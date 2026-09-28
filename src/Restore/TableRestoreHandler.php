<?php

namespace WordpressMigrator\Restore;

use WordpressMigrator\Helpers\BinarySafeEncoder;
use WordpressMigrator\Helpers\CreateStatementNormalizer;
use WordpressMigrator\Helpers\Database;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Services\Migration\MigrationRegistry;

/**
 * Class TableRestoreHandler
 *
 * @package WordpressMigrator\Restore
 *
 * Handles the restoration of database tables.
 */
class TableRestoreHandler
{
    private Database $db;
    private MigrationRegistry $registry;
    private ?string $destinationTablePrefix = null;

    /**
     * TableRestoreHandler constructor.
     *
     * @param Database          $db
     * @param MigrationRegistry $registry
     */
    public function __construct(Database $db, MigrationRegistry $registry)
    {
        $this->db = $db;
        $this->registry = $registry;
    }

    /**
     * Restores the structure of a table.
     *
     * @param array $chunkData
     * @return array
     * @throws \Exception
     */
    public function restoreStructure(array $chunkData): array
    {
        $sourcePrefix = $chunkData['source_table_prefix']
            ?? $chunkData['sourceTablePrefix']
            ?? '';
        $remapped = $this->remapTableName(
            $chunkData['table']['name'],
            $chunkData['table']['create_statement'],
            $sourcePrefix
        );
        $tableName = $remapped['name'];
        $createStatement = $remapped['create_statement'];

        try {
            $pdo = $this->db->getPdo();

            $pdo->exec("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $createStatement = CreateStatementNormalizer::normalize($createStatement);

            $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
            $pdo->exec($createStatement);

            $this->registry->markChunkRestored('table_structure', $chunkData);

            return [
                'action' => 'restored_table_structure',
                'table' => $tableName
            ];

        } catch (\PDOException $e) {
            throw new \Exception("Failed to restore table structure for {$tableName}: " . $e->getMessage());
        }
    }

    /**
     * Restores data for a table.
     *
     * @param array $chunkData
     * @return array
     * @throws \Exception
     */
    public function restoreData(array $chunkData): array
    {
        $sourcePrefix = $chunkData['source_table_prefix']
            ?? $chunkData['sourceTablePrefix']
            ?? '';
        $remapped = $this->remapTableName(
            $chunkData['table']['name'],
            $chunkData['table']['create_statement'] ?? '',
            $sourcePrefix
        );
        $tableName = $remapped['name'];
        $columns = $chunkData['columns'];
        $data = $chunkData['data'];
        $checksum = $chunkData['checksum'];

        $this->validateChecksum($data, $checksum);

        if (!empty($chunkData['binary_map'])) {
            $data = BinarySafeEncoder::decode($data, $chunkData['binary_map']);
        }

        try {
            $this->insertRows($tableName, $columns, $data);
            $this->registry->markChunkRestored('table_data', $chunkData);

            return [
                'action' => 'restored_table_data',
                'table' => $tableName,
                'chunk' => $chunkData['table']['chunk_info']['chunk_number']
            ];

        } catch (\PDOException $e) {
            throw new \Exception("Failed to restore table data for {$tableName}: " . $e->getMessage());
        }
    }

    /**
     * Remaps a source table name (and CREATE TABLE statement) to the destination prefix.
     *
     * @param string $tableName
     * @param string $createStatement
     * @param string $sourcePrefix
     * @return array{name: string, create_statement: string}
     */
    private function remapTableName(string $tableName, string $createStatement, string $sourcePrefix): array
    {
        if ($sourcePrefix === '') {
            return [
                'name' => $tableName,
                'create_statement' => $createStatement,
            ];
        }

        $destinationPrefix = $this->getDestinationTablePrefix();
        if ($sourcePrefix === $destinationPrefix) {
            return [
                'name' => $tableName,
                'create_statement' => $createStatement,
            ];
        }

        $remappedName = $tableName;
        if (str_starts_with($tableName, $sourcePrefix)) {
            $remappedName = $destinationPrefix . substr($tableName, strlen($sourcePrefix));
        }

        $remappedCreate = $createStatement;
        if ($createStatement !== '') {
            $remappedCreate = str_replace(
                ['`' . $tableName . '`', $tableName],
                ['`' . $remappedName . '`', $remappedName],
                $createStatement
            );
        }

        return [
            'name' => $remappedName,
            'create_statement' => $remappedCreate,
        ];
    }

    /**
     * Lazily detects the table prefix used on the destination WordPress instance.
     *
     * @return string
     */
    private function getDestinationTablePrefix(): string
    {
        if ($this->destinationTablePrefix !== null) {
            return $this->destinationTablePrefix;
        }

        try {
            $parser = new WpConfigParser(PathProvider::getAbsPath());
            $config = $parser->parse();
            $prefix = $config['table_prefix'] ?? null;
            // An explicit empty prefix is valid. Fall back to wp_ only when it is missing.
            $this->destinationTablePrefix = is_string($prefix) ? $prefix : 'wp_';
        } catch (\Throwable $e) {
            $this->destinationTablePrefix = 'wp_';
        }

        return $this->destinationTablePrefix;
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
     * Inserts rows into a table.
     *
     * @param string $tableName
     * @param array  $columns
     * @param array  $data
     */
    private function insertRows(string $tableName, array $columns, array $data): void
    {
        $pdo = $this->db->getPdo();

        $pdo->exec("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        $placeholders = rtrim(str_repeat('?,', count($columns)), ',');
        $columnsList = '`' . implode('`, `', $columns) . '`';
        $sql = "REPLACE INTO `{$tableName}` ({$columnsList}) VALUES ({$placeholders})";

        $stmt = $pdo->prepare($sql);

        foreach ($data as $row) {
            $stmt->execute($row);
        }
    }
}
