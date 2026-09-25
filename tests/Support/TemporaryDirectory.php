<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * Gives a test its own folder under the system temp directory and removes it afterwards.
 */
trait TemporaryDirectory
{
    private array $temporaryDirectories = [];

    protected function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/wp-migrator-test-' . bin2hex(random_bytes(6)) . '/';
        mkdir($directory, 0755, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    #[After]
    protected function removeTemporaryDirectories(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            self::removeDirectory($directory);
        }

        $this->temporaryDirectories = [];
    }

    protected static function writeFile(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $content);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            @unlink(rtrim($directory, '/'));
            return;
        }

        foreach (scandir($directory) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = rtrim($directory, '/') . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                @chmod($path, 0755);
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
