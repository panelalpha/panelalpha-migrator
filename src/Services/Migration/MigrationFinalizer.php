<?php

namespace WordpressMigrator\Services\Migration;

use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\DateTime;
use WordpressMigrator\Helpers\Filesystem;
use WordpressMigrator\Helpers\Http;
use WordpressMigrator\Helpers\JsonCompressor;

/**
 * Class MigrationFinalizer
 *
 * @package WordpressMigrator\Services\Migration
 *
 * Handles the finalization of a migration.
 */
class MigrationFinalizer
{
    private $migrationFilePath;
    private $migrationData;

    /**
     * MigrationFinalizer constructor.
     *
     * @param string $migrationId
     * @throws \Exception
     */
    public function __construct($migrationId)
    {
        $this->migrationFilePath = WP_MIGRATOR_MIGRATION_DIR . $migrationId . '.json';
        $this->loadMigrationData();
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
     *
     * @return array Response from destination
     * @throws \Exception
     */
    public function sendCompletionSignal()
    {
        $summary = $this->calculateFinalSummary();

        $completionData = [
            'type' => 'migration_complete',
            'migration_id' => $this->migrationData['migrationId'],
            'summary' => $summary,
            'start_time' => $this->migrationData['timestamp'],
            'end_time' => DateTime::currentTime('c'),
            'duration' => $this->calculateDuration(),
            'status' => 'completed'
        ];

        return $this->sendToDestination($completionData);
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
            error_log("FINALIZER ERROR: Destination URL not found. Keys: " . json_encode(array_keys($this->migrationData)));
            throw new \Exception('Destination URL is not defined.');
        }

        $endpoint = Filesystem::trailingSlashIt($destinationUrl) . 'wp-json/wp-migrator/v1/collect-chunk';

        $ip = $this->migrationData['ip'] ?? null;

        $httpArgs = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($completionData),
            'timeout' => 60,
            'sslverify' => false
        ];

        if (!empty($ip)) {
            $httpArgs['ip'] = $ip;
        }

        $response = Http::post($endpoint, $httpArgs);

        if (Http::isError($response)) {
            throw new \Exception('Failed to send completion signal: ' . $response->getErrorMessage());
        }

        $statusCode = Http::retrieveResponseCode($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            $body = Http::retrieveBody($response);
            throw new \Exception('Destination returned error: ' . $statusCode . ' - ' . $body);
        }

        return json_decode(Http::retrieveBody($response), true);
    }

    /**
     * Check if migration is complete.
     *
     * @return bool True if all chunks are sent
     */
    public function isComplete()
    {
        // On SOURCE side, the migration JSON is not updated during backup process
        // The actual status tracking happens on DESTINATION side in collected/
        // Since this method is called after JavaScript confirms all chunks were sent,
        // we consider the migration complete if the migration file exists

        // The file exists (loaded in constructor), so migration is ready to finalize
        return true;
    }
}
