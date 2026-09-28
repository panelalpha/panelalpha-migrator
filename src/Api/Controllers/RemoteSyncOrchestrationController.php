<?php

namespace WordpressMigrator\Api\Controllers;

use WordpressMigrator\Api\Middleware\RequestValidator;
use WordpressMigrator\Api\Middleware\ResponseFormatter;
use WordpressMigrator\Services\RemoteSync\RemoteSyncOrchestrationService;

/**
 * Remote sync orchestration controller.
 *
 * @package WordpressMigrator\Api\Controllers
 */
class RemoteSyncOrchestrationController
{
    private RequestValidator $requestValidator;
    private ResponseFormatter $responseFormatter;
    private RemoteSyncOrchestrationService $orchestrationService;

    /**
     * @param RemoteSyncOrchestrationService $orchestrationService
     * @param RequestValidator|null  $requestValidator
     * @param ResponseFormatter|null $responseFormatter
     */
    public function __construct(
        RemoteSyncOrchestrationService $orchestrationService,
        RequestValidator $requestValidator = null,
        ResponseFormatter $responseFormatter = null
    ) {
        $this->orchestrationService = $orchestrationService;
        $this->requestValidator = $requestValidator ?? new RequestValidator();
        $this->responseFormatter = $responseFormatter ?? new ResponseFormatter();
    }

    /**
     * Start a migration.
     *
     * @param mixed $request Request object
     * @return mixed Response
     */
    public function start($request)
    {
        try {
            $params = $this->requestValidator->getJsonParams($request);

            if (empty($params['destination_url'])) {
                return $this->responseFormatter->error(
                    'missing_destination',
                    'destination_url is required',
                    ['status' => 400]
                );
            }

            $options = [
                'destination_url' => $params['destination_url'],
                'backup_database' => $params['backup_database'] ?? true,
                'backup_files' => $params['backup_files'] ?? true,
                'exclusions' => $params['exclusions'] ?? null,
                'ip' => $params['ip'] ?? null,
                'force' => $params['force'] ?? false,
                'base64' => $params['base64'] ?? false
            ];

            $result = $this->orchestrationService->startMigration($options);

            return $this->responseFormatter->success(array_merge(
                ['success' => true],
                $result
            ));

        } catch (\Throwable $e) {
            return $this->responseFormatter->error(
                'sync_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get the status of a migration.
     *
     * @param mixed $request Request object
     * @return mixed Response
     */
    public function getStatus($request)
    {
        try {
            $migrationId = $this->requestValidator->getParam($request, 'migration_id', null);

            if (empty($migrationId)) {
                return $this->responseFormatter->error(
                    'missing_migration_id',
                    'migration_id parameter is required',
                    ['status' => 400]
                );
            }

            $status = $this->orchestrationService->getMigrationStatus($migrationId);

            if (!$status) {
                return $this->responseFormatter->error(
                    'migration_not_found',
                    'Migration not found',
                    ['status' => 404]
                );
            }

            return $this->responseFormatter->success([
                'success' => true,
                'status' => $status
            ]);

        } catch (\Throwable $e) {
            return $this->responseFormatter->error(
                'status_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    public function processBatch($request)
    {
        try {
            $params = $this->requestValidator->getJsonParams($request);

            if (empty($params['migration_id'])) {
                return $this->responseFormatter->error(
                    'missing_migration_id',
                    'migration_id is required',
                    ['status' => 400]
                );
            }

            $migrationId = $params['migration_id'];
            $batchSize = $params['batch_size'] ?? 50;

            $result = $this->orchestrationService->processBatch($migrationId, $batchSize);

            return $this->responseFormatter->success(array_merge(
                ['success' => true],
                $result
            ));

        } catch (\Throwable $e) {
            return $this->responseFormatter->error(
                'batch_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

}
