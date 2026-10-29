<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator;

use PanelAlpha\Migrator\Helpers\Filesystem;
use PanelAlpha\Migrator\Helpers\PathProvider;
use PanelAlpha\Migrator\Restore\ReceiveCleanup;

/**
 * Class Uninstaller
 *
 * @package PanelAlpha\Migrator
 *
 * Removes everything the plugin stored when it is deleted from the dashboard: its options, the
 * scheduled cleanup, the leftover migration tables, the migrations folder and the lock and setup files.
 * Only what the plugin creates is removed. The main plugin file is not loaded during an uninstall,
 * so nothing here depends on the plugin constants or on Config.
 */
class Uninstaller
{
    private const OPTIONS = ['panelalpha_migrator_token', 'panelalpha_migrator_settings'];
    private const SECRET_OPTION_PREFIX = 'panelalpha_migrator_secret_';
    private const TABLE_PREFIXES = ['pmt_', 'pmb_'];
    private const TABLE_PATTERN = '/^pm[tb]_[0-9a-f]{6}_/';
    private const CONTENT_FILES = ['panelalpha-migrator-lock.php', 'panelalpha-migrator-lock.json', 'panelalpha-migrator-setup.php'];
    private const FOLDER_FILES = ['.htaccess', 'index.php'];

    /** @var \wpdb */
    private object $wpdb;

    private string $contentDir;

    /**
     * Uninstaller constructor.
     *
     * @param \wpdb  $wpdb
     * @param string $contentDir The WordPress content folder (WP_CONTENT_DIR)
     */
    public function __construct(object $wpdb, string $contentDir)
    {
        $this->wpdb = $wpdb;
        $this->contentDir = rtrim($contentDir, '/\\');
    }

    /**
     * Removes all data of the plugin.
     */
    public function run(): void
    {
        $this->deleteOptions();

        wp_clear_scheduled_hook(ReceiveCleanup::HOOK);

        $this->dropTables();
        $this->removeMigrationsFolder($this->contentDir . '/migrations');

        foreach (self::CONTENT_FILES as $name) {
            $this->removeFile($this->contentDir . '/' . $name);
        }
    }

    /**
     * Deletes the token, the settings and the secret of every migration.
     */
    private function deleteOptions(): void
    {
        $wpdb = $this->wpdb;
        $names = self::OPTIONS;
        $like = $wpdb->esc_like(self::SECRET_OPTION_PREFIX) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the secret options have one name per migration, so they are found by prefix
        $secrets = $wpdb->get_col($wpdb->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $like));

        foreach (array_merge($names, (array) $secrets) as $name) {
            delete_option((string) $name);
        }
    }

    /**
     * Drops the temporary and the kept backup tables of migrations.
     * The foreign key checks are off so tables that reference each other drop in any order.
     */
    private function dropTables(): void
    {
        $wpdb = $this->wpdb;
        $tables = [];

        foreach (self::TABLE_PREFIXES as $prefix) {
            $pattern = $wpdb->esc_like($prefix) . '%';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the tables are found by their name
            foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern)) as $name) {
                if (preg_match(self::TABLE_PATTERN, (string) $name) === 1) {
                    $tables[] = (string) $name;
                }
            }
        }

        if ($tables === []) {
            return;
        }

        $previous = $wpdb->get_var('SELECT @@SESSION.foreign_key_checks'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a session variable
        $wpdb->query('SET SESSION foreign_key_checks = 0'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- SET SESSION cannot be prepared

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the plugin owns these tables, one %i per table
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS ' . implode(', ', array_fill(0, count($tables), '%i')), $tables));
        } finally {
            $wpdb->query('SET SESSION foreign_key_checks = ' . (int) $previous); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SET SESSION cannot be prepared, the value is cast to int
        }
    }

    /**
     * Removes the entries the plugin creates in the migrations folder.
     * The folder itself goes only when nothing else is left in it, because the name is generic.
     *
     * @param string $directory
     */
    private function removeMigrationsFolder(string $directory): void
    {
        if (is_link($directory) || !is_dir($directory)) {
            return;
        }

        foreach (array_diff((array) scandir($directory), ['.', '..']) as $name) {
            if ($this->isPluginEntry((string) $name)) {
                $this->removePath($directory . '/' . $name);
            }
        }

        if (array_diff((array) scandir($directory), ['.', '..']) === []) {
            Filesystem::direct()->rmdir($directory, false);
        }
    }

    /**
     * Tells if a name in the migrations folder is one the plugin creates.
     *
     * @param string $name
     * @return bool
     */
    private function isPluginEntry(string $name): bool
    {
        if ($name === 'collected' || in_array($name, self::FOLDER_FILES, true)) {
            return true;
        }

        if ($this->isMigrationId($name)) {
            return true;
        }

        if (preg_match('/^(?:(.+)\.secret\.php|(.+)\.json|skipped_files_(.+)\.log)$/', $name, $matches) !== 1) {
            return false;
        }

        return $this->isMigrationId(end($matches));
    }

    /**
     * Migration IDs made by the plugin start with mig_, so a folder such as cache is never taken for one.
     *
     * @param string $name
     * @return bool
     */
    private function isMigrationId(string $name): bool
    {
        return strpos($name, 'mig_') === 0 && PathProvider::isSafeMigrationId($name);
    }

    /**
     * Removes a file, a link or a folder with everything in it. Links are never followed.
     *
     * @param string $path
     */
    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            $this->removeFile($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $name) {
            $this->removePath($path . '/' . $name);
        }

        Filesystem::direct()->rmdir($path, false);
    }

    private function removeFile(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            wp_delete_file($path);
        }
    }
}
