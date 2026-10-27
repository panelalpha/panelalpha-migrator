<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class Filesystem
 *
 * @package WordpressMigrator\Helpers
 *
 * Provides filesystem utility functions.
 */
class Filesystem
{
    /**
     * Creates a directory recursively.
     *
     * @param string $path
     * @param int    $permissions
     * @return bool
     */
    public static function mkdirP(string $path, int $permissions = 0755): bool
    {
        if (!file_exists($path)) {
            $result = @mkdir($path, $permissions, true);
            if (!$result) {
                error_log("WordPress Migrator: Failed to create directory: {$path}");
            }
            return $result;
        }
        return is_dir($path);
    }

    /**
     * Appends a trailing slash to a string.
     *
     * @param string $string
     * @return string
     */
    public static function trailingSlashIt(string $string): string
    {
        return rtrim($string, '/\\') . '/';
    }

    /**
     * Ensures that a directory exists.
     *
     * @param string $path
     * @throws \RuntimeException If directory creation fails
     */
    public static function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            $result = self::mkdirP($path);
            if (!$result) {
                throw new \RuntimeException("Failed to create directory: {$path}");
            }
        }
    }

    /**
     * Replaces a file by renaming a complete temporary file over it.
     * A crash while writing leaves the previous file intact.
     * Some hosts let PHP write the file but not create the temporary file next
     * to it (a read-only folder, or no new .php files on WP Engine); then the
     * file is written in place.
     *
     * @param string $path
     * @param string $contents
     * @return bool
     */
    public static function writeFileAtomic(string $path, string $contents): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !self::mkdirP($directory)) {
            return false;
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp.php';
        $written = @file_put_contents($temporary, $contents, LOCK_EX);

        if ($written === false) {
            @unlink($temporary);
            return @file_put_contents($path, $contents, LOCK_EX) === strlen($contents);
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return false;
        }

        return true;
    }
}
