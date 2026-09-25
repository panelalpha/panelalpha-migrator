<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Dto\Migration;
use WordpressMigrator\Dto\MigrationSummary;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Services\Migration\MigrationInfoGenerator;
use WordpressMigrator\Services\Migration\MigrationService;
use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

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

        $result = (new MigrationService($databaseScanner, $generator, $storage))
            ->scanAndPrepareMigration('https://new.example.com', "cache/\n*.log", 'destination-token', '192.0.2.1', true);

        self::assertSame('mig_test', $result['migrationId']);
        self::assertSame('destination-token', $result['migrationData']['token']);
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
}
