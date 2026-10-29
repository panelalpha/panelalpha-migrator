<?php

namespace PanelAlpha\Migrator\Api\Middleware;

use PanelAlpha\Migrator\Helpers\Response;

/**
 * Class ResponseFormatter
 *
 * @package PanelAlpha\Migrator\Api\Middleware
 *
 * Formats API responses for both WordPress and standalone execution.
 */
class ResponseFormatter
{
    /**
     * Formats a success response.
     *
     * @param array $data
     * @return mixed
     */
    public static function success(array $data)
    {
        if (function_exists('rest_ensure_response')) {
            return rest_ensure_response($data);
        }

        Response::success($data);
    }

    /**
     * Formats an error response.
     *
     * @param string $code
     * @param string $message
     * @param array  $options
     * @return mixed
     */
    public static function error(string $code, string $message, array $options = [])
    {
        if (function_exists('rest_ensure_response')) {
            return new \WP_Error($code, $message, $options);
        }

        Response::error($code, $message, $options);
    }

    /**
     * Recursively converts array keys from camelCase to snake_case.
     *
     * @param array $array
     * @return array
     */
    public static function convertKeysToSnakeCase(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $snakeKey = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            if (is_array($value)) {
                $value = self::convertKeysToSnakeCase($value);
            }
            $result[$snakeKey] = $value;
        }
        return $result;
    }
}
