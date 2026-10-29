<?php

namespace PanelAlpha\Migrator\Restore;

/**
 * Class FilePathConfinement
 *
 * Decides whether a file path stays inside the WordPress directory.
 * Collapses "." and ".." segments and does not touch the disk.
 */
class FilePathConfinement
{
    /**
     * Returns the collapsed path used for the write, relative to the WordPress directory.
     *
     * @param string $filePath File path from the chunk
     * @param string $wordpressDirectory Directory that contains wp-content
     * @return string
     * @throws \InvalidArgumentException When the path does not stay inside the WordPress directory
     */
    public function confine(string $filePath, string $wordpressDirectory): string
    {
        if ($filePath === '' || $this->isAbsolute($filePath) || $this->hasDrivePrefix($filePath)) {
            throw new \InvalidArgumentException('File path is outside the WordPress directory');
        }

        $root = $this->collapse($wordpressDirectory);
        $collapsed = $this->collapse($root . '/' . $filePath);
        $prefix = $root . '/';

        if ($root === '' || !str_starts_with($collapsed, $prefix)) {
            throw new \InvalidArgumentException('File path is outside the WordPress directory');
        }

        $relative = substr($collapsed, strlen($prefix));

        if ($relative === '') {
            throw new \InvalidArgumentException('File path is outside the WordPress directory');
        }

        return $relative;
    }

    /**
     * A leading slash is an absolute path. A backslash is not a separator.
     */
    private function isAbsolute(string $filePath): bool
    {
        return str_starts_with($filePath, '/');
    }

    /**
     * A drive prefix such as "C:" is rejected even when the remainder names a place inside the directory.
     */
    private function hasDrivePrefix(string $filePath): bool
    {
        return preg_match('/^[A-Za-z]:/', $filePath) === 1;
    }

    /**
     * Collapses "." and ".." without reading the filesystem. Split on "/" only.
     */
    private function collapse(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $segments = explode('/', $path);
        $stack = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($stack === []) {
                    if ($absolute) {
                        continue;
                    }

                    $stack[] = '..';
                    continue;
                }

                if (end($stack) === '..') {
                    $stack[] = '..';
                    continue;
                }

                array_pop($stack);
                continue;
            }

            $stack[] = $segment;
        }

        $collapsed = implode('/', $stack);

        if ($absolute) {
            return '/' . $collapsed;
        }

        return $collapsed;
    }
}
