<?php

namespace WordpressMigrator\Helpers;

/**
 * Class ExclusionParser
 *
 * @package WordpressMigrator\Helpers
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
            if (self::isExtension($line)) {
                $extensions[] = self::normalizeExtension($line);
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
     * Check if line represents a directory.
     * Contains / or \ or is a name without extension.
     *
     * @param string $line
     * @return bool
     */
    private static function isDirectory(string $line): bool
    {
        // Contains path separator
        if (str_contains($line, '/') || str_contains($line, '\\')) {
            return true;
        }

        // Name without extension (no dot)
        if (!str_contains($line, '.')) {
            return true;
        }

        return false;
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
     * Normalize directory path (remove trailing slashes).
     *
     * @param string $directory
     * @return string
     */
    private static function normalizeDirectory(string $directory): string
    {
        return rtrim(str_replace('\\', '/', $directory), '/');
    }
}
