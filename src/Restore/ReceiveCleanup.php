<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Restore;

use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\Filesystem;
use PanelAlpha\Migrator\Helpers\PathProvider;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;

/**
 * Class ReceiveCleanup
 *
 * @package PanelAlpha\Migrator\Restore
 *
 * Removes what migrations leave on the destination: the temporary tables and staged files of a
 * migration that was abandoned, and the kept backup (old tables and replaced files) of a finished one.
 * A migration whose finalize stopped halfway is never touched, the next finalize call finishes it.
 */
class ReceiveCleanup
{
    public const HOOK = 'panelalpha_migrator_cleanup';

    private const ABANDONED_AFTER = 172800;
    private const BACKUP_KEPT_FOR = 604800;
    private const UNFINISHED = ['collecting', 'restoring', 'finalize_failed'];

    /** @var \wpdb */
    private object $wpdb;

    /**
     * ReceiveCleanup constructor.
     *
     * @param \wpdb $wpdb
     */
    public function __construct(object $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Plans the daily cleanup when it is not planned yet.
     */
    public static function schedule(): void
    {
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', self::HOOK);
        }
    }

    /**
     * Cancels the daily cleanup.
     */
    public static function unschedule(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /**
     * The WP-Cron callback.
     */
    public static function runScheduled(): void
    {
        global $wpdb;

        (new self($wpdb))->run();
    }

    /**
     * Removes abandoned migrations and expired backups.
     *
     * @return int How many migrations were removed
     */
    public function run(): int
    {
        $removed = 0;

        foreach ($this->migrationIds() as $migrationId) {
            $registry = new MigrationRegistry($migrationId);
            $state = $registry->getStatus()['status'] ?? null;
            $age = time() - (int) @filemtime(dirname(ReceiveFinalizer::journalFile($migrationId)) . '/status.json');

            if ($this->isFinalizing($migrationId)) {
                continue;
            }

            $unfinished = $state === 'abandoned' || in_array($state, self::UNFINISHED, true);

            if (($unfinished && $age > self::ABANDONED_AFTER) || ($state === 'restored' && $age > self::BACKUP_KEPT_FOR)) {
                $this->remove($migrationId);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * A new migration replaces the one that was not finished: its temporary tables and staged files
     * are removed. Its status stays, so a source that still sends chunks for it gets a clear answer.
     *
     * @param string $exceptId The new migration
     */
    public function discardUnfinished(string $exceptId): void
    {
        foreach ($this->migrationIds() as $migrationId) {
            $registry = new MigrationRegistry($migrationId);

            if ($migrationId === $exceptId || $this->isFinalizing($migrationId)
                || !in_array($registry->getStatus()['status'] ?? null, self::UNFINISHED, true)) {
                continue;
            }

            $this->dropWork($registry, $migrationId);
            $registry->updateStatus('abandoned');
        }
    }

    /**
     * Removes the kept backup of every finished migration but the given one.
     *
     * @param string $exceptId
     */
    public function removeOtherBackups(string $exceptId): void
    {
        foreach ($this->migrationIds() as $migrationId) {
            $journal = ReceiveFinalizer::readJournal($migrationId);

            if ($migrationId === $exceptId || $journal['state'] !== 'done' || empty($journal['keep_backup']) || !empty($journal['backup_removed'])) {
                continue;
            }

            $registry = new MigrationRegistry($migrationId);
            (new TableSwapper($this->wpdb))->dropBackups($registry->getTableMap());
            $this->removeDirectory($this->workDirectory($migrationId));

            $journal['backup_removed'] = true;
            ReceiveFinalizer::writeJournal($migrationId, $journal);
        }
    }

    /**
     * Removes everything of a migration that did not start properly or is no longer needed.
     *
     * @param string $migrationId
     */
    public function remove(string $migrationId): void
    {
        $registry = new MigrationRegistry($migrationId);

        $this->dropWork($registry, $migrationId);

        if (($registry->getStatus()['status'] ?? null) === 'restored') {
            (new TableSwapper($this->wpdb))->dropBackups($registry->getTableMap());
        }

        $this->removeDirectory(dirname(ReceiveFinalizer::journalFile($migrationId)));
    }

    /**
     * Drops the temporary tables and removes the staged and replaced files.
     * The tables dropped are the ones in the migration table map only.
     *
     * @param MigrationRegistry $registry
     * @param string            $migrationId
     */
    private function dropWork(MigrationRegistry $registry, string $migrationId): void
    {
        $map = $registry->getTableMap();

        if (($registry->getStatus()['status'] ?? null) !== 'restored') {
            (new TableSwapper($this->wpdb))->dropTemporary($map);
        }

        $this->removeDirectory($this->workDirectory($migrationId));
    }

    /**
     * A finalize that stopped halfway has swapped files or tables already. It is finished, not removed.
     *
     * @param string $migrationId
     * @return bool
     */
    private function isFinalizing(string $migrationId): bool
    {
        return in_array(ReceiveFinalizer::readJournal($migrationId)['state'], ['started', 'files_moved', 'db_swapped'], true);
    }

    /**
     * @return string[]
     */
    private function migrationIds(): array
    {
        $directory = Config::get('migration_dir') . 'collected/';
        $names = is_dir($directory) ? scandir($directory) : false;
        $ids = [];

        foreach ($names === false ? [] : $names as $name) {
            if ($name !== '.' && $name !== '..' && PathProvider::isSafeMigrationId($name) && is_dir($directory . $name)) {
                $ids[] = $name;
            }
        }

        return $ids;
    }

    /**
     * The folder with the staged and replaced files of a migration.
     *
     * @param string $migrationId
     * @return string
     */
    private function workDirectory(string $migrationId): string
    {
        return Filesystem::trailingSlashIt((string) Config::get('migration_dir')) . $migrationId;
    }

    private function removeDirectory(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            wp_delete_file($directory);

            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        foreach (array_diff((array) scandir($directory), ['.', '..']) as $name) {
            $this->removeDirectory($directory . '/' . $name);
        }

        Filesystem::direct()->rmdir($directory, false);
    }
}
