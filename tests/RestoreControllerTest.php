<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Api\Controllers\RestoreController;
use WordpressMigrator\Services\Restore\RestoreService;

/**
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
final class RestoreControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/WordPressStubs.php';
    }

    public function testFileChunkReturnsWorkAndThenCompletion(): void
    {
        $service = $this->createMock(RestoreService::class);
        $service->expects($this->exactly(2))->method('restoreFileNextChunk')->with('mig_1')
            ->willReturnOnConsecutiveCalls(['path' => 'a.txt'], null);
        $controller = new RestoreController($service);
        $request = new \WP_REST_Request(['migration_id' => 'mig_1']);

        $this->assertSame(['success' => true, 'completed' => false, 'chunk' => ['path' => 'a.txt']], $controller->fileNextChunk($request)->get_data());
        $this->assertSame(['success' => true, 'completed' => true, 'message' => 'All file chunks restored'], $controller->fileNextChunk($request)->get_data());
    }

    public function testDatabaseChunkAndProgressReturnServiceValues(): void
    {
        $service = $this->createMock(RestoreService::class);
        $service->method('restoreDatabaseNextChunk')->with('mig_1')->willReturn(['table' => 'posts']);
        $service->method('getProgress')->with('mig_1')->willReturn(['percentage' => 50]);
        $controller = new RestoreController($service);
        $request = new \WP_REST_Request(['migration_id' => 'mig_1']);

        $this->assertSame(['success' => true, 'completed' => false, 'chunk' => ['table' => 'posts']], $controller->databaseNextChunk($request)->get_data());
        $this->assertSame(['success' => true, 'progress' => ['percentage' => 50]], $controller->progress($request)->get_data());
    }

    public function testServiceFailuresBecomeErrorsWithSpecificCodes(): void
    {
        $service = $this->createMock(RestoreService::class);
        $service->method('restoreDatabaseNextChunk')->willThrowException(new \Exception('restore failed'));
        $service->method('getProgress')->willThrowException(new \Exception('progress failed'));
        $controller = new RestoreController($service);
        $request = new \WP_REST_Request(['migration_id' => 'mig_1']);

        $restore = $controller->databaseNextChunk($request);
        $this->assertSame('restore_failed', $restore->get_error_code());
        $this->assertSame(500, $restore->get_error_data()['status']);
        $progress = $controller->progress($request);
        $this->assertSame('progress_failed', $progress->get_error_code());
    }
}
