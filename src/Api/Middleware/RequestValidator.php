<?php

namespace WordpressMigrator\Api\Middleware;

use WordpressMigrator\Helpers\JsonCompressor;

/**
 * Class RequestValidator
 *
 * @package WordpressMigrator\Api\Middleware
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
     * @throws \WP_Error
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
     * @throws \WP_Error
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
     * Throws an error response.
     *
     * @param string $code
     * @param string $message
     * @return void
     * @throws \WP_Error
     */
    private static function throwError(string $code, string $message): void
    {
        if (function_exists('rest_ensure_response')) {
            throw new \WP_Error($code, $message, ['status' => 400]);
        }

        http_response_code(400);
        echo json_encode(['error' => $code, 'message' => $message]);
        exit;
    }
}
