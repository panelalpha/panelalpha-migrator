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
    public static function post(string $url, array $args = []): HttpResponse|HttpError
    {
        $headers = $args['headers'] ?? [];
        $body = $args['body'] ?? '';
        $timeout = $args['timeout'] ?? 30;
        $sslverify = $args['sslverify'] ?? true;
        $ip = $args['ip'] ?? null;

  
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        if (!empty($ip)) {
            $parsedUrl = parse_url($url);
            $host = $parsedUrl['host'] ?? '';

            if (!empty($host)) {
                $resolve = [
                    "$host:80:$ip",
                    "$host:443:$ip"
                ];
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

        curl_close($ch);

        if ($error) {
            return new HttpError($error);
        }

        return new HttpResponse($response, $httpCode);
    }

    /**
     * Checks if a response is an error.
     *
     * @param mixed $response
     * @return bool
     */
    public static function isError(mixed $response): bool
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
    public static function retrieveBody(HttpResponse $response): string|false
    {
        return $response->getBody();
    }
}
