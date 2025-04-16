<?php

/**
 * Plugin Name: LeadShield
 * Plugin URI:  https://github.com/amarasa/lead-shield
 * Description: Hooks into Gravity Forms to validate email and phone via external APIs.
 * Version:     1.0.2
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

// Append required query args (license_key, plugin_slug, domain) on update checks.
$myUpdateChecker->addQueryArgFilter(function (array $queryArgs) {
    $license_key = get_option('lead_shield_license_key', '');
    $queryArgs['license_key'] = $license_key;
    $queryArgs['plugin_slug']  = 'lead-shield';
    $queryArgs['domain']       = home_url();
    return $queryArgs;
});

defined('ABSPATH') || exit;

/* -----------------------------------------------------------------------------
   DEPENDENCY CHECKS
-----------------------------------------------------------------------------*/

/**
 * Check for the ACF dependency.
 *
 * If Advanced Custom Fields isn’t active, display an admin notice.
 */
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

/**
 * Check for the Gravity Forms dependency.
 *
 * If Gravity Forms isn’t active, display an admin notice.
 */
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
   LICENSING FUNCTIONS & ADMIN INTERFACE
-----------------------------------------------------------------------------*/

/**
 * Check whether the stored license key is valid.
 * Uses a transient (cached for one hour) to minimize API calls.
 *
 * @return bool True if valid; false otherwise.
 */
function lead_shield_is_license_valid()
{
    $cached = get_transient('lead_shield_license_valid');
    if ($cached !== false) {
        return $cached;
    }

    $license_key = get_option('lead_shield_license_key', '');
    if (empty($license_key)) {
        set_transient('lead_shield_license_valid', false, HOUR_IN_SECONDS);
        return false;
    }

    $response = wp_remote_post('http://206.189.194.86/api/license/verify', [
        'timeout' => 15,
        'body'    => [
            'license_key' => $license_key,
            'plugin_slug' => 'lead-shield',
            'domain'      => home_url(),
        ],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        set_transient('lead_shield_license_valid', false, HOUR_IN_SECONDS);
        return false;
    }

    $license_data = json_decode(wp_remote_retrieve_body($response), true);
    $valid = (!empty($license_data)
        && isset($license_data['license_info']['status'])
        && strtolower($license_data['license_info']['status']) === 'active');

    set_transient('lead_shield_license_valid', $valid, HOUR_IN_SECONDS);
    return $valid;
}

/**
 * Display an admin notice if no license key exists.
 */
function lead_shield_admin_license_check()
{
    if (!is_admin()) {
        return;
    }
    if (empty(get_option('lead_shield_license_key'))) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            _e('LeadShield is disabled because it does not have a valid license. Please enter a valid license key.', 'lead-shield');
            echo '</p></div>';
        });
    }
}
add_action('admin_init', 'lead_shield_admin_license_check');

/**
 * Add a License Settings page under the Settings menu.
 */
function lead_shield_add_license_settings_page()
{
    add_options_page(
        'LeadShield License Settings',
        'LeadShield License',
        'manage_options',
        'lead-shield-license-settings',
        'lead_shield_render_license_settings_page'
    );
}
add_action('admin_menu', 'lead_shield_add_license_settings_page');

/**
 * Render the License Settings page.
 */
function lead_shield_render_license_settings_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'lead-shield'));
    }

    // Process form submission for updating the license.
    if (isset($_POST['update_license'])) {
        check_admin_referer('lead_shield_license_settings');
        $new_key = sanitize_text_field($_POST['lead_shield_license_key']);
        $response = wp_remote_post('http://206.189.194.86/api/license/validate', [
            'body'    => [
                'license_key' => $new_key,
                'plugin_slug' => 'lead-shield',
                'domain'      => home_url(),
            ],
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            echo '<div class="error"><p>' . __('There was an error contacting the licensing server. Please try again later.', 'lead-shield') . '</p></div>';
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code == 200) {
                update_option('lead_shield_license_key', $new_key);
                delete_transient('lead_shield_license_valid');
                echo '<div class="updated"><p>' . __('License key updated successfully.', 'lead-shield') . '</p></div>';
            } elseif ($status_code == 404) {
                echo '<div class="error"><p>' . __('License key is invalid. Please enter a valid license key.', 'lead-shield') . '</p></div>';
            } elseif ($status_code == 403) {
                echo '<div class="error"><p>' . __('License key is inactive or the activation limit has been reached.', 'lead-shield') . '</p></div>';
            } else {
                echo '<div class="error"><p>' . __('Unexpected response from licensing server.', 'lead-shield') . '</p></div>';
            }
        }
    }

    // Process form submission for removing the license.
    if (isset($_POST['remove_license'])) {
        check_admin_referer('lead_shield_license_settings');
        $current_key = get_option('lead_shield_license_key', '');
        if (!empty($current_key)) {
            $response = wp_remote_post('http://206.189.194.86/api/license/deactivate', [
                'body'    => [
                    'license_key' => $current_key,
                    'plugin_slug' => 'lead-shield',
                    'domain'      => home_url(),
                ],
                'timeout' => 15,
            ]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
                delete_option('lead_shield_license_key');
                delete_transient('lead_shield_license_valid');
                echo '<div class="updated"><p>' . __('License removed successfully. LeadShield is now disabled until a valid license key is entered.', 'lead-shield') . '</p></div>';
            } else {
                echo '<div class="error"><p>' . __('There was an error removing the license. Please try again.', 'lead-shield') . '</p></div>';
            }
        }
    }

    $current_key = esc_attr(get_option('lead_shield_license_key', ''));
?>
    <div class="wrap">
        <h1><?php _e('LeadShield License Settings', 'lead-shield'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('lead_shield_license_settings'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php _e('License Key', 'lead-shield'); ?></th>
                    <td>
                        <input type="text" name="lead_shield_license_key" value="<?php echo $current_key; ?>" style="width: 400px;" />
                        <p class="description"><?php _e('Enter your valid license key for LeadShield.', 'lead-shield'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Update License', 'primary', 'update_license'); ?>
            <?php if (!empty($current_key)) : ?>
                <?php submit_button('Remove License', 'secondary', 'remove_license'); ?>
            <?php endif; ?>
        </form>
    </div>
<?php
}

/**
 * On plugin deactivation, hit the licensing API to deactivate the license,
 * then clear the stored license key and cached validation.
 */
function lead_shield_on_deactivation()
{
    $license_key = get_option('lead_shield_license_key', '');
    if (!empty($license_key)) {
        wp_remote_post('http://206.189.194.86/api/license/deactivate', [
            'body'    => [
                'license_key' => $license_key,
                'plugin_slug' => 'lead-shield',
                'domain'      => home_url(),
            ],
            'timeout' => 15,
        ]);
    }
    delete_option('lead_shield_license_key');
    delete_transient('lead_shield_license_valid');
}
register_deactivation_hook(__FILE__, 'lead_shield_on_deactivation');

/* -----------------------------------------------------------------------------
   ACF SETTINGS & API KEYS
-----------------------------------------------------------------------------*/

// Only add ACF settings if ACF is active.
if (function_exists('acf_add_options_page')) {
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
     *  - Slack Webhook URL (lead_shield_slack_webhook)
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
                array(
                    'key'           => 'field_lead_shield_slack_webhook',
                    'label'         => 'Slack Webhook URL',
                    'name'          => 'lead_shield_slack_webhook',
                    'type'          => 'text',
                    'instructions'  => 'Enter your Slack webhook URL for notifications.',
                    'required'      => 0,
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
     * This filter changes the input type to "password" so that the API keys are masked.
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

/* -----------------------------------------------------------------------------
   GRAVITY FORMS VALIDATION HOOKS (ONLY IF LICENSE IS VALID)
-----------------------------------------------------------------------------*/

// Only register the validation hooks if the license is valid.
if (lead_shield_is_license_valid()) {

    /**
     * Validate email fields using the EmailListVerify API.
     */
    add_filter('gform_field_validation', function ($result, $value, $form, $field) {
        if ($field->type === 'email') {
            // Retrieve API key from LeadShield settings.
            $api_key = get_field('emaillistverify_api_key', 'option');
            // Get the Slack Webhook URL from the custom ACF field.
            $slack_webhook_url = get_field('lead_shield_slack_webhook', 'option');

            // Step 1: Check daily email verification credits.
            $credits_api_url = "https://apps.emaillistverify.com/api/credits?secret={$api_key}";
            $credits_response = wp_remote_get($credits_api_url);
            $daily_available = 0;
            if (!is_wp_error($credits_response)) {
                $credits_body = wp_remote_retrieve_body($credits_response);
                $credits_data = json_decode($credits_body, true);
                if (isset($credits_data['daily']['available'])) {
                    $daily_available = (int)$credits_data['daily']['available'];
                }
            }

            // Step 2: Decide based on available credits.
            if ($daily_available > 0) {
                // Proceed with email validation.
                $email = sanitize_email($value);
                $api_url = "https://apps.emaillistverify.com/api/verifEmail?secret={$api_key}&email={$email}";
                $response = wp_remote_get($api_url);
                if (is_wp_error($response)) {
                    $result['is_valid'] = false;
                    $result['message']  = 'Error verifying email. Please try again later.';
                    return $result;
                }
                $body = wp_remote_retrieve_body($response);
                // Trim the response to get a clean status code from the API.
                $verification_result = trim($body);
                // Define which statuses are acceptable.
                $acceptable_statuses = ['ok', 'antispam_system', 'ok_for_all'];
                if (!in_array($verification_result, $acceptable_statuses)) {
                    $result['is_valid'] = false;
                    $result['message']  = 'The email address is invalid or not deliverable. (Status: ' . $verification_result . ')';
                } else {
                    error_log('Email is valid: ' . $email . ' (Status: ' . $verification_result . ')');
                }
            } else {
                // Daily credits are exhausted.
                if (!empty($slack_webhook_url)) {
                    // Get the current domain.
                    $domain = parse_url(home_url(), PHP_URL_HOST);
                    $message = "{$domain} - EmailListVerify has run out of daily credits. LeadShield is automatically disabled until daily credits reset to prevent leads from being blocked.";
                    // Send Slack notification using the webhook.
                    $payload = json_encode([
                        "text" => $message
                    ]);
                    $args = [
                        'body'    => $payload,
                        'headers' => [
                            'Content-Type' => 'application/json'
                        ],
                    ];
                    wp_remote_post($slack_webhook_url, $args);
                }
                // Bypass email validation; allow the form to continue.
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
        if (!$verification_result['valid']) {
            if (isset($verification_result['error'])) {
                $result['is_valid'] = false;
                $result['message']  = 'Invalid phone number: ' . $verification_result['error']['info'];
            } else {
                $result['is_valid'] = false;
                $result['message']  = 'The phone number is invalid or not deliverable.';
            }
            return $result;
        }
        if (!$verification_result['line_type']) {
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
} // end license valid check

// End of LeadShield plugin code.
