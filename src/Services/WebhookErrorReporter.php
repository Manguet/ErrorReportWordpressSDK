<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class WebhookErrorReporter
{
    private $httpClient;
    private $webhookUrl;
    private $config;

    public function __construct(string $webhookUrl, array $config = [])
    {
        $this->webhookUrl = $webhookUrl;
        $this->config = array_merge([
            'project_name' => 'WordPress Site',
            'environment' => 'production',
            'capture_request_data' => true,
            'capture_session_data' => true,
            'capture_server_data' => true,
        ], $config);
        
        $this->httpClient = new Client();
    }

    public function reportError(\Throwable $exception, string $environment, ?int $httpStatus = null, array $breadcrumbs = [])
    {
        try {
            $payload = $this->buildPayload($exception, $environment, $httpStatus, $breadcrumbs);
            return $this->sendWebhook($payload);
        } catch (\Throwable $e) {
            error_log('Failed to report error to Error Explorer: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send raw data to webhook
     */
    public function sendData(array $data): bool
    {
        try {
            return $this->sendWebhook($data);
        } catch (\Throwable $e) {
            error_log('Failed to send data to Error Explorer: ' . $e->getMessage());
            return false;
        }
    }

    public function reportMessage(
        string $message, 
        string $environment, 
        ?int $httpStatus = null, 
        array $breadcrumbs = [],
        string $level = 'error', 
        array $context = []
    ) {
        try {
            $payload = $this->buildMessagePayload($message, $environment, $httpStatus, $breadcrumbs, $level, $context);
            $this->sendWebhook($payload);
        } catch (\Throwable $e) {
            error_log('Failed to report message to Error Explorer: ' . $e->getMessage());
        }
    }

    private function buildPayload(\Throwable $exception, string $environment, ?int $httpStatus, array $breadcrumbs): array
    {
        $payload = [
            'message' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'stack_trace' => $exception->getTraceAsString(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'project' => $this->config['project_name'],
            'environment' => $environment,
            'timestamp' => date('c'),
        ];

        if ($httpStatus) {
            $payload['http_status'] = $httpStatus;
        }

        if ($this->config['capture_request_data']) {
            $payload['request'] = $this->getRequestData();
        }

        if ($this->config['capture_server_data']) {
            $payload['server'] = $this->getServerData();
        }

        if ($this->config['capture_session_data']) {
            $payload['session'] = $this->getSessionData();
        }

        if (!empty($breadcrumbs)) {
            $payload['breadcrumbs'] = $breadcrumbs;
        }

        $payload['wordpress'] = $this->getWordPressData();

        return $payload;
    }

    private function buildMessagePayload(
        string $message, 
        string $environment, 
        ?int $httpStatus, 
        array $breadcrumbs,
        string $level, 
        array $context
    ): array {
        $payload = [
            'message' => $message,
            'exception_class' => 'CustomMessage',
            'stack_trace' => $this->generateStackTrace(),
            'file' => null,
            'line' => null,
            'project' => $this->config['project_name'],
            'environment' => $environment,
            'timestamp' => date('c'),
            'level' => $level,
            'context' => $context
        ];

        if ($httpStatus) {
            $payload['http_status'] = $httpStatus;
        }

        if ($this->config['capture_request_data']) {
            $payload['request'] = $this->getRequestData();
        }

        if ($this->config['capture_server_data']) {
            $payload['server'] = $this->getServerData();
        }

        if ($this->config['capture_session_data']) {
            $payload['session'] = $this->getSessionData();
        }

        if (!empty($breadcrumbs)) {
            $payload['breadcrumbs'] = $breadcrumbs;
        }

        $payload['wordpress'] = $this->getWordPressData();

        return $payload;
    }

    private function getRequestData(): array
    {
        $data = [
            'url' => $this->getCurrentUrl(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'ip' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
        ];

        if (!empty($_GET)) {
            $data['get'] = $this->sanitizeParameters($_GET);
        }

        if (!empty($_POST)) {
            $data['post'] = $this->sanitizeParameters($_POST);
        }

        $data['headers'] = $this->sanitizeHeaders($this->getHeaders());

        return $data;
    }

    private function getServerData(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
            'memory_usage' => memory_get_usage(),
            'memory_peak' => memory_get_peak_usage(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
            'server_name' => $_SERVER['SERVER_NAME'] ?? '',
            'server_port' => $_SERVER['SERVER_PORT'] ?? '',
        ];
    }

    private function getSessionData(): array
    {
        $data = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $data['session_id'] = session_id();
            $data['session_name'] = session_name();
        }

        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $data['user'] = [
                'id' => $current_user->ID,
                'login' => $current_user->user_login,
                'email' => $current_user->user_email,
                'roles' => $current_user->roles,
            ];
        }

        return $data;
    }

    private function getWordPressData(): array
    {
        global $wp_version;

        $data = [
            'version' => $wp_version ?? 'unknown',
            'multisite' => is_multisite(),
            'debug' => defined('WP_DEBUG') ? WP_DEBUG : false,
            'debug_log' => defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : false,
        ];

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen) {
                $data['current_screen'] = [
                    'id' => $screen->id,
                    'base' => $screen->base,
                    'post_type' => $screen->post_type,
                ];
            }
        }

        if (function_exists('wp_get_theme')) {
            $theme = wp_get_theme();
            $data['theme'] = [
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'template' => $theme->get_template(),
            ];
        }

        if (function_exists('get_plugins')) {
            $plugins = get_plugins();
            $active_plugins = get_option('active_plugins', []);
            $data['plugins'] = [
                'total' => count($plugins),
                'active' => count($active_plugins),
            ];
        }

        return $data;
    }

    private function getCurrentUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        return $protocol . '://' . $host . $uri;
    }

    private function getClientIp(): string
    {
        $ip_headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ip_headers as $header) {
            if (array_key_exists($header, $_SERVER) && !empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim($ips[0]);
            }
        }

        return 'unknown';
    }

    private function getHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', substr($key, 5));
                $headers[$header] = $value;
            }
        }

        return $headers;
    }

    private function generateStackTrace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $trace = array_slice($trace, 3);
        
        $stackTrace = '';
        foreach ($trace as $i => $call) {
            $file = $call['file'] ?? '[internal]';
            $line = $call['line'] ?? 0;
            $function = $call['function'] ?? '';
            $class = $call['class'] ?? '';
            
            if ($class) {
                $function = $class . '::' . $function;
            }
            
            $stackTrace .= "#{$i} {$file}({$line}): {$function}()\n";
        }
        
        return $stackTrace;
    }


    private function sanitizeParameters(array $parameters): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'api_key', 'authorization', 'password_confirmation'];

        foreach ($parameters as $key => $value) {
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (stripos($key, $sensitiveKey) !== false) {
                    $parameters[$key] = '[REDACTED]';
                    break;
                }
            }
        }

        return $parameters;
    }

    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'cookie', 'x-api-key', 'x-auth-token'];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveHeaders)) {
                $headers[$key] = '[REDACTED]';
            }
        }

        return $headers;
    }

    private function sendWebhook(array $payload): void
    {
        if (empty($this->webhookUrl)) {
            return;
        }

        $this->httpClient->request('POST', $this->webhookUrl, [
            'json' => $payload,
            'timeout' => 5,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'ErrorExplorer-WordPress-SDK/1.0'
            ]
        ]);
    }
}