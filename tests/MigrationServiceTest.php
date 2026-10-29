<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Dto\ChunkInfo;
use PanelAlpha\Migrator\Dto\Migration;
use PanelAlpha\Migrator\Dto\MigrationSummary;
use PanelAlpha\Migrator\Dto\TableInfo;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\MigrationLock;
use PanelAlpha\Migrator\Services\Migration\MigrationInfoGenerator;
use PanelAlpha\Migrator\Services\Migration\MigrationService;
use PanelAlpha\Migrator\Services\Migration\MigrationStorageService;
use PanelAlpha\Migrator\Services\Scanner\DatabaseScanner;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class MigrationServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    public function testScanAppliesDefaultAndCustomExclusionsAndSavesMetadata(): void
    {
        $root = $this->createTemporaryDirectory();
        Config::set('root_path', $root);
        self::writeFile($root . 'index.php', 'home');
        self::writeFile($root . 'wp-config.php', 'secret');
        self::writeFile($root . 'cache/drop.txt', 'cache');
        self::writeFile($root . 'debug.log', 'log');

        $databaseScanner = $this->createMock(DatabaseScanner::class);
        $databaseScanner->expects(self::once())->method('scan')->willReturn([]);
        $generator = $this->createMock(MigrationInfoGenerator::class);
        $generator->expects(self::once())->method('generate')->willReturnCallback(
            static function (array $files, array $tables, string $url): Migration {
                self::assertSame(['index.php'], array_map(static fn ($file) => $file->path, $files));
                self::assertSame([], $tables);
                self::assertSame('https://new.example.com', $url);
                return new Migration('mig_test', $url, 'now', '6.8', PHP_VERSION, '8.0',
                    'https://old.example.com', 'https://old.example.com', $files, $tables,
                    new MigrationSummary(1, 4, 0, 0, 0, 1, 1, 0));
            }
        );
        $storage = $this->createMock(MigrationStorageService::class);
        $storage->expects(self::once())->method('saveMigrationData')->with('mig_test', self::callback(
            static fn (array $data): bool => $data['type'] === 'info'
                && $data['destinationUrl'] === 'https://new.example.com'
                && $data['files'][0]['path'] === 'index.php'
                && $data['token'] === 'destination-token'
                && $data['ip'] === '192.0.2.1'
                && $data['base64'] === true
        ));

        $lock = new MigrationLock($this->createTemporaryDirectory() . 'lock.json');
        $result = (new MigrationService($databaseScanner, $generator, $storage, $lock))
            ->scanAndPrepareMigration('https://new.example.com', "cache/\n*.log", 'destination-token', '192.0.2.1', true);

        self::assertSame('mig_test', $result['migrationId']);
        self::assertFalse($lock->isLocked());
        self::assertSame('destination-token', $result['migrationData']['token']);
    }

    public function testPinnedIpIsStoredInTheMigrationData(): void
    {
        $root = $this->createTemporaryDirectory();
        Config::set('root_path', $root);
        self::writeFile($root . 'index.php', 'home');

        $databaseScanner = $this->createMock(DatabaseScanner::class);
        $databaseScanner->method('scan')->willReturn([]);
        $generator = $this->createMock(MigrationInfoGenerator::class);
        $generator->method('generate')->willReturn(new Migration('mig_pin', 'https://new.example.com', 'now', '6.8', PHP_VERSION, '8.0',
            'https://old.example.com', 'https://old.example.com', [], [], new MigrationSummary(0, 0, 0, 0, 0, 0, 0, 0)));
        $storage = $this->createMock(MigrationStorageService::class);
        $storage->expects(self::once())->method('saveMigrationData')->with('mig_pin', self::callback(
            static fn (array $data): bool => $data['pinned_ip'] === '203.0.113.9'
        ));

        $lock = new MigrationLock($this->createTemporaryDirectory() . 'lock.json');
        (new MigrationService($databaseScanner, $generator, $storage, $lock))
            ->scanAndPrepareMigration('https://new.example.com', null, null, null, false, true, true, '203.0.113.9');
    }

    public static function insecureTlsStorageCases(): array
    {
        return [
            'manual ip and insecure_tls' => ['192.0.2.1', true, true],
            'insecure_tls without a manual ip' => [null, true, false],
            'manual ip only' => ['192.0.2.1', false, false],
        ];
    }

    /**
     * @dataProvider insecureTlsStorageCases
     */
    #[DataProvider('insecureTlsStorageCases')]
    public function testInsecureTlsIsStoredOnlyWithAManualIp(?string $ip, bool $insecureTls, bool $stored): void
    {
        $root = $this->createTemporaryDirectory();
        Config::set('root_path', $root);
        self::writeFile($root . 'index.php', 'home');

        $databaseScanner = $this->createMock(DatabaseScanner::class);
        $databaseScanner->method('scan')->willReturn([]);
        $generator = $this->createMock(MigrationInfoGenerator::class);
        $generator->method('generate')->willReturn(new Migration('mig_tls', 'https://new.example.com', 'now', '6.8', PHP_VERSION, '8.0',
            'https://old.example.com', 'https://old.example.com', [], [], new MigrationSummary(0, 0, 0, 0, 0, 0, 0, 0)));
        $storage = $this->createMock(MigrationStorageService::class);
        $storage->expects(self::once())->method('saveMigrationData')->with('mig_tls', self::callback(
            static fn (array $data): bool => array_key_exists('insecure_tls', $data) === $stored
                && (!$stored || $data['insecure_tls'] === true)
        ));

        $lock = new MigrationLock($this->createTemporaryDirectory() . 'lock.json');
        (new MigrationService($databaseScanner, $generator, $storage, $lock))
            ->scanAndPrepareMigration('https://new.example.com', null, null, $ip, false, true, true, null, $insecureTls);
    }

    public function testFilesOnlyDoesNotScanTablesAndRecordsTheDatabasePhaseAsDisabled(): void
    {
        $root = $this->createTemporaryDirectory();
        Config::set('root_path', $root);
        self::writeFile($root . 'index.php', 'home');

        $databaseScanner = $this->createMock(DatabaseScanner::class);
        $databaseScanner->expects(self::never())->method('scan');
        $generator = $this->createMock(MigrationInfoGenerator::class);
        $generator->expects(self::once())->method('generate')->willReturnCallback(
            static function (array $files, array $tables, string $url): Migration {
                self::assertSame(['index.php'], array_map(static fn ($file) => $file->path, $files));
                self::assertSame([], $tables);
                return new Migration('mig_files', $url, 'now', '6.8', PHP_VERSION, '8.0',
                    'https://old.example.com', 'https://old.example.com', $files, $tables,
                    new MigrationSummary(1, 4, 0, 0, 0, 1, 1, 0));
            }
        );
        $storage = $this->createMock(MigrationStorageService::class);
        $storage->expects(self::once())->method('saveMigrationData')->with('mig_files', self::callback(
            static fn (array $data): bool => $data['backup_database'] === false
                && $data['backup_files'] === true
                && $data['tables'] === []
                && $data['files'][0]['path'] === 'index.php'
                && $data['files'][0]['status'] === 'pending'
        ));

        $lock = new MigrationLock($this->createTemporaryDirectory() . 'lock.json');
        $result = (new MigrationService($databaseScanner, $generator, $storage, $lock))
            ->scanAndPrepareMigration('https://new.example.com', null, 'destination-token', null, false, false, true);

        self::assertFalse($result['migrationData']['backup_database']);
        self::assertTrue($result['migrationData']['backup_files']);
        self::assertSame([], $result['migrationData']['tables']);
        self::assertSame('pending', $result['migrationData']['files'][0]['status']);
    }

    public function testDatabaseOnlyDoesNotScanFilesAndRecordsTheFilesPhaseAsDisabled(): void
    {
        $root = $this->createTemporaryDirectory();
        Config::set('root_path', $root);
        self::writeFile($root . 'payload.txt', 'secret');

        $table = new TableInfo('wp_posts', 10, 1, [new ChunkInfo(1, 0, 100)], 1);
        $databaseScanner = $this->createMock(DatabaseScanner::class);
        $databaseScanner->expects(self::once())->method('scan')->willReturn([$table]);
        $generator = $this->createMock(MigrationInfoGenerator::class);
        $generator->expects(self::once())->method('generate')->willReturnCallback(
            static function (array $files, array $tables) use ($table): Migration {
                self::assertSame([], $files);
                self::assertSame([$table], $tables);
                return new Migration('mig_db', 'https://new.example.com', 'now', '6.8', PHP_VERSION, '8.0',
                    'https://old.example.com', 'https://old.example.com', $files, $tables,
                    new MigrationSummary(0, 0, 1, 10, 1, 1, 0, 1));
            }
        );
        $storage = $this->createMock(MigrationStorageService::class);
        $storage->expects(self::once())->method('saveMigrationData')->with('mig_db', self::callback(
            static fn (array $data): bool => $data['backup_database'] === true
                && $data['backup_files'] === false
                && $data['files'] === []
                && $data['tables'][0]['name'] === 'wp_posts'
                && $data['tables'][0]['status'] === 'pending'
        ));

        $lock = new MigrationLock($this->createTemporaryDirectory() . 'lock.json');
        $result = (new MigrationService($databaseScanner, $generator, $storage, $lock))
            ->scanAndPrepareMigration('https://new.example.com', null, null, null, false, true, false);

        self::assertTrue($result['migrationData']['backup_database']);
        self::assertFalse($result['migrationData']['backup_files']);
        self::assertSame([], $result['migrationData']['files']);
    }

    public function testDefaultScanStillPreparesFilesAndTables(): void
    {
        $root = $this->createTemporaryDirectory();
        Config::set('root_path', $root);
        self::writeFile($root . 'index.php', 'home');

        $table = new TableInfo('wp_options', 4, 1, [new ChunkInfo(1, 0, 100)], 1);
        $databaseScanner = $this->createMock(DatabaseScanner::class);
        $databaseScanner->expects(self::once())->method('scan')->willReturn([$table]);
        $generator = $this->createMock(MigrationInfoGenerator::class);
        $generator->expects(self::once())->method('generate')->willReturnCallback(
            static function (array $files, array $tables) use ($table): Migration {
                self::assertSame(['index.php'], array_map(static fn ($file) => $file->path, $files));
                self::assertSame([$table], $tables);
                return new Migration('mig_both', 'https://new.example.com', 'now', '6.8', PHP_VERSION, '8.0',
                    'https://old.example.com', 'https://old.example.com', $files, $tables,
                    new MigrationSummary(1, 4, 1, 4, 1, 2, 1, 1));
            }
        );
        $storage = $this->createMock(MigrationStorageService::class);
        $storage->expects(self::once())->method('saveMigrationData')->with('mig_both', self::callback(
            static fn (array $data): bool => $data['backup_database'] === true
                && $data['backup_files'] === true
                && $data['files'][0]['path'] === 'index.php'
                && $data['tables'][0]['name'] === 'wp_options'
        ));

        $lock = new MigrationLock($this->createTemporaryDirectory() . 'lock.json');
        (new MigrationService($databaseScanner, $generator, $storage, $lock))
            ->scanAndPrepareMigration('https://new.example.com');
    }

    public function testMissingMigrationReturnsNullAndDelegatesListAndPath(): void
    {
        $storage = $this->createMock(MigrationStorageService::class);
        $storage->expects(self::exactly(2))->method('migrationExists')->with('missing')->willReturn(false);
        $storage->expects(self::never())->method('loadMigrationData');
        $storage->expects(self::once())->method('listMigrations')->willReturn([['migration_id' => 'mig_1']]);
        $storage->expects(self::once())->method('getMigrationFilePath')->with('mig_1')->willReturn('/tmp/mig_1.json');
        $service = new MigrationService($this->createMock(DatabaseScanner::class),
            $this->createMock(MigrationInfoGenerator::class), $storage);

        self::assertNull($service->getMigrationInfo('missing'));
        self::assertNull($service->getMigrationInfoRaw('missing'));
        self::assertSame([['migration_id' => 'mig_1']], $service->listMigrations());
        self::assertSame('/tmp/mig_1.json', $service->getMigrationFilePath('mig_1'));
    }

    public function testDecodedAndRawMigrationInfoGetTheirRespectiveTypeKey(): void
    {
        $path = $this->createTemporaryDirectory() . 'migration.json';
        self::writeFile($path, '{"mi":"mig_1"}');
        $storage = $this->createMock(MigrationStorageService::class);
        $storage->method('migrationExists')->with('mig_1')->willReturn(true);
        $storage->method('loadMigrationData')->with('mig_1')->willReturn(['migrationId' => 'mig_1']);
        $storage->method('getMigrationFilePath')->with('mig_1')->willReturn($path);
        $service = new MigrationService($this->createMock(DatabaseScanner::class),
            $this->createMock(MigrationInfoGenerator::class), $storage);

        self::assertSame(['migrationId' => 'mig_1', 'type' => 'info'], $service->getMigrationInfo('mig_1'));
        self::assertSame(['mi' => 'mig_1', 't' => 'info'], $service->getMigrationInfoRaw('mig_1'));
    }

    public function testScanRefusesWhileAnotherMigrationHoldsTheLock(): void
    {
        $databaseScanner = $this->createMock(DatabaseScanner::class);
        $databaseScanner->expects(self::never())->method('scan');
        $generator = $this->createMock(MigrationInfoGenerator::class);
        $storage = $this->createMock(MigrationStorageService::class);
        $storage->expects(self::never())->method('saveMigrationData');
        $lock = new MigrationLock($this->createTemporaryDirectory() . 'lock.json');
        $lock->acquire('running_mig');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Another migration is in progress');

        (new MigrationService($databaseScanner, $generator, $storage, $lock))
            ->scanAndPrepareMigration('https://new.example.com', null, null, null, false, true, false);
    }
}
