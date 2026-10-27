<?php

namespace WordpressMigrator\Services\RemoteSync;

use Exception;
use InvalidArgumentException;
use WordpressMigrator\Api\Middleware\ResponseFormatter;
use WordpressMigrator\Services\Backup\BackupService;
use WordpressMigrator\Services\Backup\ChunkHttpSender;
use WordpressMigrator\Services\Migration\MigrationInfoGenerator;
use WordpressMigrator\Services\Migration\MigrationService;
use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\DefaultExclusions;
use WordpressMigrator\Helpers\DestinationGuard;
use WordpressMigrator\Helpers\Http;
use WordpressMigrator\Helpers\MigrationLock;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Helpers\TokenHelper;
use WordpressMigrator\Restore\WpConfigParser;

/**
 * Remote sync orchestration service.
 *
 * @package WordpressMigrator\Services\RemoteSync
 */
class RemoteSyncOrchestrationService
{
    private MigrationService $migrationService;
    private BackupService $backupService;
    private RemoteSyncExecutor $executor;
    private MigrationLock $lock;
    private DestinationGuard $destinationGuard;
    private MigrationStorageService $storageService;

    /**
     * @param MigrationService $migrationService
     * @param BackupService $backupService
     * @param RemoteSyncExecutor|null $executor
     * @param MigrationLock|null $lock
     * @param DestinationGuard|null $destinationGuard
     * @param MigrationStorageService|null $storageService
     */
    public function __construct(
        MigrationService   $migrationService,
        BackupService      $backupService,
        ?RemoteSyncExecutor $executor = null,
        ?MigrationLock      $lock = null,
        ?DestinationGuard   $destinationGuard = null,
        ?MigrationStorageService $storageService = null
    )
    {
        $this->migrationService = $migrationService;
        $this->backupService = $backupService;
        $this->executor = $executor ?? new RemoteSyncExecutor($backupService);
        $this->lock = $lock ?? new MigrationLock();
        $this->destinationGuard = $destinationGuard ?? new DestinationGuard();
        $this->storageService = $storageService ?? new MigrationStorageService();
    }

    /**
     * Start a migration (scan and prepare).
     *
     * @param array $options Migration options
     * @return array Migration result with migration_id and status_url
     * @throws Exception
     */
    public function startMigration(array $options): array
    {
        $this->validateOptions($options);

        $force = $options['force'] ?? false;

        if ($force) {
            $this->lock->forceRelease();
        }

        if ($this->lock->isLocked()) {
            $runningMigration = $this->lock->getLockInfo();
            throw new Exception(
                'Another migration is already in progress. Migration ID: ' .
                ($runningMigration['migration_id'] ?? 'unknown') .
                '. Please wait for it to complete or cancel it first.'
            );
        }

        $parsed = $this->parseDestinationUrl($options['destination_url']);
        $destinationUrl = $parsed['url'];
        $token = $parsed['token'];

        $exclusions = DefaultExclusions::merge($options['exclusions'] ?? null);
        $exclusionsNewlineFormat = DefaultExclusions::toNewlineFormat($exclusions);
        $ip = $options['ip'] ?? null;
        $manualIp = is_string($ip) && $ip !== '' ? $ip : null;
        $pinnedIp = $this->destinationGuard->assertAllowed($destinationUrl, $manualIp);
        $base64 = $options['base64'] ?? false;
        $backupDatabase = filter_var($options['backup_database'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $backupFiles = filter_var($options['backup_files'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $insecureTls = filter_var($options['insecure_tls'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        $result = $this->migrationService->scanAndPrepareMigration(
            $destinationUrl,
            $exclusionsNewlineFormat,
            $token,
            $ip,
            $base64,
            $backupDatabase,
            $backupFiles,
            $pinnedIp,
            $insecureTls
        );

        $migrationId = $result['migrationId'];
        $info = $this->lock->getLockInfo();
        $heldBy = is_array($info) ? ($info['migration_id'] ?? null) : null;

        if ($heldBy !== $migrationId && !$this->lock->acquire($migrationId)) {
            throw new Exception('Failed to acquire migration lock. Another migration may have started.');
        }

        $statusUrl = $this->getStatusUrl($migrationId);
        $processBatchUrl = $this->getProcessBatchUrl();
        $tablePrefix = $this->getTablePrefix();

        return [
            'migration_id' => $migrationId,
            'status_url' => $statusUrl,
            'process_batch_url' => $processBatchUrl,
            'status' => 'ready',
            'next_phase' => $backupDatabase ? 'database' : 'files',
            'table_prefix' => $tablePrefix,
            'message' => 'Migration prepared successfully. Use process_batch_url to start processing batches.'
        ];
    }

    /**
     * Get migration status including backup progress.
     *
     * @param string $migrationId Migration ID
     * @return array|null Status data or null if not found
     */
    public function getMigrationStatus(string $migrationId): ?array
    {
        $backupStatus = $this->backupService->getBackupStatus($migrationId);

        if (!$backupStatus || ($backupStatus['status'] ?? '') === 'not_found') {
            return null;
        }

        $phase = $this->determinePhase($backupStatus);
        $percentage = $this->calculateOverallPercentage($backupStatus);
        $message = $this->buildStatusMessage($backupStatus, $phase);

        return [
            'migration_id' => $migrationId,
            'phase' => $phase,
            'percentage' => $percentage,
            'message' => $message,
            'backup_running' => $backupStatus['backup_running'] ?? false,
            'status' => $backupStatus['status'] ?? 'pending',
            'database' => $backupStatus['database'] ?? null,
            'files' => $backupStatus['files'] ?? null,
            'last_activity' => $backupStatus['last_activity'] ?? null,
            'skipped_files' => $backupStatus['files']['skipped_files'] ?? []
        ];
    }

    /**
     * Validate migration options.
     *
     * @param array $options Options to validate
     * @throws InvalidArgumentException If validation fails
     */
    private function validateOptions(array $options): void
    {
        if (empty($options['destination_url'])) {
            throw new InvalidArgumentException('destination_url is required');
        }

        if (!is_string($options['destination_url']) || !filter_var($options['destination_url'], FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('destination_url must be a valid URL');
        }

        $backupDb = filter_var($options['backup_database'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $backupFiles = filter_var($options['backup_files'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        if (!$backupDb && !$backupFiles) {
            throw new InvalidArgumentException('At least one of backup_database or backup_files must be true');
        }
    }

    /**
     * Parse destination URL and extract token.
     *
     * @param string $fullUrl Full URL with token
     * @return array Array with url and token keys
     * @throws InvalidArgumentException
     */
    private function parseDestinationUrl(string $fullUrl): array
    {
        $parsed = parse_url($fullUrl);

        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new InvalidArgumentException('Invalid destination URL format');
        }

        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('destination_url must use http or https');
        }

        $token = null;
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            $token = $queryParams['token'] ?? null;
        }

        $url = $scheme . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }
        if (isset($parsed['path'])) {
            $url .= $parsed['path'];
        }

        if (!$token || !is_string($token)) {
            throw new InvalidArgumentException('Token not found in destination URL');
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new InvalidArgumentException('Invalid token format');
        }

        return ['url' => $url, 'token' => $token];
    }

    /**
     * Get the status URL for a migration.
     *
     * @param string $migrationId Migration ID
     * @return string Status URL
     */
    private function getStatusUrl(string $migrationId): string
    {
        return $this->getRestRouteUrl('remote-sync/status/' . $migrationId);
    }

    private function getProcessBatchUrl(): string
    {
        return $this->getRestRouteUrl('remote-sync/process-batch');
    }

    /**
     * Get the URL of a plugin REST route.
     * WordPress knows the right form for the site, such as ?rest_route= without pretty permalinks.
     *
     * @param string $route Route below wp-migrator/v1
     * @return string
     */
    private function getRestRouteUrl(string $route): string
    {
        if (function_exists('rest_url')) {
            return rest_url('wp-migrator/v1/' . $route);
        }

        $baseUrl = Config::get('base_url', '');

        if (empty($baseUrl)) {
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        return rtrim($baseUrl, '/') . '/wp-json/wp-migrator/v1/' . $route;
    }

    /**
     * Get the table prefix for the source WordPress instance.
     *
     * @return string Table prefix
     */
    private function getTablePrefix(): string
    {
        try {
            $parser = new WpConfigParser(PathProvider::getAbsPath());
            $config = $parser->parse();
            return $config['table_prefix'] ?? 'wp_';
        } catch (\Throwable $e) {
            return 'wp_';
        }
    }

    public function processBatch(string $migrationId, int $batchSize = 50): array
    {
        $backupStatus = $this->backupService->getBackupStatus($migrationId);

        if (!$backupStatus || ($backupStatus['status'] ?? '') === 'not_found') {
            throw new Exception('Migration not found');
        }

        $this->ensureLockHeld($migrationId);

        $phase = $this->determineCurrentPhase($backupStatus);

        if ($phase === 'database' || $phase === 'files') {
            $this->ensureInfoSent($migrationId);
        }

        if ($phase === 'database') {
            $result = $this->backupService->getDatabaseNextChunksBatch($migrationId, $batchSize);

            $backupStatus = $this->backupService->getBackupStatus($migrationId);
            $database = is_array($backupStatus['database'] ?? null) ? $backupStatus['database'] : [];

            $progress = [
                'percentage' => $database['percentage'] ?? 0,
                'current_item' => $database['current_table'] ?? null,
                'next_item' => $database['next_table'] ?? null,
                'sent_chunks' => $database['sent_chunks'] ?? 0,
                'total_chunks' => $database['total_chunks'] ?? 0
            ];

            $response = [
                'migration_id' => $migrationId,
                'phase' => $phase,
                'processed_chunks' => $result['processed_chunks'],
                'completed' => $result['completed'],
                'progress' => $progress,
            ];

            $filesDisabled = is_array($backupStatus['files'] ?? null)
                && (($backupStatus['files']['status'] ?? null) === 'disabled');

            if ($result['completed'] && $filesDisabled && $this->shouldFinalize($migrationId)) {
                $response['finalized'] = true;
                $response['message'] = 'Migration completed and finalized successfully';
                $response = $this->withSkippedFilesWarning($response, $this->finalizeWithDestination($migrationId));

                return $response;
            }

            $nextPhase = $this->determineNextPhaseForBatch($migrationId, 'database', $result['completed']);
            $response['next_phase'] = $nextPhase;

            if ($result['completed'] && $nextPhase === 'files') {
                $response['message'] = 'Database phase completed. Continue with files phase.';
            }

            return $response;

        }

        if ($phase === 'files') {
            $result = $this->backupService->getFileNextChunksBatch($migrationId, $batchSize);

            $backupStatus = $this->backupService->getBackupStatus($migrationId);
            $files = is_array($backupStatus['files'] ?? null) ? $backupStatus['files'] : [];

            $progress = [
                'percentage' => $files['percentage'] ?? 0,
                'current_item' => $files['current_file'] ?? null,
                'next_item' => $files['next_file'] ?? null,
                'sent_chunks' => $files['sent_chunks'] ?? 0,
                'total_chunks' => $files['total_chunks'] ?? 0
            ];

            $shouldFinalize = $this->shouldFinalize($migrationId);

            $response = [
                'migration_id' => $migrationId,
                'phase' => $phase,
                'processed_chunks' => $result['processed_chunks'],
                'completed' => $result['completed'],
                'progress' => $progress,
                'skipped_files' => $files['skipped_files'] ?? []
            ];

            if ($shouldFinalize) {
                $response['finalized'] = true;
                $response['message'] = 'Migration completed and finalized successfully';
                $response = $this->withSkippedFilesWarning($response, $this->finalizeWithDestination($migrationId));
            } else {
                $response['next_phase'] = $result['completed'] ? null : 'files';
            }

            return $response;
        }

        if ($phase === 'completed') {
            return $this->withSkippedFilesWarning([
                'migration_id' => $migrationId,
                'phase' => 'completed',
                'processed_chunks' => 0,
                'finalized' => true,
                'completed' => true,
                'message' => 'Migration completed and finalized successfully',
            ], $this->finalizeWithDestination($migrationId));
        }

        throw new Exception('Invalid phase: ' . $phase);
    }

    /**
     * Sends the info chunk to the destination once, before the first data chunk.
     * The destination builds its manifest from it, and cannot finish without it.
     * It is recorded as sent only after the destination confirms it.
     *
     * @param string $migrationId
     * @throws Exception
     */
    private function ensureInfoSent(string $migrationId): void
    {
        $this->lock->exclusive($migrationId, function () use ($migrationId) {
            $data = $this->storageService->loadMigrationData($migrationId);

            if (!empty($data['info_sent'])) {
                return;
            }

            $destinationUrl = $data['destinationUrl'] ?? $data['destination_url'] ?? $data['du'] ?? null;
            $token = TokenHelper::forMigration($data);

            if (empty($destinationUrl) || empty($token)) {
                throw new Exception('Destination URL or token not found in migration data');
            }

            $ip = $data['ip'] ?? null;
            $pinned = $data['pinned_ip'] ?? null;
            if (!is_string($pinned) || $pinned === '') {
                $pinned = $this->destinationGuard->assertAllowed(
                    $destinationUrl,
                    is_string($ip) && $ip !== '' ? $ip : null
                );
                $data['pinned_ip'] = $pinned;
            }

            $sender = new ChunkHttpSender(
                $destinationUrl,
                $token,
                !empty($ip) ? $ip : $pinned,
                (bool) ($data['base64'] ?? false),
                Http::tlsArgs($data)
            );

            $info = $data;
            $info['type'] = 'info';
            $sender->send(MigrationInfoGenerator::withoutSecrets(ResponseFormatter::convertKeysToSnakeCase($info)));

            $data['info_sent'] = true;
            $this->storageService->saveMigrationData($migrationId, $data);
        });
    }

    private
    function determineNextPhaseForBatch(string $migrationId, string $currentPhase, bool $completed): ?string
    {
        if (!$completed) {
            return $currentPhase;
        }

        if ($currentPhase === 'database') {
            return 'files';
        }

        return null;
    }

    /**
     * Takes the lock again for a migration whose lock expired while it was idle.
     * A live lock of another migration is left alone.
     *
     * @param string $migrationId
     * @throws Exception
     */
    private function ensureLockHeld(string $migrationId): void
    {
        $info = $this->lock->getLockInfo();
        $heldBy = is_array($info) ? ($info['migration_id'] ?? null) : null;

        if ($heldBy === $migrationId) {
            return;
        }

        if ($this->lock->isStale()) {
            $this->lock->release();
        }

        if (!$this->lock->acquire($migrationId)) {
            throw new Exception('Another migration is in progress. Migration ID: ' . ($heldBy ?? 'unknown'));
        }
    }

    /**
     * Asks the destination to finalize and releases the lock only after it confirms.
     *
     * @param string $migrationId
     * @return array
     * @throws Exception
     */
    private function finalizeWithDestination(string $migrationId): array
    {
        $result = $this->migrationService->finalizeMigration($migrationId);

        if (!is_array($result) || empty($result['success'])) {
            throw new Exception('Destination did not confirm finalization');
        }

        if (!$this->lock->release($migrationId)) {
            throw new Exception('Migration lock could not be released');
        }

        return $result;
    }

    /**
     * Adds the finalize result to a response, with the skipped files warning when there is one.
     *
     * @param array $response
     * @param array $result
     * @return array
     */
    private function withSkippedFilesWarning(array $response, array $result): array
    {
        $response['summary'] = $result;

        if (!empty($result['skipped_files'])) {
            $response['skipped_files'] = $result['skipped_files'];
            $response['warning'] = $result['warning'] ?? '';
        }

        return $response;
    }

    private function shouldFinalize(string $migrationId): bool
    {
        $status = $this->backupService->getBackupStatus($migrationId);

        if (!$status || ($status['status'] ?? '') === 'not_found') {
            return false;
        }

        $database = $status['database'] ?? null;
        $files = $status['files'] ?? null;

        return $this->phaseIsSettled($database) && $this->phaseIsSettled($files);
    }

    private function determineCurrentPhase(array $backupStatus): string
    {
        $database = $backupStatus['database'] ?? null;
        $files = $backupStatus['files'] ?? null;

        $dbDone = $this->phaseIsSettled($database) && !$this->requestedPhaseHasNotRun($database, $files);
        $filesDone = $this->phaseIsSettled($files) && !$this->requestedPhaseHasNotRun($files, $database);

        if (!$dbDone) {
            return 'database';
        }

        if (!$filesDone) {
            return 'files';
        }

        return 'completed';
    }

    /**
     * A missing phase is settled, as is one that finished or was not selected.
     * Pending work is not settled.
     */
    private function phaseIsSettled(?array $phase): bool
    {
        if ($phase === null) {
            return true;
        }

        $status = $phase['status'] ?? null;

        return $status === 'completed' || $status === 'disabled';
    }

    /**
     * The selected phase has no manifest yet, so it still has to be entered.
     * Disabled is not "completed with nothing" and must not skip that entry.
     */
    private function requestedPhaseHasNotRun(?array $requested, ?array $other): bool
    {
        return $requested === null && (($other['status'] ?? null) === 'disabled');
    }

    /**
     * Determine current migration phase.
     *
     * @param array $status Backup status
     * @return string Phase name
     */
    private function determinePhase(array $status): string
    {
        if ($status['status'] === 'completed') {
            return 'completed';
        }

        if (!($status['backup_running'] ?? false)) {
            return 'ready';
        }

        $dbStatus = is_array($status['database'] ?? null) ? ($status['database']['status'] ?? 'pending') : 'pending';
        $filesStatus = is_array($status['files'] ?? null) ? ($status['files']['status'] ?? 'pending') : 'pending';

        if ($dbStatus === 'in_progress') {
            return 'backup_database';
        }

        if ($filesStatus === 'in_progress') {
            return 'backup_files';
        }

        $dbSettled = $dbStatus === 'completed' || $dbStatus === 'disabled';
        $filesSettled = $filesStatus === 'completed' || $filesStatus === 'disabled';

        if ($dbSettled && $filesSettled && ($dbStatus === 'completed' || $filesStatus === 'completed')) {
            return 'finalizing';
        }

        return 'pending';
    }

    /**
     * Calculate overall migration percentage.
     *
     * @param array $status Backup status
     * @return int Percentage (0-100)
     */
    private
    function calculateOverallPercentage(array $status): int
    {
        $dbPercentage = $status['database']['percentage'] ?? 0;
        $filesPercentage = $status['files']['percentage'] ?? 0;

        $hasDb = $this->phaseCountsTowardProgress($status['database'] ?? null);
        $hasFiles = $this->phaseCountsTowardProgress($status['files'] ?? null);

        if (!$hasDb && !$hasFiles) {
            return 0;
        }

        if ($hasDb && !$hasFiles) {
            return (int)$dbPercentage;
        }

        if (!$hasDb && $hasFiles) {
            return (int)$filesPercentage;
        }

        return (int)(($dbPercentage + $filesPercentage) / 2);
    }

    private function phaseCountsTowardProgress(?array $phase): bool
    {
        if (!is_array($phase)) {
            return false;
        }

        $status = $phase['status'] ?? 'pending';

        return $status !== 'pending' && $status !== 'disabled';
    }

    /**
     * Build human-readable status message.
     *
     * @param array  $status Backup status
     * @param string $phase  Current phase
     * @return string Status message
     */
    private function buildStatusMessage(array $status, string $phase): string
    {
        switch ($phase) {
            case 'completed':
                return 'Migration completed successfully';

            case 'ready':
                return 'Migration prepared, ready to start backup';

            case 'backup_database':
                $db = $status['database'];
                return sprintf(
                    'Backing up database: %d/%d tables (%d/%d chunks)',
                    $db['completed_tables'] ?? 0,
                    $db['total_tables'] ?? 0,
                    $db['sent_chunks'] ?? 0,
                    $db['total_chunks'] ?? 0
                );

            case 'backup_files':
                $files = $status['files'];
                return sprintf(
                    'Backing up files: %d/%d files (%d/%d chunks)',
                    $files['completed_files'] ?? 0,
                    $files['total_files'] ?? 0,
                    $files['sent_chunks'] ?? 0,
                    $files['total_chunks'] ?? 0
                );

            case 'finalizing':
                return 'Backup complete, ready to finalize';

            default:
                return 'Migration pending';
        }
    }

}
