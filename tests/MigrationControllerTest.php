<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Api\Controllers\MigrationController;
use PanelAlpha\Migrator\Dto\ChunkInfo;
use PanelAlpha\Migrator\Dto\Migration;
use PanelAlpha\Migrator\Dto\MigrationSummary;
use PanelAlpha\Migrator\Dto\TableInfo;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\DestinationGuard;
use PanelAlpha\Migrator\Helpers\MigrationLock;
use PanelAlpha\Migrator\Services\Backup\BackupService;
use PanelAlpha\Migrator\Services\Migration\DestinationHandshake;
use PanelAlpha\Migrator\Services\Migration\DestinationHandshakeException;
use PanelAlpha\Migrator\Services\Migration\HtaccessService;
use PanelAlpha\Migrator\Services\Migration\MigrationInfoGenerator;
use PanelAlpha\Migrator\Services\Migration\MigrationService;
use PanelAlpha\Migrator\Services\Migration\MigrationStorageService;
use PanelAlpha\Migrator\Services\Scanner\DatabaseScanner;
use PanelAlpha\Migrator\Tests\Support\FakeDestination;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MigrationControllerTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    /** @var DestinationHandshake&\PHPUnit\Framework\MockObject\MockObject */
    private $handshake;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/WordPressStubs.php';
        $this->handshake = $this->createMock(DestinationHandshake::class);
        $this->handshake->method('check')->willReturn('pretty');
    }

    public function testScanPassesOptionsAndConvertsSummaryKeys(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->once())->method('scanAndPrepareMigration')
            ->with('https://destination.example', '*.log', 'secret', '8.8.4.4')
            ->willReturn([
                'migrationId' => 'mig_1',
                'migrationData' => ['summary' => ['totalFiles' => 2, 'nestedData' => ['rowCount' => 4]]],
            ]);
        $service->expects($this->once())->method('getMigrationFilePath')
            ->with('mig_1')->willReturn('/tmp/mig_1.json');

        $response = $this->controller($service)->scan(new \WP_REST_Request(
            ['destination_url' => 'https://destination.example'],
            [],
            ['exclusions' => '*.log', 'token' => 'secret', 'ip' => '8.8.4.4']
        ));

        $this->assertSame([
            'success' => true,
            'migration_id' => 'mig_1',
            'file_path' => '/tmp/mig_1.json',
            'summary' => ['total_files' => 2, 'nested_data' => ['row_count' => 4]],
        ], $response->get_data());
    }

    public function testScanForwardsTheApprovedIpAsThePinnedIp(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->once())->method('scanAndPrepareMigration')
            ->with('https://destination.example', null, null, null, false, true, true, '8.8.8.8')
            ->willReturn(['migrationId' => 'mig_1', 'migrationData' => ['summary' => []]]);

        $this->controller($service)->scan(new \WP_REST_Request(['destination_url' => 'https://destination.example']));
    }

    public function testScanForwardsInsecureTlsAndTreatsTheStringFalseAsOff(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->exactly(2))->method('scanAndPrepareMigration')
            ->willReturnCallback(function (...$args) {
                static $calls = 0;
                $this->assertSame('8.8.4.4', $args[3]);
                $this->assertSame($calls++ === 0, $args[8]);

                return ['migrationId' => 'mig_1', 'migrationData' => ['summary' => []]];
            });
        $controller = $this->controller($service);

        $controller->scan(new \WP_REST_Request(
            ['destination_url' => 'https://destination.example'],
            [],
            ['ip' => '8.8.4.4', 'insecure_tls' => true]
        ));
        $controller->scan(new \WP_REST_Request(
            ['destination_url' => 'https://destination.example'],
            [],
            ['ip' => '8.8.4.4', 'insecure_tls' => 'false']
        ));
    }

    public function testFilesOnlyScanLeavesDatabaseDisabledAndCanFinish(): void
    {
        $this->assertUnselectedPhaseIsDisabledAndMigrationCanFinish(false, true, 'database');
    }

    public function testDatabaseOnlyScanLeavesFilesDisabledAndCanFinish(): void
    {
        $this->assertUnselectedPhaseIsDisabledAndMigrationCanFinish(true, false, 'files');
    }

    public function testScanWithoutPhaseFlagsStillPreparesBothPhases(): void
    {
        $status = $this->scanPhaseSelection(null, null);

        $this->assertSame('pending', $status['database']['status']);
        $this->assertSame('pending', $status['files']['status']);
        $this->assertTrue((new MigrationLock())->isLocked());

        $response = $this->controller($this->lastService)->finalize(new \WP_REST_Request([
            'migration_id' => $status['migration_id'],
        ]));

        $this->assertSame('not_complete', $response->get_error_code());
        $this->assertSame(['status' => 400], $response->get_error_data());
        $this->assertTrue((new MigrationLock())->isLocked());
    }

    public function testScanRejectsAPrivateDestinationBeforeCreatingAMigration(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->never())->method('scanAndPrepareMigration');

        $response = $this->controller($service)->scan(new \WP_REST_Request([
            'destination_url' => 'http://127.0.0.1/',
        ]));

        $this->assertSame('destination_not_allowed', $response->get_error_code());
        $this->assertSame('The destination address is not allowed.', $response->get_error_message());
        $this->assertSame(['status' => 400], $response->get_error_data());
    }

    public function testScanChecksTheDestinationFirstAndSavesTheRestMode(): void
    {
        $this->handshake = $this->createMock(DestinationHandshake::class);
        $this->handshake->expects($this->once())->method('check')->with([
            'destinationUrl' => 'https://destination.example',
            'token' => 'secret',
            'ip' => '8.8.4.4',
            'pinned_ip' => '8.8.4.4',
            'insecure_tls' => true,
        ])->willReturn('plain');
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->once())->method('scanAndPrepareMigration')
            ->with('https://destination.example', null, 'secret', '8.8.4.4', false, true, true, '8.8.4.4', true, 'plain')
            ->willReturn(['migrationId' => 'mig_1', 'migrationData' => ['summary' => []]]);

        $this->controller($service)->scan(new \WP_REST_Request(
            ['destination_url' => 'https://destination.example'],
            [],
            ['token' => 'secret', 'ip' => '8.8.4.4', 'insecure_tls' => true]
        ));
    }

    public function testScanStopsWhenTheDestinationIsIncompatible(): void
    {
        $this->handshake = $this->createMock(DestinationHandshake::class);
        $this->handshake->method('check')->willThrowException(
            new DestinationHandshakeException('destination_incompatible', 'Destination does not have PanelAlpha Migrator 1.2.1 or newer active.')
        );
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->never())->method('scanAndPrepareMigration');

        $response = $this->controller($service)->scan(new \WP_REST_Request(
            ['destination_url' => 'https://destination.example'],
            [],
            ['token' => 'secret']
        ));

        $this->assertSame('destination_incompatible', $response->get_error_code());
        $this->assertSame('Destination does not have PanelAlpha Migrator 1.2.1 or newer active.', $response->get_error_message());
        $this->assertSame(['status' => 400], $response->get_error_data());
    }

    public function testScanRejectsDisablingBothPhases(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->never())->method('scanAndPrepareMigration');

        $response = $this->controller($service)->scan(new \WP_REST_Request(
            ['destination_url' => 'https://destination.example'],
            [],
            ['backup_database' => false, 'backup_files' => false]
        ));

        $this->assertSame('invalid_selection', $response->get_error_code());
        $this->assertSame('At least one of backup_database or backup_files must be true', $response->get_error_message());
        $this->assertSame(['status' => 400], $response->get_error_data());
    }

    public static function phaseFlagValues(): array
    {
        return [
            'string false' => ['false', false],
            'string zero' => ['0', false],
            'bool false' => [false, false],
            'string true' => ['true', true],
            'string one' => ['1', true],
            'missing' => [null, true],
        ];
    }

    /**
     * @dataProvider phaseFlagValues
     */
    #[DataProvider('phaseFlagValues')]
    public function testScanReadsTheBackupFilesFlagAsABoolean($value, bool $expected): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->once())
            ->method('scanAndPrepareMigration')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), false, true, $expected)
            ->willReturn(['migrationId' => 'mig_1', 'migrationData' => ['summary' => []]]);
        $service->method('getMigrationFilePath')->willReturn('/tmp/mig_1.json');
        $body = ['backup_database' => true];
        if ($value !== null) {
            $body['backup_files'] = $value;
        }

        $this->controller($service)->scan(new \WP_REST_Request(['destination_url' => 'https://destination.example'], [], $body));
    }

    public function testScanFailureBecomesApiError(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->method('scanAndPrepareMigration')->willThrowException(new Exception('scan failed'));

        $response = $this->controller($service)->scan(new \WP_REST_Request([
            'destination_url' => 'https://destination.example',
        ]));

        $this->assertSame('scan_failed', $response->get_error_code());
        $this->assertSame('scan failed', $response->get_error_message());
        $this->assertSame(['status' => 500], $response->get_error_data());
    }

    public function testInfoReturnsRawOrConvertedData(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->once())->method('getMigrationInfoRaw')
            ->with('mig_1')->willReturn(['rowCount' => 2]);
        $service->expects($this->once())->method('getMigrationInfo')
            ->with('mig_1')->willReturn(['rowCount' => 2]);
        $controller = $this->controller($service);

        $raw = $controller->info(new \WP_REST_Request(['migration_id' => 'mig_1', 'raw' => true]));
        $normal = $controller->info(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame(['rowCount' => 2], $raw->get_data());
        $this->assertSame(['row_count' => 2], $normal->get_data());
    }

    public function testInfoDoesNotReturnTokens(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->method('getMigrationInfoRaw')->willReturn(['t' => 'info', 'token' => 'x', 'stk' => 'y', 'mid' => 'mig_1']);
        $service->method('getMigrationInfo')->willReturn([
            'migrationId' => 'mig_1',
            'token' => 'x',
            'sourceToken' => 'y',
            'source_token' => 'z',
        ]);
        $controller = $this->controller($service);

        $raw = $controller->info(new \WP_REST_Request(['migration_id' => 'mig_1', 'raw' => true]));
        $normal = $controller->info(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame(['t' => 'info', 'mid' => 'mig_1'], $raw->get_data());
        $this->assertSame(['migration_id' => 'mig_1'], $normal->get_data());
    }

    public function testFinalizeAndTransferHtaccessReturn400ForAnUnsafeMigrationId(): void
    {
        $service = new MigrationService(
            $this->createMock(DatabaseScanner::class),
            $this->createMock(MigrationInfoGenerator::class),
            new MigrationStorageService()
        );
        $controller = $this->controller($service);
        $request = new \WP_REST_Request(['migration_id' => '../outside']);

        foreach ([$controller->finalize($request), $controller->transferHtaccess($request)] as $response) {
            $this->assertSame('invalid_id', $response->get_error_code());
            $this->assertSame(['status' => 400], $response->get_error_data());
        }
    }

    public function testInfoReturnsAnErrorForAnUnsafeMigrationId(): void
    {
        $service = new MigrationService(
            $this->createMock(DatabaseScanner::class),
            $this->createMock(MigrationInfoGenerator::class),
            new MigrationStorageService()
        );

        $response = $this->controller($service)->info(new \WP_REST_Request([
            'migration_id' => '../outside',
        ]));

        $this->assertSame('invalid_id', $response->get_error_code());
        $this->assertSame('Invalid migration ID', $response->get_error_message());
        $this->assertSame(['status' => 400], $response->get_error_data());
    }

    public function testMissingMigrationIdIsAClientErrorNotAFatalError(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->never())->method('getMigrationInfo');

        $response = $this->controller($service)->info(new \WP_REST_Request([]));

        $this->assertSame('Missing migration ID', $response->get_error_message());
        $this->assertSame(['status' => 400], $response->get_error_data());
    }

    public function testInfoReturns404WhenMigrationIsMissing(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->method('getMigrationInfo')->willReturn(null);

        $response = $this->controller($service)->info(new \WP_REST_Request(['migration_id' => 'missing']));

        $this->assertSame('not_found', $response->get_error_code());
        $this->assertSame(['status' => 404], $response->get_error_data());
    }

    public function testFinalizeDistinguishesIncompleteMigrationFromOtherFailure(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->method('finalizeMigration')->willThrowException(new Exception('Migration is not complete yet'));

        $response = $this->controller($service)->finalize(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame('not_complete', $response->get_error_code());
        $this->assertSame(['status' => 400], $response->get_error_data());
    }

    public function testFinalizeKeepsTheLockWhenTheDestinationDoesNotConfirm(): void
    {
        $dir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $dir);
        define('WP_CONTENT_DIR', rtrim($dir, '/'));
        $lock = new MigrationLock();
        $this->assertTrue($lock->acquire('mig_1'));
        $service = $this->createMock(MigrationService::class);
        $service->method('finalizeMigration')->willReturn(['success' => false]);

        $response = $this->controller($service)->finalize(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame('finalize_failed', $response->get_error_code());
        $this->assertSame('Destination did not confirm finalization', $response->get_error_message());
        $this->assertTrue($lock->isLocked());
    }

    public function testFinalizeKeepsTheLockWhileTheDestinationIsStillFinishing(): void
    {
        $dir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $dir);
        define('WP_CONTENT_DIR', rtrim($dir, '/'));
        $lock = new MigrationLock();
        $this->assertTrue($lock->acquire('mig_1'));
        $service = $this->createMock(MigrationService::class);
        $service->method('finalizeMigration')->willReturn(['success' => true, 'finalized' => false, 'phase' => 'finalizing']);

        $response = $this->controller($service)->finalize(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertFalse($data['finalized']);
        $this->assertSame('finalizing', $data['response']['phase']);
        $this->assertTrue($lock->isLocked());
    }

    public function testFinalizeCarriesTheSkippedFilesWarningAndStaysSuccessful(): void
    {
        $dir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $dir);
        define('WP_CONTENT_DIR', rtrim($dir, '/'));
        (new MigrationLock())->acquire('mig_1');
        $service = $this->createMock(MigrationService::class);
        $service->method('finalizeMigration')
            ->willReturn(['success' => true, 'skipped_files' => ['a.log'], 'warning' => 'Some files were skipped.']);

        $response = $this->controller($service)->finalize(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame(['a.log'], $data['skipped_files']);
        $this->assertSame('Some files were skipped.', $data['warning']);
    }

    public function testReceiveHtaccessRejectsMissingAndNonStringContent(): void
    {
        $htaccess = $this->createMock(HtaccessService::class);
        $htaccess->expects($this->never())->method('receive');
        $controller = $this->controller($this->createMock(MigrationService::class), $htaccess);

        $missing = $controller->receiveHtaccess(new \WP_REST_Request([], [], []));
        $invalid = $controller->receiveHtaccess(new \WP_REST_Request([], [], ['content' => ['rule']]));

        $this->assertSame('missing_content', $missing->get_error_code());
        $this->assertSame(['status' => 400], $missing->get_error_data());
        $this->assertSame('invalid_content', $invalid->get_error_code());
        $this->assertSame(['status' => 400], $invalid->get_error_data());
    }

    public function testReceiveHtaccessAcceptsEmptyStringAndReturnsByteCount(): void
    {
        $htaccess = $this->createMock(HtaccessService::class);
        $htaccess->expects($this->once())->method('receive')->with('')->willReturn(['bytes_written' => 0]);

        $response = $this->controller($this->createMock(MigrationService::class), $htaccess)
            ->receiveHtaccess(new \WP_REST_Request([], [], ['content' => '']));

        $this->assertSame([
            'success' => true,
            'saved' => true,
            'message' => '.htaccess saved successfully',
            'bytes_written' => 0,
        ], $response->get_data());
    }

    public function testTransferHtaccessReturns404BeforeCallingService(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        $htaccess = $this->createMock(HtaccessService::class);
        $htaccess->expects($this->never())->method('transfer');

        $response = $this->controller($this->createMock(MigrationService::class), $htaccess)
            ->transferHtaccess(new \WP_REST_Request(['migration_id' => 'missing']));

        $this->assertSame('not_found', $response->get_error_code());
        $this->assertSame(['status' => 404], $response->get_error_data());
    }

    public function testCompleteRejectsMissingIdAndReturns404ForUnknownMigration(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->once())->method('completeMigration')->with('missing')->willReturn(null);
        $controller = $this->controller($service);

        $missingId = $controller->complete(new \WP_REST_Request([], [], []));
        $unknown = $controller->complete(new \WP_REST_Request([], [], ['migration_id' => 'missing']));

        $this->assertSame('missing_id', $missingId->get_error_code());
        $this->assertSame(['status' => 400], $missingId->get_error_data());
        $this->assertSame('not_found', $unknown->get_error_code());
        $this->assertSame(['status' => 404], $unknown->get_error_data());
    }

    public function testCompleteRejectsUnsafeMigrationIdAsClientError(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        $service = new MigrationService(
            $this->createMock(DatabaseScanner::class),
            $this->createMock(MigrationInfoGenerator::class),
            new MigrationStorageService()
        );

        $response = $this->controller($service)->complete(new \WP_REST_Request([], [], [
            'migration_id' => '../outside',
        ]));

        $this->assertSame('invalid_id', $response->get_error_code());
        $this->assertSame('Invalid migration ID', $response->get_error_message());
        $this->assertSame(['status' => 400], $response->get_error_data());
        $this->assertDirectoryDoesNotExist(Config::get('migration_dir') . 'collected');
    }

    private ?MigrationService $lastService = null;

    private function assertUnselectedPhaseIsDisabledAndMigrationCanFinish(
        bool $backupDatabase,
        bool $backupFiles,
        string $disabledPhase
    ): void {
        $status = $this->scanPhaseSelection($backupDatabase, $backupFiles);
        $selectedPhase = $disabledPhase === 'database' ? 'files' : 'database';
        $storage = new MigrationStorageService();
        $stored = $storage->loadMigrationData($status['migration_id']);
        $disabledItems = $disabledPhase === 'database' ? $stored['tables'] : $stored['files'];

        $this->assertSame([], $disabledItems);
        $this->assertSame('disabled', $status[$disabledPhase]['status']);
        $this->assertNotSame('pending', $status[$disabledPhase]['status']);
        $this->assertSame('pending', $status[$selectedPhase]['status']);
        $this->assertTrue((new MigrationLock())->isLocked());

        $blocked = $this->controller($this->lastService)->finalize(new \WP_REST_Request([
            'migration_id' => $status['migration_id'],
        ]));
        $this->assertSame('not_complete', $blocked->get_error_code());
        $this->assertTrue((new MigrationLock())->isLocked());

        $this->markPhaseSent($storage, $status['migration_id'], $selectedPhase);

        $finished = $this->controller($this->lastService)->finalize(new \WP_REST_Request([
            'migration_id' => $status['migration_id'],
        ]));

        $this->assertSame(200, $finished->get_status());
        $this->assertTrue($finished->get_data()['success']);
        $this->assertFalse((new MigrationLock())->isLocked());
        $after = (new BackupService(null, $storage))->getBackupStatus($status['migration_id']);
        $this->assertSame('disabled', $after[$disabledPhase]['status']);
        $this->assertNotSame('pending', $after[$disabledPhase]['status']);
    }

    /**
     * @return array{migration_id: string, database: array, files: array}
     */
    private function scanPhaseSelection(?bool $backupDatabase, ?bool $backupFiles): array
    {
        $root = $this->createTemporaryDirectory();
        $migrationDir = $this->createTemporaryDirectory();
        $lockHome = $this->createTemporaryDirectory();
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', rtrim($lockHome, '/'));
        }
        Config::set('root_path', $root);
        Config::set('migration_dir', $migrationDir);
        Config::set('allowed_destinations', ['127.0.0.1']);
        self::writeFile($root . 'index.php', 'home');

        $destination = FakeDestination::get()->url();
        $table = new TableInfo('wp_posts', 10, 1, [new ChunkInfo(1, 0, 100)], 1);
        $databaseScanner = $this->createMock(DatabaseScanner::class);
        $databaseScanner->method('scan')->willReturn([$table]);
        $generator = $this->createMock(MigrationInfoGenerator::class);
        $generator->method('generate')->willReturnCallback(
            static function (array $files, array $tables, string $url): Migration {
                return new Migration('mig_scan', $url, '2026-10-09T12:00:00+00:00', '6.8', PHP_VERSION, '8.0',
                    'https://old.example.com', 'https://old.example.com', $files, $tables,
                    new MigrationSummary(count($files), 4, count($tables), 10, count($tables), 1, count($files), count($tables)));
            }
        );
        $this->lastService = new MigrationService($databaseScanner, $generator, new MigrationStorageService());

        $json = ['token' => 'secret'];
        if ($backupDatabase !== null) {
            $json['backup_database'] = $backupDatabase;
        }
        if ($backupFiles !== null) {
            $json['backup_files'] = $backupFiles;
        }

        $response = $this->controller($this->lastService)->scan(new \WP_REST_Request(
            ['destination_url' => $destination],
            [],
            $json
        ));

        $this->assertSame(200, $response->get_status());
        $migrationId = $response->get_data()['migration_id'];
        $this->assertFalse((new MigrationLock())->isLocked());
        (new BackupService(null, new MigrationStorageService()))->startBackup($migrationId);
        $status = (new BackupService(null, new MigrationStorageService()))->getBackupStatus($migrationId);
        $status['migration_id'] = $migrationId;

        return $status;
    }

    private function markPhaseSent(MigrationStorageService $storage, string $migrationId, string $phase): void
    {
        $data = $storage->loadMigrationData($migrationId);
        $key = $phase === 'database' ? 'tables' : 'files';
        foreach ($data[$key] as &$item) {
            $item['status'] = 'sent';
            foreach ($item['chunks'] as &$chunk) {
                $chunk['status'] = 'sent';
            }
        }
        unset($item, $chunk);
        $storage->saveMigrationData($migrationId, $data);
    }

    private function controller(MigrationService $service, ?HtaccessService $htaccess = null): MigrationController
    {
        return new MigrationController(
            $service,
            $htaccess ?? $this->createMock(HtaccessService::class),
            null,
            null,
            new DestinationGuard(static fn (string $host): array => ['8.8.8.8']),
            $this->handshake
        );
    }
}
