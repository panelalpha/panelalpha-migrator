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
}

