<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Api\Controllers\BackupController;
use WordpressMigrator\Services\Backup\BackupService;
use WordpressMigrator\Services\Backup\ChunkService;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BackupControllerTest extends TestCase
{
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
