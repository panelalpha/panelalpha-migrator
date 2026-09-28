<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class Translation
 *
 * @package WordpressMigrator\Helpers
 *
 * Handles translations.
 */
class Translation
{
    private static array $translations = [];

    /**
     * Translates a string.
     *
     * @param string $text
     * @param string $domain
     * @return string
     */
    public static function __(string $text, string $domain = 'default'): string
    {
        // Return the key if no translation exists
        return self::$translations[$domain][$text] ?? $text;
    }

    /**
     * Echoes a translated string.
     *
     * @param string $text
     * @param string $domain
     */
    public static function _e(string $text, string $domain = 'default'): void
    {
        echo self::__($text, $domain);
    }

    /**
     * Adds a translation.
     *
     * @param string $domain
     * @param string $key
     * @param string $value
     */
    public static function addTranslation(string $domain, string $key, string $value): void
    {
        if (!isset(self::$translations[$domain])) {
            self::$translations[$domain] = [];
        }
        self::$translations[$domain][$key] = $value;
    }

    /**
     * Loads translations from a file.
     *
     * @param string $domain
     * @param string $filePath
     */
    public static function loadTranslations(string $domain, string $filePath): void
    {
        if (file_exists($filePath)) {
            $translations = include $filePath;
            if (is_array($translations)) {
                self::$translations[$domain] = $translations;
            }
        }
    }
}

