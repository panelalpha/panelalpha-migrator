<?php

namespace PanelAlpha\Migrator\Api\Controllers;

use Exception;
use PanelAlpha\Migrator\Api\Middleware\InvalidRequestException;
use PanelAlpha\Migrator\Api\Middleware\RequestValidator;
use PanelAlpha\Migrator\Api\Middleware\ResponseFormatter;
use PanelAlpha\Migrator\Helpers\DestinationNotAllowedException;
use PanelAlpha\Migrator\Services\Backup\BackupService;
use PanelAlpha\Migrator\Services\Migration\DestinationHandshake;

/**
 * Class BackupController
 *
 * @package PanelAlpha\Migrator\Api\Controllers
 *
 * Handles backup-related API requests.
 */
class BackupController
{
    private $requestValidator;
    private $responseFormatter;
    private $backupService;
    private DestinationHandshake $handshake;

    /**
     * BackupController constructor.
     *
     * @param BackupService       $backupService
     * @param RequestValidator|null $requestValidator
     * @param ResponseFormatter|null $responseFormatter
     * @param DestinationHandshake|null $handshake
     */
    public function __construct(
        BackupService $backupService,
        ?RequestValidator $requestValidator = null,
        ?ResponseFormatter $responseFormatter = null,
        ?DestinationHandshake $handshake = null
    ) {
        $this->backupService = $backupService;
        $this->requestValidator = $requestValidator ?? new RequestValidator();
        $this->responseFormatter = $responseFormatter ?? new ResponseFormatter();
        $this->handshake = $handshake ?? new DestinationHandshake();
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

            // The info chunk goes from here, so the browser never talks to the destination.
            // A failure releases the lock again, as the backup did not start.
            try {
                $this->handshake->sendInfo($migrationId);
            } catch (\Throwable $e) {
                try {
                    $this->backupService->stopBackup($migrationId);
                } catch (\Throwable $stopError) {
                    // The original failure is the one to report.
                }
                throw $e;
            }

            return $this->responseFormatter->success([
                'success' => true,
                'message' => 'Backup started'
            ]);

        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
        } catch (DestinationNotAllowedException $e) {
            return $this->responseFormatter->error('destination_not_allowed', $e->getMessage(), ['status' => 400]);
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
}
