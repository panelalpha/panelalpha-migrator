<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Restore\FileRestoreHandler;
use WordpressMigrator\Services\Migration\MigrationRegistry;
use WordpressMigrator\Tests\Support\FakeDatabase;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class FileRestoreHandlerTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private string $abspath;

    private string $migrationDir;

    /** @var MigrationRegistry&MockObject */

    private MockObject $registry;

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

    /**
     * @dataProvider protectedPaths
     */
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

    public function testExcludedNamesAreComparedWithoutRegardToCase(): void
    {
        $result = $this->handler->restoreChunk($this->chunk('WP-Config.PHP', 'x', 1, 1));

        $this->assertTrue($result['skipped']);
        $this->assertFileDoesNotExist($this->abspath . 'WP-Config.PHP');
    }

    public function testPermissionsFromTheChunkAreMaskedToTheNineLowBits(): void
    {
        $this->handler->restoreChunk($this->chunk('script.sh', 'x', 1, 1, '4755'));

        clearstatcache();
        $this->assertSame('755', substr(sprintf('%o', fileperms($this->abspath . 'script.sh')), -3));
        $this->assertSame(0, fileperms($this->abspath . 'script.sh') & 04000);
    }

    public function testNullByteInThePathFailsOnlyThatChunk(): void
    {
        $result = $this->handler->restoreChunk($this->chunk("wp-content/uploads/a\0.php", 'x', 1, 1));

        $this->assertTrue($result['failed']);
        $this->assertTrue($result['can_retry']);
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

    public function testExistingSymlinkAsTargetFileIsNotWrittenThrough(): void
    {
        $outside = $this->createTemporaryDirectory();
        file_put_contents($outside . 'victim.txt', 'original');
        symlink($outside . 'victim.txt', $this->abspath . 'link.txt');

        $result = $this->handler->restoreChunk($this->chunk('link.txt', 'pwned', 1, 1));

        $this->assertTrue($result['failed']);
        $this->assertSame('original', file_get_contents($outside . 'victim.txt'));
    }

    public function testSymlinkedParentDirectoryLeadingOutsideIsNotWrittenThrough(): void
    {
        $outside = $this->createTemporaryDirectory();
        symlink(rtrim($outside, '/'), $this->abspath . 'uploads');

        $result = $this->handler->restoreChunk($this->chunk('uploads/new/a.txt', 'pwned', 1, 1));

        $this->assertTrue($result['failed']);
        $this->assertDirectoryDoesNotExist($outside . 'new');
        $this->assertFileDoesNotExist($outside . 'a.txt');
    }

    public function testSymlinkedParentDirectoryInsideTheSiteIsAllowed(): void
    {
        mkdir($this->abspath . 'real');
        symlink($this->abspath . 'real', $this->abspath . 'alias');

        $result = $this->handler->restoreChunk($this->chunk('alias/a.txt', 'ok', 1, 1));

        $this->assertSame('restored_file_chunk', $result['action']);
        $this->assertSame('ok', file_get_contents($this->abspath . 'real/a.txt'));
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

        $this->assertTrue($result['failed']);
        $this->assertSame('pending', $result['status']);
        $this->assertTrue($result['can_retry']);
        $this->assertStringStartsWith('Failed to create directory: ', $result['reason']);
        $this->assertStringContainsString('locked/sub/a.txt', file_get_contents($this->migrationDir . 'skipped_files_mig_1.log'));
    }

    public function testFileWhereAFolderShouldBeFailsTheWrite(): void
    {
        file_put_contents($this->abspath . 'blocker', 'a file where a folder should be');

        $result = $this->handler->restoreChunk($this->chunk('blocker/a.txt', 'x', 1, 1));

        $this->assertTrue($result['failed']);
        $this->assertSame('pending', $result['status']);
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

        $this->assertTrue($result['failed']);
        $this->assertSame('pending', $result['status']);
        $this->assertStringStartsWith('Directory not writable: ', $result['reason']);
    }

    public function testFailedWriteStaysPendingAndSucceedsAfterTheCauseIsFixed(): void
    {
        mkdir($this->abspath . 'a-folder.txt');
        $this->registry->expects($this->never())->method('markChunkRestored');

        $failed = $this->handler->restoreChunk($this->chunk('a-folder.txt', 'hello', 1, 1));

        $this->assertTrue($failed['failed']);
        $this->assertSame('pending', $failed['status']);
        $this->assertTrue($failed['can_retry']);
        $this->assertSame('Failed to write file (disk full or permissions issue)', $failed['reason']);
        $this->assertArrayNotHasKey('skipped', $failed);

        rmdir($this->abspath . 'a-folder.txt');
        $registry = $this->createMock(MigrationRegistry::class);
        $registry->method('getStatus')->willReturn(['migration_id' => 'mig_1']);
        $registry->expects($this->once())->method('markChunkRestored')->with('file_chunk', $this->anything());
        $handler = new FileRestoreHandler($registry, $this->abspath);

        $restored = $handler->restoreChunk($this->chunk('a-folder.txt', 'hello', 1, 1));

        $this->assertSame('restored_file_chunk', $restored['action']);
        $this->assertSame('hello', file_get_contents($this->abspath . 'a-folder.txt'));
    }

    public function testReplayingAChunkDoesNotDuplicateOrTruncateBytes(): void
    {
        FakeDatabase::install();
        $registry = new MigrationRegistry('mig_replay');
        $registry->registerMigration([
            'summary' => ['estimated_chunks' => 2],
            'files' => [['path' => 'big.bin', 'total_chunks' => 2]],
        ]);
        $this->writeManifest('mig_replay', 'big.bin', [
            ['offset' => 0, 'size' => 6],
            ['offset' => 6, 'size' => 4],
        ]);
        $handler = new FileRestoreHandler($registry, $this->abspath);

        $handler->restoreChunk($this->chunk('big.bin', '012345', 1, 2, '644', null, 0));
        $handler->restoreChunk($this->chunk('big.bin', '6789', 2, 2, '644', null, 6));
        $checksum = hash('sha256', '0123456789');

        $handler->restoreChunk($this->chunk('big.bin', '6789', 2, 2, '644', null, 6));
        $handler->restoreChunk($this->chunk('big.bin', '012345', 1, 2, '644', null, 0));

        $this->assertSame('0123456789', file_get_contents($this->abspath . 'big.bin'));
        $this->assertSame($checksum, hash('sha256', file_get_contents($this->abspath . 'big.bin')));
        $status = $registry->getStatus()['files']['big.bin'];
        $this->assertSame(2, $status['restored_chunks']);
    }

    public function testChunkOffsetSizeAndNumberMustMatchTheManifest(): void
    {
        FakeDatabase::install();
        $registry = new MigrationRegistry('mig_manifest');
        $registry->registerMigration([
            'files' => [['path' => 'big.bin', 'total_chunks' => 2]],
        ]);
        $this->writeManifest('mig_manifest', 'big.bin', [
            ['offset' => 0, 'size' => 4],
            ['offset' => 4, 'size' => 4],
        ]);
        $handler = new FileRestoreHandler($registry, $this->abspath);
        $handler->restoreChunk($this->chunk('big.bin', 'aaaa', 1, 2, '644', null, 0));

        try {
            $handler->restoreChunk($this->chunk('big.bin', 'bbbb', 2, 2, '644', null, 0));
            $this->fail('A chunk whose offset does not match the manifest must be rejected');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('does not match the manifest', $exception->getMessage());
        }

        $this->assertSame('aaaa', file_get_contents($this->abspath . 'big.bin'));
        $this->assertSame(1, $registry->getStatus()['files']['big.bin']['restored_chunks']);
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
        ?string $modified = null,
        ?int $offset = null
    ): array {
        $info = ['chunk_number' => $chunkNumber, 'total_chunks' => $totalChunks];
        if ($offset !== null) {
            $info['offset'] = $offset;
            $info['size'] = strlen($content);
        }

        return [
            'type' => 'file_chunk',
            'file' => [
                'path' => $path,
                'permissions' => $permissions,
                'modified' => $modified,
                'chunk_info' => $info,
            ],
            'content' => base64_encode($content),
            'checksum' => hash('sha256', $content),
        ];
    }

    /**
     * @param array<int, array{offset: int, size: int}> $chunks
     */
    private function writeManifest(string $migrationId, string $path, array $chunks): void
    {
        $directory = $this->migrationDir . 'collected/' . $migrationId . '/';
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($directory . 'migration_info.json', json_encode([
            'files' => [[
                'path' => $path,
                'total_chunks' => count($chunks),
                'chunks' => $chunks,
            ]],
        ]));
    }

    public function testCoreFilesTheSourceDoesNotHaveAreDeleted(): void
    {
        foreach (['wp-includes/version.php', 'wp-includes/old.php', 'wp-includes/gone/a.js', 'wp-admin/kept.php',
            'wp-admin/old.php', 'wp-content/plugins/own/own.php', 'old-root.php'] as $path) {
            self::writeFile($this->abspath . $path, 'x');
        }

        $removed = $this->handler->removeStaleCoreFiles(['wp-includes/version.php', 'wp-admin/kept.php']);

        $this->assertSame(3, $removed);
        $this->assertFileExists($this->abspath . 'wp-includes/version.php');
        $this->assertFileExists($this->abspath . 'wp-admin/kept.php');
        $this->assertFileDoesNotExist($this->abspath . 'wp-includes/old.php');
        $this->assertFileDoesNotExist($this->abspath . 'wp-admin/old.php');
        $this->assertDirectoryDoesNotExist($this->abspath . 'wp-includes/gone');
        $this->assertFileExists($this->abspath . 'wp-content/plugins/own/own.php');
        $this->assertFileExists($this->abspath . 'old-root.php');
    }

    public function testCoreIsLeftAloneWhenTheMigrationDidNotCarryIt(): void
    {
        self::writeFile($this->abspath . 'wp-includes/version.php', 'x');
        self::writeFile($this->abspath . 'wp-admin/index.php', 'x');

        $this->assertSame(0, $this->handler->removeStaleCoreFiles(['wp-content/uploads/a.jpg']));
        $this->assertFileExists($this->abspath . 'wp-includes/version.php');
        $this->assertFileExists($this->abspath . 'wp-admin/index.php');
    }

    public function testCoreCleanupDoesNotFollowSymlinks(): void
    {
        $outside = $this->createTemporaryDirectory();
        self::writeFile($outside . 'keep.php', 'x');
        self::writeFile($this->abspath . 'wp-includes/version.php', 'x');
        symlink(rtrim($outside, '/'), $this->abspath . 'wp-includes/linked');

        $this->handler->removeStaleCoreFiles(['wp-includes/version.php']);

        $this->assertFileExists($outside . 'keep.php');
    }
}
