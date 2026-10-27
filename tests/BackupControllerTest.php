<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Api\Controllers\BackupController;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\MigrationLock;
use WordpressMigrator\Services\Backup\BackupService;
use WordpressMigrator\Services\Backup\ChunkService;
use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Tests\Support\FakeDatabase;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

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

        $response = (new BackupController($backup, $this->createMock(ChunkService::class)))
            ->fileNextChunk(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame(200, $response->get_status());
        $this->assertSame([
            'success' => true,
            'completed' => false,
            'chunk' => ['type' => 'file_chunk', 'file' => ['path' => 'wp-content/a.jpg']],
            'progress' => ['sent' => 1],
        ], $response->get_data());
    }

    public function testStartBackupTakesTheLockThroughTheService(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->expects($this->once())->method('startBackup')->with('mig_1');

        $response = (new BackupController($backup, $this->createMock(ChunkService::class)))
            ->startBackup(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame(200, $response->get_status());
    }

    public function testStartBackupFailureIsReportedAndNothingElseRuns(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('startBackup')->willThrowException(new Exception('Failed to acquire migration lock.'));

        $response = (new BackupController($backup, $this->createMock(ChunkService::class)))
            ->startBackup(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame('start_failed', $response->get_error_code());
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
        $controller = new BackupController($backup, $this->createMock(ChunkService::class));

        $controller->stopBackup(new \WP_REST_Request(['migration_id' => 'mig_1']));
        $controller->stopBackup(new \WP_REST_Request(['migration_id' => 'mig_1', 'keep_lock' => 'true']));
    }

    public function testNoFileChunkReturnsCompletionMessage(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('getFileNextChunk')->willReturn(null);
        $backup->expects($this->once())->method('getFileProgress')->with('mig_1', null)
            ->willReturn(['sent' => 2]);

        $response = (new BackupController($backup, $this->createMock(ChunkService::class)))
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

        $response = (new BackupController($backup, $this->createMock(ChunkService::class)))
            ->databaseNextChunksBatch(new \WP_REST_Request(['migration_id' => 'mig_1', 'batch_size' => 3]));

        $this->assertSame(['chunks' => [['type' => 'table_data']]], $response->get_data());
    }

    public function testBackupServiceExceptionBecomesApiError(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('getFileNextChunk')->willThrowException(new Exception('disk unavailable'));

        $response = (new BackupController($backup, $this->createMock(ChunkService::class)))
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
            new BackupService($this->createMock(DatabaseScanner::class), new MigrationStorageService(), $lock),
            $this->createMock(ChunkService::class)
        ))->databaseNextChunk(new \WP_REST_Request(['migration_id' => '../outside']));

        $this->assertSame('invalid_id', $response->get_error_code());
        $this->assertSame('Invalid migration ID', $response->get_error_message());
        $this->assertSame(['status' => 400], $response->get_error_data());
        $this->assertDirectoryDoesNotExist($dir . 'collected');
    }

    public function testCollectChunkRejectsInvalidBodyWithoutSaving(): void
    {
        $chunks = $this->createMock(ChunkService::class);
        $chunks->expects($this->once())->method('isValidChunkData')->with(['migration_id' => 'mig_1'])
            ->willReturn(false);
        $chunks->expects($this->never())->method('saveChunk');

        $response = (new BackupController($this->createMock(BackupService::class), $chunks))
            ->collectChunk(new \WP_REST_Request([], [], ['migration_id' => 'mig_1']));

        $this->assertSame('invalid_chunk', $response->get_error_code());
        $this->assertSame(['status' => 400], $response->get_error_data());
    }

    public function testCollectChunkWithoutTokenAcknowledgesAndRegistersBody(): void
    {
        $body = ['type' => 'file_chunk', 'migration_id' => 'mig_1', 'chunk_id' => 'chunk_1'];
        $chunks = $this->createMock(ChunkService::class);
        $chunks->method('isValidChunkData')->with($body)->willReturn(true);
        $chunks->expects($this->once())->method('saveChunk')->with('mig_1', $body)->willReturn('chunk_1.json');
        $chunks->expects($this->once())->method('registerChunk')->with('mig_1', $body);

        $response = (new BackupController($this->createMock(BackupService::class), $chunks))
            ->collectChunk(new \WP_REST_Request([], [], $body));

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('acknowledgment', $data['type']);
        $this->assertSame('mig_1', $data['migration_id']);
        $this->assertSame('chunk_1', $data['chunk_id']);
        $this->assertSame('received', $data['status']);
        $this->assertFalse($data['processed']);
        $this->assertSame('chunk_1.json', $data['saved_to']);
        $this->assertNull($data['error']);
        $this->assertNotEmpty($data['timestamp']);
    }

    public function testCollectChunkRejectsUnsafeMigrationIdWithoutSaving(): void
    {
        $body = ['type' => 'file_chunk', 'migration_id' => '../outside', 'chunk_id' => 'chunk_1'];
        $chunks = $this->createMock(ChunkService::class);
        $chunks->method('isValidChunkData')->with($body)->willReturn(true);
        $chunks->expects($this->never())->method('saveChunk');
        $chunks->expects($this->never())->method('registerChunk');

        $response = (new BackupController($this->createMock(BackupService::class), $chunks))
            ->collectChunk(new \WP_REST_Request([], [], $body));

        $this->assertSame('invalid_id', $response->get_error_code());
        $this->assertSame('Invalid migration ID', $response->get_error_message());
        $this->assertSame(['status' => 400], $response->get_error_data());
    }

    public function testCollectChunkRejectsUnsafeChunkIdWithoutSaving(): void
    {
        $body = ['type' => 'file_chunk', 'migration_id' => 'mig_1', 'chunk_id' => '../other_mig/status'];
        $chunks = $this->createMock(ChunkService::class);
        $chunks->method('isValidChunkData')->with($body)->willReturn(true);
        $chunks->expects($this->never())->method('saveChunk');
        $chunks->expects($this->never())->method('registerChunk');

        $response = (new BackupController($this->createMock(BackupService::class), $chunks))
            ->collectChunk(new \WP_REST_Request([], [], $body));

        $this->assertSame('invalid_id', $response->get_error_code());
        $this->assertSame('Invalid chunk ID', $response->get_error_message());
        $this->assertSame(['status' => 400], $response->get_error_data());
    }

    public function testIncompleteCompletionIsNotASuccessfulHttpResponse(): void
    {
        Config::set('migration_dir', sys_get_temp_dir() . '/wp-migrator-incomplete-' . bin2hex(random_bytes(4)) . '/');
        FakeDatabase::install();

        $response = (new BackupController($this->createMock(BackupService::class), new ChunkService()))
            ->collectChunk(new \WP_REST_Request([], [], [
                'type' => 'migration_complete',
                'migration_id' => 'mig_1',
            ]));

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('collect_failed', $response->get_error_code());
        $this->assertSame('Migration manifest is incomplete', $response->get_error_message());
        $this->assertSame(['status' => 500], $response->get_error_data());
        $this->assertNotSame(200, $response->get_error_data()['status']);
    }

    public function testIncompleteCompletionIsNotSaved(): void
    {
        $dir = sys_get_temp_dir() . '/wp-migrator-unsaved-' . bin2hex(random_bytes(4)) . '/';
        Config::set('migration_dir', $dir);
        FakeDatabase::install();

        (new BackupController($this->createMock(BackupService::class), new ChunkService()))
            ->collectChunk(new \WP_REST_Request([], [], [
                'type' => 'migration_complete',
                'migration_id' => 'mig_1',
            ]));

        $this->assertFileDoesNotExist($dir . 'collected/mig_1/migration_complete.json');
    }

    public function testFinishedCompletionAcknowledgementIncludesSuccess(): void
    {
        Config::set('migration_dir', sys_get_temp_dir() . '/wp-migrator-complete-' . bin2hex(random_bytes(4)) . '/');
        FakeDatabase::install();
        $chunks = new ChunkService();
        $chunks->saveChunk('mig_1', ['type' => 'info', 'migration_id' => 'mig_1']);
        $chunks->registerChunk('mig_1', ['type' => 'info', 'migration_id' => 'mig_1', 'files' => [], 'tables' => []]);

        $response = (new BackupController($this->createMock(BackupService::class), $chunks))
            ->collectChunk(new \WP_REST_Request([], [], [
                'type' => 'migration_complete',
                'migration_id' => 'mig_1',
                'chunk_id' => 'complete_1',
            ]));

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertTrue($data['processed']);
        $this->assertSame('acknowledgment', $data['type']);
        $this->assertSame('mig_1', $data['migration_id']);
        $this->assertSame('complete_1', $data['chunk_id']);
        $this->assertSame('received', $data['status']);
        $this->assertNull($data['error']);
        $this->assertSame('migration_completed', $data['processing_result']['action']);
    }

    public function testCollectChunkSaveExceptionBecomesApiError(): void
    {
        $chunks = $this->createMock(ChunkService::class);
        $chunks->method('isValidChunkData')->willReturn(true);
        $chunks->method('saveChunk')->willThrowException(new Exception('cannot write'));
        $chunks->expects($this->never())->method('registerChunk');

        $response = (new BackupController($this->createMock(BackupService::class), $chunks))
            ->collectChunk(new \WP_REST_Request([], [], ['type' => 'file_chunk']));

        $this->assertSame('collect_failed', $response->get_error_code());
        $this->assertSame('cannot write', $response->get_error_message());
        $this->assertSame(['status' => 500], $response->get_error_data());
    }
}
