<?php

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class SetupFile
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Imports the one-time setup file that the PanelAlpha panel puts into wp-content.
 * The file returns an array with the token and the settings. It is deleted after it was read,
 * and its values are stored in WordPress options only when the delete worked.
 */
class SetupFile
{
    public const FILE_NAME = 'panelalpha-migrator-setup.php';

    /**
     * Reads the setup file, deletes it and saves its values.
     *
     * @param string|null $path Path of the file, wp-content by default
     * @return bool True when the file was imported
     */
    public static function import(?string $path = null): bool
    {
        $path = $path ?? self::defaultPath();

        if ($path === '' || !is_file($path)) {
            return false;
        }

        // The opcode cache may still hold an older file with the same name.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        $data = include $path;

        wp_delete_file($path);
        clearstatcache(true, $path);

        // A token file that stays on disk must not be acted on.
        if (file_exists($path)) {
            error_log('PanelAlpha Migrator: the setup file could not be deleted, so it was not imported.');

            return false;
        }

        if (!is_array($data)) {
            return true;
        }

        self::apply($data);

        return true;
    }

    /**
     * Saves the valid values. Keys that are not in the file keep their value.
     *
     * @param array $data
     */
    private static function apply(array $data): void
    {
        if (array_key_exists('token', $data)) {
            if (is_string($data['token']) && preg_match('/^[0-9a-f]{64}$/i', $data['token'])) {
                if (!TokenHelper::saveToken($data['token'])) {
                    error_log('PanelAlpha Migrator: the token from the setup file could not be saved.');
                }
            } else {
                error_log('PanelAlpha Migrator: the token in the setup file is not valid and was ignored.');
            }
        }

        $settings = TokenHelper::getSettings();
        $changed = false;

        if (isset($data['mode']) && in_array($data['mode'], ['source', 'destination'], true)) {
            $settings['mode'] = $data['mode'];
            $changed = true;
        }

        if (isset($data['showButtons']) && is_bool($data['showButtons'])) {
            $settings['show_buttons'] = $data['showButtons'];
            $changed = true;
        }

        if (isset($data['allowed_destinations']) && is_array($data['allowed_destinations'])) {
            $list = array_values(array_filter($data['allowed_destinations'], 'is_string'));
            if (count($list) === count($data['allowed_destinations'])) {
                $settings['allowed_destinations'] = $list;
                $changed = true;
            }
        }

        if ($changed) {
            TokenHelper::saveSettings($settings);
        }
    }

    private static function defaultPath(): string
    {
        return defined('WP_CONTENT_DIR') ? rtrim(WP_CONTENT_DIR, '/\\') . '/' . self::FILE_NAME : '';
    }
}
