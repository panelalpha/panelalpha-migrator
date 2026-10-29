<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Must load before Http.php so namespaced curl_setopt capture is registered first.
require __DIR__ . '/Support/CurlOptionCapture.php';

// WordPress stand-ins: Http::post always goes through wp_remote_post, and the code outside
// The plugin code uses esc_html, wp_parse_url and the WordPress file helpers.
require __DIR__ . '/Support/WordPressError.php';
require __DIR__ . '/Support/WordPressFunctions.php';
require __DIR__ . '/Support/WordPressFilesystem.php';
