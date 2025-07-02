<?php

namespace ErrorExplorer\WordPressErrorReporter\Tests\Unit;

use ErrorExplorer\WordPressErrorReporter\ErrorReporter;
use PHPUnit\Framework\TestCase;

class ErrorReporterTest extends TestCase
{
    private $errorReporter;
    private $webhookUrl = 'https://example.com/webhook/error/test-token';

    protected function setUp(): void
    {
        $this->errorReporter = new ErrorReporter($this->webhookUrl, [
            'environment' => 'testing',
            'project_name' => 'Test Project',
        ]);
    }

    public function testCanCreateErrorReporter()
    {
        $this->assertInstanceOf(ErrorReporter::class, $this->errorReporter);
    }

    public function testCanReportErrorWithoutCrashing()
    {
        $exception = new \Exception('Test exception');
        
        // Should not throw any exceptions
        $this->expectNotToPerformAssertions();
        $this->errorReporter->reportError($exception);
    }

    public function testCanReportMessageWithoutCrashing()
    {
        // Should not throw any exceptions
        $this->expectNotToPerformAssertions();
        $this->errorReporter->reportMessage('Test message', 'testing');
    }

    public function testCanAddBreadcrumbs()
    {
        // Should not throw any exceptions
        $this->expectNotToPerformAssertions();
        $this->errorReporter->addBreadcrumb('Test breadcrumb');
        $this->errorReporter->logNavigation('/from', '/to');
        $this->errorReporter->logUserAction('test_action');
        $this->errorReporter->logHttpRequest('GET', '/test');
    }

    public function testCanClearBreadcrumbs()
    {
        // Should not throw any exceptions
        $this->expectNotToPerformAssertions();
        $this->errorReporter->addBreadcrumb('Test breadcrumb');
        $this->errorReporter->clearBreadcrumbs();
    }

    public function testCanRegisterErrorHandlers()
    {
        // Should not throw any exceptions
        $this->expectNotToPerformAssertions();
        $this->errorReporter->register();
    }
}