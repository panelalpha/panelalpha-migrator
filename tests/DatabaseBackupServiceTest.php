<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Dto\IndexInfo;
use WordpressMigrator\Dto\TableChunk;
use WordpressMigrator\Dto\TableStructure;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Restore\TableRestoreHandler;
use WordpressMigrator\Services\Backup\DatabaseBackupService;
use WordpressMigrator\Services\Migration\MigrationRegistry;
use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Tests\Support\FakeDatabase;
use WordpressMigrator\Tests\Support\FakeDestination;
use WordpressMigrator\Tests\Support\RecordingPdo;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class DatabaseBackupServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private MigrationStorageService $storage;
    private FakeDestination $destination;

    protected function setUp(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        $this->storage = new MigrationStorageService();
        $this->destination = FakeDestination::get();
    }

    public function testStructureAndBinaryDataUseTheWireFormatAcceptedByTheRestoreHandler(): void
    {
        $binary = "\xff\x00\xfe";
        $rows = [[1, 'https://old.example.test/page', $binary]];
        $scanner = $this->scanner($rows);
        $this->saveMigration();
        $service = new DatabaseBackupService('mig_1', $scanner, $this->storage);

        $structure = $service->getNextChunk();
        $this->assertSame('table_structure', $structure['type']);
        $this->assertSame('old_posts', $structure['table']['name']);
        $this->assertSame('old_', $structure['source_table_prefix']);
        $this->assertSame([['name' => 'PRIMARY', 'columns' => ['id'], 'type' => 'PRIMARY']], $structure['table']['indexes']);
        $this->assertSame('secret', $this->destination->requests()[0]['headers']['x-migrator-token']);
        $this->assertSame($structure['table'], JsonCompressor::decompressFromJson($this->destination->requests()[0]['body'])['table']);
        $this->assertSame('pending', $this->storage->loadMigrationData('mig_1')['tables'][0]['status']);
        $this->assertTrue($this->storage->loadMigrationData('mig_1')['tables'][0]['structureSent']);

        $data = $service->getNextChunk();
        $this->assertSame('table_data', $data['type']);
        $this->assertSame('table_chunk_00001', $data['chunk_id']);
        $this->assertSame('old_', $data['source_table_prefix']);
        $this->assertSame([['chunk_number' => 1, 'total_chunks' => 1, 'offset' => 0, 'limit' => 100, 'actual_rows' => 1]], [$data['table']['chunk_info']]);
        $this->assertSame('https://127.0.0.1:' . $this->destination->port() . '/page', $data['data'][0][1]);
        $this->assertSame(base64_encode($binary), $data['data'][0][2]);
        $this->assertSame([[0, 2]], $data['binary_map']);
        $this->assertSame(hash('sha256', json_encode($data['data'])), $data['checksum']);
        $this->assertSame($data, JsonCompressor::decompressFromJson($this->destination->requests()[1]['body']));
        $this->assertSame('sent', $this->storage->loadMigrationData('mig_1')['tables'][0]['status']);
        $this->assertNull($service->getNextChunk());

        $pdo = new RecordingPdo();
        $registry = $this->createMock(MigrationRegistry::class);
        $registry->expects($this->exactly(2))->method('markChunkRestored');
        $root = $this->createTemporaryDirectory();
        file_put_contents($root . 'wp-config.php', "<?php\ndefine('DB_NAME', 'db');\ndefine('DB_USER', 'user');\ndefine('DB_HOST', 'db.internal');\n\$table_prefix = 'new_';\n");
        Config::set('root_path', $root);
        $handler = new TableRestoreHandler(FakeDatabase::install($pdo), $registry);

        $this->assertSame('new_posts', $handler->restoreStructure($structure)['table']);
        $this->assertContains('DROP TABLE IF EXISTS `new_posts`', $pdo->statements);
        $pdo->sqlite('CREATE TABLE new_posts (id INTEGER PRIMARY KEY, url TEXT, bytes BLOB)');
        $this->assertSame('new_posts', $handler->restoreData($data)['table']);
        $this->assertSame([1, $data['data'][0][1], $binary], $pdo->query('SELECT id, url, bytes FROM new_posts')->fetch(\PDO::FETCH_NUM));
    }

    public function testHttpFailureLeavesStructureUnsentAndAllowsRetry(): void
    {
        $this->saveMigration();
        $service = new DatabaseBackupService('mig_1', $this->scanner([[1, 'text', null]]), $this->storage);
        $this->destination->respondWith('restore.php', 500, '{"error":"boom"}');

        try {
            $service->getNextChunk();
            $this->fail('Expected HTTP failure');
        } catch (Exception $e) {
            $this->assertStringContainsString('Destination returned error: 500', $e->getMessage());
        }

        $this->assertFalse($this->storage->loadMigrationData('mig_1')['tables'][0]['structureSent']);
        $this->destination->reset();
        $this->assertSame('table_structure', $service->getNextChunk()['type']);
    }

    public function testHttpFailureLeavesDataChunkPending(): void
    {
        $this->saveMigration();
        $service = new DatabaseBackupService('mig_1', $this->scanner([[1, 'text', null]]), $this->storage);
        $service->getNextChunk();
        $this->destination->respondWith('restore.php', 503);

        try {
            $service->getNextChunk();
            $this->fail('Expected HTTP failure');
        } catch (Exception $e) {
            $this->assertStringContainsString('Destination returned error: 503', $e->getMessage());
        }

        $saved = $this->storage->loadMigrationData('mig_1')['tables'][0];
        $this->assertTrue($saved['structureSent']);
        $this->assertSame('pending', $saved['chunks'][0]['status']);
        $this->assertSame('pending', $saved['status']);
    }

    public function testProgressCountsSentChunksAndSize(): void
    {
        $this->saveMigration();
        $service = new DatabaseBackupService('mig_1', $this->scanner([[1, 'text', null]]), $this->storage);
        $this->assertSame(0, $service->getProgress()['sent_chunks']);
        $service->getNextChunk();
        $this->assertSame(0, $service->getProgress()['sent_chunks']);
        $service->getNextChunk();
        $progress = $service->getProgress();
        $this->assertSame(1, $progress['sent_chunks']);
        $this->assertSame(1, $progress['completed_tables']);
        $this->assertSame(64, $progress['sent_size']);
        $this->assertTrue($progress['is_complete']);
    }

    private function saveMigration(): void
    {
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'siteUrl' => 'https://old.example.test',
            'destinationUrl' => $this->destination->url(),
            'sourceTablePrefix' => 'old_',
            'token' => 'secret',
            'tables' => [[
                'name' => 'old_posts', 'size' => 64, 'status' => 'pending',
                'structureSent' => false, 'totalChunks' => 1,
                'chunks' => [['offset' => 0, 'limit' => 100, 'status' => 'pending']],
            ]],
        ]);
    }

    private function scanner(array $rows): DatabaseScanner
    {
        $scanner = $this->createMock(DatabaseScanner::class);
        $scanner->method('getTableStructure')->willReturn(new TableStructure(
            'old_posts',
            'CREATE TABLE `old_posts` (`id` INTEGER PRIMARY KEY, `url` TEXT, `bytes` BLOB)',
            [new IndexInfo('PRIMARY', ['id'], 'PRIMARY')]
        ));
        $scanner->method('getTableChunk')->willReturn(new TableChunk(['id', 'url', 'bytes'], $rows, count($rows)));
        $scanner->method('getChecksum')->willReturnCallback(
            static fn (array $data): string => hash('sha256', json_encode($data))
        );

        return $scanner;
    }
}
