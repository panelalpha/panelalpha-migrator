<?php

namespace WordpressMigrator\Services\Scanner;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use WordpressMigrator\Dto\FileChunkInfo;
use WordpressMigrator\Dto\FileInfo;

/**
 * Class FileScanner
 *
 * @package WordpressMigrator\Services\Scanner
 *
 * Scans the filesystem to gather information about files.
 */
class FileScanner
{
    private FileScannerConfig $config;
    private FileExclusionService $exclusionService;

    /**
     * FileScanner constructor.
     *
     * @param FileScannerConfig $config
     */
    public function __construct(FileScannerConfig $config)
    {
        $this->config = $config;
        $this->exclusionService = new FileExclusionService(
            $config->getExcludedDirs(),
            $config->getExcludedExtensions(),
            $config->getExcludedFiles()
        );
    }
    
    /**
     * Scans the filesystem for files.
     *
     * @return array
     */
    public function scan(): array
    {
        $files = [];
        $rootPath = $this->config->getRootPath();

        $directoryIterator = new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            $filePath = $fileInfo->getPathname();

            if ($this->exclusionService->isExcluded($filePath, $rootPath)) {
                if ($fileInfo->isDir()) {
                    $iterator->next();
                }
                continue;
            }

            if ($fileInfo->isFile() && $fileInfo->isReadable()) {
                $files[] = $this->createFileInfo($fileInfo, $rootPath);
            }
        }

        return $files;
    }

    /**
     * Creates a FileInfo object from an SplFileInfo object.
     *
     * @param SplFileInfo $fileInfo
     * @param string      $rootPath
     * @return FileInfo
     */
    private function createFileInfo(SplFileInfo $fileInfo, string $rootPath): FileInfo
    {
        $filePath = $fileInfo->getPathname();
        $size = $fileInfo->getSize();
        $permissions = substr(sprintf('%o', $fileInfo->getPerms()), -3);
        $modified = date('c', $fileInfo->getMTime());
        $relativePath = $this->getRelativePath($filePath, $rootPath);

        if (!$this->validateRelativePath($relativePath, $filePath, $rootPath)) {
            throw new \RuntimeException("Invalid relative path generated for: $filePath");
        }

        $chunks = $this->calculateFileChunks($size);
        $totalChunks = count($chunks);

        return new FileInfo(
            $relativePath,
            $size,
            $permissions,
            $modified,
            $chunks,
            $totalChunks
        );
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
        $filePath = str_replace('\\', '/', $filePath);
        $rootPath = str_replace('\\', '/', $rootPath);
        $rootPath = rtrim($rootPath, '/') . '/';

        if (strpos($filePath, $rootPath) === 0) {
            return ltrim(substr($filePath, strlen($rootPath)), '/');
        }

        foreach (['wp-content/', 'wp-includes/', 'wp-admin/'] as $wpDir) {
            $pos = strpos($filePath, $wpDir);
            if ($pos !== false) {
                return substr($filePath, $pos);
            }
        }

        return ltrim(str_replace($rootPath, '', $filePath), '/');
    }

    /**
     * Validates that relative path doesn't contain absolute path components.
     *
     * @param string $relativePath
     * @param string $filePath
     * @param string $rootPath
     * @return bool
     */
    private function validateRelativePath(string $relativePath, string $filePath, string $rootPath): bool
    {
        if (strpos($relativePath, '/srv/') === 0 ||
            strpos($relativePath, '/opt/') === 0 ||
            strpos($relativePath, '/home/') === 0 ||
            strpos($relativePath, '/var/') === 0 ||
            strpos($relativePath, '/bitnami/') === 0 ||
            strpos($relativePath, '/usr/') === 0) {
            return false;
        }

        if (preg_match('/^[A-Z]:\\\\/', $relativePath)) {
            return false;
        }

        return true;
    }

    /**
     * Calculates the chunks for a file based on its size.
     *
     * @param int $size
     * @return array
     */
    private function calculateFileChunks(int $size): array
    {
        $chunkSize = $this->config->getChunkSize();
        $totalChunks = $size > 0 ? ceil($size / $chunkSize) : 1;
        $chunks = [];

        for ($i = 0; $i < $totalChunks; $i++) {
            $offset = $i * $chunkSize;
            $currentChunkSize = min($chunkSize, $size - $offset);

            $chunks[] = new FileChunkInfo(
                $i + 1,
                $offset,
                $currentChunkSize
            );
        }

        return $chunks;
    }
}
