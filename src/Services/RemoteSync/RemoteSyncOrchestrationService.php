<?php

namespace WordpressMigrator\Services\RemoteSync;

use Exception;
use WordpressMigrator\Services\Backup\BackupService;
use WordpressMigrator\Services\Migration\MigrationService;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\DefaultExclusions;
use WordpressMigrator\Helpers\MigrationLock;
use WordpressMigrator\Helpers\PathProvider;
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

    /**
     * @param MigrationService $migrationService
     * @param BackupService $backupService
     * @param RemoteSyncExecutor|null $executor
     * @param MigrationLock|null $lock
     */
    public function __construct(
        MigrationService   $migrationService,
        BackupService      $backupService,
        RemoteSyncExecutor $executor = null,
        MigrationLock      $lock = null
    )
    {
        $this->migrationService = $migrationService;
        $this->backupService = $backupService;
        $this->executor = $executor ?? new RemoteSyncExecutor($backupService);
        $this->lock = $lock ?? new MigrationLock();
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
        $base64 = $options['base64'] ?? false;

        $result = $this->migrationService->scanAndPrepareMigration(
            $destinationUrl,
            $exclusionsNewlineFormat,
            $token,
            $ip,
            $base64
        );

        $migrationId = $result['migrationId'];

        if (!$this->lock->acquire($migrationId)) {
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
            'next_phase' => 'database',
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

        if (!$backupStatus) {
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
     * @throws Exception If validation fails
     */
    private function validateOptions(array $options): void
    {
        if (empty($options['destination_url'])) {
            throw new Exception('destination_url is required');
        }

        if (!filter_var($options['destination_url'], FILTER_VALIDATE_URL)) {
            throw new Exception('destination_url must be a valid URL');
        }

        $backupDb = $options['backup_database'] ?? true;
        $backupFiles = $options['backup_files'] ?? true;

        if (!$backupDb && !$backupFiles) {
            throw new Exception('At least one of backup_database or backup_files must be true');
        }
    }

    /**
     * Parse destination URL and extract token.
     *
     * @param string $fullUrl Full URL with token
     * @return array Array with url and token keys
     * @throws Exception
     */
    private function parseDestinationUrl(string $fullUrl): array
    {
        $parsed = parse_url($fullUrl);

        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new Exception('Invalid destination URL format');
        }

        $token = null;
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            $token = $queryParams['token'] ?? null;
        }

        $url = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }
        if (isset($parsed['path'])) {
            $url .= $parsed['path'];
        }

        if (!$token) {
            throw new Exception('Token not found in destination URL');
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new Exception('Invalid token format');
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
        $baseUrl = Config::get('base_url', '');

        if (empty($baseUrl)) {
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        return rtrim($baseUrl, '/') . '/wp-json/wp-migrator/v1/remote-sync/status/' . $migrationId;
    }

    private function getProcessBatchUrl(): string
    {
        $baseUrl = Config::get('base_url', '');

        if (empty($baseUrl)) {
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        return rtrim($baseUrl, '/') . '/wp-json/wp-migrator/v1/remote-sync/process-batch';
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

        $phase = $this->determineCurrentPhase($backupStatus);

        if ($phase === 'database') {
            $result = $this->backupService->getDatabaseNextChunksBatch($migrationId, $batchSize);

            $backupStatus = $this->backupService->getBackupStatus($migrationId);

            $progress = [
                'percentage' => $backupStatus['database']['percentage'] ?? 0,
                'current_item' => $backupStatus['database']['current_table'] ?? null,
                'next_item' => $backupStatus['database']['next_table'] ?? null,
                'sent_chunks' => $backupStatus['database']['sent_chunks'] ?? 0,
                'total_chunks' => $backupStatus['database']['total_chunks'] ?? 0
            ];

            $nextPhase = $this->determineNextPhaseForBatch($migrationId, 'database', $result['completed']);

            $response = [
                'migration_id' => $migrationId,
                'phase' => $phase,
                'processed_chunks' => $result['processed_chunks'],
                'completed' => $result['completed'],
                'progress' => $progress,
                'next_phase' => $nextPhase
            ];

            if ($result['completed'] && $nextPhase === 'files') {
                $response['message'] = 'Database phase completed. Continue with files phase.';
            }

            return $response;

        }

        if ($phase === 'files') {
            $result = $this->backupService->getFileNextChunksBatch($migrationId, $batchSize);

            $backupStatus = $this->backupService->getBackupStatus($migrationId);

            $progress = [
                'percentage' => $backupStatus['files']['percentage'] ?? 0,
                'current_item' => $backupStatus['files']['current_file'] ?? null,
                'next_item' => $backupStatus['files']['next_file'] ?? null,
                'sent_chunks' => $backupStatus['files']['sent_chunks'] ?? 0,
                'total_chunks' => $backupStatus['files']['total_chunks'] ?? 0
            ];

            $shouldFinalize = $this->shouldFinalize($migrationId);

            $response = [
                'migration_id' => $migrationId,
                'phase' => $phase,
                'processed_chunks' => $result['processed_chunks'],
                'completed' => $result['completed'],
                'progress' => $progress,
                'skipped_files' => $backupStatus['files']['skipped_files'] ?? []
            ];

            if ($shouldFinalize) {
                $finalizeResult = $this->migrationService->finalizeMigration($migrationId);
                $this->lock->release();

                $response['finalized'] = true;
                $response['message'] = 'Migration completed and finalized successfully';
                $response['summary'] = $finalizeResult;
            } else {
                $response['next_phase'] = $result['completed'] ? null : 'files';
            }

            return $response;
        }

        if ($phase === 'completed') {
            return [
                'migration_id' => $migrationId,
                'phase' => 'completed',
                'processed_chunks' => 0,
                'finalized' => true,
                'completed' => true,
                'message' => 'Migration is already completed'
            ];
        }

        throw new Exception('Invalid phase: ' . $phase);
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

    private function shouldFinalize(string $migrationId): bool
    {
        $status = $this->backupService->getBackupStatus($migrationId);

        if (!$status || ($status['status'] ?? '') === 'not_found') {
            return false;
        }

        $dbStatus = $status['database']['status'] ?? null;
        $filesStatus = $status['files']['status'] ?? null;

        $dbComplete = ($status['database'] === null || $dbStatus === 'completed');
        $filesComplete = ($status['files'] === null || $filesStatus === 'completed');

        return $dbComplete && $filesComplete;
    }

    private function determineCurrentPhase(array $backupStatus): string
    {
        $dbStatus = $backupStatus['database']['status'] ?? null;
        $filesStatus = $backupStatus['files']['status'] ?? null;

        $dbDone = ($backupStatus['database'] === null || $dbStatus === 'completed');
        $filesDone = ($backupStatus['files'] === null || $filesStatus === 'completed');

        if (!$dbDone) {
            return 'database';
        }

        if (!$filesDone) {
            return 'files';
        }

        return 'completed';
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

        $dbStatus = $status['database']['status'] ?? 'pending';
        $filesStatus = $status['files']['status'] ?? 'pending';

        if ($dbStatus === 'in_progress') {
            return 'backup_database';
        }

        if ($filesStatus === 'in_progress') {
            return 'backup_files';
        }

        if ($dbStatus === 'completed' && $filesStatus === 'completed') {
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

        $dbStatus = $status['database']['status'] ?? 'pending';
        $filesStatus = $status['files']['status'] ?? 'pending';

        $hasDb = $dbStatus !== 'pending';
        $hasFiles = $filesStatus !== 'pending';

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
