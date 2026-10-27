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
        $pluginDir = defined('WP_MIGRATOR_PLUGIN_DIR')
            ? WP_MIGRATOR_PLUGIN_DIR
            : dirname(__DIR__, 2) . '/';

        $token = self::readToken($pluginDir . 'config.php');

        return $token === '' ? null : $token;
    }

    /**
     * Reads the token from config.php.
     *
     * The file text is read first, because right after a token change the
     * opcode cache can still answer an include with the previous config.php.
     *
     * @param string $configFilePath
     * @return string Empty when no token is configured
     */
    public static function readToken(string $configFilePath): string
    {
        if (!file_exists($configFilePath)) {
            return '';
        }

        $content = @file_get_contents($configFilePath);
        if (is_string($content) && preg_match("/'token'\s*=>\s*'([^']*)'/", $content, $matches)) {
            return $matches[1];
        }

        $configData = include $configFilePath;
        $token = is_array($configData) ? ($configData['token'] ?? '') : '';

        return is_string($token) ? $token : '';
    }

    /**
     * Gets the token for requests to the destination from the migration data.
     * The site's own token is never sent to the destination.
     *
     * @param array $migrationData
     * @return string
     * @throws \RuntimeException
     */
    public static function forMigration(array $migrationData): string
    {
        $token = $migrationData['token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Destination token not found in migration data');
        }

        return $token;
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
