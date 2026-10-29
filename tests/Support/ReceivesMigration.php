<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests\Support;

use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Restore\ChunkProcessor;
use PanelAlpha\Migrator\Restore\FileRestoreHandler;
use PanelAlpha\Migrator\Restore\ReceiveStarter;
use PanelAlpha\Migrator\Restore\TableRestoreHandler;
use PanelAlpha\Migrator\Services\Backup\ChunkService;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;

/**
 * A destination with a live plugin folder and a migration that carries a new version of it and an options table.
 * Needs the tests to use ResetsGlobalState and TemporaryDirectory.
 */
trait ReceivesMigration
{
    private RecordingWpdb $wpdb;

    private string $abspath;

    private string $migrationDir;

    protected function setUpDestination(): void
    {
        $this->abspath = $this->createTemporaryDirectory();
        $this->migrationDir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $this->migrationDir);
        $this->wpdb = new RecordingWpdb();
        $this->wpdb->varAnswers['active_plugins'] = serialize(['hello.php']);
        $this->wpdb->sqlite('CREATE TABLE wp_options (option_id integer, option_name text, option_value text, autoload text)');
        self::writeFile($this->abspath . 'wp-content/plugins/demo/old.php', 'old');
    }

    protected function infoChunk(string $migrationId, array $overrides = []): array
    {
        return $overrides + [
            'type' => 'info',
            'migration_id' => $migrationId,
            'source_table_prefix' => 'wp_',
            'tables' => [['name' => 'wp_options', 'total_chunks' => 0]],
            'files' => [['path' => 'wp-content/plugins/demo/demo.php', 'size' => 3, 'total_chunks' => 1]],
        ];
    }

    protected function structureChunk(string $migrationId): array
    {
        return [
            'type' => 'table_structure',
            'migration_id' => $migrationId,
            'table' => [
                'name' => 'wp_options',
                'create_statement' => 'CREATE TABLE `wp_options` (`option_id` bigint) ENGINE=InnoDB',
                'indexes' => [],
            ],
        ];
    }

    protected function fileChunk(string $migrationId, string $path = 'wp-content/plugins/demo/demo.php', string $content = 'new'): array
    {
        return [
            'type' => 'file_chunk',
            'migration_id' => $migrationId,
            'file' => [
                'path' => $path,
                'permissions' => '644',
                'modified' => null,
                'chunk_info' => ['chunk_number' => 1, 'total_chunks' => 1],
            ],
            'content' => base64_encode($content),
            'checksum' => hash('sha256', $content),
        ];
    }

    /**
     * Sends the info chunk, the structure of the options table and the plugin file, and creates the temporary table.
     */
    protected function receiveWholeMigration(string $migrationId, array $infoOverrides = []): void
    {
        (new ReceiveStarter($this->wpdb))->start($migrationId, $this->infoChunk($migrationId, $infoOverrides));

        $registry = new MigrationRegistry($migrationId);
        $processor = new ChunkProcessor(
            new TableRestoreHandler($this->wpdb, $registry),
            new FileRestoreHandler($registry, $this->abspath),
            new ChunkService()
        );
        $processor->process($migrationId, $this->structureChunk($migrationId));
        $processor->process($migrationId, $this->fileChunk($migrationId));

        $this->createTemporaryTables($registry);
    }

    protected function createTemporaryTables(MigrationRegistry $registry): void
    {
        foreach ($registry->getTableMap() as $names) {
            $this->wpdb->sqlite('CREATE TABLE "' . $names['temp'] . '" (option_id integer, option_name text, option_value text, autoload text)');
        }
    }
}
