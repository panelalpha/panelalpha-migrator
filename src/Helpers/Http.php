<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class Http
 *
 * @package WordpressMigrator\Helpers
 *
 * Provides HTTP client functionality.
 */
class Http
{
    /**
     * Sends a POST request.
     *
     * @param string $url
     * @param array  $args
     * @return HttpResponse|HttpError
     */
    public static function post(string $url, array $args = [])
    {
        $headers = $args['headers'] ?? [];
        $body = $args['body'] ?? '';
        $timeout = $args['timeout'] ?? 30;
        $sslverify = $args['sslverify'] ?? true;
        $ip = $args['ip'] ?? null;

  
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        if (!empty($ip)) {
            $resolve = self::resolveEntries($url, $ip);
            if ($resolve !== []) {
                curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
            }
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslverify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslverify ? 2 : 0);

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "$key: $value";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            return new HttpError($error);
        }

        return new HttpResponse($response, $httpCode);
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
        $parsedUrl = parse_url($url);
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
