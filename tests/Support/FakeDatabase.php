<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests\Support;

use PDO;
use ReflectionClass;
use WordpressMigrator\Helpers\Database;

/**
 * Puts a Database singleton in place without connecting to MySQL.
 */
final class FakeDatabase
{
    /**
     * Installs the given Database as the singleton, or one backed by the given PDO.
     */
    public static function install(Database|PDO|null $database = null): Database
    {
        if (!$database instanceof Database) {
            $pdo = $database ?? new PDO('sqlite::memory:');
            $reflection = new ReflectionClass(Database::class);
            $database = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('pdo')->setValue($database, $pdo);
        }

        (new ReflectionClass(Database::class))->setStaticPropertyValue('instance', $database);

        return $database;
    }

    public static function reset(): void
    {
        (new ReflectionClass(Database::class))->setStaticPropertyValue('instance', null);
    }
}
