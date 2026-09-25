<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Class Database
 *
 * @package WordpressMigrator\Helpers
 *
 * Handles database connections and queries.
 */
class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    /**
     * Database constructor.
     *
     * @throws \Exception
     */
    private function __construct()
    {
        try {
            $host = DB_HOST;
            $port = null;
            $socket = null;

            if (strpos($host, ':') !== false) {
                list($hostname, $socketOrPort) = explode(':', $host, 2);

                if (strpos($socketOrPort, '/') === 0) {
                    $host = $hostname;
                    $socket = $socketOrPort;
                } else {
                    $host = $hostname;
                    $port = $socketOrPort;
                }
            }

            if ($socket !== null) {
                $dsn = sprintf(
                    'mysql:unix_socket=%s;dbname=%s;charset=utf8mb4',
                    $socket,
                    DB_NAME
                );
            } elseif ($port !== null) {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $host,
                    $port,
                    DB_NAME
                );
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    $host,
                    DB_NAME
                );
            }

            $this->pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', SESSION foreign_key_checks = 0"
            ]);
        } catch (PDOException $e) {
            throw new \Exception('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Gets the singleton instance of the Database class.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Prepares and executes a SQL query.
     *
     * @param string $query
     * @param mixed  ...$params
     * @return PDOStatement
     */
    public function prepare(string $query, mixed ...$params): PDOStatement
    {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Gets multiple rows from the database.
     *
     * @param string $query
     * @param array  $params
     * @return array
     */
    public function getResults(string $query, array $params = []): array
    {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Gets a single row from the database.
     *
     * @param string $query
     * @param array  $params
     * @return mixed
     */
    public function getRow(string $query, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Gets a single variable from the database.
     *
     * @param string $query
     * @param array  $params
     * @return mixed
     */
    public function getVar(string $query, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * Gets the PDO instance.
     *
     * @return PDO
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
