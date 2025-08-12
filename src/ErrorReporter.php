<?php

namespace ErrorExplorer\WordPressErrorReporter;

use ErrorExplorer\WordPressErrorReporter\Services\WebhookErrorReporter;
use ErrorExplorer\WordPressErrorReporter\Services\BreadcrumbManager;
use ErrorExplorer\WordPressErrorReporter\Services\OfflineManager;
use ErrorExplorer\WordPressErrorReporter\Services\QuotaManager;
use ErrorExplorer\WordPressErrorReporter\Services\RateLimiter;
use ErrorExplorer\WordPressErrorReporter\Services\RetryManager;
use ErrorExplorer\WordPressErrorReporter\Services\SDKMonitor;
use ErrorExplorer\WordPressErrorReporter\Services\SecurityValidator;
use ErrorExplorer\WordPressErrorReporter\Services\BatchManager;
use ErrorExplorer\WordPressErrorReporter\Services\CompressionService;
use ErrorExplorer\WordPressErrorReporter\Services\CircuitBreaker;

class ErrorReporter
{
    private $webhook_reporter;
    private $breadcrumb_manager;
    private $offline_manager;
    private $quota_manager;
    private $rate_limiter;
    private $retry_manager;
    private $sdk_monitor;
    private $security_validator;
    private $batch_manager;
    private $compression_service;
    private $circuit_breaker;
    private $config;
    private $initialized = false;

    public function __construct(string $webhookUrl, array $config = [])
    {
        $this->config = array_merge([
            'environment' => 'production',
            'capture_request_data' => true,
            'capture_session_data' => true,
            'capture_server_data' => true,
            'max_breadcrumbs' => 20,
            // Quota configuration
            'quota_daily_limit' => 1000,
            'quota_monthly_limit' => 10000,
            'quota_payload_size_limit' => 512000,
            'quota_burst_limit' => 10,
            'quota_burst_window_ms' => 60000,
            // Rate limiting
            'rate_limit_requests_per_minute' => 60,
            'rate_limit_duplicate_window' => 300,
            // Batch configuration
            'batch_enabled' => true,
            'batch_size' => 10,
            'batch_timeout' => 5000,
            // Compression
            'compression_enabled' => true,
            'compression_threshold' => 1024,
            'compression_level' => 6,
            // Circuit breaker
            'circuit_breaker_enabled' => true,
            'circuit_breaker_failure_threshold' => 5,
            'circuit_breaker_timeout' => 30000,
            'circuit_breaker_half_open_requests' => 3,
            // Offline support
            'offline_enabled' => true,
            'offline_max_queue_size' => 50,
            'offline_max_age' => 86400,
            // Retry configuration
            'retry_max_attempts' => 3,
            'retry_delay' => 1000,
            'retry_exponential_base' => 2,
        ], $config);

        $this->initializeServices($webhookUrl);
    }

    private function initializeServices(string $webhookUrl): void
    {
        if ($this->initialized) {
            return;
        }

        // Initialize security validator first - configurable HTTPS requirement
        $requireHttps = $this->config['require_https'] ?? (wp_get_environment_type() === 'production');
        $this->security_validator = new SecurityValidator([
            'requireHttps' => $requireHttps  // Allow HTTP for local testing, require HTTPS in production
        ]);
        
        // Validate webhook URL
        if (!$this->security_validator->validateWebhookUrl($webhookUrl)) {
            throw new \InvalidArgumentException('Invalid webhook URL provided');
        }

        // Initialize core services
        $this->breadcrumb_manager = new BreadcrumbManager($this->config['max_breadcrumbs']);
        $this->sdk_monitor = new SDKMonitor();
        
        // Initialize quota and rate limiting
        $this->quota_manager = new QuotaManager([
            'dailyLimit' => $this->config['quota_daily_limit'],
            'monthlyLimit' => $this->config['quota_monthly_limit'],
            'payloadSizeLimit' => $this->config['quota_payload_size_limit'],
            'burstLimit' => $this->config['quota_burst_limit'],
            'burstWindowMs' => $this->config['quota_burst_window_ms'],
        ]);
        
        $this->rate_limiter = new RateLimiter([
            'requestsPerMinute' => $this->config['rate_limit_requests_per_minute'],
            'duplicateWindow' => $this->config['rate_limit_duplicate_window']
        ]);
        
        // Initialize network services
        $this->retry_manager = new RetryManager([
            'maxAttempts' => $this->config['retry_max_attempts'],
            'delay' => $this->config['retry_delay'],
            'exponentialBase' => $this->config['retry_exponential_base']
        ]);
        
        $this->circuit_breaker = new CircuitBreaker([
            'failureThreshold' => $this->config['circuit_breaker_failure_threshold'],
            'timeout' => $this->config['circuit_breaker_timeout'],
            'halfOpenRequests' => $this->config['circuit_breaker_half_open_requests']
        ]);
        
        // Initialize optimization services
        if ($this->config['compression_enabled']) {
            $this->compression_service = new CompressionService([
                'threshold' => $this->config['compression_threshold'],
                'level' => $this->config['compression_level']
            ]);
        }
        
        if ($this->config['batch_enabled']) {
            $this->batch_manager = new BatchManager([
                'batchSize' => $this->config['batch_size'],
                'batchTimeout' => $this->config['batch_timeout']
            ]);
            $this->batch_manager->setSendFunction([$this, 'sendBatch']);
        }
        
        if ($this->config['offline_enabled']) {
            $this->offline_manager = new OfflineManager([
                'maxQueueSize' => $this->config['offline_max_queue_size'],
                'maxAge' => $this->config['offline_max_age']
            ]);
            $this->offline_manager->setSendFunction([$this, 'sendError']);
        }
        
        // Initialize webhook reporter
        $this->webhook_reporter = new WebhookErrorReporter($webhookUrl, $this->config);
        
        $this->initialized = true;
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
        try {
            // Start monitoring (no specific startOperation method, just track performance later)
            $start_time = microtime(true);
            
            // Get breadcrumbs
            $breadcrumbs = $this->breadcrumb_manager->getBreadcrumbs();
            
            // Prepare error data
            $errorData = $this->prepareErrorData($exception, $httpStatus, $breadcrumbs);
            
            // Validate and sanitize
            if (!$this->security_validator->validateErrorData($errorData)) {
                $this->sdk_monitor->trackSuppressedError('validation_failed');
                return false;
            }
            
            $errorData = $this->security_validator->sanitizeSensitiveData($errorData);
            
            // Check quotas
            $payloadSize = strlen(json_encode($errorData));
            $quotaResult = $this->quota_manager->canSendError($payloadSize);
            
            if (!$quotaResult['allowed']) {
                $this->sdk_monitor->trackSuppressedError('quota_exceeded');
                // Queue for later if offline manager is enabled
                if ($this->offline_manager) {
                    return $this->offline_manager->handleError($errorData);
                }
                return false;
            }
            
            // Check rate limiting
            $rateLimitResult = $this->rate_limiter->canSendError($errorData);
            if (!$rateLimitResult['allowed']) {
                $this->sdk_monitor->trackSuppressedError('rate_limited');
                return false;
            }
            
            // Record rate limit usage
            $this->rate_limiter->markErrorSent($errorData);
            
            // Compress if needed
            if ($this->compression_service && $this->compression_service->shouldCompress($payloadSize)) {
                $errorData['compressed'] = true;
                $errorData['payload'] = $this->compression_service->compress(json_encode($errorData));
            }
            
            // Send or batch
            $sent = false;
            if ($this->batch_manager && $this->config['batch_enabled']) {
                $this->batch_manager->addToBatch($errorData);
                $sent = true;
            } else {
                $sent = $this->sendWithCircuitBreaker($errorData);
            }
            
            if ($sent) {
                $this->quota_manager->recordUsage($payloadSize);
                // Track successful performance
                $duration = microtime(true) - $start_time;
                $this->sdk_monitor->trackPerformance('error_report', $duration, true);
            } else {
                $this->sdk_monitor->trackError(new \Exception('send_failed'), ['operation' => 'error_report']);
                // Try offline queue
                if ($this->offline_manager) {
                    return $this->offline_manager->handleError($errorData);
                }
            }
            
            return $sent;
            
        } catch (\Exception $e) {
            $this->sdk_monitor->trackError($e, ['operation' => 'error_report']);
            error_log('ErrorExplorer: Failed to report error - ' . $e->getMessage());
            return false;
        }
    }

    private function prepareErrorData(\Throwable $exception, ?int $httpStatus, array $breadcrumbs): array
    {
        return [
            'message' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'stack_trace' => $exception->getTraceAsString(),
            'environment' => $this->config['environment'],
            'project' => $this->config['project_name'] ?? 'wordpress-error-explorer', // Champ requis par la validation
            'httpStatus' => $httpStatus,
            'breadcrumbs' => $breadcrumbs,
            'timestamp' => date('c'), // Format ISO 8601 au lieu d'un timestamp numérique
            'sdk_version' => ERROR_EXPLORER_VERSION ?? '1.0.0',
        ];
    }

    private function generateErrorHash(\Throwable $exception): string
    {
        return md5($exception->getMessage() . $exception->getFile() . $exception->getLine());
    }

    private function sendWithCircuitBreaker(array $errorData): bool
    {
        if (!$this->circuit_breaker || !$this->config['circuit_breaker_enabled']) {
            return $this->sendError($errorData);
        }
        
        return $this->circuit_breaker->execute(function() use ($errorData) {
            return $this->sendError($errorData);
        });
    }

    private function sendError(array $errorData): bool
    {
        // Les données sont maintenant déjà préparées au bon format, on utilise directement sendData
        if ($this->retry_manager) {
            $retryResult = $this->retry_manager->executeWithRetry(function() use ($errorData) {
                return $this->webhook_reporter->sendData($errorData);
            });
            // RetryManager retourne un array avec 'success' et autres infos
            return $retryResult['success'] ?? false;
        }
        
        return $this->webhook_reporter->sendData($errorData);
    }

    public function sendBatch(array $batch): bool
    {
        return $this->sendWithCircuitBreaker(['batch' => $batch, 'type' => 'batch']);
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

    public function getStats(): array
    {
        return [
            'quota' => $this->quota_manager ? $this->quota_manager->getStats() : null,
            'rate_limiter' => $this->rate_limiter ? $this->rate_limiter->getStats() : null,
            'sdk_monitor' => $this->sdk_monitor ? $this->sdk_monitor->getHealthReport() : null,
            'circuit_breaker' => $this->circuit_breaker ? ['state' => $this->circuit_breaker->getState()] : null,
            'offline_queue' => $this->offline_manager ? $this->offline_manager->getQueueStats() : null,
            'batch_manager' => $this->batch_manager ? $this->batch_manager->getStats() : null,
        ];
    }

    public function flushBatch(): void
    {
        if ($this->batch_manager) {
            $this->batch_manager->flush();
        }
    }

    public function flushOfflineQueue(): void
    {
        if ($this->offline_manager) {
            $this->offline_manager->flushQueue();
        }
    }

    public function destroy(): void
    {
        if ($this->offline_manager) {
            $this->offline_manager->destroy();
        }
        if ($this->quota_manager) {
            $this->quota_manager->destroy();
        }
        if ($this->rate_limiter) {
            $this->rate_limiter->destroy();
        }
        if ($this->batch_manager) {
            $this->batch_manager->destroy();
        }
        if ($this->circuit_breaker) {
            $this->circuit_breaker->reset();
        }
        
        $this->initialized = false;
    }
}