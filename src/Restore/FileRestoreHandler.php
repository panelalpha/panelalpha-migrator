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
     * Deletes WordPress core files that the source does not have, so a
     * destination on another WordPress version keeps none of its old core.
     * Works only in wp-admin and wp-includes, and only when the migration
     * carried the core (wp-includes/version.php is in the manifest).
     *
     * @param array $migratedPaths Relative paths of all files in the manifest
     * @return int Number of deleted files
     */
    public function removeStaleCoreFiles(array $migratedPaths): int
    {
        $migrated = array_flip(array_map(fn($path) => ltrim(str_replace('\\', '/', (string) $path), '/'), $migratedPaths));
        $root = realpath($this->abspath);

        if (!isset($migrated['wp-includes/version.php']) || $root === false) {
            return 0;
        }

        $removed = 0;

        foreach (['wp-admin', 'wp-includes'] as $directory) {
            $base = $root . '/' . $directory;

            if (!is_dir($base) || is_link($base)) {
                continue;
            }

            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $path = $item->getPathname();

                if ($item->isDir() && !$item->isLink()) {
                    // Removes only folders left empty.
                    @rmdir($path);
                } elseif (!isset($migrated[substr($path, strlen($root) + 1)]) && @unlink($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
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
        $this->assertMatchesManifest($filePath, $chunkInfo, strlen($decodedContent));

        if ($this->chunkAlreadyRestored($filePath, (int) $chunkInfo['chunk_number'])) {
            return [
                'action' => 'restored_file_chunk',
                'file' => $filePath,
                'chunk' => $chunkInfo['chunk_number']
            ];
        }

        $fullPath = $this->abspath . $filePath;
        $directory = dirname($fullPath);

        try {
            if ($this->resolvesOutsideRoot($fullPath)) {
                return $this->failChunk($filePath, 'Path resolves outside the WordPress directory through a symlink');
            }

            if (!$this->ensureDirectory($directory)) {
                return $this->failChunk($filePath, 'Failed to create directory: ' . $directory);
            }

            if (!is_writable($directory)) {
                return $this->failChunk($filePath, 'Directory not writable: ' . $directory);
            }

            if (!$this->writeContent($fullPath, $decodedContent, $chunkInfo)) {
                return $this->failChunk($filePath, 'Failed to write file (disk full or permissions issue)');
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

        } catch (\Throwable $e) {
            return $this->failChunk($filePath, 'Exception: ' . $e->getMessage());
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
        $filePath = strtolower($filePath);

        foreach ($this->excludedFiles as $excluded) {
            if (strpos($filePath, $excluded) === 0) {
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
     * Looks at the disk: the target may be a symlink, or its nearest existing
     * parent may be a symlink that leads out of the WordPress directory.
     *
     * @param string $fullPath
     * @return bool
     */
    private function resolvesOutsideRoot(string $fullPath): bool
    {
        if (is_link($fullPath)) {
            return true;
        }

        $root = realpath($this->abspath);

        if ($root === false) {
            return true;
        }

        $parent = dirname($fullPath);

        while (!file_exists($parent)) {
            if (is_link($parent)) {
                return true;
            }

            $next = dirname($parent);

            if ($next === $parent) {
                return true;
            }

            $parent = $next;
        }

        $real = realpath($parent);

        return $real === false || ($real !== $root && !str_starts_with($real, rtrim($root, '/') . '/'));
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
     * Writes one chunk at its offset. A repeated write covers the same bytes
     * and does not append them again or truncate a later chunk.
     *
     * @param string $fullPath
     * @param string $content
     * @param array  $chunkInfo
     * @return bool
     */
    private function writeContent(string $fullPath, string $content, array $chunkInfo): bool
    {
        $chunkNumber = (int) $chunkInfo['chunk_number'];

        if (!array_key_exists('offset', $chunkInfo)) {
            if ($chunkNumber === 1) {
                $result = @file_put_contents($fullPath, $content);
            } else {
                $result = @file_put_contents($fullPath, $content, FILE_APPEND);
            }

            return $result !== false;
        }

        $offset = (int) $chunkInfo['offset'];
        $total = (int) ($chunkInfo['total_chunks'] ?? $chunkNumber);
        $handle = @fopen($fullPath, 'c+b');

        if ($handle === false) {
            return false;
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                return false;
            }

            $written = fwrite($handle, $content);

            if ($written !== strlen($content)) {
                return false;
            }

            if ($chunkNumber === $total) {
                ftruncate($handle, $offset + strlen($content));
            }

            return true;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Checks offset, size and chunk number against the stored manifest when one exists.
     *
     * @param string $filePath
     * @param array  $chunkInfo
     * @param int    $length
     * @throws \Exception
     */
    private function assertMatchesManifest(string $filePath, array $chunkInfo, int $length): void
    {
        $number = (int) ($chunkInfo['chunk_number'] ?? 0);
        $total = (int) ($chunkInfo['total_chunks'] ?? 0);

        if ($number < 1 || ($total > 0 && $number > $total)) {
            throw new \Exception('Chunk number does not match the manifest for file: ' . $filePath);
        }

        if (isset($chunkInfo['size']) && (int) $chunkInfo['size'] !== $length) {
            throw new \Exception('Chunk size does not match the manifest for file: ' . $filePath);
        }

        $expected = $this->registry->expectedFileChunk($filePath, $number);

        if ($expected === null) {
            return;
        }

        $offset = array_key_exists('offset', $chunkInfo) ? (int) $chunkInfo['offset'] : null;

        if ($offset !== (int) $expected['offset']
            || $length !== (int) $expected['size']
            || $number !== (int) $expected['chunk_number']
        ) {
            throw new \Exception('Chunk offset, size, or number does not match the manifest for file: ' . $filePath);
        }
    }

    /**
     * A chunk that was already applied is safe to receive again after a lost acknowledgement.
     *
     * @param string $filePath
     * @param int    $chunkNumber
     * @return bool
     */
    private function chunkAlreadyRestored(string $filePath, int $chunkNumber): bool
    {
        return $this->registry->hasRestoredFileChunk($filePath, $chunkNumber);
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
        @chmod($fullPath, octdec($permissions) & 0777);

        if ($modified) {
            $timestamp = strtotime($modified);
            if ($timestamp) {
                @touch($fullPath, $timestamp);
            }
        }
    }

    /**
     * Records an intentional exclusion. The chunk is not written, and the reason
     * is part of the acknowledgement so the source can show the gap.
     *
     * @param array  $chunkData
     * @param string $filePath
     * @param string $reason
     * @return array
     */
    private function skipFile(array $chunkData, string $filePath, string $reason): array
    {
        $this->registry->markChunkRestored('file_chunk', $chunkData);
        $this->logSkippedFile($filePath, $reason);

        return [
            'skipped' => true,
            'reason' => $reason,
            'file' => $filePath,
            'can_retry' => false
        ];
    }

    /**
     * A failed write stays pending so a later attempt can succeed once the cause is gone.
     *
     * @param string $filePath
     * @param string $reason
     * @return array
     */
    private function failChunk(string $filePath, string $reason): array
    {
        $this->logSkippedFile($filePath, $reason, 'FAILED');

        return [
            'failed' => true,
            'status' => 'pending',
            'reason' => $reason,
            'file' => $filePath,
            'can_retry' => true,
        ];
    }

    /**
     * Logs a skipped file.
     *
     * @param string $filePath
     * @param string $reason
     */
    private function logSkippedFile(string $filePath, string $reason, string $label = 'SKIPPED'): void
    {
        $migrationsDir = Config::get('migration_dir');

        if (!file_exists($migrationsDir)) {
            @mkdir($migrationsDir, 0755, true);
        }

        $migrationId = $this->registry->getStatus()['migration_id'] ?? 'unknown';
        $logFile = $migrationsDir . 'skipped_files_' . $migrationId . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$label}: {$filePath} - Reason: {$reason}\n";

        @file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}
