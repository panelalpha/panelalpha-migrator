<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Services\Backup\BackupService;
use WordpressMigrator\Services\RemoteSync\RemoteSyncExecutor;

final class RemoteSyncExecutorTest extends TestCase
{
    private BackupService&MockObject $backupService;

    private RemoteSyncExecutor $executor;

    protected function setUp(): void
    {
        $this->backupService = $this->createMock(BackupService::class);
        $this->executor = new RemoteSyncExecutor($this->backupService);
    }

    private function recordSetBackupRunningCalls(): array
    {
        $calls = [];
        $this->backupService->method('setBackupRunning')->willReturnCallback(
            function (string $migrationId, bool $running) use (&$calls): void {
                $calls[] = [$migrationId, $running];
            }
        );

        return $calls;
    }

    public function testDatabaseAndFilesAreBothBackedUpByDefault(): void
    {
        $calls = [];
        $this->backupService->method('setBackupRunning')->willReturnCallback(
            function (string $migrationId, bool $running) use (&$calls): void {
                $calls[] = [$migrationId, $running];
            }
        );
        $this->backupService->method('getDatabaseNextChunk')->willReturnOnConsecutiveCalls(['n' => 1], ['n' => 2], null);
        $this->backupService->method('getFileNextChunk')->willReturnOnConsecutiveCalls(['n' => 1], null);

        $result = $this->executor->executeBackup('mig_1', 'https://dest.example.com', 'token');

        $this->assertSame([
            'success' => true,
            'database' => ['total_chunks' => 2, 'sent_chunks' => 2, 'status' => 'completed'],
            'files' => ['total_chunks' => 1, 'sent_chunks' => 1, 'status' => 'completed'],
        ], $result);
        $this->assertSame([['mig_1', true], ['mig_1', false]], $calls);
    }

    public function testDatabaseOnlyBackupSkipsFiles(): void
    {
        $this->backupService->method('getDatabaseNextChunk')->willReturnOnConsecutiveCalls(['n' => 1], null);
        $this->backupService->expects($this->never())->method('getFileNextChunk');

        $result = $this->executor->executeBackup('mig_1', 'https://dest.example.com', 'token', true, false);

        $this->assertSame(['total_chunks' => 1, 'sent_chunks' => 1, 'status' => 'completed'], $result['database']);
        $this->assertNull($result['files']);
    }

    public function testFilesOnlyBackupSkipsDatabase(): void
    {
        $this->backupService->method('getFileNextChunk')->willReturnOnConsecutiveCalls(['n' => 1], null);
        $this->backupService->expects($this->never())->method('getDatabaseNextChunk');

        $result = $this->executor->executeBackup('mig_1', 'https://dest.example.com', 'token', false, true);

        $this->assertNull($result['database']);
        $this->assertSame(['total_chunks' => 1, 'sent_chunks' => 1, 'status' => 'completed'], $result['files']);
    }

    public function testEmptyBackupHasZeroChunksButStillCompletes(): void
    {
        $this->backupService->method('getDatabaseNextChunk')->willReturn(null);
        $this->backupService->method('getFileNextChunk')->willReturn(null);

        $result = $this->executor->executeBackup('mig_1', 'https://dest.example.com', 'token');

        $this->assertSame(['total_chunks' => 0, 'sent_chunks' => 0, 'status' => 'completed'], $result['database']);
        $this->assertSame(['total_chunks' => 0, 'sent_chunks' => 0, 'status' => 'completed'], $result['files']);
    }

    public function testSetBackupRunningFalseIsCalledEvenWhenDatabaseBackupThrows(): void
    {
        $calls = [];
        $this->backupService->method('setBackupRunning')->willReturnCallback(
            function (string $migrationId, bool $running) use (&$calls): void {
                $calls[] = [$migrationId, $running];
            }
        );
        $this->backupService->method('getDatabaseNextChunk')->willThrowException(new Exception('boom'));

        try {
            $this->executor->executeBackup('mig_1', 'https://dest.example.com', 'token');
            $this->fail('Expected an exception');
        } catch (Exception $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([['mig_1', true], ['mig_1', false]], $calls);
    }

    public function testSetBackupRunningFalseIsCalledEvenWhenFilesBackupThrows(): void
    {
        $calls = [];
        $this->backupService->method('setBackupRunning')->willReturnCallback(
            function (string $migrationId, bool $running) use (&$calls): void {
                $calls[] = [$migrationId, $running];
            }
        );
        $this->backupService->method('getDatabaseNextChunk')->willReturn(null);
        $this->backupService->method('getFileNextChunk')->willThrowException(new Exception('files failed'));

        try {
            $this->executor->executeBackup('mig_1', 'https://dest.example.com', 'token');
            $this->fail('Expected an exception');
        } catch (Exception $e) {
            $this->assertSame('files failed', $e->getMessage());
        }

        $this->assertSame([['mig_1', true], ['mig_1', false]], $calls);
    }
}
