<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

/**
 * Validates and sanitizes error data for security
 */
class SecurityValidator
{
    private array $config;
    private array $defaultSensitivePatterns;

    public function __construct(array $config = [])
    {
        $this->defaultSensitivePatterns = [
            // Credit card numbers
            '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
            // Social Security Numbers
            '/\b\d{3}-\d{2}-\d{4}\b/',
            // Email addresses (in some contexts might be sensitive)
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            // Phone numbers
            '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/',
            // IP addresses
            '/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/',
            // JWT tokens
            '/\beyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*\b/',
            // API keys (common patterns)
            '/\b[Aa]pi[_-]?[Kk]ey[:\s]*[A-Za-z0-9_-]{20,}\b/',
            // Passwords (in URLs or JSON)
            '/["\']?password["\']?\s*[:\s=]\s*["\'][^"\']*["\']?/i',
            // Access tokens
            '/\b[Aa]ccess[_-]?[Tt]oken[:\s]*[A-Za-z0-9_-]{20,}\b/'
        ];

        $this->config = array_merge([
            'requireHttps' => true,
            'validateToken' => true,
            'maxPayloadSize' => 1024 * 1024, // 1MB
            'allowedDomains' => [],
            'sensitiveDataPatterns' => $this->defaultSensitivePatterns
        ], $config);
    }

    /**
     * Validate a webhook URL
     */
    public function validateWebhookUrl(string $url): bool
    {
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $parsedUrl = parse_url($url);
        
        // Must have a host
        if (!isset($parsedUrl['host'])) {
            return false;
        }
        
        // Require HTTPS if configured
        if ($this->config['requireHttps'] && $parsedUrl['scheme'] !== 'https') {
            return false;
        }
        
        // Check allowed domains if configured
        if (!empty($this->config['allowedDomains'])) {
            $allowed = false;
            foreach ($this->config['allowedDomains'] as $domain) {
                if (strpos($parsedUrl['host'], $domain) !== false) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Validate configuration settings
     */
    public function validateConfiguration(array $config): array
    {
        $errors = [];
        $warnings = [];

        // Validate webhook URL
        if (empty($config['webhookUrl'])) {
            $errors[] = 'Webhook URL is required';
        } else {
            $parsedUrl = wp_parse_url($config['webhookUrl']);
            
            if (!$parsedUrl || empty($parsedUrl['host'])) {
                $errors[] = 'Invalid webhook URL format';
            } else {
                if ($this->config['requireHttps'] && ($parsedUrl['scheme'] !== 'https')) {
                    $errors[] = 'HTTPS is required for webhook URL in production';
                }

                if (!empty($this->config['allowedDomains']) && 
                    !in_array($parsedUrl['host'], $this->config['allowedDomains'])) {
                    $errors[] = sprintf('Domain %s is not in allowed domains list', $parsedUrl['host']);
                }
            }
        }

        // Validate project name
        if (empty($config['projectName']) || empty(trim($config['projectName']))) {
            $errors[] = 'Project name is required';
        }

        // Validate environment
        if (!empty($config['environment']) && 
            !in_array($config['environment'], ['development', 'staging', 'production'])) {
            $warnings[] = 'Environment should be one of: development, staging, production';
        }

        // Validate retry configuration
        if (isset($config['retries']) && ($config['retries'] < 0 || $config['retries'] > 10)) {
            $warnings[] = 'Retry count should be between 0 and 10';
        }

        if (isset($config['timeout']) && ($config['timeout'] < 1000 || $config['timeout'] > 30000)) {
            $warnings[] = 'Timeout should be between 1000ms and 30000ms';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Validate error payload
     */
    public function validatePayload(array $errorData): array
    {
        $errors = [];
        $warnings = [];

        // Check payload size
        $payloadSize = $this->calculatePayloadSize($errorData);
        if ($payloadSize > $this->config['maxPayloadSize']) {
            $errors[] = sprintf(
                'Payload size (%d bytes) exceeds maximum allowed size (%d bytes)',
                $payloadSize,
                $this->config['maxPayloadSize']
            );
        }

        // Check for sensitive data
        $sensitiveDataFound = $this->detectSensitiveData($errorData);
        if (!empty($sensitiveDataFound)) {
            $warnings[] = sprintf('Potential sensitive data detected: %s', implode(', ', $sensitiveDataFound));
        }

        // Validate required fields
        if (empty($errorData['message'])) {
            $errors[] = 'Error message is required';
        }

        if (empty($errorData['project'])) {
            $errors[] = 'Project name is required';
        }

        if (empty($errorData['timestamp'])) {
            $errors[] = 'Timestamp is required';
        }

        // Validate data types
        if (isset($errorData['line']) && !is_numeric($errorData['line'])) {
            $errors[] = 'Line number must be numeric';
        }

        if (isset($errorData['breadcrumbs']) && !is_array($errorData['breadcrumbs'])) {
            $errors[] = 'Breadcrumbs must be an array';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Sanitize error data by removing/masking sensitive information
     */
    public function sanitizeErrorData(array $errorData): array
    {
        $sanitized = $errorData;

        // Sanitize message
        if (!empty($sanitized['message'])) {
            $sanitized['message'] = $this->sanitizeText($sanitized['message']);
        }

        // Sanitize stack trace
        if (!empty($sanitized['stack_trace'])) {
            $sanitized['stack_trace'] = $this->sanitizeText($sanitized['stack_trace']);
        }

        // Sanitize context data
        if (!empty($sanitized['context'])) {
            $sanitized['context'] = $this->sanitizeObject($sanitized['context']);
        }

        // Sanitize breadcrumbs
        if (!empty($sanitized['breadcrumbs']) && is_array($sanitized['breadcrumbs'])) {
            $sanitized['breadcrumbs'] = array_map(function($breadcrumb) {
                if (!empty($breadcrumb['message'])) {
                    $breadcrumb['message'] = $this->sanitizeText($breadcrumb['message']);
                }
                if (!empty($breadcrumb['data'])) {
                    $breadcrumb['data'] = $this->sanitizeObject($breadcrumb['data']);
                }
                return $breadcrumb;
            }, $sanitized['breadcrumbs']);
        }

        // Sanitize user data
        if (!empty($sanitized['user'])) {
            $sanitized['user'] = $this->sanitizeObject($sanitized['user']);
        }

        // Sanitize additional data fields
        if (!empty($sanitized['additional_data'])) {
            $sanitized['additional_data'] = $this->sanitizeObject($sanitized['additional_data']);
        }

        return $sanitized;
    }

    /**
     * Calculate payload size in bytes
     */
    private function calculatePayloadSize(array $data): int
    {
        return strlen(wp_json_encode($data));
    }

    /**
     * Detect sensitive data in error data
     */
    private function detectSensitiveData(array $errorData): array
    {
        $sensitiveDataTypes = [];
        $textToCheck = implode(' ', [
            $errorData['message'] ?? '',
            $errorData['stack_trace'] ?? '',
            wp_json_encode($errorData['context'] ?? []),
            wp_json_encode($errorData['user'] ?? []),
            wp_json_encode($errorData['breadcrumbs'] ?? []),
            wp_json_encode($errorData['additional_data'] ?? [])
        ]);

        foreach ($this->config['sensitiveDataPatterns'] as $pattern) {
            if (preg_match($pattern, $textToCheck)) {
                if (strpos($pattern, '\d{4}[-\s]?\d{4}') !== false) {
                    $sensitiveDataTypes[] = 'Credit Card';
                } elseif (strpos($pattern, '\d{3}-\d{2}-\d{4}') !== false) {
                    $sensitiveDataTypes[] = 'SSN';
                } elseif (strpos($pattern, '@') !== false) {
                    $sensitiveDataTypes[] = 'Email';
                } elseif (strpos($pattern, 'eyJ') !== false) {
                    $sensitiveDataTypes[] = 'JWT Token';
                } elseif (strpos($pattern, '[Aa]pi') !== false) {
                    $sensitiveDataTypes[] = 'API Key';
                } elseif (strpos($pattern, 'password') !== false) {
                    $sensitiveDataTypes[] = 'Password';
                } else {
                    $sensitiveDataTypes[] = 'PII';
                }
            }
        }

        return array_unique($sensitiveDataTypes);
    }

    /**
     * Validate error data (alias for validatePayload)
     */
    public function validateErrorData(array $errorData): bool
    {
        $result = $this->validatePayload($errorData);
        return $result['valid'];
    }
    
    /**
     * Sanitize sensitive data (alias for sanitizeErrorData)
     */
    public function sanitizeSensitiveData(array $errorData): array
    {
        return $this->sanitizeErrorData($errorData);
    }

    /**
     * Sanitize text by removing sensitive patterns
     */
    private function sanitizeText(string $text): string
    {
        $sanitized = $text;
        
        foreach ($this->config['sensitiveDataPatterns'] as $pattern) {
            $sanitized = preg_replace($pattern, '[REDACTED]', $sanitized);
        }
        
        return $sanitized;
    }

    /**
     * Sanitize object by removing sensitive keys and values
     */
    private function sanitizeObject($obj)
    {
        if (!is_array($obj) && !is_object($obj)) {
            if (is_string($obj)) {
                return $this->sanitizeText($obj);
            }
            return $obj;
        }

        if (is_object($obj)) {
            $obj = (array) $obj;
        }

        if (is_array($obj)) {
            $sanitized = [];
            
            foreach ($obj as $key => $value) {
                // Check if key might contain sensitive data
                $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'credential', 'api_key', 'access_token'];
                $isSensitiveKey = false;
                
                foreach ($sensitiveKeys as $sensitiveKey) {
                    if (stripos($key, $sensitiveKey) !== false) {
                        $isSensitiveKey = true;
                        break;
                    }
                }
                
                if ($isSensitiveKey) {
                    $sanitized[$key] = '[REDACTED]';
                } elseif (is_string($value)) {
                    $sanitized[$key] = $this->sanitizeText($value);
                } elseif (is_array($value) || is_object($value)) {
                    $sanitized[$key] = $this->sanitizeObject($value);
                } else {
                    $sanitized[$key] = $value;
                }
            }
            
            return $sanitized;
        }

        return $obj;
    }

    /**
     * Add a custom sensitive data pattern
     */
    public function addSensitivePattern(string $pattern): void
    {
        $this->config['sensitiveDataPatterns'][] = $pattern;
    }

    /**
     * Remove a sensitive data pattern
     */
    public function removeSensitivePattern(string $pattern): void
    {
        $key = array_search($pattern, $this->config['sensitiveDataPatterns']);
        if ($key !== false) {
            unset($this->config['sensitiveDataPatterns'][$key]);
            // Re-index array
            $this->config['sensitiveDataPatterns'] = array_values($this->config['sensitiveDataPatterns']);
        }
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
     * Validate WordPress nonce for security
     */
    public function validateNonce(string $nonce, string $action = 'error_explorer_action'): bool
    {
        return wp_verify_nonce($nonce, $action) !== false;
    }

    /**
     * Check if current user has required capabilities
     */
    public function validateUserCapabilities(string $capability = 'manage_options'): bool
    {
        return current_user_can($capability);
    }

    /**
     * Validate that request is coming from a valid referrer
     */
    public function validateReferrer(): bool
    {
        $referrer = wp_get_referer();
        if (!$referrer) {
            return false;
        }

        $siteUrl = get_site_url();
        return strpos($referrer, $siteUrl) === 0;
    }
}