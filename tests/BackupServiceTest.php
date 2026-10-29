<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Dto\TableChunk;
use PanelAlpha\Migrator\Dto\TableStructure;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\MigrationLock;
use PanelAlpha\Migrator\Services\Backup\BackupService;
use PanelAlpha\Migrator\Services\Migration\MigrationStorageService;
use PanelAlpha\Migrator\Services\Scanner\DatabaseScanner;
use PanelAlpha\Migrator\Tests\Support\FakeDestination;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class BackupServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private MigrationStorageService $storage;
    private FakeDestination $destination;
    private MigrationLock $lock;

    protected function setUp(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        Config::set('allowed_destinations', ['127.0.0.1']);
        $this->storage = new MigrationStorageService();
        $this->destination = FakeDestination::get();
        $this->lock = new MigrationLock($this->createTemporaryDirectory() . 'lock.json');
    }

    public function testUnselectedDatabasePhaseIsDisabledAndNotCompletedEmpty(): void
    {
        $this->storage->saveMigrationData('mig_1', [
            'backup_database' => false,
            'backup_files' => true,
            'tables' => [],
            'files' => [$this->item('index.php', 'pending', ['pending'])],
        ]);

        $status = (new BackupService(null, $this->storage))->getBackupStatus('mig_1');

        $this->assertSame('disabled', $status['database']['status']);
        $this->assertSame(0, $status['database']['total_chunks']);
        $this->assertSame('pending', $status['files']['status']);
        $this->assertSame(1, $status['files']['total_chunks']);
    }

    public function testUnselectedFilesPhaseIsDisabledAndNotCompletedEmpty(): void
    {
        $this->storage->saveMigrationData('mig_1', [
            'backup_database' => true,
            'backup_files' => false,
            'tables' => [$this->item('wp_posts', 'pending', ['pending'])],
            'files' => [],
        ]);

        $status = (new BackupService(null, $this->storage))->getBackupStatus('mig_1');

        $this->assertSame('disabled', $status['files']['status']);
        $this->assertSame(0, $status['files']['total_chunks']);
        $this->assertSame([], $status['files']['skipped_files']);
        $this->assertSame('pending', $status['database']['status']);
        $this->assertSame(1, $status['database']['total_chunks']);
    }

    public function testDisabledPhaseDoesNotCountAsCompletedWhenNothingElseHasStarted(): void
    {
        $this->storage->saveMigrationData('mig_files', [
            'backup_database' => false,
            'backup_files' => true,
            'tables' => [],
            'files' => [],
        ]);
        $this->storage->saveMigrationData('mig_db', [
            'backup_database' => true,
            'backup_files' => false,
            'tables' => [],
            'files' => [],
        ]);

        $filesOnly = (new BackupService(null, $this->storage))->getBackupStatus('mig_files');
        $databaseOnly = (new BackupService(null, $this->storage))->getBackupStatus('mig_db');

        $this->assertSame('disabled', $filesOnly['database']['status']);
        $this->assertNull($filesOnly['files']);
        $this->assertSame('idle', $filesOnly['status']);
        $this->assertSame('disabled', $databaseOnly['files']['status']);
        $this->assertNull($databaseOnly['database']);
        $this->assertSame('idle', $databaseOnly['status']);
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
        $this->lock->acquire('mig_1');
        $service = new BackupService(null, $this->storage, $this->lock);

        $service->setBackupRunning('mig_1', true);
        $this->assertTrue($service->getBackupStatus('mig_1')['backup_running']);
        $this->assertFalse($service->getBackupStatus('mig_1')['is_interrupted']);
        $this->assertNotEmpty($this->storage->loadMigrationData('mig_1')['backup_last_update']);

        $service->setBackupRunning('mig_1', false);
        $this->assertTrue($service->getBackupStatus('mig_1')['is_interrupted']);
    }

    public function testStartBackupAcquiresTheLockAndMarksRunning(): void
    {
        $this->storage->saveMigrationData('mig_1', ['tables' => []]);

        (new BackupService(null, $this->storage, $this->lock))->startBackup('mig_1');

        $this->assertSame('mig_1', $this->lock->getLockInfo()['migration_id']);
        $this->assertTrue($this->storage->loadMigrationData('mig_1')['backup_running']);
    }

    public function testStartBackupKeepsALockAlreadyHeldByTheSameMigration(): void
    {
        $this->storage->saveMigrationData('mig_1', ['tables' => []]);
        $this->lock->acquire('mig_1');

        (new BackupService(null, $this->storage, $this->lock))->startBackup('mig_1');

        $this->assertTrue($this->storage->loadMigrationData('mig_1')['backup_running']);
    }

    public function testStartBackupFailsWhileAnotherMigrationHoldsTheLock(): void
    {
        $this->storage->saveMigrationData('mig_1', ['tables' => []]);
        $this->lock->acquire('other');

        try {
            (new BackupService(null, $this->storage, $this->lock))->startBackup('mig_1');
            $this->fail('Expected an exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('Failed to acquire migration lock', $e->getMessage());
        }

        $this->assertSame('other', $this->lock->getLockInfo()['migration_id']);
        $this->assertArrayNotHasKey('backup_running', $this->storage->loadMigrationData('mig_1'));
    }

    public function testStartBackupRejectsMissingMigrationWithoutTakingTheLock(): void
    {
        try {
            (new BackupService(null, $this->storage, $this->lock))->startBackup('absent');
            $this->fail('Expected an exception');
        } catch (Exception $e) {
            $this->assertSame('Migration file not found', $e->getMessage());
        }

        $this->assertFalse($this->lock->isLocked());
    }

    public function testStopBackupReleasesTheLockAndClearsRunning(): void
    {
        $this->storage->saveMigrationData('mig_1', ['tables' => []]);
        $service = new BackupService(null, $this->storage, $this->lock);
        $service->startBackup('mig_1');

        $service->stopBackup('mig_1');

        $this->assertFalse($this->lock->isLocked());
        $this->assertFalse($this->storage->loadMigrationData('mig_1')['backup_running']);
    }

    public function testStopBackupCanKeepTheLockForFinalize(): void
    {
        $this->storage->saveMigrationData('mig_1', ['tables' => []]);
        $service = new BackupService(null, $this->storage, $this->lock);
        $service->startBackup('mig_1');

        $service->stopBackup('mig_1', true);

        $this->assertSame('mig_1', $this->lock->getLockInfo()['migration_id']);
        $this->assertFalse($this->storage->loadMigrationData('mig_1')['backup_running']);
    }

    public function testStopAfterTheLockWasReleasedStillClearsRunning(): void
    {
        $this->storage->saveMigrationData('mig_1', ['tables' => [], 'backup_running' => true]);

        (new BackupService(null, $this->storage, $this->lock))->stopBackup('mig_1');

        $this->assertFalse($this->storage->loadMigrationData('mig_1')['backup_running']);
    }

    public function testStopDoesNotReleaseAnotherMigrationsLock(): void
    {
        $this->storage->saveMigrationData('mig_1', ['tables' => [], 'backup_running' => true]);
        $this->lock->acquire('other');

        (new BackupService(null, $this->storage, $this->lock))->stopBackup('mig_1');

        $this->assertSame('other', $this->lock->getLockInfo()['migration_id']);
        $this->assertFalse($this->storage->loadMigrationData('mig_1')['backup_running']);
    }

    public function testAnotherOwnerCannotMutateTheSameMigration(): void
    {
        $this->lock->acquire('other');
        $this->storage->saveMigrationData('mig_1', [
            'tables' => [$this->item('old_posts', 'pending', ['pending'])],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Migration lock is held by another migration');

        (new BackupService(null, $this->storage, $this->lock))->setBackupRunning('mig_1', true);
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
        $this->lock->acquire('mig_1');
        $service = new BackupService($scanner, $this->storage, $this->lock);

        $batch = $service->getDatabaseNextChunksBatch('mig_1', 2);

        $this->assertSame(2, $batch['processed_chunks']);
        $this->assertTrue($batch['completed']);
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
