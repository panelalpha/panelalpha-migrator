<?php

namespace PanelAlpha\Migrator\Services\Scanner;

/**
 * Class FileScannerConfig
 *
 * @package PanelAlpha\Migrator\Services\Scanner
 *
 * Configuration for the FileScanner.
 */
class FileScannerConfig
{
    private const CHUNK_SIZE = 532480;

    private string $rootPath;
    private int $chunkSize;
    private array $excludedDirectories;
    private array $excludedFiles;
    private array $excludedExtensions;

    /**
     * FileScannerConfig constructor.
     *
     * @param string $rootPath
     * @param array  $excludedDirectories
     * @param array  $excludedExtensions
     * @param array  $excludedFiles
     */
    public function __construct(
        string $rootPath,
        array $excludedDirectories,
        array $excludedExtensions,
        array $excludedFiles
    ) {
        $this->rootPath = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR;
        $this->chunkSize = self::CHUNK_SIZE;
        $this->excludedDirectories = $excludedDirectories;
        $this->excludedExtensions = $excludedExtensions;
        $this->excludedFiles = $excludedFiles;
    }

    /**
     * Gets the root path for the scan.
     *
     * @return string
     */
    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * Gets the chunk size for file scanning.
     *
     * @return int
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * Gets the excluded directories.
     *
     * @return array
     */
    public function getExcludedDirs(): array
    {
        return $this->excludedDirectories;
    }

    /**
     * Gets the excluded file extensions.
     *
     * @return array
     */
    public function getExcludedExtensions(): array
    {
        return $this->excludedExtensions;
    }

    /**
     * Gets the excluded files.
     *
     * @return array
     */
    public function getExcludedFiles(): array
    {
        return $this->excludedFiles;
    }
}
