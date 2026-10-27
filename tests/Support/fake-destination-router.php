<?php

/**
 * Router for the PHP built-in server that stands in for a destination site.
 *
 * Every request is appended to the log file as one JSON line. The answer comes from the
 * first rule in the rules file whose "match" is part of the request URI; without a match
 * the answer is 200 with {"success":true}.
 */

$directory = getenv('FAKE_DESTINATION_DIR');

$entry = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? '',
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => file_get_contents('php://input'),
];

file_put_contents($directory . '/requests.log', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

$rules = [];
if (file_exists($directory . '/rules.json')) {
    $rules = json_decode(file_get_contents($directory . '/rules.json'), true) ?: [];
}

$status = 200;
$body = null;

foreach ($rules as $rule) {
    if (strpos($entry['uri'], $rule['match']) !== false) {
        $status = $rule['status'];
        $body = $rule['body'];
        break;
    }
}

if ($body === null) {
    $payload = ['success' => true];

    if (strpos($entry['uri'], 'restore.php') !== false) {
        $request = json_decode($entry['body'], true);
        if (!is_array($request)) {
            $decoded = base64_decode($entry['body'], true);
            $request = is_string($decoded) ? json_decode($decoded, true) : null;
        }

        if (is_array($request)) {
            $migrationId = $request['migration_id'] ?? $request['m'] ?? null;
            $chunkId = $request['chunk_id'] ?? $request['c'] ?? null;
            if ($migrationId !== null) {
                $payload['migration_id'] = $migrationId;
            }
            if ($chunkId !== null) {
                $payload['chunk_id'] = $chunkId;
            }
        }
    }

    $body = json_encode($payload);
}

http_response_code($status);
header('Content-Type: application/json');
echo $body;
