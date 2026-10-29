<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use PanelAlpha\Migrator\Helpers\Filesystem;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class FilesystemTest extends TestCase
{
    use TemporaryDirectory;

    public function testTrailingSlashIsAddedOnce(): void
    {
        $this->assertSame('/var/www/', Filesystem::trailingSlashIt('/var/www'));
        $this->assertSame('/var/www/', Filesystem::trailingSlashIt('/var/www///'));
        $this->assertSame('C:\\site/', Filesystem::trailingSlashIt('C:\\site\\'));
    }

    public function testMkdirCreatesNestedFolders(): void
    {
        $path = $this->createTemporaryDirectory() . 'a/b/c';

        $this->assertTrue(Filesystem::mkdirP($path));
        $this->assertDirectoryExists($path);
        $this->assertTrue(Filesystem::mkdirP($path));
    }

    public function testMkdirOnAnExistingFileFails(): void
    {
        $path = $this->createTemporaryDirectory() . 'file.txt';
        file_put_contents($path, 'x');

        $this->assertFalse(Filesystem::mkdirP($path));
    }

    public function testMkdirUnderAFileFails(): void
    {
        $file = $this->createTemporaryDirectory() . 'file.txt';
        file_put_contents($file, 'x');

        $this->assertFalse(@Filesystem::mkdirP($file . '/child'));
    }

    public function testEnsureDirectoryExistsCreatesTheFolder(): void
    {
        $path = $this->createTemporaryDirectory() . 'new';

        Filesystem::ensureDirectoryExists($path);

        $this->assertDirectoryExists($path);
    }

    public function testEnsureDirectoryExistsThrowsWhenItCannotCreate(): void
    {
        $file = $this->createTemporaryDirectory() . 'file.txt';
        file_put_contents($file, 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create directory: ' . $file . '/child');

        Filesystem::ensureDirectoryExists($file . '/child');
    }

    public function testAtomicWriteFallsBackToWritingInPlace(): void
    {
        $directory = $this->createTemporaryDirectory();
        $path = $directory . 'config.php';
        file_put_contents($path, 'old');
        chmod($path, 0666);
        chmod($directory, 0555);

        try {
            $this->assertTrue(Filesystem::writeFileAtomic($path, 'new'));
            $this->assertSame('new', file_get_contents($path));
            $this->assertSame([$path], glob($directory . '*'));
        } finally {
            chmod($directory, 0755);
        }
    }
}
