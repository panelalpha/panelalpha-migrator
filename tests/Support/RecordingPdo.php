<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests\Support;

use PDO;

/**
 * An in-memory SQLite PDO that records every statement and answers the MySQL-only ones.
 *
 * SET statements and CREATE TABLE statements are recorded but not run, because SQLite
 * refuses them. SHOW TABLES and SHOW CREATE TABLE are answered from sqlite_master.
 * Use sqlite() to prepare the database with statements SQLite understands.
 */
final class RecordingPdo extends PDO
{
    /** @var string[] */
    public array $statements = [];

    public ?string $failOn = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function sqlite(string $statement): void
    {
        parent::exec($statement);
    }

    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        $this->statements[] = $statement;
        $this->failIfAsked($statement);

        if (preg_match('/^\s*(SET|CREATE)\b/i', $statement)) {
            return 0;
        }

        return parent::exec($statement);
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        $this->statements[] = $query;
        $this->failIfAsked($query);

        return parent::prepare($query, $options);
    }

    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$fetchModeArgs)
    {
        $this->statements[] = $query;
        $this->failIfAsked($query);

        if (preg_match('/^\s*SHOW TABLES\s*$/i', $query)) {
            $query = "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name";
        } elseif (preg_match('/^\s*SHOW CREATE TABLE `([^`]+)`\s*$/i', $query, $matches)) {
            $query = "SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name = " . parent::quote($matches[1]);
        }

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function failIfAsked(string $statement): void
    {
        if ($this->failOn !== null && str_contains($statement, $this->failOn)) {
            throw new \PDOException('Simulated failure for: ' . $this->failOn);
        }
    }
}
