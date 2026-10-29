<?php

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class TokenHelper
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Helper for reading and saving the site token and the plugin settings.
 * Both live in WordPress options, so a plugin update does not remove them.
 */
class TokenHelper
{
    public const TOKEN_OPTION = 'panelalpha_migrator_token';

    public const SETTINGS_OPTION = 'panelalpha_migrator_settings';

    /**
     * Gets the authentication token of this site.
     *
     * @return string|null Null when no token is saved
     */
    public static function getToken(): ?string
    {
        if (!function_exists('get_option')) {
            return null;
        }

        $token = get_option(self::TOKEN_OPTION, '');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Saves the authentication token and checks that it can be read back.
     * The option is not autoloaded, so it is not loaded on every page.
     *
     * @param string $token
     * @return bool
     */
    public static function saveToken(string $token): bool
    {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            return false;
        }

        update_option(self::TOKEN_OPTION, $token, false);

        return get_option(self::TOKEN_OPTION, '') === $token;
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
     * Gets the plugin settings: the defaults with the saved values on top.
     * A saved value of the wrong type is ignored and unknown keys are dropped.
     *
     * @return array{mode: string, show_buttons: bool, allowed_destinations: list<string>}
     */
    public static function getSettings(): array
    {
        $stored = function_exists('get_option') ? get_option(self::SETTINGS_OPTION, []) : [];

        return self::normalizeSettings(is_array($stored) ? $stored : []);
    }

    /**
     * Saves the plugin settings. Values of the wrong type are dropped.
     *
     * @param array $settings Keys: mode, show_buttons, allowed_destinations
     * @return bool
     */
    public static function saveSettings(array $settings): bool
    {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            return false;
        }

        $clean = self::normalizeSettings($settings);

        update_option(self::SETTINGS_OPTION, $clean);

        return get_option(self::SETTINGS_OPTION, null) === $clean;
    }

    /**
     * Gets the migration mode.
     *
     * @return string 'source' or 'destination'
     */
    public static function getMode(): string
    {
        return self::getSettings()['mode'];
    }

    /**
     * Gets the show_buttons setting.
     *
     * @return bool
     */
    public static function getShowButtons(): bool
    {
        return self::getSettings()['show_buttons'];
    }

    /**
     * Gets the destinations that may be migrated to even when they look private.
     *
     * @return list<string>
     */
    public static function getAllowedDestinations(): array
    {
        return self::getSettings()['allowed_destinations'];
    }

    /**
     * Keeps the known settings with the right type, filled up with the defaults.
     *
     * @param array $settings
     * @return array{mode: string, show_buttons: bool, allowed_destinations: list<string>}
     */
    private static function normalizeSettings(array $settings): array
    {
        $clean = [
            'mode' => 'source',
            'show_buttons' => true,
            'allowed_destinations' => [],
        ];

        if (isset($settings['mode']) && in_array($settings['mode'], ['source', 'destination'], true)) {
            $clean['mode'] = $settings['mode'];
        }

        if (isset($settings['show_buttons']) && is_bool($settings['show_buttons'])) {
            $clean['show_buttons'] = $settings['show_buttons'];
        }

        if (isset($settings['allowed_destinations']) && is_array($settings['allowed_destinations'])) {
            $clean['allowed_destinations'] = array_values(array_filter($settings['allowed_destinations'], 'is_string'));
        }

        return $clean;
    }
}
