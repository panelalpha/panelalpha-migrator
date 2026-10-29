<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\JsonCompressor;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class MigrationRegistryTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private string $migrationDir;

    private MigrationRegistry $registry;

    protected function setUp(): void
    {
        $this->migrationDir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $this->migrationDir);
        $this->registry = new MigrationRegistry('mig_1');
    }

    public function testUnknownMigrationHasNoStatus(): void
    {
        $this->assertNull($this->registry->getStatus());
        $this->assertNull($this->registry->getMigrationInfo());
        $this->assertSame(['status' => 'not_found', 'percentage' => 0], $this->registry->getRestoreProgress());
        $this->assertFalse($this->registry->markChunkReceived('file_chunk', $this->fileChunk('a.txt')));
        $this->assertFalse($this->registry->markChunkRestored('file_chunk', $this->fileChunk('a.txt')));
        $this->assertFalse($this->registry->updateStatus('restoring'));
        $this->assertFalse($this->registry->addError('x'));
    }

    public function testOnlySkippedPathsFromTheManifestAreKept(): void
    {
        $status = ['files' => ['a.txt' => [], 'b.log' => []]];

        $this->assertSame(['b.log'], $this->registry->knownSkippedFiles($status, ['b.log', 'b.log', 'nope.txt', '../etc/passwd']));
        $this->assertSame([], $this->registry->knownSkippedFiles($status, 'b.log'));
        $this->assertSame([], $this->registry->knownSkippedFiles($status, ['a' => ['b.log'], 123, null]));
    }

    public function testRegisterCreatesCollectingStatusForEveryFileAndTable(): void
    {
        $this->assertTrue($this->registerTwoFilesAndOneTable());

        $status = $this->registry->getStatus();
        $this->assertFileExists($this->migrationDir . 'collected/mig_1/status.json');
        $this->assertSame('mig_1', $status['migration_id']);
        $this->assertSame('collecting', $status['status']);
        $this->assertSame(5, $status['chunks_total']);
        $this->assertSame(0, $status['chunks_received']);
        $this->assertSame(['a.txt', 'big.bin'], array_keys($status['files']));
        $this->assertSame('pending', $status['files']['big.bin']['status']);
        $this->assertSame(2, $status['files']['big.bin']['total_chunks']);
        $this->assertFalse($status['tables']['wp_posts']['structure_received']);
    }

    public function testCamelCaseEstimatedChunksIsAccepted(): void
    {
        $this->registry->registerMigration(['summary' => ['estimatedChunks' => 7]]);

        $this->assertSame(7, $this->registry->getStatus()['chunks_total']);
    }

    public function testStatusFileIsStoredCompressed(): void
    {
        $this->registerTwoFilesAndOneTable();

        $raw = json_decode(file_get_contents($this->migrationDir . 'collected/mig_1/status.json'), true);

        $this->assertTrue(JsonCompressor::isCompressed($raw));
        $this->assertSame('collecting', $raw['st']);
    }

    public function testReceivedFileChunksMoveTheFileFromReceivingToReceived(): void
    {
        $this->registerTwoFilesAndOneTable();

        $this->registry->markChunkReceived('file_chunk', $this->fileChunk('big.bin'));
        $this->assertSame('receiving', $this->registry->getStatus()['files']['big.bin']['status']);

        $this->registry->markChunkReceived('file_chunk', $this->fileChunk('big.bin'));
        $status = $this->registry->getStatus();
        $this->assertSame('received', $status['files']['big.bin']['status']);
        $this->assertSame(2, $status['chunks_received']);
    }

    public function testRestoredFileChunksCountTheFileOnceItIsComplete(): void
    {
        $this->registerTwoFilesAndOneTable();

        $this->registry->markChunkRestored('file_chunk', $this->fileChunk('big.bin'));
        $status = $this->registry->getStatus();
        $this->assertSame('restoring', $status['files']['big.bin']['status']);
        $this->assertSame(0, $status['files_restored']);

        $this->registry->markChunkRestored('file_chunk', $this->fileChunk('big.bin'));
        $status = $this->registry->getStatus();
        $this->assertSame('restored', $status['files']['big.bin']['status']);
        $this->assertSame(1, $status['files_restored']);
    }

    public function testTableStructureAndDataAreTracked(): void
    {
        $this->registerTwoFilesAndOneTable();
        $table = ['table' => ['name' => 'wp_posts']];

        $this->registry->markChunkReceived('table_structure', $table);
        $this->registry->markChunkRestored('table_structure', $table);
        $this->registry->markChunkReceived('table_data', $table);
        $this->registry->markChunkRestored('table_data', $table);
        $status = $this->registry->getStatus()['tables']['wp_posts'];
        $this->assertTrue($status['structure_received']);
        $this->assertTrue($status['structure_restored']);
        $this->assertSame('restoring', $status['status']);

        $this->registry->markChunkReceived('table_data', $table);
        $this->assertSame('received', $this->registry->getStatus()['tables']['wp_posts']['status']);
        $this->registry->markChunkRestored('table_data', $table);
        $status = $this->registry->getStatus();
        $this->assertSame('restored', $status['tables']['wp_posts']['status']);
        $this->assertSame(1, $status['tables_restored']);
    }

    public function testChunksForUnknownItemsOnlyCountAsReceived(): void
    {
        $this->registerTwoFilesAndOneTable();
        $before = $this->registry->getStatus();

        $this->registry->markChunkReceived('file_chunk', $this->fileChunk('unknown.txt'));
        $this->registry->markChunkReceived('table_data', ['table' => ['name' => 'wp_unknown']]);
        $this->registry->markChunkReceived('table_structure', ['table' => ['name' => 'wp_unknown']]);
        $this->registry->markChunkRestored('file_chunk', $this->fileChunk('unknown.txt'));
        $this->registry->markChunkRestored('table_data', ['table' => ['name' => 'wp_unknown']]);
        $this->registry->markChunkRestored('table_structure', ['table' => ['name' => 'wp_unknown']]);
        $this->registry->markChunkReceived('other', []);

        $after = $this->registry->getStatus();
        $this->assertSame($before['files'], $after['files']);
        $this->assertSame($before['tables'], $after['tables']);
        $this->assertSame(4, $after['chunks_received']);
    }

    public function testStatusTimestampsFollowTheRestore(): void
    {
        $this->registerTwoFilesAndOneTable();

        $this->registry->updateStatus('restoring');
        $started = $this->registry->getStatus()['restore_started_at'];
        $this->assertNotNull($started);
        $this->assertNull($this->registry->getStatus()['restore_completed_at']);

        $this->registry->updateStatus('restored');
        $status = $this->registry->getStatus();
        $this->assertSame('restored', $status['status']);
        $this->assertSame($started, $status['restore_started_at']);
        $this->assertNotNull($status['restore_completed_at']);
    }

    public function testErrorsAreAppended(): void
    {
        $this->registerTwoFilesAndOneTable();

        $this->registry->addError('first');
        $this->registry->addError('second');

        $errors = $this->registry->getStatus()['errors'];
        $this->assertSame(['first', 'second'], array_column($errors, 'message'));
    }

    public function testNextChunkToRestoreFollowsStructureThenOrderedChunks(): void
    {
        $this->assertNull($this->registry->getNextChunkToRestore('file_chunk'));

        $this->registerTwoFilesAndOneTable();
        $directory = $this->migrationDir . 'collected/mig_1/';
        $this->writeChunk($directory . 'late.json', [
            'type' => 'file_chunk',
            'file' => ['path' => 'big.bin', 'chunk_info' => ['chunk_number' => 2]],
        ]);
        $this->writeChunk($directory . 'rows.json', [
            'type' => 'table_data',
            'table' => ['name' => 'wp_posts', 'chunk_info' => ['chunkNumber' => 1]],
        ]);
        $this->writeChunk($directory . 'structure_wp_posts.json', [
            'type' => 'table_structure',
            'table' => ['name' => 'wp_posts'],
        ]);

        $this->assertNull($this->registry->getNextChunkToRestore('file_chunk'));
        $this->assertSame('table_structure', $this->registry->getNextChunkToRestore(['table_structure', 'table_data'])['type']);

        $this->writeChunk($directory . 'first.json', [
            'type' => 'file_chunk',
            'file' => ['path' => 'big.bin', 'chunk_info' => ['chunk_number' => 1]],
        ]);
        $this->assertSame(1, $this->registry->getNextChunkToRestore('file_chunk')['file']['chunk_info']['chunk_number']);

        $this->registry->markChunkRestored('table_structure', ['table' => ['name' => 'wp_posts']]);
        $next = $this->registry->getNextChunkToRestore(['table_structure', 'table_data']);
        $this->assertSame('table_data', $next['type']);
        $this->assertSame(1, $next['table']['chunk_info']['chunkNumber']);

        $this->registry->markChunkRestored('file_chunk', ['file' => ['path' => 'big.bin']]);
        $this->assertSame(2, $this->registry->getNextChunkToRestore('file_chunk')['file']['chunk_info']['chunk_number']);
    }

    public function testRestoreProgressCountsRestoredFilesAndTables(): void
    {
        $this->registerTwoFilesAndOneTable();
        $this->registry->markChunkRestored('file_chunk', $this->fileChunk('a.txt'));

        $progress = $this->registry->getRestoreProgress();

        $this->assertSame('collecting', $progress['status']);
        $this->assertSame(2, $progress['total_files']);
        $this->assertSame(1, $progress['restored_files']);
        $this->assertSame(1, $progress['total_tables']);
        $this->assertSame(0, $progress['restored_tables']);
        $this->assertEquals(33.33, $progress['percentage']);
        $this->assertSame([], $progress['errors']);
    }

    public function testEmptyMigrationHasZeroProgress(): void
    {
        $this->registry->registerMigration([]);

        $this->assertEquals(0, $this->registry->getRestoreProgress()['percentage']);
    }

    public function testMigrationInfoIsReadFromTheSourceMigrationFile(): void
    {
        file_put_contents($this->migrationDir . 'mig_1.json', JsonCompressor::compressToJson(['migration_id' => 'mig_1']));

        $this->assertSame(['migration_id' => 'mig_1'], $this->registry->getMigrationInfo());
    }

    public function testSourceUrlAndTokenComeFromMigrationInfo(): void
    {
        $this->assertNull($this->registry->getSourceUrl());
        $this->assertNull($this->registry->getSourceToken());
        $this->assertNull($this->registry->getSourceUrlWithToken());

        $this->writeMigrationInfo(['site_url' => 'https://old.example.com', 'source_token' => 'a b']);

        $this->assertSame('https://old.example.com', $this->registry->getSourceUrl());
        $this->assertSame('a b', $this->registry->getSourceToken());
        $this->assertSame('https://old.example.com?token=a+b', $this->registry->getSourceUrlWithToken());
    }

    public function testSourceUrlWithAQueryGetsAnAmpersand(): void
    {
        $this->writeMigrationInfo(['site_url' => 'https://old.example.com/?lang=pl', 'sourceToken' => 'abc']);

        $this->assertSame('https://old.example.com/?lang=pl&token=abc', $this->registry->getSourceUrlWithToken());
    }

    public function testSourceUrlWithoutTokenIsReturnedAlone(): void
    {
        $this->writeMigrationInfo(['site_url' => 'https://old.example.com']);

        $this->assertSame('https://old.example.com', $this->registry->getSourceUrlWithToken());
    }

    public static function unsafeMigrationIds(): array
    {
        return [
            'slash' => ['../outside'],
            'backslash' => ['..\\outside'],
            'dotdot' => ['..'],
            'empty' => [''],
            'absolute' => ['/etc/passwd'],
        ];
    }

    /**
     * @dataProvider unsafeMigrationIds
     */
    #[DataProvider('unsafeMigrationIds')]
    public function testUnsafeMigrationIdIsRejectedBeforeADirectoryIsBuilt(string $migrationId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid migration ID');

        new MigrationRegistry($migrationId);
    }

    public function testStatusIsReadOnceInsideTheLockAndFreshOutsideIt(): void
    {
        $this->registerTwoFilesAndOneTable();
        $statusFile = $this->migrationDir . 'collected/mig_1/status.json';

        $this->registry->withStatusLock(function () use ($statusFile) {
            $this->registry->getStatus();
            file_put_contents($statusFile, '{}');

            $this->assertSame('mig_1', $this->registry->getStatus()['migration_id']);
            $this->assertTrue($this->registry->markChunkRestored('file_chunk', ['file' => ['path' => 'a.txt', 'chunk_info' => ['chunk_number' => 1]]]));
            $this->assertSame(1, (new MigrationRegistry('mig_1'))->getStatus()['files']['a.txt']['restored_chunks']);
        });

        $this->assertSame(1, $this->registry->getStatus()['files']['a.txt']['restored_chunks']);
        file_put_contents($statusFile, JsonCompressor::compressToJson(['migration_id' => 'changed']));
        $this->assertSame('changed', $this->registry->getStatus()['migration_id']);
    }

    public function testFileChunkOffsetsComeFromTheSmallIndex(): void
    {
        $this->registry->registerMigration([
            'files' => [['path' => 'big.bin', 'total_chunks' => 2, 'chunks' => [['offset' => 0, 'size' => 10], ['offset' => 10, 'size' => 4]]]],
        ]);

        $this->assertFileExists($this->migrationDir . 'collected/mig_1/file_chunks.json');
        $this->assertSame(['offset' => 10, 'size' => 4, 'chunk_number' => 2], $this->registry->expectedFileChunk('big.bin', 2));
        $this->assertNull($this->registry->expectedFileChunk('big.bin', 3));
        $this->assertNull($this->registry->expectedFileChunk('other.bin', 1));
    }

    public function testFileChunkOffsetsFallBackToTheManifestWithoutAnIndex(): void
    {
        $this->writeMigrationInfo(['files' => [['path' => 'big.bin', 'chunks' => [['offset' => 0, 'size' => 10], ['offset' => 10, 'size' => 4]]]]]);

        $this->assertSame(['offset' => 10, 'size' => 4, 'chunk_number' => 2], $this->registry->expectedFileChunk('big.bin', 2));
    }

    public function testTableMapIsStoredWithTheHashAndReadBack(): void
    {
        $this->registerTwoFilesAndOneTable();
        $this->assertNull($this->registry->getTableHash());
        $this->assertSame([], $this->registry->getTableMap());

        $map = ['wp_posts' => ['destination' => 'wp_posts', 'temp' => 'pmt_ab12cd_wp_posts', 'backup' => 'pmb_ab12cd_wp_posts']];
        $this->assertTrue($this->registry->saveTableMap('ab12cd', $map));

        $fresh = new MigrationRegistry('mig_1');
        $this->assertSame('ab12cd', $fresh->getTableHash());
        $this->assertSame($map, $fresh->getTableMap());
        $this->assertSame(2, $fresh->getStatus()['tables']['wp_posts']['total_chunks']);
    }

    public function testRegisteringAgainKeepsTheTableHash(): void
    {
        $this->registerTwoFilesAndOneTable();
        $this->registry->saveTableMap('ab12cd', []);

        $this->registerTwoFilesAndOneTable();

        $this->assertSame('ab12cd', $this->registry->getTableHash());
        $this->assertSame([], $this->registry->getTableMap());
    }

    public function testTableMapIgnoresTablesThatAreNotInTheManifest(): void
    {
        $this->registerTwoFilesAndOneTable();

        $this->registry->saveTableMap('ab12cd', ['wp_other' => ['destination' => 'wp_other', 'temp' => 'pmt_ab12cd_wp_other', 'backup' => 'pmb_ab12cd_wp_other']]);

        $this->assertSame([], $this->registry->getTableMap());
    }

    public function testTableMapOfAnUnknownMigrationIsNotSaved(): void
    {
        $this->assertFalse($this->registry->saveTableMap('ab12cd', []));
        $this->assertNull($this->registry->getTableHash());
    }

    private function registerTwoFilesAndOneTable(): bool
    {
        return $this->registry->registerMigration([
            'summary' => ['estimated_chunks' => 5],
            'files' => [
                ['path' => 'a.txt', 'total_chunks' => 1],
                ['path' => 'big.bin', 'total_chunks' => 2],
            ],
            'tables' => [
                ['name' => 'wp_posts', 'total_chunks' => 2],
            ],
        ]);
    }

    private function fileChunk(string $path): array
    {
        return ['file' => ['path' => $path]];
    }

    private function writeMigrationInfo(array $info): void
    {
        $directory = $this->migrationDir . 'collected/mig_1/';
        @mkdir($directory, 0755, true);
        file_put_contents($directory . 'migration_info.json', JsonCompressor::compressToJson($info));
    }

    private function writeChunk(string $path, array $chunk): void
    {
        file_put_contents($path, JsonCompressor::compressToJson($chunk));
    }
}
