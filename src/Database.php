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
        $dsn = $config['driver'] === 'pgsql'
            ? sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=10', $config['host'], $config['port'], $config['database'], $config['sslmode'])
            : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']);

        try {
            self::$connection = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            if ($config['driver'] === 'pgsql') {
                self::$connection->exec("SET TIME ZONE 'UTC'");
                self::$connection->exec("SET statement_timeout = '120s'");
            }
        } catch (PDOException $exception) {
            Logger::error('Database connection failed', ['code' => $exception->getCode()]);
            self::disconnect();
            throw new RuntimeException('WTFCode could not connect to its database.', 0, $exception);
        }

        return self::$connection;
    }

    /**
     * Release the cached connection so a supervised worker or a later request
     * cannot keep reusing a broken PDO handle. Any open transaction is rolled
     * back when the connection is still healthy enough to do so.
     */
    public static function disconnect(): void
    {
        $connection = self::$connection;
        self::$connection = null;

        if (!$connection instanceof PDO) {
            return;
        }

        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // A broken connection may not be able to report or roll back its
            // transaction. Dropping the final handle still prevents reuse.
        }
    }

    public static function driver(): string
    {
        return (string) app_config()['driver'];
    }

    public static function isPostgres(): bool
    {
        return self::driver() === 'pgsql';
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
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
