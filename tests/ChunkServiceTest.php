<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\JsonCompressor;
use PanelAlpha\Migrator\Services\Backup\ChunkService;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

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
        $this->service = new ChunkService();
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

    /**
     * @dataProvider chunkFileNames
     */
    #[DataProvider('chunkFileNames')]
    public function testChunkIsSavedCompressedUnderItsFileName(array $chunk, string $expectedName): void
    {
        $name = $this->service->saveChunk('mig_1', $chunk);

        $this->assertSame($expectedName, $name);
        $path = $this->migrationDir . 'collected/mig_1/' . $name;
        $this->assertFileExists($path);
        $this->assertSame($chunk, JsonCompressor::decompressFromJson(file_get_contents($path)));
    }

    public static function unsafeChunks(): array
    {
        return [
            'file chunk' => [['type' => 'file_chunk', 'chunk_id' => '../other_mig/status']],
            'table data' => [['type' => 'table_data', 'chunk_id' => '../other_mig/status']],
            'other type' => [['type' => 'custom', 'chunk_id' => '../x']],
            'type itself' => [['type' => '../x', 'chunk_id' => 'a']],
        ];
    }

    /**
     * @dataProvider unsafeChunks
     */
    #[DataProvider('unsafeChunks')]
    public function testUnsafeChunkIdIsRejectedBeforeAnythingIsWritten(array $chunk): void
    {
        try {
            $this->service->saveChunk('mig_1', $chunk);
            $this->fail('Expected the chunk id to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid chunk ID', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->migrationDir . 'collected/other_mig/status.json');
    }

    public function testTwoTablesKeepTheirOwnChunkOne(): void
    {
        $posts = $this->tableChunk('wp_posts', 1, [[1, 'Hello']]);
        $users = $this->tableChunk('wp_users', 1, [[7, 'Ada']]);

        $postsFile = $this->service->saveChunk('mig_1', $posts);
        $usersFile = $this->service->saveChunk('mig_1', $users);

        $this->assertNotSame($postsFile, $usersFile);
        $directory = $this->migrationDir . 'collected/mig_1/';
        $this->assertSame($posts, JsonCompressor::decompressFromJson(file_get_contents($directory . $postsFile)));
        $this->assertSame($users, JsonCompressor::decompressFromJson(file_get_contents($directory . $usersFile)));
    }

    public function testTableNameCannotEscapeTheMigrationDirectory(): void
    {
        $chunk = $this->tableChunk('../wp_users', 1, [[1]]);

        $name = $this->service->saveChunk('mig_1', $chunk);

        $this->assertStringContainsString('wp_users', $name);
        $this->assertDoesNotMatchRegularExpression('/[\/\\\\]|\.\./', $name);
        $this->assertFileExists($this->migrationDir . 'collected/mig_1/' . $name);
        $this->assertFileDoesNotExist(dirname($this->migrationDir) . '/wp_users_00001.json');
    }

    public function testAnOldChunkFileIsLeftInPlaceWhenANewChunkIsSaved(): void
    {
        $directory = $this->migrationDir . 'collected/mig_1/';
        mkdir($directory, 0755, true);
        $old = $directory . 'table_chunk_00001.json';
        file_put_contents($old, JsonCompressor::compressToJson($this->tableChunk('wp_posts', 1, [[1, 'kept']])));

        $newFile = $this->service->saveChunk('mig_1', $this->tableChunk('wp_comments', 1, [[2, 'new']]));

        $this->assertFileExists($old);
        $this->assertNotSame('table_chunk_00001.json', $newFile);
        $this->assertFileExists($directory . $newFile);
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
    public function testUnsafeMigrationIdIsRejectedAndNotUsedAsADirectory(string $migrationId): void
    {
        try {
            $this->service->saveChunk($migrationId, ['type' => 'info']);
            $this->fail('An unsafe migration id must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid migration ID', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->migrationDir . 'outside');
    }

    private function tableChunk(string $table, int $number, array $rows): array
    {
        return [
            'type' => 'table_data',
            'migration_id' => 'mig_1',
            'chunk_id' => 'table_chunk_' . str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'table' => [
                'name' => $table,
                'chunk_info' => ['chunk_number' => $number, 'total_chunks' => 1],
            ],
            'columns' => ['ID', 'title'],
            'data' => $rows,
        ];
    }
}
