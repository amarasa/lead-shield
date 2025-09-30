<?php

/**
 * Plugin Name: LeadShield
 * Plugin URI:  https://github.com/amarasa/lead-shield
 * Description: Hooks into Gravity Forms to validate email and phone via external APIs.
 * Version:     1.2
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

/* -----------------------------------------------------------------------------
   CLEANUP LEGACY LICENSE DATA
-----------------------------------------------------------------------------*/

function lead_shield_cleanup_legacy_license_data()
{
    // Remove any leftover license-related options
    delete_option('lead_shield_license_key');
    delete_transient('lead_shield_license_valid');
}
register_activation_hook(__FILE__, 'lead_shield_cleanup_legacy_license_data');

/* -----------------------------------------------------------------------------
   DEPENDENCY CHECKS
-----------------------------------------------------------------------------*/

function lead_shield_check_acf()
{
    if (!function_exists('acf_add_options_page')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>LeadShield</strong> requires <a href="https://www.advancedcustomfields.com/" target="_blank">Advanced Custom Fields</a> to be installed and activated.</p>';
            echo '</div>';
        });
    }
}
add_action('admin_init', 'lead_shield_check_acf');

function lead_shield_check_gravity_forms()
{
    if (!class_exists('GFForms')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>LeadShield</strong> requires <a href="https://www.gravityforms.com/" target="_blank">Gravity Forms</a> to be installed and activated.</p>';
            echo '</div>';
        });
    }
}
add_action('admin_init', 'lead_shield_check_gravity_forms');

/* -----------------------------------------------------------------------------
   ACF SETTINGS & API KEYS
-----------------------------------------------------------------------------*/

if (function_exists('acf_add_options_page')) {
    acf_add_options_page(array(
        'page_title' => 'LeadShield Settings',
        'menu_title' => 'LeadShield',
        'menu_slug'  => 'lead-shield-settings',
        'capability' => 'manage_options',
        'redirect'   => false,
    ));

    function lead_shield_register_acf_field_groups()
    {
        acf_add_local_field_group(array(
            'key'    => 'group_leadshield_settings',
            'title'  => 'LeadShield API Keys',
            'fields' => array(
                array(
                    'key'           => 'field_mailverify_api_key',
                    'label'         => 'MailVerify API Key',
                    'name'          => 'mailverify_api_key',
                    'type'          => 'text',
                    'instructions'  => 'Enter your MailVerify API key.',
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
                array(
                    'key'           => 'field_lead_shield_slack_webhook',
                    'label'         => 'Slack Webhook URL',
                    'name'          => 'lead_shield_slack_webhook',
                    'type'          => 'text',
                    'instructions'  => 'Enter your Slack webhook URL for notifications.',
                    'required'      => 0,
                ),
                array(
                    'key'           => 'field_leadshield_notification_sent',
                    'label'         => 'Notification Sent',
                    'name'          => 'notification_sent',
                    'type'          => 'true_false',
                    'ui'            => 1,
                    'default_value' => 0,
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

    function lead_shield_convert_api_fields_to_password($field)
    {
        if (in_array($field['name'], array('mailverify_api_key', 'numverify_api_key'))) {
            $field['type'] = 'password';
        }
        return $field;
    }
    add_filter('acf/load_field', 'lead_shield_convert_api_fields_to_password');
}

/* -----------------------------------------------------------------------------
   GRAVITY FORMS VALIDATION HOOKS
-----------------------------------------------------------------------------*/

add_filter('gform_field_validation', function ($result, $value, $form, $field) {
    // Only target email fields
    if ($field->type === 'email') {
        // Immediate fail on blank email
        if (empty($value)) {
            $result['is_valid'] = false;
            $result['message']  = __('Email is required.', 'lead-shield');
            return $result;
        }

        // Retrieve API key and Slack webhook
        $api_key            = get_field('mailverify_api_key', 'option');
        $slack_webhook_url  = get_field('lead_shield_slack_webhook', 'option');

        // Check daily credit usage
        $credits_api_url    = "https://api.mailverify.ai/api/v1/user/credits_left";
        $credits_response   = wp_remote_get($credits_api_url, [
            'headers' => [
                'x-auth-mailverify' => $api_key
            ]
        ]);
        $daily_available    = 0;
        if (!is_wp_error($credits_response)) {
            $credits_data = json_decode(wp_remote_retrieve_body($credits_response), true);
            if (isset($credits_data['creditsLeft'])) {
                $daily_available = (int) $credits_data['creditsLeft'];
            }
        }

        // Manage one-time Slack notifications via an ACF flag
        $notif_sent = get_field('notification_sent', 'option');

        if ($daily_available > 0) {
            // Reset flag when credits return
            if ($notif_sent) {
                update_field('notification_sent', false, 'option');
            }

            // Proceed with live email verification
            $email           = sanitize_email($value);
            $verification_url = "https://api.mailverify.ai/api/v1/verify/single";
            $response        = wp_remote_post($verification_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-auth-mailverify' => $api_key
                ],
                'body' => json_encode([
                    'email' => $email
                ])
            ]);
            if (is_wp_error($response)) {
                $result['is_valid'] = false;
                $result['message']  = __('Error verifying email. Please try again later.', 'lead-shield');
                return $result;
            }
            $verification_data = json_decode(wp_remote_retrieve_body($response), true);

            // Check if the response has the expected structure
            if (!isset($verification_data['data']['status'])) {
                $result['is_valid'] = false;
                $result['message']  = __('Error verifying email. Please try again later.', 'lead-shield');
                return $result;
            }

            $verification_status = $verification_data['data']['status'];
            $acceptable_statuses = ['VALID', 'ACCEPT_ALL', 'UNKNOWN', 'DELIVERABLE'];

            if (!in_array($verification_status, $acceptable_statuses)) {
                $result['is_valid'] = false;
                $result['message']  = sprintf(__('The email address is invalid or not deliverable. (Status: %s)', 'lead-shield'), $verification_status);
            } else {
                error_log('Email is valid: ' . $email . ' (Status: ' . $verification_status . ')');
            }
        } else {
            // Out of credits: send one-time Slack alert and bypass validation
            if (!$notif_sent && !empty($slack_webhook_url)) {
                $domain  = parse_url(home_url(), PHP_URL_HOST);
                $message = sprintf("%s - MailVerify has run out of credits. LeadShield is automatically disabled until credits are available.", $domain);
                wp_remote_post($slack_webhook_url, [
                    'body'    => json_encode(['text' => $message]),
                    'headers' => ['Content-Type' => 'application/json'],
                ]);
                update_field('notification_sent', true, 'option');
            }
            // Bypass email validation
            return $result;
        }
    }

    return $result;
}, 10, 4);

add_filter('gform_field_validation', function ($result, $value, $form, $field) {
    if ($field->type !== 'phone') {
        return $result;
    }
    $api_key = get_field('numverify_api_key', 'option');
    if (empty($api_key)) {
        error_log('NumVerify API key is missing in settings.');
        $result['is_valid'] = false;
        $result['message']  = __('Phone validation is temporarily unavailable. Please try again later.', 'lead-shield');
        return $result;
    }
    $phone    = sanitize_text_field($value);
    $api_url  = "http://apilayer.net/api/validate?access_key={$api_key}&number=1{$phone}";
    $response = wp_remote_get($api_url);
    if (is_wp_error($response)) {
        $result['is_valid'] = false;
        $result['message']  = __('Error verifying phone number. Please try again later.', 'lead-shield');
        return $result;
    }
    $verification_result = json_decode(wp_remote_retrieve_body($response), true);
    if (!$verification_result['valid']) {
        $msg = isset($verification_result['error'])
            ? $verification_result['error']['info']
            : __('The phone number is invalid or not deliverable.', 'lead-shield');
        $result['is_valid'] = false;
        $result['message']  = sprintf(__('Invalid phone number: %s', 'lead-shield'), $msg);
        return $result;
    }
    if (!$verification_result['line_type']) {
        $result['is_valid'] = false;
        $result['message']  = __('The phone number is not a valid mobile or landline.', 'lead-shield');
        return $result;
    }
    foreach ($form['fields'] as $form_field) {
        if ($form_field->type === 'hidden' && strtolower($form_field->label) === 'line_type') {
            $_POST['input_' . $form_field->id] = sanitize_text_field($verification_result['line_type']);
            break;
        }
    }
    return $result;
}, 10, 4);

// End of LeadShield plugin code.
