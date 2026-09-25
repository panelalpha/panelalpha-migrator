<?php
/**
 * Standalone Restore Worker
 * Handles chunk collection and restoration without requiring WordPress to be loaded
 * Can be called via HTTP or CLI
 */

// Set CORS headers to allow cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Migrator-Token');
header('Access-Control-Max-Age: 86400');


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('ABSPATH', dirname(__FILE__) . '/../../../');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');

require_once __DIR__ . '/vendor/autoload.php';



use WordpressMigrator\RestoreWorker;

try {
    $worker = new RestoreWorker(ABSPATH);
    $worker->run();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
