<?php

namespace WordpressMigrator\Api\Controllers;

use Exception;
use WordpressMigrator\Api\Middleware\InvalidRequestException;
use WordpressMigrator\Api\Middleware\RequestValidator;
use WordpressMigrator\Api\Middleware\ResponseFormatter;
use WordpressMigrator\Services\Restore\RestoreService;

/**
 * Class RestoreController
 *
 * @package WordpressMigrator\Api\Controllers
 *
 * Handles restore-related API requests.
 */
class RestoreController
{
    private $requestValidator;
    private $responseFormatter;
    private $restoreService;

    /**
     * RestoreController constructor.
     *
     * @param RestoreService $restoreService
     * @param RequestValidator|null $requestValidator
     * @param ResponseFormatter|null $responseFormatter
     */
    public function __construct(
        RestoreService $restoreService,
        ?RequestValidator $requestValidator = null,
        ?ResponseFormatter $responseFormatter = null
    ) {
        $this->restoreService = $restoreService;
        $this->requestValidator = $requestValidator ?? new RequestValidator();
        $this->responseFormatter = $responseFormatter ?? new ResponseFormatter();
    }

    /**
     * Restores the next file chunk for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function fileNextChunk($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);

            $chunk = $this->restoreService->restoreFileNextChunk($migrationId);

            if ($chunk === null) {
                return $this->responseFormatter->success([
                    'success' => true,
                    'completed' => true,
                    'message' => 'All file chunks restored'
                ]);
            }

            return $this->responseFormatter->success([
                'success' => true,
                'completed' => false,
                'chunk' => $chunk
            ]);

        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
        } catch (Exception $e) {
            return $this->responseFormatter->error('restore_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Restores the next database chunk for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function databaseNextChunk($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);

            $chunk = $this->restoreService->restoreDatabaseNextChunk($migrationId);

            if ($chunk === null) {
                return $this->responseFormatter->success([
                    'success' => true,
                    'completed' => true,
                    'message' => 'All database chunks restored'
                ]);
            }

            return $this->responseFormatter->success([
                'success' => true,
                'completed' => false,
                'chunk' => $chunk
            ]);

        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
        } catch (Exception $e) {
            return $this->responseFormatter->error('restore_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Gets the restore progress for a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function progress($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $progress = $this->restoreService->getProgress($migrationId);

            return $this->responseFormatter->success([
                'success' => true,
                'progress' => $progress
            ]);

        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
        } catch (Exception $e) {
            return $this->responseFormatter->error('progress_failed', $e->getMessage(), ['status' => 500]);
        }
    }
}
