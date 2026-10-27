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
     *
     * @param Database|PDO|null $database
     */
    public static function install($database = null): Database
    {
        if (!$database instanceof Database) {
            $pdo = $database ?? new PDO('sqlite::memory:');
            $reflection = new ReflectionClass(Database::class);
            $database = $reflection->newInstanceWithoutConstructor();
            $property = $reflection->getProperty('pdo');
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true);
            }
            $property->setValue($database, $pdo);
        }

        (new ReflectionClass(Database::class))->setStaticPropertyValue('instance', $database);

        return $database;
    }

    public static function reset(): void
    {
        (new ReflectionClass(Database::class))->setStaticPropertyValue('instance', null);
    }
}
