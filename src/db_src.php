<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class DB
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
                ]
            );
        }
        return self::$pdo;
    }

    /** Execute a prepared statement, return the statement */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Fetch a single row or null */
    public static function row(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    /** Fetch all rows */
    public static function rows(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Fetch a single scalar value or null */
    public static function val(string $sql, array $params = []): mixed
    {
        $val = self::query($sql, $params)->fetchColumn();
        return $val === false ? null : $val;
    }

    /** Execute and return affected row count */
    public static function run(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** Return last insert ID */
    public static function lastId(): string
    {
        return self::get()->lastInsertId();
    }

    /** Begin transaction */
    public static function begin(): void   { self::get()->beginTransaction(); }
    public static function commit(): void  { self::get()->commit(); }
    public static function rollback(): void { self::get()->rollBack(); }
}
