<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Services\Scanner;

use PanelAlpha\Migrator\Dto\ChunkInfo;
use PanelAlpha\Migrator\Dto\IndexInfo;
use PanelAlpha\Migrator\Dto\TableChunk;
use PanelAlpha\Migrator\Dto\TableInfo;
use PanelAlpha\Migrator\Dto\TableStructure;

/**
 * Class DatabaseScanner
 *
 * @package PanelAlpha\Migrator\Services\Scanner
 *
 * Scans the database to gather information about tables.
 */
class DatabaseScanner
{
    private const ROWS_PER_CHUNK = 100;

    private ?object $wpdb;

    /**
     * DatabaseScanner constructor.
     *
     * @param \wpdb|null $wpdb The global $wpdb when not given
     */
    public function __construct(?object $wpdb = null)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Scans all tables in the database.
     *
     * @return array Array of TableInfo objects
     */
    public function scan(): array
    {
        $tables = [];
        $allTables = $this->getAllTables();

        foreach ($allTables as $tableName) {
            $tableInfo = $this->getTableInfo($tableName);

            if ($tableInfo !== null) {
                $tables[] = $tableInfo;
            }
        }

        return $tables;
    }

    /**
     * Gets all tables from the database. Views are left out, they hold no rows of their own.
     *
     * @return array Array of table names
     */
    private function getAllTables(): array
    {
        $tables = $this->results("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'", 'ARRAY_N');
        $tableNames = [];

        foreach ($tables as $table) {
            $tableNames[] = $table[0];
        }

        return $tableNames;
    }

    /**
     * Gets information about a single table.
     *
     * @param string $tableName Table name
     * @return TableInfo|null Table information or null
     */
    private function getTableInfo(string $tableName): ?TableInfo
    {
        $size = $this->getTableSize($tableName);
        $rowCount = $this->getTableRowCount($tableName);
        $chunks = $this->calculateTableChunks($rowCount);
        $totalChunks = count($chunks);

        return new TableInfo(
            $tableName,
            $size,
            $rowCount,
            $chunks,
            $totalChunks
        );
    }

    /**
     * Gets the size of a table.
     *
     * @param string $tableName Table name
     * @return int Table size in bytes
     */
    private function getTableSize(string $tableName): int
    {
        $sizeQuery = $this->wpdb()->prepare(
            "SELECT data_length + index_length
            FROM information_schema.TABLES
            WHERE table_schema = %s
            AND table_name = %s",
            $this->wpdb()->dbname,
            $tableName
        );

        return (int) $this->scalar($sizeQuery);
    }

    /**
     * Gets the row count of a table.
     *
     * @param string $tableName Table name
     * @return int Number of rows
     */
    private function getTableRowCount(string $tableName): int
    {
        return (int) $this->scalar($this->wpdb()->prepare('SELECT COUNT(*) FROM %i', $tableName));
    }

    /**
     * Calculates the chunks for a table based on its row count.
     *
     * @param int $rowCount Number of rows
     * @return array Array of ChunkInfo objects
     */
    private function calculateTableChunks(int $rowCount): array
    {
        $totalChunks = $rowCount > 0 ? (int)ceil($rowCount / self::ROWS_PER_CHUNK) : 1;
        $chunks = [];

        for ($i = 0; $i < $totalChunks; $i++) {
            $offset = $i * self::ROWS_PER_CHUNK;
            $limit = self::ROWS_PER_CHUNK;

            $chunks[] = new ChunkInfo(
                $i + 1,
                $offset,
                $limit
            );
        }

        return $chunks;
    }

    /**
     * Gets the structure of a table.
     *
     * @param string $tableName Table name
     * @return TableStructure|null Table structure or null
     */
    public function getTableStructure(string $tableName): ?TableStructure
    {
        $createStatement = $this->getCreateTableStatement($tableName);

        if ($createStatement === null) {
            return null;
        }

        $indexes = $this->getTableIndexes($tableName);

        return new TableStructure(
            $tableName,
            $createStatement,
            $indexes
        );
    }

    /**
     * Gets the CREATE TABLE statement for a table.
     *
     * @param string $tableName Table name
     * @return string|null CREATE TABLE statement or null
     */
    private function getCreateTableStatement(string $tableName): ?string
    {
        $createResult = $this->results($this->wpdb()->prepare('SHOW CREATE TABLE %i', $tableName), 'ARRAY_N');

        if ($createResult === []) {
            return null;
        }

        return $createResult[0][1];
    }

    /**
     * Gets the indexes of a table.
     *
     * @param string $tableName Table name
     * @return array Array of IndexInfo objects
     */
    private function getTableIndexes(string $tableName): array
    {
        $indexesResult = $this->results($this->wpdb()->prepare('SHOW INDEXES FROM %i', $tableName));

        $indexes = [];
        $processedKeys = [];

        foreach ($indexesResult as $index) {
            $keyName = $index->Key_name;

            if (in_array($keyName, $processedKeys, true)) {
                $this->addColumnToExistingIndex($indexes, $keyName, $index->Column_name);
            } else {
                $indexes[] = $this->createIndexInfo($index);
                $processedKeys[] = $keyName;
            }
        }

        return $indexes;
    }

    /**
     * Adds a column to an existing index.
     *
     * @param array  $indexes Array of IndexInfo objects (passed by reference)
     * @param string $keyName Index key name
     * @param string $columnName Column name to add
     */
    private function addColumnToExistingIndex(array &$indexes, string $keyName, string $columnName): void
    {
        foreach ($indexes as &$idx) {
            if ($idx->name === $keyName) {
                $idx->columns[] = $columnName;
                break;
            }
        }
    }

    /**
     * Creates an IndexInfo object from a database index row.
     *
     * @param mixed $index Database index row object
     * @return IndexInfo Index information
     */
    private function createIndexInfo($index): IndexInfo
    {
        $type = 'INDEX';
        if ($index->Key_name === 'PRIMARY') {
            $type = 'PRIMARY';
        } elseif ($index->Non_unique == 0) {
            $type = 'UNIQUE';
        }

        return new IndexInfo(
            $index->Key_name,
            [$index->Column_name],
            $type
        );
    }

    /**
     * Gets a chunk of data from a table.
     *
     * @param string $tableName Table name
     * @param int    $offset Row offset
     * @param int    $limit Row limit
     * @return TableChunk|null Table chunk or null
     */
    public function getTableChunk(string $tableName, int $offset, int $limit): ?TableChunk
    {
        $columns = $this->getTableColumns($tableName);
        $dataResult = $this->getTableData($tableName, $offset, $limit);

        if ($dataResult === null) {
            return null;
        }

        return new TableChunk(
            $columns,
            $dataResult,
            count($dataResult)
        );
    }

    /**
     * Gets the columns of a table.
     *
     * @param string $tableName Table name
     * @return array Array of column names
     */
    private function getTableColumns(string $tableName): array
    {
        $columnsResult = $this->results($this->wpdb()->prepare('SHOW COLUMNS FROM %i', $tableName));

        $columns = [];
        foreach ($columnsResult as $column) {
            $columns[] = $column->Field;
        }

        return $columns;
    }

    /**
     * Gets data from a table with a limit and offset.
     *
     * @param string $tableName Table name
     * @param int    $offset Row offset
     * @param int    $limit Row limit
     * @return array|null Array of rows or null
     */
    private function getTableData(string $tableName, int $offset, int $limit): ?array
    {
        $dataQuery = $this->wpdb()->prepare('SELECT * FROM %i LIMIT %d OFFSET %d', $tableName, $limit, $offset);

        return $this->results($dataQuery, 'ARRAY_N', true);
    }

    /**
     * Gets a checksum for data.
     *
     * @param mixed $data Data to checksum
     * @return string SHA256 hash
     */
    public function getChecksum($data): string
    {
        $previousPrecision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            $json = serialize($data);
        }

        $hash = hash('sha256', $json);

        ini_set('serialize_precision', $previousPrecision);

        return $hash;
    }

    /**
     * Gets the total size of the database.
     *
     * @return int Total size in bytes
     */
    public function getTotalSize(): int
    {
        $sizeQuery = $this->wpdb()->prepare(
            "SELECT SUM(data_length + index_length)
            FROM information_schema.TABLES
            WHERE table_schema = %s",
            $this->wpdb()->dbname
        );

        return (int) $this->scalar($sizeQuery);
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
     * Reads rows. A failed query throws.
     *
     * @param string $query SQL query
     * @param string $output OBJECT or ARRAY_N
     * @param bool   $flush Frees the result held by $wpdb, for queries that return many rows
     * @return array Array of rows
     * @throws \RuntimeException
     */
    private function results(string $query, string $output = 'OBJECT', bool $flush = false): array
    {
        return $this->read(function ($wpdb) use ($query, $output, $flush): array {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- every caller passes a statement built with prepare()
            $rows = $wpdb->get_results($query, $output);
            $this->assertNoError($wpdb);

            if ($flush) {
                $wpdb->flush();
            }

            return is_array($rows) ? $rows : [];
        });
    }

    /**
     * Reads one value.
     *
     * @param string $query SQL query
     * @return string|null Value or null
     * @throws \RuntimeException
     */
    private function scalar(string $query)
    {
        return $this->read(function ($wpdb) use ($query) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- every caller passes a statement built with prepare()
            $value = $wpdb->get_var($query);
            $this->assertNoError($wpdb);

            return $value;
        });
    }

    /**
     * Runs a read with the settings it needs, and puts them back afterwards.
     * Database errors do not print into the response, and the connection speaks utf8mb4,
     * otherwise 4-byte characters would be lost.
     *
     * @param callable $callback Receives $wpdb
     * @return mixed
     */
    private function read(callable $callback)
    {
        $wpdb = $this->wpdb();
        $suppressErrors = $wpdb->suppress_errors(true);
        $charsetChanged = false;

        try {
            if ($wpdb->charset !== 'utf8mb4' && $wpdb->has_cap('utf8mb4')) {
                $wpdb->set_charset($wpdb->dbh, 'utf8mb4', '');
                $charsetChanged = true;
            }

            return $callback($wpdb);
        } finally {
            if ($charsetChanged) {
                $wpdb->set_charset($wpdb->dbh);
            }
            $wpdb->suppress_errors($suppressErrors);
        }
    }

    /**
     * Throws when the last query failed, because $wpdb answers it with no rows.
     *
     * @param \wpdb $wpdb
     * @throws \RuntimeException
     */
    private function assertNoError(object $wpdb): void
    {
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException(esc_html('Database read failed: ' . $wpdb->last_error));
        }
    }
}
