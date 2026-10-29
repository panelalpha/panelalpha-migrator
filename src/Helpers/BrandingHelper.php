<?php

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class BrandingHelper
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Helper for reading the branding from branding.php, which the PanelAlpha panel may add.
 */
class BrandingHelper
{
    /**
     * Gets the content of branding.php.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        $pluginDir = defined('PANELALPHA_MIGRATOR_PLUGIN_DIR')
            ? PANELALPHA_MIGRATOR_PLUGIN_DIR
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
     * Gets the branding, with PanelAlpha as the default.
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
