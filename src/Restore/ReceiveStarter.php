<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Restore;

use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Services\Backup\ChunkService;
use PanelAlpha\Migrator\Services\Migration\MigrationInfoGenerator;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;

/**
 * Class ReceiveStarter
 *
 * @package PanelAlpha\Migrator\Restore
 *
 * Handles the info chunk on the destination. It checks the disk space and the database privileges,
 * removes what an earlier migration that was not finished left behind (the newest migration wins),
 * registers the migration and names its temporary tables.
 */
class ReceiveStarter
{
    private const DISK_RATIO = 1.1;
    private const DISK_RESERVE_BYTES = 52428800;

    /** @var \wpdb */
    private object $wpdb;
    private ChunkService $chunkService;
    private ReceiveCleanup $cleanup;

    /**
     * ReceiveStarter constructor.
     *
     * @param \wpdb              $wpdb
     * @param ChunkService|null  $chunkService
     * @param ReceiveCleanup|null $cleanup
     */
    public function __construct(object $wpdb, ?ChunkService $chunkService = null, ?ReceiveCleanup $cleanup = null)
    {
        $this->wpdb = $wpdb;
        $this->chunkService = $chunkService ?? new ChunkService();
        $this->cleanup = $cleanup ?? new ReceiveCleanup($wpdb);
    }

    /**
     * Starts a migration. A repeated info chunk of the same migration keeps its table names.
     *
     * @param string $migrationId
     * @param array  $info
     * @return array
     * @throws InsufficientDiskSpaceException
     * @throws DatabasePrivilegesException
     * @throws \Exception
     */
    public function start(string $migrationId, array $info): array
    {
        $this->assertDiskSpace($info['files'] ?? []);

        $this->cleanup->discardUnfinished($migrationId);

        $this->chunkService->saveChunk($migrationId, $info);
        $registry = new MigrationRegistry($migrationId);
        $registry->registerMigration(MigrationInfoGenerator::withoutSecrets($info));

        try {
            $swapper = new TableSwapper($this->wpdb);
            $map = $swapper->createMap(
                $registry,
                array_column($info['tables'] ?? [], 'name'),
                (string) ($info['source_table_prefix'] ?? 'wp_')
            );
            $swapper->checkPrivileges((string) $registry->getTableHash());
        } catch (\Throwable $e) {
            // The migration did not start, so nothing of it stays.
            $this->cleanup->remove($migrationId);

            throw $e;
        }

        return ['action' => 'registered_migration', 'tables' => count($map)];
    }

    /**
     * Free space in the folder where the migration keeps its staged files, or null when it cannot be read.
     *
     * @return float|null
     */
    public function freeDiskSpace(): ?float
    {
        $directory = (string) Config::get('migration_dir');
        $free = function_exists('disk_free_space') ? @disk_free_space($directory) : false;

        return $free === false ? null : (float) $free;
    }

    /**
     * The files may need as much room as the manifest says, plus a margin for the staged copies.
     *
     * @param array $files
     * @throws InsufficientDiskSpaceException
     */
    private function assertDiskSpace(array $files): void
    {
        $free = $this->freeDiskSpace();

        if ($free === null) {
            return;
        }

        $total = 0;

        foreach ($files as $file) {
            $total += (int) ($file['size'] ?? 0);
        }

        $needed = (int) ceil($total * self::DISK_RATIO) + self::DISK_RESERVE_BYTES;

        if ($free < $needed) {
            throw new InsufficientDiskSpaceException(esc_html(sprintf(
                'Destination does not have enough free disk space: %d bytes free, %d bytes needed',
                (int) $free,
                $needed
            )));
        }
    }
}
