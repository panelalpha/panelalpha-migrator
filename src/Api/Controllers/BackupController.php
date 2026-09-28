<?php

namespace WordpressMigrator\Api\Controllers;

use Exception;
use WordpressMigrator\Api\Middleware\RequestValidator;
use WordpressMigrator\Api\Middleware\ResponseFormatter;
use WordpressMigrator\Services\Backup\BackupService;
use WordpressMigrator\Services\Backup\ChunkService;
use WordpressMigrator\Helpers\DateTime;

/**
 * Class BackupController
 *
 * @package WordpressMigrator\Api\Controllers
 *
 * Handles backup-related API requests.
 */
class BackupController
{
    private $requestValidator;
    private $responseFormatter;
    private $backupService;
    private $chunkService;

    /**
     * BackupController constructor.
     *
     * @param BackupService       $backupService
     * @param ChunkService        $chunkService
     * @param RequestValidator|null $requestValidator
     * @param ResponseFormatter|null $responseFormatter
     */
    public function __construct(
        BackupService $backupService,
        ChunkService $chunkService,
        RequestValidator $requestValidator = null,
        ResponseFormatter $responseFormatter = null
    ) {
        $this->backupService = $backupService;
        $this->chunkService = $chunkService;
        $this->requestValidator = $requestValidator ?? new RequestValidator();
        $this->responseFormatter = $responseFormatter ?? new ResponseFormatter();
    }

    /**
     * Gets the next database chunk for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function databaseNextChunk($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $chunk = $this->backupService->getDatabaseNextChunk($migrationId);

            // Extract current table from chunk
            $currentTable = null;
            if ($chunk && isset($chunk['table']) && isset($chunk['table']['name'])) {
                $currentTable = $chunk['table']['name'];
            }

            $progress = $this->backupService->getDatabaseProgress($migrationId, $currentTable);

            return $this->formatChunkResponseWithProgress($chunk, $progress, 'All database chunks sent');

        } catch (Exception $e) {
            return $this->responseFormatter->error('backup_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Gets multiple database chunks in a single batch for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function databaseNextChunksBatch($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $batchSize = $request->get_param('batch_size') ?? 50;

            $result = $this->backupService->getDatabaseNextChunksBatch($migrationId, $batchSize);

            return $this->responseFormatter->success($result);

        } catch (Exception $e) {
            return $this->responseFormatter->error('backup_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Gets the database backup progress for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function databaseProgress($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $progress = $this->backupService->getDatabaseProgress($migrationId);

            return $this->formatProgressResponse($progress);

        } catch (Exception $e) {
            return $this->responseFormatter->error('progress_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Gets the next file chunk for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function fileNextChunk($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $chunk = $this->backupService->getFileNextChunk($migrationId);

            $currentFile = null;
            if ($chunk && isset($chunk['file']) && isset($chunk['file']['path'])) {
                $currentFile = $chunk['file']['path'];
            }

            $progress = $this->backupService->getFileProgress($migrationId, $currentFile);

            return $this->formatChunkResponseWithProgress($chunk, $progress, 'All file chunks sent');

        } catch (Exception $e) {
            return $this->responseFormatter->error('backup_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Gets multiple file chunks in a single batch for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function fileNextChunksBatch($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $batchSize = $request->get_param('batch_size') ?? 50;

            $result = $this->backupService->getFileNextChunksBatch($migrationId, $batchSize);

            return $this->responseFormatter->success($result);

        } catch (Exception $e) {
            return $this->responseFormatter->error('backup_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Gets the file backup progress for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function fileProgress($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $progress = $this->backupService->getFileProgress($migrationId);

            return $this->formatProgressResponse($progress);

        } catch (Exception $e) {
            return $this->responseFormatter->error('progress_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Gets the backup status for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function getStatus($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $status = $this->backupService->getBackupStatus($migrationId);

            return $this->responseFormatter->success([
                'success' => true,
                'status' => $status
            ]);

        } catch (Exception $e) {
            return $this->responseFormatter->error('status_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Starts the backup process for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function startBackup($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $this->backupService->setBackupRunning($migrationId, true);

            return $this->responseFormatter->success([
                'success' => true,
                'message' => 'Backup started'
            ]);

        } catch (Exception $e) {
            return $this->responseFormatter->error('start_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Stops the backup process for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function stopBackup($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $this->backupService->setBackupRunning($migrationId, false);

            return $this->responseFormatter->success([
                'success' => true,
                'message' => 'Backup stopped'
            ]);

        } catch (Exception $e) {
            return $this->responseFormatter->error('stop_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Collects a chunk from the remote source.
     *
     * @param mixed $request
     * @return mixed
     */
    public function collectChunk($request)
    {
        try {
            $chunkData = $this->requestValidator->getJsonParams($request);

            if (!$this->chunkService->isValidChunkData($chunkData)) {
                return $this->responseFormatter->error('invalid_chunk', 'Invalid chunk data', ['status' => 400]);
            }

            $migrationId = $chunkData['migration_id'] ?? 'unknown';

            $filename = $this->chunkService->saveChunk($migrationId, $chunkData);
            $this->chunkService->registerChunk($migrationId, $chunkData);

            // If this is a migration_complete chunk, process it immediately
            $isProcessed = false;
            $processingResult = null;

            if (isset($chunkData['type']) && $chunkData['type'] === 'migration_complete') {
                $domainStats = $this->processMigrationComplete($migrationId, $chunkData);
                $isProcessed = true;
                $processingResult = $domainStats;
            }

            $result = $this->buildAcknowledgmentResponse($migrationId, $chunkData, $filename, $isProcessed, $processingResult);

            return $this->responseFormatter->success($result);

        } catch (Exception $e) {
            return $this->responseFormatter->error('collect_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Process migration completion and perform domain replacement.
     *
     * @param string $migrationId
     * @param array  $chunkData
     * @return array
     */
    private function processMigrationComplete(string $migrationId, array $chunkData): array
    {
        try {
            $db = \WordpressMigrator\Helpers\Database::getInstance();
            $registry = new \WordpressMigrator\Services\Migration\MigrationRegistry($migrationId);

            $tableHandler = new \WordpressMigrator\Restore\TableRestoreHandler($db, $registry);
            $fileHandler = new \WordpressMigrator\Restore\FileRestoreHandler($registry, defined('ABSPATH') ? ABSPATH : getcwd() . '/');

            $processor = new \WordpressMigrator\Restore\ChunkProcessor($tableHandler, $fileHandler, $this->chunkService);

            $result = $processor->process($migrationId, $chunkData);

            return $result;

        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Formats the chunk response with progress.
     *
     * @param mixed  $chunk
     * @param array  $progress
     * @param string $completionMessage
     * @return mixed
     */
    private function formatChunkResponseWithProgress($chunk, array $progress, string $completionMessage)
    {
        if ($chunk === null) {
            return $this->responseFormatter->success([
                'success' => true,
                'completed' => true,
                'message' => $completionMessage,
                'progress' => $progress
            ]);
        }

        return $this->responseFormatter->success([
            'success' => true,
            'completed' => false,
            'chunk' => $chunk,
            'progress' => $progress
        ]);
    }

    /**
     * Formats the progress response.
     *
     * @param array $progress
     * @return mixed
     */
    private function formatProgressResponse(array $progress)
    {
        return $this->responseFormatter->success([
            'success' => true,
            'progress' => $progress
        ]);
    }
    
    /**
     * Builds the acknowledgment response.
     *
     * @param string $migrationId
     * @param array  $chunkData
     * @param string $filename
     * @param bool   $isProcessed
     * @param array|null $processingResult
     * @return array
     */
    private function buildAcknowledgmentResponse(string $migrationId, array $chunkData, string $filename, bool $isProcessed = false, ?array $processingResult = null): array
    {
        $response = [
            'type' => 'acknowledgment',
            'migration_id' => $migrationId,
            'chunk_id' => $chunkData['chunk_id'] ?? null,
            'status' => 'received',
            'processed' => $isProcessed,
            'error' => null,
            'saved_to' => $filename,
            'timestamp' => $this->getCurrentTimestamp()
        ];

        if ($processingResult !== null) {
            $response['processing_result'] = $processingResult;
        }

        return $response;
    }

    /**
     * Gets the current timestamp.
     *
     * @return string
     */
    private function getCurrentTimestamp(): string
    {
        return function_exists('current_time')
            ? current_time('mysql')
            : DateTime::currentTime('mysql');
    }
}
