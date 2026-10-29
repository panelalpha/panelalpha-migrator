<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Restore;

use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;

/**
 * Class TableSwapper
 *
 * @package PanelAlpha\Migrator\Restore
 *
 * Names the temporary and backup tables of a migration and swaps the temporary tables in.
 * The map is source table name => destination (live) name, temporary name, backup name.
 * Only names from the map are ever dropped or renamed, and never a live table.
 */
class TableSwapper
{
    public const TEMP_PREFIX = 'pmt_';
    public const BACKUP_PREFIX = 'pmb_';

    private const MAX_NAME_LENGTH = 64;
    private const HASH_ATTEMPTS = 10;
    private const LOCK_WAIT_TIMEOUT = 30;

    /** @var \wpdb */
    private object $wpdb;

    /**
     * TableSwapper constructor.
     *
     * @param \wpdb $wpdb
     */
    public function __construct(object $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Quotes a table name for SQL.
     *
     * @param string $name
     * @return string
     */
    public static function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Builds the name map for the tables of a migration and stores it in the migration status.
     * A migration that already has a hash keeps it, so a repeated info chunk uses the same names.
     *
     * @param MigrationRegistry $registry
     * @param string[]          $sourceNames
     * @param string            $sourcePrefix
     * @return array<string, array{destination: string, temp: string, backup: string}>
     * @throws \Exception
     */
    public function createMap(MigrationRegistry $registry, array $sourceNames, string $sourcePrefix): array
    {
        $hash = $registry->getTableHash() ?? $this->chooseHash();
        $map = $this->buildMap($hash, $sourceNames, $sourcePrefix);
        $registry->saveTableMap($hash, $map);

        return $map;
    }

    /**
     * Builds the map for a hash without storing it.
     *
     * @param string   $hash
     * @param string[] $sourceNames
     * @param string   $sourcePrefix
     * @return array<string, array{destination: string, temp: string, backup: string}>
     * @throws \Exception
     */
    public function buildMap(string $hash, array $sourceNames, string $sourcePrefix): array
    {
        $map = [];
        $used = [];

        foreach ($sourceNames as $sourceName) {
            $destination = $this->destinationName($sourceName, $sourcePrefix);

            if (isset($used[$destination])) {
                throw new \Exception(esc_html("Two source tables map to the destination table {$destination}"));
            }
            $used[$destination] = true;

            $map[$sourceName] = [
                'destination' => $destination,
                'temp' => $this->prefixedName(self::TEMP_PREFIX . $hash . '_', $destination),
                'backup' => $this->prefixedName(self::BACKUP_PREFIX . $hash . '_', $destination),
            ];
        }

        return $map;
    }

    /**
     * Moves a source table name to the destination prefix. Tables without the source prefix keep their name.
     *
     * @param string $sourceName
     * @param string $sourcePrefix
     * @return string
     */
    public function destinationName(string $sourceName, string $sourcePrefix): string
    {
        if ($sourcePrefix === '' || !str_starts_with($sourceName, $sourcePrefix)) {
            return $sourceName;
        }

        return $this->wpdb->base_prefix . substr($sourceName, strlen($sourcePrefix));
    }

    /**
     * Picks six hex characters that no temporary or backup table of another migration uses.
     *
     * @return string
     * @throws \Exception
     */
    public function chooseHash(): string
    {
        for ($attempt = 0; $attempt < self::HASH_ATTEMPTS; $attempt++) {
            $hash = bin2hex(random_bytes(3));

            if ($this->tablesLike(self::TEMP_PREFIX . $hash . '_') === [] && $this->tablesLike(self::BACKUP_PREFIX . $hash . '_') === []) {
                return $hash;
            }
        }

        throw new \Exception('Could not find a free prefix for the temporary tables');
    }

    /**
     * Checks that the database user can create, rename and drop tables, which the swap needs.
     *
     * @param string $hash
     * @throws DatabasePrivilegesException
     */
    public function checkPrivileges(string $hash): void
    {
        $probe = self::TEMP_PREFIX . $hash . '_probe';
        $renamed = self::TEMP_PREFIX . $hash . '_probe2';

        try {
            $ok = $this->query($this->wpdb->prepare('DROP TABLE IF EXISTS %i, %i', $probe, $renamed)) !== false
                && $this->query($this->wpdb->prepare('CREATE TABLE %i (id int) ENGINE=InnoDB', $probe)) !== false
                && $this->query($this->wpdb->prepare('RENAME TABLE %i TO %i', $probe, $renamed)) !== false;
        } finally {
            $this->query($this->wpdb->prepare('DROP TABLE IF EXISTS %i, %i', $probe, $renamed));
        }

        if (!$ok) {
            throw new DatabasePrivilegesException();
        }
    }

    /**
     * Lists the temporary tables of the map that do not exist.
     *
     * @param array $map
     * @return string[]
     */
    public function missingTemporary(array $map): array
    {
        $missing = [];

        foreach ($map as $names) {
            if (!$this->tableExists($names['temp'])) {
                $missing[] = $names['temp'];
            }
        }

        return $missing;
    }

    /**
     * Replaces the live tables with the temporary ones in one RENAME TABLE statement.
     * The live tables go to the backup names. Either every table is renamed or none is.
     * The backup tables are dropped after the swap unless $keepBackup is set.
     *
     * @param array $map
     * @param bool  $keepBackup
     * @return array{swapped: int, backup_kept: bool}
     * @throws \Exception
     */
    public function swap(array $map, bool $keepBackup = false): array
    {
        $missing = $this->missingTemporary($map);
        if ($missing !== []) {
            throw new \Exception(esc_html('Temporary tables are missing: ' . implode(', ', $missing)));
        }

        $pairs = [];
        $backups = [];

        foreach ($map as $names) {
            if ($this->tableExists($names['destination'])) {
                $pairs[] = self::quoteIdentifier($names['destination']) . ' TO ' . self::quoteIdentifier($names['backup']);
                $backups[] = $names['backup'];
            }
            $pairs[] = self::quoteIdentifier($names['temp']) . ' TO ' . self::quoteIdentifier($names['destination']);
        }

        if ($pairs === []) {
            return ['swapped' => 0, 'backup_kept' => false];
        }

        $previousTimeout = $this->wpdb->get_var('SELECT @@SESSION.lock_wait_timeout');
        $this->query('SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT_TIMEOUT);

        try {
            $renamed = $this->query('RENAME TABLE ' . implode(', ', $pairs));
            $error = $this->wpdb->last_error;
        } finally {
            if ($previousTimeout !== null) {
                $this->query('SET SESSION lock_wait_timeout = ' . (int) $previousTimeout);
            }
        }

        if ($renamed === false) {
            throw new \Exception(esc_html('Failed to swap the tables: ' . $error));
        }

        if (!$keepBackup && $backups !== []) {
            $this->dropTables($backups, self::BACKUP_PREFIX);
        }

        return ['swapped' => count($map), 'backup_kept' => $keepBackup && $backups !== []];
    }

    /**
     * Drops the temporary tables of a migration that did not finish.
     *
     * @param array $map
     */
    public function dropTemporary(array $map): void
    {
        $this->dropTables(array_column($map, 'temp'), self::TEMP_PREFIX);
    }

    /**
     * Drops the backup tables of a finished migration.
     *
     * @param array $map
     */
    public function dropBackups(array $map): void
    {
        $this->dropTables(array_column($map, 'backup'), self::BACKUP_PREFIX);
    }

    /**
     * Names a table under a prefix. A name over the MySQL limit ends with a hash of the destination name.
     *
     * @param string $prefix
     * @param string $destination
     * @return string
     */
    private function prefixedName(string $prefix, string $destination): string
    {
        $name = $prefix . $destination;

        if (strlen($name) <= self::MAX_NAME_LENGTH) {
            return $name;
        }

        return $prefix . substr(sha1($destination), 0, 40);
    }

    /**
     * Drops tables that start with the given prefix, ignoring any other name.
     * The foreign key checks are off so tables that reference each other drop in any order.
     *
     * @param string[] $names
     * @param string   $requiredPrefix
     */
    private function dropTables(array $names, string $requiredPrefix): void
    {
        $tables = [];

        foreach ($names as $name) {
            if (str_starts_with($name, $requiredPrefix)) {
                $tables[] = $name;
            }
        }

        if ($tables === []) {
            return;
        }

        $previous = $this->wpdb->get_var('SELECT @@SESSION.foreign_key_checks');
        $this->query('SET SESSION foreign_key_checks = 0');

        try {
            $this->query($this->wpdb->prepare('DROP TABLE IF EXISTS ' . implode(', ', array_fill(0, count($tables), '%i')), $tables));
        } finally {
            $this->query('SET SESSION foreign_key_checks = ' . (int) $previous);
        }
    }

    /**
     * @param string $name
     * @return bool
     */
    private function tableExists(string $name): bool
    {
        return in_array(strtolower($name), array_map('strtolower', $this->tablesLike($name)), true);
    }

    /**
     * Lists tables whose name starts with the given text (an exact name matches itself).
     *
     * @param string $prefix
     * @return string[]
     */
    private function tablesLike(string $prefix): array
    {
        $wpdb = $this->wpdb;
        $pattern = $wpdb->esc_like($prefix) . '%';

        return (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
    }

    /**
     * Runs a statement without letting wpdb print the database error into the response.
     *
     * @param string $sql
     * @return int|bool
     */
    private function query(string $sql)
    {
        $previous = $this->wpdb->suppress_errors(true);

        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SET SESSION and RENAME TABLE cannot be prepared; table names are quoted with quoteIdentifier()
            return $this->wpdb->query($sql);
        } finally {
            $this->wpdb->suppress_errors($previous);
        }
    }
}
