<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\JsonCompressor;
use PanelAlpha\Migrator\Services\Migration\MigrationStorageService;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class MigrationStorageServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private string $migrationDir;

    private MigrationStorageService $storage;

    protected function setUp(): void
    {
        $this->migrationDir = $this->createTemporaryDirectory() . 'migrations/';
        Config::set('migration_dir', $this->migrationDir);
        $this->storage = new MigrationStorageService();
    }

    public function testMigrationIsSavedCompressedAndLoadedBack(): void
    {
        $data = [
            'migrationId' => 'mig_1',
            'destination_url' => 'https://new.example.com/sub',
            'files' => [['path' => 'wp-content/a.jpg', 'status' => 'pending']],
        ];

        $this->assertFalse($this->storage->migrationExists('mig_1'));
        $this->storage->saveMigrationData('mig_1', $data);

        $this->assertTrue($this->storage->migrationExists('mig_1'));
        $this->assertSame($this->migrationDir . 'mig_1.json', $this->storage->getMigrationFilePath('mig_1'));
        $raw = file_get_contents($this->migrationDir . 'mig_1.json');
        $this->assertStringContainsString('"du":"https://new.example.com/sub"', $raw);
        $this->assertSame($data, $this->storage->loadMigrationData('mig_1'));
    }

    public function testSaveFailsWhenTheFileCannotBeWritten(): void
    {
        mkdir($this->migrationDir . 'mig_1.json', 0755, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to save migration data to: ' . $this->migrationDir . 'mig_1.json');

        $this->storage->saveMigrationData('mig_1', ['a' => 1]);
    }

    public function testNoCollectedFolderMeansNoMigrations(): void
    {
        $this->assertSame($this->migrationDir . 'collected/', $this->storage->getCollectedDirectory());
        $this->assertSame([], $this->storage->listMigrations());
    }

    public function testCollectedMigrationsAreListedNewestFirst(): void
    {
        $this->writeCollected('mig_old', [
            'timestamp' => '2026-01-01T00:00:00+00:00',
            'site_url' => 'https://old.example.com',
            'files' => [['path' => 'a'], ['path' => 'b']],
            'tables' => [['name' => 'wp_posts']],
        ]);
        $this->writeCollected('mig_new', ['timestamp' => '2026-09-01T00:00:00+00:00']);
        mkdir($this->migrationDir . 'collected/without-info');
        file_put_contents($this->migrationDir . 'collected/stray-file.json', '{}');

        $migrations = $this->storage->listMigrations();

        $this->assertSame(['mig_new', 'mig_old'], array_column($migrations, 'migration_id'));
        $this->assertSame([
            'migration_id' => 'mig_old',
            'timestamp' => '2026-01-01T00:00:00+00:00',
            'source_url' => 'https://old.example.com',
            'status' => 'collected',
            'files_total' => 2,
            'tables_total' => 1,
            'files_restored' => 0,
            'tables_restored' => 0,
        ], $migrations[1]);
        $this->assertSame('Unknown', $migrations[0]['source_url']);
    }

    public function testCompressedStatusIsListedWithItsRealProgress(): void
    {
        $this->writeCollected('mig_1', [
            'timestamp' => '2026-09-01T00:00:00+00:00',
            'site_url' => 'https://example.com',
            'files' => [['path' => 'a']],
            'tables' => [['name' => 'wp_posts']],
        ]);
        file_put_contents(
            $this->migrationDir . 'collected/mig_1/status.json',
            JsonCompressor::compressToJson([
                'status' => 'restoring',
                'files_restored' => 3,
                'tables_restored' => 2,
            ])
        );

        $migration = $this->storage->listMigrations()[0];

        $this->assertSame('restoring', $migration['status']);
        $this->assertSame(3, $migration['files_restored']);
        $this->assertSame(2, $migration['tables_restored']);
    }

    public function testPlainStatusJsonIsStillListed(): void
    {
        $this->writeCollected('mig_1', [
            'timestamp' => '2026-09-01T00:00:00+00:00',
            'site_url' => 'https://example.com',
        ]);
        file_put_contents(
            $this->migrationDir . 'collected/mig_1/status.json',
            json_encode([
                'status' => 'restored',
                'files_restored' => 1,
                'tables_restored' => 4,
            ])
        );

        $migration = $this->storage->listMigrations()[0];

        $this->assertSame('restored', $migration['status']);
        $this->assertSame(1, $migration['files_restored']);
        $this->assertSame(4, $migration['tables_restored']);
    }

    private function writeCollected(string $migrationId, array $info): void
    {
        $directory = $this->migrationDir . 'collected/' . $migrationId . '/';
        mkdir($directory, 0755, true);
        file_put_contents($directory . 'migration_info.json', JsonCompressor::compressToJson($info));
    }

    public function testSecretsAreKeptOutOfTheWebReadableManifest(): void
    {
        $data = [
            'migrationId' => 'mig_1',
            'token' => 'destination-secret',
            'sourceToken' => 'source-secret',
            'destination_url' => 'https://new.example.com',
        ];

        $this->storage->saveMigrationData('mig_1', $data);

        $manifest = file_get_contents($this->migrationDir . 'mig_1.json');
        $this->assertStringNotContainsString('destination-secret', $manifest);
        $this->assertStringNotContainsString('source-secret', $manifest);
        $secretFile = $this->migrationDir . 'mig_1.secret.php';
        $this->assertStringStartsWith("<?php exit; ?>\n", file_get_contents($secretFile));
        $this->assertStringNotContainsString('source-secret', file_get_contents($secretFile));
        $this->assertSame('', (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($secretFile)));

        $loaded = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('destination-secret', $loaded['token']);
        $this->assertArrayNotHasKey('sourceToken', $loaded);
        $this->assertSame('https://new.example.com', $loaded['destination_url']);
    }

    public function testOldManifestWithTheTokenInsideStillLoadsAndMovesOnSave(): void
    {
        mkdir($this->migrationDir, 0755, true);
        file_put_contents($this->migrationDir . 'mig_1.json', JsonCompressor::compressToJson([
            'token' => 'old-token',
            'sourceToken' => 'old-source-token',
            'destination_url' => 'https://new.example.com',
        ], JSON_UNESCAPED_SLASHES));

        $loaded = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('old-token', $loaded['token']);
        $this->assertArrayNotHasKey('sourceToken', $loaded);

        $this->storage->saveMigrationData('mig_1', $loaded);

        $this->assertStringNotContainsString('old-token', file_get_contents($this->migrationDir . 'mig_1.json'));
        $this->assertSame('old-token', $this->storage->loadMigrationData('mig_1')['token']);
    }
}
