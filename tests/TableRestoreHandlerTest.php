<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\BinarySafeEncoder;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Restore\TableRestoreHandler;
use WordpressMigrator\Services\Migration\MigrationRegistry;
use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Tests\Support\FakeDatabase;
use WordpressMigrator\Tests\Support\RecordingPdo;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class TableRestoreHandlerTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private RecordingPdo $pdo;

    /** @var MigrationRegistry&MockObject */

    private MockObject $registry;

    private TableRestoreHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = new RecordingPdo();
        $this->registry = $this->createMock(MigrationRegistry::class);
        $this->handler = new TableRestoreHandler(FakeDatabase::install($this->pdo), $this->registry);
        $this->useDestinationPrefix('wp_');
    }

    public function testStructureIsDroppedAndCreatedAsInnoDb(): void
    {
        $this->registry->expects($this->once())->method('markChunkRestored')->with('table_structure', $this->anything());

        $result = $this->handler->restoreStructure($this->structureChunk(
            'wp_posts',
            'CREATE TABLE `wp_posts` (`ID` bigint) ENGINE=MyISAM ROW_FORMAT=FIXED',
            'wp_'
        ));

        $this->assertSame(['action' => 'restored_table_structure', 'table' => 'wp_posts'], $result);
        $this->assertSame([
            "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'",
            'SET FOREIGN_KEY_CHECKS = 0',
            'DROP TABLE IF EXISTS `wp_posts`',
            'CREATE TABLE `wp_posts` (`ID` bigint) ENGINE=InnoDB',
        ], $this->pdo->statements);
    }

    public function testStructureMovesToTheDestinationPrefix(): void
    {
        $this->useDestinationPrefix('new_');

        $result = $this->handler->restoreStructure($this->structureChunk(
            'old_posts',
            'CREATE TABLE `old_posts` (`ID` bigint, KEY `old_posts_idx` (`ID`)) ENGINE=InnoDB',
            'old_'
        ));

        $this->assertSame('new_posts', $result['table']);
        $this->assertContains('DROP TABLE IF EXISTS `new_posts`', $this->pdo->statements);
        $this->assertContains('CREATE TABLE `new_posts` (`ID` bigint, KEY `new_posts_idx` (`ID`)) ENGINE=InnoDB', $this->pdo->statements);
    }

    public function testTableWithoutTheSourcePrefixKeepsItsName(): void
    {
        $this->useDestinationPrefix('new_');

        $result = $this->handler->restoreStructure($this->structureChunk('custom_log', 'CREATE TABLE `custom_log` (`id` int)', 'old_'));

        $this->assertSame('custom_log', $result['table']);
    }

    public function testCamelCaseSourcePrefixIsAccepted(): void
    {
        $this->useDestinationPrefix('new_');
        $chunk = $this->structureChunk('old_posts', 'CREATE TABLE `old_posts` (`ID` bigint)', 'ignored');
        unset($chunk['source_table_prefix']);
        $chunk['sourceTablePrefix'] = 'old_';

        $this->assertSame('new_posts', $this->handler->restoreStructure($chunk)['table']);
    }

    public function testNoSourcePrefixKeepsTheTableName(): void
    {
        $this->useDestinationPrefix('new_');
        $chunk = $this->structureChunk('old_posts', 'CREATE TABLE `old_posts` (`ID` bigint)', '');

        $this->assertSame('old_posts', $this->handler->restoreStructure($chunk)['table']);
    }

    public function testEmptyDestinationPrefixIsKept(): void
    {
        $this->useDestinationPrefix('');

        $result = $this->handler->restoreStructure($this->structureChunk('old_posts', 'CREATE TABLE `old_posts` (`ID` bigint)', 'old_'));

        $this->assertSame('posts', $result['table']);
        $this->assertContains('DROP TABLE IF EXISTS `posts`', $this->pdo->statements);
        $this->assertContains('CREATE TABLE `posts` (`ID` bigint)', $this->pdo->statements);
    }

    public function testRowsUseAnEmptyDestinationPrefix(): void
    {
        $this->useDestinationPrefix('');
        $this->pdo->sqlite('CREATE TABLE posts (ID INTEGER, post_title TEXT)');

        $result = $this->handler->restoreData($this->dataChunk('old_posts', ['ID', 'post_title'], [[1, 'Hello']], 'old_'));

        $this->assertSame('posts', $result['table']);
        $this->assertEquals([[1, 'Hello']], $this->pdo->query('SELECT * FROM posts')->fetchAll(\PDO::FETCH_NUM));
    }

    public function testDatabaseErrorNamesTheTable(): void
    {
        $this->pdo->failOn = 'DROP TABLE';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to restore table structure for wp_posts: Simulated failure for: DROP TABLE');

        $this->handler->restoreStructure($this->structureChunk('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'wp_'));
    }

    public function testRowsAreReplacedInto(): void
    {
        $this->pdo->sqlite('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)');
        $this->pdo->sqlite("INSERT INTO wp_options VALUES (1, 'siteurl', 'https://stale.example.com')");
        $this->registry->expects($this->once())->method('markChunkRestored')->with('table_data', $this->anything());

        $result = $this->handler->restoreData($this->dataChunk('wp_options', ['option_id', 'option_name', 'option_value'], [
            [1, 'siteurl', 'https://new.example.com'],
            [2, 'blogname', 'My site'],
        ]));

        $this->assertSame(['action' => 'restored_table_data', 'table' => 'wp_options', 'chunk' => 1], $result);
        $this->assertEquals(
            [[1, 'siteurl', 'https://new.example.com'], [2, 'blogname', 'My site']],
            $this->pdo->query('SELECT * FROM wp_options ORDER BY option_id')->fetchAll(\PDO::FETCH_NUM)
        );
        $this->assertContains(
            'REPLACE INTO `wp_options` (`option_id`, `option_name`, `option_value`) VALUES (?,?,?)',
            $this->pdo->statements
        );
    }

    public function testRowsGoToTheTableUnderTheDestinationPrefix(): void
    {
        $this->useDestinationPrefix('new_');
        $this->pdo->sqlite('CREATE TABLE new_posts (ID INTEGER, post_title TEXT)');

        $result = $this->handler->restoreData($this->dataChunk('old_posts', ['ID', 'post_title'], [[1, 'Hello']], 'old_'));

        $this->assertSame('new_posts', $result['table']);
        $this->assertEquals([[1, 'Hello']], $this->pdo->query('SELECT * FROM new_posts')->fetchAll(\PDO::FETCH_NUM));
    }

    public function testBinaryValuesAreDecodedBeforeTheInsert(): void
    {
        $this->pdo->sqlite('CREATE TABLE wp_meta (id INTEGER, value BLOB)');
        $binary = "\xff\xd8\xff\xe0binary";
        $encoded = BinarySafeEncoder::encode([[1, $binary]]);

        $this->handler->restoreData($this->dataChunk('wp_meta', ['id', 'value'], $encoded['data'], 'wp_', $encoded['binary_map']));

        $this->assertSame($binary, $this->pdo->query('SELECT value FROM wp_meta')->fetchColumn());
    }

    public function testChecksumMismatchStopsTheInsert(): void
    {
        $this->pdo->sqlite('CREATE TABLE wp_posts (ID INTEGER)');
        $chunk = $this->dataChunk('wp_posts', ['ID'], [[1]]);
        $chunk['data'] = [[2]];
        $this->registry->expects($this->never())->method('markChunkRestored');

        try {
            $this->handler->restoreData($chunk);
            $this->fail('Expected a checksum mismatch');
        } catch (Exception $e) {
            $this->assertSame('Checksum mismatch for table data', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM wp_posts')->fetchColumn());
    }

    public function testChecksumFromTheSourceMatchesForDecimalsAndText(): void
    {
        $this->pdo->sqlite('CREATE TABLE wp_prices (id INTEGER, price REAL, label TEXT)');
        $rows = [[1, 0.1 + 0.2, 'Zażółć'], [2, 1e-7, null], [3, 19.99, '"quoted" / slashed']];
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            $checksum = (new \ReflectionClass(DatabaseScanner::class))->newInstanceWithoutConstructor()->getChecksum($rows);
        } finally {
            ini_set('serialize_precision', $precision);
        }

        $chunk = $this->dataChunk('wp_prices', ['id', 'price', 'label'], $rows);
        $chunk['checksum'] = $checksum;

        $this->assertSame('restored_table_data', $this->handler->restoreData($chunk)['action']);
        $this->assertSame($precision, ini_get('serialize_precision'));
    }

    public function testAChunkThatFailsHalfwayLeavesNoRows(): void
    {
        $this->pdo->sqlite('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT NOT NULL)');
        $this->registry->expects($this->never())->method('markChunkRestored');

        try {
            $this->handler->restoreData($this->dataChunk('wp_options', ['option_id', 'option_name'], [[1, 'siteurl'], [2, null]]));
            $this->fail('A row that cannot be inserted must fail the chunk');
        } catch (Exception $e) {
            $this->assertStringContainsString('Failed to restore table data for wp_options', $e->getMessage());
        }

        $this->assertSame([], $this->pdo->query('SELECT * FROM wp_options')->fetchAll(\PDO::FETCH_NUM));
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testInsertErrorNamesTheTable(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to restore table data for wp_missing: ');

        $this->handler->restoreData($this->dataChunk('wp_missing', ['ID'], [[1]]));
    }

    private function useDestinationPrefix(string $prefix): void
    {
        $abspath = $this->createTemporaryDirectory();
        file_put_contents($abspath . 'wp-config.php', "<?php\ndefine('DB_NAME', 'db');\ndefine('DB_USER', 'user');\ndefine('DB_HOST', 'db.internal');\n\$table_prefix = '{$prefix}';\n");
        Config::set('root_path', $abspath);
        $this->handler = new TableRestoreHandler(FakeDatabase::install($this->pdo), $this->registry);
    }

    private function structureChunk(string $table, string $createStatement, string $sourcePrefix): array
    {
        return [
            'type' => 'table_structure',
            'table' => ['name' => $table, 'create_statement' => $createStatement, 'indexes' => []],
            'source_table_prefix' => $sourcePrefix,
        ];
    }

    private function dataChunk(string $table, array $columns, array $rows, string $sourcePrefix = 'wp_', array $binaryMap = []): array
    {
        $scanner = (new \ReflectionClass(DatabaseScanner::class))->newInstanceWithoutConstructor();

        $chunk = [
            'type' => 'table_data',
            'table' => ['name' => $table, 'chunk_info' => ['chunk_number' => 1, 'total_chunks' => 1]],
            'columns' => $columns,
            'data' => $rows,
            'checksum' => $scanner->getChecksum($rows),
            'source_table_prefix' => $sourcePrefix,
        ];

        if ($binaryMap !== []) {
            $chunk['binary_map'] = $binaryMap;
        }

        return $chunk;
    }
}
