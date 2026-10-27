<?php

namespace WordpressMigrator\Services\Backup;

use WordpressMigrator\Dto\ChunkInfo;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\Filesystem;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Services\Migration\MigrationInfoGenerator;
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
        if (($chunkData['type'] ?? null) === 'info') {
            $chunkData = MigrationInfoGenerator::withoutSecrets($chunkData);
        }

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
        $this->assertSafeMigrationId($migrationId);
        $registry = new MigrationRegistry($migrationId);

        if ($chunkData['type'] === 'info') {
            $registry->registerMigration(MigrationInfoGenerator::withoutSecrets($chunkData));
        } else {
            $registry->markChunkReceived($chunkData['type'], $chunkData);
        }
    }

    /**
     * Rejects a migration id that cannot be used as a directory name.
     *
     * @param string $migrationId
     * @return void
     */
    private function assertSafeMigrationId(string $migrationId): void
    {
        if (!PathProvider::isSafeMigrationId($migrationId)) {
            throw new \InvalidArgumentException('Invalid migration ID');
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
        $this->assertSafeMigrationId($migrationId);

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
     * File name for one table's chunk. An older package may already have
     * table_chunk_00001.json on disk; this name does not reuse that file,
     * so a new chunk is not written over it and the old file is left to be read.
     *
     * @param array  $chunkData
     * @param string $chunkId
     * @return string
     */
    private function tableDataFilename(array $chunkData, string $chunkId): string
    {
        $table = $chunkData['table']['name'] ?? '';
        $number = $chunkData['table']['chunk_info']['chunk_number']
            ?? $chunkData['table']['chunk_info']['chunkNumber']
            ?? null;
        $migrationId = $chunkData['migration_id'] ?? '';

        if ($table === '' || $number === null || $migrationId === '') {
            return $chunkId !== '' ? $chunkId : 'unknown';
        }

        return $this->safeSegment($migrationId)
            . '_' . $this->safeSegment($table)
            . '_' . str_pad((string) (int) $number, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Keeps a path segment inside the migration directory.
     *
     * @param string $value
     * @return string
     */
    private function safeSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $value);
        $safe = trim((string) $safe, '_');

        return $safe === '' ? 'unknown' : $safe;
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

        if (!is_string($type) || !is_string($chunkId) || !PathProvider::isSafeChunkToken($type)
            || !PathProvider::isSafeChunkToken($chunkId)) {
            throw new \InvalidArgumentException('Invalid chunk ID');
        }

        switch ($type) {
            case 'info':
                return 'migration_info.json';
            case 'table_structure':
                $tableName = $this->safeSegment($chunkData['table']['name'] ?? 'unknown');
                return "structure_{$tableName}.json";
            case 'table_data':
                return $this->tableDataFilename($chunkData, (string) $chunkId) . '.json';
            case 'file_chunk':
                return "{$chunkId}.json";
            case 'migration_complete':
                return 'migration_complete.json';
            default:
                return "{$type}_{$chunkId}.json";
        }
    }
}
