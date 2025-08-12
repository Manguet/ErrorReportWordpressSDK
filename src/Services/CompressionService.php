<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

/**
 * Handles compression of error data to reduce bandwidth usage
 */
class CompressionService
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enabled' => true,
            'threshold' => 1024, // 1KB - minimum size to compress
            'level' => 6, // Compression level (1-9)
            'method' => 'gzip' // Currently only gzip supported
        ], $config);
    }

    /**
     * Compress data if conditions are met
     */
    public function compress(string $data): array
    {
        if (!$this->config['enabled'] || strlen($data) < $this->config['threshold']) {
            return [
                'data' => $data,
                'compressed' => false,
                'originalSize' => strlen($data),
                'compressedSize' => strlen($data),
                'compressionRatio' => 1.0
            ];
        }

        // Check if gzip functions are available
        if (!function_exists('gzencode')) {
            return [
                'data' => $data,
                'compressed' => false,
                'originalSize' => strlen($data),
                'compressedSize' => strlen($data),
                'compressionRatio' => 1.0,
                'error' => 'gzip functions not available'
            ];
        }

        try {
            $originalSize = strlen($data);
            
            // Compress using gzip
            $compressed = gzencode($data, $this->config['level']);
            
            if ($compressed === false) {
                throw new \Exception('Compression failed');
            }
            
            $compressedSize = strlen($compressed);
            $compressionRatio = $compressedSize / $originalSize;
            
            // Only use compression if it actually reduces size
            if ($compressedSize >= $originalSize) {
                return [
                    'data' => $data,
                    'compressed' => false,
                    'originalSize' => $originalSize,
                    'compressedSize' => $originalSize,
                    'compressionRatio' => 1.0,
                    'reason' => 'Compression did not reduce size'
                ];
            }

            return [
                'data' => base64_encode($compressed),
                'compressed' => true,
                'originalSize' => $originalSize,
                'compressedSize' => $compressedSize,
                'compressionRatio' => $compressionRatio,
                'encoding' => 'gzip+base64'
            ];

        } catch (\Exception $e) {
            return [
                'data' => $data,
                'compressed' => false,
                'originalSize' => strlen($data),
                'compressedSize' => strlen($data),
                'compressionRatio' => 1.0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Decompress data
     */
    public function decompress(string $data, string $encoding = 'gzip+base64'): array
    {
        try {
            if ($encoding === 'gzip+base64') {
                // First decode base64
                $decoded = base64_decode($data);
                if ($decoded === false) {
                    throw new \Exception('Base64 decode failed');
                }
                
                // Then decompress gzip
                $decompressed = gzdecode($decoded);
                if ($decompressed === false) {
                    throw new \Exception('Gzip decompression failed');
                }
                
                return [
                    'data' => $decompressed,
                    'success' => true,
                    'originalSize' => strlen($data),
                    'decompressedSize' => strlen($decompressed)
                ];
            }
            
            // If not compressed or unknown encoding, return as-is
            return [
                'data' => $data,
                'success' => true,
                'originalSize' => strlen($data),
                'decompressedSize' => strlen($data),
                'note' => 'Data was not compressed'
            ];
            
        } catch (\Exception $e) {
            return [
                'data' => $data,
                'success' => false,
                'error' => $e->getMessage(),
                'originalSize' => strlen($data),
                'decompressedSize' => strlen($data)
            ];
        }
    }

    /**
     * Get compression headers for HTTP requests
     */
    public function getCompressionHeaders(bool $isCompressed): array
    {
        if (!$isCompressed) {
            return [];
        }

        return [
            'Content-Encoding' => 'gzip',
            'Content-Type' => 'application/json'
        ];
    }

    /**
     * Estimate compression ratio for given data
     */
    public function estimateCompressionRatio(string $data): float
    {
        $length = strlen($data);
        
        if ($length === 0) {
            return 1.0;
        }
        
        // Count unique characters
        $uniqueChars = count(array_unique(str_split($data)));
        $totalChars = $length;
        
        // More repetitive text compresses better
        $repetitionFactor = 1 - ($uniqueChars / $totalChars);
        
        // JSON/text typically compresses to 20-40% of original size
        $baseRatio = 0.3;
        $adjustedRatio = $baseRatio * (1 - $repetitionFactor * 0.5);
        
        return min(1.0, max(0.1, $adjustedRatio));
    }

    /**
     * Check if compression is beneficial for given data
     */
    public function shouldCompress(string $data): array
    {
        $size = strlen($data);
        $enabled = $this->config['enabled'];
        $aboveThreshold = $size >= $this->config['threshold'];
        $gzipAvailable = function_exists('gzencode');
        $estimatedRatio = $this->estimateCompressionRatio($data);
        $worthCompressing = $estimatedRatio < 0.8; // Only if we expect 20%+ reduction
        
        return [
            'should' => $enabled && $aboveThreshold && $gzipAvailable && $worthCompressing,
            'reasons' => [
                'enabled' => $enabled,
                'aboveThreshold' => $aboveThreshold,
                'gzipAvailable' => $gzipAvailable,
                'worthCompressing' => $worthCompressing,
                'size' => $size,
                'threshold' => $this->config['threshold'],
                'estimatedRatio' => $estimatedRatio
            ]
        ];
    }

    /**
     * Compress JSON data specifically
     */
    public function compressJson(array $data): array
    {
        $jsonString = wp_json_encode($data);
        if ($jsonString === false) {
            return [
                'data' => $data,
                'compressed' => false,
                'error' => 'JSON encoding failed'
            ];
        }
        
        $result = $this->compress($jsonString);
        
        // If compressed, return the compressed string
        // If not compressed, return the original array
        if ($result['compressed']) {
            return $result;
        } else {
            $result['data'] = $data;
            return $result;
        }
    }

    /**
     * Batch compress multiple data items
     */
    public function compressBatch(array $items): array
    {
        $results = [];
        $totalOriginalSize = 0;
        $totalCompressedSize = 0;
        $compressedCount = 0;
        
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $item = wp_json_encode($item);
            }
            
            if (!is_string($item)) {
                $item = (string) $item;
            }
            
            $result = $this->compress($item);
            $results[$key] = $result;
            
            $totalOriginalSize += $result['originalSize'];
            $totalCompressedSize += $result['compressedSize'];
            
            if ($result['compressed']) {
                $compressedCount++;
            }
        }
        
        return [
            'results' => $results,
            'summary' => [
                'totalItems' => count($items),
                'compressedItems' => $compressedCount,
                'totalOriginalSize' => $totalOriginalSize,
                'totalCompressedSize' => $totalCompressedSize,
                'overallCompressionRatio' => $totalOriginalSize > 0 ? $totalCompressedSize / $totalOriginalSize : 1.0,
                'spacesSaved' => $totalOriginalSize - $totalCompressedSize
            ]
        ];
    }

    /**
     * Get compression statistics
     */
    public function getStats(string $data): array
    {
        $size = strlen($data);
        $shouldCompress = $this->shouldCompress($data);
        $estimated = $this->estimateCompressionRatio($data);
        
        return [
            'size' => $size,
            'sizeFormatted' => $this->formatBytes($size),
            'shouldCompress' => $shouldCompress['should'],
            'estimatedCompressionRatio' => $estimated,
            'estimatedCompressedSize' => (int) ($size * $estimated),
            'estimatedSavings' => (int) ($size * (1 - $estimated)),
            'config' => $this->config
        ];
    }

    /**
     * Format bytes for human readable display
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $updates): void
    {
        $this->config = array_merge($this->config, $updates);
        
        // Validate compression level
        if (isset($this->config['level'])) {
            $this->config['level'] = max(1, min(9, (int) $this->config['level']));
        }
        
        // Validate threshold
        if (isset($this->config['threshold'])) {
            $this->config['threshold'] = max(0, (int) $this->config['threshold']);
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
     * Test compression functionality
     */
    public function test(): array
    {
        $testData = str_repeat('This is a test string for compression. ', 50);
        
        $result = $this->compress($testData);
        
        if ($result['compressed']) {
            // Test decompression
            $decompressed = $this->decompress($result['data'], $result['encoding']);
            $roundTripSuccess = $decompressed['success'] && $decompressed['data'] === $testData;
        } else {
            $roundTripSuccess = true; // No compression, so data is unchanged
            $decompressed = ['success' => true];
        }
        
        return [
            'compression' => $result,
            'decompression' => $decompressed,
            'roundTripSuccess' => $roundTripSuccess,
            'gzipAvailable' => function_exists('gzencode'),
            'testDataSize' => strlen($testData)
        ];
    }
}