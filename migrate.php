<?php
/**
 * WordPress Migration Script
 *
 * Usage: php migrate.php <destination_url_with_token> [source_url] [batch_size]
 *
 * Example:
 * php migrate.php "http://dest.com?token=abc123...def" "http://localhost" 50
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($argc < 2) {
    echo "Usage: php migrate.php <destination_url_with_token> [source_url] [batch_size]\n";
    echo "\nExample:\n";
    echo "  php migrate.php \"http://dest.com?token=abc123...\" \"http://localhost\" 50\n";
    exit(1);
}

$destinationUrl = $argv[1];
$sourceUrl = $argv[2] ?? 'http://localhost';
$batchSize = (int)($argv[3] ?? 50);

$configPath = __DIR__ . '/config.php';
$config = is_file($configPath) ? include $configPath : [];
$sourceToken = is_array($config) ? (string) ($config['token'] ?? '') : '';

if ($sourceToken === '') {
    echo "✗ Error: No token configured in config.php\n";
    exit(1);
}

$apiBase = rtrim($sourceUrl, '/') . '/wp-json/wp-migrator/v1';

echo str_repeat("=", 70) . "\n";
echo "WordPress Migration - Batch Processing\n";
echo str_repeat("=", 70) . "\n";
echo "Source:      $sourceUrl\n";
echo "Destination: " . strtok($destinationUrl, '?') . "?token=***\n";
echo "Batch size:  $batchSize chunks/request\n";
echo str_repeat("=", 70) . "\n\n";

function makeRequest($url, $data = null, $token = '') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Migrator-Token: ' . $token,
        ]);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error: $error");
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("HTTP error: $httpCode\nResponse: $response");
    }

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON decode error: " . json_last_error_msg() . "\nResponse: $response");
    }

    return $decoded;
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $unitIndex = 0;

    while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
        $bytes /= 1024;
        $unitIndex++;
    }

    return round($bytes, 2) . ' ' . $units[$unitIndex];
}

echo "[1/3] Starting migration (scan and prepare)...\n";

try {
    $startResponse = makeRequest("$apiBase/remote-sync", [
        'destination_url' => $destinationUrl,
        'backup_database' => true,
        'backup_files' => true,
    ], $sourceToken);

    if (!isset($startResponse['success']) || !$startResponse['success']) {
        throw new Exception("Failed to start migration: " . json_encode($startResponse));
    }

    $migrationId = $startResponse['migration_id'];

    echo "✓ Migration prepared\n";
    echo "  Migration ID: $migrationId\n\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[2/3] Processing chunks...\n\n";

$requestNum = 1;
$totalProcessed = 0;
$startTime = time();

try {
    while (true) {
        $batchStart = microtime(true);

        $batchResponse = makeRequest("$apiBase/remote-sync/process-batch", [
            'migration_id' => $migrationId,
            'batch_size' => $batchSize,
        ], $sourceToken);

        $batchEnd = microtime(true);
        $batchDuration = $batchEnd - $batchStart;

        if (!isset($batchResponse['success']) || !$batchResponse['success']) {
            throw new Exception("Batch failed: " . json_encode($batchResponse));
        }

        $phase = $batchResponse['phase'] ?? 'unknown';
        $processedChunks = $batchResponse['processed_chunks'] ?? 0;
        $completed = $batchResponse['completed'] ?? false;
        $finalized = $batchResponse['finalized'] ?? false;

        $progress = $batchResponse['progress'] ?? [];
        $percentage = $progress['percentage'] ?? 0;
        $sentChunks = $progress['sent_chunks'] ?? 0;
        $totalChunks = $progress['total_chunks'] ?? 0;

        $totalProcessed += $processedChunks;

        $elapsed = time() - $startTime;
        $chunksPerSec = $elapsed > 0 ? round($totalProcessed / $elapsed, 2) : 0;

        printf(
            "Request #%d | Phase: %-8s | Chunks: %4d/%-4d (%5.1f%%) | Processed: %2d | Speed: %.2f chunks/sec | Time: %.2fs\n",
            $requestNum,
            $phase,
            $sentChunks,
            $totalChunks,
            $percentage,
            $processedChunks,
            $chunksPerSec,
            $batchDuration
        );

        if ($finalized) {
            echo "\n✓ Migration completed and finalized!\n\n";

            if (isset($batchResponse['summary'])) {
                $summary = $batchResponse['summary'];
                echo "[3/3] Summary:\n";
                echo "  Tables sent:  " . ($summary['total_tables_sent'] ?? 0) . "\n";
                echo "  Files sent:   " . ($summary['total_files_sent'] ?? 0) . "\n";
                echo "  Total chunks: " . ($summary['total_chunks_sent'] ?? 0) . "\n";
            }

            break;
        }

        if ($processedChunks === 0) {
            echo "\n✓ No more chunks to process\n";
            break;
        }

        $requestNum++;

        usleep(100000);
    }

} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

$totalTime = time() - $startTime;
$minutes = floor($totalTime / 60);
$seconds = $totalTime % 60;

echo "\n";
echo str_repeat("=", 70) . "\n";
echo "Migration completed in ";
if ($minutes > 0) {
    echo "{$minutes}m {$seconds}s";
} else {
    echo "{$seconds}s";
}
echo "\n";
echo "Total requests: $requestNum\n";
echo "Total chunks:   $totalProcessed\n";
echo "Avg speed:      " . ($totalTime > 0 ? round($totalProcessed / $totalTime, 2) : 0) . " chunks/sec\n";
echo str_repeat("=", 70) . "\n";
