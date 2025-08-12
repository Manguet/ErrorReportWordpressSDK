<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

/**
 * Monitors SDK performance and health
 */
class SDKMonitor
{
    private array $metrics;
    private array $performanceEntries = [];
    private int $startTime;
    private string $metricsKey = 'error_explorer_sdk_metrics';
    private string $performanceKey = 'error_explorer_performance_entries';
    private int $maxPerformanceEntries = 100;

    public function __construct()
    {
        $this->startTime = time();
        $this->metrics = $this->initializeMetrics();
        $this->setupHealthCheck();
    }

    /**
     * Initialize default metrics
     */
    private function initializeMetrics(): array
    {
        $storedMetrics = get_option($this->metricsKey, []);
        
        return array_merge([
            'errorsReported' => 0,
            'errorsSuppressed' => 0,
            'retryAttempts' => 0,
            'offlineQueueSize' => 0,
            'averageResponseTime' => 0,
            'uptime' => 0,
            'memoryUsage' => 0,
            'lastErrorTime' => null
        ], $storedMetrics);
    }

    /**
     * Set up health check cron if not already scheduled
     */
    private function setupHealthCheck(): void
    {
        if (!wp_next_scheduled('error_explorer_health_check')) {
            wp_schedule_event(time(), 'error_explorer_health_interval', 'error_explorer_health_check');
        }

        // Add custom cron interval
        add_filter('cron_schedules', [$this, 'addHealthCheckInterval']);
        add_action('error_explorer_health_check', [$this, 'performHealthCheck']);
    }

    /**
     * Add custom cron interval for health checks
     */
    public function addHealthCheckInterval(array $schedules): array
    {
        $schedules['error_explorer_health_interval'] = [
            'interval' => 30, // 30 seconds
            'display' => __('Every 30 Seconds (Error Explorer Health Check)', 'error-explorer')
        ];
        return $schedules;
    }

    /**
     * Perform health check (called by cron)
     */
    public function performHealthCheck(): void
    {
        $this->updateMemoryUsage();
        $this->cleanupOldPerformanceEntries();
        $this->saveMetrics();
    }

    /**
     * Track an error being reported
     */
    public function trackError(\Exception $error, array $context = []): void
    {
        $this->metrics['errorsReported']++;
        $this->metrics['lastErrorTime'] = time();
        
        if (!empty($context['suppressed'])) {
            $this->metrics['errorsSuppressed']++;
        }
        
        $this->saveMetrics();
    }

    /**
     * Track a suppressed error
     */
    public function trackSuppressedError(string $reason): void
    {
        $this->metrics['errorsSuppressed']++;
        $this->saveMetrics();
    }

    /**
     * Track a retry attempt
     */
    public function trackRetryAttempt(): void
    {
        $this->metrics['retryAttempts']++;
        $this->saveMetrics();
    }

    /**
     * Track performance of an operation
     */
    public function trackPerformance(string $operation, float $duration, bool $success = true): void
    {
        $entry = [
            'operation' => $operation,
            'duration' => $duration,
            'timestamp' => time(),
            'success' => $success
        ];

        $this->performanceEntries[] = $entry;
        $this->updateAverageResponseTime();
        
        // Keep only recent entries
        if (count($this->performanceEntries) > $this->maxPerformanceEntries) {
            $this->performanceEntries = array_slice($this->performanceEntries, -$this->maxPerformanceEntries);
        }
        
        $this->savePerformanceEntries();
    }

    /**
     * Update offline queue size
     */
    public function updateOfflineQueueSize(int $size): void
    {
        $this->metrics['offlineQueueSize'] = $size;
        $this->saveMetrics();
    }

    /**
     * Update average response time based on recent entries
     */
    private function updateAverageResponseTime(): void
    {
        if (empty($this->performanceEntries)) {
            $this->metrics['averageResponseTime'] = 0;
            return;
        }

        // Use only the last 20 entries for average
        $recentEntries = array_slice($this->performanceEntries, -20);
        $total = array_sum(array_column($recentEntries, 'duration'));
        $this->metrics['averageResponseTime'] = $total / count($recentEntries);
    }

    /**
     * Update uptime metric
     */
    private function updateUptimeMetric(): void
    {
        $this->metrics['uptime'] = time() - $this->startTime;
    }

    /**
     * Update memory usage metric
     */
    private function updateMemoryUsage(): void
    {
        $this->metrics['memoryUsage'] = memory_get_usage(true);
    }

    /**
     * Clean up old performance entries
     */
    private function cleanupOldPerformanceEntries(): void
    {
        $cutoff = time() - (60 * 60); // Keep entries from last hour
        
        $this->performanceEntries = array_filter($this->performanceEntries, function($entry) use ($cutoff) {
            return $entry['timestamp'] > $cutoff;
        });
        
        // Re-index the array
        $this->performanceEntries = array_values($this->performanceEntries);
        $this->savePerformanceEntries();
    }

    /**
     * Get current metrics
     */
    public function getMetrics(): array
    {
        $this->updateUptimeMetric();
        $this->updateMemoryUsage();
        
        return $this->metrics;
    }

    /**
     * Assess SDK health
     */
    public function assessHealth(): array
    {
        $metrics = $this->getMetrics();
        $issues = [];
        $recommendations = [];
        $score = 100;

        // Check error suppression rate
        $errorRate = $metrics['errorsReported'] > 0 ? 
            ($metrics['errorsSuppressed'] / $metrics['errorsReported']) * 100 : 0;
        
        if ($errorRate > 50) {
            $issues[] = 'High error suppression rate';
            $recommendations[] = 'Review error filtering configuration';
            $score -= 20;
        }

        // Check average response time
        if ($metrics['averageResponseTime'] > 5000) {
            $issues[] = 'Slow average response time';
            $recommendations[] = 'Check network connectivity and server performance';
            $score -= 15;
        }

        // Check offline queue size
        if ($metrics['offlineQueueSize'] > 10) {
            $issues[] = 'Large offline queue';
            $recommendations[] = 'Check network connectivity';
            $score -= 10;
        }

        // Check memory usage (> 50MB)
        if ($metrics['memoryUsage'] > 50 * 1024 * 1024) {
            $issues[] = 'High memory usage';
            $recommendations[] = 'Consider reducing breadcrumb retention or queue sizes';
            $score -= 10;
        }

        // Check if errors are being reported recently
        $timeSinceLastError = $metrics['lastErrorTime'] ? (time() - $metrics['lastErrorTime']) : null;
        if ($timeSinceLastError !== null && $timeSinceLastError > 24 * 60 * 60) {
            $issues[] = 'No errors reported in 24 hours';
            $recommendations[] = 'Verify error reporting is working correctly';
            $score -= 5;
        }

        // Determine health status
        if ($score >= 80) {
            $status = 'healthy';
        } elseif ($score >= 60) {
            $status = 'degraded';
        } else {
            $status = 'unhealthy';
        }

        return [
            'status' => $status,
            'score' => max(0, $score),
            'issues' => $issues,
            'recommendations' => $recommendations,
            'metrics' => $metrics
        ];
    }

    /**
     * Get performance statistics
     */
    public function getPerformanceStats(): array
    {
        $entries = $this->getPerformanceEntries();
        
        if (empty($entries)) {
            return [
                'totalOperations' => 0,
                'successRate' => 0,
                'averageDuration' => 0,
                'operations' => []
            ];
        }

        $totalOperations = count($entries);
        $successfulOperations = count(array_filter($entries, function($entry) {
            return $entry['success'];
        }));
        
        $successRate = ($successfulOperations / $totalOperations) * 100;
        $averageDuration = array_sum(array_column($entries, 'duration')) / $totalOperations;
        
        // Group by operation type
        $operationStats = [];
        foreach ($entries as $entry) {
            $op = $entry['operation'];
            if (!isset($operationStats[$op])) {
                $operationStats[$op] = [
                    'count' => 0,
                    'successes' => 0,
                    'totalDuration' => 0
                ];
            }
            
            $operationStats[$op]['count']++;
            $operationStats[$op]['totalDuration'] += $entry['duration'];
            
            if ($entry['success']) {
                $operationStats[$op]['successes']++;
            }
        }

        // Calculate stats for each operation
        foreach ($operationStats as $op => &$stats) {
            $stats['successRate'] = ($stats['successes'] / $stats['count']) * 100;
            $stats['averageDuration'] = $stats['totalDuration'] / $stats['count'];
        }

        return [
            'totalOperations' => $totalOperations,
            'successRate' => $successRate,
            'averageDuration' => $averageDuration,
            'operations' => $operationStats
        ];
    }

    /**
     * Reset all metrics
     */
    public function reset(): void
    {
        $this->metrics = $this->initializeMetrics();
        $this->performanceEntries = [];
        $this->startTime = time();
        
        $this->saveMetrics();
        $this->savePerformanceEntries();
    }

    /**
     * Save metrics to WordPress options
     */
    private function saveMetrics(): void
    {
        update_option($this->metricsKey, $this->metrics, false);
    }

    /**
     * Get performance entries from storage
     */
    private function getPerformanceEntries(): array
    {
        if (empty($this->performanceEntries)) {
            $this->performanceEntries = get_transient($this->performanceKey) ?: [];
        }
        return $this->performanceEntries;
    }

    /**
     * Save performance entries to storage
     */
    private function savePerformanceEntries(): void
    {
        // Store for 1 hour
        set_transient($this->performanceKey, $this->performanceEntries, HOUR_IN_SECONDS);
    }

    /**
     * Destroy the monitor and clean up
     */
    public function destroy(): void
    {
        wp_clear_scheduled_hook('error_explorer_health_check');
        delete_option($this->metricsKey);
        delete_transient($this->performanceKey);
        
        remove_filter('cron_schedules', [$this, 'addHealthCheckInterval']);
        remove_action('error_explorer_health_check', [$this, 'performHealthCheck']);
    }
}