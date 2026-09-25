<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Restore\FileRestoreHandler;
use WordpressMigrator\Services\Migration\MigrationRegistry;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class FileRestoreHandlerTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private string $abspath;

    private string $migrationDir;

    private MigrationRegistry&MockObject $registry;

    private FileRestoreHandler $handler;

    protected function setUp(): void
    {
        $this->abspath = $this->createTemporaryDirectory();
        $this->migrationDir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $this->migrationDir);
        $this->registry = $this->createMock(MigrationRegistry::class);
        $this->registry->method('getStatus')->willReturn(['migration_id' => 'mig_1']);
        $this->handler = new FileRestoreHandler($this->registry, $this->abspath);
    }

    public function testSingleChunkFileIsWrittenWithPermissionsAndDate(): void
    {
        $this->registry->expects($this->once())->method('markChunkRestored')->with('file_chunk', $this->anything());

        $result = $this->handler->restoreChunk($this->chunk('wp-content/uploads/a.txt', 'hello', 1, 1, '600', '2024-02-03T04:05:06+00:00'));

        $this->assertSame(['action' => 'restored_file_chunk', 'file' => 'wp-content/uploads/a.txt', 'chunk' => 1], $result);
        $path = $this->abspath . 'wp-content/uploads/a.txt';
        $this->assertSame('hello', file_get_contents($path));
        clearstatcache();
        $this->assertSame('600', substr(sprintf('%o', fileperms($path)), -3));
        $this->assertSame(strtotime('2024-02-03T04:05:06+00:00'), filemtime($path));
    }

    public function testLaterChunksAreAppended(): void
    {
        $this->handler->restoreChunk($this->chunk('big.bin', 'part1-', 1, 3));
        $this->handler->restoreChunk($this->chunk('big.bin', 'part2-', 2, 3));
        $this->handler->restoreChunk($this->chunk('big.bin', 'part3', 3, 3));

        $this->assertSame('part1-part2-part3', file_get_contents($this->abspath . 'big.bin'));
    }

    public function testFirstChunkReplacesAnExistingFile(): void
    {
        file_put_contents($this->abspath . 'index.php', 'old content that is longer');

        $this->handler->restoreChunk($this->chunk('index.php', 'new', 1, 1));

        $this->assertSame('new', file_get_contents($this->abspath . 'index.php'));
    }

    public function testEmptyFileIsCreated(): void
    {
        $this->handler->restoreChunk($this->chunk('empty.txt', '', 1, 1));

        $this->assertFileExists($this->abspath . 'empty.txt');
        $this->assertSame('', file_get_contents($this->abspath . 'empty.txt'));
    }

    public function testBinaryContentArrivesUnchanged(): void
    {
        $binary = random_bytes(2048);

        $this->handler->restoreChunk($this->chunk('image.png', $binary, 1, 1));

        $this->assertSame($binary, file_get_contents($this->abspath . 'image.png'));
    }

    public static function protectedPaths(): array
    {
        return [
            ['wp-config.php'],
            ['.htaccess'],
            ['wp-content/migrations/mig_1.json'],
            ['wp-content/plugins/wordpress-migrator/config.php'],
            ['./wp-config.php'],
            ['wp-content/../wp-config.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedPaths')]
    public function testDestinationConfigurationIsNeverOverwritten(string $path): void
    {
        file_put_contents($this->abspath . 'wp-config.php', 'destination config');
        $this->registry->expects($this->once())->method('markChunkRestored');

        $result = $this->handler->restoreChunk($this->chunk($path, 'source content', 1, 1));

        $this->assertTrue($result['skipped']);
        $this->assertSame('Critical file excluded from restore', $result['reason']);
        $this->assertFalse($result['can_retry']);
        $this->assertSame('destination config', file_get_contents($this->abspath . 'wp-config.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/wordpress-migrator/config.php');
        $this->assertStringContainsString('SKIPPED: ', file_get_contents($this->migrationDir . 'skipped_files_mig_1.log'));
    }

    public function testPathThatLeavesTheSiteIsRejectedBeforeAnyWrite(): void
    {
        $this->registry->expects($this->never())->method('markChunkRestored');

        try {
            $this->handler->restoreChunk($this->chunk('../outside.txt', 'x', 1, 1));
            $this->fail('Expected the path to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('File path is outside the WordPress directory', $e->getMessage());
        }

        $this->assertFileDoesNotExist(dirname($this->abspath) . '/outside.txt');
    }

    public function testChecksumMismatchThrows(): void
    {
        $chunk = $this->chunk('a.txt', 'hello', 1, 1);
        $chunk['checksum'] = hash('sha256', 'other');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Checksum mismatch for file: a.txt');

        $this->handler->restoreChunk($chunk);
    }

    public function testContentThatIsNotBase64StillHasToMatchTheChecksum(): void
    {
        $chunk = $this->chunk('a.txt', 'hello', 1, 1);
        $chunk['content'] = '***';

        $this->expectExceptionMessage('Checksum mismatch for file: a.txt');

        $this->handler->restoreChunk($chunk);
    }

    public function testFolderThatCannotBeCreatedSkipsTheFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to read-only folders');
        }

        mkdir($this->abspath . 'locked', 0555);

        $result = $this->handler->restoreChunk($this->chunk('locked/sub/a.txt', 'x', 1, 1));

        $this->assertTrue($result['skipped']);
        $this->assertFalse($result['can_retry']);
        $this->assertStringStartsWith('Failed to create directory: ', $result['reason']);
        $this->assertStringContainsString('locked/sub/a.txt', file_get_contents($this->migrationDir . 'skipped_files_mig_1.log'));
    }

    public function testFileWhereAFolderShouldBeFailsTheWrite(): void
    {
        file_put_contents($this->abspath . 'blocker', 'a file where a folder should be');

        $result = $this->handler->restoreChunk($this->chunk('blocker/a.txt', 'x', 1, 1));

        $this->assertTrue($result['skipped']);
        $this->assertTrue($result['can_retry']);
        $this->assertSame('a file where a folder should be', file_get_contents($this->abspath . 'blocker'));
    }

    public function testReadOnlyFolderSkipsTheFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to read-only folders');
        }

        mkdir($this->abspath . 'locked', 0555);

        $result = $this->handler->restoreChunk($this->chunk('locked/a.txt', 'x', 1, 1));

        $this->assertTrue($result['skipped']);
        $this->assertStringStartsWith('Directory not writable: ', $result['reason']);
    }

    public function testFileThatCannotBeWrittenCanBeRetried(): void
    {
        mkdir($this->abspath . 'a-folder.txt');

        $result = $this->handler->restoreChunk($this->chunk('a-folder.txt', 'x', 1, 1));

        $this->assertTrue($result['skipped']);
        $this->assertTrue($result['can_retry']);
        $this->assertSame('Failed to write file (disk full or permissions issue)', $result['reason']);
    }

    public function testMissingMigrationIdIsLoggedAsUnknown(): void
    {
        $registry = $this->createMock(MigrationRegistry::class);
        $registry->method('getStatus')->willReturn(null);
        $handler = new FileRestoreHandler($registry, $this->abspath);

        $handler->restoreChunk($this->chunk('wp-config.php', 'x', 1, 1));

        $this->assertFileExists($this->migrationDir . 'skipped_files_unknown.log');
    }

    private function chunk(
        string $path,
        string $content,
        int $chunkNumber,
        int $totalChunks,
        string $permissions = '644',
        ?string $modified = null
    ): array {
        return [
            'type' => 'file_chunk',
            'file' => [
                'path' => $path,
                'permissions' => $permissions,
                'modified' => $modified,
                'chunk_info' => ['chunk_number' => $chunkNumber, 'total_chunks' => $totalChunks],
            ],
            'content' => base64_encode($content),
            'checksum' => hash('sha256', $content),
        ];
    }
}
