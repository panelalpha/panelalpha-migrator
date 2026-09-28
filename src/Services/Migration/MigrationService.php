<?php

namespace WordpressMigrator\Services\Migration;

use Exception;
use WordpressMigrator\Api\RouteProvider;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\ExclusionParser;
use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Services\Scanner\FileScanner;
use WordpressMigrator\Services\Migration\MigrationInfoGenerator;
use WordpressMigrator\Services\Migration\MigrationFinalizer;
use WordpressMigrator\Services\Migration\MigrationRegistry;
use WordpressMigrator\Services\Scanner\FileScannerConfig;

/**
 * Class MigrationService
 *
 * @package WordpressMigrator\Services\Migration
 *
 * Service for handling migration operations.
 */
class MigrationService
{
    private $databaseScanner;
    private $migrationInfoGenerator;
    private $migrationStorageService;

    /**
     * MigrationService constructor.
     *
     * @param DatabaseScanner         $databaseScanner
     * @param MigrationInfoGenerator  $migrationInfoGenerator
     * @param MigrationStorageService $migrationStorageService
     */
    public function __construct(
        DatabaseScanner $databaseScanner,
        MigrationInfoGenerator $migrationInfoGenerator,
        MigrationStorageService $migrationStorageService
    ) {
        $this->databaseScanner = $databaseScanner;
        $this->migrationInfoGenerator = $migrationInfoGenerator;
        $this->migrationStorageService = $migrationStorageService;
    }

    /**
     * Scans the site and prepares a migration.
     *
     * @param string      $destinationUrl
     * @param string|null $exclusionsInput
     * @param string|null $token
     * @param string|null $ip
     * @param bool        $base64
     * @return array
     */
    public function scanAndPrepareMigration(string $destinationUrl, ?string $exclusionsInput = null, ?string $token = null, ?string $ip = null, bool $base64 = false): array
    {
        $exclusions = null;
        if (!empty($exclusionsInput)) {
            $exclusions = ExclusionParser::parse($exclusionsInput);
        }

        $fileScanner = RouteProvider::createFileScanner($exclusions);

        $files = $fileScanner->scan();
        $tables = $this->databaseScanner->scan();

        $migrationData = $this->migrationInfoGenerator->generate($files, $tables, $destinationUrl);

        $migrationDataArray = json_decode(json_encode($migrationData), true);

        if (!isset($migrationDataArray['type'])) {
            $migrationDataArray['type'] = 'info';
        }

        if (!empty($token)) {
            $migrationDataArray['token'] = $token;
        }

        if (!empty($ip)) {
            $migrationDataArray['ip'] = $ip;
        }

        if ($base64) {
            $migrationDataArray['base64'] = true;
        }

        $migrationId = $migrationData->migrationId;
        $this->migrationStorageService->saveMigrationData($migrationId, $migrationDataArray);

        return [
            'migrationId' => $migrationId,
            'migrationData' => $migrationDataArray
        ];
    }

    /**
     * Gets information about a specific migration.
     *
     * @param string $migrationId
     * @return array|null
     */
    public function getMigrationInfo(string $migrationId): ?array
    {
        if (!$this->migrationStorageService->migrationExists($migrationId)) {
            return null;
        }

        $data = $this->migrationStorageService->loadMigrationData($migrationId);

        if (!isset($data['type'])) {
            $data['type'] = 'info';
        }

        return $data;
    }

    /**
     * Gets raw (compressed) migration information.
     *
     * @param string $migrationId
     * @return array|null
     */
    public function getMigrationInfoRaw(string $migrationId): ?array
    {
        if (!$this->migrationStorageService->migrationExists($migrationId)) {
            return null;
        }

        $filePath = $this->migrationStorageService->getMigrationFilePath($migrationId);
        $json = file_get_contents($filePath);
        $data = json_decode($json, true);

        if (!isset($data['t'])) {
            $data['t'] = 'info';
        }

        return $data;
    }
    
    /**
     * Gets the file path for a migration.
     *
     * @param string $migrationId
     * @return string
     */
    public function getMigrationFilePath(string $migrationId): string
    {
        return $this->migrationStorageService->getMigrationFilePath($migrationId);
    }

    /**
     * Lists all migrations.
     *
     * @return array
     */
    public function listMigrations(): array
    {
        return $this->migrationStorageService->listMigrations();
    }
    
    /**
     * Finalizes a migration.
     *
     * @param string $migrationId
     * @return mixed
     * @throws Exception
     */
    public function finalizeMigration(string $migrationId)
    {
        $finalization = new MigrationFinalizer($migrationId);

        if (!$finalization->isComplete()) {
            throw new Exception('Migration is not complete yet');
        }

        return $finalization->sendCompletionSignal();
    }

    /**
     * Marks a migration as complete.
     *
     * @param string $migrationId
     * @return array|null
     */
    public function completeMigration(string $migrationId): ?array
    {
        $registry = new MigrationRegistry($migrationId);
        $status = $registry->getStatus();

        if (!$status) {
            return null;
        }

        $registry->updateStatus('collected');

        return ['old_status' => $status, 'new_status' => 'collected'];
    }

}
