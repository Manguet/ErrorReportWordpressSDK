<?php
/**
 * Plugin Name: Error Explorer Reporter
 * Plugin URI: https://error-explorer.com
 * Description: Automatically sends errors to Error Explorer monitoring platform for better error tracking and debugging.
 * Version: 1.0.0
 * Author: Error Explorer Team
 * License: MIT
 * Text Domain: error-explorer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.7
 * Network: false
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ERROR_EXPLORER_VERSION', '1.0.0');
define('ERROR_EXPLORER_PLUGIN_FILE', __FILE__);
define('ERROR_EXPLORER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ERROR_EXPLORER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check for Composer autoloader
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . __('Error Explorer: Composer dependencies not found. Please run composer install.', 'error-explorer') . '</p></div>';
    });
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

use ErrorExplorer\WordPressErrorReporter\ErrorReporter;

class ErrorExplorerPlugin
{
    private static $instance = null;
    private $error_reporter;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        add_action('init', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);
    }

    public function load_textdomain()
    {
        load_plugin_textdomain('error-explorer', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function init()
    {
        $webhook_url = get_option('error_explorer_webhook_url');
        $enabled = get_option('error_explorer_enabled', false);
        
        if ($enabled && !empty($webhook_url)) {
            $this->error_reporter = new ErrorReporter($webhook_url, [
                'environment' => wp_get_environment_type(),
                'capture_request_data' => get_option('error_explorer_capture_request', true),
                'capture_session_data' => get_option('error_explorer_capture_session', true),
                'capture_server_data' => get_option('error_explorer_capture_server', true),
            ]);
            
            $this->error_reporter->register();
        }
    }

    public function add_admin_menu()
    {
        add_options_page(
            __('Error Explorer Settings', 'error-explorer'),
            __('Error Explorer', 'error-explorer'),
            'manage_options',
            'error-explorer',
            [$this, 'settings_page']
        );
    }

    public function settings_init()
    {
        register_setting('error_explorer', 'error_explorer_webhook_url');
        register_setting('error_explorer', 'error_explorer_enabled');
        register_setting('error_explorer', 'error_explorer_capture_request');
        register_setting('error_explorer', 'error_explorer_capture_session');
        register_setting('error_explorer', 'error_explorer_capture_server');

        add_settings_section(
            'error_explorer_section',
            __('Configuration', 'error-explorer'),
            [$this, 'settings_section_callback'],
            'error_explorer'
        );

        add_settings_field(
            'error_explorer_enabled',
            __('Enable Error Reporting', 'error-explorer'),
            [$this, 'enabled_field_callback'],
            'error_explorer',
            'error_explorer_section'
        );

        add_settings_field(
            'error_explorer_webhook_url',
            __('Webhook URL', 'error-explorer'),
            [$this, 'webhook_url_field_callback'],
            'error_explorer',
            'error_explorer_section'
        );

        add_settings_field(
            'error_explorer_capture_request',
            __('Capture Request Data', 'error-explorer'),
            [$this, 'capture_request_field_callback'],
            'error_explorer',
            'error_explorer_section'
        );

        add_settings_field(
            'error_explorer_capture_session',
            __('Capture Session Data', 'error-explorer'),
            [$this, 'capture_session_field_callback'],
            'error_explorer',
            'error_explorer_section'
        );

        add_settings_field(
            'error_explorer_capture_server',
            __('Capture Server Data', 'error-explorer'),
            [$this, 'capture_server_field_callback'],
            'error_explorer',
            'error_explorer_section'
        );
    }

    public function settings_section_callback()
    {
        echo '<p>' . __('Configure Error Explorer to monitor your WordPress site for errors.', 'error-explorer') . '</p>';
    }

    public function enabled_field_callback()
    {
        $enabled = get_option('error_explorer_enabled', false);
        echo '<input type="checkbox" name="error_explorer_enabled" value="1" ' . checked(1, $enabled, false) . ' />';
        echo '<p class="description">' . __('Enable or disable error reporting to Error Explorer.', 'error-explorer') . '</p>';
    }

    public function webhook_url_field_callback()
    {
        $webhook_url = get_option('error_explorer_webhook_url');
        echo '<input type="url" name="error_explorer_webhook_url" value="' . esc_attr($webhook_url) . '" size="50" />';
        echo '<p class="description">' . __('Your Error Explorer webhook URL from your project settings.', 'error-explorer') . '</p>';
    }

    public function capture_request_field_callback()
    {
        $capture = get_option('error_explorer_capture_request', true);
        echo '<input type="checkbox" name="error_explorer_capture_request" value="1" ' . checked(1, $capture, false) . ' />';
        echo '<p class="description">' . __('Include request data (GET, POST, headers) with error reports.', 'error-explorer') . '</p>';
    }

    public function capture_session_field_callback()
    {
        $capture = get_option('error_explorer_capture_session', true);
        echo '<input type="checkbox" name="error_explorer_capture_session" value="1" ' . checked(1, $capture, false) . ' />';
        echo '<p class="description">' . __('Include session data with error reports.', 'error-explorer') . '</p>';
    }

    public function capture_server_field_callback()
    {
        $capture = get_option('error_explorer_capture_server', true);
        echo '<input type="checkbox" name="error_explorer_capture_server" value="1" ' . checked(1, $capture, false) . ' />';
        echo '<p class="description">' . __('Include server environment data with error reports.', 'error-explorer') . '</p>';
    }

    public function settings_page()
    {
        ?>
        <div class="wrap">
            <h1><?php echo __('Error Explorer Settings', 'error-explorer'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('error_explorer');
                do_settings_sections('error_explorer');
                submit_button();
                ?>
            </form>
            
            <div class="card" style="margin-top: 20px;">
                <h2><?php echo __('Test Error Reporting', 'error-explorer'); ?></h2>
                <p><?php echo __('Click the button below to send a test error to Error Explorer.', 'error-explorer'); ?></p>
                <button type="button" class="button" onclick="errorExplorerTestError()"><?php echo __('Send Test Error', 'error-explorer'); ?></button>
            </div>
            
            <script>
            function errorExplorerTestError() {
                if (confirm('<?php echo esc_js(__('This will send a test error to Error Explorer. Continue?', 'error-explorer')); ?>')) {
                    jQuery.post(ajaxurl, {
                        action: 'error_explorer_test_error',
                        nonce: '<?php echo wp_create_nonce('error_explorer_test'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('<?php echo esc_js(__('Test error sent successfully!', 'error-explorer')); ?>');
                        } else {
                            alert('<?php echo esc_js(__('Failed to send test error: ', 'error-explorer')); ?>' + response.data);
                        }
                    });
                }
            }
            </script>
        </div>
        <?php
    }

    public function activate()
    {
        add_option('error_explorer_enabled', false);
        add_option('error_explorer_capture_request', true);
        add_option('error_explorer_capture_session', true);
        add_option('error_explorer_capture_server', true);
    }

    public function deactivate()
    {
        // Clear any scheduled events or cleanup if needed
    }

    public static function uninstall()
    {
        // Remove all plugin options when uninstalled
        delete_option('error_explorer_enabled');
        delete_option('error_explorer_webhook_url');
        delete_option('error_explorer_capture_request');
        delete_option('error_explorer_capture_session');
        delete_option('error_explorer_capture_server');
    }
}

// AJAX handler for test error
add_action('wp_ajax_error_explorer_test_error', function() {
    if (!wp_verify_nonce($_POST['nonce'], 'error_explorer_test')) {
        wp_die(__('Security check failed', 'error-explorer'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'error-explorer'));
    }
    
    try {
        throw new Exception('Test error from Error Explorer WordPress plugin');
    } catch (Exception $e) {
        // The error will be caught by our error handler
        wp_send_json_success(__('Test error triggered', 'error-explorer'));
    }
});

// Initialize the plugin
ErrorExplorerPlugin::get_instance();