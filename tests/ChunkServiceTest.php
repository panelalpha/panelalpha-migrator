<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Services\Backup\ChunkService;
use WordpressMigrator\Services\Migration\MigrationRegistry;
use WordpressMigrator\Tests\Support\FakeDatabase;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class ChunkServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private string $migrationDir;

    private ChunkService $service;

    protected function setUp(): void
    {
        $this->migrationDir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $this->migrationDir);
        FakeDatabase::install();
        $this->service = new ChunkService();
    }

    public function testChunkDataNeedsAType(): void
    {
        $this->assertTrue($this->service->isValidChunkData(['type' => 'info']));
        $this->assertFalse($this->service->isValidChunkData([]));
        $this->assertFalse($this->service->isValidChunkData(['migration_id' => 'mig_1']));
    }

    public static function chunkFileNames(): array
    {
        return [
            'info' => [['type' => 'info'], 'migration_info.json'],
            'structure' => [['type' => 'table_structure', 'table' => ['name' => 'wp_posts']], 'structure_wp_posts.json'],
            'structure without name' => [['type' => 'table_structure'], 'structure_unknown.json'],
            'table data' => [['type' => 'table_data', 'chunk_id' => 'table_chunk_00001'], 'table_chunk_00001.json'],
            'file chunk' => [['type' => 'file_chunk', 'chunk_id' => 'file_chunk_00002'], 'file_chunk_00002.json'],
            'file chunk without id' => [['type' => 'file_chunk'], 'unknown.json'],
            'completion' => [['type' => 'migration_complete'], 'migration_complete.json'],
            'other type' => [['type' => 'custom', 'chunk_id' => 'x1'], 'custom_x1.json'],
        ];
    }

    #[DataProvider('chunkFileNames')]
    public function testChunkIsSavedCompressedUnderItsFileName(array $chunk, string $expectedName): void
    {
        $name = $this->service->saveChunk('mig_1', $chunk);

        $this->assertSame($expectedName, $name);
        $path = $this->migrationDir . 'collected/mig_1/' . $name;
        $this->assertFileExists($path);
        $this->assertSame($chunk, JsonCompressor::decompressFromJson(file_get_contents($path)));
    }

    public function testInfoChunkRegistersTheMigration(): void
    {
        $this->service->saveChunk('mig_1', ['type' => 'info']);

        $this->service->registerChunk('mig_1', [
            'type' => 'info',
            'files' => [['path' => 'a.txt', 'total_chunks' => 1]],
        ]);

        $status = (new MigrationRegistry('mig_1'))->getStatus();
        $this->assertSame('collecting', $status['status']);
        $this->assertArrayHasKey('a.txt', $status['files']);
    }

    public function testOtherChunkIsMarkedReceived(): void
    {
        $this->service->saveChunk('mig_1', ['type' => 'info']);
        $this->service->registerChunk('mig_1', ['type' => 'info', 'files' => [['path' => 'a.txt', 'total_chunks' => 1]]]);

        $this->service->registerChunk('mig_1', ['type' => 'file_chunk', 'file' => ['path' => 'a.txt']]);

        $status = (new MigrationRegistry('mig_1'))->getStatus();
        $this->assertSame(1, $status['chunks_received']);
        $this->assertSame('received', $status['files']['a.txt']['status']);
    }
}
