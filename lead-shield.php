<?php

/**
 * Plugin Name: LeadShield
 * Plugin URI:  https://github.com/amarasa/lead-shield
 * Description: Hooks into Gravity Forms to validate email and phone via external APIs.
 * Version:     1.1.1
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
   LICENSING FUNCTIONS & ADMIN INTERFACE
-----------------------------------------------------------------------------*/

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

function lead_shield_render_license_settings_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'lead-shield'));
    }

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
   GRAVITY FORMS VALIDATION HOOKS (ONLY IF LICENSE IS VALID)
-----------------------------------------------------------------------------*/

if (lead_shield_is_license_valid()) {

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
                $acceptable_statuses = ['VALID', 'ACCEPT_ALL', 'UNKNOWN'];

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
} // end license valid check

// End of LeadShield plugin code.
