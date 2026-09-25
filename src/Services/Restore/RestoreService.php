<?php

namespace WordpressMigrator\Services\Restore;

use WordpressMigrator\Helpers\Database;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Restore\FileRestoreHandler;
use WordpressMigrator\Restore\TableRestoreHandler;
use WordpressMigrator\Services\Migration\MigrationRegistry;

/**
 * Class RestoreService
 *
 * @package WordpressMigrator\Services\Restore
 *
 * Service for handling restore operations.
 */
class RestoreService
{
    /**
     * Restores the next file chunk for a migration.
     *
     * @param string $migrationId
     * @return array|null
     * @throws \Exception
     */
    public function restoreFileNextChunk(string $migrationId)
    {
        $registry = new MigrationRegistry($migrationId);
        $handler = new FileRestoreHandler($registry, PathProvider::getAbsPath());

        $chunk = $registry->getNextChunkToRestore('file_chunk');
        if (!$chunk) {
            return null;
        }

        return $handler->restoreChunk($chunk);
    }

    /**
     * Restores the next database chunk for a migration.
     *
     * @param string $migrationId
     * @return array|null
     * @throws \Exception
     */
    public function restoreDatabaseNextChunk(string $migrationId)
    {
        $registry = new MigrationRegistry($migrationId);

        $chunk = $registry->getNextChunkToRestore(['table_structure', 'table_data']);
        if (!$chunk) {
            return null;
        }

        $db = Database::getInstance();
        $handler = new TableRestoreHandler($db, $registry);

        if ($chunk['type'] === 'table_structure') {
            return $handler->restoreStructure($chunk);
        } else {
            return $handler->restoreData($chunk);
        }
    }

    /**
     * Gets the restore progress for a migration.
     *
     * @param string $migrationId
     * @return array
     */
    public function getProgress(string $migrationId): array
    {
        $registry = new MigrationRegistry($migrationId);
        return $registry->getRestoreProgress();
    }
}
