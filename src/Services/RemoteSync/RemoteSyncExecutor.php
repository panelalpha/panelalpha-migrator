<?php

namespace WordpressMigrator\Services\RemoteSync;

use Exception;
use WordpressMigrator\Services\Backup\BackupService;

/**
 * Executes the full remote sync backup process.
 *
 * @package WordpressMigrator\Services\RemoteSync
 */
class RemoteSyncExecutor
{
    private BackupService $backupService;

    public function __construct(BackupService $backupService)
    {
        $this->backupService = $backupService;
    }

    /**
     * Execute the full backup process for a migration.
     *
     * @param string $migrationId Migration ID
     * @param string $destinationUrl Destination URL
     * @param string $token Destination token
     * @param bool   $backupDatabase Whether to backup database
     * @param bool   $backupFiles Whether to backup files
     * @return array Result with success status and summary
     * @throws Exception
     */
    public function executeBackup(
        string $migrationId,
        string $destinationUrl,
        string $token,
        bool $backupDatabase = true,
        bool $backupFiles = true
    ): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
        }

        $this->backupService->setBackupRunning($migrationId, true);

        try {
            $dbResult = null;
            if ($backupDatabase) {
                $dbResult = $this->processDatabaseBackup($migrationId, $destinationUrl, $token);
            }

            $filesResult = null;
            if ($backupFiles) {
                $filesResult = $this->processFilesBackup($migrationId, $destinationUrl, $token);
            }

            $this->backupService->setBackupRunning($migrationId, false);

            return [
                'success' => true,
                'database' => $dbResult,
                'files' => $filesResult
            ];

        } catch (Exception $e) {
            $this->backupService->setBackupRunning($migrationId, false);
            throw $e;
        }
    }

    /**
     * Process all database chunks.
     *
     * @param string $migrationId Migration ID
     * @param string $destinationUrl Destination URL (not used - chunks auto-send)
     * @param string $token Destination token (not used - chunks auto-send)
     * @return array Database backup result
     * @throws Exception
     */
    private function processDatabaseBackup(string $migrationId, string $destinationUrl, string $token): array
    {
        $totalChunks = 0;

        while (true) {
            $chunk = $this->backupService->getDatabaseNextChunk($migrationId);

            if ($chunk === null) {
                break;
            }

            $totalChunks++;

            if ($totalChunks % 10 === 0) {
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
            }
        }

        return [
            'total_chunks' => $totalChunks,
            'sent_chunks' => $totalChunks,
            'status' => 'completed'
        ];
    }

    /**
     * Process all file chunks.
     *
     * @param string $migrationId Migration ID
     * @param string $destinationUrl Destination URL (not used - chunks auto-send)
     * @param string $token Destination token (not used - chunks auto-send)
     * @return array Files backup result
     * @throws Exception
     */
    private function processFilesBackup(string $migrationId, string $destinationUrl, string $token): array
    {
        $totalChunks = 0;

        while (true) {
            $chunk = $this->backupService->getFileNextChunk($migrationId);

            if ($chunk === null) {
                break;
            }

            $totalChunks++;

            if ($totalChunks % 10 === 0) {
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
            }
        }

        return [
            'total_chunks' => $totalChunks,
            'sent_chunks' => $totalChunks,
            'status' => 'completed'
        ];
    }
}
