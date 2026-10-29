<?php

namespace PanelAlpha\Migrator\Restore;

use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\Filesystem;
use PanelAlpha\Migrator\Helpers\PathProvider;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;

/**
 * Class FileRestoreHandler
 *
 * @package PanelAlpha\Migrator\Restore
 *
 * Handles the restoration of file chunks. Code files are written to the
 * staging folder and move into place at the end (see StagedFiles). Other
 * files, such as uploads, are written in place.
 */
class FileRestoreHandler
{
    private MigrationRegistry $registry;
    private string $abspath;
    private array $excludedFiles;
    private FilePathConfinement $filePathConfinement;
    private ?StagedFiles $staged;

    /**
     * FileRestoreHandler constructor.
     *
     * @param MigrationRegistry $registry
     * @param string            $abspath
     * @param StagedFiles|null  $staged Built from the migration id when not given
     */
    public function __construct(MigrationRegistry $registry, string $abspath, ?StagedFiles $staged = null)
    {
        $this->registry = $registry;
        $this->abspath = $abspath;
        $this->staged = $staged;
        $this->filePathConfinement = new FilePathConfinement();
        $this->excludedFiles = [
            'wp-config.php',
            '.htaccess',
            'wp-content/migrations/',
            'wp-content/plugins/panelalpha-migrator/',
            // The plugin folder before the rename in 1.2.1.
            'wp-content/plugins/wordpress-migrator/',
        ];

        // This plugin's folder under the name it has on this site.
        $ownPrefix = $this->ownPluginPrefix();

        if ($ownPrefix !== null) {
            $this->excludedFiles[] = $ownPrefix;
        }
    }

    /**
     * The staged files of this migration, to move into place at the end.
     *
     * @return StagedFiles
     */
    public function staged(): StagedFiles
    {
        if ($this->staged === null) {
            $migrationId = (string) ($this->registry->getStatus()['migration_id'] ?? 'unknown');

            if (!PathProvider::isSafeMigrationId($migrationId)) {
                $migrationId = 'unknown';
            }

            $this->staged = new StagedFiles(
                $this->abspath,
                Filesystem::trailingSlashIt((string) Config::get('migration_dir')) . $migrationId,
                $this->excludedFiles,
                !empty($this->registry->getStatus()['keep_backup'])
            );
        }

        return $this->staged;
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

        if ($this->staged()->isDestinationDropIn($filePath)) {
            return $this->skipFile($chunkData, $filePath, 'Destination drop-in is kept');
        }

        if ($this->staged()->isStaged($filePath) && $this->staged()->hasReservedSuffix($filePath)) {
            return $this->skipFile($chunkData, $filePath, 'File name ends with ' . StagedFiles::SUFFIX . ', which is reserved for staged files');
        }

        $content = $chunkData['content'];
        $checksum = $chunkData['checksum'];
        $permissions = $chunkData['file']['permissions'];
        $chunkInfo = $chunkData['file']['chunk_info'];

        $decodedContent = base64_decode($content);

        if ($decodedContent === false) {
            throw new \Exception(esc_html('Failed to decode file content for: ' . $filePath));
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

        $staged = $this->staged()->isStaged($filePath);
        $fullPath = $staged ? $this->staged()->stagedFilePath($filePath) : $this->abspath . $filePath;
        $directory = dirname($fullPath);

        try {
            if ($staged && !$this->staged()->ensureDirectories()) {
                return $this->failChunk($filePath, 'Failed to create staging directory');
            }

            $roots = $staged ? [$this->staged()->stagedRoot()] : $this->staged()->liveRoots();

            if ($this->staged()->resolvesOutsideRoots($fullPath, $roots)) {
                return $this->failChunk($filePath, 'Path resolves outside the WordPress directory through a symlink');
            }

            if (!$this->ensureDirectory($directory)) {
                return $this->failChunk($filePath, 'Failed to create directory: ' . $directory);
            }

            if (!wp_is_writable($directory)) {
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
     * The folder of this plugin as a lowercase path relative to the WordPress
     * directory, from where the plugin runs. The folder name may differ from the default.
     *
     * @return string|null
     */
    private function ownPluginPrefix(): ?string
    {
        $own = realpath(PathProvider::getPluginDir());
        $roots = ['' => $this->abspath];

        if (defined('WP_CONTENT_DIR')) {
            $roots['wp-content/'] = WP_CONTENT_DIR;
        }

        foreach ($roots as $prefix => $root) {
            $root = realpath($root);

            if ($own !== false && $root !== false && strpos($own, rtrim($root, '/') . '/') === 0) {
                return strtolower($prefix . substr($own, strlen(rtrim($root, '/')) + 1)) . '/';
            }
        }

        return null;
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
            throw new \Exception(esc_html('Checksum mismatch for file: ' . $filePath));
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
        $handle = @fopen($fullPath, 'c+b'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writes one byte range of a file at its offset, which WP_Filesystem cannot do

        if ($handle === false) {
            return false;
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                return false;
            }

            $written = fwrite($handle, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writes one byte range of a file at its offset, which WP_Filesystem cannot do

            if ($written !== strlen($content)) {
                return false;
            }

            if ($chunkNumber === $total) {
                ftruncate($handle, $offset + strlen($content));
            }

            return true;
        } finally {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- writes one byte range of a file at its offset, which WP_Filesystem cannot do
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
            throw new \Exception(esc_html('Chunk number does not match the manifest for file: ' . $filePath));
        }

        if (isset($chunkInfo['size']) && (int) $chunkInfo['size'] !== $length) {
            throw new \Exception(esc_html('Chunk size does not match the manifest for file: ' . $filePath));
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
            throw new \Exception(esc_html('Chunk offset, size, or number does not match the manifest for file: ' . $filePath));
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
        $mode = (int) octdec($permissions) & 0777;

        // WP_Filesystem_Direct::chmod() reads a mode of 0 as "use the default", which needs FS_CHMOD_FILE.
        if ($mode !== 0) {
            Filesystem::direct()->chmod($fullPath, $mode);
        }

        if ($modified) {
            $timestamp = strtotime($modified);
            if ($timestamp) {
                @Filesystem::direct()->touch($fullPath, $timestamp);
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
            wp_mkdir_p($migrationsDir);
        }

        $migrationId = $this->registry->getStatus()['migration_id'] ?? 'unknown';
        $logFile = $migrationsDir . 'skipped_files_' . $migrationId . '.log';
        $timestamp = gmdate('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$label}: {$filePath} - Reason: {$reason}\n";

        @file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}
