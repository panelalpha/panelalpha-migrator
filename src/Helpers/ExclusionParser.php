<?php

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class ExclusionParser
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Parses exclusion rules from a multiline string.
 */
class ExclusionParser
{
    /**
     * Parse multiline exclusion input into categories
     *
     * @param string $input Multiline string with exclusions
     * @return array{directories: array, extensions: array, files: array}
     */
    public static function parse(string $input): array
    {
        $directories = [];
        $extensions = [];
        $files = [];

        $lines = explode("\n", $input);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Detect type and categorize
            if (self::hasPathSeparator($line)) {
                $directories[] = self::normalizeDirectory($line);
            } elseif (self::isExtension($line)) {
                $extensions[] = self::normalizeExtension($line);

                // A name such as .git or .ddev is also a hidden folder or file.
                if (str_starts_with($line, '.')) {
                    $directories[] = $line;
                }
            } elseif (self::isDirectory($line)) {
                $directories[] = self::normalizeDirectory($line);
            } else {
                // Treat as file
                $files[] = $line;
            }
        }

        return [
            'directories' => $directories,
            'extensions' => $extensions,
            'files' => $files,
        ];
    }

    /**
     * Check if line represents an extension.
     * Patterns: *.ext, .ext
     *
     * @param string $line
     * @return bool
     */
    private static function isExtension(string $line): bool
    {
        return str_starts_with($line, '*.') || str_starts_with($line, '.');
    }

    /**
     * Check if line is a path, such as wp-content/cache or /.ddev.
     *
     * @param string $line
     * @return bool
     */
    private static function hasPathSeparator(string $line): bool
    {
        return str_contains($line, '/') || str_contains($line, '\\');
    }

    /**
     * Check if line represents a directory: a name without extension.
     *
     * @param string $line
     * @return bool
     */
    private static function isDirectory(string $line): bool
    {
        return !str_contains($line, '.');
    }

    /**
     * Normalize extension (remove *. or . prefix).
     *
     * @param string $extension
     * @return string
     */
    private static function normalizeExtension(string $extension): string
    {
        if (str_starts_with($extension, '*.')) {
            return substr($extension, 2);
        }
        if (str_starts_with($extension, '.')) {
            return substr($extension, 1);
        }
        return $extension;
    }

    /**
     * Normalize directory path (remove leading and trailing slashes).
     *
     * @param string $directory
     * @return string
     */
    private static function normalizeDirectory(string $directory): string
    {
        return trim(str_replace('\\', '/', $directory), '/');
    }
}
