<?php

namespace WordpressMigrator\Services\Migration;

use WordpressMigrator\Dto\Migration;
use WordpressMigrator\Dto\MigrationSummary;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\Database;
use WordpressMigrator\Helpers\DateTime;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Helpers\TokenHelper;
use WordpressMigrator\Restore\WpConfigParser;

/**
 * Class MigrationInfoGenerator
 *
 * @package WordpressMigrator\Services\Migration
 *
 * Generates migration information.
 */
class MigrationInfoGenerator
{
    /**
     * Generates a Migration object with all the necessary information.
     *
     * @param array  $files
     * @param array  $tables
     * @param string $destinationUrl
     * @return Migration
     */
    public function generate($files, $tables, $destinationUrl): Migration
    {
        $migrationId = $this->generateMigrationId();

        $totalFiles = count($files);
        $totalFileSize = 0;
        $fileChunks = 0;

        foreach ($files as $file) {
            $totalFileSize += $file->size;
            $fileChunks += $file->totalChunks;
        }

        $totalTables = count($tables);
        $totalDbSize = 0;
        $totalRows = 0;
        $dbChunks = 0;

        foreach ($tables as $table) {
            $totalDbSize += $table->size;
            $totalRows += $table->rowCount;
            $dbChunks += $table->totalChunks;
        }

        $estimatedChunks = $fileChunks + $dbChunks;

        $summary = new MigrationSummary(
            $totalFiles,
            $totalFileSize,
            $totalTables,
            $totalDbSize,
            $totalRows,
            $estimatedChunks,
            $fileChunks,
            $dbChunks
        );

        // Get token from config.php file
        $sourceToken = TokenHelper::getToken();

        return new Migration(
            $migrationId,
            $destinationUrl,
            DateTime::currentTime('c'),
            Config::get('wp_version', 'unknown'),
            PHP_VERSION,
            $this->getMysqlVersion(),
            Config::get('site_url'),
            Config::get('home_url'),
            $files,
            $tables,
            $summary,
            $sourceToken,
            $this->getSourceTablePrefix()
        );
    }

    /**
     * Gets the table prefix used by the source WordPress instance.
     *
     * @return string
     */
    private function getSourceTablePrefix(): string
    {
        if (isset($GLOBALS['table_prefix']) && is_string($GLOBALS['table_prefix']) && $GLOBALS['table_prefix'] !== '') {
            return $GLOBALS['table_prefix'];
        }

        try {
            $parser = new WpConfigParser(PathProvider::getAbsPath());
            $config = $parser->parse();
            $prefix = $config['table_prefix'] ?? '';
            if (is_string($prefix) && $prefix !== '') {
                return $prefix;
            }
        } catch (\Throwable $e) {
            // Fall through to default.
        }

        return 'wp_';
    }

    /**
     * Generates a unique migration ID.
     *
     * @return string
     */
    private function generateMigrationId()
    {
        $timestamp = DateTime::currentTime('Ymd_His');
        return 'mig_' . $timestamp . '_' . bin2hex(random_bytes(16));
    }

    /**
     * Removes the tokens from migration info that leaves the source.
     * The destination needs neither the destination token nor the source token.
     *
     * @param array $info
     * @return array
     */
    public static function withoutSecrets(array $info): array
    {
        return array_diff_key($info, array_flip(['token', 'sourceToken', 'source_token', 'stk']));
    }

    /**
     * Gets the MySQL version.
     *
     * @return string
     */
    private function getMysqlVersion()
    {
        $db = Database::getInstance();
        $version = $db->getVar('SELECT VERSION()');

        return $version ?: 'unknown';
    }

    /**
     * Formats a size in bytes to a human-readable format.
     *
     * @param int $bytes
     * @return string
     */
    public static function formatSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
