<?php

declare(strict_types=1);

namespace App\Engine;

use PDO;
use PDOException;

/**
 * PDO wrapper. Connects to MySQL from .env credentials.
 *
 * Provides query(), execute(), transaction(), lastInsertId().
 * All queries use prepared statements. PDO::ERRMODE_EXCEPTION is set globally.
 *
 * Demo mode (.demo sentinel) does NOT change the database connection.
 * Both normal and demo mode use the same MySQL database from .env.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $name = $_ENV['DB_DATABASE'] ?? 'voxelbooking';
        $user = $_ENV['DB_USERNAME'] ?? 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        $initCommandAttr = defined('Pdo\Mysql::ATTR_INIT_COMMAND')
            ? \Pdo\Mysql::ATTR_INIT_COMMAND
            : 1002; // PDO::MYSQL_ATTR_INIT_COMMAND value

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            $initCommandAttr             => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
        ]);

        return self::$pdo;
    }

    /**
     * Execute a SELECT query with prepared statement bindings.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function query(string $sql, array $bindings = []): array
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->fetchAll();
    }

    /**
     * Execute an INSERT/UPDATE/DELETE with prepared statement bindings.
     * Returns the number of affected rows.
     */
    public static function execute(string $sql, array $bindings = []): int
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    /**
     * Execute a callback within a database transaction.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connect();
        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function lastInsertId(): string
    {
        return self::connect()->lastInsertId();
    }

    public static function pdo(): PDO
    {
        return self::connect();
    }

    /**
     * Check if a database connection can be established.
     */
    public static function canConnect(): bool
    {
        try {
            self::connect();
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Check if a specific table exists (MySQL via information_schema).
     */
    public static function tableExists(string $table): bool
    {
        try {
            if (self::isSQLite()) {
                $result = self::query(
                    "SELECT COUNT(*) as cnt FROM sqlite_master WHERE type = 'table' AND name = ?",
                    [$table]
                );
            } else {
                $result = self::query(
                    'SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                    [$_ENV['DB_DATABASE'] ?? 'voxelbooking', $table]
                );
            }

            return ($result[0]['cnt'] ?? 0) > 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Check if the current connection is SQLite.
     */
    public static function isSQLite(): bool
    {
        try {
            return self::connect()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Upsert a key/value setting. Cross-DB compatible (MySQL + SQLite).
     *
     * Uses INSERT OR REPLACE on SQLite, ON DUPLICATE KEY UPDATE on MySQL.
     * The settings table must have a UNIQUE constraint on `key`.
     */
    public static function upsertSetting(string $key, string $value): void
    {
        if (self::isSQLite()) {
            self::execute(
                'INSERT OR REPLACE INTO `settings` (`key`, `value`) VALUES (?, ?)',
                [$key, $value]
            );
        } else {
            self::execute(
                'INSERT INTO `settings` (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
                [$key, $value]
            );
        }
    }

    /**
     * Reset the connection (for testing).
     */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
