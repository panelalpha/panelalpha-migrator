<?php

namespace WordpressMigrator\Services\Backup;

use WordpressMigrator\Dto\ChunkInfo;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\Filesystem;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Services\Migration\MigrationRegistry;

/**
 * Class ChunkService
 *
 * @package WordpressMigrator\Services\Backup
 *
 * Service for handling backup chunks.
 */
class ChunkService
{
    /**
     * Validates chunk data.
     *
     * @param array $chunkData
     * @return bool
     */
    public function isValidChunkData(array $chunkData): bool
    {
        return !empty($chunkData) && isset($chunkData['type']);
    }

    /**
     * Saves a chunk to a file.
     *
     * @param string $migrationId
     * @param array  $chunkData
     * @return string
     */
    public function saveChunk(string $migrationId, array $chunkData): string
    {
        $migrationDir = $this->ensureMigrationDirectory($migrationId);
        $filename = $this->generateChunkFilename($chunkData);
        $this->saveChunkToFile($migrationDir . $filename, $chunkData);

        return $filename;
    }

    /**
     * Registers a chunk in the migration registry.
     *
     * @param string $migrationId
     * @param array  $chunkData
     */
    public function registerChunk(string $migrationId, array $chunkData): void
    {
        $registry = new MigrationRegistry($migrationId);

        if ($chunkData['type'] === 'info') {
            $registry->registerMigration($chunkData);
        } else {
            $registry->markChunkReceived($chunkData['type'], $chunkData);
        }
    }

    /**
     * Ensures the migration directory exists.
     *
     * @param string $migrationId
     * @return string
     */
    private function ensureMigrationDirectory(string $migrationId): string
    {
        $collectDir = Config::get('migration_dir') . 'collected/';
        $this->ensureDirectoryExists($collectDir);

        $migrationDir = $collectDir . $migrationId . '/';
        $this->ensureDirectoryExists($migrationDir);

        return $migrationDir;
    }

    /**
     * Ensures a directory exists.
     *
     * @param string $directory
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!file_exists($directory)) {
            Filesystem::mkdirP($directory);
        }
    }
    
    /**
     * Saves chunk data to a file.
     *
     * @param string $filepath
     * @param array  $chunkData
     */
    private function saveChunkToFile(string $filepath, array $chunkData): void
    {
        $json = JsonCompressor::compressToJson($chunkData);
        file_put_contents($filepath, $json);
    }

    /**
     * Generates a filename for a chunk.
     *
     * @param array $chunkData
     * @return string
     */
    private function generateChunkFilename(array $chunkData): string
    {
        $type = $chunkData['type'];
        $chunkId = $chunkData['chunk_id'] ?? 'unknown';

        switch ($type) {
            case 'info':
                return 'migration_info.json';
            case 'table_structure':
                $tableName = $chunkData['table']['name'] ?? 'unknown';
                return "structure_{$tableName}.json";
            case 'table_data':
                return "{$chunkId}.json";
            case 'file_chunk':
                return "{$chunkId}.json";
            case 'migration_complete':
                return 'migration_complete.json';
            default:
                return "{$type}_{$chunkId}.json";
        }
    }
}
