# LeadShield

**Contributors:** amarasa  
**Author:** Angelo Marasa  
**Tags:** gravity forms, email validation, phone validation, lead generation, ACF  
**Requires at least:** 5.0  
**Tested up to:** 6.0  
**Stable tag:** 0.1.0  
**License:** GPLv2 or later  
**License URI:** [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

## Description

LeadShield is a WordPress plugin that hooks into Gravity Forms to validate email and phone fields via external APIs. It leverages Advanced Custom Fields (ACF) to provide a secure settings page for storing API keys.

### Key Features

-   Validate email fields using the EmailListVerify API.
-   Validate phone fields using the NumVerify API.
-   Automatic dependency checks for Advanced Custom Fields and Gravity Forms.
-   Secure settings page for API key configuration with masked (password) input fields.
-   Developer-friendly and easily extensible code.

## Installation

1. Upload the `lead-shield` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the WordPress admin **Plugins** screen.
3. Ensure that both the [Advanced Custom Fields](https://www.advancedcustomfields.com/) and [Gravity Forms](https://www.gravityforms.com/) plugins are installed and activated.
4. Navigate to the LeadShield settings page (found under the **LeadShield** menu in the WordPress dashboard) to enter your API keys.

## Frequently Asked Questions

### What happens if ACF or Gravity Forms is not installed?

If either dependency is missing, LeadShield will display an admin notice alerting you that the required plugin is not active.

### How do I update my API keys?

You can update your API keys on the LeadShield settings page in your WordPress admin dashboard. The keys are stored securely and displayed as masked inputs.

### Can I modify the plugin’s behavior?

Yes! The plugin is built with extensibility in mind. Developers can modify the validation logic or integrate additional API services as needed.

## Changelog

### 0.1.0

-   Initial release.
-   Added email validation via the EmailListVerify API.
-   Added phone validation via the NumVerify API.
-   Implemented dependency checks for ACF and Gravity Forms.
-   Created a secure settings page for storing API keys.

## Upgrade Notice

### 0.1.0

Initial release.

## License

This plugin is licensed under the GPLv2 or later. For more details, see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
