<?php

namespace WordpressMigrator\Helpers;

/**
 * Class TokenHelper
 *
 * @package WordpressMigrator\Helpers
 *
 * Helper for reading authentication token and configuration from config.php file.
 */
class TokenHelper
{
    /**
     * Gets the authentication token from config.php file.
     *
     * @return string|null
     */
    public static function getToken(): ?string
    {
        $config = self::getConfig();
        return $config['token'] ?? null;
    }

    /**
     * Gets the configuration from config.php file.
     *
     * @return array
     */
    public static function getConfig(): array
    {
        $pluginDir = defined('WP_MIGRATOR_PLUGIN_DIR')
            ? WP_MIGRATOR_PLUGIN_DIR
            : dirname(__DIR__, 2) . '/';

        $configFilePath = $pluginDir . 'config.php';

        if (!file_exists($configFilePath)) {
            return [];
        }

        $configData = include $configFilePath;

        if (!is_array($configData)) {
            return [];
        }

        return $configData;
    }

    /**
     * Gets the migration mode from config.php file.
     *
     * @return string 'source' or 'destination'
     */
    public static function getMode(): string
    {
        $config = self::getConfig();
        return $config['mode'] ?? 'source';
    }

    /**
     * Gets the showButtons setting from config.php file.
     *
     * @return bool
     */
    public static function getShowButtons(): bool
    {
        $config = self::getConfig();
        return $config['showButtons'] ?? true;
    }
}
