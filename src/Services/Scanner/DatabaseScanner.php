<?php

declare(strict_types=1);

namespace WordpressMigrator\Services\Scanner;

use WordpressMigrator\Dto\ChunkInfo;
use WordpressMigrator\Dto\IndexInfo;
use WordpressMigrator\Dto\TableChunk;
use WordpressMigrator\Dto\TableInfo;
use WordpressMigrator\Dto\TableStructure;
use WordpressMigrator\Helpers\Database;

/**
 * Class DatabaseScanner
 *
 * @package WordpressMigrator\Services\Scanner
 *
 * Scans the database to gather information about tables.
 */
class DatabaseScanner
{
    private const ROWS_PER_CHUNK = 100;

    private Database $db;

    /**
     * DatabaseScanner constructor.
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
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
     * Gets all tables from the database.
     *
     * @return array Array of table names
     */
    private function getAllTables(): array
    {
        $stmt = $this->db->getPdo()->query('SHOW TABLES');
        $tables = $stmt->fetchAll(\PDO::FETCH_NUM);
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
        $sizeQuery = "SELECT
                data_length + index_length as size
            FROM information_schema.TABLES
            WHERE table_schema = ?
            AND table_name = ?";

        $sizeResult = $this->db->getRow($sizeQuery, [DB_NAME, $tableName]);

        return $sizeResult ? (int)$sizeResult->size : 0;
    }

    /**
     * Gets the row count of a table.
     *
     * @param string $tableName Table name
     * @return int Number of rows
     */
    private function getTableRowCount(string $tableName): int
    {
        $countQuery = "SELECT COUNT(*) as count FROM `{$tableName}`";
        $countResult = $this->db->getRow($countQuery);

        return $countResult ? (int)$countResult->count : 0;
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
        $stmt = $this->db->getPdo()->query("SHOW CREATE TABLE `{$tableName}`");
        $createResult = $stmt->fetch(\PDO::FETCH_NUM);

        if (!$createResult) {
            return null;
        }

        return $createResult[1];
    }

    /**
     * Gets the indexes of a table.
     *
     * @param string $tableName Table name
     * @return array Array of IndexInfo objects
     */
    private function getTableIndexes(string $tableName): array
    {
        $indexesQuery = "SHOW INDEXES FROM `{$tableName}`";
        $indexesResult = $this->db->getResults($indexesQuery);

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
        $columnsQuery = "SHOW COLUMNS FROM `{$tableName}`";
        $columnsResult = $this->db->getResults($columnsQuery);

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
        $dataQuery = "SELECT * FROM `{$tableName}` LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->getPdo()->query($dataQuery);
        $dataResult = $stmt->fetchAll(\PDO::FETCH_NUM);

        return $dataResult;
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
        $sizeQuery = "SELECT SUM(data_length + index_length) as size
            FROM information_schema.TABLES
            WHERE table_schema = ?";

        $result = $this->db->getRow($sizeQuery, [DB_NAME]);

        return $result ? (int)$result->size : 0;
    }
}
