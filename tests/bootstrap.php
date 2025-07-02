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