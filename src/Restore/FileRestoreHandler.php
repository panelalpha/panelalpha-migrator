<?php

namespace WordpressMigrator\Restore;

use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\Filesystem;
use WordpressMigrator\Services\Migration\MigrationRegistry;

/**
 * Class FileRestoreHandler
 *
 * @package WordpressMigrator\Restore
 *
 * Handles the restoration of file chunks.
 */
class FileRestoreHandler
{
    private MigrationRegistry $registry;
    private string $abspath;
    private array $excludedFiles;
    private FilePathConfinement $filePathConfinement;

    /**
     * FileRestoreHandler constructor.
     *
     * @param MigrationRegistry $registry
     * @param string            $abspath
     */
    public function __construct(MigrationRegistry $registry, string $abspath)
    {
        $this->registry = $registry;
        $this->abspath = $abspath;
        $this->filePathConfinement = new FilePathConfinement();
        $this->excludedFiles = [
            'wp-config.php',
            '.htaccess',
            'wp-content/migrations/',
            'wp-content/plugins/wordpress-migrator/',
        ];
    }

    /**
     * Restores a single file chunk.
     *
     * @param array $chunkData
     * @return array
     * @throws \Exception
     */
    public function restoreChunk(array $chunkData): array
    {
        $filePath = $this->filePathConfinement->confine($chunkData['file']['path'], $this->abspath);

        if ($this->isExcluded($filePath)) {
            return $this->skipFile($chunkData, $filePath, 'Critical file excluded from restore');
        }

        $content = $chunkData['content'];
        $checksum = $chunkData['checksum'];
        $permissions = $chunkData['file']['permissions'];
        $chunkInfo = $chunkData['file']['chunk_info'];

        $decodedContent = base64_decode($content);

        if ($decodedContent === false) {
            throw new \Exception('Failed to decode file content for: ' . $filePath);
        }

        $this->validateChecksum($decodedContent, $checksum, $filePath);

        $fullPath = $this->abspath . $filePath;
        $directory = dirname($fullPath);

        try {
            if (!$this->ensureDirectory($directory)) {
                return $this->skipFile($chunkData, $filePath, 'Failed to create directory: ' . $directory, false);
            }

            if (!is_writable($directory)) {
                return $this->skipFile($chunkData, $filePath, 'Directory not writable: ' . $directory, false);
            }

            if (!$this->writeContent($fullPath, $decodedContent, $chunkInfo['chunk_number'])) {
                return $this->skipFile($chunkData, $filePath, 'Failed to write file (disk full or permissions issue)', true);
            }

            if ($chunkInfo['chunk_number'] === $chunkInfo['total_chunks']) {
                $this->finalizeFile($fullPath, $permissions, $chunkData['file']['modified'] ?? null);
            }

            $this->registry->markChunkRestored('file_chunk', $chunkData);

            return [
                'action' => 'restored_file_chunk',
                'file' => $filePath,
                'chunk' => $chunkInfo['chunk_number']
            ];

        } catch (\Exception $e) {
            return $this->skipFile($chunkData, $filePath, 'Exception: ' . $e->getMessage(), false);
        }
    }

    /**
     * Checks if a file is excluded from restoration.
     *
     * @param string $filePath
     * @return bool
     */
    private function isExcluded(string $filePath): bool
    {
        foreach ($this->excludedFiles as $excluded) {
            if ($filePath === $excluded || strpos($filePath, $excluded) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates the checksum of the file content.
     *
     * @param string $content
     * @param string $expectedChecksum
     * @param string $filePath
     * @throws \Exception
     */
    private function validateChecksum(string $content, string $expectedChecksum, string $filePath): void
    {
        $actualChecksum = hash('sha256', $content);

        if ($actualChecksum !== $expectedChecksum) {
            throw new \Exception('Checksum mismatch for file: ' . $filePath);
        }
    }

    /**
     * Ensures a directory exists.
     *
     * @param string $directory
     * @return bool
     */
    private function ensureDirectory(string $directory): bool
    {
        if (file_exists($directory)) {
            return true;
        }

        $result = Filesystem::mkdirP($directory);

        return $result && is_dir($directory);
    }

    /**
     * Writes content to a file.
     *
     * @param string $fullPath
     * @param string $content
     * @param int    $chunkNumber
     * @return bool
     */
    private function writeContent(string $fullPath, string $content, int $chunkNumber): bool
    {
        if ($chunkNumber === 1) {
            $result = @file_put_contents($fullPath, $content);
        } else {
            $result = @file_put_contents($fullPath, $content, FILE_APPEND);
        }

        return $result !== false;
    }

    /**
     * Finalizes a file after all chunks have been written.
     *
     * @param string      $fullPath
     * @param string      $permissions
     * @param string|null $modified
     */
    private function finalizeFile(string $fullPath, string $permissions, ?string $modified): void
    {
        @chmod($fullPath, octdec($permissions));

        if ($modified) {
            $timestamp = strtotime($modified);
            if ($timestamp) {
                @touch($fullPath, $timestamp);
            }
        }
    }

    /**
     * Skips a file and logs the reason.
     *
     * @param array  $chunkData
     * @param string $filePath
     * @param string $reason
     * @param bool   $canRetry
     * @return array
     */
    private function skipFile(array $chunkData, string $filePath, string $reason, bool $canRetry = false): array
    {
        $this->registry->markChunkRestored('file_chunk', $chunkData);
        $this->logSkippedFile($filePath, $reason);

        return [
            'skipped' => true,
            'reason' => $reason,
            'file' => $filePath,
            'can_retry' => $canRetry
        ];
    }

    /**
     * Logs a skipped file.
     *
     * @param string $filePath
     * @param string $reason
     */
    private function logSkippedFile(string $filePath, string $reason): void
    {
        $migrationsDir = Config::get('migration_dir');

        if (!file_exists($migrationsDir)) {
            @mkdir($migrationsDir, 0755, true);
        }

        $migrationId = $this->registry->getStatus()['migration_id'] ?? 'unknown';
        $logFile = $migrationsDir . 'skipped_files_' . $migrationId . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] SKIPPED: {$filePath} - Reason: {$reason}\n";

        @file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}
