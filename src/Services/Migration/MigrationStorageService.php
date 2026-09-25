<?php

declare(strict_types=1);

namespace WordpressMigrator\Services\Migration;

use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Helpers\Filesystem;
use WordpressMigrator\Helpers\JsonCompressor;

/**
 * Class MigrationStorageService
 *
 * @package WordpressMigrator\Services\Migration
 *
 * Handles storage and retrieval of migration data.
 */
class MigrationStorageService
{
    /**
     * Gets the file path for a migration.
     *
     * @param string $migrationId Migration identifier
     * @return string Full file path
     */
    public function getMigrationFilePath(string $migrationId): string
    {
        return PathProvider::getMigrationFilePath($migrationId);
    }

    /**
     * Saves migration data to a file.
     *
     * @param string $migrationId Migration identifier
     * @param array  $migrationDataArray Migration data to save
     * @throws \RuntimeException If file cannot be saved
     */
    public function saveMigrationData(string $migrationId, array $migrationDataArray): void
    {
        $filePath = $this->getMigrationFilePath($migrationId);
        $migrationDir = dirname($filePath);

        Filesystem::ensureDirectoryExists($migrationDir);

        $json = JsonCompressor::compressToJson($migrationDataArray, JSON_UNESCAPED_SLASHES);
        $result = @file_put_contents($filePath, $json);

        if ($result === false) {
            throw new \RuntimeException("Failed to save migration data to: {$filePath}");
        }
    }

    /**
     * Checks if a migration exists.
     *
     * @param string $migrationId
     * @return bool
     */
    public function migrationExists(string $migrationId): bool
    {
        $filePath = $this->getMigrationFilePath($migrationId);
        return file_exists($filePath);
    }

    /**
     * Loads migration data from a file.
     *
     * @param string $migrationId
     * @return array
     */
    public function loadMigrationData(string $migrationId): array
    {
        $filePath = $this->getMigrationFilePath($migrationId);
        $json = file_get_contents($filePath);
        return JsonCompressor::decompressFromJson($json);
    }
    
    /**
     * Gets the directory where collected chunks are stored.
     *
     * @return string Collected directory path
     */
    public function getCollectedDirectory(): string
    {
        return PathProvider::getCollectedDir();
    }
    
    /**
     * Lists all migrations.
     *
     * @return array
     */
    public function listMigrations(): array
    {
        $collectDir = $this->getCollectedDirectory();

        if (!file_exists($collectDir)) {
            return [];
        }

        $migrations = $this->collectMigrationsList($collectDir);
        
        return $this->sortMigrationsByTimestamp($migrations);
    }

    /**
     * Collects a list of migrations from the collected directory.
     *
     * @param string $collectDir
     * @return array
     */
    private function collectMigrationsList(string $collectDir): array
    {
        $migrations = [];
        $items = scandir($collectDir);

        foreach ($items as $item) {
            if ($this->shouldSkipItem($item)) {
                continue;
            }

            $migrationDir = $collectDir . $item;

            if (!is_dir($migrationDir)) {
                continue;
            }

            $migrationInfo = $this->extractMigrationInfo($migrationDir, $item);

            if ($migrationInfo) {
                $migrations[] = $migrationInfo;
            }
        }

        return $migrations;
    }

    /**
     * Checks if a directory item should be skipped.
     *
     * @param string $item
     * @return bool
     */
    private function shouldSkipItem(string $item): bool
    {
        return $item === '.' || $item === '..';
    }

    /**
     * Extracts information about a single migration.
     *
     * @param string $migrationDir
     * @param string $item
     * @return array|null
     */
    private function extractMigrationInfo(string $migrationDir, string $item): ?array
    {
        $infoFile = $migrationDir . '/migration_info.json';

        if (!file_exists($infoFile)) {
            return null;
        }

        $json = file_get_contents($infoFile);
        $info = JsonCompressor::decompressFromJson($json);
        $status = $this->loadMigrationStatus($migrationDir);

        return [
            'migration_id' => $item,
            'timestamp' => $info['timestamp'] ?? null,
            'source_url' => $info['site_url'] ?? 'Unknown',
            'status' => $status['status'] ?? 'collected',
            'files_total' => count($info['files'] ?? []),
            'tables_total' => count($info['tables'] ?? []),
            'files_restored' => $status['files_restored'] ?? 0,
            'tables_restored' => $status['tables_restored'] ?? 0
        ];
    }

    /**
     * Loads the status of a migration.
     *
     * @param string $migrationDir
     * @return array|null
     */
    private function loadMigrationStatus(string $migrationDir): ?array
    {
        $statusFile = $migrationDir . '/status.json';

        if (!file_exists($statusFile)) {
            return null;
        }

        $json = file_get_contents($statusFile);
        if (!is_string($json) || $json === '') {
            return null;
        }

        $status = JsonCompressor::decompressFromJson($json);

        return $status === [] ? null : $status;
    }

    /**
     * Sorts migrations by timestamp in descending order.
     *
     * @param array $migrations
     * @return array
     */
    private function sortMigrationsByTimestamp(array $migrations): array
    {
        usort($migrations, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        return $migrations;
    }
}
