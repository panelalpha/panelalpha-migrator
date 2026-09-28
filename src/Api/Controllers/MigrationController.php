<?php

namespace WordpressMigrator\Api\Controllers;

use Exception;
use WordpressMigrator\Api\Middleware\RequestValidator;
use WordpressMigrator\Api\Middleware\ResponseFormatter;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Services\Migration\HtaccessService;
use WordpressMigrator\Services\Migration\MigrationService;

/**
 * Class MigrationController
 *
 * @package WordpressMigrator\Api\Controllers
 *
 * Handles migration-related API requests.
 */
class MigrationController
{
    private $requestValidator;
    private $responseFormatter;
    private $migrationService;
    private $htaccessService;

    /**
     * MigrationController constructor.
     *
     * @param MigrationService $migrationService
     * @param HtaccessService|null $htaccessService
     * @param RequestValidator|null $requestValidator
     * @param ResponseFormatter|null $responseFormatter
     */
    public function __construct(
        MigrationService $migrationService,
        HtaccessService $htaccessService = null,
        RequestValidator $requestValidator = null,
        ResponseFormatter $responseFormatter = null
    ) {
        $this->migrationService = $migrationService;
        $this->htaccessService = $htaccessService ?? new HtaccessService();
        $this->requestValidator = $requestValidator ?? new RequestValidator();
        $this->responseFormatter = $responseFormatter ?? new ResponseFormatter();
    }

    /**
     * Scans the site and prepares a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function scan($request)
    {
        try {
            $destinationUrl = $this->requestValidator->getDestinationUrl($request);

            $exclusions = null;
            $token = null;
            $ip = null;
            $params = $this->requestValidator->getJsonParams($request);
            if (isset($params['exclusions']) && !empty($params['exclusions'])) {
                $exclusions = $params['exclusions'];
            }
            if (isset($params['token']) && !empty($params['token'])) {
                $token = $params['token'];
            }
            if (isset($params['ip']) && !empty($params['ip'])) {
                $ip = $params['ip'];
            }

            $result = $this->migrationService->scanAndPrepareMigration($destinationUrl, $exclusions, $token, $ip);

            $response = [
                'success' => true,
                'migration_id' => $result['migrationId'],
                'file_path' => $this->migrationService->getMigrationFilePath($result['migrationId']),
                'summary' => $this->responseFormatter->convertKeysToSnakeCase($result['migrationData']['summary'])
            ];

            return $this->responseFormatter->success($response);
        } catch (Exception $e) {
            return $this->responseFormatter->error('scan_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Gets information about a specific migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function info($request)
    {
        $migrationId = $this->requestValidator->getMigrationId($request);

        $raw = $this->requestValidator->getParam($request, 'raw', false);

        if ($raw) {
            $data = $this->migrationService->getMigrationInfoRaw($migrationId);

            if (!$data) {
                return $this->responseFormatter->error('not_found', 'Migration information not found', ['status' => 404]);
            }

            return $this->responseFormatter->success($data);
        }

        $data = $this->migrationService->getMigrationInfo($migrationId);

        if (!$data) {
            return $this->responseFormatter->error('not_found', 'Migration information not found', ['status' => 404]);
        }

        $convertedData = $this->responseFormatter->convertKeysToSnakeCase($data);

        return $this->responseFormatter->success($convertedData);
    }

    /**
     * Finalizes a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function finalize($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);
            $response = $this->migrationService->finalizeMigration($migrationId);

            $result = [
                'success' => true,
                'message' => 'Migration finalized successfully',
                'response' => $response
            ];

            return $this->responseFormatter->success($result);

        } catch (Exception $e) {
            if ($e->getMessage() === 'Migration is not complete yet') {
                return $this->responseFormatter->error('not_complete', $e->getMessage(), ['status' => 400]);
            }
            return $this->responseFormatter->error('finalize_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Lists all migrations.
     *
     * @param mixed $request
     * @return mixed
     */
    public function listMigrations($request)
    {
        try {
            $migrations = $this->migrationService->listMigrations();
            return $this->responseFormatter->success(['success' => true, 'migrations' => $migrations]);
        } catch (Exception $e) {
            return $this->responseFormatter->error('list_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Reads .htaccess from the source site and transfers it to the destination.
     * Destination URL, token and IP are loaded from the migration file.
     *
     * @param mixed $request
     * @return mixed
     */
    public function transferHtaccess($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);

            if (!file_exists(PathProvider::getMigrationFilePath($migrationId))) {
                return $this->responseFormatter->error('not_found', 'Migration not found', ['status' => 404]);
            }

            $result = $this->htaccessService->transfer($migrationId);

            $message = $result['transferred']
                ? '.htaccess transferred successfully'
                : ($result['message'] ?? 'No .htaccess file found on source');

            return $this->responseFormatter->success([
                'success'     => true,
                'transferred' => $result['transferred'],
                'message'     => $message,
            ]);

        } catch (Exception $e) {
            return $this->responseFormatter->error('transfer_htaccess_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Receives .htaccess content from source and saves it to the local filesystem.
     *
     * @param mixed $request
     * @return mixed
     */
    public function receiveHtaccess($request)
    {
        try {
            $params = $this->requestValidator->getJsonParams($request);

            if (!isset($params['content'])) {
                return $this->responseFormatter->error('missing_content', 'Missing .htaccess content', ['status' => 400]);
            }

            $content = $params['content'];
            if (!is_string($content)) {
                return $this->responseFormatter->error('invalid_content', 'Invalid .htaccess content', ['status' => 400]);
            }

            $result = $this->htaccessService->receive($content);

            return $this->responseFormatter->success([
                'success'       => true,
                'saved'         => true,
                'message'       => '.htaccess saved successfully',
                'bytes_written' => $result['bytes_written'],
            ]);

        } catch (Exception $e) {
            return $this->responseFormatter->error('receive_htaccess_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Marks a migration as complete.
     *
     * @param mixed $request
     * @return mixed
     */
    public function complete($request)
    {
        try {
            $params = $this->requestValidator->getJsonParams($request);
            $migrationId = $params['migration_id'] ?? null;

            if (empty($migrationId)) {
                return $this->responseFormatter->error('missing_id', 'Missing migration ID', ['status' => 400]);
            }

            $result = $this->migrationService->completeMigration($migrationId);

            if (!$result) {
                return $this->responseFormatter->error('not_found', 'Migration not found', ['status' => 404]);
            }

            $response = [
                'success' => true,
                'message' => 'Migration marked as collected',
                'migration_id' => $migrationId,
                'previous_status' => $result['old_status']['status'],
                'new_status' => $result['new_status']
            ];

            return $this->responseFormatter->success($response);

        } catch (Exception $e) {
            return $this->responseFormatter->error('complete_failed', $e->getMessage(), ['status' => 500]);
        }
    }
}