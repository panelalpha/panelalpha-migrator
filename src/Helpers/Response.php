<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class Response
 *
 * @package WordpressMigrator\Helpers
 *
 * Handles JSON responses.
 */
class Response
{
    /**
     * Sends a JSON response.
     *
     * @param mixed $data
     * @param int   $statusCode
     */
    public static function json($data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Sends a JSON error response.
     *
     * @param string $code
     * @param string $message
     * @param array  $data
     */
    public static function error(string $code, string $message, array $data = []): void
    {
        $statusCode = $data['status'] ?? 400;

        self::json([
            'code' => $code,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    /**
     * Sends a JSON success response.
     *
     * @param mixed $data
     */
    public static function success($data): void
    {
        self::json($data, 200);
    }
}

