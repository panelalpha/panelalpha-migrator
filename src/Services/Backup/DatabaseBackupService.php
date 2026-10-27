<?php

declare(strict_types=1);

namespace WordpressMigrator\Services\Backup;

use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Helpers\BinarySafeEncoder;
use WordpressMigrator\Helpers\DateTime;
use WordpressMigrator\Helpers\DestinationGuard;
use WordpressMigrator\Helpers\Http;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Helpers\TokenHelper;

/**
 * Class DatabaseBackupService
 *
 * @package WordpressMigrator\Services\Backup
 *
 * Service for handling database backup operations.
 */
class DatabaseBackupService
{
    use DefersMigrationSaves;

    use ProgressCalculator;

    private DatabaseScanner $dbScanner;
    private MigrationStorageService $storageService;
    private ChunkHttpSender $httpSender;
    private ChunkDomainReplacer $domainReplacer;
    private string $migrationId;
    private array $migrationData;

    /**
     * DatabaseBackupService constructor.
     *
     * @param string $migrationId Migration identifier
     * @param DatabaseScanner|null $dbScanner Database scanner instance
     * @param MigrationStorageService|null $storageService Storage service instance
     * @throws \Exception
     */
    public function __construct(
        string $migrationId,
        ?DatabaseScanner $dbScanner = null,
        ?MigrationStorageService $storageService = null
    ) {
        $this->migrationId = $migrationId;
        $this->dbScanner = $dbScanner ?? new DatabaseScanner();
        $this->storageService = $storageService ?? new MigrationStorageService();

        $this->loadMigrationData();
        $this->initializeDependencies();
    }

    /**
     * Loads migration data from storage.
     *
     * @throws \Exception
     */
    private function loadMigrationData(): void
    {
        if (!$this->storageService->migrationExists($this->migrationId)) {
            throw new \Exception('Migration file not found');
        }

        $this->migrationData = $this->storageService->loadMigrationData($this->migrationId);
    }

    /**
     * Initializes dependencies that require migration data.
     */
    private function initializeDependencies(): void
    {
        $destinationUrl = $this->getDestinationUrl();
        $authToken = $this->getAuthToken();
        $sourceUrl = $this->getSourceUrl();
        $ip = $this->migrationData['pinned_ip'] ?? null;
        $base64 = $this->migrationData['base64'] ?? false;

        $this->httpSender = new ChunkHttpSender($destinationUrl, $authToken, $ip, $base64, Http::tlsArgs($this->migrationData));
        $this->domainReplacer = new ChunkDomainReplacer($sourceUrl, $destinationUrl);
    }

    /**
     * Gets the next database chunk to be sent.
     *
     * @return array|null Chunk data or null if all chunks sent
     */
    public function getNextChunk(): ?array
    {
        foreach ($this->migrationData['tables'] as $tableIndex => &$table) {
            if (!$table['structureSent']) {
                return $this->getTableStructureChunk($tableIndex, $table);
            }

            foreach ($table['chunks'] as $chunkIndex => &$chunk) {
                if ($chunk['status'] === 'pending') {
                    return $this->getTableDataChunk($tableIndex, $chunkIndex, $table, $chunk);
                }
            }
        }

        return null;
    }

    /**
     * Gets a table structure chunk.
     *
     * @param int   $tableIndex Table index in migration data
     * @param array $table Table data array
     * @return array Chunk response
     */
    private function getTableStructureChunk(int $tableIndex, array &$table): array
    {
        $structure = $this->fetchTableStructure($table['name']);
        $response = $this->buildTableStructureResponse($structure);

        $this->assertDestinationAllowed();
        $this->httpSender->send($response);
        $this->markStructureAsSent($tableIndex);

        return $response;
    }

    /**
     * Fetches the structure of a table.
     *
     * @param string $tableName Table name
     * @return \WordpressMigrator\Dto\TableStructure Table structure object
     * @throws \Exception If structure cannot be fetched
     */
    private function fetchTableStructure(string $tableName)
    {
        $structure = $this->dbScanner->getTableStructure($tableName);

        if (!$structure) {
            throw new \Exception('Cannot get table structure for ' . $tableName);
        }

        return $structure;
    }

    /**
     * Builds the response for a table structure chunk.
     *
     * @param mixed $structure Table structure object
     * @return array Structure chunk response
     */
    private function buildTableStructureResponse($structure): array
    {
        return [
            'type' => 'table_structure',
            'migration_id' => $this->migrationData['migrationId'],
            'table' => [
                'name' => $structure->name,
                'create_statement' => $structure->createStatement,
                'indexes' => $this->formatIndexes($structure->indexes)
            ],
            'source_table_prefix' => $this->getSourceTablePrefix(),
            'timestamp' => DateTime::currentTime('c')
        ];
    }

    /**
     * Formats table indexes for the response.
     *
     * @param array $indexes Array of index objects
     * @return array Formatted indexes
     */
    private function formatIndexes(array $indexes): array
    {
        return array_map(function($index) {
            return [
                'name' => $index->name,
                'columns' => $index->columns,
                'type' => $index->type
            ];
        }, $indexes);
    }

    /**
     * Marks a table's structure as sent.
     *
     * @param int $tableIndex Table index
     */
    private function markStructureAsSent(int $tableIndex): void
    {
        $this->migrationData['tables'][$tableIndex]['structureSent'] = true;
        $this->saveMigrationData();
    }

    /**
     * Gets a table data chunk.
     *
     * @param int   $tableIndex Table index in migration data
     * @param int   $chunkIndex Chunk index in table
     * @param array $table Table data array
     * @param array $chunk Chunk data array
     * @return array Chunk response
     */
    private function getTableDataChunk(int $tableIndex, int $chunkIndex, array &$table, array &$chunk): array
    {
        $tableChunk = $this->fetchTableChunk($table['name'], $chunk['offset'], $chunk['limit']);
        $chunkNumber = $chunkIndex + 1;
        $response = $this->buildTableDataResponse($table, $tableChunk, $chunkNumber, $chunk);

        $this->assertDestinationAllowed();
        $this->httpSender->send($response);
        $this->markChunkAsSent($tableIndex, $chunkIndex);
        $this->updateTableStatus($tableIndex);

        return $response;
    }

    /**
     * Fetches a chunk of data from a table.
     *
     * @param string $tableName Table name
     * @param int    $offset Row offset
     * @param int    $limit Row limit
     * @return \WordpressMigrator\Dto\TableChunk Table chunk object
     * @throws \Exception If chunk cannot be fetched
     */
    private function fetchTableChunk(string $tableName, int $offset, int $limit)
    {
        $tableChunk = $this->dbScanner->getTableChunk($tableName, $offset, $limit);

        if (!$tableChunk) {
            throw new \Exception('Cannot get table data for ' . $tableName);
        }

        return $tableChunk;
    }

    /**
     * Builds the response for a table data chunk.
     *
     * @param array $table Table data array
     * @param mixed $tableChunk Table chunk object from scanner
     * @param int   $chunkNumber Sequential chunk number
     * @param array $chunk Chunk metadata array
     * @return array Complete chunk response
     */
    private function buildTableDataResponse(array &$table, $tableChunk, int $chunkNumber, array &$chunk): array
    {
        $rows = $this->withLiveSiteAddress($table['name'], $tableChunk->columns, $tableChunk->data);
        $processedData = $this->domainReplacer->replaceInData($rows);

        $encoded = BinarySafeEncoder::encode($processedData);
        $safeData = $encoded['data'];
        $binaryMap = $encoded['binary_map'];

        $response = [
            'type' => 'table_data',
            'migration_id' => $this->migrationData['migrationId'],
            'chunk_id' => $this->generateChunkId($table['name'], $chunkNumber),
            'table' => [
                'name' => $table['name'],
                'chunk_info' => $this->buildChunkInfo($chunkNumber, $table, $chunk, $tableChunk)
            ],
            'columns' => $tableChunk->columns,
            'data' => $safeData,
            'checksum' => $this->dbScanner->getChecksum($safeData),
            'source_table_prefix' => $this->getSourceTablePrefix(),
            'timestamp' => DateTime::currentTime('c')
        ];

        if (!empty($binaryMap)) {
            $response['binary_map'] = $binaryMap;
        }

        return $response;
    }

    /**
     * Generates a unique ID for a chunk.
     *
     * @param string $tableName Table the chunk belongs to
     * @param int $chunkNumber Sequential chunk number
     * @return string Formatted chunk ID
     */
    private function generateChunkId(string $tableName, int $chunkNumber): string
    {
        $migration = preg_replace('/[^A-Za-z0-9_]/', '_', $this->migrationId);
        $table = preg_replace('/[^A-Za-z0-9_]/', '_', $tableName);
        $table = trim((string) $table, '_');

        return trim((string) $migration, '_')
            . '_' . ($table === '' ? 'unknown' : $table)
            . '_' . str_pad((string) $chunkNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Builds the chunk information array for the response.
     *
     * @param int   $chunkNumber Sequential chunk number
     * @param array $table Table data array
     * @param array $chunk Chunk metadata array
     * @param mixed $tableChunk Table chunk object
     * @return array Chunk information
     */
    private function buildChunkInfo(int $chunkNumber, array &$table, array &$chunk, $tableChunk): array
    {
        return [
            'chunk_number' => $chunkNumber,
            'total_chunks' => $table['totalChunks'],
            'offset' => $chunk['offset'],
            'limit' => $chunk['limit'],
            'actual_rows' => $tableChunk->actualRows
        ];
    }

    /**
     * Marks a chunk as sent.
     *
     * @param int $tableIndex Table index
     * @param int $chunkIndex Chunk index
     */
    private function markChunkAsSent(int $tableIndex, int $chunkIndex): void
    {
        $this->migrationData['tables'][$tableIndex]['chunks'][$chunkIndex]['status'] = 'sent';
    }

    /**
     * Updates the status of a table.
     *
     * @param int $tableIndex Table index
     */
    private function updateTableStatus(int $tableIndex): void
    {
        $chunks = $this->migrationData['tables'][$tableIndex]['chunks'];
        $allChunksSent = $this->areAllChunksSent($chunks);

        if ($allChunksSent) {
            $this->migrationData['tables'][$tableIndex]['status'] = 'sent';
        } else {
            $this->migrationData['tables'][$tableIndex]['status'] = 'in_progress';
        }

        $this->saveMigrationData();
    }

    /**
     * Gets the database backup progress.
     *
     * @param string|null $currentTableName Optional current table name being processed
     * @return array Progress information
     */
    public function getProgress(?string $currentTableName = null): array
    {
        $stats = $this->calculateProgressStats();
        $percentage = $this->calculatePercentage($stats['total_chunks'], $stats['sent_chunks']);

        return [
            'total_chunks' => $stats['total_chunks'],
            'sent_chunks' => $stats['sent_chunks'],
            'total_tables' => $stats['total_tables'],
            'completed_tables' => $stats['completed_tables'],
            'percentage' => $percentage,
            'is_complete' => $stats['sent_chunks'] === $stats['total_chunks'],
            'current_table' => $currentTableName ?? $stats['current_table'],
            'total_size' => $stats['total_size'],
            'sent_size' => $stats['sent_size']
        ];
    }

    /**
     * Calculates progress statistics.
     *
     * @return array Statistics array with progress information
     */
    private function calculateProgressStats(): array
    {
        $totalChunks = 0;
        $sentChunks = 0;
        $totalTables = count($this->migrationData['tables']);
        $completedTables = 0;
        $currentTable = null;
        $totalSize = 0;
        $sentSize = 0;

        foreach ($this->migrationData['tables'] as $table) {
            $totalChunks += $table['totalChunks'];
            $totalSize += $table['size'];

            if ($table['status'] === 'sent') {
                $completedTables++;
                $sentSize += $table['size'];
            } elseif ($table['status'] === 'in_progress') {
                $sentSize += $this->calculateSentSize($table['chunks']);

                if ($currentTable === null) {
                    $currentTable = $table['name'];
                }
            } elseif ($table['status'] === 'pending' && $currentTable === null) {
                $currentTable = $table['name'];
            }

            $sentChunks += $this->countSentChunks($table['chunks']);
        }

        return [
            'total_chunks' => $totalChunks,
            'sent_chunks' => $sentChunks,
            'total_tables' => $totalTables,
            'completed_tables' => $completedTables,
            'current_table' => $currentTable,
            'total_size' => $totalSize,
            'sent_size' => $sentSize
        ];
    }

    /**
     * Refuses a stored destination that points at a private or loopback address.
     * Runs once for a migration without a pinned IP, then keeps the approved IP.
     */
    private function assertDestinationAllowed(): void
    {
        $pinned = $this->migrationData['pinned_ip'] ?? null;
        if (is_string($pinned) && $pinned !== '') {
            return;
        }

        $ip = $this->migrationData['ip'] ?? null;
        $pinned = (new DestinationGuard())->assertAllowed(
            $this->getDestinationUrl(),
            is_string($ip) && $ip !== '' ? $ip : null
        );

        if ($pinned === null) {
            return;
        }

        $this->migrationData['pinned_ip'] = $pinned;
        $this->saveMigrationData();
        $this->httpSender->setIp($pinned);
    }

    /**
     * Gets the destination URL from migration data.
     *
     * @return string Destination URL
     * @throws \Exception If destination URL not found
     */
    private function getDestinationUrl(): string
    {
        $destinationUrl = $this->migrationData['destinationUrl']
            ?? $this->migrationData['destination_url']
            ?? $this->migrationData['du']
            ?? null;

        if (empty($destinationUrl)) {
            error_log("ERROR: Destination URL not found in migration data. Keys: " . json_encode(array_keys($this->migrationData)));
            throw new \Exception('Destination URL not found in migration data');
        }

        return $destinationUrl;
    }

    /**
     * Gets the source URL from migration data.
     *
     * @return string Source URL
     */
    private function getSourceUrl(): string
    {
        return $this->migrationData['siteUrl']
            ?? $this->migrationData['site_url']
            ?? $this->migrationData['su']
            ?? '';
    }

    /**
     * Puts the site address WordPress uses into the siteurl and home rows.
     * A host can set the address in wp-config.php and keep an old one in the
     * database (e.g. Bitnami keeps http://127.0.0.1), which would then reach
     * the destination untouched by the domain replacement.
     *
     * @param string $tableName Table name
     * @param array  $columns Column names
     * @param array  $rows Rows with numeric keys
     * @return array Rows
     */
    private function withLiveSiteAddress(string $tableName, array $columns, array $rows): array
    {
        $nameColumn = array_search('option_name', $columns, true);
        $valueColumn = array_search('option_value', $columns, true);

        if ($tableName !== $this->getSourceTablePrefix() . 'options' || $nameColumn === false || $valueColumn === false || !function_exists('get_option')) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            $option = $row[$nameColumn] ?? null;
            if ($option !== 'siteurl' && $option !== 'home') {
                continue;
            }

            $address = get_option($option);
            if (is_string($address) && $address !== '') {
                $rows[$index][$valueColumn] = $address;
            }
        }

        return $rows;
    }

    /**
     * Gets the source table prefix from migration data.
     *
     * @return string Source table prefix
     */
    private function getSourceTablePrefix(): string
    {
        return $this->migrationData['sourceTablePrefix']
            ?? $this->migrationData['source_table_prefix']
            ?? $this->migrationData['stp']
            ?? 'wp_';
    }

    /**
     * Gets the authentication token.
     *
     * @return string Authentication token
     */
    private function getAuthToken(): string
    {
        return TokenHelper::forMigration($this->migrationData);
    }
}
