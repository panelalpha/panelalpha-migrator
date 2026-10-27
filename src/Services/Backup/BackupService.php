<?php

declare(strict_types=1);

namespace WordpressMigrator\Services\Backup;

use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Helpers\MigrationLock;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Helpers\JsonCompressor;

/**
 * Class BackupService
 *
 * @package WordpressMigrator\Services\Backup
 *
 * Service for handling backup operations with dependency injection.
 */
class BackupService
{
    private ?DatabaseScanner $dbScanner;
    private ?MigrationStorageService $storageService;
    private MigrationLock $lock;

    /**
     * BackupService constructor.
     *
     * @param DatabaseScanner|null $dbScanner Database scanner instance
     * @param MigrationStorageService|null $storageService Storage service instance
     */
    public function __construct(
        ?DatabaseScanner $dbScanner = null,
        ?MigrationStorageService $storageService = null,
        ?MigrationLock $lock = null
    ) {
        $this->dbScanner = $dbScanner;
        $this->storageService = $storageService;
        $this->lock = $lock ?? new MigrationLock();
    }

    /**
     * Gets the next database chunk for a migration.
     *
     * @param string $migrationId Migration identifier
     * @return array|null Chunk data or null
     */
    public function getDatabaseNextChunk(string $migrationId): ?array
    {
        return $this->mutate($migrationId, function () use ($migrationId) {
            return $this->createDatabaseBackupService($migrationId)->getNextChunk();
        });
    }

    /**
     * Creates a DatabaseBackupService instance.
     *
     * @param string $migrationId Migration identifier
     * @return DatabaseBackupService
     */
    private function createDatabaseBackupService(string $migrationId): DatabaseBackupService
    {
        return new DatabaseBackupService(
            $migrationId,
            $this->dbScanner,
            $this->storageService
        );
    }

    /**
     * Creates a FileBackupService instance.
     *
     * @param string $migrationId Migration identifier
     * @return FileBackupService
     */
    private function createFileBackupService(string $migrationId): FileBackupService
    {
        return new FileBackupService(
            $migrationId,
            $this->storageService
        );
    }

    /**
     * Gets multiple database chunks in a single batch for a migration.
     *
     * @param string $migrationId Migration identifier
     * @param int $batchSize Number of chunks to process in this batch
     * @return array Batch processing result
     */
    public function getDatabaseNextChunksBatch(string $migrationId, int $batchSize = 50): array
    {
        return $this->mutate($migrationId, function () use ($migrationId, $batchSize) {
            $backup = $this->createDatabaseBackupService($migrationId);
            return $this->processChunksBatch($backup, $batchSize);
        });
    }

    /**
     * Gets the database backup progress.
     *
     * @param string $migrationId Migration identifier
     * @param string|null $currentTableName Optional current table name being processed
     * @return array Progress information
     */
    public function getDatabaseProgress(string $migrationId, ?string $currentTableName = null): array
    {
        $backup = $this->createDatabaseBackupService($migrationId);
        return $backup->getProgress($currentTableName);
    }

    /**
     * Gets the next file chunk for a migration.
     *
     * @param string $migrationId Migration identifier
     * @return array|null Chunk data or null
     */
    public function getFileNextChunk(string $migrationId): ?array
    {
        return $this->mutate($migrationId, function () use ($migrationId) {
            return $this->createFileBackupService($migrationId)->getNextChunk();
        });
    }

    /**
     * Gets multiple file chunks in a single batch for a migration.
     *
     * @param string $migrationId Migration identifier
     * @param int $batchSize Number of chunks to process in this batch
     * @return array Batch processing result
     */
    public function getFileNextChunksBatch(string $migrationId, int $batchSize = 50): array
    {
        return $this->mutate($migrationId, function () use ($migrationId, $batchSize) {
            $backup = $this->createFileBackupService($migrationId);
            return $this->processChunksBatch($backup, $batchSize);
        });
    }

    /**
     * Processes chunks in batch for any backup service.
     *
     * @param DatabaseBackupService|FileBackupService $backup Backup service instance
     * @param int $batchSize Number of chunks to process
     * @return array Batch processing result
     */
    private function processChunksBatch($backup, int $batchSize): array
    {
        $processedCount = $backup->inBatch(function () use ($backup, $batchSize): int {
            $count = 0;

            while ($count < $batchSize && $backup->getNextChunk() !== null) {
                $count++;
            }

            return $count;
        });

        $progress = $backup->getProgress();

        return [
            'success' => true,
            'processed_chunks' => $processedCount,
            'completed' => ($progress['is_complete'] ?? false) === true,
            'progress' => $progress
        ];
    }

    /**
     * Gets the file backup progress.
     *
     * @param string $migrationId Migration identifier
     * @param string|null $currentFilePath Optional current file path being processed
     * @return array Progress information
     */
    public function getFileProgress(string $migrationId, ?string $currentFilePath = null): array
    {
        $backup = $this->createFileBackupService($migrationId);
        return $backup->getProgress($currentFilePath);
    }

    /**
     * Gets the overall backup status for a migration.
     *
     * @param string $migrationId Migration identifier
     * @return array Backup status information
     */
    public function getBackupStatus(string $migrationId): array
    {
        $migrationFilePath = PathProvider::getMigrationFilePath($migrationId);

        if (!file_exists($migrationFilePath)) {
            return $this->buildNotFoundStatus($migrationId);
        }

        $migrationData = $this->loadMigrationData($migrationFilePath);

        return $this->buildBackupStatus($migrationId, $migrationData, $migrationFilePath);
    }

    /**
     * Builds status response for not found migration.
     *
     * @param string $migrationId Migration identifier
     * @return array Not found status
     */
    private function buildNotFoundStatus(string $migrationId): array
    {
        return [
            'migration_id' => $migrationId,
            'status' => 'not_found',
            'database' => null,
            'files' => null,
            'last_activity' => null,
            'backup_running' => false
        ];
    }

    /**
     * Loads migration data from file.
     *
     * @param string $migrationFilePath File path
     * @return array Migration data
     */
    private function loadMigrationData(string $migrationFilePath): array
    {
        return JsonCompressor::decompressFromJson(file_get_contents($migrationFilePath));
    }

    /**
     * Builds complete backup status.
     *
     * @param string $migrationId Migration identifier
     * @param array $migrationData Migration data
     * @param string $migrationFilePath File path
     * @return array Complete backup status
     */
    private function buildBackupStatus(string $migrationId, array $migrationData, string $migrationFilePath): array
    {
        $databaseStatus = $this->analyzeDatabaseStatus($migrationData);
        $filesStatus = $this->analyzeFilesStatus($migrationData);
        $overallStatus = $this->determineOverallStatus($databaseStatus, $filesStatus);
        $lastActivity = $this->getLastActivityTimestamp($migrationFilePath);
        $backupRunning = $migrationData['backup_running'] ?? false;

        return [
            'migration_id' => $migrationId,
            'status' => $overallStatus,
            'database' => $databaseStatus,
            'files' => $filesStatus,
            'last_activity' => $lastActivity,
            'is_interrupted' => $this->isInterrupted($overallStatus, $lastActivity, $backupRunning),
            'backup_running' => $backupRunning,
            'destination_url' => $this->extractDestinationUrl($migrationData)
        ];
    }

    /**
     * Extracts destination URL from migration data.
     *
     * @param array $migrationData Migration data
     * @return string|null Destination URL or null
     */
    private function extractDestinationUrl(array $migrationData): ?string
    {
        return $migrationData['destinationUrl']
            ?? $migrationData['destination_url']
            ?? $migrationData['du']
            ?? null;
    }

    /**
     * Sets the backup running state for a migration.
     *
     * @param string $migrationId Migration identifier
     * @param bool   $running Running state
     * @throws \Exception
     */
    public function setBackupRunning(string $migrationId, bool $running): void
    {
        $storage = $this->storageService ?? new \WordpressMigrator\Services\Migration\MigrationStorageService();

        if (!$storage->migrationExists($migrationId)) {
            throw new \Exception('Migration file not found');
        }

        $work = function () use ($storage, $migrationId, $running) {
            $migrationData = $storage->loadMigrationData($migrationId);
            $migrationData['backup_running'] = $running;
            $migrationData['backup_last_update'] = date('c');
            $storage->saveMigrationData($migrationId, $migrationData);
        };

        // Clearing the flag must work after the lock is gone, or it stays true forever.
        if (!$running && !$this->holdsLock($migrationId)) {
            $work();
            return;
        }

        $this->mutate($migrationId, $work);
    }

    /**
     * Starts the dashboard backup: takes the migration lock and marks the backup as running.
     *
     * @param string $migrationId Migration identifier
     * @throws \Exception When the migration is unknown or another migration holds the lock
     */
    public function startBackup(string $migrationId): void
    {
        $storage = $this->storageService ?? new \WordpressMigrator\Services\Migration\MigrationStorageService();

        if (!$storage->migrationExists($migrationId)) {
            throw new \Exception('Migration file not found');
        }

        $acquired = false;
        if (!$this->holdsLock($migrationId)) {
            if (!$this->lock->acquire($migrationId)) {
                throw new \Exception('Failed to acquire migration lock. Another migration may be running.');
            }
            $acquired = true;
        }

        try {
            $this->setBackupRunning($migrationId, true);
        } catch (\Throwable $e) {
            if ($acquired) {
                $this->lock->release($migrationId);
            }
            throw $e;
        }
    }

    /**
     * Stops the dashboard backup and clears the running flag.
     * The lock is released unless the caller is about to finalize, which releases it.
     *
     * @param string $migrationId Migration identifier
     * @param bool   $keepLock    Keep the lock for the finalize step
     * @throws \Exception
     */
    public function stopBackup(string $migrationId, bool $keepLock = false): void
    {
        $this->setBackupRunning($migrationId, false);

        if (!$keepLock) {
            $this->lock->release($migrationId);
        }
    }

    private function holdsLock(string $migrationId): bool
    {
        $info = $this->lock->getLockInfo();

        return is_array($info) && ($info['migration_id'] ?? null) === $migrationId;
    }

    /**
     * One owner mutates a migration. The API batch path and the dashboard
     * backup path both come through here and share the same lock file.
     *
     * @param string   $migrationId
     * @param callable $work
     * @return mixed
     */
    private function mutate(string $migrationId, callable $work)
    {
        return $this->lock->exclusive($migrationId, $work);
    }

    /**
     * Analyzes the database backup status from migration data.
     *
     * @param array $migrationData Migration data array
     * @return array|null Database status or null
     */
    private function analyzeDatabaseStatus(array $migrationData): ?array
    {
        if (array_key_exists('backup_database', $migrationData) && $migrationData['backup_database'] === false) {
            return [
                'total_tables' => 0,
                'completed_tables' => 0,
                'total_chunks' => 0,
                'sent_chunks' => 0,
                'percentage' => 0,
                'status' => 'disabled',
                'current_table' => null,
                'next_table' => null,
            ];
        }

        if (!isset($migrationData['tables']) || empty($migrationData['tables'])) {
            return null;
        }

        $stats = $this->calculateItemsStats($migrationData['tables']);

        return [
            'total_tables' => $stats['total_items'],
            'completed_tables' => $stats['completed_items'],
            'total_chunks' => $stats['total_chunks'],
            'sent_chunks' => $stats['sent_chunks'],
            'percentage' => $stats['percentage'],
            'status' => $stats['status'],
            'current_table' => $stats['current_item'] ?? null,
            'next_table' => $stats['next_item'] ?? null
        ];
    }

    /**
     * Analyzes the file backup status from migration data.
     *
     * @param array $migrationData Migration data array
     * @return array|null Files status or null
     */
    private function analyzeFilesStatus(array $migrationData): ?array
    {
        if (array_key_exists('backup_files', $migrationData) && $migrationData['backup_files'] === false) {
            return [
                'total_files' => 0,
                'completed_files' => 0,
                'total_chunks' => 0,
                'sent_chunks' => 0,
                'percentage' => 0,
                'status' => 'disabled',
                'current_file' => null,
                'next_file' => null,
                'skipped_files' => [],
            ];
        }

        if (!isset($migrationData['files']) || empty($migrationData['files'])) {
            return null;
        }

        $stats = $this->calculateItemsStats($migrationData['files']);

        $skippedFiles = array_values(array_map(
            function ($f) { return $f['path']; },
            array_filter($migrationData['files'], function ($f) { return ($f['status'] ?? '') === 'skipped'; })
        ));

        return [
            'total_files' => $stats['total_items'],
            'completed_files' => $stats['completed_items'],
            'total_chunks' => $stats['total_chunks'],
            'sent_chunks' => $stats['sent_chunks'],
            'percentage' => $stats['percentage'],
            'status' => $stats['status'],
            'current_file' => $stats['current_item'] ?? null,
            'next_file' => $stats['next_item'] ?? null,
            'skipped_files' => $skippedFiles
        ];
    }

    /**
     * Calculates statistics for items (tables or files).
     *
     * @param array $items Array of items (tables or files)
     * @return array Statistics array
     */
    private function calculateItemsStats(array $items): array
    {
        $totalItems = count($items);
        $completedItems = 0;
        $totalChunks = 0;
        $sentChunks = 0;
        $inProgress = false;
        $currentItem = null;
        $nextItem = null;
        $passedCurrent = false;

        foreach ($items as $item) {
            $itemId = $item['name'] ?? $item['path'] ?? null;

            if (($item['status'] ?? '') === 'skipped') {
                $completedItems++;
                continue;
            }

            $totalChunks += $item['totalChunks'];
            $sentChunks += $this->countSentChunksInItem($item);

            $itemStatus = $this->updateItemCounters($item['status'], $completedItems, $inProgress);
            if ($itemStatus === 'in_progress') {
                $inProgress = true;
            }

            if ($currentItem === null && ($item['status'] === 'in_progress' || $item['status'] === 'pending')) {
                $currentItem = $itemId;
            }
            if ($currentItem !== null && $itemId === $currentItem) {
                $passedCurrent = true;
            }
            if ($passedCurrent && $nextItem === null && $item['status'] === 'pending') {
                $nextItem = $itemId;
            }
        }

        $percentage = $this->calculatePercentage($totalChunks, $sentChunks);
        $status = $this->determineItemStatus($sentChunks, $totalChunks, $inProgress);

        return [
            'total_items' => $totalItems,
            'completed_items' => $completedItems,
            'total_chunks' => $totalChunks,
            'sent_chunks' => $sentChunks,
            'percentage' => $percentage,
            'status' => $status,
            'current_item' => $currentItem,
            'next_item' => $nextItem
        ];
    }

    /**
     * Counts sent chunks in a single item.
     *
     * @param array $item Item (table or file) data
     * @return int Number of sent chunks
     */
    private function countSentChunksInItem(array $item): int
    {
        $count = 0;

        foreach ($item['chunks'] as $chunk) {
            if ($chunk['status'] === 'sent') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Updates item counters based on status.
     *
     * @param string $status Item status
     * @param int $completedItems Completed items counter (passed by reference)
     * @param bool $inProgress In progress flag (passed by reference)
     * @return string Item status
     */
    private function updateItemCounters(string $status, int &$completedItems, bool &$inProgress): string
    {
        if ($status === 'sent') {
            $completedItems++;
        } elseif ($status === 'in_progress') {
            $inProgress = true;
        }

        return $status;
    }

    /**
     * Calculates percentage of completion.
     *
     * @param int $total Total number
     * @param int $sent Sent number
     * @return float Percentage (0-100)
     */
    private function calculatePercentage(int $total, int $sent): float
    {
        return $total > 0 ? round(($sent / $total) * 100, 2) : 0;
    }

    /**
     * Determines the status of a backup item (database or files).
     *
     * @param int  $sent
     * @param int  $total
     * @param bool $inProgress
     * @return string
     */
    private function determineItemStatus(int $sent, int $total, bool $inProgress): string
    {
        if ($total === 0) {
            return 'completed';
        } elseif ($sent === 0) {
            return 'pending';
        } elseif ($sent === $total) {
            return 'completed';
        } elseif ($inProgress) {
            return 'in_progress';
        } else {
            return 'interrupted';
        }
    }

    /**
     * Determines the overall backup status.
     *
     * @param array|null $dbStatus Database status
     * @param array|null $filesStatus Files status
     * @return string Overall status
     */
    private function determineOverallStatus(?array $dbStatus, ?array $filesStatus): string
    {
        $statuses = $this->extractStatuses($dbStatus, $filesStatus);

        if (empty($statuses)) {
            return 'idle';
        }

        return $this->findHighestPriorityStatus($statuses);
    }

    /**
     * Extracts statuses from database and files status arrays.
     *
     * @param array|null $dbStatus Database status
     * @param array|null $filesStatus Files status
     * @return array Array of statuses
     */
    private function extractStatuses(?array $dbStatus, ?array $filesStatus): array
    {
        return array_values(array_filter(
            [
                $dbStatus['status'] ?? null,
                $filesStatus['status'] ?? null,
            ],
            static fn ($status): bool => is_string($status) && $status !== '' && $status !== 'disabled'
        ));
    }

    /**
     * Finds the highest priority status from array of statuses.
     *
     * @param array $statuses Array of status strings
     * @return string Highest priority status
     */
    private function findHighestPriorityStatus(array $statuses): string
    {
        $priorityOrder = ['in_progress', 'interrupted', 'pending', 'completed'];

        foreach ($priorityOrder as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return 'completed';
    }

    /**
     * Gets the last activity timestamp for a migration file.
     *
     * @param string $filePath
     * @return string|null
     */
    private function getLastActivityTimestamp(string $filePath): ?string
    {
        $mtime = filemtime($filePath);
        return $mtime ? date('c', $mtime) : null;
    }

    /**
     * Checks if a backup is interrupted.
     *
     * @param string      $status
     * @param string|null $lastActivity
     * @param bool        $backupRunning
     * @return bool
     */
    private function isInterrupted(string $status, ?string $lastActivity, bool $backupRunning): bool
    {
        if ($backupRunning) {
            return false;
        }

        if ($status === 'in_progress' || $status === 'interrupted') {
            return true;
        }

        return false;
    }
}
