<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

/**
 * Manages batching of errors for efficient sending
 */
class BatchManager
{
    private array $config;
    private array $currentBatch = [];
    private $sendFunction = null;
    private int $batchCounter = 0;
    private string $batchKey = 'error_explorer_current_batch';
    private string $counterKey = 'error_explorer_batch_counter';

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enabled' => true,
            'maxSize' => 10,
            'maxWaitTime' => 5, // seconds
            'maxPayloadSize' => 500 * 1024 // 500KB
        ], $config);

        $this->batchCounter = get_option($this->counterKey, 0);
        $this->currentBatch = get_transient($this->batchKey) ?: [];

        // Schedule batch processing if not already scheduled
        if (!wp_next_scheduled('error_explorer_process_batch')) {
            wp_schedule_event(time(), 'error_explorer_batch_interval', 'error_explorer_process_batch');
        }

        // Add custom cron interval
        add_filter('cron_schedules', [$this, 'addBatchInterval']);
        add_action('error_explorer_process_batch', [$this, 'processScheduledBatch']);
    }

    /**
     * Add custom cron interval for batch processing
     */
    public function addBatchInterval(array $schedules): array
    {
        $schedules['error_explorer_batch_interval'] = [
            'interval' => $this->config['maxWaitTime'],
            'display' => sprintf(__('Every %d Seconds (Error Explorer Batch)', 'error-explorer'), $this->config['maxWaitTime'])
        ];
        return $schedules;
    }

    /**
     * Set the function to send batched errors
     */
    public function setSendFunction(callable $sendFunction): void
    {
        $this->sendFunction = $sendFunction;
    }

    /**
     * Add an error to the batch
     */
    public function addError(array $errorData): void
    {
        if (!$this->config['enabled'] || $this->sendFunction === null) {
            // If batching disabled, send immediately
            if ($this->sendFunction !== null) {
                $batch = [
                    'errors' => [$errorData],
                    'batchId' => $this->generateBatchId(),
                    'timestamp' => current_time('mysql'),
                    'count' => 1
                ];
                call_user_func($this->sendFunction, $batch);
            }
            return;
        }

        $this->currentBatch[] = $errorData;
        $this->saveBatch();

        // Check if we should send the batch immediately
        if ($this->shouldSendBatch()) {
            $this->sendCurrentBatch();
        } else {
            // Schedule batch processing if not already scheduled for soon
            if (!wp_next_scheduled('error_explorer_send_batch_now')) {
                wp_schedule_single_event(time() + $this->config['maxWaitTime'], 'error_explorer_send_batch_now');
                add_action('error_explorer_send_batch_now', [$this, 'sendCurrentBatch']);
            }
        }
    }

    /**
     * Check if the batch should be sent immediately
     */
    private function shouldSendBatch(): bool
    {
        // Check batch size
        if (count($this->currentBatch) >= $this->config['maxSize']) {
            return true;
        }

        // Check payload size
        $estimatedSize = $this->estimateBatchSize();
        return $estimatedSize >= $this->config['maxPayloadSize'];
    }

    /**
     * Estimate the size of the current batch
     */
    private function estimateBatchSize(): int
    {
        if (empty($this->currentBatch)) {
            return 0;
        }

        $batchData = [
            'errors' => $this->currentBatch,
            'batchId' => 'estimate',
            'timestamp' => current_time('mysql'),
            'count' => count($this->currentBatch)
        ];

        return strlen(wp_json_encode($batchData));
    }

    /**
     * Send the current batch
     */
    public function sendCurrentBatch(): void
    {
        if (empty($this->currentBatch) || $this->sendFunction === null) {
            return;
        }

        $batch = [
            'errors' => $this->currentBatch,
            'batchId' => $this->generateBatchId(),
            'timestamp' => current_time('mysql'),
            'count' => count($this->currentBatch)
        ];

        // Clear current batch
        $this->currentBatch = [];
        $this->saveBatch();

        // Clear any scheduled batch sending
        wp_clear_scheduled_hook('error_explorer_send_batch_now');

        try {
            call_user_func($this->sendFunction, $batch);
        } catch (\Exception $e) {
            // Log error but don't throw to prevent breaking the site
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[BatchManager] Failed to send batch: %s', $e->getMessage()));
            }
        }
    }

    /**
     * Process batch via scheduled cron (fallback)
     */
    public function processScheduledBatch(): void
    {
        if (!empty($this->currentBatch)) {
            $this->sendCurrentBatch();
        }
    }

    /**
     * Flush any pending batch immediately
     */
    public function flush(): void
    {
        if (!empty($this->currentBatch)) {
            $this->sendCurrentBatch();
        }
    }

    /**
     * Generate a unique batch ID
     */
    private function generateBatchId(): string
    {
        $this->batchCounter++;
        update_option($this->counterKey, $this->batchCounter, false);
        
        $timestamp = time();
        $random = substr(md5(uniqid(mt_rand(), true)), 0, 8);
        
        return sprintf('batch_%d_%d_%s', $timestamp, $this->batchCounter, $random);
    }

    /**
     * Get current batch statistics
     */
    public function getStats(): array
    {
        $timeUntilFlush = 0;
        $nextScheduled = wp_next_scheduled('error_explorer_send_batch_now');
        
        if ($nextScheduled) {
            $timeUntilFlush = max(0, $nextScheduled - time());
        }

        return [
            'currentBatchSize' => count($this->currentBatch),
            'hasPendingBatch' => !empty($this->currentBatch),
            'timeUntilFlush' => $timeUntilFlush,
            'estimatedPayloadSize' => $this->estimateBatchSize(),
            'batchCounter' => $this->batchCounter,
            'config' => $this->config
        ];
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $updates): void
    {
        $oldEnabled = $this->config['enabled'];
        $this->config = array_merge($this->config, $updates);

        // If batching was disabled, flush current batch
        if ($oldEnabled && !$this->config['enabled']) {
            $this->flush();
        }

        // Update cron schedule if interval changed
        if (isset($updates['maxWaitTime'])) {
            wp_clear_scheduled_hook('error_explorer_process_batch');
            if (!wp_next_scheduled('error_explorer_process_batch')) {
                wp_schedule_event(time(), 'error_explorer_batch_interval', 'error_explorer_process_batch');
            }
        }
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Save current batch to storage
     */
    private function saveBatch(): void
    {
        if (empty($this->currentBatch)) {
            delete_transient($this->batchKey);
        } else {
            // Store batch for max wait time plus some buffer
            $expiration = $this->config['maxWaitTime'] + 60;
            set_transient($this->batchKey, $this->currentBatch, $expiration);
        }
    }

    /**
     * Clear the current batch without sending
     */
    public function clearBatch(): void
    {
        $this->currentBatch = [];
        $this->saveBatch();
        wp_clear_scheduled_hook('error_explorer_send_batch_now');
    }

    /**
     * Split a large batch into smaller chunks
     */
    public function splitBatch(array $errors, int $maxSize = null): array
    {
        $maxSize = $maxSize ?? $this->config['maxSize'];
        return array_chunk($errors, $maxSize);
    }

    /**
     * Get batch history (for debugging)
     */
    public function getBatchHistory(): array
    {
        $history = get_option('error_explorer_batch_history', []);
        
        // Keep only the last 10 batches
        if (count($history) > 10) {
            $history = array_slice($history, -10);
            update_option('error_explorer_batch_history', $history, false);
        }
        
        return $history;
    }

    /**
     * Record batch in history (for debugging)
     */
    public function recordBatchHistory(array $batch): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $history = get_option('error_explorer_batch_history', []);
        $history[] = [
            'batchId' => $batch['batchId'],
            'count' => $batch['count'],
            'timestamp' => $batch['timestamp'],
            'sent_at' => current_time('mysql')
        ];
        
        // Keep only the last 10 entries
        if (count($history) > 10) {
            $history = array_slice($history, -10);
        }
        
        update_option('error_explorer_batch_history', $history, false);
    }

    /**
     * Destroy the batch manager and clean up
     */
    public function destroy(): void
    {
        wp_clear_scheduled_hook('error_explorer_process_batch');
        wp_clear_scheduled_hook('error_explorer_send_batch_now');
        
        delete_transient($this->batchKey);
        
        // Try to flush any remaining errors
        if (!empty($this->currentBatch) && $this->sendFunction !== null) {
            try {
                $this->sendCurrentBatch();
            } catch (\Exception $e) {
                // Ignore errors during cleanup
            }
        }
        
        remove_filter('cron_schedules', [$this, 'addBatchInterval']);
        remove_action('error_explorer_process_batch', [$this, 'processScheduledBatch']);
        
        $this->sendFunction = null;
    }
}