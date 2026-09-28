<?php

namespace WordpressMigrator;

use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\Database;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Restore\ChunkProcessor;
use WordpressMigrator\Restore\FileRestoreHandler;
use WordpressMigrator\Restore\RestoreHttpHandler;
use WordpressMigrator\Restore\TableRestoreHandler;
use WordpressMigrator\Restore\WpConfigParser;
use WordpressMigrator\Services\Backup\ChunkService;
use WordpressMigrator\Services\Migration\MigrationRegistry;

/**
 * Class RestoreWorker
 *
 * @package WordpressMigrator
 *
 * Handles the restore process when executed as a standalone script.
 */
class RestoreWorker
{
    private RestoreHttpHandler $http;
    private ChunkProcessor $processor;
    private string $abspath;

    /**
     * RestoreWorker constructor.
     *
     * @param string $abspath
     * @throws \RuntimeException
     */
    public function __construct(string $abspath)
    {
        $this->abspath = $abspath;
        $this->initializeEnvironment();
        $this->http = new RestoreHttpHandler();
        $this->processor = $this->createChunkProcessor();
    }

    /**
     * Runs the restore worker.
     */
    public function run(): void
    {
        $action = $this->http->getAction();

        switch ($action) {
            case 'collect-and-restore':
                $this->handleCollectAndRestore();
                break;

            case 'status':
                $this->handleStatus();
                break;

            case 'health':
                $this->http->sendJson(['status' => 'ok', 'service' => 'restore-worker']);
                break;

            default:
                $this->http->sendJson([
                    'error' => 'Invalid action. Use: collect-and-restore, status, or health'
                ], 400);
        }
    }

    /**
     * Initializes the WordPress environment.
     *
     * @throws \RuntimeException
     */
    private function initializeEnvironment(): void
    {
        $parser = new WpConfigParser($this->abspath);
        $config = $parser->parse();

        if (!defined('DB_NAME')) {
            define('DB_NAME', $config['db_name']);
        }
        if (!defined('DB_USER')) {
            define('DB_USER', $config['db_user']);
        }
        if (!defined('DB_PASSWORD')) {
            define('DB_PASSWORD', $config['db_password']);
        }
        if (!defined('DB_HOST')) {
            define('DB_HOST', $config['db_host']);
        }
        if (!defined('DB_CHARSET')) {
            define('DB_CHARSET', 'utf8mb4');
        }
        if (!defined('DB_COLLATE')) {
            define('DB_COLLATE', '');
        }

        $GLOBALS['table_prefix'] = $config['table_prefix'];

        Config::set('migration_dir', $this->abspath . 'wp-content/migrations/');
        Config::set('plugin_dir', dirname(__DIR__) . '/');
    }

    /**
     * Creates a ChunkProcessor instance.
     *
     * @return ChunkProcessor
     */
    private function createChunkProcessor(): ChunkProcessor
    {
        $db = Database::getInstance();

        $tableHandler = new TableRestoreHandler($db, new MigrationRegistry('temp'));
        $fileHandler = new FileRestoreHandler(new MigrationRegistry('temp'), $this->abspath);
        $chunkService = new ChunkService();

        return new ChunkProcessor($tableHandler, $fileHandler, $chunkService);
    }

    /**
     * Handles the 'collect-and-restore' action.
     */
    private function handleCollectAndRestore(): void
    {
        $this->http->authenticate();

        $rawData = $this->http->getJsonInputBase64();
        $chunkData = JsonCompressor::isCompressed($rawData)
            ? JsonCompressor::decompress($rawData)
            : $rawData;

        if (empty($chunkData) || !isset($chunkData['type'])) {
            $this->http->sendJson(['error' => 'Invalid chunk data'], 400);
        }

        $migrationId = $chunkData['migration_id'] ?? 'unknown';

        try {
            $registry = new MigrationRegistry($migrationId);
            $tableHandler = new TableRestoreHandler(Database::getInstance(), $registry);
            $fileHandler = new FileRestoreHandler($registry, $this->abspath);
            $chunkService = new ChunkService();
            $processor = new ChunkProcessor($tableHandler, $fileHandler, $chunkService);

            $result = $processor->process($migrationId, $chunkData);

            $this->http->sendJson([
                'success' => true,
                'migration_id' => $migrationId,
                'chunk_type' => $chunkData['type'],
                'chunk_id' => $chunkData['chunk_id'] ?? null,
                'processed' => true,
                'result' => $result,
                'timestamp' => date('c')
            ]);

        } catch (\Exception $e) {
            $this->http->sendJson([
                'error' => 'Processing failed: ' . $e->getMessage(),
                'migration_id' => $migrationId,
                'chunk_type' => $chunkData['type'] ?? 'unknown'
            ], 500);
        }
    }

    /**
     * Handles the 'status' action.
     */
    private function handleStatus(): void
    {
        $this->http->authenticate();

        $migrationId = $this->http->getMigrationId();

        if (empty($migrationId)) {
            $this->http->sendJson(['error' => 'Missing migration_id parameter'], 400);
        }

        try {
            $registry = new MigrationRegistry($migrationId);
            $status = $registry->getStatus();

            if (!$status) {
                $this->http->sendJson(['error' => 'Migration not found'], 404);
            }

            $this->http->sendJson([
                'success' => true,
                'migration_id' => $migrationId,
                'status' => $status
            ]);

        } catch (\Exception $e) {
            $this->http->sendJson(['error' => 'Status check failed: ' . $e->getMessage()], 500);
        }
    }
}
