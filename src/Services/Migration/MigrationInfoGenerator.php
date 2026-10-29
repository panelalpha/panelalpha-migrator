<?php

namespace PanelAlpha\Migrator\Services\Migration;

use PanelAlpha\Migrator\Dto\Migration;
use PanelAlpha\Migrator\Dto\MigrationSummary;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\DateTime;
use PanelAlpha\Migrator\Helpers\TokenHelper;

/**
 * Class MigrationInfoGenerator
 *
 * @package PanelAlpha\Migrator\Services\Migration
 *
 * Generates migration information.
 */
class MigrationInfoGenerator
{
    private ?object $wpdb;

    /**
     * MigrationInfoGenerator constructor.
     *
     * @param \wpdb|null $wpdb The global $wpdb when not given
     */
    public function __construct(?object $wpdb = null)
    {
        $this->wpdb = $wpdb;
    }

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

        // Get the site token from the plugin options
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
        $prefix = $this->wpdb()->base_prefix ?? '';

        return is_string($prefix) && $prefix !== '' ? $prefix : 'wp_';
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
        $version = $this->wpdb()->get_var('SELECT VERSION()');

        return $version ?: 'unknown';
    }

    /**
     * Gets the database connection.
     *
     * @return \wpdb
     */
    private function wpdb(): object
    {
        return $this->wpdb ?? $GLOBALS['wpdb'];
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
