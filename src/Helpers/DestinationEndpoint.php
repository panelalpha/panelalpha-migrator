<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Helpers;

/**
 * Builds the URLs of the plugin REST routes on the destination site.
 *
 * Pretty permalinks serve /wp-json/..., sites without them only answer ?rest_route=....
 * The form is decided once when the destination is checked and kept in the migration
 * data under MODE_KEY, so every later request goes straight to the form that works.
 */
class DestinationEndpoint
{
    public const MODE_PRETTY = 'pretty';
    public const MODE_PLAIN = 'plain';
    public const MODE_KEY = 'drm';

    private const ROUTE_NAMESPACE = 'panelalpha-migrator/v1/';

    private string $baseUrl;

    private string $mode;

    /**
     * @param string $destinationUrl Destination site URL, a query string is dropped
     * @param string $mode           MODE_PRETTY or MODE_PLAIN, anything else counts as pretty
     */
    public function __construct(string $destinationUrl, string $mode = self::MODE_PRETTY)
    {
        $this->baseUrl = rtrim((string) strtok($destinationUrl, '?#'), '/\\');
        $this->mode = $mode === self::MODE_PLAIN ? self::MODE_PLAIN : self::MODE_PRETTY;
    }

    /**
     * Reads the saved mode from migration data. A migration without one uses pretty links.
     *
     * @param array $migrationData
     * @return string
     */
    public static function modeFrom(array $migrationData): string
    {
        return ($migrationData[self::MODE_KEY] ?? null) === self::MODE_PLAIN ? self::MODE_PLAIN : self::MODE_PRETTY;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Returns the URL of a route below panelalpha-migrator/v1.
     *
     * @param string               $route Route without a leading slash, such as receive/check
     * @param array<string,string> $query Extra query arguments, such as a cache buster
     * @return string
     */
    public function url(string $route, array $query = []): string
    {
        if ($this->mode === self::MODE_PLAIN) {
            $url = $this->baseUrl . '/?rest_route=/' . self::ROUTE_NAMESPACE . $route;
        } else {
            $url = $this->baseUrl . '/wp-json/' . self::ROUTE_NAMESPACE . $route;
        }

        if ($query !== []) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($query);
        }

        return $url;
    }
}
