<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Dto\TableChunk;
use WordpressMigrator\Dto\TableStructure;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Services\Backup\DatabaseBackupService;
use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Tests\Support\FakeDestination;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DatabaseBackupSiteAddressTest extends TestCase
{
    use TemporaryDirectory;

    public function testSiteAddressSetInWpConfigReplacesTheOneStoredInTheDatabase(): void
    {
        // As on Bitnami: wp-config.php sets the address, the database keeps http://127.0.0.1.
        eval('namespace { function get_option($name, $default = false) {
            return ["siteurl" => "https://old.example.test", "home" => "https://old.example.test"][$name] ?? $default; } }');
        Config::set('migration_dir', $this->createTemporaryDirectory());
        Config::set('allowed_destinations', ['127.0.0.1']);
        $storage = new MigrationStorageService();
        $destination = FakeDestination::get();
        $storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'siteUrl' => 'https://old.example.test',
            'destinationUrl' => $destination->url(),
            'sourceTablePrefix' => 'old_',
            'token' => 'secret',
            'tables' => [[
                'name' => 'old_options', 'size' => 64, 'status' => 'pending',
                'structureSent' => true, 'totalChunks' => 1,
                'chunks' => [['offset' => 0, 'limit' => 100, 'status' => 'pending']],
            ]],
        ]);
        $scanner = $this->createMock(DatabaseScanner::class);
        $scanner->method('getTableStructure')->willReturn(new TableStructure('old_options', 'CREATE TABLE `old_options` (`option_id` INTEGER)', []));
        $scanner->method('getTableChunk')->willReturn(new TableChunk(['option_id', 'option_name', 'option_value', 'autoload'], [
            [1, 'siteurl', 'http://127.0.0.1', 'yes'],
            [2, 'home', 'http://127.0.0.1', 'yes'],
            [3, 'blogname', 'http://127.0.0.1', 'yes'],
        ], 3));
        $scanner->method('getChecksum')->willReturnCallback(static fn (array $data): string => hash('sha256', json_encode($data)));

        $data = (new DatabaseBackupService('mig_1', $scanner, $storage))->getNextChunk();

        // The domain replacement keeps the scheme of the source address.
        $expected = str_replace('http://', 'https://', rtrim($destination->url(), '/'));
        $this->assertSame($expected, $data['data'][0][2]);
        $this->assertSame($expected, $data['data'][1][2]);
        $this->assertSame('http://127.0.0.1', $data['data'][2][2]);
    }
}
