<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class Http
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Provides HTTP client functionality.
 */
class Http
{
    /**
     * Sends a POST request through the WordPress HTTP API. Redirects are not followed.
     * The http_api_curl hook limits this request to http and https and pins the host
     * to the manual IP. A pinned request is never sent without cURL.
     *
     * @param string $url
     * @param array  $args
     * @return HttpResponse|HttpError
     */
    public static function post(string $url, array $args = [])
    {
        return self::send('POST', $url, $args);
    }

    /**
     * Sends a GET request the same way as post(), without a body.
     *
     * @param string $url
     * @param array  $args
     * @return HttpResponse|HttpError
     */
    public static function get(string $url, array $args = [])
    {
        return self::send('GET', $url, $args);
    }

    /**
     * @param string $method POST or GET
     * @param string $url
     * @param array  $args
     * @return HttpResponse|HttpError
     */
    private static function send(string $method, string $url, array $args)
    {
        $ip = $args['ip'] ?? null;
        $resolve = empty($ip) ? [] : self::resolveEntries($url, $ip);

        if ($resolve !== [] && !function_exists('curl_init')) {
            return new HttpError('cURL is required to connect to a pinned IP address');
        }

        $pinned = false;
        // WordPress offers no other way to pin the connection to an IP address.
        $hook = static function ($handle, $parsedArgs) use ($resolve, &$pinned): void {
            if (empty($parsedArgs['panelalpha_migrator_request'])) {
                return;
            }

            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            }
            if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            }
            if ($resolve !== []) {
                curl_setopt($handle, CURLOPT_RESOLVE, $resolve); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
                $pinned = true;
            }
        };

        add_action('http_api_curl', $hook, 10, 2);
        try {
            $requestArgs = [
                'headers' => $args['headers'] ?? [],
                'body' => $args['body'] ?? '',
                'timeout' => $args['timeout'] ?? 30,
                'sslverify' => $args['sslverify'] ?? true,
                'redirection' => 0,
                'panelalpha_migrator_request' => true,
            ];
            $response = $method === 'GET' ? wp_remote_get($url, $requestArgs) : wp_remote_post($url, $requestArgs);
        } finally {
            remove_action('http_api_curl', $hook, 10);
        }

        if (is_wp_error($response)) {
            return new HttpError($response->get_error_message());
        }

        if ($resolve !== [] && !$pinned) {
            return new HttpError('The request was not sent over cURL, so the pinned IP address was not used');
        }

        return new HttpResponse(wp_remote_retrieve_body($response), (int) wp_remote_retrieve_response_code($response));
    }

    /**
     * Extra Http::post arguments for a migration: skips the HTTPS certificate
     * check only when the migration has a manual IP and insecure_tls turned on.
     *
     * @param array $migrationData
     * @return array
     */
    public static function tlsArgs(array $migrationData): array
    {
        if (!empty($migrationData['ip']) && ($migrationData['insecure_tls'] ?? false) === true) {
            return ['sslverify' => false];
        }

        return [];
    }

    /**
     * Pins the hostname to the manual IP on the URL port, or the scheme default.
     * Returns no entry when the IP is not a single valid IP address.
     *
     * @return list<string>
     */
    private static function resolveEntries(string $url, string $ip): array
    {
        $parsedUrl = wp_parse_url($url);
        if (!is_array($parsedUrl)) {
            return [];
        }

        $host = $parsedUrl['host'] ?? '';

        if ($host === '') {
            return [];
        }

        if (filter_var(trim($ip, '[]'), FILTER_VALIDATE_IP) === false) {
            return [];
        }

        $scheme = strtolower($parsedUrl['scheme'] ?? 'http');
        $port = $parsedUrl['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (str_contains($ip, ':') && !str_starts_with($ip, '[')) {
            $ip = "[$ip]";
        }

        return ["$host:$port:$ip"];
    }

    /**
     * Checks if a response is an error.
     *
     * @param mixed $response
     * @return bool
     */
    public static function isError($response): bool
    {
        return $response instanceof HttpError;
    }

    /**
     * Retrieves the response code from an HttpResponse.
     *
     * @param HttpResponse $response
     * @return int
     */
    public static function retrieveResponseCode(HttpResponse $response): int
    {
        return $response->getStatusCode();
    }

    /**
     * Retrieves the body from an HttpResponse.
     *
     * @param HttpResponse $response
     * @return string|false
     */
    public static function retrieveBody(HttpResponse $response)
    {
        return $response->getBody();
    }
}
