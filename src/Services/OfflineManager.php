<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

/**
 * Manages offline error storage using WordPress transients
 */
class OfflineManager
{
    private int $maxQueueSize;
    private int $maxAge;
    private string $queueKey = 'error_explorer_offline_queue';
    private $sendFunction = null;
    private bool $processingQueue = false;

    public function __construct(int $maxQueueSize = 50, int $maxAge = 86400)
    {
        $this->maxQueueSize = $maxQueueSize;
        $this->maxAge = $maxAge; // 24 hours in seconds
        
        // Schedule cleanup if not already scheduled
        if (!wp_next_scheduled('error_explorer_cleanup_offline_queue')) {
            wp_schedule_event(time(), 'hourly', 'error_explorer_cleanup_offline_queue');
        }
        
        add_action('error_explorer_cleanup_offline_queue', [$this, 'cleanupQueue']);
        add_action('error_explorer_process_offline_queue', [$this, 'processQueue']);
    }

    /**
     * Set the function to send errors
     */
    public function setSendFunction(callable $sendFunction): void
    {
        $this->sendFunction = $sendFunction;
    }

    /**
     * Handle an error, either sending it immediately or queuing it
     */
    public function handleError(array $errorData): bool
    {
        // Try to send immediately if online
        if ($this->isOnline() && $this->sendFunction !== null) {
            try {
                $result = call_user_func($this->sendFunction, $errorData);
                if ($result === true) {
                    return true;
                }
            } catch (\Exception $e) {
                // Failed to send, queue it
            }
        }
        
        // Queue the error for later processing
        return $this->queueError($errorData);
    }

    /**
     * Queue an error for offline processing
     */
    private function queueError(array $errorData): bool
    {
        $queue = $this->getQueue();
        
        $queueItem = [
            'id' => $this->generateId(),
            'errorData' => $errorData,
            'timestamp' => time(),
            'attempts' => 0
        ];
        
        // Add to queue
        $queue[] = $queueItem;
        
        // Enforce max queue size
        if (count($queue) > $this->maxQueueSize) {
            // Sort by timestamp and keep only the most recent
            usort($queue, function($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });
            $queue = array_slice($queue, 0, $this->maxQueueSize);
        }
        
        // Save queue
        $this->saveQueue($queue);
        
        // Schedule processing if not already scheduled
        if (!wp_next_scheduled('error_explorer_process_offline_queue')) {
            wp_schedule_single_event(time() + 60, 'error_explorer_process_offline_queue');
        }
        
        return true;
    }

    /**
     * Process the offline queue
     */
    public function processQueue(): void
    {
        if ($this->processingQueue || !$this->isOnline() || $this->sendFunction === null) {
            return;
        }
        
        $this->processingQueue = true;
        
        try {
            $queue = $this->getQueue();
            $processedItems = [];
            
            foreach ($queue as $key => $item) {
                try {
                    $result = call_user_func($this->sendFunction, $item['errorData']);
                    if ($result === true) {
                        $processedItems[] = $item['id'];
                    } else {
                        // Increment attempts
                        $queue[$key]['attempts']++;
                        
                        // Remove if too many attempts
                        if ($queue[$key]['attempts'] >= 3) {
                            $processedItems[] = $item['id'];
                        }
                    }
                } catch (\Exception $e) {
                    // Increment attempts on exception
                    $queue[$key]['attempts']++;
                    
                    if ($queue[$key]['attempts'] >= 3) {
                        $processedItems[] = $item['id'];
                    }
                }
            }
            
            // Remove processed items
            $queue = array_filter($queue, function($item) use ($processedItems) {
                return !in_array($item['id'], $processedItems);
            });
            
            // Re-index array
            $queue = array_values($queue);
            
            $this->saveQueue($queue);
            
            // Reschedule if there are still items
            if (!empty($queue)) {
                wp_schedule_single_event(time() + 300, 'error_explorer_process_offline_queue');
            }
            
        } finally {
            $this->processingQueue = false;
        }
    }

    /**
     * Clean up old queue items
     */
    public function cleanupQueue(): void
    {
        $queue = $this->getQueue();
        $cutoff = time() - $this->maxAge;
        
        $queue = array_filter($queue, function($item) use ($cutoff) {
            return $item['timestamp'] > $cutoff;
        });
        
        // Re-index array
        $queue = array_values($queue);
        
        $this->saveQueue($queue);
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(): array
    {
        $queue = $this->getQueue();
        
        $oldestTimestamp = null;
        if (!empty($queue)) {
            $timestamps = array_column($queue, 'timestamp');
            $oldestTimestamp = min($timestamps);
        }
        
        return [
            'size' => count($queue),
            'oldestTimestamp' => $oldestTimestamp,
            'isOnline' => $this->isOnline(),
            'isProcessing' => $this->processingQueue
        ];
    }

    /**
     * Flush the queue (try to process all items immediately)
     */
    public function flushQueue(): void
    {
        if ($this->isOnline()) {
            $this->processQueue();
        }
    }

    /**
     * Clear the entire queue
     */
    public function clearQueue(): void
    {
        delete_transient($this->queueKey);
    }

    /**
     * Check if the system is online
     */
    private function isOnline(): bool
    {
        // Check if we can reach a reliable endpoint
        $response = wp_remote_get('https://www.google.com', [
            'timeout' => 5,
            'sslverify' => false
        ]);
        
        return !is_wp_error($response);
    }

    /**
     * Generate a unique ID
     */
    private function generateId(): string
    {
        return wp_generate_uuid4();
    }

    /**
     * Get the queue from storage
     */
    private function getQueue(): array
    {
        $queue = get_transient($this->queueKey);
        
        if ($queue === false || !is_array($queue)) {
            return [];
        }
        
        return $queue;
    }

    /**
     * Save the queue to storage
     */
    private function saveQueue(array $queue): void
    {
        // Use a long expiration (30 days) as we manage cleanup ourselves
        set_transient($this->queueKey, $queue, 30 * DAY_IN_SECONDS);
    }

    /**
     * Destroy the manager and clean up
     */
    public function destroy(): void
    {
        wp_clear_scheduled_hook('error_explorer_cleanup_offline_queue');
        wp_clear_scheduled_hook('error_explorer_process_offline_queue');
        $this->clearQueue();
        $this->sendFunction = null;
    }
}