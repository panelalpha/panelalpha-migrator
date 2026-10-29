<?php

namespace PanelAlpha\Migrator\Restore;

use PanelAlpha\Migrator\Services\Backup\ChunkService;
use PanelAlpha\Migrator\Services\Migration\MigrationInfoGenerator;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;

/**
 * Class ChunkProcessor
 *
 * @package PanelAlpha\Migrator\Restore
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

            default:
                throw new \Exception(esc_html('Unknown chunk type: ' . $type));
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
}
