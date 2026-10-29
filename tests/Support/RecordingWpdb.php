<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests\Support;

use PDO;

/**
 * A stand-in for the WordPress $wpdb that records every statement passed to query().
 *
 * It is backed by an in-memory SQLite database. SET, CREATE TABLE and RENAME TABLE are recorded
 * but not run, because SQLite refuses them. DROP TABLE of several tables runs one table at a time.
 * SHOW TABLES LIKE is answered from sqlite_master, and START TRANSACTION runs as BEGIN.
 * Variable reads are answered from $variables.
 * Reads for the source side are answered too: SHOW FULL TABLES, SHOW CREATE TABLE, SHOW COLUMNS,
 * SELECT COUNT(*) and plain SELECT run on SQLite. SHOW INDEXES and other answers come from $resultAnswers.
 * Use sqlite() to prepare the database with statements SQLite understands.
 */
final class RecordingWpdb
{
    public string $base_prefix = 'wp_';

    public string $dbname = 'test_database';

    public string $charset = 'utf8mb4';

    public string $last_error = '';

    public bool $check_current_query = true;

    public bool $suppress = false;

    public $dbh = 'connection';

    /** @var string[] */
    public array $queries = [];

    /** @var bool[] The value of check_current_query at each query(), keyed like $queries. */
    public array $checkFlags = [];

    /** @var string[] */
    public array $charsetCalls = [];

    /** @var array<string, string> */
    public array $variables = [
        '@@SESSION.sql_mode' => 'STRICT_TRANS_TABLES',
        '@@SESSION.foreign_key_checks' => '1',
        '@@SESSION.lock_wait_timeout' => '86400',
        '@@max_allowed_packet' => '16777216',
    ];

    /** @var int How many times flush() was called. */
    public int $flushed = 0;

    public bool $utf8mb4 = true;

    /** @var array<int, string[]> Answers for the next SHOW TABLES LIKE queries, used before the real tables. */
    public array $showTablesAnswers = [];

    /** @var array<string, string|null> Answers for get_var(), keyed by a text the query contains. */
    public array $varAnswers = [];

    /** @var array<string, array> Rows for get_results(), keyed by a text the query contains. */
    public array $resultAnswers = [];

    public ?string $failOn = null;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function sqlite(string $statement): void
    {
        $this->pdo->exec($statement);
    }

    /**
     * @return array<int, array>
     */
    public function rows(string $query): array
    {
        return $this->pdo->query($query)->fetchAll(PDO::FETCH_NUM);
    }

    /**
     * @return string[]
     */
    public function tables(): array
    {
        return $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function query(string $query)
    {
        $this->queries[] = $query;
        $this->checkFlags[count($this->queries) - 1] = $this->check_current_query;
        $this->last_error = '';

        if ($this->failOn !== null && str_contains($query, $this->failOn)) {
            $this->last_error = 'Simulated failure for: ' . $this->failOn;

            return false;
        }

        if (preg_match('/^\s*(SET|CREATE|RENAME)\b/i', $query)) {
            return 0;
        }

        if (preg_match('/^\s*DROP TABLE IF EXISTS (.+)$/i', $query, $matches)) {
            foreach (explode(', ', $matches[1]) as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
            }

            return true;
        }

        if (preg_match('/^\s*START TRANSACTION\s*$/i', $query)) {
            $query = 'BEGIN';
        }

        try {
            return $this->pdo->exec($query);
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();

            return false;
        }
    }

    public function get_var(string $query)
    {
        $this->queries[] = $query;
        $this->last_error = '';

        if ($this->failOn !== null && str_contains($query, $this->failOn)) {
            $this->last_error = 'Simulated failure for: ' . $this->failOn;

            return null;
        }

        if (preg_match('/^SELECT (@@[\w.]+)$/', $query, $matches)) {
            return $this->variables[$matches[1]] ?? null;
        }

        foreach ($this->varAnswers as $needle => $answer) {
            if (str_contains($query, $needle)) {
                return $answer;
            }
        }

        if (preg_match('/^SELECT COUNT\(\*\) FROM `[^`]+`$/', $query)) {
            return (string) $this->pdo->query($query)->fetchColumn();
        }

        return null;
    }

    /**
     * @return array<int, array|object>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        $this->queries[] = $query;
        $this->last_error = '';

        if ($this->failOn !== null && str_contains($query, $this->failOn)) {
            $this->last_error = 'Simulated failure for: ' . $this->failOn;

            return [];
        }

        foreach ($this->resultAnswers as $needle => $rows) {
            if (str_contains($query, $needle)) {
                return $this->shape($rows, $output);
            }
        }

        if (preg_match("/^SHOW FULL TABLES WHERE Table_type = '(BASE TABLE|VIEW)'$/", $query, $matches)) {
            $types = ['table' => 'BASE TABLE', 'view' => 'VIEW'];
            $rows = [];

            foreach ($this->pdo->query("SELECT name, type FROM sqlite_master WHERE type IN ('table', 'view') ORDER BY name")->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
                if ($types[$type] === $matches[1]) {
                    $rows[] = [$name, $types[$type]];
                }
            }

            return $this->shape($rows, $output);
        }

        if (preg_match('/^SHOW CREATE TABLE `([^`]+)`$/', $query, $matches)) {
            $rows = $this->pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote($matches[1]))->fetchAll(PDO::FETCH_NUM);

            return $this->shape($rows, $output);
        }

        if (preg_match('/^SHOW COLUMNS FROM `([^`]+)`$/', $query, $matches)) {
            $rows = array_map(static function (array $column): array {
                return ['Field' => $column['name']];
            }, $this->pdo->query('PRAGMA table_info(`' . $matches[1] . '`)')->fetchAll(PDO::FETCH_ASSOC));

            return $this->shape($rows, $output);
        }

        if (!preg_match('/^SELECT \* FROM `[^`]+` LIMIT \d+ OFFSET \d+$/', $query)) {
            return [];
        }

        try {
            $rows = $this->pdo->query($query)->fetchAll(PDO::FETCH_NUM);
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();

            return [];
        }

        return $this->shape($rows, $output);
    }

    public function flush(): void
    {
        $this->flushed++;
    }

    /**
     * @param array<int, array|object> $rows Rows as lists, or as maps for objects
     * @return array<int, array|object>
     */
    private function shape(array $rows, string $output): array
    {
        if ($output === 'ARRAY_N') {
            return array_map(static function ($row): array {
                return array_values((array) $row);
            }, $rows);
        }

        return array_map(static function ($row): object {
            return (object) $row;
        }, $rows);
    }

    /**
     * @return string[]
     */
    public function get_col(string $query): array
    {
        $this->queries[] = $query;

        if ($this->showTablesAnswers !== [] && str_starts_with($query, 'SHOW TABLES LIKE')) {
            return array_shift($this->showTablesAnswers);
        }

        if (preg_match("/^SHOW TABLES LIKE '(.*)'$/s", $query, $matches)) {
            $statement = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE ? ESCAPE '\\' ORDER BY name");
            $statement->execute([$matches[1]]);

            return $statement->fetchAll(PDO::FETCH_COLUMN);
        }

        return [];
    }

    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        return preg_replace_callback('/%[sdi%]/', function (array $found) use (&$args): string {
            if ($found[0] === '%%') {
                return '%';
            }

            $value = array_shift($args);

            if ($found[0] === '%i') {
                return '`' . str_replace('`', '``', (string) $value) . '`';
            }

            if ($found[0] === '%d') {
                return (string) (int) $value;
            }

            // SQLite cannot hold a NUL byte inside a quoted string, so such values become a blob literal.
            return str_contains((string) $value, "\0") ? "X'" . bin2hex((string) $value) . "'" : $this->pdo->quote((string) $value);
        }, $query);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = $this->suppress;
        $this->suppress = $suppress;

        return $previous;
    }

    public function has_cap(string $capability): bool
    {
        return $capability === 'utf8mb4' && $this->utf8mb4;
    }

    public function set_charset($dbh, ?string $charset = null, ?string $collate = null): void
    {
        $this->charsetCalls[] = ($charset ?? 'default') . '/' . ($collate ?? 'default');
    }
}
