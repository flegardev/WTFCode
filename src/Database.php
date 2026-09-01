<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = app_config();
        $driver = (string) ($config['driver'] ?? 'mysql');

        $dsn = match ($driver) {
            'pgsql' => sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=10', $config['host'], $config['port'], $config['database'], $config['sslmode']),
            'sqlite' => sprintf('sqlite:%s', $config['database']),
            default => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']),
        };

        try {
            self::$connection = new PDO($dsn, $config['username'] ?? null, $config['password'] ?? null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            if ($driver === 'pgsql') {
                self::$connection->exec("SET TIME ZONE 'UTC'");
                self::$connection->exec("SET statement_timeout = '120s'");
            } elseif ($driver === 'sqlite') {
                self::$connection->exec('PRAGMA foreign_keys = ON;');
                self::$connection->exec('PRAGMA journal_mode = WAL;');
            }
        } catch (PDOException $exception) {
            Logger::error('Database connection failed', ['code' => $exception->getCode(), 'driver' => $driver]);
            self::$connection = null;
            throw new RuntimeException('WTFCode could not connect to its database.', 0, $exception);
        }

        return self::$connection;
    }

    public static function setConnection(?PDO $pdo): void
    {
        self::$connection = $pdo;
    }

    public static function driver(): string
    {
        return (string) app_config()['driver'];
    }

    public static function isPostgres(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    /** @param array<string|int, mixed> $parameters */
    public static function insert(string $sql, array $parameters = []): int
    {
        $statement = self::connection()->prepare(self::isPostgres() ? rtrim($sql, " \t\n\r;") . ' RETURNING id' : $sql);
        $statement->execute($parameters);
        return self::isPostgres() ? (int) $statement->fetchColumn() : (int) self::connection()->lastInsertId();
    }

    public static function isUniqueViolation(PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505', '19'], true);
    }
}
