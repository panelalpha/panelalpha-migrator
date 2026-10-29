<?php

namespace PanelAlpha\Migrator\Services\Migration;

use Exception;
use PanelAlpha\Migrator\Api\RouteProvider;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\DestinationEndpoint;
use PanelAlpha\Migrator\Helpers\ExclusionParser;
use PanelAlpha\Migrator\Helpers\MigrationLock;
use PanelAlpha\Migrator\Services\Scanner\DatabaseScanner;
use PanelAlpha\Migrator\Services\Scanner\FileScanner;
use PanelAlpha\Migrator\Services\Migration\MigrationInfoGenerator;
use PanelAlpha\Migrator\Services\Migration\MigrationFinalizer;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;
use PanelAlpha\Migrator\Services\Scanner\FileScannerConfig;

/**
 * Class MigrationService
 *
 * @package PanelAlpha\Migrator\Services\Migration
 *
 * Service for handling migration operations.
 */
class MigrationService
{
    private $databaseScanner;
    private $migrationInfoGenerator;
    private $migrationStorageService;
    private MigrationLock $lock;

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
        MigrationStorageService $migrationStorageService,
        ?MigrationLock $lock = null
    ) {
        $this->databaseScanner = $databaseScanner;
        $this->migrationInfoGenerator = $migrationInfoGenerator;
        $this->migrationStorageService = $migrationStorageService;
        $this->lock = $lock ?? new MigrationLock();
    }

    /**
     * Scans the site and prepares a migration.
     *
     * @param string      $destinationUrl
     * @param string|null $exclusionsInput
     * @param string|null $token
     * @param string|null $ip
     * @param bool        $base64
     * @param bool        $backupDatabase
     * @param bool        $backupFiles
     * @param string|null $pinnedIp
     * @param bool        $insecureTls Skip the HTTPS certificate check; only used with a manual IP
     * @param string|null $restMode    REST URL form found when the destination was checked, see DestinationEndpoint
     * @return array
     */
    public function scanAndPrepareMigration(string $destinationUrl, ?string $exclusionsInput = null, ?string $token = null, ?string $ip = null, bool $base64 = false, bool $backupDatabase = true, bool $backupFiles = true, ?string $pinnedIp = null, bool $insecureTls = false, ?string $restMode = null): array
    {
        if ($this->lock->isLocked()) {
            throw new Exception('Another migration is in progress. Wait for it to finish before scanning.');
        }

        $exclusions = null;
        if (!empty($exclusionsInput)) {
            $exclusions = ExclusionParser::parse($exclusionsInput);
        }

        $files = [];
        if ($backupFiles) {
            $fileScanner = RouteProvider::createFileScanner($exclusions);
            $files = $fileScanner->scan();
        }

        $tables = [];
        if ($backupDatabase) {
            $tables = $this->databaseScanner->scan();
        }

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

        if (!empty($ip) && $insecureTls) {
            $migrationDataArray['insecure_tls'] = true;
            error_log('MIGRATOR: HTTPS certificate checks are off for migration ' . $migrationData->migrationId . ' (manual IP with insecure_tls).');
        }

        if (!empty($pinnedIp)) {
            $migrationDataArray['pinned_ip'] = $pinnedIp;
        }

        if ($restMode !== null) {
            $migrationDataArray[DestinationEndpoint::MODE_KEY] = $restMode;
        }

        if ($base64) {
            $migrationDataArray['base64'] = true;
        }

        $migrationDataArray['backup_database'] = $backupDatabase;
        $migrationDataArray['backup_files'] = $backupFiles;

        $migrationId = $migrationData->migrationId;

        // A scan only reads and writes its own migration file. The lock is taken
        // when the backup starts, so an abandoned scan never blocks anything.
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
        $data = is_array($data) ? $data : [];
        $data = $this->migrationStorageService->loadSecrets($migrationId) + $data;

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
