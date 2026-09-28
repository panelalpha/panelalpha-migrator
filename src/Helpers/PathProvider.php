<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class PathProvider
 *
 * @package WordpressMigrator\Helpers
 *
 * Provides centralized access to file system paths, abstracting WordPress constants.
 */
class PathProvider
{
    /**
     * Gets the migrations directory path.
     *
     * @return string
     */
    public static function getMigrationDir(): string
    {
        if (defined('WP_MIGRATOR_MIGRATION_DIR')) {
            return WP_MIGRATOR_MIGRATION_DIR;
        }

        return Config::get('migration_dir', self::getPluginDir() . 'migrations/');
    }

    /**
     * Gets the plugin directory path.
     *
     * @return string
     */
    public static function getPluginDir(): string
    {
        if (defined('WP_MIGRATOR_PLUGIN_DIR')) {
            return WP_MIGRATOR_PLUGIN_DIR;
        }

        return Config::get('plugin_dir', dirname(__DIR__, 2) . '/');
    }

    /**
     * Gets the plugin URL.
     *
     * @return string
     */
    public static function getPluginUrl(): string
    {
        if (defined('WP_MIGRATOR_PLUGIN_URL')) {
            return WP_MIGRATOR_PLUGIN_URL;
        }

        return Config::get('plugin_url', '');
    }

    /**
     * Gets the WordPress absolute path (ABSPATH).
     *
     * Handles special directory structures:
     * - WP Cloud: WP_CONTENT_DIR outside ABSPATH
     * - Bitnami: wp-config.php and wp-content as symlinks to different directory
     *
     * @return string
     */
    public static function getAbsPath(): string
    {
        // WP Cloud detection: WP_CONTENT_DIR is outside ABSPATH
        if (defined('WP_CONTENT_DIR') && defined('ABSPATH')) {
            $contentDir = WP_CONTENT_DIR;
            $abspath = ABSPATH;

            if (strpos($contentDir, $abspath) !== 0) {
                // WP Cloud: use parent of WP_CONTENT_DIR
                $userFilesDir = dirname($contentDir) . '/';
                return $userFilesDir;
            }
        }

        // Bitnami detection: wp-config.php or wp-content are symlinks to outside ABSPATH
        if (defined('ABSPATH')) {
            $abspath = ABSPATH;
            $configPath = $abspath . 'wp-config.php';
            $contentPath = $abspath . 'wp-content';

            // Check if wp-config.php is a symlink pointing outside ABSPATH
            if (is_link($configPath)) {
                $realConfigPath = realpath($configPath);
                if ($realConfigPath && strpos($realConfigPath, $abspath) !== 0) {
                    // Bitnami: wp-config.php symlinks to /bitnami/wordpress/
                    return dirname($realConfigPath) . '/';
                }
            }

            // Check if wp-content is a symlink pointing outside ABSPATH
            if (is_link($contentPath)) {
                $realContentPath = realpath($contentPath);
                if ($realContentPath && strpos($realContentPath, $abspath) !== 0) {
                    // Bitnami: wp-content symlinks to /bitnami/wordpress/
                    return dirname($realContentPath) . '/';
                }
            }

            return ABSPATH;
        }

        return Config::get('root_path', getcwd() . '/');
    }

    /**
     * Gets the plugin version.
     *
     * @return string
     */
    public static function getVersion(): string
    {
        if (defined('WP_MIGRATOR_VERSION')) {
            return WP_MIGRATOR_VERSION;
        }

        return Config::get('version', '1.0.0');
    }

    /**
     * Gets the full path to a migration file.
     *
     * @param string $migrationId Migration identifier
     * @return string
     */
    public static function getMigrationFilePath(string $migrationId): string
    {
        return self::getMigrationDir() . $migrationId . '.json';
    }

    /**
     * Gets the collected chunks directory path.
     *
     * @return string
     */
    public static function getCollectedDir(): string
    {
        return self::getMigrationDir() . 'collected/';
    }
}


