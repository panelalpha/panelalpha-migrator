<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Api\Controllers\BackupController;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\DestinationNotAllowedException;
use PanelAlpha\Migrator\Helpers\MigrationLock;
use PanelAlpha\Migrator\Services\Backup\BackupService;
use PanelAlpha\Migrator\Services\Migration\DestinationHandshake;
use PanelAlpha\Migrator\Services\Migration\MigrationStorageService;
use PanelAlpha\Migrator\Services\Scanner\DatabaseScanner;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BackupControllerTest extends TestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/WordPressStubs.php';
    }

    public function testFileNextChunkIncludesCurrentFileAndProgress(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->expects($this->once())->method('getFileNextChunk')->with('mig_1')->willReturn([
            'type' => 'file_chunk', 'file' => ['path' => 'wp-content/a.jpg'],
        ]);
        $backup->expects($this->once())->method('getFileProgress')
            ->with('mig_1', 'wp-content/a.jpg')->willReturn(['sent' => 1]);

        $response = (new BackupController($backup))
            ->fileNextChunk(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame(200, $response->get_status());
        $this->assertSame([
            'success' => true,
            'completed' => false,
            'chunk' => ['type' => 'file_chunk', 'file' => ['path' => 'wp-content/a.jpg']],
            'progress' => ['sent' => 1],
        ], $response->get_data());
    }

    public function testStartBackupTakesTheLockThroughTheServiceAndSendsTheInfoChunk(): void
    {
        $backup = $this->createMock(BackupService::class);
        $handshake = $this->createMock(DestinationHandshake::class);
        $backup->expects($this->once())->method('startBackup')->with('mig_1');
        $handshake->expects($this->once())->method('sendInfo')->with('mig_1');
        $backup->expects($this->never())->method('stopBackup');

        $response = (new BackupController($backup, null, null, $handshake))
            ->startBackup(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame(200, $response->get_status());
    }

    public function testStartBackupFailureIsReportedAndNothingElseRuns(): void
    {
        $backup = $this->createMock(BackupService::class);
        $handshake = $this->createMock(DestinationHandshake::class);
        $backup->method('startBackup')->willThrowException(new Exception('Failed to acquire migration lock.'));
        $handshake->expects($this->never())->method('sendInfo');

        $response = (new BackupController($backup, null, null, $handshake))
            ->startBackup(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame('start_failed', $response->get_error_code());
    }

    public function testStartBackupReleasesTheLockWhenTheDestinationRefusesTheInfoChunk(): void
    {
        $backup = $this->createMock(BackupService::class);
        $handshake = $this->createMock(DestinationHandshake::class);
        $handshake->method('sendInfo')->willThrowException(new Exception('Destination returned error: 400'));
        $backup->expects($this->once())->method('stopBackup')->with('mig_1');

        $response = (new BackupController($backup, null, null, $handshake))
            ->startBackup(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame('start_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
        $this->assertStringContainsString('Destination returned error: 400', $response->get_error_message());
    }

    public function testStartBackupReportsTheInfoFailureEvenWhenReleasingTheLockFails(): void
    {
        $backup = $this->createMock(BackupService::class);
        $handshake = $this->createMock(DestinationHandshake::class);
        $handshake->method('sendInfo')->willThrowException(new Exception('Destination returned error: 400'));
        $backup->method('stopBackup')->willThrowException(new Exception('Migration file not found'));

        $response = (new BackupController($backup, null, null, $handshake))
            ->startBackup(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertStringContainsString('Destination returned error: 400', $response->get_error_message());
    }

    public function testStartBackupRefusesABlockedDestinationWith400(): void
    {
        $backup = $this->createMock(BackupService::class);
        $handshake = $this->createMock(DestinationHandshake::class);
        $handshake->method('sendInfo')->willThrowException(new DestinationNotAllowedException());

        $response = (new BackupController($backup, null, null, $handshake))
            ->startBackup(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame('destination_not_allowed', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function testStopBackupReleasesTheLockUnlessKeepLockIsSet(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->expects($this->exactly(2))->method('stopBackup')
            ->willReturnCallback(function (string $id, bool $keep): void {
                static $calls = [];
                $calls[] = $keep;
                if (count($calls) === 2) {
                    $this->assertSame([false, true], $calls);
                }
            });
        $controller = new BackupController($backup);

        $controller->stopBackup(new \WP_REST_Request(['migration_id' => 'mig_1']));
        $controller->stopBackup(new \WP_REST_Request(['migration_id' => 'mig_1', 'keep_lock' => 'true']));
    }

    public function testNoFileChunkReturnsCompletionMessage(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('getFileNextChunk')->willReturn(null);
        $backup->expects($this->once())->method('getFileProgress')->with('mig_1', null)
            ->willReturn(['sent' => 2]);

        $response = (new BackupController($backup))
            ->fileNextChunk(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame([
            'success' => true,
            'completed' => true,
            'message' => 'All file chunks sent',
            'progress' => ['sent' => 2],
        ], $response->get_data());
    }

    public function testDatabaseBatchPassesRequestedSizeAndReturnsServiceResult(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->expects($this->once())->method('getDatabaseNextChunksBatch')
            ->with('mig_1', 3)->willReturn(['chunks' => [['type' => 'table_data']]]);

        $response = (new BackupController($backup))
            ->databaseNextChunksBatch(new \WP_REST_Request(['migration_id' => 'mig_1', 'batch_size' => 3]));

        $this->assertSame(['chunks' => [['type' => 'table_data']]], $response->get_data());
    }

    public function testBackupServiceExceptionBecomesApiError(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('getFileNextChunk')->willThrowException(new Exception('disk unavailable'));

        $response = (new BackupController($backup))
            ->fileNextChunk(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame('backup_failed', $response->get_error_code());
        $this->assertSame('disk unavailable', $response->get_error_message());
        $this->assertSame(['status' => 500], $response->get_error_data());
    }

    public function testChunkRouteRejectsUnsafeMigrationIdAsClientError(): void
    {
        $dir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $dir);
        $lock = new MigrationLock($dir . 'lock.json');
        $this->assertTrue($lock->acquire('../outside'));

        $response = (new BackupController(
            new BackupService($this->createMock(DatabaseScanner::class), new MigrationStorageService(), $lock)
        ))->databaseNextChunk(new \WP_REST_Request(['migration_id' => '../outside']));

        $this->assertSame('invalid_id', $response->get_error_code());
        $this->assertSame('Invalid migration ID', $response->get_error_message());
        $this->assertSame(['status' => 400], $response->get_error_data());
        $this->assertDirectoryDoesNotExist($dir . 'collected');
    }
}
