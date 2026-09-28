<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Dto\TableChunk;
use WordpressMigrator\Dto\TableStructure;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Services\Backup\BackupService;
use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Tests\Support\FakeDestination;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class BackupServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private MigrationStorageService $storage;
    private FakeDestination $destination;

    protected function setUp(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        $this->storage = new MigrationStorageService();
        $this->destination = FakeDestination::get();
    }

    public function testMissingMigrationHasNotFoundStatus(): void
    {
        $status = (new BackupService(null, $this->storage))->getBackupStatus('absent');

        $this->assertSame('not_found', $status['status']);
        $this->assertNull($status['database']);
        $this->assertNull($status['files']);
        $this->assertFalse($status['backup_running']);
    }

    public function testStatusSummarizesDatabaseAndSkippedFiles(): void
    {
        $this->storage->saveMigrationData('mig_1', [
            'destination_url' => 'https://destination.test',
            'backup_running' => false,
            'tables' => [
                $this->item('old_posts', 'in_progress', ['sent', 'pending']),
                $this->item('old_options', 'pending', ['pending']),
            ],
            'files' => [
                $this->item('gone.txt', 'skipped', ['skipped']),
                $this->item('present.txt', 'sent', ['sent']),
            ],
        ]);

        $status = (new BackupService(null, $this->storage))->getBackupStatus('mig_1');

        $this->assertSame('in_progress', $status['status']);
        $this->assertTrue($status['is_interrupted']);
        $this->assertSame('https://destination.test', $status['destination_url']);
        $this->assertSame(3, $status['database']['total_chunks']);
        $this->assertSame(1, $status['database']['sent_chunks']);
        $this->assertSame('old_posts', $status['database']['current_table']);
        $this->assertSame('old_options', $status['database']['next_table']);
        $this->assertSame(['gone.txt'], $status['files']['skipped_files']);
        $this->assertSame('completed', $status['files']['status']);
    }

    public function testRunningFlagPersistsAndSuppressesInterruptedMarker(): void
    {
        $this->storage->saveMigrationData('mig_1', [
            'tables' => [$this->item('old_posts', 'in_progress', ['sent', 'pending'])],
        ]);
        $service = new BackupService(null, $this->storage);

        $service->setBackupRunning('mig_1', true);
        $this->assertTrue($service->getBackupStatus('mig_1')['backup_running']);
        $this->assertFalse($service->getBackupStatus('mig_1')['is_interrupted']);
        $this->assertNotEmpty($this->storage->loadMigrationData('mig_1')['backup_last_update']);

        $service->setBackupRunning('mig_1', false);
        $this->assertTrue($service->getBackupStatus('mig_1')['is_interrupted']);
    }

    public function testSetBackupRunningRejectsMissingMigration(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Migration file not found');

        (new BackupService(null, $this->storage))->setBackupRunning('absent', true);
    }

    public function testDatabaseBatchRunsThroughStructureAndDataAndPersistsProgress(): void
    {
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'tables' => [[
                'name' => 'wp_posts', 'size' => 32, 'status' => 'pending',
                'structureSent' => false, 'totalChunks' => 1,
                'chunks' => [['offset' => 0, 'limit' => 100, 'status' => 'pending']],
            ]],
        ]);
        $scanner = $this->createMock(DatabaseScanner::class);
        $scanner->method('getTableStructure')->willReturn(new TableStructure('wp_posts', 'CREATE TABLE `wp_posts` (`id` int)', []));
        $scanner->method('getTableChunk')->willReturn(new TableChunk(['id'], [[1]], 1));
        $scanner->method('getChecksum')->willReturn(hash('sha256', json_encode([[1]])));
        $service = new BackupService($scanner, $this->storage);

        $batch = $service->getDatabaseNextChunksBatch('mig_1', 2);

        $this->assertSame(2, $batch['processed_chunks']);
        $this->assertFalse($batch['completed']);
        $this->assertSame(100.0, $batch['progress']['percentage']);
        $this->assertCount(2, $this->destination->requests());
        $this->assertSame('sent', $this->storage->loadMigrationData('mig_1')['tables'][0]['chunks'][0]['status']);
        $this->assertSame(0, $service->getDatabaseNextChunksBatch('mig_1', 2)['processed_chunks']);
        $this->assertTrue($service->getDatabaseNextChunksBatch('mig_1', 2)['completed']);
    }

    private function item(string $name, string $status, array $chunkStatuses): array
    {
        return [
            'name' => $name,
            'path' => $name,
            'status' => $status,
            'totalChunks' => count($chunkStatuses),
            'chunks' => array_map(static fn (string $chunkStatus): array => ['status' => $chunkStatus], $chunkStatuses),
        ];
    }
}
