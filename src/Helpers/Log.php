<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class Log
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Writes plugin messages to the PHP error log when WordPress debugging is on.
 */
class Log
{
    /**
     * Writes an error message, only when WP_DEBUG is enabled.
     *
     * @param string $message Message to log.
     */
    public static function error(string $message): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- only runs when WP_DEBUG is on
        error_log($message);
    }
}
