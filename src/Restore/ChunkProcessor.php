<?php

namespace WordpressMigrator\Restore;

use WordpressMigrator\Services\Backup\ChunkService;
use WordpressMigrator\Services\Migration\MigrationInfoGenerator;
use WordpressMigrator\Services\Migration\MigrationRegistry;

/**
 * Class ChunkProcessor
 *
 * @package WordpressMigrator\Restore
 *
 * Processes received chunks during the restore process.
 */
class ChunkProcessor
{
    private TableRestoreHandler $tableHandler;
    private FileRestoreHandler $fileHandler;
    private ChunkService $chunkService;

    /**
     * ChunkProcessor constructor.
     *
     * @param TableRestoreHandler $tableHandler
     * @param FileRestoreHandler  $fileHandler
     * @param ChunkService        $chunkService
     */
    public function __construct(TableRestoreHandler $tableHandler, FileRestoreHandler $fileHandler, ChunkService $chunkService)
    {
        $this->tableHandler = $tableHandler;
        $this->fileHandler = $fileHandler;
        $this->chunkService = $chunkService;
    }

    /**
     * Processes a chunk based on its type.
     *
     * @param string $migrationId
     * @param array  $chunkData
     * @return array
     * @throws \Exception
     */
    public function process(string $migrationId, array $chunkData): array
    {
        $type = $chunkData['type'];

        switch ($type) {
            case 'info':
                return $this->processInfo($migrationId, $chunkData);

            case 'table_structure':
                return $this->tableHandler->restoreStructure($chunkData);

            case 'table_data':
                return $this->tableHandler->restoreData($chunkData);

            case 'file_chunk':
                return $this->fileHandler->restoreChunk($chunkData);

            case 'migration_complete':
                return $this->processComplete($migrationId, $chunkData);

            default:
                throw new \Exception('Unknown chunk type: ' . $type);
        }
    }

    /**
     * Processes the 'info' chunk.
     *
     * @param string $migrationId
     * @param array  $chunkData
     * @return array
     */
    private function processInfo(string $migrationId, array $chunkData): array
    {
        // Save migration_info.json file
        $this->chunkService->saveChunk($migrationId, $chunkData);
        
        // Register migration in registry
        $registry = new MigrationRegistry($migrationId);
        $registry->registerMigration(MigrationInfoGenerator::withoutSecrets($chunkData));

        return ['action' => 'registered_migration'];
    }

    /**
     * Processes the 'migration_complete' chunk.
     *
     * @param string $migrationId
     * @param array  $chunkData
     * @return array
     */
    private function processComplete(string $migrationId, array $chunkData): array
    {
        $registry = new MigrationRegistry($migrationId);
        $skippedFiles = $registry->knownSkippedFiles($registry->getStatus() ?? [], $chunkData['skipped_files'] ?? []);

        if (!$registry->isRestoreComplete($skippedFiles)) {
            throw new \Exception('Migration manifest is incomplete');
        }

        // Domain replacement is no longer needed here - domains are replaced
        // before sending chunks from source WordPress
        error_log("MIGRATION COMPLETE: Migration {$migrationId} completed. Domain replacement was handled during chunk creation.");

        $removed = $this->fileHandler->removeStaleCoreFiles(array_keys(($registry->getStatus() ?? [])['files'] ?? []));
        if ($removed > 0) {
            error_log("MIGRATION COMPLETE: Migration {$migrationId} deleted {$removed} WordPress core files the source does not have.");
        }

        $registry->updateStatus('restored');

        $result = [
            'action' => 'migration_completed',
            'message' => 'Migration completed successfully. Domains were replaced during transfer.'
        ];

        if ($skippedFiles !== []) {
            error_log("MIGRATION COMPLETE: Migration {$migrationId} skipped files deleted on the source: " . implode(', ', $skippedFiles));
            $result['skipped_files'] = $skippedFiles;
            $result['warning'] = 'Some files were deleted on the source before they could be sent and were skipped.';
        }

        return $result;
    }

}
