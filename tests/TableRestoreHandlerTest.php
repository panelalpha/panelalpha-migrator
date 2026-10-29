<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\BinarySafeEncoder;
use PanelAlpha\Migrator\Restore\MigrationConflictException;
use PanelAlpha\Migrator\Restore\TableRestoreHandler;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;
use PanelAlpha\Migrator\Services\Scanner\DatabaseScanner;
use PanelAlpha\Migrator\Tests\Support\RecordingWpdb;

final class TableRestoreHandlerTest extends TestCase
{
    private RecordingWpdb $wpdb;

    /** @var MigrationRegistry&MockObject */
    private MockObject $registry;

    private TableRestoreHandler $handler;

    /** @var array<string, array{destination: string, temp: string, backup: string}> */
    private array $map = [];

    protected function setUp(): void
    {
        $this->wpdb = new RecordingWpdb();
        $this->registry = $this->createMock(MigrationRegistry::class);
        $this->registry->method('getTableMap')->willReturnCallback(function (): array {
            return $this->map;
        });
        $this->handler = new TableRestoreHandler($this->wpdb, $this->registry);
        $this->mapTable('wp_posts');
    }

    public function testStructureIsDroppedAndCreatedAsInnoDbUnderTheTemporaryName(): void
    {
        $this->registry->expects($this->once())->method('markChunkRestored')->with('table_structure', $this->anything());

        $result = $this->handler->restoreStructure($this->structureChunk(
            'wp_posts',
            'CREATE TABLE `wp_posts` (`ID` bigint) ENGINE=MyISAM ROW_FORMAT=FIXED'
        ));

        $this->assertSame(['action' => 'restored_table_structure', 'table' => 'wp_posts'], $result);
        $this->assertSame([
            'SELECT @@SESSION.sql_mode',
            'SELECT @@SESSION.foreign_key_checks',
            "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'",
            'SET SESSION foreign_key_checks = 0',
            'DROP TABLE IF EXISTS `pmt_ab12cd_wp_posts`',
            'CREATE TABLE `pmt_ab12cd_wp_posts` (`ID` bigint) ENGINE=InnoDB',
            "SET SESSION sql_mode = 'STRICT_TRANS_TABLES'",
            'SET SESSION foreign_key_checks = 1',
        ], $this->wpdb->queries);
    }

    public function testSessionSettingsAreRestoredWhenTheStructureFails(): void
    {
        $this->wpdb->failOn = 'CREATE TABLE';
        $this->registry->expects($this->never())->method('markChunkRestored');

        try {
            $this->handler->restoreStructure($this->structureChunk('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)'));
            $this->fail('Expected the failed CREATE to stop the chunk');
        } catch (Exception $e) {
            $this->assertSame('Failed to restore table structure for wp_posts: Simulated failure for: CREATE TABLE', $e->getMessage());
        }

        $this->assertContains("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'", $this->wpdb->queries);
        $this->assertContains('SET SESSION foreign_key_checks = 1', $this->wpdb->queries);
        $this->assertTrue($this->wpdb->check_current_query);
        $this->assertFalse($this->wpdb->suppress);
    }

    public function testStructureMovesToTheDestinationNameAndTheTemporaryPrefix(): void
    {
        $this->mapTable('old_posts', 'new_posts');

        $result = $this->handler->restoreStructure($this->structureChunk(
            'old_posts',
            'CREATE TABLE `old_posts` (`ID` bigint, `old_posts_id` bigint, KEY `old_posts_idx` (`ID`)) ENGINE=InnoDB'
        ));

        $this->assertSame('new_posts', $result['table']);
        $this->assertContains('DROP TABLE IF EXISTS `pmt_ab12cd_new_posts`', $this->wpdb->queries);
        $this->assertContains(
            'CREATE TABLE `pmt_ab12cd_new_posts` (`ID` bigint, `old_posts_id` bigint, KEY `old_posts_idx` (`ID`)) ENGINE=InnoDB',
            $this->wpdb->queries
        );
    }

    public function testForeignKeysPointAtTheTemporaryTablesWithoutConstraintNames(): void
    {
        $this->mapTable('wp_orders');

        $this->handler->restoreStructure($this->structureChunk(
            'wp_orders',
            'CREATE TABLE `wp_orders` (`id` bigint, `post_id` bigint, CONSTRAINT `fk_post` FOREIGN KEY (`post_id`) REFERENCES `wp_posts` (`ID`)) ENGINE=InnoDB'
        ));

        $this->assertContains(
            'CREATE TABLE `pmt_ab12cd_wp_orders` (`id` bigint, `post_id` bigint, FOREIGN KEY (`post_id`) REFERENCES `pmt_ab12cd_wp_posts` (`ID`)) ENGINE=InnoDB',
            $this->wpdb->queries
        );
    }

    public function testUtf8DestinationSwitchesToUtf8mb4ForTheImportAndBack(): void
    {
        $this->wpdb->charset = 'utf8';

        $this->handler->restoreStructure($this->structureChunk('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)'));

        $this->assertSame(['utf8mb4/', 'default/default'], $this->wpdb->charsetCalls);
    }

    public function testUtf8mb4DestinationKeepsItsCharset(): void
    {
        $this->handler->restoreStructure($this->structureChunk('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)'));

        $this->assertSame([], $this->wpdb->charsetCalls);
    }

    public function testServerWithoutUtf8mb4KeepsItsCharset(): void
    {
        $this->wpdb->charset = 'utf8';
        $this->wpdb->utf8mb4 = false;

        $this->handler->restoreStructure($this->structureChunk('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)'));

        $this->assertSame([], $this->wpdb->charsetCalls);
    }

    public function testStructureOfATableOutsideTheMapIsAConflict(): void
    {
        $this->expectException(MigrationConflictException::class);

        $this->handler->restoreStructure($this->structureChunk('wp_unknown', 'CREATE TABLE `wp_unknown` (`ID` bigint)'));
    }

    public function testDataOfATableOutsideTheMapIsAConflict(): void
    {
        $this->map = [];

        try {
            $this->handler->restoreData($this->dataChunk('wp_posts', ['ID'], [[1]]));
            $this->fail('Rows that arrive before the info chunk must be refused');
        } catch (MigrationConflictException $e) {
            $this->assertStringContainsString('Send the info chunk first', $e->getMessage());
        }

        $this->assertSame([], $this->wpdb->queries);
    }

    public function testDatabaseErrorNamesTheTable(): void
    {
        $this->wpdb->failOn = 'DROP TABLE';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to restore table structure for wp_posts: Simulated failure for: DROP TABLE');

        $this->handler->restoreStructure($this->structureChunk('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)'));
    }

    public function testRowsAreReplacedIntoTheTemporaryTable(): void
    {
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_wp_posts (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)');
        $this->wpdb->sqlite("INSERT INTO pmt_ab12cd_wp_posts VALUES (1, 'siteurl', 'https://stale.example.com')");
        $this->registry->expects($this->once())->method('markChunkRestored')->with('table_data', $this->anything());

        $result = $this->handler->restoreData($this->dataChunk('wp_posts', ['option_id', 'option_name', 'option_value'], [
            [1, 'siteurl', 'https://new.example.com'],
            [2, 'blogname', 'My site'],
        ]));

        $this->assertSame(['action' => 'restored_table_data', 'table' => 'wp_posts', 'chunk' => 1], $result);
        $this->assertEquals(
            [[1, 'siteurl', 'https://new.example.com'], [2, 'blogname', 'My site']],
            $this->wpdb->rows('SELECT * FROM pmt_ab12cd_wp_posts ORDER BY option_id')
        );
        $this->assertContains(
            "REPLACE INTO `pmt_ab12cd_wp_posts` (`option_id`, `option_name`, `option_value`) VALUES ('1','siteurl','https://new.example.com'),('2','blogname','My site')",
            $this->wpdb->queries
        );
        $this->assertContains('START TRANSACTION', $this->wpdb->queries);
        $this->assertContains('COMMIT', $this->wpdb->queries);
    }

    public function testRowsGoToTheTemporaryTableOfTheDestinationName(): void
    {
        $this->mapTable('old_posts', 'new_posts');
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_new_posts (ID INTEGER, post_title TEXT)');

        $result = $this->handler->restoreData($this->dataChunk('old_posts', ['ID', 'post_title'], [[1, 'Hello']]));

        $this->assertSame('new_posts', $result['table']);
        $this->assertEquals([[1, 'Hello']], $this->wpdb->rows('SELECT * FROM pmt_ab12cd_new_posts'));
    }

    public function testImportQueriesSkipTheUtf8Check(): void
    {
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_wp_posts (ID INTEGER)');

        $this->handler->restoreData($this->dataChunk('wp_posts', ['ID'], [[1]]));

        foreach ($this->wpdb->queries as $index => $query) {
            if (str_starts_with($query, 'REPLACE') || $query === 'COMMIT') {
                $this->assertFalse($this->wpdb->checkFlags[$index], $query);
            }
        }
        $this->assertTrue($this->wpdb->check_current_query);
    }

    public function testNullStaysALiteralAndOtherValuesAreQuotedStrings(): void
    {
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_wp_posts (a, b, c, d, e)');

        $this->handler->restoreData($this->dataChunk('wp_posts', ['a', 'b', 'c', 'd', 'e'], [[null, 7, 0.1 + 0.2, true, "it's 100% \\ done"]]));

        $this->assertContains(
            "REPLACE INTO `pmt_ab12cd_wp_posts` (`a`, `b`, `c`, `d`, `e`) VALUES (NULL,'7','0.30000000000000004','1','it''s 100% \\ done')",
            $this->wpdb->queries
        );
        $this->assertEquals([[null, 7, 0.30000000000000004, 1, "it's 100% \\ done"]], $this->wpdb->rows('SELECT * FROM pmt_ab12cd_wp_posts'));
    }

    public function testRowsShareAStatementUntilItIsAsLongAsTheLimit(): void
    {
        $this->wpdb->variables['@@max_allowed_packet'] = (string) (65536 + 3000);
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_wp_posts (ID INTEGER, body TEXT)');
        $rows = [];
        for ($id = 1; $id <= 6; $id++) {
            $rows[] = [$id, str_repeat('x', 1000)];
        }

        $this->handler->restoreData($this->dataChunk('wp_posts', ['ID', 'body'], $rows));

        $inserts = array_values(array_filter($this->wpdb->queries, static function (string $query): bool {
            return str_starts_with($query, 'REPLACE');
        }));
        $this->assertGreaterThan(1, count($inserts));
        $this->assertLessThan(6, count($inserts));
        foreach ($inserts as $insert) {
            $this->assertLessThanOrEqual(3000, strlen($insert));
        }
        $this->assertSame(6, (int) $this->wpdb->rows('SELECT COUNT(*) FROM pmt_ab12cd_wp_posts')[0][0]);
    }

    public function testMaxAllowedPacketIsReadOncePerHandler(): void
    {
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_wp_posts (ID INTEGER)');

        $this->handler->restoreData($this->dataChunk('wp_posts', ['ID'], [[1]]));
        $this->handler->restoreData($this->dataChunk('wp_posts', ['ID'], [[2]], 2));

        $reads = array_filter($this->wpdb->queries, static function (string $query): bool {
            return $query === 'SELECT @@max_allowed_packet';
        });
        $this->assertCount(1, $reads);
    }

    public function testARowLargerThanMaxAllowedPacketFailsTheChunkWithTheLimit(): void
    {
        $this->wpdb->variables['@@max_allowed_packet'] = '2000';
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_wp_posts (ID INTEGER, body TEXT)');
        $this->registry->expects($this->never())->method('markChunkRestored');

        try {
            $this->handler->restoreData($this->dataChunk('wp_posts', ['ID', 'body'], [[1, 'short'], [2, str_repeat('x', 5000)]]));
            $this->fail('A row over max_allowed_packet must fail the chunk');
        } catch (Exception $e) {
            $this->assertStringContainsString('Failed to restore table data for wp_posts', $e->getMessage());
            $this->assertStringContainsString('max_allowed_packet (2000 bytes)', $e->getMessage());
        }

        $this->assertContains('ROLLBACK', $this->wpdb->queries);
        $this->assertSame([], $this->wpdb->rows('SELECT * FROM pmt_ab12cd_wp_posts'));
    }

    public function testBinaryValuesAreDecodedBeforeTheInsert(): void
    {
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_wp_posts (id INTEGER, value BLOB)');
        $binary = "\xff\xd8\xff\xe0binary";
        $encoded = BinarySafeEncoder::encode([[1, $binary]]);

        $this->handler->restoreData($this->dataChunk('wp_posts', ['id', 'value'], $encoded['data'], 1, $encoded['binary_map']));

        $this->assertSame($binary, $this->wpdb->rows('SELECT value FROM pmt_ab12cd_wp_posts')[0][0]);
    }

    public function testChecksumMismatchStopsTheInsert(): void
    {
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_wp_posts (ID INTEGER)');
        $chunk = $this->dataChunk('wp_posts', ['ID'], [[1]]);
        $chunk['data'] = [[2]];
        $this->registry->expects($this->never())->method('markChunkRestored');

        try {
            $this->handler->restoreData($chunk);
            $this->fail('Expected a checksum mismatch');
        } catch (Exception $e) {
            $this->assertSame('Checksum mismatch for table data', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->wpdb->rows('SELECT COUNT(*) FROM pmt_ab12cd_wp_posts')[0][0]);
    }

    public function testChecksumFromTheSourceMatchesForDecimalsAndText(): void
    {
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_wp_posts (id INTEGER, price REAL, label TEXT)');
        $rows = [[1, 0.1 + 0.2, 'Zażółć'], [2, 1e-7, null], [3, 19.99, '"quoted" / slashed']];
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            $checksum = (new \ReflectionClass(DatabaseScanner::class))->newInstanceWithoutConstructor()->getChecksum($rows);
        } finally {
            ini_set('serialize_precision', $precision);
        }

        $chunk = $this->dataChunk('wp_posts', ['id', 'price', 'label'], $rows);
        $chunk['checksum'] = $checksum;

        $this->assertSame('restored_table_data', $this->handler->restoreData($chunk)['action']);
        $this->assertSame($precision, ini_get('serialize_precision'));
        $this->assertEquals([[2, 1e-7, null]], $this->wpdb->rows('SELECT * FROM pmt_ab12cd_wp_posts WHERE id = 2'));
    }

    public function testAChunkThatFailsHalfwayLeavesNoRows(): void
    {
        $this->wpdb->variables['@@max_allowed_packet'] = (string) (65536 + 1024);
        $this->wpdb->sqlite('CREATE TABLE pmt_ab12cd_wp_posts (option_id INTEGER PRIMARY KEY, option_name TEXT NOT NULL)');
        $this->registry->expects($this->never())->method('markChunkRestored');
        $filler = str_repeat('x', 600);

        try {
            $this->handler->restoreData($this->dataChunk('wp_posts', ['option_id', 'option_name'], [[1, $filler], [2, $filler], [3, null]]));
            $this->fail('A row that cannot be inserted must fail the chunk');
        } catch (Exception $e) {
            $this->assertStringContainsString('Failed to restore table data for wp_posts', $e->getMessage());
        }

        $inserts = array_filter($this->wpdb->queries, static function (string $query): bool {
            return str_starts_with($query, 'REPLACE');
        });
        $this->assertCount(2, $inserts);
        $this->assertSame([], $this->wpdb->rows('SELECT * FROM pmt_ab12cd_wp_posts'));
        $this->assertContains('ROLLBACK', $this->wpdb->queries);
    }

    public function testInsertErrorNamesTheTable(): void
    {
        $this->mapTable('wp_missing');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to restore table data for wp_missing: ');

        $this->handler->restoreData($this->dataChunk('wp_missing', ['ID'], [[1]]));
    }

    public function testAChunkThatWasRestoredBeforeIsNotInsertedAgain(): void
    {
        $this->registry->method('hasRestoredTableChunk')->with('wp_posts', 1)->willReturn(true);
        $this->registry->expects($this->never())->method('markChunkRestored');

        $result = $this->handler->restoreData($this->dataChunk('wp_posts', ['ID'], [[1]]));

        $this->assertSame(['action' => 'restored_table_data', 'table' => 'wp_posts', 'chunk' => 1], $result);
        $this->assertSame([], $this->wpdb->queries);
    }

    private function mapTable(string $source, ?string $destination = null): void
    {
        $destination = $destination ?? $source;
        $this->map[$source] = [
            'destination' => $destination,
            'temp' => 'pmt_ab12cd_' . $destination,
            'backup' => 'pmb_ab12cd_' . $destination,
        ];
    }

    private function structureChunk(string $table, string $createStatement): array
    {
        return [
            'type' => 'table_structure',
            'table' => ['name' => $table, 'create_statement' => $createStatement, 'indexes' => []],
            'source_table_prefix' => 'wp_',
        ];
    }

    private function dataChunk(string $table, array $columns, array $rows, int $chunkNumber = 1, array $binaryMap = []): array
    {
        $scanner = (new \ReflectionClass(DatabaseScanner::class))->newInstanceWithoutConstructor();

        $chunk = [
            'type' => 'table_data',
            'table' => ['name' => $table, 'chunk_info' => ['chunk_number' => $chunkNumber, 'total_chunks' => 2]],
            'columns' => $columns,
            'data' => $rows,
            'checksum' => $scanner->getChecksum($rows),
            'source_table_prefix' => 'wp_',
        ];

        if ($binaryMap !== []) {
            $chunk['binary_map'] = $binaryMap;
        }

        return $chunk;
    }
}
