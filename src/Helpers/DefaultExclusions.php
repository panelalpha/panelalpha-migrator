<?php

namespace WordpressMigrator\Helpers;

/**
 * Default exclusions for migration.
 *
 * @package WordpressMigrator\Helpers
 */
class DefaultExclusions
{
    /**
     * Get default exclusions that are always applied.
     *
     * @return array
     */
    public static function get(): array
    {
        // The root .htaccess is left out by the scanner and sent on its own.
        // Listing it here would drop every nested .htaccess too (e.g. WooCommerce uploads).
        return [
            'wp-config.php',
            'wordpress-migrator',
            'wp-content/plugins/wordpress-migrator',
        ];
    }

    /**
     * Merge user exclusions with default exclusions.
     *
     * @param string|null $userExclusions Comma-separated or newline-separated exclusions
     * @return string Comma-separated exclusions
     */
    public static function merge(?string $userExclusions): string
    {
        $defaults = self::get();

        if (empty($userExclusions)) {
            return implode(',', $defaults);
        }

        $userArray = self::parse($userExclusions);
        $merged = array_unique(array_merge($defaults, $userArray));

        return implode(',', $merged);
    }

    /**
     * Parse user exclusions from comma or newline separated format.
     *
     * @param string $exclusions
     * @return array
     */
    private static function parse(string $exclusions): array
    {
        $exclusions = str_replace("\n", ',', $exclusions);

        $items = explode(',', $exclusions);

        $items = array_map('trim', $items);

        $items = array_filter($items, function($item) {
            return !empty($item);
        });

        return array_values($items);
    }

    /**
     * Convert comma-separated exclusions to newline format (for backend processing).
     *
     * @param string $exclusions Comma-separated exclusions
     * @return string Newline-separated exclusions
     */
    public static function toNewlineFormat(string $exclusions): string
    {
        $items = self::parse($exclusions);
        return implode("\n", $items);
    }
}
