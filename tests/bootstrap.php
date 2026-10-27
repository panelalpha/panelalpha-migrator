<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Must load before Http.php so namespaced curl_setopt capture is registered first.
require __DIR__ . '/Support/CurlOptionCapture.php';
