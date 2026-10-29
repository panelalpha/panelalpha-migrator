<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Services\Migration;

use PanelAlpha\Migrator\Helpers\PathProvider;
use PanelAlpha\Migrator\Helpers\Filesystem;
use PanelAlpha\Migrator\Helpers\JsonCompressor;

/**
 * Class MigrationStorageService
 *
 * @package PanelAlpha\Migrator\Services\Migration
 *
 * Handles storage and retrieval of migration data.
 */
class MigrationStorageService
{
    private const SECRET_GUARD = "<?php exit; ?>\n";
    private const SECRET_OPTION_PREFIX = 'panelalpha_migrator_secret_';

    /**
     * Keys kept out of the web-readable manifest.
     * The source token is not kept at all.
     */
    private const SECRET_KEYS = ['token'];
    private const DROPPED_KEYS = ['sourceToken', 'source_token', 'stk'];

    /**
     * Gets the file path for a migration.
     *
     * @param string $migrationId Migration identifier
     * @return string Full file path
     */
    public function getMigrationFilePath(string $migrationId): string
    {
        return PathProvider::getMigrationFilePath($migrationId);
    }

    /**
     * Saves migration data to a file.
     *
     * @param string $migrationId Migration identifier
     * @param array  $migrationDataArray Migration data to save
     * @throws \RuntimeException If file cannot be saved
     */
    public function saveMigrationData(string $migrationId, array $migrationDataArray): void
    {
        $filePath = $this->getMigrationFilePath($migrationId);
        $migrationDir = dirname($filePath);

        Filesystem::ensureDirectoryExists($migrationDir);

        $secrets = array_intersect_key($migrationDataArray, array_flip(self::SECRET_KEYS));
        $public = array_diff_key($migrationDataArray, $secrets, array_flip(self::DROPPED_KEYS));

        if ($secrets !== []) {
            $secretPath = PathProvider::getMigrationSecretFilePath($migrationId);
            $secretJson = json_encode($secrets, JSON_UNESCAPED_SLASHES);
            $saved = $secretJson !== false && Filesystem::writeFileAtomic($secretPath, self::SECRET_GUARD . $secretJson);

            // Hosts such as WP Engine refuse new .php files; the WordPress database is the fallback.
            if (!$saved && function_exists('update_option')) {
                update_option(self::SECRET_OPTION_PREFIX . $migrationId, $secrets, false);
                $saved = get_option(self::SECRET_OPTION_PREFIX . $migrationId) === $secrets;
            }

            if (!$saved) {
                throw new \RuntimeException(esc_html("Failed to save migration data to: {$secretPath}"));
            }
        }

        $json = JsonCompressor::compressToJson($public, JSON_UNESCAPED_SLASHES);
        $result = Filesystem::writeFileAtomic($filePath, $json);

        if ($result === false) {
            throw new \RuntimeException(esc_html("Failed to save migration data to: {$filePath}"));
        }
    }

    /**
     * Checks if a migration exists.
     *
     * @param string $migrationId
     * @return bool
     */
    public function migrationExists(string $migrationId): bool
    {
        $filePath = $this->getMigrationFilePath($migrationId);
        return file_exists($filePath);
    }

    /**
     * Loads migration data from a file.
     *
     * @param string $migrationId
     * @return array
     */
    public function loadMigrationData(string $migrationId): array
    {
        $filePath = $this->getMigrationFilePath($migrationId);
        $json = file_get_contents($filePath);
        $data = JsonCompressor::decompressFromJson($json);

        return $this->loadSecrets($migrationId) + array_diff_key($data, array_flip(self::DROPPED_KEYS));
    }

    /**
     * Loads the secrets stored next to a migration manifest, or in the
     * WordPress database when the host refused the file.
     * A secret still present in an old manifest is kept when there is neither.
     *
     * @param string $migrationId
     * @return array
     */
    public function loadSecrets(string $migrationId): array
    {
        $content = @file_get_contents(PathProvider::getMigrationSecretFilePath($migrationId));
        if (!is_string($content) || strncmp($content, self::SECRET_GUARD, strlen(self::SECRET_GUARD)) !== 0) {
            $stored = function_exists('get_option') ? get_option(self::SECRET_OPTION_PREFIX . $migrationId) : false;

            return is_array($stored) ? $stored : [];
        }

        $secrets = json_decode(substr($content, strlen(self::SECRET_GUARD)), true);

        return is_array($secrets) ? $secrets : [];
    }
    
    /**
     * Gets the directory where collected chunks are stored.
     *
     * @return string Collected directory path
     */
    public function getCollectedDirectory(): string
    {
        return PathProvider::getCollectedDir();
    }
    
    /**
     * Lists all migrations.
     *
     * @return array
     */
    public function listMigrations(): array
    {
        $collectDir = $this->getCollectedDirectory();

        if (!file_exists($collectDir)) {
            return [];
        }

        $migrations = $this->collectMigrationsList($collectDir);
        
        return $this->sortMigrationsByTimestamp($migrations);
    }

    /**
     * Collects a list of migrations from the collected directory.
     *
     * @param string $collectDir
     * @return array
     */
    private function collectMigrationsList(string $collectDir): array
    {
        $migrations = [];
        $items = scandir($collectDir);

        foreach ($items as $item) {
            if ($this->shouldSkipItem($item)) {
                continue;
            }

            $migrationDir = $collectDir . $item;

            if (!is_dir($migrationDir)) {
                continue;
            }

            $migrationInfo = $this->extractMigrationInfo($migrationDir, $item);

            if ($migrationInfo) {
                $migrations[] = $migrationInfo;
            }
        }

        return $migrations;
    }

    /**
     * Checks if a directory item should be skipped.
     *
     * @param string $item
     * @return bool
     */
    private function shouldSkipItem(string $item): bool
    {
        return $item === '.' || $item === '..';
    }

    /**
     * Extracts information about a single migration.
     *
     * @param string $migrationDir
     * @param string $item
     * @return array|null
     */
    private function extractMigrationInfo(string $migrationDir, string $item): ?array
    {
        $infoFile = $migrationDir . '/migration_info.json';

        if (!file_exists($infoFile)) {
            return null;
        }

        $json = file_get_contents($infoFile);
        $info = JsonCompressor::decompressFromJson($json);
        $status = $this->loadMigrationStatus($migrationDir);

        return [
            'migration_id' => $item,
            'timestamp' => $info['timestamp'] ?? null,
            'source_url' => $info['site_url'] ?? 'Unknown',
            'status' => $status['status'] ?? 'collected',
            'files_total' => count($info['files'] ?? []),
            'tables_total' => count($info['tables'] ?? []),
            'files_restored' => $status['files_restored'] ?? 0,
            'tables_restored' => $status['tables_restored'] ?? 0
        ];
    }

    /**
     * Loads the status of a migration.
     *
     * @param string $migrationDir
     * @return array|null
     */
    private function loadMigrationStatus(string $migrationDir): ?array
    {
        $statusFile = $migrationDir . '/status.json';

        if (!file_exists($statusFile)) {
            return null;
        }

        $json = file_get_contents($statusFile);
        if (!is_string($json) || $json === '') {
            return null;
        }

        $status = JsonCompressor::decompressFromJson($json);

        return $status === [] ? null : $status;
    }

    /**
     * Sorts migrations by timestamp in descending order.
     *
     * @param array $migrations
     * @return array
     */
    private function sortMigrationsByTimestamp(array $migrations): array
    {
        usort($migrations, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        return $migrations;
    }
}
