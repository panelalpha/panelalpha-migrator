<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Restore\FileRestoreHandler;
use PanelAlpha\Migrator\Restore\StagedFiles;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

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
        $this->handler->restoreChunk($this->chunk('wp-content/uploads/big.bin', 'part1-', 1, 3));
        $this->handler->restoreChunk($this->chunk('wp-content/uploads/big.bin', 'part2-', 2, 3));
        $this->handler->restoreChunk($this->chunk('wp-content/uploads/big.bin', 'part3', 3, 3));

        $this->assertSame('part1-part2-part3', file_get_contents($this->abspath . 'wp-content/uploads/big.bin'));
    }

    public function testFirstChunkReplacesAnExistingFile(): void
    {
        self::writeFile($this->abspath . 'wp-content/uploads/readme.txt', 'old content that is longer');

        $this->handler->restoreChunk($this->chunk('wp-content/uploads/readme.txt', 'new', 1, 1));

        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/uploads/readme.txt'));
    }

    public function testEmptyFileIsCreated(): void
    {
        $this->handler->restoreChunk($this->chunk('wp-content/uploads/empty.txt', '', 1, 1));

        $this->assertFileExists($this->abspath . 'wp-content/uploads/empty.txt');
        $this->assertSame('', file_get_contents($this->abspath . 'wp-content/uploads/empty.txt'));
    }

    public function testBinaryContentArrivesUnchanged(): void
    {
        $binary = random_bytes(2048);

        $this->handler->restoreChunk($this->chunk('wp-content/uploads/image.png', $binary, 1, 1));

        $this->assertSame($binary, file_get_contents($this->abspath . 'wp-content/uploads/image.png'));
    }

    public static function protectedPaths(): array
    {
        return [
            ['wp-config.php'],
            ['.htaccess'],
            ['wp-content/migrations/mig_1.json'],
            ['wp-content/plugins/panelalpha-migrator/config.php'],
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
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/panelalpha-migrator/config.php');
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
        $this->handler->restoreChunk($this->chunk('wp-content/uploads/script.sh', 'x', 1, 1, '4755'));

        clearstatcache();
        $this->assertSame('755', substr(sprintf('%o', fileperms($this->abspath . 'wp-content/uploads/script.sh')), -3));
        $this->assertSame(0, fileperms($this->abspath . 'wp-content/uploads/script.sh') & 04000);
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
        mkdir($this->abspath . 'wp-content/uploads', 0755, true);
        symlink($outside . 'victim.txt', $this->abspath . 'wp-content/uploads/link.txt');

        $result = $this->handler->restoreChunk($this->chunk('wp-content/uploads/link.txt', 'pwned', 1, 1));

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
        mkdir($this->abspath . 'wp-content/uploads/a-folder.txt', 0755, true);
        $this->registry->expects($this->never())->method('markChunkRestored');

        $failed = $this->handler->restoreChunk($this->chunk('wp-content/uploads/a-folder.txt', 'hello', 1, 1));

        $this->assertTrue($failed['failed']);
        $this->assertSame('pending', $failed['status']);
        $this->assertTrue($failed['can_retry']);
        $this->assertSame('Failed to write file (disk full or permissions issue)', $failed['reason']);
        $this->assertArrayNotHasKey('skipped', $failed);

        rmdir($this->abspath . 'wp-content/uploads/a-folder.txt');
        $registry = $this->createMock(MigrationRegistry::class);
        $registry->method('getStatus')->willReturn(['migration_id' => 'mig_1']);
        $registry->expects($this->once())->method('markChunkRestored')->with('file_chunk', $this->anything());
        $handler = new FileRestoreHandler($registry, $this->abspath);

        $restored = $handler->restoreChunk($this->chunk('wp-content/uploads/a-folder.txt', 'hello', 1, 1));

        $this->assertSame('restored_file_chunk', $restored['action']);
        $this->assertSame('hello', file_get_contents($this->abspath . 'wp-content/uploads/a-folder.txt'));
    }

    public function testReplayingAChunkDoesNotDuplicateOrTruncateBytes(): void
    {
        $registry = new MigrationRegistry('mig_replay');
        $registry->registerMigration([
            'summary' => ['estimated_chunks' => 2],
            'files' => [['path' => 'wp-content/uploads/big.bin', 'total_chunks' => 2]],
        ]);
        $this->writeManifest('mig_replay', 'wp-content/uploads/big.bin', [
            ['offset' => 0, 'size' => 6],
            ['offset' => 6, 'size' => 4],
        ]);
        $handler = new FileRestoreHandler($registry, $this->abspath);

        $handler->restoreChunk($this->chunk('wp-content/uploads/big.bin', '012345', 1, 2, '644', null, 0));
        $handler->restoreChunk($this->chunk('wp-content/uploads/big.bin', '6789', 2, 2, '644', null, 6));
        $checksum = hash('sha256', '0123456789');

        $handler->restoreChunk($this->chunk('wp-content/uploads/big.bin', '6789', 2, 2, '644', null, 6));
        $handler->restoreChunk($this->chunk('wp-content/uploads/big.bin', '012345', 1, 2, '644', null, 0));

        $this->assertSame('0123456789', file_get_contents($this->abspath . 'wp-content/uploads/big.bin'));
        $this->assertSame($checksum, hash('sha256', file_get_contents($this->abspath . 'wp-content/uploads/big.bin')));
        $status = $registry->getStatus()['files']['wp-content/uploads/big.bin'];
        $this->assertSame(2, $status['restored_chunks']);
    }

    public function testChunkOffsetSizeAndNumberMustMatchTheManifest(): void
    {
        $registry = new MigrationRegistry('mig_manifest');
        $registry->registerMigration([
            'files' => [['path' => 'wp-content/uploads/big.bin', 'total_chunks' => 2]],
        ]);
        $this->writeManifest('mig_manifest', 'wp-content/uploads/big.bin', [
            ['offset' => 0, 'size' => 4],
            ['offset' => 4, 'size' => 4],
        ]);
        $handler = new FileRestoreHandler($registry, $this->abspath);
        $handler->restoreChunk($this->chunk('wp-content/uploads/big.bin', 'aaaa', 1, 2, '644', null, 0));

        try {
            $handler->restoreChunk($this->chunk('wp-content/uploads/big.bin', 'bbbb', 2, 2, '644', null, 0));
            $this->fail('A chunk whose offset does not match the manifest must be rejected');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('does not match the manifest', $exception->getMessage());
        }

        $this->assertSame('aaaa', file_get_contents($this->abspath . 'wp-content/uploads/big.bin'));
        $this->assertSame(1, $registry->getStatus()['files']['wp-content/uploads/big.bin']['restored_chunks']);
    }

    public function testMissingMigrationIdIsLoggedAsUnknown(): void
    {
        $registry = $this->createMock(MigrationRegistry::class);
        $registry->method('getStatus')->willReturn(null);
        $handler = new FileRestoreHandler($registry, $this->abspath);

        $handler->restoreChunk($this->chunk('wp-config.php', 'x', 1, 1));

        $this->assertFileExists($this->migrationDir . 'skipped_files_unknown.log');
    }

    public function testCodeFilesGoToTheStagingFolderAndNotToTheLiveSite(): void
    {
        self::writeFile($this->abspath . 'wp-includes/version.php', 'live');

        $result = $this->handler->restoreChunk($this->chunk('wp-includes/version.php', 'staged', 1, 1));

        $this->assertSame('restored_file_chunk', $result['action']);
        $this->assertSame('live', file_get_contents($this->abspath . 'wp-includes/version.php'));
        $this->assertSame('staged', file_get_contents($this->stagedRoot() . 'wp-includes/version.php' . StagedFiles::SUFFIX));
    }

    public function testStagedFileGetsItsPermissionsAndDate(): void
    {
        $this->handler->restoreChunk($this->chunk('wp-content/plugins/a/a.php', '<?php', 1, 1, '640', '2024-02-03T04:05:06+00:00'));

        $path = $this->stagedRoot() . 'wp-content/plugins/a/a.php' . StagedFiles::SUFFIX;
        clearstatcache();
        $this->assertSame('640', substr(sprintf('%o', fileperms($path)), -3));
        $this->assertSame(strtotime('2024-02-03T04:05:06+00:00'), filemtime($path));
    }

    public function testStagedFileArrivesInChunks(): void
    {
        $this->handler->restoreChunk($this->chunk('wp-content/themes/t/functions.php', 'part1-', 1, 2));
        $this->handler->restoreChunk($this->chunk('wp-content/themes/t/functions.php', 'part2', 2, 2));

        $this->assertSame('part1-part2', file_get_contents($this->stagedRoot() . 'wp-content/themes/t/functions.php' . StagedFiles::SUFFIX));
    }

    public function testPhpFileInUploadsIsStagedAndImagesAreWrittenInPlace(): void
    {
        $this->handler->restoreChunk($this->chunk('wp-content/uploads/2024/index.php', '<?php', 1, 1));
        $this->handler->restoreChunk($this->chunk('wp-content/uploads/2024/a.jpg', 'jpg', 1, 1));

        $this->assertFileExists($this->stagedRoot() . 'wp-content/uploads/2024/index.php' . StagedFiles::SUFFIX);
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/uploads/2024/index.php');
        $this->assertSame('jpg', file_get_contents($this->abspath . 'wp-content/uploads/2024/a.jpg'));
        $this->assertFileDoesNotExist($this->stagedRoot() . 'wp-content/uploads/2024/a.jpg');
    }

    public function testStagingFolderIsProtectedFromListing(): void
    {
        $this->handler->restoreChunk($this->chunk('index.php', '<?php', 1, 1));

        $this->assertFileExists($this->migrationDir . 'mig_1/index.php');
        $this->assertFileExists($this->migrationDir . 'mig_1/.htaccess');
        $this->assertSame('<?php', file_get_contents($this->stagedRoot() . 'index.php' . StagedFiles::SUFFIX));
    }

    public static function destinationDropIns(): array
    {
        return [
            ['wp-content/object-cache.php'],
            ['wp-content/advanced-cache.php'],
            ['wp-content/db.php'],
            ['wp-content/Object-Cache.php'],
        ];
    }

    /**
     * @dataProvider destinationDropIns
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('destinationDropIns')]
    public function testDropInsOfTheSourceAreNotWritten(string $path): void
    {
        self::writeFile($this->abspath . 'wp-content/object-cache.php', 'destination drop-in');
        $this->registry->expects($this->once())->method('markChunkRestored');

        $result = $this->handler->restoreChunk($this->chunk($path, 'source drop-in', 1, 1));

        $this->assertTrue($result['skipped']);
        $this->assertSame('Destination drop-in is kept', $result['reason']);
        $this->assertSame('destination drop-in', file_get_contents($this->abspath . 'wp-content/object-cache.php'));
        $this->assertDirectoryDoesNotExist($this->stagedRoot() . 'wp-content');
    }

    public function testOtherFilesNamedLikeDropInsAreStillStaged(): void
    {
        $this->handler->restoreChunk($this->chunk('wp-content/plugins/a/db.php', 'x', 1, 1));

        $this->assertFileExists($this->stagedRoot() . 'wp-content/plugins/a/db.php' . StagedFiles::SUFFIX);
    }

    public function testPluginFolderUnderAnotherNameIsNeverWritten(): void
    {
        $own = $this->abspath . 'wp-content/plugins/migrator-renamed';
        mkdir($own, 0755, true);
        Config::set('plugin_dir', $own . '/');
        $handler = new FileRestoreHandler($this->registry, $this->abspath);

        $result = $handler->restoreChunk($this->chunk('wp-content/plugins/migrator-renamed/src/Plugin.php', 'x', 1, 1));

        $this->assertTrue($result['skipped']);
        $this->assertFileDoesNotExist($this->stagedRoot() . 'wp-content/plugins/migrator-renamed/src/Plugin.php');
    }

    public function testSymlinkedParentInsideTheStagingFolderLeadingOutsideIsNotWrittenThrough(): void
    {
        $outside = $this->createTemporaryDirectory();
        mkdir($this->stagedRoot() . 'wp-content/plugins', 0755, true);
        symlink(rtrim($outside, '/'), $this->stagedRoot() . 'wp-content/plugins/linked');

        $result = $this->handler->restoreChunk($this->chunk('wp-content/plugins/linked/a.php', 'pwned', 1, 1));

        $this->assertTrue($result['failed']);
        $this->assertFileDoesNotExist($outside . 'a.php');
    }

    public function testStagedFileThatIsASymlinkIsNotWrittenThrough(): void
    {
        $outside = $this->createTemporaryDirectory();
        file_put_contents($outside . 'victim.php', 'original');
        mkdir($this->stagedRoot(), 0755, true);
        symlink($outside . 'victim.php', $this->stagedRoot() . 'evil.php' . StagedFiles::SUFFIX);

        $result = $this->handler->restoreChunk($this->chunk('evil.php', 'pwned', 1, 1));

        $this->assertTrue($result['failed']);
        $this->assertSame('original', file_get_contents($outside . 'victim.php'));
    }

    public function testStagedFileIsWrittenWithTheSuffixAndNoRealNameExists(): void
    {
        $this->handler->restoreChunk($this->chunk('wp-content/plugins/a/a.php', 'part1-', 1, 2));
        $this->handler->restoreChunk($this->chunk('wp-content/plugins/a/a.php', 'part2', 2, 2));

        $this->assertFileExists($this->stagedRoot() . 'wp-content/plugins/a/a.php.pam-staged');
        $this->assertFileDoesNotExist($this->stagedRoot() . 'wp-content/plugins/a/a.php');
    }

    public function testStagedNamesThatEndWithTheSuffixAreRefused(): void
    {
        $this->registry->expects($this->exactly(2))->method('markChunkRestored');

        $file = $this->handler->restoreChunk($this->chunk('wp-content/plugins/a/x.php.pam-staged', 'x', 1, 1));
        $folder = $this->handler->restoreChunk($this->chunk('wp-content/plugins/a.pam-staged/x.php', 'x', 1, 1));

        foreach ([$file, $folder] as $result) {
            $this->assertTrue($result['skipped']);
            $this->assertStringContainsString('reserved for staged files', $result['reason']);
        }
        $this->assertDirectoryDoesNotExist($this->stagedRoot() . 'wp-content');
    }

    public function testFileOutsideTheStagedRuleMayEndWithTheSuffix(): void
    {
        $result = $this->handler->restoreChunk($this->chunk('wp-content/uploads/export.pam-staged', 'x', 1, 1));

        $this->assertSame('restored_file_chunk', $result['action']);
        $this->assertSame('x', file_get_contents($this->abspath . 'wp-content/uploads/export.pam-staged'));
    }

    public function testStagedFilesOfTheHandlerMoveIntoPlace(): void
    {
        self::writeFile($this->abspath . 'wp-content/plugins/a/old.php', 'old');
        $this->handler->restoreChunk($this->chunk('wp-content/plugins/a/new.php', 'new', 1, 1));

        $result = $this->handler->staged()->moveIntoPlace();
        $this->handler->staged()->finish();

        $this->assertSame(1, $result['moved']);
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/a/new.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/a/old.php');
        $this->assertDirectoryDoesNotExist($this->migrationDir . 'mig_1/replaced');
    }

    private function stagedRoot(): string
    {
        return $this->migrationDir . 'mig_1/staged/';
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
}
