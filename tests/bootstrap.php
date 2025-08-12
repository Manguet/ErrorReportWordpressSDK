<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Mock WordPress functions for testing
if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type() {
        return 'testing';
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite() {
        return false;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return false;
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() {
        return (object) [
            'ID' => 0,
            'user_login' => '',
            'user_email' => '',
            'roles' => [],
        ];
    }
}

if (!function_exists('get_current_screen')) {
    function get_current_screen() {
        return null;
    }
}

if (!function_exists('wp_get_theme')) {
    function wp_get_theme() {
        return new class {
            public function get($key) {
                $data = [
                    'Name' => 'Test Theme',
                    'Version' => '1.0.0',
                ];
                return $data[$key] ?? '';
            }
            
            public function get_template() {
                return 'test-theme';
            }
        };
    }
}

if (!function_exists('get_plugins')) {
    function get_plugins() {
        return [];
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1) {
        // Mock implementation for testing
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $args = 1) {
        // Mock implementation for testing
        return true;
    }
}

if (!function_exists('add_options_page')) {
    function add_options_page($page_title, $menu_title, $capability, $menu_slug, $callback) {
        return true;
    }
}

if (!function_exists('register_setting')) {
    function register_setting($group, $option_name) {
        return true;
    }
}

if (!function_exists('add_settings_section')) {
    function add_settings_section($id, $title, $callback, $page) {
        return true;
    }
}

if (!function_exists('add_settings_field')) {
    function add_settings_field($id, $title, $callback, $page, $section) {
        return true;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {
        return true;
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {
        return true;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action) {
        return 'test_nonce_' . md5($action);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action) {
        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true;
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message, $title = '', $args = []) {
        die($message);
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) {
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        $options = [
            'error_explorer_webhook_url' => 'https://example.com/webhook/error/test-token',
            'error_explorer_enabled' => true,
            'error_explorer_capture_request' => true,
            'error_explorer_capture_session' => true,
            'error_explorer_capture_server' => true,
        ];
        
        return $options[$option] ?? $default;
    }
}

// Global WordPress version
$GLOBALS['wp_version'] = '6.4.0';

// Additional mocks for new services
if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $test_transients;
        if (!isset($test_transients)) {
            $test_transients = [];
        }
        return $test_transients[$transient] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        global $test_transients;
        if (!isset($test_transients)) {
            $test_transients = [];
        }
        $test_transients[$transient] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        global $test_transients;
        if (!isset($test_transients)) {
            $test_transients = [];
        }
        unset($test_transients[$transient]);
        return true;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = true) {
        global $test_options;
        if (!isset($test_options)) {
            $test_options = [];
        }
        $test_options[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $test_options;
        if (!isset($test_options)) {
            $test_options = [];
        }
        unset($test_options[$option]);
        return true;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
        return true;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        return false;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = []) {
        return true;
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        return ['response' => ['code' => 200], 'body' => 'OK'];
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = []) {
        return ['response' => ['code' => 200], 'body' => 'OK'];
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        if ($type === 'timestamp') {
            return time();
        }
        return date($type, time());
    }
}

// WordPress constants
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// WP_Error class mock
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $errors = [];
        private $error_data = [];
        
        public function __construct($code = '', $message = '', $data = '') {
            if ($code) {
                $this->errors[$code][] = $message;
                if ($data) {
                    $this->error_data[$code] = $data;
                }
            }
        }
        
        public function get_error_code() {
            $codes = array_keys($this->errors);
            return $codes[0] ?? '';
        }
        
        public function get_error_message($code = '') {
            if (!$code) {
                $code = $this->get_error_code();
            }
            return $this->errors[$code][0] ?? '';
        }
    }
}

// Define ERROR_EXPLORER_VERSION if not defined
if (!defined('ERROR_EXPLORER_VERSION')) {
    define('ERROR_EXPLORER_VERSION', '2.0.0');
}