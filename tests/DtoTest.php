<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Dto\ChunkInfo;
use WordpressMigrator\Dto\FileChunkInfo;
use WordpressMigrator\Dto\FileInfo;
use WordpressMigrator\Dto\IndexInfo;
use WordpressMigrator\Dto\Migration;
use WordpressMigrator\Dto\MigrationSummary;
use WordpressMigrator\Dto\TableChunk;
use WordpressMigrator\Dto\TableInfo;
use WordpressMigrator\Dto\TableStructure;

final class DtoTest extends TestCase
{
    public function testChunksStartPending(): void
    {
        $this->assertSame('pending', (new ChunkInfo(1, 0, 100))->status);
        $this->assertSame('pending', (new FileChunkInfo(1, 0, 10))->status);
        $this->assertSame('pending', (new FileInfo('a.txt', 10, '644', '2026-01-01T00:00:00+00:00', [], 1))->status);
        $this->assertSame('pending', (new TableInfo('wp_posts', 10, 1, [], 1))->status);
        $this->assertFalse((new TableInfo('wp_posts', 10, 1, [], 1))->structureSent);
    }

    public function testStructureAndChunkKeepTheirValues(): void
    {
        $index = new IndexInfo('PRIMARY', ['ID'], 'PRIMARY');
        $structure = new TableStructure('wp_posts', 'CREATE TABLE `wp_posts` (...)', [$index]);
        $chunk = new TableChunk(['ID'], [[1], [2]], 2);

        $this->assertSame([$index], $structure->indexes);
        $this->assertSame('CREATE TABLE `wp_posts` (...)', $structure->createStatement);
        $this->assertSame(2, $chunk->actualRows);
        $this->assertSame([[1], [2]], $chunk->data);
    }

    public function testMigrationEncodesToTheKeysOfTheMigrationFile(): void
    {
        $file = new FileInfo('index.php', 5, '644', '2026-01-01T00:00:00+00:00', [new FileChunkInfo(1, 0, 5)], 1);
        $table = new TableInfo('wp_posts', 100, 3, [new ChunkInfo(1, 0, 100)], 1);
        $summary = new MigrationSummary(1, 5, 1, 100, 3, 2, 1, 1);

        $migration = new Migration(
            'mig_20260925_120000',
            'https://new.example.com',
            '2026-09-25T12:00:00+00:00',
            '6.8',
            '8.3.0',
            '8.0.36',
            'https://old.example.com',
            'https://old.example.com',
            [$file],
            [$table],
            $summary,
            'source-token',
            'wpx_'
        );

        $data = json_decode(json_encode($migration), true);

        $this->assertSame('info', $data['type']);
        $this->assertSame('mig_20260925_120000', $data['migrationId']);
        $this->assertSame('https://new.example.com', $data['destinationUrl']);
        $this->assertSame('source-token', $data['sourceToken']);
        $this->assertSame('wpx_', $data['sourceTablePrefix']);
        $this->assertSame(2, $data['summary']['estimatedChunks']);
        $this->assertSame(
            ['path' => 'index.php', 'size' => 5, 'permissions' => '644', 'modified' => '2026-01-01T00:00:00+00:00',
                'chunks' => [['id' => 1, 'offset' => 0, 'size' => 5, 'status' => 'pending']], 'totalChunks' => 1, 'status' => 'pending'],
            $data['files'][0]
        );
        $this->assertSame(
            ['name' => 'wp_posts', 'size' => 100, 'rowCount' => 3, 'structureSent' => false,
                'chunks' => [['id' => 1, 'offset' => 0, 'limit' => 100, 'status' => 'pending']], 'totalChunks' => 1, 'status' => 'pending'],
            $data['tables'][0]
        );
    }

    public function testMigrationDefaultsToWpPrefixAndNoToken(): void
    {
        $migration = new Migration(
            'mig_1', 'https://new.example.com', 'now', 'unknown', '8.3', 'unknown',
            'https://old.example.com', 'https://old.example.com', [], [],
            new MigrationSummary(0, 0, 0, 0, 0, 0, 0, 0)
        );

        $this->assertNull($migration->sourceToken);
        $this->assertSame('wp_', $migration->sourceTablePrefix);
    }
}
