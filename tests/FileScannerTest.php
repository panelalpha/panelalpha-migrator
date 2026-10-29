<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Services\Scanner\FileScanner;
use PanelAlpha\Migrator\Services\Scanner\FileScannerConfig;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class FileScannerTest extends TestCase
{
    use TemporaryDirectory;

    public function testScansRelativePathsAndFileMetadata(): void
    {
        $root = $this->createTemporaryDirectory();
        $path = $root . 'wp-content/uploads/photo.jpg';
        self::writeFile($path, 'photo');

        $files = (new FileScanner(new FileScannerConfig($root, [], [], [])))->scan();

        self::assertCount(1, $files);
        self::assertSame('wp-content/uploads/photo.jpg', $files[0]->path);
        self::assertSame(5, $files[0]->size);
        self::assertSame(substr(sprintf('%o', fileperms($path)), -3), $files[0]->permissions);
        self::assertSame(date('c', filemtime($path)), $files[0]->modified);
        self::assertSame('pending', $files[0]->status);
        self::assertSame(1, $files[0]->totalChunks);
        self::assertSame([1, 0, 5], [$files[0]->chunks[0]->id, $files[0]->chunks[0]->offset, $files[0]->chunks[0]->size]);
    }

    public function testChunksLargeAndEmptyFiles(): void
    {
        $root = $this->createTemporaryDirectory();
        $chunkSize = (new FileScannerConfig($root, [], [], []))->getChunkSize();
        self::writeFile($root . 'large.bin', str_repeat('a', $chunkSize + 3));
        self::writeFile($root . 'empty.txt', '');

        $files = (new FileScanner(new FileScannerConfig($root, [], [], [])))->scan();
        $byPath = [];
        foreach ($files as $file) {
            $byPath[$file->path] = $file;
        }

        self::assertSame(2, $byPath['large.bin']->totalChunks);
        self::assertSame([1, 0, $chunkSize], [$byPath['large.bin']->chunks[0]->id, $byPath['large.bin']->chunks[0]->offset, $byPath['large.bin']->chunks[0]->size]);
        self::assertSame([2, $chunkSize, 3], [$byPath['large.bin']->chunks[1]->id, $byPath['large.bin']->chunks[1]->offset, $byPath['large.bin']->chunks[1]->size]);
        self::assertSame(1, $byPath['empty.txt']->totalChunks);
        self::assertSame(0, $byPath['empty.txt']->chunks[0]->size);
    }

    public function testExcludesConfiguredFilesAndExtensions(): void
    {
        $root = $this->createTemporaryDirectory();
        self::writeFile($root . 'keep.txt', 'yes');
        self::writeFile($root . 'error_log', 'secret');
        self::writeFile($root . 'debug.LOG', 'debug');

        $files = (new FileScanner(new FileScannerConfig($root, [], ['log'], ['error_log'])))->scan();

        self::assertSame(['keep.txt'], array_map(static fn ($file) => $file->path, $files));
    }

    public function testExcludesDirectoryTreeWhileKeepingOtherFiles(): void
    {
        $root = $this->createTemporaryDirectory();
        self::writeFile($root . 'cache/secret.txt', 'secret');
        self::writeFile($root . 'wp-content/uploads/photo.jpg', 'photo');

        $files = (new FileScanner(new FileScannerConfig($root, ['cache'], [], [])))->scan();

        self::assertSame(['wp-content/uploads/photo.jpg'], array_map(static fn ($file) => $file->path, $files));
    }

    public function testSkipsSymlinkWhoseTargetIsOutsideTheScanRoot(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlink() is not available');
        }

        $root = $this->createTemporaryDirectory();
        $outside = sys_get_temp_dir() . '/panelalpha-migrator-outside-' . bin2hex(random_bytes(4));
        mkdir($outside);
        $outsideFile = $outside . '/secret.txt';
        file_put_contents($outsideFile, 'secret');
        self::writeFile($root . 'inside.txt', 'inside');
        $link = $root . 'leak.txt';

        if (!@symlink($outsideFile, $link)) {
            @unlink($outsideFile);
            @rmdir($outside);
            $this->markTestSkipped('symlink() could not create a link');
        }

        try {
            $files = (new FileScanner(new FileScannerConfig($root, [], [], [])))->scan();
            $paths = array_map(static fn ($file) => $file->path, $files);

            $this->assertSame(['inside.txt'], $paths);
        } finally {
            @unlink($link);
            @unlink($outsideFile);
            @rmdir($outside);
        }
    }

    public function testMissingRootRaisesFilesystemError(): void
    {
        $root = $this->createTemporaryDirectory() . 'missing';
        $this->expectException(\UnexpectedValueException::class);
        (new FileScanner(new FileScannerConfig($root, [], [], [])))->scan();
    }
}
