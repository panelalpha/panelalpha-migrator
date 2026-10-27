<?php

namespace WordpressMigrator\Api\Controllers;

use Exception;
use WordpressMigrator\Api\Middleware\InvalidRequestException;
use WordpressMigrator\Api\Middleware\RequestValidator;
use WordpressMigrator\Api\Middleware\ResponseFormatter;
use WordpressMigrator\Helpers\DateTime;
use WordpressMigrator\Helpers\DestinationNotAllowedException;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Services\Backup\BackupService;
use WordpressMigrator\Services\Backup\ChunkService;

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
        ?RequestValidator $requestValidator = null,
        ?ResponseFormatter $responseFormatter = null
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
            return $this->migrationIdErrorOr($e, 'backup_failed');
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
            return $this->migrationIdErrorOr($e, 'backup_failed');
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

        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
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
            return $this->migrationIdErrorOr($e, 'backup_failed');
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
            return $this->migrationIdErrorOr($e, 'backup_failed');
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

        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
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

        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
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
            $this->backupService->startBackup($migrationId);

            return $this->responseFormatter->success([
                'success' => true,
                'message' => 'Backup started'
            ]);

        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
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
            $keepLock = filter_var($request->get_param('keep_lock'), FILTER_VALIDATE_BOOLEAN);
            $this->backupService->stopBackup($migrationId, $keepLock);

            return $this->responseFormatter->success([
                'success' => true,
                'message' => 'Backup stopped'
            ]);

        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
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

            if (!is_string($migrationId) || !PathProvider::isSafeMigrationId($migrationId)) {
                return $this->responseFormatter->error('invalid_id', 'Invalid migration ID', ['status' => 400]);
            }

            $chunkId = $chunkData['chunk_id'] ?? 'unknown';
            $chunkType = $chunkData['type'];

            if (!is_string($chunkId) || !PathProvider::isSafeChunkToken($chunkId)
                || !is_string($chunkType) || !PathProvider::isSafeChunkToken($chunkType)) {
                return $this->responseFormatter->error('invalid_id', 'Invalid chunk ID', ['status' => 400]);
            }

            // A migration_complete chunk is processed first and saved only when that succeeded
            $isProcessed = false;
            $processingResult = null;

            if (isset($chunkData['type']) && $chunkData['type'] === 'migration_complete') {
                $processingResult = $this->processMigrationComplete($migrationId, $chunkData);
                $isProcessed = true;
            }

            $filename = $this->chunkService->saveChunk($migrationId, $chunkData);
            $this->chunkService->registerChunk($migrationId, $chunkData);

            $result = $this->buildAcknowledgmentResponse($migrationId, $chunkData, $filename, $isProcessed, $processingResult);

            return $this->responseFormatter->success($result);

        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
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
        $db = \WordpressMigrator\Helpers\Database::getInstance();
        $registry = new \WordpressMigrator\Services\Migration\MigrationRegistry($migrationId);

        $tableHandler = new \WordpressMigrator\Restore\TableRestoreHandler($db, $registry);
        $fileHandler = new \WordpressMigrator\Restore\FileRestoreHandler($registry, defined('ABSPATH') ? ABSPATH : getcwd() . '/');

        $processor = new \WordpressMigrator\Restore\ChunkProcessor($tableHandler, $fileHandler, $this->chunkService);

        return $processor->process($migrationId, $chunkData);
    }

    /**
     * The alphabet check rejects an id before it is used as a path.
     * That rejection is a client error. Every other failure stays a server error.
     *
     * @param Exception $e
     * @param string    $code
     * @return mixed
     */
    private function migrationIdErrorOr(Exception $e, string $code)
    {
        if ($e instanceof DestinationNotAllowedException) {
            return $this->responseFormatter->error('destination_not_allowed', $e->getMessage(), ['status' => 400]);
        }

        if ($e instanceof InvalidRequestException) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
        }

        if ($e instanceof \InvalidArgumentException && $e->getMessage() === 'Invalid migration ID') {
            return $this->responseFormatter->error('invalid_id', 'Invalid migration ID', ['status' => 400]);
        }

        return $this->responseFormatter->error($code, $e->getMessage(), ['status' => 500]);
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

        if ($isProcessed) {
            $response['success'] = true;
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
