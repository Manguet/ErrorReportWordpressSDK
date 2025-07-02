<?php

namespace ErrorExplorer\WordPressErrorReporter;

use ErrorExplorer\WordPressErrorReporter\Services\WebhookErrorReporter;
use ErrorExplorer\WordPressErrorReporter\Services\BreadcrumbManager;

class ErrorReporter
{
    private $webhook_reporter;
    private $breadcrumb_manager;
    private $config;

    public function __construct(string $webhookUrl, array $config = [])
    {
        $this->config = array_merge([
            'environment' => 'production',
            'capture_request_data' => true,
            'capture_session_data' => true,
            'capture_server_data' => true,
            'max_breadcrumbs' => 20,
        ], $config);

        $this->webhook_reporter = new WebhookErrorReporter($webhookUrl, $this->config);
        $this->breadcrumb_manager = new BreadcrumbManager($this->config['max_breadcrumbs']);
    }

    public function register()
    {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
        
        add_action('wp_die_handler', [$this, 'handleWpDie'], 10, 1);
        
        if (function_exists('add_action')) {
            add_action('wp_ajax_nopriv_error_explorer_test', [$this, 'handleTestError']);
            add_action('wp_ajax_error_explorer_test', [$this, 'handleTestError']);
        }
    }

    public function handleError($severity, $message, $file, $line)
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $exception = new \ErrorException($message, 0, $severity, $file, $line);
        $this->reportError($exception);
        
        return false;
    }

    public function handleException($exception)
    {
        $this->reportError($exception);
    }

    public function handleShutdown()
    {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exception = new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );
            
            $this->reportError($exception);
        }
    }

    public function handleWpDie($function)
    {
        return function($message, $title = '', $args = []) use ($function) {
            if (is_wp_error($message)) {
                $this->reportMessage(
                    $message->get_error_message(),
                    $this->config['environment'],
                    null,
                    'error',
                    ['wp_error_code' => $message->get_error_code()]
                );
            } elseif (is_string($message)) {
                $this->reportMessage($message, $this->config['environment'], null, 'error');
            }
            
            return call_user_func($function, $message, $title, $args);
        };
    }

    public function reportError(\Throwable $exception, ?int $httpStatus = null)
    {
        $breadcrumbs = $this->breadcrumb_manager->getBreadcrumbs();
        $this->webhook_reporter->reportError(
            $exception, 
            $this->config['environment'], 
            $httpStatus,
            $breadcrumbs
        );
    }

    public function reportMessage(
        string $message, 
        ?string $environment = null, 
        ?int $httpStatus = null, 
        string $level = 'error', 
        array $context = []
    ) {
        $breadcrumbs = $this->breadcrumb_manager->getBreadcrumbs();
        $this->webhook_reporter->reportMessage(
            $message,
            $environment ?? $this->config['environment'],
            $httpStatus,
            $breadcrumbs,
            $level,
            $context
        );
    }

    public function addBreadcrumb(string $message, string $category = 'custom', string $level = 'info', array $data = [])
    {
        $this->breadcrumb_manager->addBreadcrumb($message, $category, $level, $data);
    }

    public function logNavigation(string $from, string $to, array $data = [])
    {
        $this->breadcrumb_manager->logNavigation($from, $to, $data);
    }

    public function logUserAction(string $action, array $data = [])
    {
        $this->breadcrumb_manager->logUserAction($action, $data);
    }

    public function logHttpRequest(string $method, string $url, ?int $statusCode = null, array $data = [])
    {
        $this->breadcrumb_manager->logHttpRequest($method, $url, $statusCode, $data);
    }

    public function clearBreadcrumbs()
    {
        $this->breadcrumb_manager->clearBreadcrumbs();
    }

    public function handleTestError()
    {
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'error_explorer_test')) {
            wp_die('Security check failed');
        }
        
        throw new \Exception('Test error from Error Explorer WordPress plugin - ' . date('Y-m-d H:i:s'));
    }
}