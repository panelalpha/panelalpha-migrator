<?php

namespace WordpressMigrator\Services\Migration;

use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\Database;
use WordpressMigrator\Helpers\Filesystem;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Helpers\PathProvider;

/**
 * Class MigrationRegistry
 *
 * @package WordpressMigrator\Services\Migration
 *
 * Manages the state and progress of a migration.
 */
class MigrationRegistry
{
    private const STATUS_COLLECTING = 'collecting';
    private const STATUS_RESTORING = 'restoring';
    private const STATUS_RESTORED = 'restored';

    private const CHUNK_TYPE_FILE = 'file_chunk';
    private const CHUNK_TYPE_TABLE_STRUCTURE = 'table_structure';
    private const CHUNK_TYPE_TABLE_DATA = 'table_data';

    private const ITEM_STATUS_PENDING = 'pending';
    private const ITEM_STATUS_RECEIVING = 'receiving';
    private const ITEM_STATUS_RECEIVED = 'received';
    private const ITEM_STATUS_RESTORING = 'restoring';
    private const ITEM_STATUS_RESTORED = 'restored';

    /**
     * The status read once inside withStatusLock(), shared by every registry
     * of that migration, so one chunk does not decode the status file again and again.
     */
    private static ?string $lockedStatusFile = null;
    private static ?array $lockedStatus = null;

    private string $migrationId;
    private string $migrationDir;
    private string $statusFile;
    private string $migrationFile;
    private Database $db;

    /**
     * MigrationRegistry constructor.
     *
     * @param string $migrationId
     */
    public function __construct(string $migrationId)
    {
        if (!PathProvider::isSafeMigrationId($migrationId)) {
            throw new \InvalidArgumentException('Invalid migration ID');
        }

        $this->migrationId = $migrationId;
        $this->migrationDir = Config::get('migration_dir') . 'collected/' . $migrationId . '/';
        $this->statusFile = $this->migrationDir . 'status.json';
        $this->migrationFile = Config::get('migration_dir') . $migrationId . '.json';
        $this->db = Database::getInstance();
    }

    /**
     * Registers a new migration.
     *
     * @param array $migrationInfo
     * @return bool
     */
    public function registerMigration(array $migrationInfo): bool
    {
        $this->ensureMigrationDirectoryExists();

        $status = $this->buildInitialStatus($migrationInfo);
        $this->saveStatus($status);
        $this->saveFileChunkIndex($migrationInfo['files'] ?? []);

        return true;
    }

    /**
     * Runs one chunk under an exclusive lock on the status file, reading the
     * status once and writing it at most as often as it changes.
     *
     * @param callable $callback
     * @return mixed
     */
    public function withStatusLock(callable $callback)
    {
        $handle = is_dir($this->migrationDir) ? @fopen($this->migrationDir . 'status.lock', 'c') : false;

        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return $callback();
        }

        self::$lockedStatusFile = $this->statusFile;
        self::$lockedStatus = null;

        try {
            return $callback();
        } finally {
            self::$lockedStatusFile = null;
            self::$lockedStatus = null;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Marks a chunk as received.
     *
     * @param string $chunkType
     * @param array  $chunkData
     * @return bool
     */
    public function markChunkReceived(string $chunkType, array $chunkData): bool
    {
        $status = $this->getStatus();

        if (!$status) {
            return false;
        }

        $this->updateChunkReceivedStatus($status, $chunkType, $chunkData);
        $status['chunks_received']++;
        $this->saveStatus($status);

        return true;
    }

    /**
     * Marks a chunk as restored.
     *
     * @param string $chunkType
     * @param array  $chunkData
     * @return bool
     */
    public function markChunkRestored(string $chunkType, array $chunkData): bool
    {
        $status = $this->getStatus();

        if (!$status) {
            return false;
        }

        $this->updateChunkRestoredStatus($status, $chunkType, $chunkData);
        $this->saveStatus($status);

        return true;
    }

    /**
     * Gets the current status of the migration.
     *
     * @return array|null
     */
    public function getStatus(): ?array
    {
        $locked = self::$lockedStatusFile === $this->statusFile;

        if ($locked && self::$lockedStatus !== null) {
            return self::$lockedStatus;
        }

        if (!file_exists($this->statusFile)) {
            return null;
        }

        $json = file_get_contents($this->statusFile);
        $status = JsonCompressor::decompressFromJson($json);

        if ($locked) {
            self::$lockedStatus = $status;
        }

        return $status;
    }

    /**
     * Gets the migration information.
     *
     * @return array|null
     */
    public function getMigrationInfo(): ?array
    {
        if (!file_exists($this->migrationFile)) {
            return null;
        }

        $json = file_get_contents($this->migrationFile);
        return JsonCompressor::decompressFromJson($json);
    }

    /**
     * Updates the overall status of the migration.
     *
     * @param string $newStatus
     * @return bool
     */
    public function updateStatus(string $newStatus): bool
    {
        $status = $this->getStatus();

        if (!$status) {
            return false;
        }

        $status['status'] = $newStatus;
        $this->updateStatusTimestamps($status, $newStatus);
        $this->saveStatus($status);

        return true;
    }

    /**
     * Adds an error to the migration log.
     *
     * @param string $error
     * @return bool
     */
    public function addError(string $error): bool
    {
        $status = $this->getStatus();

        if (!$status) {
            return false;
        }

        $status['errors'][] = [
            'timestamp' => date('c'),
            'message' => $error
        ];

        $this->saveStatus($status);

        return true;
    }

    /**
     * Gets the restore progress.
     *
     * @return array
     */
    public function getRestoreProgress(): array
    {
        $status = $this->getStatus();

        if (!$status) {
            return [
                'status' => 'not_found',
                'percentage' => 0
            ];
        }

        return $this->calculateRestoreProgress($status);
    }

    /**
     * Returns the next stored chunk that still needs restoring.
     *
     * File and table-data chunks are taken in chunk-number order, because the
     * status file only counts how many chunks of each item were restored.
     * A table's structure is restored before its rows.
     *
     * @param string|array $types Chunk type, or a list of types
     * @return array|null
     */
    public function getNextChunkToRestore($types): ?array
    {
        if (!is_dir($this->migrationDir)) {
            return null;
        }

        $wanted = array_values((array) $types);
        $status = $this->getStatus() ?? ['files' => [], 'tables' => []];
        $ready = [];

        foreach ($this->loadStoredChunks($wanted) as $chunk) {
            if ($this->isNextRestorableChunk($chunk, $status)) {
                $ready[] = $chunk;
            }
        }

        if ($ready === []) {
            return null;
        }

        usort($ready, function (array $a, array $b): int {
            return $this->chunkSortKey($a) <=> $this->chunkSortKey($b);
        });

        return $ready[0];
    }

    /**
     * True when every file and table in the manifest has been restored.
     * An empty manifest is complete. A missing status is not.
     * Files the source reports as skipped count as complete.
     *
     * @param array $skippedFiles Relative paths the source skipped
     * @return bool
     */
    public function isRestoreComplete(array $skippedFiles = []): bool
    {
        $status = $this->getStatus();

        if (!$status) {
            return false;
        }

        $skipped = array_flip($this->knownSkippedFiles($status, $skippedFiles));

        foreach ($status['files'] as $path => $file) {
            $total = (int) ($file['total_chunks'] ?? 0);

            if ((int) ($file['restored_chunks'] ?? 0) < $total && !isset($skipped[(string) $path])) {
                return false;
            }
        }

        foreach ($status['tables'] as $table) {
            if (empty($table['structure_restored'])) {
                return false;
            }

            $total = (int) ($table['total_chunks'] ?? 0);

            if ((int) ($table['restored_chunks'] ?? 0) < $total) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keeps only the strings that are paths in the manifest.
     *
     * @param array $status
     * @param mixed $skippedFiles
     * @return string[]
     */
    public function knownSkippedFiles(array $status, $skippedFiles): array
    {
        if (!is_array($skippedFiles)) {
            return [];
        }

        $known = [];

        foreach ($skippedFiles as $path) {
            if (is_string($path) && isset($status['files'][$path])) {
                $known[] = $path;
            }
        }

        return array_values(array_unique($known));
    }

    /**
     * Whether this table chunk number was already counted as restored.
     *
     * @param string $tableName
     * @param int    $chunkNumber
     * @return bool
     */
    public function hasRestoredTableChunk(string $tableName, int $chunkNumber): bool
    {
        $status = $this->getStatus();
        $ids = $status['tables'][$tableName]['restored_chunk_ids'] ?? [];

        return isset($ids[(string) $chunkNumber]);
    }

    public function hasRestoredFileChunk(string $path, int $chunkNumber): bool
    {
        $status = $this->getStatus();
        $ids = $status['files'][$path]['restored_chunk_ids'] ?? [];

        return isset($ids[(string) $chunkNumber]);
    }

    /**
     * Expected offset and size for a file chunk, from the stored manifest.
     * Null when this migration has no manifest entry for the chunk.
     *
     * @param string $path
     * @param int    $chunkNumber
     * @return array|null
     */
    public function expectedFileChunk(string $path, int $chunkNumber): ?array
    {
        $indexJson = @file_get_contents($this->migrationDir . 'file_chunks.json');
        $index = is_string($indexJson) ? json_decode($indexJson, true) : null;

        if (is_array($index)) {
            $chunk = $index[$path][$chunkNumber - 1] ?? null;

            return is_array($chunk) && count($chunk) === 2
                ? ['offset' => (int) $chunk[0], 'size' => (int) $chunk[1], 'chunk_number' => $chunkNumber]
                : null;
        }

        $infoFile = $this->migrationDir . 'migration_info.json';

        if (!is_file($infoFile)) {
            return null;
        }

        $info = JsonCompressor::decompressFromJson((string) file_get_contents($infoFile));

        foreach (($info['files'] ?? []) as $file) {
            if (($file['path'] ?? '') !== $path) {
                continue;
            }

            $chunks = $file['chunks'] ?? [];
            $chunk = $chunks[$chunkNumber - 1] ?? null;

            if (!is_array($chunk) || !isset($chunk['offset'], $chunk['size'])) {
                return null;
            }

            return [
                'offset' => (int) $chunk['offset'],
                'size' => (int) $chunk['size'],
                'chunk_number' => $chunkNumber,
            ];
        }

        return null;
    }

    /**
     * Gets the source URL from migration info.
     *
     * @return string|null
     */
    public function getSourceUrl(): ?string
    {
        $infoFile = $this->migrationDir . 'migration_info.json';

        if (!file_exists($infoFile)) {
            return null;
        }

        $json = file_get_contents($infoFile);
        $info = JsonCompressor::decompressFromJson($json);

        return $info['site_url'] ?? null;
    }

    /**
     * Gets the source token from migration info.
     *
     * @return string|null
     */
    public function getSourceToken(): ?string
    {
        $infoFile = $this->migrationDir . 'migration_info.json';

        if (!file_exists($infoFile)) {
            return null;
        }

        $json = file_get_contents($infoFile);
        $info = JsonCompressor::decompressFromJson($json);

        // Try both snake_case and camelCase
        return $info['source_token'] ?? $info['sourceToken'] ?? null;
    }

    /**
     * Gets the source URL with token.
     *
     * @return string|null
     */
    public function getSourceUrlWithToken(): ?string
    {
        $sourceUrl = $this->getSourceUrl();
        $token = $this->getSourceToken();

        if (!$sourceUrl || !$token) {
            return $sourceUrl;
        }

        $separator = strpos($sourceUrl, '?') !== false ? '&' : '?';
        return $sourceUrl . $separator . 'token=' . urlencode($token);
    }

    /**
     * Loads stored chunks of the requested types from the migration directory.
     *
     * @param array $types
     * @return array
     */
    private function loadStoredChunks(array $types): array
    {
        $items = scandir($this->migrationDir);
        if ($items === false) {
            return [];
        }

        $skip = ['status.json', 'migration_info.json', 'migration_complete.json'];
        $chunks = [];

        foreach ($items as $item) {
            if (!str_ends_with($item, '.json') || in_array($item, $skip, true)) {
                continue;
            }

            $path = $this->migrationDir . $item;
            if (!is_file($path)) {
                continue;
            }

            $json = file_get_contents($path);
            if (!is_string($json) || $json === '') {
                continue;
            }

            $chunk = JsonCompressor::decompressFromJson($json);
            if (!isset($chunk['type']) || !in_array($chunk['type'], $types, true)) {
                continue;
            }

            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * Checks whether this chunk is the next one its file or table is waiting for.
     *
     * @param array $chunk
     * @param array $status
     * @return bool
     */
    private function isNextRestorableChunk(array $chunk, array $status): bool
    {
        $type = $chunk['type'] ?? '';

        if ($type === self::CHUNK_TYPE_FILE) {
            $path = $chunk['file']['path'] ?? null;
            if (!is_string($path) || $path === '') {
                return false;
            }

            $restored = (int) ($status['files'][$path]['restored_chunks'] ?? 0);

            return $this->chunkNumber($chunk) === $restored + 1;
        }

        $name = $chunk['table']['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return false;
        }

        $table = $status['tables'][$name] ?? [];

        if ($type === self::CHUNK_TYPE_TABLE_STRUCTURE) {
            return empty($table['structure_restored']);
        }

        if ($type === self::CHUNK_TYPE_TABLE_DATA) {
            if (empty($table['structure_restored'])) {
                return false;
            }

            $restored = (int) ($table['restored_chunks'] ?? 0);

            return $this->chunkNumber($chunk) === $restored + 1;
        }

        return false;
    }

    /**
     * Reads the sequential chunk number from a file or table chunk.
     *
     * @param array $chunk
     * @return int
     */
    private function chunkNumber(array $chunk): int
    {
        $info = $chunk['file']['chunk_info'] ?? $chunk['table']['chunk_info'] ?? [];

        return (int) ($info['chunk_number'] ?? $info['chunkNumber'] ?? 0);
    }

    /**
     * Sort key: one table at a time (structure, then rows), then files by path.
     *
     * @param array $chunk
     * @return array
     */
    private function chunkSortKey(array $chunk): array
    {
        $type = $chunk['type'] ?? '';

        if ($type === self::CHUNK_TYPE_TABLE_STRUCTURE) {
            return [0, (string) ($chunk['table']['name'] ?? ''), 0];
        }

        if ($type === self::CHUNK_TYPE_TABLE_DATA) {
            return [0, (string) ($chunk['table']['name'] ?? ''), 1, $this->chunkNumber($chunk)];
        }

        return [1, (string) ($chunk['file']['path'] ?? ''), $this->chunkNumber($chunk)];
    }

    /**
     * Ensures the migration directory exists.
     */
    private function ensureMigrationDirectoryExists(): void
    {
        if (!file_exists($this->migrationDir)) {
            Filesystem::mkdirP($this->migrationDir);
        }
    }

    /**
     * Builds the initial status array for the migration.
     *
     * @param array $migrationInfo
     * @return array
     */
    private function buildInitialStatus(array $migrationInfo): array
    {
        // Try different possible keys for estimated chunks (support both snake_case and camelCase)
        $estimatedChunks = $migrationInfo['summary']['estimated_chunks']
            ?? $migrationInfo['summary']['estimatedChunks']
            ?? 0;

        $status = [
            'migration_id' => $this->migrationId,
            'registered_at' => date('c'),
            'status' => self::STATUS_COLLECTING,
            'files' => [],
            'tables' => [],
            'chunks_received' => 0,
            'chunks_total' => $estimatedChunks,
            'files_restored' => 0,
            'tables_restored' => 0,
            'restore_started_at' => null,
            'restore_completed_at' => null,
            'errors' => []
        ];

        $status['files'] = $this->initializeFilesStatus($migrationInfo['files'] ?? []);
        $status['tables'] = $this->initializeTablesStatus($migrationInfo['tables'] ?? []);

        return $status;
    }

    /**
     * Initializes the status for files.
     *
     * @param array $files
     * @return array
     */
    private function initializeFilesStatus(array $files): array
    {
        $filesStatus = [];

        foreach ($files as $file) {
            $filesStatus[$file['path']] = [
                'total_chunks' => $file['total_chunks'],
                'received_chunks' => 0,
                'restored_chunks' => 0,
                'status' => self::ITEM_STATUS_PENDING,
                'file_created' => false
            ];
        }

        return $filesStatus;
    }

    /**
     * Initializes the status for tables.
     *
     * @param array $tables
     * @return array
     */
    private function initializeTablesStatus(array $tables): array
    {
        $tablesStatus = [];

        foreach ($tables as $table) {
            $tablesStatus[$table['name']] = [
                'total_chunks' => $table['total_chunks'],
                'received_chunks' => 0,
                'restored_chunks' => 0,
                'structure_received' => false,
                'structure_restored' => false,
                'status' => self::ITEM_STATUS_PENDING
            ];
        }

        return $tablesStatus;
    }

    /**
     * Updates the status of a received chunk.
     *
     * @param array  $status
     * @param string $chunkType
     * @param array  $chunkData
     */
    private function updateChunkReceivedStatus(array &$status, string $chunkType, array $chunkData): void
    {
        switch ($chunkType) {
            case self::CHUNK_TYPE_FILE:
                $this->updateFileChunkReceived($status, $chunkData);
                break;

            case self::CHUNK_TYPE_TABLE_STRUCTURE:
                $this->updateTableStructureReceived($status, $chunkData);
                break;

            case self::CHUNK_TYPE_TABLE_DATA:
                $this->updateTableDataReceived($status, $chunkData);
                break;
        }
    }

    /**
     * Updates the received status for a file chunk.
     *
     * @param array $status
     * @param array $chunkData
     */
    private function updateFileChunkReceived(array &$status, array $chunkData): void
    {
        $path = $chunkData['file']['path'];

        if (!isset($status['files'][$path])) {
            return;
        }

        $status['files'][$path]['received_chunks']++;

        $isComplete = $status['files'][$path]['received_chunks'] >= $status['files'][$path]['total_chunks'];
        $status['files'][$path]['status'] = $isComplete ? self::ITEM_STATUS_RECEIVED : self::ITEM_STATUS_RECEIVING;
    }

    /**
     * Updates the received status for a table structure chunk.
     *
     * @param array $status
     * @param array $chunkData
     */
    private function updateTableStructureReceived(array &$status, array $chunkData): void
    {
        $tableName = $chunkData['table']['name'];

        if (isset($status['tables'][$tableName])) {
            $status['tables'][$tableName]['structure_received'] = true;
        }
    }

    /**
     * Updates the received status for a table data chunk.
     *
     * @param array $status
     * @param array $chunkData
     */
    private function updateTableDataReceived(array &$status, array $chunkData): void
    {
        $tableName = $chunkData['table']['name'];

        if (!isset($status['tables'][$tableName])) {
            return;
        }

        $status['tables'][$tableName]['received_chunks']++;

        $isComplete = $status['tables'][$tableName]['received_chunks'] >= $status['tables'][$tableName]['total_chunks'];
        $status['tables'][$tableName]['status'] = $isComplete ? self::ITEM_STATUS_RECEIVED : self::ITEM_STATUS_RECEIVING;
    }

    /**
     * Updates the restored status of a chunk.
     *
     * @param array  $status
     * @param string $chunkType
     * @param array  $chunkData
     */
    private function updateChunkRestoredStatus(array &$status, string $chunkType, array $chunkData): void
    {
        switch ($chunkType) {
            case self::CHUNK_TYPE_FILE:
                $this->updateFileChunkRestored($status, $chunkData);
                break;

            case self::CHUNK_TYPE_TABLE_STRUCTURE:
                $this->updateTableStructureRestored($status, $chunkData);
                break;

            case self::CHUNK_TYPE_TABLE_DATA:
                $this->updateTableDataRestored($status, $chunkData);
                break;
        }
    }

    /**
     * Updates the restored status for a file chunk.
     *
     * @param array $status
     * @param array $chunkData
     */
    private function updateFileChunkRestored(array &$status, array $chunkData): void
    {
        $path = $chunkData['file']['path'];

        if (!isset($status['files'][$path])) {
            return;
        }

        $chunkNumber = $chunkData['file']['chunk_info']['chunk_number'] ?? null;

        if ($chunkNumber !== null) {
            $key = (string) $chunkNumber;

            if (isset($status['files'][$path]['restored_chunk_ids'][$key])) {
                return;
            }

            $status['files'][$path]['restored_chunk_ids'][$key] = true;
        }

        $status['files'][$path]['restored_chunks']++;

        $isComplete = $status['files'][$path]['restored_chunks'] >= $status['files'][$path]['total_chunks'];

        if ($isComplete) {
            $status['files'][$path]['status'] = self::ITEM_STATUS_RESTORED;
            $status['files_restored']++;
        } else {
            $status['files'][$path]['status'] = self::ITEM_STATUS_RESTORING;
        }
    }

    /**
     * Updates the restored status for a table structure chunk.
     *
     * @param array $status
     * @param array $chunkData
     */
    private function updateTableStructureRestored(array &$status, array $chunkData): void
    {
        $tableName = $chunkData['table']['name'];

        if (isset($status['tables'][$tableName])) {
            $status['tables'][$tableName]['structure_restored'] = true;
        }
    }

    /**
     * Updates the restored status for a table data chunk.
     *
     * @param array $status
     * @param array $chunkData
     */
    private function updateTableDataRestored(array &$status, array $chunkData): void
    {
        $tableName = $chunkData['table']['name'];

        if (!isset($status['tables'][$tableName])) {
            return;
        }

        $chunkNumber = $chunkData['table']['chunk_info']['chunk_number']
            ?? $chunkData['table']['chunk_info']['chunkNumber']
            ?? null;

        if ($chunkNumber !== null) {
            $key = (string) $chunkNumber;

            if (isset($status['tables'][$tableName]['restored_chunk_ids'][$key])) {
                return;
            }

            $status['tables'][$tableName]['restored_chunk_ids'][$key] = true;
        }

        $status['tables'][$tableName]['restored_chunks']++;

        $isComplete = $status['tables'][$tableName]['restored_chunks'] >= $status['tables'][$tableName]['total_chunks'];

        if ($isComplete) {
            $status['tables'][$tableName]['status'] = self::ITEM_STATUS_RESTORED;
            $status['tables_restored']++;
        } else {
            $status['tables'][$tableName]['status'] = self::ITEM_STATUS_RESTORING;
        }
    }

    /**
     * Updates the timestamps in the status.
     *
     * @param array  $status
     * @param string $newStatus
     */
    private function updateStatusTimestamps(array &$status, string $newStatus): void
    {
        if ($newStatus === self::STATUS_RESTORING && !$status['restore_started_at']) {
            $status['restore_started_at'] = date('c');
        }

        if ($newStatus === self::STATUS_RESTORED) {
            $status['restore_completed_at'] = date('c');
        }
    }

    /**
     * Saves the status to a file.
     *
     * @param array $status
     * @return bool
     */
    private function saveStatus(array $status): bool
    {
        $json = JsonCompressor::compressToJson($status);
        $saved = Filesystem::writeFileAtomic($this->statusFile, $json);

        if (self::$lockedStatusFile === $this->statusFile) {
            self::$lockedStatus = $saved ? $status : null;
        }

        return $saved;
    }

    /**
     * Writes the offset and size of every file chunk to a small index,
     * so checking one chunk does not decode the whole manifest.
     *
     * @param array $files
     */
    private function saveFileChunkIndex(array $files): void
    {
        $indexFile = $this->migrationDir . 'file_chunks.json';
        $index = [];

        foreach ($files as $file) {
            // Without every chunk list the manifest stays the only source.
            if (!isset($file['path']) || !is_array($file['chunks'] ?? null)) {
                @unlink($indexFile);
                return;
            }

            $index[$file['path']] = array_map(
                fn($chunk) => [(int) ($chunk['offset'] ?? 0), (int) ($chunk['size'] ?? 0)],
                $file['chunks']
            );
        }

        $json = json_encode($index, JSON_UNESCAPED_SLASHES);

        if ($json === false || !Filesystem::writeFileAtomic($indexFile, $json)) {
            @unlink($indexFile);
        }
    }

    /**
     * Calculates the restore progress.
     *
     * @param array $status
     * @return array
     */
    private function calculateRestoreProgress(array $status): array
    {
        $totalFiles = count($status['files']);
        $totalTables = count($status['tables']);
        $totalItems = $totalFiles + $totalTables;

        $restoredFiles = $status['files_restored'];
        $restoredTables = $status['tables_restored'];
        $restoredItems = $restoredFiles + $restoredTables;

        $percentage = $totalItems > 0 ? ($restoredItems / $totalItems) * 100 : 0;

        return [
            'status' => $status['status'],
            'total_files' => $totalFiles,
            'restored_files' => $restoredFiles,
            'total_tables' => $totalTables,
            'restored_tables' => $restoredTables,
            'chunks_received' => $status['chunks_received'],
            'chunks_total' => $status['chunks_total'],
            'percentage' => round($percentage, 2),
            'started_at' => $status['restore_started_at'],
            'completed_at' => $status['restore_completed_at'],
            'errors' => $status['errors']
        ];
    }
}
