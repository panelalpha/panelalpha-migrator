<?php

declare(strict_types=1);

/**
 * Stand-ins for the WordPress functions the plugin calls outside the REST layer, loaded by
 * the test bootstrap for every test. They behave like the WordPress versions.
 *
 * The HTTP API stand-ins:
 * wp_remote_post and wp_remote_get send the request with cURL like the WordPress cURL transport: HTTP/1.1,
 * no redirects, certificate checks from sslverify, and the http_api_curl hooks run just
 * before sending. The certificate options are also recorded in CurlOptionCapture.
 * Set $GLOBALS['http_wp_skip_curl_hooks'] to act like a transport other than cURL.
 */
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['http_wp_hooks'][$hook][] = $callback;
}

function remove_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['http_wp_hooks'][$hook] = array_values(array_filter(
        $GLOBALS['http_wp_hooks'][$hook] ?? [],
        static function ($registered) use ($callback): bool {
            return $registered !== $callback;
        }
    ));
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_post(string $url, array $args)
{
    return panelalpha_test_remote_request('POST', $url, $args);
}

function wp_remote_get(string $url, array $args)
{
    return panelalpha_test_remote_request('GET', $url, $args);
}

function panelalpha_test_remote_request(string $method, string $url, array $args)
{
    $GLOBALS['http_wp_requests'][] = ['url' => $url, 'args' => $args];

    $headers = [];
    foreach ($args['headers'] ?? [] as $key => $value) {
        $headers[] = "$key: $value";
    }
    $verify = $args['sslverify'] ?? true;

    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $args['body'] ?? '');
    }
    curl_setopt($handle, CURLOPT_TIMEOUT, $args['timeout'] ?? 5);
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, $verify);
    curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
    \PanelAlpha\Migrator\Tests\Support\CurlOptionCapture::record(CURLOPT_SSL_VERIFYPEER, $verify);
    \PanelAlpha\Migrator\Tests\Support\CurlOptionCapture::record(CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);

    if (empty($GLOBALS['http_wp_skip_curl_hooks'])) {
        foreach ($GLOBALS['http_wp_hooks']['http_api_curl'] ?? [] as $callback) {
            $callback($handle, $args, $url);
        }
    }

    $body = curl_exec($handle);
    $error = curl_error($handle);
    if ($error !== '') {
        return new WP_Error('http_request_failed', 'cURL error ' . curl_errno($handle) . ': ' . $error);
    }

    return ['body' => $body, 'response' => ['code' => curl_getinfo($handle, CURLINFO_HTTP_CODE)]];
}

function wp_remote_retrieve_body($response): string
{
    return (string) $response['body'];
}

function wp_remote_retrieve_response_code($response)
{
    return $response['response']['code'];
}

function esc_html($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function wp_parse_url(string $url, int $component = -1)
{
    return parse_url($url, $component);
}

function wp_delete_file(string $file): bool
{
    return @unlink($file);
}

function wp_is_writable(string $path): bool
{
    return is_writable($path);
}

function wp_mkdir_p(string $target): bool
{
    return is_dir($target) || @mkdir($target, 0755, true);
}
