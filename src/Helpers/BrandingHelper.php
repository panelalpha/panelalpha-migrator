<?php

namespace WordpressMigrator\Helpers;

/**
 * Class BrandingHelper
 *
 * @package WordpressMigrator\Helpers
 *
 * Helper for reading branding and configuration from config.php file.
 */
class BrandingHelper
{
    /**
     * Gets the configuration from config.php file.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        $pluginDir = defined('WP_MIGRATOR_PLUGIN_DIR')
            ? WP_MIGRATOR_PLUGIN_DIR
            : dirname(__DIR__, 2) . '/';

        $configFilePath = $pluginDir . 'branding.php';

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
     * Gets the branding configuration from config.php file.
     *
     * @return array{name: string, url: string} Associative array with 'name' and 'url' keys.
     */
    public static function getBranding(): array
    {
        $config = self::getConfig();
        return [
            'name' => $config['branding']['name'] ?? 'PanelAlpha',
            'url' => $config['branding']['url'] ?? 'https://panelalpha.com',
        ];
    }
}
