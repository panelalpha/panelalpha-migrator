<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Services\Scanner\DatabaseScanner;
use PanelAlpha\Migrator\Tests\Support\RecordingWpdb;

final class DatabaseScannerTest extends TestCase
{
    private RecordingWpdb $wpdb;
    private DatabaseScanner $scanner;

    protected function setUp(): void
    {
        $this->wpdb = new RecordingWpdb();
        $this->wpdb->sqlite('CREATE TABLE wp_posts (id INTEGER PRIMARY KEY, slug TEXT, body TEXT)');
        $this->wpdb->sqlite('CREATE TABLE wp_empty (id INTEGER)');
        $this->wpdb->sqlite("INSERT INTO wp_posts VALUES (1, 'hello', 'first'), (2, 'world', 'second')");
        $this->wpdb->varAnswers['SUM('] = '250';
        $this->wpdb->varAnswers["table_name = 'wp_posts'"] = '250';
        $this->wpdb->resultAnswers['SHOW INDEXES'] = [
            ['Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Non_unique' => 0],
            ['Key_name' => 'slug_body', 'Column_name' => 'slug', 'Non_unique' => 0],
            ['Key_name' => 'slug_body', 'Column_name' => 'body', 'Non_unique' => 0],
        ];
        $this->scanner = new DatabaseScanner($this->wpdb);
    }

    public function testScansAllTablesIncludingEmptyTable(): void
    {
        $tables = $this->scanner->scan();
        $byName = [];
        foreach ($tables as $table) {
            $byName[$table->name] = $table;
        }

        self::assertSame(['wp_empty', 'wp_posts'], array_keys($byName));
        self::assertSame([0, 0, 1], [$byName['wp_empty']->size, $byName['wp_empty']->rowCount, $byName['wp_empty']->totalChunks]);
        self::assertSame([250, 2, 1], [$byName['wp_posts']->size, $byName['wp_posts']->rowCount, $byName['wp_posts']->totalChunks]);
        self::assertSame([1, 0, 100], [$byName['wp_posts']->chunks[0]->id, $byName['wp_posts']->chunks[0]->offset, $byName['wp_posts']->chunks[0]->limit]);
    }

    public function testViewsAreNotScanned(): void
    {
        $this->wpdb->sqlite('CREATE VIEW wp_posts_view AS SELECT id FROM wp_posts');

        $names = array_map(static fn ($table) => $table->name, $this->scanner->scan());

        self::assertSame(['wp_empty', 'wp_posts'], $names);
        self::assertContains("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'", $this->wpdb->queries);
    }

    public function testSplitsRowsIntoHundredRowChunks(): void
    {
        $this->wpdb->sqlite('DELETE FROM wp_posts');
        foreach (range(1, 101) as $id) {
            $this->wpdb->sqlite('INSERT INTO wp_posts (id) VALUES (' . $id . ')');
        }

        $posts = array_values(array_filter($this->scanner->scan(), static fn ($table) => $table->name === 'wp_posts'))[0];

        self::assertSame(101, $posts->rowCount);
        self::assertSame(2, $posts->totalChunks);
        self::assertSame([2, 100, 100], [$posts->chunks[1]->id, $posts->chunks[1]->offset, $posts->chunks[1]->limit]);
    }

    public function testReadsStructureIndexesAndRows(): void
    {
        $structure = $this->scanner->getTableStructure('wp_posts');
        self::assertSame('wp_posts', $structure->name);
        self::assertStringContainsString('CREATE TABLE wp_posts', $structure->createStatement);
        self::assertCount(2, $structure->indexes);
        self::assertSame(['id'], $structure->indexes[0]->columns);
        self::assertSame('PRIMARY', $structure->indexes[0]->type);
        self::assertSame(['slug', 'body'], $structure->indexes[1]->columns);
        self::assertSame('UNIQUE', $structure->indexes[1]->type);

        $chunk = $this->scanner->getTableChunk('wp_posts', 1, 1);
        self::assertSame(['id', 'slug', 'body'], $chunk->columns);
        self::assertEquals([[2, 'world', 'second']], $chunk->data);
        self::assertSame(1, $chunk->actualRows);
    }

    public function testReadsOnlyTheRequestedPageOfRows(): void
    {
        $this->scanner->getTableChunk('wp_posts', 100, 100);

        self::assertContains('SELECT * FROM `wp_posts` LIMIT 100 OFFSET 100', $this->wpdb->queries);
        self::assertSame(1, $this->wpdb->flushed);
    }

    public function testBinaryValuesAndNullsComeBackUnchanged(): void
    {
        $this->wpdb->sqlite('CREATE TABLE wp_big (id INTEGER, data BLOB, note TEXT)');
        $this->wpdb->sqlite("INSERT INTO wp_big VALUES (1, X'00FF10', NULL)");

        $chunk = $this->scanner->getTableChunk('wp_big', 0, 100);

        self::assertSame("\x00\xFF\x10", $chunk->data[0][1]);
        self::assertNull($chunk->data[0][2]);
    }

    public function testQuotesTableNames(): void
    {
        $this->wpdb->failOn = '`odd``name`';

        try {
            $this->scanner->getTableChunk('odd`name', 0, 1);
            self::fail('The failed read must throw');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('SHOW COLUMNS FROM `odd``name`', implode("\n", $this->wpdb->queries));
        }
    }

    public function testReturnsNullWhenCreateStatementIsMissing(): void
    {
        self::assertNull($this->scanner->getTableStructure('does_not_exist'));
    }

    public function testAFailedReadThrowsInsteadOfReturningNoRows(): void
    {
        $this->wpdb->failOn = 'SELECT * FROM';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database read failed: Simulated failure for: SELECT * FROM');

        $this->scanner->getTableChunk('wp_posts', 0, 100);
    }

    public function testErrorPrintingIsRestoredAfterReading(): void
    {
        $this->scanner->getTableChunk('wp_posts', 0, 100);

        self::assertFalse($this->wpdb->suppress);
    }

    public function testChecksumAndTotalSize(): void
    {
        $data = [["invalid\xff", 1]];
        self::assertSame(hash('sha256', json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE)), $this->scanner->getChecksum($data));
        self::assertSame(250, $this->scanner->getTotalSize());
    }

    public function testTheGlobalWpdbIsUsedByDefault(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb;

        try {
            self::assertSame(250, (new DatabaseScanner())->getTotalSize());
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }
}
