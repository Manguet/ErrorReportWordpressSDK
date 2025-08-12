<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

/**
 * Manages retry logic with exponential backoff for failed operations
 */
class RetryManager
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'maxRetries' => 3,
            'initialDelay' => 1000, // milliseconds
            'maxDelay' => 30000, // 30 seconds
            'backoffMultiplier' => 2,
            'jitter' => true
        ], $config);
    }

    /**
     * Execute an operation with retry logic
     */
    public function executeWithRetry(callable $operation, array $customConfig = []): array
    {
        $config = array_merge($this->config, $customConfig);
        $startTime = microtime(true);
        $lastError = null;
        $attempts = 0;

        for ($attempt = 0; $attempt <= $config['maxRetries']; $attempt++) {
            $attempts++;
            
            try {
                $result = call_user_func($operation);
                
                return [
                    'success' => true,
                    'result' => $result,
                    'attempts' => $attempts,
                    'totalTime' => (microtime(true) - $startTime) * 1000 // Convert to milliseconds
                ];
            } catch (\Exception $error) {
                $lastError = $error;

                // Don't retry on the last attempt
                if ($attempt === $config['maxRetries']) {
                    break;
                }

                // Don't retry on certain types of errors
                if ($this->shouldNotRetry($error)) {
                    break;
                }

                // Calculate delay for next attempt
                $delay = $this->calculateDelay($attempt, $config);
                
                // Sleep for the calculated delay (convert milliseconds to microseconds)
                usleep($delay * 1000);
            }
        }

        return [
            'success' => false,
            'error' => $lastError ? $lastError->getMessage() : 'Unknown error',
            'errorCode' => $lastError ? $lastError->getCode() : 0,
            'attempts' => $attempts,
            'totalTime' => (microtime(true) - $startTime) * 1000
        ];
    }

    /**
     * Determine if we should not retry based on the error
     */
    private function shouldNotRetry(\Exception $error): bool
    {
        $message = $error->getMessage();
        $code = $error->getCode();

        // Don't retry on HTTP client errors (4xx)
        if (preg_match('/400|401|403|404|422/', $message)) {
            return true;
        }

        // Don't retry on validation errors
        if ($error instanceof \InvalidArgumentException || 
            $error instanceof \TypeError ||
            stripos($message, 'validation') !== false) {
            return true;
        }

        // Don't retry on certain WordPress error codes
        if (in_array($code, [400, 401, 403, 404, 422])) {
            return true;
        }

        return false;
    }

    /**
     * Calculate the delay for the next retry attempt
     */
    private function calculateDelay(int $attempt, array $config): int
    {
        // Calculate exponential backoff
        $delay = $config['initialDelay'] * pow($config['backoffMultiplier'], $attempt);
        
        // Apply max delay cap
        $delay = min($delay, $config['maxDelay']);
        
        // Add jitter to prevent thundering herd
        if ($config['jitter']) {
            $jitterAmount = $delay * 0.1; // 10% jitter
            $jitter = (mt_rand() / mt_getrandmax() - 0.5) * 2 * $jitterAmount;
            $delay += $jitter;
        }
        
        return max(0, (int) round($delay));
    }

    /**
     * Simple retry helper method
     */
    public function retry(callable $operation): mixed
    {
        $result = $this->executeWithRetry($operation);
        
        if ($result['success']) {
            return $result['result'];
        }
        
        throw new \Exception($result['error'], $result['errorCode'] ?? 0);
    }

    /**
     * Create a retryable version of a function
     */
    public function makeRetryable(callable $function, array $customConfig = []): \Closure
    {
        return function (...$args) use ($function, $customConfig) {
            return $this->retry(function() use ($function, $args) {
                return call_user_func_array($function, $args);
            });
        };
    }

    /**
     * Retry with WordPress HTTP API integration
     */
    public function retryHttpRequest(string $url, array $args = []): array
    {
        return $this->executeWithRetry(function() use ($url, $args) {
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message(), $response->get_error_code());
            }
            
            $httpCode = wp_remote_retrieve_response_code($response);
            
            // Throw exception for HTTP errors that should trigger retries
            if ($httpCode >= 500 || $httpCode === 429) {
                throw new \Exception(
                    sprintf('HTTP %d: %s', $httpCode, wp_remote_retrieve_response_message($response)),
                    $httpCode
                );
            }
            
            // For 4xx errors, don't retry (will be caught by shouldNotRetry)
            if ($httpCode >= 400) {
                throw new \InvalidArgumentException(
                    sprintf('HTTP %d: %s', $httpCode, wp_remote_retrieve_response_message($response)),
                    $httpCode
                );
            }
            
            return $response;
        });
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $updates): void
    {
        $this->config = array_merge($this->config, $updates);
    }

    /**
     * Get retry statistics for a callable
     */
    public function benchmarkRetries(callable $operation, int $iterations = 10): array
    {
        $results = [];
        $totalSuccess = 0;
        $totalAttempts = 0;
        $totalTime = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $result = $this->executeWithRetry($operation);
            $results[] = $result;
            
            if ($result['success']) {
                $totalSuccess++;
            }
            
            $totalAttempts += $result['attempts'];
            $totalTime += $result['totalTime'];
        }

        return [
            'iterations' => $iterations,
            'successRate' => $totalSuccess / $iterations,
            'averageAttempts' => $totalAttempts / $iterations,
            'averageTime' => $totalTime / $iterations,
            'results' => $results
        ];
    }
}