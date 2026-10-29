<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class Filesystem
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Provides filesystem utility functions.
 */
class Filesystem
{
    /**
     * @var \WP_Filesystem_Direct|null
     */
    private static $direct;

    /**
     * Returns the direct WordPress filesystem.
     * The global $wp_filesystem may use FTP or SSH, which needs credentials
     * and cannot run in a REST call, so the direct one is used.
     *
     * @return \WP_Filesystem_Direct
     */
    public static function direct(): \WP_Filesystem_Direct
    {
        if (self::$direct === null) {
            if (!class_exists('WP_Filesystem_Direct', false)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            }

            self::$direct = new \WP_Filesystem_Direct(null);
        }

        return self::$direct;
    }

    /**
     * Creates a directory recursively.
     *
     * @param string $path
     * @return bool
     */
    public static function mkdirP(string $path): bool
    {
        if (!file_exists($path)) {
            $result = wp_mkdir_p($path);
            if (!$result) {
                error_log("PanelAlpha Migrator: Failed to create directory: {$path}");
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
                throw new \RuntimeException(esc_html("Failed to create directory: {$path}"));
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
            wp_delete_file($temporary);
            return @file_put_contents($path, $contents, LOCK_EX) === strlen($contents);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- the atomic replace needs rename(), WP_Filesystem::move() with overwrite deletes the old file first, so the file is missing for a moment
        if (!@rename($temporary, $path)) {
            wp_delete_file($temporary);
            return false;
        }

        return true;
    }
}
