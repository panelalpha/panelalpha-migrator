<?php

namespace PanelAlpha\Migrator\Services\Scanner;

/**
 * Class FileExclusionService
 *
 * @package PanelAlpha\Migrator\Services\Scanner
 *
 * Service for checking if a file or directory should be excluded from the scan.
 */
class FileExclusionService
{
    private array $excludedDirs;
    private array $excludedExtensions;
    private array $excludedFiles;

    /**
     * FileExclusionService constructor.
     *
     * @param array $excludedDirs
     * @param array $excludedExtensions
     * @param array $excludedFiles
     */
    public function __construct(array $excludedDirs, array $excludedExtensions, array $excludedFiles)
    {
        $this->excludedDirs = $excludedDirs;
        $this->excludedExtensions = $excludedExtensions;
        $this->excludedFiles = $excludedFiles;
    }

    /**
     * Checks if a file path is excluded.
     *
     * @param string $filePath
     * @param string $rootPath
     * @return bool
     */
    public function isExcluded(string $filePath, string $rootPath): bool
    {
        $relativePath = $this->getRelativePath($filePath, $rootPath);

        if ($this->isDirectoryExcluded($relativePath)) {
            return true;
        }

        if ($this->isFileExcluded($relativePath)) {
            return true;
        }

        if ($this->isExtensionExcluded($filePath)) {
            return true;
        }

        return false;
    }
    
    /**
     * Checks if a directory is excluded.
     *
     * @param string $relativePath
     * @return bool
     */
    private function isDirectoryExcluded(string $relativePath): bool
    {
        foreach ($this->excludedDirs as $excludedDir) {

            if (strpos($relativePath, $excludedDir . '/') === 0 || $relativePath === $excludedDir) {
                return true;
            }

            if (strpos($relativePath, '/' . $excludedDir . '/') !== false) {
                return true;
            }

    
            if (substr($relativePath, -strlen('/' . $excludedDir)) === '/' . $excludedDir) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if a file is excluded.
     *
     * @param string $relativePath
     * @return bool
     */
    private function isFileExcluded(string $relativePath): bool
    {
        foreach ($this->excludedFiles as $excludedFile) {
            if ($relativePath === $excludedFile || strpos($relativePath, $excludedFile) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a file extension is excluded.
     *
     * @param string $filePath
     * @return bool
     */
    private function isExtensionExcluded(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, $this->excludedExtensions);
    }
    
    /**
     * Gets the relative path of a file.
     *
     * @param string $filePath
     * @param string $rootPath
     * @return string
     */
    private function getRelativePath(string $filePath, string $rootPath): string
    {
        $relativePath = str_replace($rootPath, '', $filePath);
        return ltrim(str_replace('\\', '/', $relativePath), '/');
    }
}
