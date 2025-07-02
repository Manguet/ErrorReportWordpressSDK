<?php

namespace ErrorExplorer\WordPressErrorReporter\Services;

class BreadcrumbManager
{
    private $breadcrumbs = [];
    private $maxBreadcrumbs = 50;

    public function __construct(int $maxBreadcrumbs = 50)
    {
        $this->maxBreadcrumbs = $maxBreadcrumbs;
    }

    public function addBreadcrumb(string $message, string $category = 'custom', string $level = 'info', array $data = [])
    {
        $breadcrumb = [
            'timestamp' => microtime(true),
            'message' => $message,
            'category' => $category,
            'level' => $level,
            'data' => $data
        ];

        $this->breadcrumbs[] = $breadcrumb;

        if (count($this->breadcrumbs) > $this->maxBreadcrumbs) {
            $this->breadcrumbs = array_slice($this->breadcrumbs, -$this->maxBreadcrumbs);
        }
    }

    public function logNavigation(string $from, string $to, array $data = [])
    {
        $this->addBreadcrumb("Navigation: {$from} → {$to}", 'navigation', 'info', array_merge($data, [
            'from' => $from,
            'to' => $to
        ]));
    }

    public function logUserAction(string $action, array $data = [])
    {
        $this->addBreadcrumb("User action: {$action}", 'user', 'info', array_merge($data, [
            'action' => $action
        ]));
    }

    public function logHttpRequest(string $method, string $url, ?int $statusCode = null, array $data = [])
    {
        $message = "HTTP: {$method} {$url}";
        if ($statusCode) {
            $message .= " ({$statusCode})";
        }

        $this->addBreadcrumb($message, 'http', 'info', array_merge($data, [
            'method' => $method,
            'url' => $url,
            'status_code' => $statusCode
        ]));
    }

    public function logQuery(string $query, ?float $duration = null, array $data = [])
    {
        $message = "Query: " . substr($query, 0, 100);
        if (strlen($query) > 100) {
            $message .= '...';
        }
        
        if ($duration) {
            $message .= " ({$duration}ms)";
        }

        $this->addBreadcrumb($message, 'query', 'info', array_merge($data, [
            'query' => $query,
            'duration' => $duration
        ]));
    }

    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    public function clearBreadcrumbs()
    {
        $this->breadcrumbs = [];
    }

    public function setMaxBreadcrumbs(int $max)
    {
        $this->maxBreadcrumbs = $max;
        
        if (count($this->breadcrumbs) > $this->maxBreadcrumbs) {
            $this->breadcrumbs = array_slice($this->breadcrumbs, -$this->maxBreadcrumbs);
        }
    }
}