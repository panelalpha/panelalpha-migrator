<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class DateTime
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Handles date and time operations.
 */
class DateTime
{
    /**
     * Gets the current time in the specified format.
     *
     * @param string $format
     * @return string
     */
    public static function currentTime(string $format = 'Y-m-d H:i:s'): string
    {
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $datetime = new \DateTime('now', $timezone);

        if ($format === 'c') {
            return $datetime->format(\DateTime::ATOM);
        }

        if ($format === 'mysql') {
            return $datetime->format('Y-m-d H:i:s');
        }

        return $datetime->format($format);
    }

    /**
     * Gets the current timestamp.
     *
     * @return int
     */
    public static function timestamp(): int
    {
        return time();
    }
}
