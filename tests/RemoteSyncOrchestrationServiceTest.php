<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\MigrationLock;
use WordpressMigrator\Services\Backup\BackupService;
use WordpressMigrator\Services\Migration\MigrationService;
use WordpressMigrator\Services\RemoteSync\RemoteSyncExecutor;
use WordpressMigrator\Services\RemoteSync\RemoteSyncOrchestrationService;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class RemoteSyncOrchestrationServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private const TOKEN = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

    private MigrationService&MockObject $migrationService;

    private BackupService&MockObject $backupService;

    private RemoteSyncExecutor&MockObject $executor;

    protected function setUp(): void
    {
        $this->migrationService = $this->createMock(MigrationService::class);
        $this->backupService = $this->createMock(BackupService::class);
        $this->executor = $this->createMock(RemoteSyncExecutor::class);
    }

    #[After]
    protected function cleanUpServerGlobals(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST']);
    }

    private function lock(): MigrationLock
    {
        return new MigrationLock($this->createTemporaryDirectory() . 'lock.json');
    }

    private function service(?MigrationLock $lock = null): RemoteSyncOrchestrationService
    {
        return new RemoteSyncOrchestrationService(
            $this->migrationService,
            $this->backupService,
            $this->executor,
            $lock ?? $this->lock()
        );
    }

    private function validOptions(array $overrides = []): array
    {
        return array_merge([
            'destination_url' => 'https://dest.example.com/mig?token=' . self::TOKEN,
        ], $overrides);
    }

    public static function missingDestinationUrlValues(): array
    {
        return [
            'missing key' => [[]],
            'empty string' => [['destination_url' => '']],
        ];
    }

    #[DataProvider('missingDestinationUrlValues')]
    public function testMissingDestinationUrlIsRejected(array $options): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('destination_url is required');

        $this->service()->startMigration($options);
    }

    public function testInvalidDestinationUrlIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('destination_url must be a valid URL');

        $this->service()->startMigration(['destination_url' => 'not-a-url']);
    }

    public function testDestinationUrlWithoutSchemeOrHostFailsUrlParsing(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid destination URL format');

        $this->service()->startMigration(['destination_url' => 'mailto:test@example.com']);
    }

    public function testBothBackupFlagsFalseIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('At least one of backup_database or backup_files must be true');

        $this->service()->startMigration($this->validOptions([
            'backup_database' => false,
            'backup_files' => false,
        ]));
    }

    public function testUrlWithoutTokenIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Token not found in destination URL');

        $this->service()->startMigration($this->validOptions([
            'destination_url' => 'https://dest.example.com/mig',
        ]));
    }

    public function testTokenThatIsNotSixtyFourHexCharsIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid token format');

        $this->service()->startMigration($this->validOptions([
            'destination_url' => 'https://dest.example.com/mig?token=abc123',
        ]));
    }

    public function testUrlParsingKeepsSchemeHostPortAndPathAndDropsTheQuery(): void
    {
        $this->migrationService->expects($this->once())
            ->method('scanAndPrepareMigration')
            ->with(
                'https://dest.example.com:8443/sub/path',
                $this->anything(),
                self::TOKEN,
                null,
                false
            )
            ->willReturn(['migrationId' => 'mig_1']);

        $result = $this->service()->startMigration($this->validOptions([
            'destination_url' => 'https://dest.example.com:8443/sub/path?token=' . self::TOKEN . '&foo=bar',
        ]));

        $this->assertSame('mig_1', $result['migration_id']);
    }

    public function testExclusionsAreMergedWithDefaultsAndPassedInNewlineFormat(): void
    {
        $expected = implode("\n", [
            '.htaccess',
            'wp-config.php',
            'wordpress-migrator',
            'wp-content/plugins/wordpress-migrator',
            'foo.txt',
            'bar.txt',
        ]);

        $this->migrationService->expects($this->once())
            ->method('scanAndPrepareMigration')
            ->with($this->anything(), $expected, $this->anything(), $this->anything(), $this->anything())
            ->willReturn(['migrationId' => 'mig_1']);

        $this->service()->startMigration($this->validOptions([
            'exclusions' => "foo.txt,bar.txt",
        ]));
    }

    public function testDefaultExclusionsArePassedWhenNoneAreGiven(): void
    {
        $expected = implode("\n", [
            '.htaccess',
            'wp-config.php',
            'wordpress-migrator',
            'wp-content/plugins/wordpress-migrator',
        ]);

        $this->migrationService->expects($this->once())
            ->method('scanAndPrepareMigration')
            ->with($this->anything(), $expected, $this->anything(), $this->anything(), $this->anything())
            ->willReturn(['migrationId' => 'mig_1']);

        $this->service()->startMigration($this->validOptions());
    }

    public function testIpAndBase64AreForwarded(): void
    {
        $this->migrationService->expects($this->once())
            ->method('scanAndPrepareMigration')
            ->with($this->anything(), $this->anything(), $this->anything(), '10.0.0.5', true)
            ->willReturn(['migrationId' => 'mig_1']);

        $this->service()->startMigration($this->validOptions([
            'ip' => '10.0.0.5',
            'base64' => true,
        ]));
    }

    public function testStartMigrationRefusesWhileLockedAndNamesTheRunningMigration(): void
    {
        $lock = $this->lock();
        $lock->acquire('existing-mig');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Another migration is already in progress. Migration ID: existing-mig. ' .
            'Please wait for it to complete or cancel it first.'
        );

        $this->service($lock)->startMigration($this->validOptions());
    }

    public function testForceReleasesTheExistingLockFirst(): void
    {
        $lock = $this->lock();
        $lock->acquire('old-mig');

        $this->migrationService->method('scanAndPrepareMigration')->willReturn(['migrationId' => 'new-mig']);

        $result = $this->service($lock)->startMigration($this->validOptions(['force' => true]));

        $this->assertSame('new-mig', $result['migration_id']);
        $this->assertTrue($lock->isLocked());
        $this->assertSame('new-mig', $lock->getLockInfo()['migration_id']);
    }

    public function testFailureToAcquireTheLockAfterScanThrows(): void
    {
        $lock = $this->createMock(MigrationLock::class);
        $lock->method('isLocked')->willReturn(false);
        $lock->expects($this->once())->method('acquire')->willReturn(false);

        $this->migrationService->method('scanAndPrepareMigration')->willReturn(['migrationId' => 'mig_1']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to acquire migration lock. Another migration may have started.');

        $this->service($lock)->startMigration($this->validOptions());
    }

    public function testResultUsesConfiguredBaseUrl(): void
    {
        Config::set('base_url', 'https://panel.example.com/');
        $this->migrationService->method('scanAndPrepareMigration')->willReturn(['migrationId' => 'mig_1']);

        $result = $this->service()->startMigration($this->validOptions());

        $this->assertSame(
            'https://panel.example.com/wp-json/wp-migrator/v1/remote-sync/status/mig_1',
            $result['status_url']
        );
        $this->assertSame(
            'https://panel.example.com/wp-json/wp-migrator/v1/remote-sync/process-batch',
            $result['process_batch_url']
        );
    }

    public function testResultFallsBackToServerGlobalsOverHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'shop.example.com';
        $this->migrationService->method('scanAndPrepareMigration')->willReturn(['migrationId' => 'mig_1']);

        $result = $this->service()->startMigration($this->validOptions());

        $this->assertSame(
            'https://shop.example.com/wp-json/wp-migrator/v1/remote-sync/status/mig_1',
            $result['status_url']
        );
        $this->assertSame(
            'https://shop.example.com/wp-json/wp-migrator/v1/remote-sync/process-batch',
            $result['process_batch_url']
        );
    }

    public function testResultFallsBackToServerGlobalsOverHttp(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'shop.example.com';
        $this->migrationService->method('scanAndPrepareMigration')->willReturn(['migrationId' => 'mig_1']);

        $result = $this->service()->startMigration($this->validOptions());

        $this->assertSame(
            'http://shop.example.com/wp-json/wp-migrator/v1/remote-sync/status/mig_1',
            $result['status_url']
        );
    }

    public function testResultShapeAndDefaultTablePrefix(): void
    {
        unset($_SERVER['HTTP_HOST']);
        Config::set('root_path', $this->createTemporaryDirectory());
        $this->migrationService->method('scanAndPrepareMigration')->willReturn(['migrationId' => 'mig_1']);

        $result = $this->service()->startMigration($this->validOptions());

        $this->assertSame('mig_1', $result['migration_id']);
        $this->assertSame('ready', $result['status']);
        $this->assertSame('database', $result['next_phase']);
        $this->assertSame('wp_', $result['table_prefix']);
        $this->assertSame(
            'Migration prepared successfully. Use process_batch_url to start processing batches.',
            $result['message']
        );
    }

    public function testTablePrefixIsReadFromWpConfigUnderRootPath(): void
    {
        $root = $this->createTemporaryDirectory();
        file_put_contents($root . 'wp-config.php', "<?php\n" .
            "define('DB_NAME', 'db');\n" .
            "define('DB_USER', 'user');\n" .
            "define('DB_HOST', 'localhost');\n" .
            "\$table_prefix = 'custom_';\n");
        Config::set('root_path', $root);
        $this->migrationService->method('scanAndPrepareMigration')->willReturn(['migrationId' => 'mig_1']);

        $result = $this->service()->startMigration($this->validOptions());

        $this->assertSame('custom_', $result['table_prefix']);
    }

    public function testStatusIsNullWhenBackupServiceReturnsEmptyArray(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([]);

        $this->assertNull($this->service()->getMigrationStatus('mig_1'));
    }

    public function testPhaseIsCompletedWhenOverallStatusIsCompleted(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([
            'status' => 'completed',
            'backup_running' => false,
            'database' => ['status' => 'completed'],
            'files' => ['status' => 'completed'],
        ]);

        $status = $this->service()->getMigrationStatus('mig_1');

        $this->assertSame('completed', $status['phase']);
        $this->assertSame('Migration completed successfully', $status['message']);
    }

    public function testPhaseIsReadyWhenBackupIsNotRunning(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([
            'status' => 'pending',
            'backup_running' => false,
        ]);

        $status = $this->service()->getMigrationStatus('mig_1');

        $this->assertSame('ready', $status['phase']);
        $this->assertSame('Migration prepared, ready to start backup', $status['message']);
    }

    public function testPhaseIsBackupDatabaseWhenDatabaseIsInProgressAndFilesIsNull(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([
            'status' => 'in_progress',
            'backup_running' => true,
            'database' => [
                'status' => 'in_progress',
                'completed_tables' => 2,
                'total_tables' => 5,
                'sent_chunks' => 10,
                'total_chunks' => 25,
                'percentage' => 40,
            ],
            'files' => null,
        ]);

        $status = $this->service()->getMigrationStatus('mig_1');

        $this->assertSame('backup_database', $status['phase']);
        $this->assertSame('Backing up database: 2/5 tables (10/25 chunks)', $status['message']);
        $this->assertSame(40, $status['percentage']);
    }

    public function testPhaseIsBackupFilesWhenDatabaseIsNullAndFilesIsInProgress(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([
            'status' => 'in_progress',
            'backup_running' => true,
            'database' => null,
            'files' => [
                'status' => 'in_progress',
                'completed_files' => 3,
                'total_files' => 9,
                'sent_chunks' => 7,
                'total_chunks' => 20,
                'percentage' => 60,
                'skipped_files' => ['bad.txt'],
            ],
        ]);

        $status = $this->service()->getMigrationStatus('mig_1');

        $this->assertSame('backup_files', $status['phase']);
        $this->assertSame('Backing up files: 3/9 files (7/20 chunks)', $status['message']);
        $this->assertSame(60, $status['percentage']);
        $this->assertSame(['bad.txt'], $status['skipped_files']);
    }

    public function testPhaseIsFinalizingWhenDatabaseAndFilesAreBothCompleted(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([
            'status' => 'in_progress',
            'backup_running' => true,
            'database' => ['status' => 'completed', 'percentage' => 100],
            'files' => ['status' => 'completed', 'percentage' => 100],
        ]);

        $status = $this->service()->getMigrationStatus('mig_1');

        $this->assertSame('finalizing', $status['phase']);
        $this->assertSame('Backup complete, ready to finalize', $status['message']);
        $this->assertSame(100, $status['percentage']);
    }

    public function testPhaseIsPendingByDefault(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([
            'status' => 'pending',
            'backup_running' => true,
            'database' => ['status' => 'pending', 'percentage' => 0],
            'files' => ['status' => 'pending', 'percentage' => 0],
        ]);

        $status = $this->service()->getMigrationStatus('mig_1');

        $this->assertSame('pending', $status['phase']);
        $this->assertSame('Migration pending', $status['message']);
        $this->assertSame(0, $status['percentage']);
    }

    public function testPercentageIsAverageWhenBothDatabaseAndFilesHaveStarted(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([
            'status' => 'in_progress',
            'backup_running' => true,
            'database' => ['status' => 'in_progress', 'percentage' => 33],
            'files' => ['status' => 'in_progress', 'percentage' => 34],
        ]);

        $status = $this->service()->getMigrationStatus('mig_1');

        $this->assertSame(33, $status['percentage']);
    }

    public function testSkippedFilesDefaultsToEmptyArrayWhenFilesIsNull(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([
            'status' => 'in_progress',
            'backup_running' => true,
            'database' => ['status' => 'in_progress', 'percentage' => 10],
            'files' => null,
        ]);

        $status = $this->service()->getMigrationStatus('mig_1');

        $this->assertSame([], $status['skipped_files']);
    }

    public function testStatusFieldsAreCopiedThroughWithDefaults(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([]);
        $status = $this->service()->getMigrationStatus('mig_1');
        $this->assertNull($status);

        $this->backupService = $this->createMock(BackupService::class);
        $this->backupService->method('getBackupStatus')->willReturn(['status' => 'pending', 'backup_running' => false]);
        $status = $this->service()->getMigrationStatus('mig_1');

        $this->assertSame('mig_1', $status['migration_id']);
        $this->assertFalse($status['backup_running']);
        $this->assertSame('pending', $status['status']);
        $this->assertNull($status['database']);
        $this->assertNull($status['files']);
        $this->assertNull($status['last_activity']);
    }

    public function testProcessBatchThrowsWhenBackupStatusIsEmpty(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn([]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Migration not found');

        $this->service()->processBatch('mig_1');
    }

    public function testProcessBatchThrowsWhenStatusIsNotFound(): void
    {
        $this->backupService->method('getBackupStatus')->willReturn(['status' => 'not_found']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Migration not found');

        $this->service()->processBatch('mig_1');
    }

    public function testDatabasePhaseInProgressKeepsTheDatabasePhase(): void
    {
        $this->backupService->method('getBackupStatus')->willReturnOnConsecutiveCalls(
            ['database' => ['status' => 'in_progress'], 'files' => null],
            [
                'database' => [
                    'percentage' => 40,
                    'current_table' => 'wp_posts',
                    'next_table' => 'wp_options',
                    'sent_chunks' => 10,
                    'total_chunks' => 25,
                ],
                'files' => null,
            ]
        );
        $this->backupService->expects($this->once())
            ->method('getDatabaseNextChunksBatch')
            ->with('mig_1', 50)
            ->willReturn(['processed_chunks' => 10, 'completed' => false]);

        $result = $this->service()->processBatch('mig_1');

        $this->assertSame('mig_1', $result['migration_id']);
        $this->assertSame('database', $result['phase']);
        $this->assertSame(10, $result['processed_chunks']);
        $this->assertFalse($result['completed']);
        $this->assertSame('database', $result['next_phase']);
        $this->assertArrayNotHasKey('message', $result);
        $this->assertSame([
            'percentage' => 40,
            'current_item' => 'wp_posts',
            'next_item' => 'wp_options',
            'sent_chunks' => 10,
            'total_chunks' => 25,
        ], $result['progress']);
    }

    public function testDatabasePhaseCompletionMovesToFiles(): void
    {
        $this->backupService->method('getBackupStatus')->willReturnOnConsecutiveCalls(
            ['database' => ['status' => 'in_progress'], 'files' => null],
            ['database' => ['percentage' => 100], 'files' => null]
        );
        $this->backupService->method('getDatabaseNextChunksBatch')
            ->willReturn(['processed_chunks' => 0, 'completed' => true]);

        $result = $this->service()->processBatch('mig_1');

        $this->assertSame('files', $result['next_phase']);
        $this->assertSame('Database phase completed. Continue with files phase.', $result['message']);
    }

    public function testFilesPhaseInProgressReturnsSkippedFilesAndKeepsPhase(): void
    {
        $this->backupService->method('getBackupStatus')->willReturnOnConsecutiveCalls(
            ['database' => null, 'files' => ['status' => 'in_progress']],
            ['database' => null, 'files' => ['percentage' => 50, 'skipped_files' => ['x.txt']]],
            ['database' => null, 'files' => ['status' => 'in_progress']]
        );
        $this->backupService->expects($this->once())
            ->method('getFileNextChunksBatch')
            ->with('mig_1', 50)
            ->willReturn(['processed_chunks' => 5, 'completed' => false]);

        $result = $this->service()->processBatch('mig_1');

        $this->assertSame('files', $result['phase']);
        $this->assertSame(['x.txt'], $result['skipped_files']);
        $this->assertFalse($result['completed']);
        $this->assertSame('files', $result['next_phase']);
        $this->assertArrayNotHasKey('finalized', $result);
    }

    public function testFilesPhaseCompletionWithoutBothSidesDoneSetsNextPhaseNull(): void
    {
        $this->backupService->method('getBackupStatus')->willReturnOnConsecutiveCalls(
            ['database' => ['status' => 'completed'], 'files' => ['status' => 'in_progress']],
            ['database' => ['status' => 'completed'], 'files' => ['percentage' => 100, 'skipped_files' => []]],
            ['database' => ['status' => 'in_progress'], 'files' => ['status' => 'completed']]
        );
        $this->backupService->method('getFileNextChunksBatch')
            ->willReturn(['processed_chunks' => 0, 'completed' => true]);

        $result = $this->service()->processBatch('mig_1');

        $this->assertTrue($result['completed']);
        $this->assertNull($result['next_phase']);
        $this->assertArrayNotHasKey('finalized', $result);
    }

    public function testFilesPhaseFinalizesWhenBothDatabaseAndFilesAreComplete(): void
    {
        $lock = $this->lock();
        $lock->acquire('mig_1');
        $this->backupService->method('getBackupStatus')->willReturnOnConsecutiveCalls(
            ['database' => ['status' => 'completed'], 'files' => ['status' => 'in_progress']],
            ['database' => ['status' => 'completed'], 'files' => ['percentage' => 100, 'skipped_files' => []]],
            ['database' => ['status' => 'completed'], 'files' => ['status' => 'completed']]
        );
        $this->backupService->method('getFileNextChunksBatch')
            ->willReturn(['processed_chunks' => 0, 'completed' => true]);
        $this->migrationService->expects($this->once())
            ->method('finalizeMigration')
            ->with('mig_1')
            ->willReturn(['files' => ['sent' => 3]]);

        $result = $this->service($lock)->processBatch('mig_1');

        $this->assertTrue($result['finalized']);
        $this->assertSame('Migration completed and finalized successfully', $result['message']);
        $this->assertSame(['files' => ['sent' => 3]], $result['summary']);
        $this->assertFalse($lock->isLocked());
    }

    public function testOnlyFilesMigrationSkipsTheDatabasePhaseEntirely(): void
    {
        $this->backupService->method('getBackupStatus')->willReturnOnConsecutiveCalls(
            ['database' => null, 'files' => ['status' => 'pending']],
            ['database' => null, 'files' => ['percentage' => 0, 'skipped_files' => []]],
            ['database' => null, 'files' => ['status' => 'pending']]
        );
        $this->backupService->expects($this->once())->method('getFileNextChunksBatch')
            ->willReturn(['processed_chunks' => 3, 'completed' => false]);
        $this->backupService->expects($this->never())->method('getDatabaseNextChunksBatch');

        $result = $this->service()->processBatch('mig_1');

        $this->assertSame('files', $result['phase']);
    }

    public function testAlreadyCompletedMigrationReturnsFinalizedWithoutTouchingBackupOrLock(): void
    {
        $lock = $this->createMock(MigrationLock::class);
        $lock->expects($this->never())->method('release');
        $this->backupService->expects($this->once())->method('getBackupStatus')->willReturn([
            'database' => ['status' => 'completed'],
            'files' => ['status' => 'completed'],
        ]);
        $this->migrationService->expects($this->never())->method('finalizeMigration');
        $this->backupService->expects($this->never())->method('getDatabaseNextChunksBatch');
        $this->backupService->expects($this->never())->method('getFileNextChunksBatch');

        $result = $this->service($lock)->processBatch('mig_1');

        $this->assertSame('mig_1', $result['migration_id']);
        $this->assertSame('completed', $result['phase']);
        $this->assertSame(0, $result['processed_chunks']);
        $this->assertTrue($result['finalized']);
        $this->assertTrue($result['completed']);
        $this->assertSame('Migration is already completed', $result['message']);
    }
}
