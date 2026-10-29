<?php

namespace PanelAlpha\Migrator\Restore;

use PanelAlpha\Migrator\Helpers\BinarySafeEncoder;
use PanelAlpha\Migrator\Helpers\CreateStatementNormalizer;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;

/**
 * Class TableRestoreHandler
 *
 * @package PanelAlpha\Migrator\Restore
 *
 * Handles the restoration of database tables.
 * Tables are written under the temporary names of the migration table map.
 * They replace the live tables later, in TableSwapper.
 */
class TableRestoreHandler
{
    private const MAX_STATEMENT_BYTES = 1048576;
    private const PACKET_RESERVE_BYTES = 65536;
    private const FALLBACK_PACKET_BYTES = 1048576;

    /** @var \wpdb */
    private object $wpdb;
    private MigrationRegistry $registry;
    private ?int $maxAllowedPacket = null;

    /**
     * TableRestoreHandler constructor.
     *
     * @param \wpdb             $wpdb
     * @param MigrationRegistry $registry
     */
    public function __construct(object $wpdb, MigrationRegistry $registry)
    {
        $this->wpdb = $wpdb;
        $this->registry = $registry;
    }

    /**
     * Restores the structure of a table.
     *
     * @param array $chunkData
     * @return array
     * @throws MigrationConflictException
     * @throws \Exception
     */
    public function restoreStructure(array $chunkData): array
    {
        $names = $this->namesFor($chunkData['table']['name']);

        $createStatement = CreateStatementNormalizer::forTempTable(
            CreateStatementNormalizer::normalize($chunkData['table']['create_statement']),
            $this->tempNames()
        );

        try {
            $this->withImportSession(function () use ($names, $createStatement): void {
                $this->run('DROP TABLE IF EXISTS ' . TableSwapper::quoteIdentifier($names['temp']));
                $this->run($createStatement);
            });

            $this->registry->markChunkRestored('table_structure', $chunkData);

            return [
                'action' => 'restored_table_structure',
                'table' => $names['destination']
            ];

        } catch (\RuntimeException $e) {
            throw new \Exception(esc_html("Failed to restore table structure for {$names['destination']}: " . $e->getMessage()));
        }
    }

    /**
     * Restores data for a table.
     *
     * @param array $chunkData
     * @return array
     * @throws MigrationConflictException
     * @throws \Exception
     */
    public function restoreData(array $chunkData): array
    {
        $names = $this->namesFor($chunkData['table']['name']);
        $tableName = $names['destination'];
        $columns = $chunkData['columns'];
        $data = $chunkData['data'];
        $checksum = $chunkData['checksum'];

        $this->validateChecksum($data, $checksum);

        $chunkNumber = (int) ($chunkData['table']['chunk_info']['chunk_number'] ?? 0);
        if ($chunkNumber > 0 && $this->registry->hasRestoredTableChunk($chunkData['table']['name'], $chunkNumber)) {
            return [
                'action' => 'restored_table_data',
                'table' => $tableName,
                'chunk' => $chunkNumber
            ];
        }

        if (!empty($chunkData['binary_map'])) {
            $data = BinarySafeEncoder::decode($data, $chunkData['binary_map']);
        }

        try {
            $this->withImportSession(function () use ($names, $columns, $data): void {
                $this->insertRows($names['temp'], $columns, $data);
            });
            $this->registry->markChunkRestored('table_data', $chunkData);

            return [
                'action' => 'restored_table_data',
                'table' => $tableName,
                'chunk' => $chunkData['table']['chunk_info']['chunk_number']
            ];

        } catch (\RuntimeException $e) {
            throw new \Exception(esc_html("Failed to restore table data for {$tableName}: " . $e->getMessage()));
        }
    }

    /**
     * Looks up the destination, temporary and backup names of a source table.
     *
     * @param string $sourceName
     * @return array{destination: string, temp: string, backup: string}
     * @throws MigrationConflictException
     */
    private function namesFor(string $sourceName): array
    {
        $map = $this->registry->getTableMap();

        if (!isset($map[$sourceName])) {
            throw new MigrationConflictException(esc_html("Table {$sourceName} is not part of this migration. Send the info chunk first."));
        }

        return $map[$sourceName];
    }

    /**
     * Source table name => temporary table name, for the REFERENCES of foreign keys.
     *
     * @return array<string, string>
     */
    private function tempNames(): array
    {
        return array_map(static function (array $names): string {
            return $names['temp'];
        }, $this->registry->getTableMap());
    }

    /**
     * Runs the callback with the session settings an import needs, and puts them back afterwards.
     * Database errors do not print into the response, and queries are not checked for valid UTF-8,
     * because raw bytes from BinarySafeEncoder are not.
     *
     * @param callable $callback
     */
    private function withImportSession(callable $callback): void
    {
        $wpdb = $this->wpdb;
        $suppressErrors = $wpdb->suppress_errors(true);
        $checkQuery = $wpdb->check_current_query;
        $sqlMode = $wpdb->get_var('SELECT @@SESSION.sql_mode');
        $foreignKeyChecks = $wpdb->get_var('SELECT @@SESSION.foreign_key_checks');
        $charsetChanged = false;

        try {
            $this->run("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
            $this->run('SET SESSION foreign_key_checks = 0');

            // On a utf8 destination 4-byte characters would be lost.
            if ($wpdb->charset !== 'utf8mb4' && $wpdb->has_cap('utf8mb4')) {
                $wpdb->set_charset($wpdb->dbh, 'utf8mb4', '');
                $charsetChanged = true;
            }

            $callback();
        } finally {
            if ($charsetChanged) {
                $wpdb->set_charset($wpdb->dbh);
            }
            if ($sqlMode !== null) {
                $wpdb->query($wpdb->prepare('SET SESSION sql_mode = %s', $sqlMode));
            }
            if ($foreignKeyChecks !== null) {
                $wpdb->query($wpdb->prepare('SET SESSION foreign_key_checks = %d', (int) $foreignKeyChecks));
            }
            $wpdb->check_current_query = $checkQuery;
            $wpdb->suppress_errors($suppressErrors);
        }
    }

    /**
     * Runs a statement of the import.
     *
     * @param string $sql
     * @throws \RuntimeException
     */
    private function run(string $sql): void
    {
        $this->wpdb->check_current_query = false;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the statements are SET SESSION, CREATE TABLE from the source and row values already passed through prepare()
        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException(esc_html($this->wpdb->last_error));
        }
    }

    /**
     * Validates the checksum of table data.
     *
     * @param array  $data
     * @param string $expectedChecksum
     * @throws \Exception
     */
    private function validateChecksum(array $data, string $expectedChecksum): void
    {
        $previousPrecision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            $json = serialize($data);
        }

        $actualChecksum = hash('sha256', $json);

        ini_set('serialize_precision', $previousPrecision);

        if ($actualChecksum !== $expectedChecksum) {
            throw new \Exception('Checksum mismatch for table data');
        }
    }

    /**
     * Inserts rows into a table. Rows share a statement while it stays under the size limit.
     * One commit per chunk instead of one per statement; a failed chunk leaves no rows behind.
     *
     * @param string $tableName
     * @param array  $columns
     * @param array  $data
     * @throws \RuntimeException
     */
    private function insertRows(string $tableName, array $columns, array $data): void
    {
        $packet = $this->maxAllowedPacket();
        $limit = max(1024, min($packet - self::PACKET_RESERVE_BYTES, self::MAX_STATEMENT_BYTES));
        $head = 'REPLACE INTO ' . TableSwapper::quoteIdentifier($tableName)
            . ' (' . implode(', ', array_map([TableSwapper::class, 'quoteIdentifier'], $columns)) . ') VALUES ';

        $this->run('START TRANSACTION');

        try {
            $values = [];
            $length = strlen($head);

            foreach ($data as $row) {
                $rowSql = $this->rowSql($row);

                if (strlen($head) + strlen($rowSql) >= $packet) {
                    throw new \RuntimeException("A row is larger than max_allowed_packet ({$packet} bytes) of the destination database");
                }

                if ($values !== [] && $length + strlen($rowSql) + 1 > $limit) {
                    $this->run($head . implode(',', $values));
                    $values = [];
                    $length = strlen($head);
                }

                $values[] = $rowSql;
                $length += strlen($rowSql) + 1;
            }

            if ($values !== []) {
                $this->run($head . implode(',', $values));
            }

            $this->run('COMMIT');
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Builds one "(value, ...)" group. NULL stays a literal, everything else goes through prepare as a string.
     *
     * @param array $row
     * @return string
     */
    private function rowSql(array $row): string
    {
        $placeholders = [];
        $values = [];

        foreach ($row as $value) {
            if ($value === null) {
                $placeholders[] = 'NULL';
                continue;
            }

            $placeholders[] = '%s';

            if (is_float($value)) {
                $value = sprintf('%.17g', $value);
            } elseif (is_bool($value)) {
                $value = (int) $value;
            }

            $values[] = (string) $value;
        }

        $group = '(' . implode(',', $placeholders) . ')';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- one %s is added for each value of the row
        return $values === [] ? $group : $this->wpdb->prepare($group, $values);
    }

    /**
     * Reads max_allowed_packet of the destination database once.
     *
     * @return int
     */
    private function maxAllowedPacket(): int
    {
        if ($this->maxAllowedPacket === null) {
            $packet = (int) $this->wpdb->get_var('SELECT @@max_allowed_packet');
            $this->maxAllowedPacket = $packet > 0 ? $packet : self::FALLBACK_PACKET_BYTES;
        }

        return $this->maxAllowedPacket;
    }
}
