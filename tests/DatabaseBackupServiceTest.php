<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Dto\IndexInfo;
use PanelAlpha\Migrator\Dto\TableChunk;
use PanelAlpha\Migrator\Dto\TableStructure;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\JsonCompressor;
use PanelAlpha\Migrator\Restore\TableRestoreHandler;
use PanelAlpha\Migrator\Services\Backup\DatabaseBackupService;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;
use PanelAlpha\Migrator\Services\Migration\MigrationStorageService;
use PanelAlpha\Migrator\Services\Scanner\DatabaseScanner;
use PanelAlpha\Migrator\Tests\Support\CurlOptionCapture;
use PanelAlpha\Migrator\Tests\Support\FakeDestination;
use PanelAlpha\Migrator\Tests\Support\RecordingWpdb;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class DatabaseBackupServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private MigrationStorageService $storage;
    private FakeDestination $destination;

    protected function setUp(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        Config::set('allowed_destinations', ['127.0.0.1']);
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
        $this->assertSame('mig_1_old_posts_00001', $data['chunk_id']);
        $this->assertSame('old_', $data['source_table_prefix']);
        $this->assertSame([['chunk_number' => 1, 'total_chunks' => 1, 'offset' => 0, 'limit' => 100, 'actual_rows' => 1]], [$data['table']['chunk_info']]);
        $this->assertSame('https://127.0.0.1:' . $this->destination->port() . '/page', $data['data'][0][1]);
        $this->assertSame(base64_encode($binary), $data['data'][0][2]);
        $this->assertSame([[0, 2]], $data['binary_map']);
        $this->assertSame(hash('sha256', json_encode($data['data'])), $data['checksum']);
        $this->assertSame($data, JsonCompressor::decompressFromJson($this->destination->requests()[1]['body']));
        $this->assertSame('sent', $this->storage->loadMigrationData('mig_1')['tables'][0]['status']);
        $this->assertNull($service->getNextChunk());

        $wpdb = new RecordingWpdb();
        $wpdb->base_prefix = 'new_';
        $registry = $this->createMock(MigrationRegistry::class);
        $registry->method('getTableMap')->willReturn([
            'old_posts' => ['destination' => 'new_posts', 'temp' => 'pmt_ab12cd_new_posts', 'backup' => 'pmb_ab12cd_new_posts'],
        ]);
        $registry->expects($this->exactly(2))->method('markChunkRestored');
        $handler = new TableRestoreHandler($wpdb, $registry);

        $this->assertSame('new_posts', $handler->restoreStructure($structure)['table']);
        $this->assertContains('DROP TABLE IF EXISTS `pmt_ab12cd_new_posts`', $wpdb->queries);
        $wpdb->sqlite('CREATE TABLE pmt_ab12cd_new_posts (id INTEGER PRIMARY KEY, url TEXT, bytes BLOB)');
        $this->assertSame('new_posts', $handler->restoreData($data)['table']);
        $this->assertEquals([1, $data['data'][0][1], $binary], $wpdb->rows('SELECT id, url, bytes FROM pmt_ab12cd_new_posts')[0]);
    }

    public function testHttpFailureLeavesStructureUnsentAndAllowsRetry(): void
    {
        $this->saveMigration();
        $service = new DatabaseBackupService('mig_1', $this->scanner([[1, 'text', null]]), $this->storage);
        $this->destination->respondWith('/v1/receive', 500, '{"error":"boom"}');

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
        $this->destination->respondWith('/v1/receive', 503);

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

    public function testMigrationWithoutPinnedIpGetsOneOnTheFirstChunkAndIsNotCheckedAgain(): void
    {
        $this->saveMigration();
        $service = new DatabaseBackupService('mig_1', $this->scanner([[1, 'text', null]]), $this->storage);

        $service->getNextChunk();

        $this->assertSame('127.0.0.1', $this->storage->loadMigrationData('mig_1')['pinned_ip']);

        // 127.0.0.1 would now be refused, so the second chunk proves the guard does not run again.
        Config::set('allowed_destinations', []);
        $service->getNextChunk();

        $this->assertCount(2, $this->destination->requests());
    }

    public function testStoredPinnedIpSkipsTheGuardAndIsUsedForTheConnection(): void
    {
        Config::set('allowed_destinations', []);
        $this->saveMigration(['pinned_ip' => '127.0.0.1', 'destinationUrl' => 'http://destination.invalid:' . $this->destination->port()]);
        $service = new DatabaseBackupService('mig_1', $this->scanner([[1, 'text', null]]), $this->storage);
        CurlOptionCapture::reset();

        $service->getNextChunk();

        $this->assertSame(
            ['destination.invalid:' . $this->destination->port() . ':127.0.0.1'],
            CurlOptionCapture::get(CURLOPT_RESOLVE)
        );
        $this->assertCount(1, $this->destination->requests());
    }

    public static function tlsScenarios(): array
    {
        return [
            'manual ip and insecure_tls' => [['ip' => '127.0.0.1', 'insecure_tls' => true], false],
            'insecure_tls without a manual ip' => [['insecure_tls' => true], true],
            'manual ip, insecure_tls off' => [['ip' => '127.0.0.1', 'insecure_tls' => false], true],
            'manual ip, insecure_tls as the string false' => [['ip' => '127.0.0.1', 'insecure_tls' => 'false'], true],
            'manual ip, insecure_tls missing' => [['ip' => '127.0.0.1'], true],
            'default' => [[], true],
        ];
    }

    /**
     * @dataProvider tlsScenarios
     */
    #[DataProvider('tlsScenarios')]
    public function testChunkSkipsCertificateChecksOnlyForAManualIpWithInsecureTls(array $tlsData, bool $strict): void
    {
        $this->saveMigration($tlsData);
        $service = new DatabaseBackupService('mig_1', $this->scanner([[1, 'text', null]]), $this->storage);
        CurlOptionCapture::reset();

        $service->getNextChunk();

        $this->assertSame($strict, CurlOptionCapture::peerVerificationEnabled());
        $this->assertSame($strict, CurlOptionCapture::hostVerificationEnabled());
    }

    private function saveMigration(array $overrides = []): void
    {
        $this->storage->saveMigrationData('mig_1', $overrides + [
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
