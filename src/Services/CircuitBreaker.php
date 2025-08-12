<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

/**
 * Circuit breaker pattern implementation for error reporting resilience
 */
class CircuitBreaker
{
    const STATE_CLOSED = 'CLOSED';
    const STATE_OPEN = 'OPEN';
    const STATE_HALF_OPEN = 'HALF_OPEN';

    private array $config;
    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private int $successCount = 0;
    private int $lastFailureTime = 0;
    private int $stateChangeTime;
    private array $requests = [];
    private string $stateKey = 'error_explorer_circuit_breaker_state';
    private string $requestsKey = 'error_explorer_circuit_breaker_requests';

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'failureThreshold' => 5, // Number of failures to trigger open state
            'resetTimeout' => 60000, // Time in milliseconds before attempting reset
            'monitoringPeriod' => 300000, // 5 minutes in milliseconds  
            'minimumRequests' => 3 // Minimum requests before evaluating failure rate
        ], $config);

        $this->stateChangeTime = time() * 1000; // Convert to milliseconds
        $this->loadState();
        
        // Schedule cleanup if not already scheduled
        if (!wp_next_scheduled('error_explorer_cleanup_circuit_breaker')) {
            wp_schedule_event(time(), 'hourly', 'error_explorer_cleanup_circuit_breaker');
        }
        
        add_action('error_explorer_cleanup_circuit_breaker', [$this, 'cleanup']);
    }

    /**
     * Execute an operation with circuit breaker protection
     */
    public function execute(callable $operation)
    {
        if ($this->state === self::STATE_OPEN) {
            if ($this->shouldAttemptReset()) {
                $this->state = self::STATE_HALF_OPEN;
                $this->stateChangeTime = time() * 1000;
                $this->saveState();
            } else {
                throw new \Exception('Circuit breaker is OPEN - operation not executed');
            }
        }

        try {
            $startTime = microtime(true);
            $result = call_user_func($operation);
            $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            
            $this->onSuccess();
            return $result;
        } catch (\Exception $error) {
            $this->onFailure();
            throw $error;
        }
    }

    /**
     * Handle successful operation
     */
    private function onSuccess(): void
    {
        $this->successCount++;
        $now = time() * 1000;
        
        $this->requests[] = [
            'timestamp' => $now,
            'success' => true
        ];
        
        if ($this->state === self::STATE_HALF_OPEN) {
            $this->state = self::STATE_CLOSED;
            $this->failureCount = 0;
            $this->stateChangeTime = $now;
        }
        
        $this->cleanupRequests();
        $this->saveState();
        $this->saveRequests();
    }

    /**
     * Handle failed operation
     */
    private function onFailure(): void
    {
        $this->failureCount++;
        $now = time() * 1000;
        $this->lastFailureTime = $now;
        
        $this->requests[] = [
            'timestamp' => $now,
            'success' => false
        ];
        
        if ($this->shouldOpenCircuit()) {
            $this->state = self::STATE_OPEN;
            $this->stateChangeTime = $now;
        }
        
        $this->cleanupRequests();
        $this->saveState();
        $this->saveRequests();
    }

    /**
     * Clean up old request records
     */
    private function cleanupRequests(): void
    {
        $cutoff = (time() * 1000) - $this->config['monitoringPeriod'];
        
        $this->requests = array_values(array_filter($this->requests, function($request) use ($cutoff) {
            return $request['timestamp'] > $cutoff;
        }));
    }

    /**
     * Determine if circuit should be opened
     */
    private function shouldOpenCircuit(): bool
    {
        $this->cleanupRequests();

        if (count($this->requests) < $this->config['minimumRequests']) {
            return false;
        }

        $failures = array_filter($this->requests, function($request) {
            return !$request['success'];
        });
        
        $failureRate = count($failures) / count($this->requests);
        $failureThreshold = $this->config['failureThreshold'] / 10; // Convert to percentage

        return $failureRate >= $failureThreshold;
    }

    /**
     * Determine if reset should be attempted
     */
    private function shouldAttemptReset(): bool
    {
        $now = time() * 1000;
        return ($now - $this->stateChangeTime) >= $this->config['resetTimeout'];
    }

    /**
     * Check if operation can be executed
     */
    public function canExecute(): bool
    {
        if ($this->state === self::STATE_CLOSED) {
            return true;
        }

        if ($this->state === self::STATE_OPEN && $this->shouldAttemptReset()) {
            $this->state = self::STATE_HALF_OPEN;
            $this->stateChangeTime = time() * 1000;
            $this->saveState();
            return true;
        }

        return $this->state === self::STATE_HALF_OPEN;
    }

    /**
     * Get circuit breaker statistics
     */
    public function getStats(): array
    {
        $this->cleanupRequests();
        
        $now = time() * 1000;
        $totalRequests = count($this->requests);
        $failures = array_filter($this->requests, function($request) {
            return !$request['success'];
        });
        $failureRate = $totalRequests > 0 ? count($failures) / $totalRequests : 0;
        
        $stats = [
            'state' => $this->state,
            'failureCount' => $this->failureCount,
            'successCount' => $this->successCount,
            'totalRequests' => $totalRequests,
            'failureRate' => $failureRate,
            'timeInCurrentState' => $now - $this->stateChangeTime,
            'lastFailureTime' => $this->lastFailureTime,
            'canExecute' => $this->canExecute(),
            'config' => $this->config
        ];

        if ($this->state === self::STATE_OPEN) {
            $stats['nextRetryTime'] = $this->stateChangeTime + $this->config['resetTimeout'];
            $stats['timeUntilReset'] = max(0, $stats['nextRetryTime'] - $now);
        }

        return $stats;
    }

    /**
     * Reset circuit breaker to initial state
     */
    public function reset(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->lastFailureTime = 0;
        $this->stateChangeTime = time() * 1000;
        $this->requests = [];
        
        $this->saveState();
        $this->saveRequests();
    }

    /**
     * Force circuit breaker to open state
     */
    public function forceOpen(): void
    {
        $this->state = self::STATE_OPEN;
        $this->stateChangeTime = time() * 1000;
        $this->saveState();
    }

    /**
     * Force circuit breaker to closed state
     */
    public function forceClose(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->stateChangeTime = time() * 1000;
        $this->saveState();
    }

    /**
     * Get current state
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Clean up old data (called by cron)
     */
    public function cleanup(): void
    {
        $this->cleanupRequests();
        $this->saveRequests();
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $updates): void
    {
        $this->config = array_merge($this->config, $updates);
        
        // Validate configuration values
        $this->config['failureThreshold'] = max(1, (int) $this->config['failureThreshold']);
        $this->config['resetTimeout'] = max(1000, (int) $this->config['resetTimeout']);
        $this->config['monitoringPeriod'] = max(60000, (int) $this->config['monitoringPeriod']);
        $this->config['minimumRequests'] = max(1, (int) $this->config['minimumRequests']);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Create a circuit breaker protected version of a function
     */
    public function protect(callable $function): \Closure
    {
        return function (...$args) use ($function) {
            return $this->execute(function () use ($function, $args) {
                return call_user_func_array($function, $args);
            });
        };
    }

    /**
     * Test circuit breaker functionality
     */
    public function test(): array
    {
        $results = [];
        $originalState = $this->state;
        
        try {
            // Test successful operation
            $result = $this->execute(function () {
                return 'success';
            });
            $results['success_test'] = $result === 'success';
            
            // Test failed operation
            try {
                $this->execute(function () {
                    throw new \Exception('Test failure');
                });
                $results['failure_test'] = false; // Should have thrown
            } catch (\Exception $e) {
                $results['failure_test'] = $e->getMessage() === 'Test failure';
            }
            
            // Test state management
            $stateBefore = $this->state;
            $this->forceOpen();
            $results['force_open'] = $this->state === self::STATE_OPEN;
            
            $this->forceClose();
            $results['force_close'] = $this->state === self::STATE_CLOSED;
            
            $results['overall'] = array_reduce($results, function ($carry, $item) {
                return $carry && $item;
            }, true);
            
        } finally {
            // Restore original state
            $this->state = $originalState;
            $this->saveState();
        }
        
        return $results;
    }

    /**
     * Load state from storage
     */
    private function loadState(): void
    {
        $savedState = get_transient($this->stateKey);
        if ($savedState !== false) {
            $this->state = $savedState['state'] ?? self::STATE_CLOSED;
            $this->failureCount = $savedState['failureCount'] ?? 0;
            $this->successCount = $savedState['successCount'] ?? 0;
            $this->lastFailureTime = $savedState['lastFailureTime'] ?? 0;
            $this->stateChangeTime = $savedState['stateChangeTime'] ?? (time() * 1000);
        }
        
        $savedRequests = get_transient($this->requestsKey);
        if ($savedRequests !== false) {
            $this->requests = $savedRequests;
        }
        
        // Clean up old requests on load
        $this->cleanupRequests();
    }

    /**
     * Save state to storage
     */
    private function saveState(): void
    {
        $state = [
            'state' => $this->state,
            'failureCount' => $this->failureCount,
            'successCount' => $this->successCount,
            'lastFailureTime' => $this->lastFailureTime,
            'stateChangeTime' => $this->stateChangeTime
        ];
        
        // Store for longer than monitoring period
        $expiration = ($this->config['monitoringPeriod'] * 2) / 1000;
        set_transient($this->stateKey, $state, (int) $expiration);
    }

    /**
     * Save requests to storage
     */
    private function saveRequests(): void
    {
        // Store for monitoring period duration
        $expiration = $this->config['monitoringPeriod'] / 1000;
        set_transient($this->requestsKey, $this->requests, (int) $expiration);
    }

    /**
     * Destroy circuit breaker and clean up
     */
    public function destroy(): void
    {
        wp_clear_scheduled_hook('error_explorer_cleanup_circuit_breaker');
        delete_transient($this->stateKey);
        delete_transient($this->requestsKey);
        remove_action('error_explorer_cleanup_circuit_breaker', [$this, 'cleanup']);
    }
}