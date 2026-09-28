<?php

namespace WordpressMigrator\Services\Migration;

use Exception;
use WordpressMigrator\Helpers\Filesystem;
use WordpressMigrator\Helpers\Http;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Helpers\PathProvider;

/**
 * Class HtaccessService
 *
 * @package WordpressMigrator\Services\Migration
 *
 * Handles transferring and receiving .htaccess files between sites during migration.
 */
class HtaccessService
{
    /**
     * Reads the local .htaccess and transfers it to the destination site.
     *
     * Migration data (destination URL, token, IP) is loaded from the migration file.
     * Returns ['transferred' => false, 'message' => '...'] when there is no .htaccess to send.
     *
     * @param string $migrationId
     * @return array
     * @throws Exception
     */
    public function transfer(string $migrationId): array
    {
        $migrationFilePath = PathProvider::getMigrationFilePath($migrationId);
        $migrationData = JsonCompressor::decompressFromJson(file_get_contents($migrationFilePath));

        $destinationUrl = $migrationData['destinationUrl']
            ?? $migrationData['destination_url']
            ?? $migrationData['du']
            ?? null;

        if (empty($destinationUrl)) {
            throw new Exception('Destination URL not found in migration data');
        }

        $token = $migrationData['token'] ?? null;
        $ip    = $migrationData['ip']    ?? null;

        $htaccessPath = PathProvider::getAbsPath() . '.htaccess';

        if (!file_exists($htaccessPath)) {
            return ['transferred' => false, 'message' => 'No .htaccess file found on source'];
        }

        $content = file_get_contents($htaccessPath);
        if ($content === false) {
            throw new Exception('Failed to read .htaccess file');
        }

        $endpointPretty = Filesystem::trailingSlashIt($destinationUrl) . 'wp-json/wp-migrator/v1/receive-htaccess';
        $endpointRestRoute = rtrim($destinationUrl, '/');
        $endpointRestRoute .= (strpos($endpointRestRoute, '?') !== false) ? '&' : '/?';
        $endpointRestRoute .= 'rest_route=/wp-migrator/v1/receive-htaccess';

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($token)) {
            $headers['X-Migrator-Token'] = $token;
        }

        $httpArgs = [
            'headers'   => $headers,
            'body'      => json_encode(['content' => $content, 'migration_id' => $migrationId]),
            'timeout'   => 30,
            'sslverify' => false,
        ];

        if (!empty($ip)) {
            $httpArgs['ip'] = $ip;
        }

        $response = Http::post($endpointPretty, $httpArgs);

        if (Http::isError($response)) {
            throw new Exception('Failed to transfer .htaccess: ' . $response->getErrorMessage());
        }

        $statusCode = Http::retrieveResponseCode($response);

        // Some servers do not route /wp-json/* when rewrites/permalinks are not configured.
        // Fallback to the non-pretty REST route which works without mod_rewrite.
        if ($statusCode === 404) {
            $response = Http::post($endpointRestRoute, $httpArgs);
            if (Http::isError($response)) {
                throw new Exception('Failed to transfer .htaccess: ' . $response->getErrorMessage());
            }
            $statusCode = Http::retrieveResponseCode($response);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $responseBody = Http::retrieveBody($response);
            throw new Exception('Destination returned error ' . $statusCode . ': ' . $responseBody);
        }

        return ['transferred' => true];
    }

    /**
     * Writes received .htaccess content to the local filesystem.
     *
     * @param string $content
     * @return array
     * @throws Exception
     */
    public function receive(string $content): array
    {
        $htaccessPath = PathProvider::getAbsPath() . '.htaccess';

        $bytesWritten = file_put_contents($htaccessPath, $content);

        if ($bytesWritten === false) {
            throw new Exception('Failed to write .htaccess file');
        }

        return ['bytes_written' => $bytesWritten];
    }
}
