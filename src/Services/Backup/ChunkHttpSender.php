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
    private array $tlsArgs;

    /**
     * ChunkHttpSender constructor.
     *
     * @param string $destinationUrl Destination server URL
     * @param string $authToken Authentication token
     * @param string|null $ip Optional IP address to override host
     * @param bool $useBase64 Whether to use base64 encoding for requests
     * @param array $tlsArgs Extra Http::post arguments for TLS, from Http::tlsArgs()
     */
    public function __construct(string $destinationUrl, string $authToken, ?string $ip = null, bool $useBase64 = false, array $tlsArgs = [])
    {
        $this->destinationUrl = $destinationUrl;
        $this->authToken = $authToken;
        $this->ip = $ip;
        $this->useBase64 = $useBase64;
        $this->tlsArgs = $tlsArgs;
    }

    /**
     * Sets the IP the connection is pinned to.
     */
    public function setIp(?string $ip): void
    {
        $this->ip = $ip;
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
        $this->validateResponse($response, $chunkData);

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
        ];

        if (!empty($this->ip)) {
            $httpArgs['ip'] = $this->ip;
        }

        $httpArgs = array_merge($httpArgs, $this->tlsArgs);

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
        ];

        if (!empty($this->ip)) {
            $httpArgs['ip'] = $this->ip;
        }

        $httpArgs = array_merge($httpArgs, $this->tlsArgs);

        return Http::post($endpoint, $httpArgs);
    }

    /**
     * Validates the HTTP response from destination.
     * A 2xx status is not enough: the acknowledgement has to succeed for this
     * migration and chunk, and a failed or retryable result is not delivery.
     *
     * @param mixed $response HTTP response object
     * @param array $chunkData Chunk that was sent
     * @throws \Exception If response indicates an error
     */
    private function validateResponse($response, array $chunkData): void
    {
        if (Http::isError($response)) {
            throw new \Exception('Failed to send chunk: ' . $response->getErrorMessage());
        }

        $statusCode = Http::retrieveResponseCode($response);
        $body = Http::retrieveBody($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \Exception('Destination returned error: ' . $statusCode . ' - ' . substr((string) $body, 0, 300));
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded) || empty($decoded['success'])) {
            throw new \Exception('Destination acknowledgement was not successful');
        }

        if (($decoded['migration_id'] ?? null) !== ($chunkData['migration_id'] ?? null)) {
            throw new \Exception('Destination acknowledgement migration id does not match');
        }

        if (array_key_exists('chunk_id', $chunkData) && ($decoded['chunk_id'] ?? null) !== $chunkData['chunk_id']) {
            throw new \Exception('Destination acknowledgement chunk id does not match');
        }

        $result = $decoded['result'] ?? null;

        if (is_array($result) && (!empty($result['failed']) || !empty($result['can_retry']))) {
            $reason = $result['reason'] ?? 'chunk was not delivered';
            throw new \Exception('Destination did not deliver the chunk: ' . $reason);
        }
    }
}


