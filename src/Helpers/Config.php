<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class Config
 *
 * @package WordpressMigrator\Helpers
 *
 * Handles plugin configuration.
 */
class Config
{
    private static array $config = [];

    /**
     * Sets a configuration value.
     *
     * @param string $key
     * @param mixed  $value
     */
    public static function set(string $key, $value): void
    {
        self::$config[$key] = $value;
    }

    /**
     * Gets a configuration value.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return self::$config[$key] ?? $default;
    }

    /**
     * Initializes the configuration.
     */
    public static function init(): void
    {
        // Set default configuration
        self::$config = [
            'plugin_dir' => WP_MIGRATOR_PLUGIN_DIR ?? dirname(__DIR__, 2) . '/',
            'plugin_url' => WP_MIGRATOR_PLUGIN_URL ?? '',
            'migration_dir' => WP_MIGRATOR_MIGRATION_DIR ?? dirname(__DIR__, 2) . '/migrations/',
            'version' => WP_MIGRATOR_VERSION ?? '1.0.0',
            'site_url' => self::getSiteUrl(),
            'home_url' => self::getHomeUrl(),
            'wp_version' => self::getWpVersion(),
            'admin_url' => self::getAdminUrl(),
            'rest_url' => self::getRestUrl(),
            'mode' => self::getMode(),
            'show_mode_switcher' => self::getShowModeSwitcher(),
        ];
    }

    /**
     * Gets the migrator mode from config.php.
     *
     * @return string
     */
    private static function getMode(): string
    {
        return TokenHelper::getMode();
    }

    /**
     * Gets the show mode switcher setting from config.php.
     *
     * @return bool
     */
    private static function getShowModeSwitcher(): bool
    {
        return TokenHelper::getShowButtons();
    }

    /**
     * Gets the site URL.
     *
     * @return string
     */
    private static function getSiteUrl(): string
    {
        // Try to get from WordPress options or use server variables
        if (defined('WP_SITEURL')) {
            return WP_SITEURL;
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return "{$protocol}://{$host}";
    }

    /**
     * Gets the home URL.
     *
     * @return string
     */
    private static function getHomeUrl(): string
    {
        if (defined('WP_HOME')) {
            return WP_HOME;
        }

        return self::getSiteUrl();
    }

    /**
     * Gets the WordPress version.
     *
     * @return string
     */
    private static function getWpVersion(): string
    {
        // Try to read WordPress version
        $versionFile = ABSPATH . 'wp-includes/version.php';
        if (file_exists($versionFile)) {
            include $versionFile;
            return $wp_version ?? 'unknown';
        }

        return 'unknown';
    }

    /**
     * Gets the admin URL.
     *
     * @return string
     */
    private static function getAdminUrl(): string
    {
        return self::getSiteUrl() . '/wp-admin/';
    }

    /**
     * Gets the REST API URL.
     *
     * @return string
     */
    private static function getRestUrl(): string
    {
        return self::getSiteUrl() . '/wp-json/';
    }
}
