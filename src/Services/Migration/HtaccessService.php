<?php

namespace PanelAlpha\Migrator\Services\Migration;

use Exception;
use PanelAlpha\Migrator\Helpers\DestinationEndpoint;
use PanelAlpha\Migrator\Helpers\DestinationGuard;
use PanelAlpha\Migrator\Helpers\Http;
use PanelAlpha\Migrator\Helpers\JsonCompressor;
use PanelAlpha\Migrator\Helpers\PathProvider;
use PanelAlpha\Migrator\Helpers\TokenHelper;

/**
 * Class HtaccessService
 *
 * @package PanelAlpha\Migrator\Services\Migration
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
        $migrationData = array_merge(
            JsonCompressor::decompressFromJson(file_get_contents($migrationFilePath)),
            (new MigrationStorageService())->loadSecrets($migrationId)
        );

        $destinationUrl = $migrationData['destinationUrl']
            ?? $migrationData['destination_url']
            ?? $migrationData['du']
            ?? null;

        if (empty($destinationUrl)) {
            throw new Exception('Destination URL not found in migration data');
        }

        $token = TokenHelper::forMigration($migrationData);
        $ip    = $migrationData['ip']    ?? null;

        $htaccessPath = PathProvider::getAbsPath() . '.htaccess';

        if (!file_exists($htaccessPath)) {
            return ['transferred' => false, 'message' => 'No .htaccess file found on source'];
        }

        $content = file_get_contents($htaccessPath);
        if ($content === false) {
            throw new Exception('Failed to read .htaccess file');
        }

        $endpoint = (new DestinationEndpoint($destinationUrl, DestinationEndpoint::modeFrom($migrationData)))->url('receive-htaccess');

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($token)) {
            $headers['X-Migrator-Token'] = $token;
        }

        $httpArgs = [
            'headers'   => $headers,
            'body'      => json_encode(['content' => $content, 'migration_id' => $migrationId]),
            'timeout'   => 30,
        ];

        $pinned = $migrationData['pinned_ip'] ?? null;
        if (!is_string($pinned) || $pinned === '') {
            $pinned = (new DestinationGuard())->assertAllowed($destinationUrl, is_string($ip) && $ip !== '' ? $ip : null);
        }

        if ($pinned !== null) {
            $httpArgs['ip'] = $pinned;
        }

        $httpArgs = array_merge($httpArgs, Http::tlsArgs($migrationData));

        $response = Http::post($endpoint, $httpArgs);

        if (Http::isError($response)) {
            throw new Exception(esc_html('Failed to transfer .htaccess: ' . $response->getErrorMessage()));
        }

        $statusCode = Http::retrieveResponseCode($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            $responseBody = Http::retrieveBody($response);
            throw new Exception(esc_html('Destination returned error ' . $statusCode . ': ' . substr((string) $responseBody, 0, 300)));
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
