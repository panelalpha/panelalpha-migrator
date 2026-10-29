<?php

namespace PanelAlpha\Migrator\Api\Middleware;

use PanelAlpha\Migrator\Helpers\JsonCompressor;

/**
 * Class RequestValidator
 *
 * @package PanelAlpha\Migrator\Api\Middleware
 *
 * Validates incoming API requests.
 */
class RequestValidator
{
    /**
     * Gets the migration ID from the request.
     *
     * @param mixed $request
     * @return string
     * @throws InvalidRequestException
     */
    public static function getMigrationId($request): string
    {
        $migrationId = self::getParam($request, 'migration_id');

        if (empty($migrationId)) {
            self::throwError('missing_id', 'Missing migration ID');
        }

        return $migrationId;
    }

    /**
     * Gets the destination URL from the request.
     *
     * @param mixed $request
     * @return string
     * @throws InvalidRequestException
     */
    public static function getDestinationUrl($request): string
    {
        $url = self::getParam($request, 'destination_url');

        if (empty($url)) {
            self::throwError('missing_url', 'Missing destination URL');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            self::throwError('invalid_url', 'Invalid URL');
        }

        return $url;
    }

    /**
     * Gets a parameter from the request.
     *
     * @param mixed $request
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public static function getParam($request, string $key, $default = null)
    {
        if (is_object($request) && method_exists($request, 'get_param')) {
            return $request->get_param($key) ?? $default;
        }

        return $request->getParam($key) ?? $default;
    }

    /**
     * Gets JSON parameters from the request.
     *
     * @param mixed $request
     * @return array
     */
    public static function getJsonParams($request): array
    {
        $data = [];

        if (is_object($request) && method_exists($request, 'get_json_params')) {
            $data = $request->get_json_params() ?? [];
        } else {
            $data = $request->getJsonParams() ?? [];
        }

        if (!empty($data) && JsonCompressor::isCompressed($data)) {
            return JsonCompressor::decompress($data);
        }

        return $data;
    }

    /**
     * Gets the chunk sent by the source from the request body.
     * The body is JSON, or base64 of JSON when the source says so (X-Migrator-Encoding: base64)
     * or when it does not start like JSON. A compressed chunk is expanded.
     *
     * @param mixed $request
     * @return array
     * @throws InvalidRequestException
     */
    public static function getChunkBody($request): array
    {
        $input = (string) $request->get_body();
        $isBase64 = $request->get_header('x_migrator_encoding') === 'base64';

        if (!$isBase64 && $input !== '') {
            $trimmed = ltrim($input);
            $isBase64 = $trimmed !== '' && $trimmed[0] !== '{' && $trimmed[0] !== '[';
        }

        if ($isBase64) {
            $decoded = base64_decode($input, true);
            if ($decoded !== false) {
                $input = $decoded;
            }
        }

        $data = json_decode($input, true);

        if (!is_array($data)) {
            self::throwError('invalid_json', 'Invalid JSON input');
        }

        return JsonCompressor::isCompressed($data) ? JsonCompressor::decompress($data) : $data;
    }

    /**
     * Throws an error response.
     *
     * @param string $code
     * @param string $message
     * @return void
     * @throws InvalidRequestException
     */
    private static function throwError(string $code, string $message): void
    {
        if (function_exists('rest_ensure_response')) {
            throw new InvalidRequestException(esc_html($code), esc_html($message));
        }

        http_response_code(400);
        echo json_encode(['error' => $code, 'message' => $message]);
        exit;
    }
}
