<?php

declare(strict_types=1);

namespace WordpressMigrator\Services\Backup;

use WordpressMigrator\Helpers\Filesystem;
use WordpressMigrator\Helpers\Http;
use WordpressMigrator\Helpers\JsonCompressor;

/**
 * Class ChunkHttpSender
 *
 * @package WordpressMigrator\Services\Backup
 *
 * Handles sending backup chunks to the destination server via HTTP.
 */
class ChunkHttpSender
{
    private string $destinationUrl;
    private string $authToken;
    private ?string $ip;
    private bool $useBase64;

    /**
     * ChunkHttpSender constructor.
     *
     * @param string $destinationUrl Destination server URL
     * @param string $authToken Authentication token
     * @param string|null $ip Optional IP address to override host
     * @param bool $useBase64 Whether to use base64 encoding for requests
     */
    public function __construct(string $destinationUrl, string $authToken, ?string $ip = null, bool $useBase64 = false)
    {
        $this->destinationUrl = $destinationUrl;
        $this->authToken = $authToken;
        $this->ip = $ip;
        $this->useBase64 = $useBase64;
    }

    /**
     * Sends a chunk to the destination server.
     *
     * @param array $chunkData Chunk data to send
     * @return array Response data from destination
     * @throws \Exception If sending fails or response is invalid
     */
    public function send(array $chunkData): array
    {
        $endpoint = $this->buildEndpoint();
        $response = $this->useBase64
            ? $this->sendHttpRequestBase64($endpoint, $chunkData)
            : $this->sendHttpRequest($endpoint, $chunkData);
        $this->validateResponse($response);

        return json_decode(Http::retrieveBody($response), true);
    }

    /**
     * Builds the destination endpoint URL.
     *
     * @return string Complete endpoint URL
     */
    private function buildEndpoint(): string
    {
        return Filesystem::trailingSlashIt($this->destinationUrl) 
            . 'wp-content/plugins/wordpress-migrator/restore.php?action=collect-and-restore';
    }

    /**
     * Sends an HTTP POST request with chunk data.
     *
     * @param string $endpoint Target endpoint URL
     * @param array  $chunkData Data to send
     * @return \WordpressMigrator\Helpers\HttpResponse|\WordpressMigrator\Helpers\HttpError
     */
    private function sendHttpRequest(string $endpoint, array $chunkData)
    {
        $httpArgs = [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Migrator-Token' => $this->authToken,
            ],
            'body' => JsonCompressor::compressToJson($chunkData),
            'timeout' => 120,
            'sslverify' => false
        ];

        if (!empty($this->ip)) {
            $httpArgs['ip'] = $this->ip;
        }

        return Http::post($endpoint, $httpArgs);
    }


      /**
     * Sends an HTTP POST request with chunk data.
     *
     * @param string $endpoint Target endpoint URL
     * @param array  $chunkData Data to send
     * @return \WordpressMigrator\Helpers\HttpResponse|\WordpressMigrator\Helpers\HttpError
     */
    private function sendHttpRequestBase64(string $endpoint, array $chunkData)
    {
        $jsonBody = JsonCompressor::compressToJson($chunkData);
        $encodedBody = base64_encode($jsonBody);

        $httpArgs = [
            'headers' => [
                'Content-Type' => 'application/octet-stream',
                'X-Migrator-Token' => $this->authToken,
                'X-Migrator-Encoding' => 'base64',
            ],
            'body' => $encodedBody,
            'timeout' => 120,
            'sslverify' => false
        ];

        if (!empty($this->ip)) {
            $httpArgs['ip'] = $this->ip;
        }

        return Http::post($endpoint, $httpArgs);
    }

    /**
     * Validates the HTTP response from destination.
     *
     * @param mixed $response HTTP response object
     * @throws \Exception If response indicates an error
     */
    private function validateResponse($response): void
    {
        if (Http::isError($response)) {
            throw new \Exception('Failed to send chunk: ' . $response->getErrorMessage());
        }

        $statusCode = Http::retrieveResponseCode($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            $body = Http::retrieveBody($response);
            throw new \Exception('Destination returned error: ' . $statusCode . ' - ' . $body);
        }
    }
}


