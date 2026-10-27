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
        $this->http = new RestoreHttpHandler();
    }

    /**
     * Runs the restore worker.
     */
    public function run(): void
    {
        $action = $this->http->getAction();

        switch ($action) {
            case 'collect-and-restore':
            case 'status':
                $this->http->authenticate();
                $this->initializeEnvironment();

                if ($action === 'status') {
                    $this->handleStatus();
                } else {
                    $this->handleCollectAndRestore();
                }
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
     * Handles the 'collect-and-restore' action.
     */
    private function handleCollectAndRestore(): void
    {
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

            $result = $registry->withStatusLock(fn() => $processor->process($migrationId, $chunkData));

            $this->http->sendJson(self::acknowledgement($migrationId, $chunkData, $result));

        } catch (\Throwable $e) {
            error_log('WordPress Migrator: Processing failed: ' . $e->getMessage());
            $this->http->sendJson([
                'error' => 'Processing failed. Check the PHP error log on the destination.',
                'migration_id' => $migrationId,
                'chunk_type' => $chunkData['type'] ?? 'unknown'
            ], 500);
        }
    }

    /**
     * Builds the acknowledgement returned to the source.
     * A failed or retryable result is not a successful delivery.
     * An explicit skip keeps its reason and is acknowledged as such.
     *
     * @param string $migrationId
     * @param array  $chunkData
     * @param array  $result
     * @return array
     */
    public static function acknowledgement(string $migrationId, array $chunkData, array $result): array
    {
        $delivered = empty($result['failed']) && empty($result['can_retry']);

        return [
            'success' => $delivered,
            'migration_id' => $migrationId,
            'chunk_type' => $chunkData['type'] ?? null,
            'chunk_id' => $chunkData['chunk_id'] ?? null,
            'processed' => $delivered,
            'result' => $result,
            'timestamp' => date('c')
        ];
    }

    /**
     * Handles the 'status' action.
     */
    private function handleStatus(): void
    {
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

        } catch (\Throwable $e) {
            error_log('WordPress Migrator: Status check failed: ' . $e->getMessage());
            $this->http->sendJson(['error' => 'Status check failed. Check the PHP error log on the destination.'], 500);
        }
    }
}
