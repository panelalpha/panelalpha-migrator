<?php

namespace PanelAlpha\Migrator\Services\Migration;

use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\DateTime;
use PanelAlpha\Migrator\Helpers\DestinationEndpoint;
use PanelAlpha\Migrator\Helpers\DestinationGuard;
use PanelAlpha\Migrator\Helpers\Http;
use PanelAlpha\Migrator\Helpers\JsonCompressor;
use PanelAlpha\Migrator\Helpers\PathProvider;
use PanelAlpha\Migrator\Helpers\TokenHelper;

/**
 * Class MigrationFinalizer
 *
 * @package PanelAlpha\Migrator\Services\Migration
 *
 * Handles the finalization of a migration.
 */
class MigrationFinalizer
{
    private $migrationFilePath;
    private $migrationData;
    private int $attempts;
    private int $pauseMicroseconds;

    /**
     * MigrationFinalizer constructor.
     *
     * @param string $migrationId
     * @param int    $attempts          How many times finalize is asked while the destination answers it is still working
     * @param int    $pauseMicroseconds Pause between those attempts
     * @throws \Exception
     */
    public function __construct($migrationId, int $attempts = 10, int $pauseMicroseconds = 1000000)
    {
        $this->attempts = max(1, $attempts);
        $this->pauseMicroseconds = max(0, $pauseMicroseconds);
        $this->migrationFilePath = PathProvider::getMigrationFilePath($migrationId);
        $this->loadMigrationData();
        $this->migrationData = array_merge($this->migrationData, (new MigrationStorageService())->loadSecrets($migrationId));
    }

    /**
     * Loads migration data from the migration file.
     *
     * @throws \Exception
     */
    private function loadMigrationData()
    {
        if (!file_exists($this->migrationFilePath)) {
            throw new \Exception('Migration file not found');
        }

        $json = file_get_contents($this->migrationFilePath);
        $this->migrationData = JsonCompressor::decompressFromJson($json);

    }

    /**
     * Send migration complete signal to destination.
     * While the destination answers that it is still finishing, finalize is asked again a few
     * times. A result with finalized false means it is still working after the last attempt.
     *
     * @return array Response from destination
     * @throws \Exception
     */
    public function sendCompletionSignal()
    {
        if (!$this->isComplete()) {
            throw new \Exception('Migration is not complete yet');
        }

        $summary = $this->calculateFinalSummary();

        $completionData = [
            'type' => 'migration_complete',
            'migration_id' => $this->migrationData['migrationId'],
            'summary' => $summary,
            'start_time' => $this->migrationData['timestamp'],
            'end_time' => DateTime::currentTime('c'),
            'duration' => $this->calculateDuration(),
            'status' => 'completed',
            'skipped_files' => $this->skippedFiles()
        ];

        $response = $this->sendToDestination($completionData);

        for ($attempt = 1; $attempt < $this->attempts && $this->isStillFinishing($response); $attempt++) {
            usleep($this->pauseMicroseconds);
            $response = $this->sendToDestination($completionData);
        }

        if ($this->isStillFinishing($response)) {
            return $response;
        }

        if (is_array($response) && $completionData['skipped_files'] !== []) {
            $response['skipped_files'] = $completionData['skipped_files'];
            $response['warning'] = 'Some files were deleted on the source before they could be sent and were skipped.';
        }

        return $response;
    }

    /**
     * The destination confirmed the request but has not finished the migration yet.
     *
     * @param mixed $response
     * @return bool
     */
    private function isStillFinishing($response): bool
    {
        return is_array($response) && !empty($response['success']) && ($response['finalized'] ?? true) === false;
    }

    /**
     * Relative paths of the files that were skipped, as listed in the manifest.
     *
     * @return string[]
     */
    private function skippedFiles(): array
    {
        $paths = [];

        foreach (($this->migrationData['files'] ?? []) as $file) {
            if (($file['status'] ?? '') === 'skipped' && is_string($file['path'] ?? null)) {
                $paths[] = $file['path'];
            }
        }

        return $paths;
    }

    /**
     * Calculate final migration summary.
     *
     * @return array Summary statistics
     */
    private function calculateFinalSummary()
    {
        $filesSent = 0;
        $filesFailed = 0;
        $filesSkipped = 0;
        $totalFileSize = 0;
        $fileChunksSent = 0;
        $fileChunksTotal = 0;

        foreach (($this->migrationData['files'] ?? []) as $file) {
            if ($file['status'] === 'sent') {
                $filesSent++;
            } elseif ($file['status'] === 'failed') {
                $filesFailed++;
            } elseif ($file['status'] === 'skipped') {
                $filesSkipped++;
            }

            $totalFileSize += $file['size'];

            foreach ($file['chunks'] as $chunk) {
                if (($chunk['status'] ?? '') === 'skipped') {
                    continue;
                }
                $fileChunksTotal++;
                if ($chunk['status'] === 'sent') {
                    $fileChunksSent++;
                }
            }
        }

        $tablesSent = 0;
        $tablesFailed = 0;
        $totalDbSize = 0;
        $totalRows = 0;
        $dbChunksSent = 0;
        $dbChunksTotal = 0;

        foreach (($this->migrationData['tables'] ?? []) as $table) {
            if ($table['status'] === 'sent') {
                $tablesSent++;
            } elseif ($table['status'] === 'failed') {
                $tablesFailed++;
            }

            $totalDbSize += $table['size'];
            $totalRows += $table['rowCount'];

            foreach ($table['chunks'] as $chunk) {
                $dbChunksTotal++;
                if ($chunk['status'] === 'sent') {
                    $dbChunksSent++;
                }
            }
        }

        return [
            'files' => [
                'total' => count($this->migrationData['files'] ?? []),
                'sent' => $filesSent,
                'failed' => $filesFailed,
                'skipped' => $filesSkipped,
                'total_size' => $totalFileSize
            ],
            'tables' => [
                'total' => count($this->migrationData['tables'] ?? []),
                'sent' => $tablesSent,
                'failed' => $tablesFailed,
                'total_rows' => $totalRows,
                'total_size' => $totalDbSize
            ],
            'chunks' => [
                'total' => $fileChunksTotal + $dbChunksTotal,
                'sent' => $fileChunksSent + $dbChunksSent,
                'failed' => ($fileChunksTotal - $fileChunksSent) + ($dbChunksTotal - $dbChunksSent)
            ]
        ];
    }

    /**
     * Calculate migration duration.
     *
     * @return string Duration in HH:MM:SS format
     * @throws \Exception
     */
    private function calculateDuration()
    {
        $start = new \DateTime($this->migrationData['timestamp']);
        $end = new \DateTime();
        $interval = $start->diff($end);

        return sprintf('%02d:%02d:%02d', $interval->h, $interval->i, $interval->s);
    }

    /**
     * Send completion data to destination.
     *
     * @param array $completionData Completion data to send
     * @return array Response from destination
     * @throws \Exception
     */
    private function sendToDestination($completionData)
    {
        $destinationUrl = $this->migrationData['destinationUrl']
            ?? $this->migrationData['destination_url']
            ?? $this->migrationData['du']
            ?? null;

        if (empty($destinationUrl)) {
            throw new \Exception('Destination URL is not defined.');
        }

        $endpoint = (new DestinationEndpoint($destinationUrl, DestinationEndpoint::modeFrom($this->migrationData)))->url('receive/finalize');

        $ip = $this->migrationData['ip'] ?? null;
        $token = TokenHelper::forMigration($this->migrationData);

        $headers = [
            'Content-Type' => 'application/json',
        ];
        if (!empty($token)) {
            $headers['X-Migrator-Token'] = $token;
        }

        $httpArgs = [
            'headers' => $headers,
            'body' => json_encode($completionData),
            'timeout' => 120,
        ];

        $pinned = $this->migrationData['pinned_ip'] ?? null;
        if (!is_string($pinned) || $pinned === '') {
            $pinned = (new DestinationGuard())->assertAllowed($destinationUrl, is_string($ip) && $ip !== '' ? $ip : null);
        }

        if ($pinned !== null) {
            $httpArgs['ip'] = $pinned;
        }

        $httpArgs = array_merge($httpArgs, Http::tlsArgs($this->migrationData));

        $response = $this->postCompletion($endpoint, $httpArgs);
        $decoded = $this->decodeConfirmation($response);
        $statusCode = Http::retrieveResponseCode($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            $body = Http::retrieveBody($response);
            throw new \Exception(esc_html('Destination returned error: ' . $statusCode . ' - ' . substr((string) $body, 0, 300)));
        }

        // 202 means the destination is still finishing, even without a finalized field.
        if ($statusCode === 202 && is_array($decoded) && !array_key_exists('finalized', $decoded)) {
            $decoded['finalized'] = false;
        }

        return $decoded ?? json_decode((string) Http::retrieveBody($response), true);
    }

    /**
     * Posts the completion signal and returns the response.
     *
     * @param string $endpoint
     * @param array  $httpArgs
     * @return \PanelAlpha\Migrator\Helpers\HttpResponse
     * @throws \Exception
     */
    private function postCompletion(string $endpoint, array $httpArgs)
    {
        $response = Http::post($endpoint, $httpArgs);

        if (Http::isError($response)) {
            throw new \Exception(esc_html('Failed to send completion signal: ' . $response->getErrorMessage()));
        }

        return $response;
    }

    /**
     * Returns the decoded answer when it is a confirmation, otherwise null.
     *
     * @param \PanelAlpha\Migrator\Helpers\HttpResponse $response
     * @return array|null
     */
    private function decodeConfirmation($response): ?array
    {
        $statusCode = Http::retrieveResponseCode($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $decoded = json_decode((string) Http::retrieveBody($response), true);

        return is_array($decoded) && !empty($decoded['success']) ? $decoded : null;
    }

    /**
     * Check if migration is complete.
     *
     * @return bool True if all chunks are sent
     */
    public function isComplete()
    {
        foreach (($this->migrationData['files'] ?? []) as $file) {
            if (!$this->itemIsResolved($file, true)) {
                return false;
            }
        }

        foreach (($this->migrationData['tables'] ?? []) as $table) {
            if (!$this->itemIsResolved($table, false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A file is resolved when it was sent or explicitly skipped.
     * A table is resolved only when it was sent. Pending and failed items block finalization.
     *
     * @param array $item
     * @param bool  $allowSkip
     * @return bool
     */
    private function itemIsResolved(array $item, bool $allowSkip): bool
    {
        $status = $item['status'] ?? '';
        $resolved = $status === 'sent' || ($allowSkip && $status === 'skipped');

        if (!$resolved) {
            return false;
        }

        foreach (($item['chunks'] ?? []) as $chunk) {
            $chunkStatus = $chunk['status'] ?? '';
            if ($chunkStatus === 'sent' || ($allowSkip && $chunkStatus === 'skipped')) {
                continue;
            }

            return false;
        }

        return true;
    }
}
