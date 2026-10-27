<?php

namespace WordpressMigrator\Api\Controllers;

use Exception;
use WordpressMigrator\Api\Middleware\InvalidRequestException;
use WordpressMigrator\Api\Middleware\RequestValidator;
use WordpressMigrator\Api\Middleware\ResponseFormatter;
use WordpressMigrator\Helpers\DestinationGuard;
use WordpressMigrator\Helpers\DestinationNotAllowedException;
use WordpressMigrator\Helpers\MigrationLock;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Services\Migration\HtaccessService;
use WordpressMigrator\Services\Migration\MigrationInfoGenerator;
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
    private DestinationGuard $destinationGuard;

    /**
     * MigrationController constructor.
     *
     * @param MigrationService $migrationService
     * @param HtaccessService|null $htaccessService
     * @param RequestValidator|null $requestValidator
     * @param ResponseFormatter|null $responseFormatter
     * @param DestinationGuard|null $destinationGuard
     */
    public function __construct(
        MigrationService $migrationService,
        ?HtaccessService $htaccessService = null,
        ?RequestValidator $requestValidator = null,
        ?ResponseFormatter $responseFormatter = null,
        ?DestinationGuard $destinationGuard = null
    ) {
        $this->migrationService = $migrationService;
        $this->htaccessService = $htaccessService ?? new HtaccessService();
        $this->requestValidator = $requestValidator ?? new RequestValidator();
        $this->responseFormatter = $responseFormatter ?? new ResponseFormatter();
        $this->destinationGuard = $destinationGuard ?? new DestinationGuard();
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

            $backupDatabase = filter_var($params['backup_database'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
            $backupFiles = filter_var($params['backup_files'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
            $insecureTls = filter_var($params['insecure_tls'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            if (!$backupDatabase && !$backupFiles) {
                return $this->responseFormatter->error(
                    'invalid_selection',
                    'At least one of backup_database or backup_files must be true',
                    ['status' => 400]
                );
            }

            $pinnedIp = $this->destinationGuard->assertAllowed($destinationUrl, is_string($ip) ? $ip : null);

            $result = $this->migrationService->scanAndPrepareMigration(
                $destinationUrl,
                $exclusions,
                $token,
                $ip,
                false,
                $backupDatabase,
                $backupFiles,
                $pinnedIp,
                $insecureTls
            );

            $response = [
                'success' => true,
                'migration_id' => $result['migrationId'],
                'file_path' => $this->migrationService->getMigrationFilePath($result['migrationId']),
                'summary' => $this->responseFormatter->convertKeysToSnakeCase($result['migrationData']['summary'])
            ];

            return $this->responseFormatter->success($response);
        } catch (DestinationNotAllowedException $e) {
            return $this->responseFormatter->error('destination_not_allowed', $e->getMessage(), ['status' => 400]);
        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
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
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);

            $raw = $this->requestValidator->getParam($request, 'raw', false);

            if ($raw) {
                $data = $this->migrationService->getMigrationInfoRaw($migrationId);

                if (!$data) {
                    return $this->responseFormatter->error('not_found', 'Migration information not found', ['status' => 404]);
                }

                return $this->responseFormatter->success(MigrationInfoGenerator::withoutSecrets($data));
            }

            $data = $this->migrationService->getMigrationInfo($migrationId);

            if (!$data) {
                return $this->responseFormatter->error('not_found', 'Migration information not found', ['status' => 404]);
            }

            $convertedData = $this->responseFormatter->convertKeysToSnakeCase(MigrationInfoGenerator::withoutSecrets($data));

            return $this->responseFormatter->success($convertedData);
        } catch (\InvalidArgumentException $e) {
            return $this->responseFormatter->error('invalid_id', $e->getMessage(), ['status' => 400]);
        }
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

            if (!is_array($response) || empty($response['success'])) {
                throw new Exception('Destination did not confirm finalization');
            }

            if (!(new MigrationLock())->release($migrationId)) {
                return $this->responseFormatter->error(
                    'finalize_failed',
                    'Migration lock could not be released',
                    ['status' => 409]
                );
            }

            $result = [
                'success' => true,
                'message' => 'Migration finalized successfully',
                'response' => $response
            ];

            if (!empty($response['skipped_files'])) {
                $result['skipped_files'] = $response['skipped_files'];
                $result['warning'] = $response['warning'] ?? '';
            }

            return $this->responseFormatter->success($result);

        } catch (DestinationNotAllowedException $e) {
            return $this->responseFormatter->error('destination_not_allowed', $e->getMessage(), ['status' => 400]);
        } catch (\InvalidArgumentException $e) {
            return $this->responseFormatter->error('invalid_id', $e->getMessage(), ['status' => 400]);
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

        } catch (DestinationNotAllowedException $e) {
            return $this->responseFormatter->error('destination_not_allowed', $e->getMessage(), ['status' => 400]);
        } catch (\InvalidArgumentException $e) {
            return $this->responseFormatter->error('invalid_id', $e->getMessage(), ['status' => 400]);
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
            if ($e instanceof \InvalidArgumentException && $e->getMessage() === 'Invalid migration ID') {
                return $this->responseFormatter->error('invalid_id', 'Invalid migration ID', ['status' => 400]);
            }

            return $this->responseFormatter->error('complete_failed', $e->getMessage(), ['status' => 500]);
        }
    }
}