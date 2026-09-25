<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Database;
use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Tests\Support\FakeDatabase;
use WordpressMigrator\Tests\Support\RecordingPdo;

final class DatabaseScannerTest extends TestCase
{
    private RecordingPdo $pdo;
    private DatabaseScanner $scanner;

    protected function setUp(): void
    {
        if (!defined('DB_NAME')) {
            define('DB_NAME', 'test_database');
        }

        $this->pdo = new RecordingPdo();
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
        $this->pdo->sqlite('CREATE TABLE wp_posts (id INTEGER PRIMARY KEY, slug TEXT, body TEXT)');
        $this->pdo->sqlite('CREATE TABLE wp_empty (id INTEGER)');
        $this->pdo->sqlite("INSERT INTO wp_posts VALUES (1, 'hello', 'first'), (2, 'world', 'second')");
        FakeDatabase::install(new ScannerDatabase($this->pdo));
        $this->scanner = new DatabaseScanner();
    }

    protected function tearDown(): void
    {
        FakeDatabase::reset();
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

    public function testSplitsRowsIntoHundredRowChunks(): void
    {
        $this->pdo->sqlite('DELETE FROM wp_posts');
        $insert = $this->pdo->prepare('INSERT INTO wp_posts (id) VALUES (?)');
        foreach (range(1, 101) as $id) {
            $insert->execute([$id]);
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
        self::assertSame([[2, 'world', 'second']], $chunk->data);
        self::assertSame(1, $chunk->actualRows);
    }

    public function testReturnsNullWhenCreateStatementIsMissing(): void
    {
        self::assertNull($this->scanner->getTableStructure('does_not_exist'));
    }

    public function testChecksumAndTotalSize(): void
    {
        $data = [["invalid\xff", 1]];
        self::assertSame(hash('sha256', json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE)), $this->scanner->getChecksum($data));
        self::assertSame(250, $this->scanner->getTotalSize());
    }
}

/** Supplies MySQL metadata while leaving table discovery and row reads on real SQLite. */
final class ScannerDatabase extends Database
{
    public function __construct(private RecordingPdo $recordingPdo) {}

    public function getPdo(): PDO
    {
        return $this->recordingPdo;
    }

    public function getRow(string $query, array $params = []): mixed
    {
        if (str_contains($query, 'information_schema.TABLES')) {
            if (str_contains($query, 'SUM(')) {
                return (object) ['size' => 250];
            }
            return $params[1] === 'wp_posts' ? (object) ['size' => 250] : false;
        }
        if (preg_match('/COUNT\(\*\).*FROM `([^`]+)`/', $query, $matches)) {
            return (object) ['count' => (int) $this->recordingPdo->query('SELECT COUNT(*) FROM `' . $matches[1] . '`')->fetchColumn()];
        }
        throw new \LogicException('Unexpected query: ' . $query);
    }

    public function getResults(string $query, array $params = []): array
    {
        if (str_starts_with($query, 'SHOW COLUMNS')) {
            return [(object) ['Field' => 'id'], (object) ['Field' => 'slug'], (object) ['Field' => 'body']];
        }
        if (str_starts_with($query, 'SHOW INDEXES')) {
            return [
                (object) ['Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Non_unique' => 0],
                (object) ['Key_name' => 'slug_body', 'Column_name' => 'slug', 'Non_unique' => 0],
                (object) ['Key_name' => 'slug_body', 'Column_name' => 'body', 'Non_unique' => 0],
            ];
        }
        throw new \LogicException('Unexpected query: ' . $query);
    }

    public function getVar(string $query, array $params = []): mixed
    {
        if (str_contains($query, 'information_schema.TABLES')) {
            return 250;
        }
        throw new \LogicException('Unexpected query: ' . $query);
    }
}
