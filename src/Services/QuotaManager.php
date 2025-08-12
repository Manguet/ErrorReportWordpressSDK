<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

/**
 * Manages error reporting quotas to prevent abuse and control costs
 */
class QuotaManager
{
    private array $config;
    private string $optionKey = 'error_explorer_quota_data';
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'dailyLimit' => 1000,
            'monthlyLimit' => 10000,
            'payloadSizeLimit' => 512000, // 500KB
            'burstLimit' => 10,
            'burstWindowMs' => 60000 // 1 minute
        ], $config);
        
        // Schedule daily cleanup
        if (!wp_next_scheduled('error_explorer_reset_daily_quota')) {
            wp_schedule_event(strtotime('tomorrow'), 'daily', 'error_explorer_reset_daily_quota');
        }
        
        add_action('error_explorer_reset_daily_quota', [$this, 'resetDailyQuota']);
    }

    /**
     * Check if an error can be sent based on quotas
     */
    public function canSendError(int $payloadSize = 0): array
    {
        $this->cleanupOldData();
        $stats = $this->getStats();
        
        // Check payload size limit
        if ($payloadSize > $this->config['payloadSizeLimit']) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Payload size (%d bytes) exceeds limit (%d bytes)',
                    $payloadSize,
                    $this->config['payloadSizeLimit']
                ),
                'quotaStats' => $stats
            ];
        }
        
        // Check burst limit
        $burstCount = $this->getBurstCount();
        if ($burstCount >= $this->config['burstLimit']) {
            return [
                'allowed' => false,
                'reason' => 'Burst limit exceeded',
                'quotaStats' => $stats
            ];
        }
        
        // Check daily limit
        $data = $this->getData();
        if ($data['dailyCount'] >= $this->config['dailyLimit']) {
            return [
                'allowed' => false,
                'reason' => 'Daily quota exceeded',
                'quotaStats' => $stats
            ];
        }
        
        // Check monthly limit
        if ($data['monthlyCount'] >= $this->config['monthlyLimit']) {
            return [
                'allowed' => false,
                'reason' => 'Monthly quota exceeded',
                'quotaStats' => $stats
            ];
        }
        
        return [
            'allowed' => true,
            'quotaStats' => $stats
        ];
    }

    /**
     * Record usage of quota
     */
    public function recordUsage(int $payloadSize = 0): void
    {
        $this->cleanupOldData();
        
        $data = $this->getData();
        $now = time();
        
        // Increment counters
        $data['dailyCount']++;
        $data['monthlyCount']++;
        $data['totalBytes'] += $payloadSize;
        
        // Add to burst tracking
        $data['burstTimestamps'][] = $now;
        
        // Keep only recent burst timestamps
        $cutoff = $now - ($this->config['burstWindowMs'] / 1000);
        $data['burstTimestamps'] = array_values(array_filter(
            $data['burstTimestamps'],
            function($timestamp) use ($cutoff) {
                return $timestamp > $cutoff;
            }
        ));
        
        $this->saveData($data);
    }

    /**
     * Get current quota statistics
     */
    public function getStats(): array
    {
        $this->cleanupOldData();
        
        $data = $this->getData();
        $burstCount = $this->getBurstCount();
        
        // Calculate next reset time (midnight local time)
        $tomorrow = strtotime('tomorrow');
        
        return [
            'dailyUsage' => $data['dailyCount'],
            'monthlyUsage' => $data['monthlyCount'],
            'dailyRemaining' => max(0, $this->config['dailyLimit'] - $data['dailyCount']),
            'monthlyRemaining' => max(0, $this->config['monthlyLimit'] - $data['monthlyCount']),
            'burstUsage' => $burstCount,
            'burstRemaining' => max(0, $this->config['burstLimit'] - $burstCount),
            'totalBytes' => $data['totalBytes'],
            'isOverQuota' => $data['dailyCount'] >= $this->config['dailyLimit'] || 
                            $data['monthlyCount'] >= $this->config['monthlyLimit'] ||
                            $burstCount >= $this->config['burstLimit'],
            'nextResetTime' => $tomorrow
        ];
    }

    /**
     * Reset all quotas
     */
    public function resetQuotas(): void
    {
        $this->saveData([
            'dailyCount' => 0,
            'monthlyCount' => 0,
            'totalBytes' => 0,
            'burstTimestamps' => [],
            'lastResetDate' => $this->getDateKey(),
            'lastResetMonth' => $this->getMonthKey()
        ]);
    }

    /**
     * Reset daily quota (called by cron)
     */
    public function resetDailyQuota(): void
    {
        $data = $this->getData();
        $data['dailyCount'] = 0;
        $data['lastResetDate'] = $this->getDateKey();
        $this->saveData($data);
    }

    /**
     * Clean up old data and reset counters if needed
     */
    private function cleanupOldData(): void
    {
        $data = $this->getData();
        $currentDate = $this->getDateKey();
        $currentMonth = $this->getMonthKey();
        
        // Reset daily count if date changed
        if ($currentDate !== $data['lastResetDate']) {
            $data['dailyCount'] = 0;
            $data['lastResetDate'] = $currentDate;
        }
        
        // Reset monthly count if month changed
        if ($currentMonth !== $data['lastResetMonth']) {
            $data['monthlyCount'] = 0;
            $data['totalBytes'] = 0;
            $data['lastResetMonth'] = $currentMonth;
        }
        
        // Clean up old burst timestamps
        $now = time();
        $cutoff = $now - ($this->config['burstWindowMs'] / 1000);
        $data['burstTimestamps'] = array_values(array_filter(
            $data['burstTimestamps'],
            function($timestamp) use ($cutoff) {
                return $timestamp > $cutoff;
            }
        ));
        
        $this->saveData($data);
    }

    /**
     * Get current burst count
     */
    private function getBurstCount(): int
    {
        $data = $this->getData();
        $now = time();
        $cutoff = $now - ($this->config['burstWindowMs'] / 1000);
        
        return count(array_filter(
            $data['burstTimestamps'],
            function($timestamp) use ($cutoff) {
                return $timestamp > $cutoff;
            }
        ));
    }

    /**
     * Get stored quota data
     */
    private function getData(): array
    {
        $data = get_option($this->optionKey, []);
        
        return array_merge([
            'dailyCount' => 0,
            'monthlyCount' => 0,
            'totalBytes' => 0,
            'burstTimestamps' => [],
            'lastResetDate' => $this->getDateKey(),
            'lastResetMonth' => $this->getMonthKey()
        ], $data);
    }

    /**
     * Save quota data
     */
    private function saveData(array $data): void
    {
        update_option($this->optionKey, $data, false);
    }

    /**
     * Get current date key
     */
    private function getDateKey(): string
    {
        return date('Y-m-d');
    }

    /**
     * Get current month key
     */
    private function getMonthKey(): string
    {
        return date('Y-m');
    }

    /**
     * Update configuration
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
     * Destroy the manager and clean up
     */
    public function destroy(): void
    {
        wp_clear_scheduled_hook('error_explorer_reset_daily_quota');
        delete_option($this->optionKey);
    }
}