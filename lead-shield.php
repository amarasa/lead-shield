<?php

/**
 * Plugin Name: LeadShield
 * Plugin URI:  https://github.com/amarasa/lead-shield
 * Description: Hooks into Gravity Forms to validate email and phone via external APIs.
 * Version:     0.1.0
 * Author:      Angelo Marasa
 * Author URI:  https://github.com/amarasa
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lead-shield
 */

require 'puc/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/amarasa/lead-shield',
    __FILE__,
    'lead-shield'
);


defined('ABSPATH') || exit;

/**
 * Check for the ACF dependency.
 *
 * If Advanced Custom Fields isn’t active, display an admin notice.
 */
function lead_shield_check_acf()
{
    if (! function_exists('acf_add_options_page')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>LeadShield</strong> requires <a href="https://www.advancedcustomfields.com/" target="_blank">Advanced Custom Fields</a> to be installed and activated.</p>';
            echo '</div>';
        });
    }
}
add_action('admin_init', 'lead_shield_check_acf');

/**
 * Check for the Gravity Forms dependency.
 *
 * If Gravity Forms isn’t active, display an admin notice.
 */
function lead_shield_check_gravity_forms()
{
    if (! class_exists('GFForms')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>LeadShield</strong> requires <a href="https://www.gravityforms.com/" target="_blank">Gravity Forms</a> to be installed and activated.</p>';
            echo '</div>';
        });
    }
}
add_action('admin_init', 'lead_shield_check_gravity_forms');

/**
 * Only add ACF settings if ACF is active.
 */
if (function_exists('acf_add_options_page')) {

    /**
     * Add an ACF Options Page for LeadShield settings.
     */
    acf_add_options_page(array(
        'page_title' => 'LeadShield Settings',
        'menu_title' => 'LeadShield',
        'menu_slug'  => 'lead-shield-settings',
        'capability' => 'manage_options',
        'redirect'   => false,
    ));

    /**
     * Register ACF Field Group for API Keys.
     *
     * This group creates two fields:
     *  - EmailListVerify API key (emaillistverify_api_key)
     *  - NumVerify API key (numverify_api_key)
     *
     * They are displayed on the LeadShield settings page.
     */
    function lead_shield_register_acf_field_groups()
    {
        acf_add_local_field_group(array(
            'key'    => 'group_leadshield_settings',
            'title'  => 'LeadShield API Keys',
            'fields' => array(
                array(
                    'key'           => 'field_emaillistverify_api_key',
                    'label'         => 'EmailListVerify API Key',
                    'name'          => 'emaillistverify_api_key',
                    'type'          => 'text',
                    'instructions'  => 'Enter your EmailListVerify API key.',
                    'required'      => 1,
                ),
                array(
                    'key'           => 'field_numverify_api_key',
                    'label'         => 'NumVerify API Key',
                    'name'          => 'numverify_api_key',
                    'type'          => 'text',
                    'instructions'  => 'Enter your NumVerify API key.',
                    'required'      => 1,
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param'    => 'options_page',
                        'operator' => '==',
                        'value'    => 'lead-shield-settings',
                    ),
                ),
            ),
        ));
    }
    add_action('acf/init', 'lead_shield_register_acf_field_groups');

    /**
     * Convert API key fields to password inputs.
     *
     * This filter changes the input type to "password" so that the API key is masked.
     */
    function lead_shield_convert_api_fields_to_password($field)
    {
        if (in_array($field['name'], array('emaillistverify_api_key', 'numverify_api_key'))) {
            $field['type'] = 'password';
        }
        return $field;
    }
    add_filter('acf/load_field', 'lead_shield_convert_api_fields_to_password');
}

/* ---------------------
   Gravity Forms Validation Hooks
   --------------------- */

/**
 * Validate email fields using the EmailListVerify API.
 */
add_filter('gform_field_validation', function ($result, $value, $form, $field) {
    if ($field->type === 'email') {
        // Retrieve the API key from the LeadShield settings.
        $api_key = get_field('emaillistverify_api_key', 'option');
        $email   = sanitize_email($value);
        $api_url = "https://apps.emaillistverify.com/api/verifEmail?secret={$api_key}&email={$email}";
        $response = wp_remote_get($api_url);

        if (is_wp_error($response)) {
            $result['is_valid'] = false;
            $result['message']  = 'Error verifying email. Please try again later.';
            return $result;
        }

        $body = wp_remote_retrieve_body($response);
        // Adjust the response handling as needed based on your API’s actual output.
        $verification_result = print_r($body, true);

        if ($verification_result !== 'ok') {
            $result['is_valid'] = false;
            $result['message']  = 'The email address is invalid or not deliverable.';
        } else {
            error_log('Email is valid: ' . $email);
        }
    }
    return $result;
}, 10, 4);

/**
 * Validate phone fields using the NumVerify API.
 */
add_filter('gform_field_validation', function ($result, $value, $form, $field) {
    if ($field->type !== 'phone') {
        return $result;
    }

    // Retrieve the NumVerify API key from the LeadShield settings.
    $api_key = get_field('numverify_api_key', 'option');

    if (empty($api_key)) {
        error_log('NumVerify API key is missing in settings.');
        $result['is_valid'] = false;
        $result['message']  = 'Phone validation is temporarily unavailable. Please try again later.';
        return $result;
    }

    $phone   = sanitize_text_field($value);
    $api_url = "http://apilayer.net/api/validate?access_key={$api_key}&number=1{$phone}";
    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        $result['is_valid'] = false;
        $result['message']  = 'Error verifying phone number. Please try again later.';
        return $result;
    }

    $body = wp_remote_retrieve_body($response);
    $verification_result = json_decode($body, true);

    if (! $verification_result['valid']) {
        if (isset($verification_result['error'])) {
            $result['is_valid'] = false;
            $result['message']  = 'Invalid phone number: ' . $verification_result['error']['info'];
        } else {
            $result['is_valid'] = false;
            $result['message']  = 'The phone number is invalid or not deliverable.';
        }
        return $result;
    }

    if (! $verification_result['line_type']) {
        $result['is_valid'] = false;
        $result['message']  = 'The phone number is not a valid mobile or landline.';
        return $result;
    }

    // Populate the hidden "line_type" field if it exists in the form.
    foreach ($form['fields'] as $form_field) {
        if ($form_field->type === 'hidden' && strtolower($form_field->label) === 'line_type') {
            $input_name = 'input_' . $form_field->id;
            $_POST[$input_name] = sanitize_text_field($verification_result['line_type']);
            break;
        }
    }
    return $result;
}, 10, 4);
