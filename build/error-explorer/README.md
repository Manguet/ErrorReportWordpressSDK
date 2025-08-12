# Error Explorer WordPress SDK

Automatically send WordPress errors to Error Explorer monitoring platform for better error tracking and debugging.

## Features

- 🚨 Automatic error detection and reporting
- 📊 Detailed error context (request, session, server data)
- 🔍 Stack traces and breadcrumbs
- ⚙️ Easy WordPress admin configuration
- 🔒 Sensitive data sanitization
- 🎯 WordPress-specific error handling
- 📱 Supports WordPress multisite
- 🔧 Test error functionality

## Installation

### Method 1: WordPress Plugin (Recommended)

1. Download the plugin files to your WordPress `wp-content/plugins/` directory
2. Activate the plugin through the WordPress admin
3. Configure the plugin in Settings > Error Explorer

### Method 2: Composer Installation

```bash
composer require error-explorer/wordpress-error-reporter
```

Then include in your WordPress theme or plugin:

```php
require_once 'vendor/autoload.php';

use ErrorExplorer\WordPressErrorReporter\ErrorReporter;

$errorReporter = new ErrorReporter('YOUR_WEBHOOK_URL', [
    'environment' => wp_get_environment_type(),
    'project_name' => 'My WordPress Site',
    'capture_request_data' => true,
    'capture_session_data' => true,
    'capture_server_data' => true,
]);

$errorReporter->register();
```

## Configuration

### WordPress Plugin Configuration

1. Go to **Settings > Error Explorer** in your WordPress admin
2. Configure the following options:

| Option | Description | Default |
|--------|-------------|---------|
| Enable Error Reporting | Enable/disable error reporting | Disabled |
| Webhook URL | Your Error Explorer project webhook URL | Empty |
| Capture Request Data | Include request data (GET, POST, headers) | Enabled |
| Capture Session Data | Include session and user data | Enabled |
| Capture Server Data | Include server environment data | Enabled |

### Getting Your Webhook URL

1. Log in to your Error Explorer dashboard
2. Go to your project settings
3. Copy the webhook URL (format: `https://error-explorer.com/webhook/error/your-token`)

### Manual Configuration

```php
$config = [
    'environment' => wp_get_environment_type(), // or 'production', 'staging', etc.
    'project_name' => 'My WordPress Site',
    'capture_request_data' => true,
    'capture_session_data' => true,
    'capture_server_data' => true,
    'max_breadcrumbs' => 20,
];

$errorReporter = new ErrorReporter('YOUR_WEBHOOK_URL', $config);
$errorReporter->register();
```

## Usage

### Automatic Error Handling

Once configured, the SDK automatically captures:

- PHP Fatal Errors
- PHP Warnings and Notices
- Uncaught Exceptions
- WordPress `wp_die()` calls
- Plugin and theme errors

### Manual Error Reporting

```php
// Report an exception
try {
    // Your code here
    throw new Exception('Something went wrong');
} catch (Exception $e) {
    $errorReporter->reportError($e, wp_get_environment_type(), 500);
}

// Report a custom message
$errorReporter->reportMessage(
    'Custom error message',
    wp_get_environment_type(),
    null, // HTTP status (optional)
    'error', // Level: error, warning, info
    ['user_id' => get_current_user_id()] // Additional context
);
```

### Breadcrumbs for Better Context

Add breadcrumbs to track user actions before an error:

```php
// Add a custom breadcrumb
$errorReporter->addBreadcrumb('User logged in', 'auth', 'info', [
    'user_id' => get_current_user_id()
]);

// Log navigation
$errorReporter->logNavigation('/wp-admin/', '/wp-admin/edit.php');

// Log user actions
$errorReporter->logUserAction('post_published', [
    'post_id' => $post->ID,
    'post_type' => $post->post_type
]);

// Log HTTP requests
$errorReporter->logHttpRequest('POST', '/wp-admin/admin-ajax.php', 200, [
    'action' => $_POST['action']
]);
```

## WordPress Integration Examples

### Theme Integration

Add to your theme's `functions.php`:

```php
add_action('after_setup_theme', function() {
    if (class_exists('ErrorExplorer\WordPressErrorReporter\ErrorReporter')) {
        $webhook_url = get_option('error_explorer_webhook_url');
        
        if ($webhook_url && get_option('error_explorer_enabled')) {
            $errorReporter = new \ErrorExplorer\WordPressErrorReporter\ErrorReporter($webhook_url, [
                'environment' => wp_get_environment_type(),
                'project_name' => get_bloginfo('name'),
            ]);
            
            $errorReporter->register();
        }
    }
});
```

### Plugin Integration

```php
class MyPlugin {
    private $errorReporter;
    
    public function __construct() {
        add_action('plugins_loaded', [$this, 'init_error_reporting']);
    }
    
    public function init_error_reporting() {
        if (class_exists('ErrorExplorer\WordPressErrorReporter\ErrorReporter')) {
            $this->errorReporter = new \ErrorExplorer\WordPressErrorReporter\ErrorReporter(
                'YOUR_WEBHOOK_URL',
                ['project_name' => 'My Plugin']
            );
            
            $this->errorReporter->register();
        }
    }
    
    public function some_method() {
        try {
            // Plugin logic here
        } catch (Exception $e) {
            if ($this->errorReporter) {
                $this->errorReporter->addBreadcrumb('Plugin method failed', 'plugin');
                $this->errorReporter->reportError($e);
            }
            throw $e; // Re-throw if needed
        }
    }
}
```

### WooCommerce Integration

Track WooCommerce-specific events:

```php
// Track order failures
add_action('woocommerce_order_status_failed', function($order_id) {
    global $errorReporter;
    if ($errorReporter) {
        $errorReporter->addBreadcrumb('Order failed', 'woocommerce', 'error', [
            'order_id' => $order_id
        ]);
        
        $errorReporter->reportMessage(
            'WooCommerce order failed',
            wp_get_environment_type(),
            null,
            'warning',
            ['order_id' => $order_id]
        );
    }
});

// Track payment errors
add_action('woocommerce_payment_failure', function() {
    global $errorReporter;
    if ($errorReporter) {
        $errorReporter->addBreadcrumb('Payment failed', 'woocommerce', 'error');
    }
});
```

## Testing

### Test Error Functionality

The plugin includes a test error feature accessible from the admin settings page, or you can trigger it manually:

```php
// Test exception
try {
    throw new Exception('Test error from Error Explorer WordPress SDK');
} catch (Exception $e) {
    $errorReporter->reportError($e);
}

// Test PHP error
$undefined_variable->someProperty; // Will be caught by error handler
```

### Using the Test Project

A complete test project is available in `test-wordpress-project/`:

```bash
cd test-wordpress-project
php test-errors.php
```

This will run various error scenarios to test the SDK functionality.

## Captured Data

The SDK captures comprehensive error context:

### Error Information
- Exception message and class
- Stack trace
- File and line number
- Error fingerprint for grouping

### Request Data (if enabled)
- URL, method, IP address
- GET/POST parameters (sanitized)
- HTTP headers (sanitized)
- User agent and referer

### WordPress Data
- WordPress version
- Current theme and plugins
- User information (if logged in)
- Multisite information
- Debug settings

### Server Data (if enabled)
- PHP version and memory usage
- Server software and environment
- Memory limits and execution time
- Document root and server name

### Session Data (if enabled)
- Session ID and name
- Current user details
- User roles and capabilities

## Security

The SDK automatically sanitizes sensitive data:

- Passwords and tokens are redacted
- Authorization headers are masked
- Sensitive form fields are filtered
- API keys and secrets are protected

## Troubleshooting

### Common Issues

1. **Errors not appearing in dashboard**
   - Check webhook URL is correct
   - Verify error reporting is enabled
   - Check WordPress error logs for SDK errors

2. **Permission errors**
   - Ensure WordPress has permission to make HTTP requests
   - Check firewall rules for outbound connections

3. **Missing dependencies**
   - Run `composer install` if using Composer
   - Ensure Guzzle HTTP client is available

### Debug Mode

Enable WordPress debug mode to see SDK errors:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check `/wp-content/debug.log` for SDK-related messages.

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher
- Guzzle HTTP client
- cURL extension

## Support

For support and documentation, visit [Error Explorer Documentation](https://error-explorer.com/docs) or create an issue in the project repository.

## License

MIT License - see LICENSE file for details.