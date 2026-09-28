<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Api\Controllers\RemoteSyncOrchestrationController;
use WordpressMigrator\Services\RemoteSync\RemoteSyncOrchestrationService;

#[RunTestsInSeparateProcesses]
final class RemoteSyncOrchestrationControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/WordPressStubs.php';
    }

    public function testStartRequiresDestinationUrl(): void
    {
        $service = $this->createMock(RemoteSyncOrchestrationService::class);
        $service->expects($this->never())->method('startMigration');

        $error = (new RemoteSyncOrchestrationController($service))->start(new \WP_REST_Request([], [], []));

        $this->assertSame('missing_destination', $error->get_error_code());
        $this->assertSame(400, $error->get_error_data()['status']);
    }

    public function testStartPassesDefaultsAndExplicitOptions(): void
    {
        $service = $this->createMock(RemoteSyncOrchestrationService::class);
        $service->expects($this->once())->method('startMigration')->with([
            'destination_url' => 'https://new.example.test',
            'backup_database' => false,
            'backup_files' => true,
            'exclusions' => ['cache'],
            'ip' => null,
            'force' => false,
            'base64' => true,
        ])->willReturn(['migration_id' => 'mig_1']);
        $request = new \WP_REST_Request([], [], [
            'destination_url' => 'https://new.example.test',
            'backup_database' => false,
            'exclusions' => ['cache'],
            'base64' => true,
        ]);

        $response = (new RemoteSyncOrchestrationController($service))->start($request);

        $this->assertSame(['success' => true, 'migration_id' => 'mig_1'], $response->get_data());
    }

    public function testGetStatusHandlesMissingUnknownAndKnownMigration(): void
    {
        $service = $this->createMock(RemoteSyncOrchestrationService::class);
        $service->expects($this->exactly(2))->method('getMigrationStatus')->willReturnCallback(
            static fn (string $id): ?array => $id === 'known' ? ['stage' => 'backup'] : null
        );
        $controller = new RemoteSyncOrchestrationController($service);

        $this->assertSame('missing_migration_id', $controller->getStatus(new \WP_REST_Request())->get_error_code());
        $this->assertSame('migration_not_found', $controller->getStatus(new \WP_REST_Request(['migration_id' => 'unknown']))->get_error_code());
        $this->assertSame(['success' => true, 'status' => ['stage' => 'backup']], $controller->getStatus(new \WP_REST_Request(['migration_id' => 'known']))->get_data());
    }

    public function testProcessBatchRequiresIdAndPassesBatchSize(): void
    {
        $service = $this->createMock(RemoteSyncOrchestrationService::class);
        $service->expects($this->once())->method('processBatch')->with('mig_1', 7)->willReturn(['processed' => 7]);
        $controller = new RemoteSyncOrchestrationController($service);

        $this->assertSame('missing_migration_id', $controller->processBatch(new \WP_REST_Request())->get_error_code());
        $response = $controller->processBatch(new \WP_REST_Request([], [], ['migration_id' => 'mig_1', 'batch_size' => 7]));
        $this->assertSame(['success' => true, 'processed' => 7], $response->get_data());
    }

    public function testServiceExceptionBecomesServerError(): void
    {
        $service = $this->createMock(RemoteSyncOrchestrationService::class);
        $service->method('startMigration')->willThrowException(new \RuntimeException('cannot start'));

        $error = (new RemoteSyncOrchestrationController($service))->start(new \WP_REST_Request([], [], ['destination_url' => 'https://new.example.test']));

        $this->assertSame('sync_failed', $error->get_error_code());
        $this->assertSame('cannot start', $error->get_error_message());
        $this->assertSame(500, $error->get_error_data()['status']);
    }
}
