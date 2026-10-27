<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Helpers\MigrationLock;
use WordpressMigrator\Services\Backup\BackupService;
use WordpressMigrator\Services\Migration\MigrationService;
use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Services\RemoteSync\RemoteSyncExecutor;
use WordpressMigrator\Services\RemoteSync\RemoteSyncOrchestrationService;
use WordpressMigrator\Tests\Support\FakeDestination;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class RemoteSyncInfoChunkTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private const ID = 'mig_20260101_000000_0123456789abcdef0123456789abcdef';

    private MigrationStorageService $storage;

    private FakeDestination $destination;

    /** @var BackupService&MockObject */

    private MockObject $backupService;

    protected function setUp(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        Config::set('allowed_destinations', ['127.0.0.1']);
        $this->storage = new MigrationStorageService();
        $this->destination = FakeDestination::get();
        $this->backupService = $this->createMock(BackupService::class);
    }

    private function service(): RemoteSyncOrchestrationService
    {
        return new RemoteSyncOrchestrationService(
            $this->createMock(MigrationService::class),
            $this->backupService,
            $this->createMock(RemoteSyncExecutor::class),
            new MigrationLock($this->createTemporaryDirectory() . 'lock.json'),
            null,
            $this->storage
        );
    }

    private function saveMigration(array $overrides = []): void
    {
        $this->storage->saveMigrationData(self::ID, $overrides + [
            'type' => 'info',
            'migrationId' => self::ID,
            'destinationUrl' => $this->destination->url(),
            'siteUrl' => 'https://old.example.test',
            'sourceTablePrefix' => 'wp_',
            'token' => 'dest-token',
            'sourceToken' => 'source-token',
            'summary' => ['totalFiles' => 1, 'estimatedChunks' => 3],
            'files' => [['path' => 'a.txt', 'size' => 1, 'totalChunks' => 1, 'status' => 'pending', 'chunks' => []]],
            'tables' => [],
        ]);
    }

    private function backupStatus(array $database, ?array $files): void
    {
        $this->backupService->method('getBackupStatus')->willReturn(['database' => $database, 'files' => $files]);
        $this->backupService->method('getDatabaseNextChunksBatch')->willReturn(['processed_chunks' => 0, 'completed' => false]);
        $this->backupService->method('getFileNextChunksBatch')->willReturn(['processed_chunks' => 0, 'completed' => false]);
    }

    public function testFirstBatchSendsTheInfoChunkOnceWithoutSecrets(): void
    {
        $this->saveMigration();
        $this->backupStatus(['status' => 'in_progress'], null);
        $service = $this->service();

        $service->processBatch(self::ID);
        $service->processBatch(self::ID);

        $requests = $this->destination->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('dest-token', $requests[0]['headers']['x-migrator-token']);
        $info = JsonCompressor::decompressFromJson($requests[0]['body']);
        $this->assertSame('info', $info['type']);
        $this->assertSame(self::ID, $info['migration_id']);
        $this->assertSame(3, $info['summary']['estimatedChunks']);
        $this->assertSame('a.txt', $info['files'][0]['path']);
        $this->assertSame(1, $info['files'][0]['total_chunks']);
        foreach (['token', 't', 'source_token', 'sourceToken', 'stk'] as $key) {
            $this->assertArrayNotHasKey($key, $info);
        }
        $this->assertStringNotContainsString('source-token', $requests[0]['body']);
        $this->assertTrue($this->storage->loadMigrationData(self::ID)['info_sent']);
    }

    public function testFilesOnlyMigrationSendsTheInfoChunkBeforeTheFilesPhase(): void
    {
        $this->saveMigration(['backup_database' => false]);
        $this->backupStatus(['status' => 'disabled'], ['status' => 'pending']);

        $this->service()->processBatch(self::ID);

        $this->assertCount(1, $this->destination->requests());
    }

    public function testInfoChunkIsSentAgainWhenTheDestinationDidNotConfirmIt(): void
    {
        $this->saveMigration();
        $this->backupStatus(['status' => 'in_progress'], null);
        $service = $this->service();
        $this->destination->respondWith('restore.php', 500, '{}');

        try {
            $service->processBatch(self::ID);
            $this->fail('A failed info chunk must stop the batch');
        } catch (Exception $e) {
            $this->assertArrayNotHasKey('info_sent', $this->storage->loadMigrationData(self::ID));
        }

        $this->destination->reset();
        $service->processBatch(self::ID);

        $this->assertCount(1, $this->destination->requests());
        $this->assertTrue($this->storage->loadMigrationData(self::ID)['info_sent']);
    }
}
