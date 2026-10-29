<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Restore;

use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\Filesystem;
use PanelAlpha\Migrator\Helpers\PathProvider;
use PanelAlpha\Migrator\Helpers\TokenHelper;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;

// The lock of a migration is a flock on a file held for the whole request, which WP_Filesystem does not offer.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

/**
 * Class ReceiveFinalizer
 *
 * @package PanelAlpha\Migrator\Restore
 *
 * Finishes a migration on the destination: moves the staged files into place, then swaps the
 * temporary tables in with one RENAME TABLE. The site is in maintenance mode meanwhile.
 *
 * A journal (collected/<id>/finalize.json) records how far the work got, so a request that stopped
 * halfway is finished by the next call. If the swap fails, the files go back and the site keeps its
 * old files and tables.
 *
 * Journal states: started, files_moved, db_swapped, done, failed.
 */
class ReceiveFinalizer
{
    private const IN_PROGRESS = ['started', 'files_moved', 'db_swapped'];
    private const TIME_LIMIT = 300;

    /** @var \wpdb */
    private object $wpdb;
    private string $abspath;

    /**
     * ReceiveFinalizer constructor.
     *
     * @param \wpdb  $wpdb
     * @param string $abspath
     */
    public function __construct(object $wpdb, string $abspath)
    {
        $this->wpdb = $wpdb;
        $this->abspath = Filesystem::trailingSlashIt($abspath);
    }

    /**
     * Finalizes a migration. Safe to call again after a stop or while another call is running.
     *
     * @param string   $migrationId
     * @param string[] $skippedFiles Files the source could not send because they were deleted
     * @return array{finalized: bool, phase?: string, result?: array}
     * @throws MigrationNotFoundException
     * @throws MigrationConflictException
     * @throws \Exception
     */
    public function finalize(string $migrationId, array $skippedFiles = []): array
    {
        $registry = new MigrationRegistry($migrationId);
        $status = $registry->getStatus();

        if (!$status) {
            throw new MigrationNotFoundException('Migration not found');
        }

        if (($status['status'] ?? '') === 'abandoned') {
            throw new MigrationConflictException('This migration was replaced by a newer one');
        }

        $journal = self::readJournal($migrationId);

        if ($journal['state'] === 'done') {
            return ['finalized' => true, 'result' => $journal['result'] ?? []];
        }

        $lock = $this->lock($migrationId);

        if ($lock === null) {
            return ['finalized' => false, 'phase' => 'finalizing'];
        }

        try {
            // Another call may have finished while this one was starting.
            $journal = self::readJournal($migrationId);

            if ($journal['state'] === 'done') {
                return ['finalized' => true, 'result' => $journal['result'] ?? []];
            }

            return ['finalized' => true, 'result' => $this->run($registry, $migrationId, $skippedFiles, $journal)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Path of the journal of a migration.
     *
     * @param string $migrationId
     * @return string
     */
    public static function journalFile(string $migrationId): string
    {
        return Config::get('migration_dir') . 'collected/' . $migrationId . '/finalize.json';
    }

    /**
     * Reads the journal. A migration that never started to finalize has the state null.
     *
     * @param string $migrationId
     * @return array
     */
    public static function readJournal(string $migrationId): array
    {
        $file = self::journalFile($migrationId);
        $journal = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($journal) ? $journal + ['state' => null] : ['state' => null];
    }

    /**
     * Writes the journal.
     *
     * @param string $migrationId
     * @param array  $journal
     * @throws \RuntimeException
     */
    public static function writeJournal(string $migrationId, array $journal): void
    {
        if (!Filesystem::writeFileAtomic(self::journalFile($migrationId), (string) json_encode($journal))) {
            throw new \RuntimeException('Failed to write the finalize journal');
        }
    }

    /**
     * Takes the lock of the migration without waiting. Null when another call holds it.
     *
     * @param string $migrationId
     * @return resource|null
     */
    private function lock(string $migrationId)
    {
        $handle = @fopen(dirname(self::journalFile($migrationId)) . '/status.lock', 'c');

        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param MigrationRegistry $registry
     * @param string            $migrationId
     * @param string[]          $skippedFiles
     * @param array             $journal
     * @return array The result for the source
     * @throws \Exception
     */
    private function run(MigrationRegistry $registry, string $migrationId, array $skippedFiles, array $journal): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::TIME_LIMIT);
        }

        $map = $registry->getTableMap();
        $status = $registry->getStatus() ?? [];

        // A call that stops halfway leaves a journal. The next call goes on from it.
        if (!in_array($journal['state'], self::IN_PROGRESS, true)) {
            $journal = $this->begin($registry, $migrationId, $status, $map, $skippedFiles);
        }

        $keepBackup = !empty($status['keep_backup']);
        $staged = (new FileRestoreHandler($registry, $this->abspath))->staged();
        $swapper = new TableSwapper($this->wpdb);

        $this->enterMaintenance();

        try {
            if ($journal['state'] === 'started') {
                $moved = $staged->moveIntoPlace();
                $journal['moved'] = $moved['moved'];
                $journal['warnings'] = $moved['warnings'];
                $journal['state'] = 'files_moved';
                self::writeJournal($migrationId, $journal);
            }

            if ($journal['state'] === 'files_moved') {
                $this->swapTables($swapper, $map, $keepBackup, $staged);
                $journal['state'] = 'db_swapped';
                self::writeJournal($migrationId, $journal);
            }
        } catch (\Throwable $e) {
            $this->fail($registry, $migrationId, $journal, $e);
            throw $e;
        }

        return $this->complete($registry, $migrationId, $journal, $staged);
    }

    /**
     * Checks that the migration can be finalized and starts the journal.
     *
     * @param MigrationRegistry $registry
     * @param string            $migrationId
     * @param array             $status
     * @param array             $map
     * @param string[]          $skippedFiles
     * @return array The journal
     * @throws MigrationConflictException
     * @throws \Exception
     */
    private function begin(MigrationRegistry $registry, string $migrationId, array $status, array $map, array $skippedFiles): array
    {
        $skipped = $registry->knownSkippedFiles($status, $skippedFiles);

        if (!$registry->isRestoreComplete($skipped)) {
            throw new MigrationConflictException('Migration manifest is incomplete');
        }

        $missing = (new TableSwapper($this->wpdb))->missingTemporary($map);

        if ($missing !== []) {
            throw new MigrationConflictException(esc_html('Temporary tables are missing: ' . implode(', ', $missing)));
        }

        $optionsTable = $this->optionsTable($map);

        if ($optionsTable === null) {
            throw new MigrationConflictException('The migration does not contain the options table');
        }

        $this->keepThisPluginActive($optionsTable);
        $this->keepThisPluginSettings($optionsTable);

        $journal = [
            'state' => 'started',
            'started_at' => gmdate('c'),
            'keep_backup' => !empty($status['keep_backup']),
            'skipped_files' => $skipped,
        ];
        self::writeJournal($migrationId, $journal);

        return $journal;
    }

    /**
     * Swaps the tables. After a stop the swap may be done already: the temporary tables are gone then.
     * When the swap fails, the files that were moved go back.
     *
     * @param TableSwapper $swapper
     * @param array        $map
     * @param bool         $keepBackup
     * @param StagedFiles  $staged
     * @throws \Exception
     */
    private function swapTables(TableSwapper $swapper, array $map, bool $keepBackup, StagedFiles $staged): void
    {
        try {
            if ($map !== [] && count($swapper->missingTemporary($map)) === count($map)) {
                if (!$keepBackup) {
                    $swapper->dropBackups($map);
                }

                return;
            }

            $swapper->swap($map, $keepBackup);
        } catch (\Throwable $e) {
            $problems = $staged->rollback();
            $message = $e->getMessage();

            if ($problems !== []) {
                $message .= ' (not every file could be restored: ' . implode('; ', $problems) . ')';
            }

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous exception, not output
            throw new \RuntimeException(esc_html($message), 0, $e);
        }
    }

    /**
     * Ends the work after the swap. The new options are live from here, so nothing reads or writes them.
     *
     * @param MigrationRegistry $registry
     * @param string            $migrationId
     * @param array             $journal
     * @param StagedFiles       $staged
     * @return array
     */
    private function complete(MigrationRegistry $registry, string $migrationId, array $journal, StagedFiles $staged): array
    {
        // A persistent object cache (Redis, Memcached) still holds the options of the old database.
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        try {
            $staged->finish();
        } catch (\Throwable $e) {
            error_log("PanelAlpha Migrator: Migration {$migrationId} could not clean up its staged files: " . $e->getMessage());
        }

        $this->leaveMaintenance();
        $registry->updateStatus('restored');

        $moved = (int) ($journal['moved'] ?? 0);
        $skipped = $journal['skipped_files'] ?? [];
        $warnings = $journal['warnings'] ?? [];

        if ($moved > 0) {
            error_log("MIGRATION COMPLETE: Migration {$migrationId} moved {$moved} staged files and folders into place.");
        }

        $result = [
            'action' => 'migration_completed',
            'message' => 'Migration completed successfully. Domains were replaced during transfer.'
        ];

        if ($skipped !== []) {
            error_log("MIGRATION COMPLETE: Migration {$migrationId} skipped files deleted on the source: " . implode(', ', $skipped));
            $result['skipped_files'] = $skipped;
            $result['warning'] = 'Some files were deleted on the source before they could be sent and were skipped.';
        }

        if ($warnings !== []) {
            error_log("MIGRATION COMPLETE: Migration {$migrationId} did not replace: " . implode('; ', $warnings));
            $result['warnings'] = $warnings;
        }

        $journal['state'] = 'done';
        $journal['done_at'] = gmdate('c');
        $journal['result'] = $result;
        self::writeJournal($migrationId, $journal);

        // Only the latest kept backup stays on the destination.
        try {
            (new ReceiveCleanup($this->wpdb))->removeOtherBackups($migrationId);
        } catch (\Throwable $e) {
            error_log("PanelAlpha Migrator: Removing older backups failed: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * The site goes back to its old state. The temporary tables stay for diagnosis.
     *
     * @param MigrationRegistry $registry
     * @param string            $migrationId
     * @param array             $journal
     * @param \Throwable        $e
     */
    private function fail(MigrationRegistry $registry, string $migrationId, array $journal, \Throwable $e): void
    {
        error_log("PanelAlpha Migrator: Finalizing migration {$migrationId} failed: " . $e->getMessage());

        $this->leaveMaintenance();

        $journal['state'] = 'failed';
        $journal['error'] = $e->getMessage();

        try {
            self::writeJournal($migrationId, $journal);
        } catch (\Throwable $writeError) {
            error_log('PanelAlpha Migrator: ' . $writeError->getMessage());
        }

        $registry->updateStatus('finalize_failed');
    }

    /**
     * The temporary options table of the migration, or null when the migration has no options table.
     *
     * @param array $map
     * @return string|null
     */
    private function optionsTable(array $map): ?string
    {
        foreach ($map as $names) {
            if ($names['destination'] === $this->wpdb->base_prefix . 'options') {
                return $names['temp'];
            }
        }

        return null;
    }

    /**
     * The new options come from the source, which may not have this plugin active.
     * Adds it to the active plugins of the new options, so the destination keeps answering after the swap.
     *
     * @param string $optionsTable
     * @throws \Exception
     */
    private function keepThisPluginActive(string $optionsTable): void
    {
        $wpdb = $this->wpdb;
        $value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $optionsTable, 'active_plugins'));
        $active = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : false;
        $basename = $this->pluginBasename();

        if (!is_array($active) || in_array($basename, $active, true)) {
            return;
        }

        $active[] = $basename;
        sort($active);

        $previous = $wpdb->suppress_errors(true);

        try {
            $updated = $wpdb->query($wpdb->prepare(
                'UPDATE %i SET option_value = %s WHERE option_name = %s',
                $optionsTable,
                serialize($active),
                'active_plugins'
            ));
            $error = $wpdb->last_error;
        } finally {
            $wpdb->suppress_errors($previous);
        }

        if ($updated === false) {
            throw new \Exception(esc_html('Failed to keep the plugin active in the new options: ' . $error));
        }
    }

    /**
     * Keeps the token and the settings of this site. The new options table replaces the live one,
     * so the rows of the source are removed and the live rows are copied in.
     * Without the token a retried finalize or status call from the source would be refused.
     *
     * @param string $optionsTable
     * @throws \Exception
     */
    private function keepThisPluginSettings(string $optionsTable): void
    {
        $wpdb = $this->wpdb;
        $names = [TokenHelper::TOKEN_OPTION, TokenHelper::SETTINGS_OPTION];
        $copied = true;
        $previous = $wpdb->suppress_errors(true);

        try {
            $deleted = $wpdb->query($wpdb->prepare(
                'DELETE FROM %i WHERE option_name IN (%s, %s)',
                $optionsTable,
                $names[0],
                $names[1]
            ));
            $error = $wpdb->last_error;

            if ($deleted !== false) {
                $copied = $wpdb->query($wpdb->prepare(
                    'INSERT INTO %i (option_name, option_value, autoload) SELECT option_name, option_value, autoload FROM %i WHERE option_name IN (%s, %s)',
                    $optionsTable,
                    $wpdb->base_prefix . 'options',
                    $names[0],
                    $names[1]
                ));
                $error = $wpdb->last_error;
            }
        } finally {
            $wpdb->suppress_errors($previous);
        }

        if ($deleted === false || $copied === false) {
            throw new \Exception(esc_html('Failed to keep the token and settings in the new options: ' . $error));
        }
    }

    private function pluginBasename(): string
    {
        $directory = PathProvider::getPluginDir();

        if (function_exists('plugin_basename')) {
            return plugin_basename($directory . 'panelalpha-migrator.php');
        }

        return basename(rtrim($directory, '/')) . '/panelalpha-migrator.php';
    }

    /**
     * Puts the site in maintenance mode (WordPress ignores a .maintenance file after ten minutes).
     * The window is short, so a site where the file cannot be written is migrated without it.
     */
    private function enterMaintenance(): void
    {
        @file_put_contents($this->abspath . '.maintenance', '<?php $upgrading = ' . time() . '; ?>');
    }

    private function leaveMaintenance(): void
    {
        wp_delete_file($this->abspath . '.maintenance');
    }
}
