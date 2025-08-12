<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

/**
 * Rate limiter to prevent overwhelming the API and handle duplicate errors
 */
class RateLimiter
{
    private array $config;
    private string $requestsKey = 'error_explorer_rate_limit_requests';
    private string $errorsKey = 'error_explorer_rate_limit_errors';

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'maxRequests' => 100,
            'windowMs' => 60000, // 1 minute in milliseconds
            'duplicateErrorWindow' => 300000 // 5 minutes in milliseconds
        ], $config);

        // Schedule cleanup if not already scheduled
        if (!wp_next_scheduled('error_explorer_cleanup_rate_limiter')) {
            wp_schedule_event(time(), 'hourly', 'error_explorer_cleanup_rate_limiter');
        }

        add_action('error_explorer_cleanup_rate_limiter', [$this, 'cleanup']);
    }

    /**
     * Check if an error can be sent based on rate limits and duplication
     */
    public function canSendError(array $errorData): array
    {
        $now = time() * 1000; // Convert to milliseconds for consistency

        // Clean up old requests first
        $this->removeExpiredRequests($now);

        // Check rate limit
        $requests = $this->getRequests();
        if (count($requests) >= $this->config['maxRequests']) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'resetTime' => $this->getNextResetTime($now),
                'reason' => 'Rate limit exceeded'
            ];
        }

        // Check for duplicate errors
        $errorHash = $this->generateErrorHash($errorData);
        $lastSeen = $this->getLastErrorTime($errorHash);

        if ($lastSeen !== null && ($now - $lastSeen) < $this->config['duplicateErrorWindow']) {
            return [
                'allowed' => false,
                'remaining' => $this->config['maxRequests'] - count($requests),
                'resetTime' => $this->getNextResetTime($now),
                'reason' => 'Duplicate error'
            ];
        }

        return [
            'allowed' => true,
            'remaining' => $this->config['maxRequests'] - count($requests) - 1,
            'resetTime' => $this->getNextResetTime($now)
        ];
    }

    /**
     * Mark that an error was sent
     */
    public function markErrorSent(array $errorData): void
    {
        $now = time() * 1000;

        // Add to requests list
        $requests = $this->getRequests();
        $requests[] = $now;
        $this->saveRequests($requests);

        // Update error hash tracking
        $errorHash = $this->generateErrorHash($errorData);
        $this->setLastErrorTime($errorHash, $now);
    }

    /**
     * Generate a hash for error deduplication using enhanced fingerprinting
     */
    private function generateErrorHash(array $errorData): string
    {
        // Enhanced fingerprint combining stack trace signature + message
        $stackSignature = $this->extractStackSignature($errorData['stack_trace'] ?? '', 3);
        $messageSignature = substr($errorData['message'] ?? '', 0, 100);
        $errorType = $errorData['exception_class'] ?? 'Unknown';
        
        // Combine signatures
        $key = sprintf(
            '%s|%s|%s',
            $stackSignature,
            $messageSignature,
            $errorType
        );
        
        return hash('sha256', $key);
    }

    /**
     * Extract stack trace signature by taking the first N meaningful frames
     * and normalizing line numbers to avoid over-segmentation
     */
    private function extractStackSignature(string $stackTrace, int $depth = 3): string
    {
        if (empty($stackTrace)) {
            return '';
        }
        
        $lines = explode("\n", $stackTrace);
        
        // Filter meaningful frames (skip WordPress core and system files)
        $meaningfulFrames = array_filter($lines, function($line) {
            $trimmed = trim($line);
            return !empty($trimmed) && 
                   strpos($line, '#') === 0 && 
                   !str_contains($line, 'wp-includes/') &&
                   !str_contains($line, 'wp-admin/') &&
                   !str_contains($line, '/plugins/') &&
                   (str_contains($line, '.php') || str_contains($line, 'eval()'));
        });
        
        // Take first N frames
        $frames = array_slice($meaningfulFrames, 0, $depth);
        
        // Normalize each frame (remove specific line numbers)
        $normalizedFrames = array_map(function($frame) {
            // Replace line numbers with XX to avoid over-segmentation
            return preg_replace('/:\d+/', ':XX', $frame);
        }, $frames);
        
        return implode('|', $normalizedFrames);
    }

    /**
     * Remove expired requests from tracking
     */
    private function removeExpiredRequests(int $now): void
    {
        $cutoff = $now - $this->config['windowMs'];
        $requests = $this->getRequests();

        $requests = array_values(array_filter($requests, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        }));

        $this->saveRequests($requests);
    }

    /**
     * Get the next reset time for rate limiting
     */
    private function getNextResetTime(int $now): int
    {
        $requests = $this->getRequests();
        
        if (empty($requests)) {
            return $now + $this->config['windowMs'];
        }

        $oldestRequest = min($requests);
        return $oldestRequest + $this->config['windowMs'];
    }

    /**
     * Cleanup old data (called by cron)
     */
    public function cleanup(): void
    {
        $now = time() * 1000;
        
        // Clean up old requests
        $this->removeExpiredRequests($now);
        
        // Clean up old error hashes
        $errorHashes = $this->getErrorHashes();
        $cutoff = $now - $this->config['duplicateErrorWindow'];
        
        $cleanedHashes = array_filter($errorHashes, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });
        
        $this->saveErrorHashes($cleanedHashes);
    }

    /**
     * Get rate limiter statistics
     */
    public function getStats(): array
    {
        $requests = $this->getRequests();
        $errorHashes = $this->getErrorHashes();

        return [
            'requestCount' => count($requests),
            'errorHashCount' => count($errorHashes),
            'remaining' => max(0, $this->config['maxRequests'] - count($requests)),
            'windowMs' => $this->config['windowMs'],
            'duplicateWindow' => $this->config['duplicateErrorWindow']
        ];
    }

    /**
     * Get tracked requests from storage
     */
    private function getRequests(): array
    {
        $requests = get_transient($this->requestsKey);
        return $requests === false ? [] : $requests;
    }

    /**
     * Save tracked requests to storage
     */
    private function saveRequests(array $requests): void
    {
        // Store for twice the window duration to handle edge cases
        $expiration = ($this->config['windowMs'] * 2) / 1000;
        set_transient($this->requestsKey, $requests, (int) $expiration);
    }

    /**
     * Get error hashes from storage
     */
    private function getErrorHashes(): array
    {
        $hashes = get_transient($this->errorsKey);
        return $hashes === false ? [] : $hashes;
    }

    /**
     * Save error hashes to storage
     */
    private function saveErrorHashes(array $hashes): void
    {
        // Store for twice the duplicate window duration
        $expiration = ($this->config['duplicateErrorWindow'] * 2) / 1000;
        set_transient($this->errorsKey, $hashes, (int) $expiration);
    }

    /**
     * Get the last time an error hash was seen
     */
    private function getLastErrorTime(string $hash): ?int
    {
        $hashes = $this->getErrorHashes();
        return $hashes[$hash] ?? null;
    }

    /**
     * Set the last seen time for an error hash
     */
    private function setLastErrorTime(string $hash, int $timestamp): void
    {
        $hashes = $this->getErrorHashes();
        $hashes[$hash] = $timestamp;
        $this->saveErrorHashes($hashes);
    }

    /**
     * Update rate limiter configuration
     */
    public function updateConfig(array $updates): void
    {
        $this->config = array_merge($this->config, $updates);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Reset all rate limiting data
     */
    public function reset(): void
    {
        delete_transient($this->requestsKey);
        delete_transient($this->errorsKey);
    }

    /**
     * Destroy the rate limiter and clean up
     */
    public function destroy(): void
    {
        wp_clear_scheduled_hook('error_explorer_cleanup_rate_limiter');
        $this->reset();
    }
}