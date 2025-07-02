=== Error Explorer ===
Contributors: errorexplorer
Tags: error-reporting, monitoring, debugging, error-tracking, exception-handling
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Automatically send WordPress errors to Error Explorer monitoring platform for better error tracking and debugging.

== Description ==

**Error Explorer** is a comprehensive error monitoring and reporting plugin for WordPress that automatically captures and sends errors to the Error Explorer platform for better debugging and site monitoring.

= Key Features =

* 🚨 **Automatic Error Detection** - Captures PHP errors, exceptions, and WordPress-specific errors
* 📊 **Detailed Context** - Includes request data, session information, and server details
* 🔍 **Stack Traces & Breadcrumbs** - Complete error traces with user action history
* ⚙️ **Easy Configuration** - Simple WordPress admin interface setup
* 🔒 **Data Security** - Automatic sanitization of sensitive information
* 🎯 **WordPress Integration** - Native WordPress hooks and multisite support
* 📱 **Real-time Monitoring** - Instant error notifications via webhook
* 🔧 **Test Functionality** - Built-in error testing capability

= Why Choose Error Explorer? =

Traditional WordPress error logging often leaves you searching through log files without context. Error Explorer provides:

* **Centralized Dashboard** - View all your WordPress site errors in one place
* **Rich Context** - Understand what users were doing when errors occurred
* **Smart Grouping** - Similar errors are grouped together for easier management
* **Performance Monitoring** - Track error frequency and patterns
* **Team Collaboration** - Share error reports with your development team

= How It Works =

1. Install and activate the plugin
2. Get your webhook URL from your Error Explorer dashboard
3. Configure the plugin in Settings > Error Explorer
4. Errors are automatically captured and sent to your dashboard
5. Monitor and resolve issues from the Error Explorer platform

= Captured Error Types =

* PHP Fatal Errors
* PHP Warnings and Notices
* Uncaught Exceptions
* WordPress wp_die() calls
* Plugin and theme errors
* Custom error messages

= Captured Context Data =

* **Error Details**: Message, stack trace, file, and line number
* **Request Information**: URL, method, IP address, headers, GET/POST data
* **WordPress Data**: Version, theme, active plugins, user information
* **Server Data**: PHP version, memory usage, server environment
* **Session Data**: User session and authentication details
* **Breadcrumbs**: User actions leading up to the error

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Go to Plugins > Add New
3. Search for "Error Explorer"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to your WordPress admin panel
3. Go to Plugins > Add New > Upload Plugin
4. Choose the ZIP file and click "Install Now"
5. Activate the plugin

= Configuration =

1. Go to Settings > Error Explorer in your WordPress admin
2. Get your webhook URL from your Error Explorer project dashboard
3. Paste the webhook URL in the plugin settings
4. Enable error reporting
5. Configure which data types to capture (optional)
6. Save the settings

== Frequently Asked Questions ==

= Do I need an Error Explorer account? =

Yes, you need an account on the Error Explorer platform to receive error reports. The plugin sends errors via webhook to your Error Explorer dashboard.

= What data is sent to Error Explorer? =

The plugin captures error details, request information, WordPress context, and server data. All sensitive information (passwords, tokens, etc.) is automatically sanitized before sending.

= Will this slow down my website? =

The plugin is designed to have minimal performance impact. Error reporting happens asynchronously and only when errors occur. Normal site operation is not affected.

= Can I test if the plugin is working? =

Yes! The plugin includes a "Test Error" button in the settings page that will send a test error to verify your configuration is working correctly.

= Is it compatible with multisite? =

Yes, Error Explorer supports WordPress multisite installations and can be configured per site or network-wide.

= What PHP version is required? =

The plugin requires PHP 7.4 or higher, which aligns with WordPress recommended PHP versions.

= Can I customize what errors are captured? =

Yes, you can configure whether to capture request data, session data, and server data. Future versions will include more granular filtering options.

= Is the plugin GDPR compliant? =

The plugin automatically sanitizes sensitive personal data. However, you should review your data handling practices and privacy policy to ensure compliance with applicable regulations.

== Screenshots ==

1. Main plugin settings page - Configure webhook URL and error capture options
2. Error reporting dashboard - View captured errors in Error Explorer platform
3. Error detail view - Complete error context with stack traces and breadcrumbs
4. Test error functionality - Verify your configuration is working correctly

== Changelog ==

= 1.0.0 =
* Initial release
* Automatic PHP error and exception capture
* WordPress-specific error handling
* Rich context data collection
* Breadcrumb tracking system
* Admin interface for configuration
* Test error functionality
* Sensitive data sanitization
* Multisite support

== Upgrade Notice ==

= 1.0.0 =
Initial release of Error Explorer WordPress plugin. Start monitoring your WordPress site errors today!

== Support ==

For support, documentation, and feature requests:

* Visit our [documentation](https://error-explorer.com/docs)
* Contact support through the Error Explorer platform
* Report issues on our GitHub repository

== Privacy Policy ==

Error Explorer WordPress plugin collects error information and context data to help debug your WordPress site. This may include:

* Error messages and stack traces
* Request URLs and parameters (sensitive data is sanitized)
* User information (for logged-in users)
* Server and WordPress environment details

All data is sent securely to your configured Error Explorer webhook endpoint. No data is sent to third parties beyond your configured monitoring platform.

Sensitive information such as passwords, API keys, and authorization tokens are automatically redacted before transmission.