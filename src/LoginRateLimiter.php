<?php

declare(strict_types=1);

final class LoginRateLimiter
{
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 900;

    public static function key(string $email): string
    {
        return hash('sha256', strtolower(trim($email)) . '|' . client_ip());
    }

    public static function blocked(string $key): bool
    {
        $statement = Database::connection()->prepare('SELECT locked_until FROM login_attempts WHERE attempt_key = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $lockedUntil = $statement->fetchColumn();
        return is_string($lockedUntil) && strtotime($lockedUntil) > time();
    }

    public static function failed(string $key): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if (Database::isPostgres()) {
                $pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key, 1))')->execute(['key' => $key]);
            }
            $statement = $pdo->prepare('SELECT failures, first_attempt_at FROM login_attempts WHERE attempt_key = :key FOR UPDATE');
            $statement->execute(['key' => $key]);
            $row = $statement->fetch();
            $expired = !$row || strtotime((string) $row['first_attempt_at']) <= time() - self::WINDOW_SECONDS;
            $failures = $expired ? 1 : ((int) $row['failures'] + 1);
            $firstAttempt = $expired ? gmdate('Y-m-d H:i:sP') : (string) $row['first_attempt_at'];
            $lockedUntil = $failures >= self::MAX_FAILURES ? gmdate('Y-m-d H:i:sP', time() + self::WINDOW_SECONDS) : null;
            $sql = Database::isPostgres()
                ? 'INSERT INTO login_attempts (attempt_key, failures, first_attempt_at, locked_until, updated_at) VALUES (:key, :failures, :first_attempt, :locked_until, CURRENT_TIMESTAMP) ON CONFLICT (attempt_key) DO UPDATE SET failures = EXCLUDED.failures, first_attempt_at = EXCLUDED.first_attempt_at, locked_until = EXCLUDED.locked_until, updated_at = CURRENT_TIMESTAMP'
                : 'INSERT INTO login_attempts (attempt_key, failures, first_attempt_at, locked_until, updated_at) VALUES (:key, :failures, :first_attempt, :locked_until, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE failures = VALUES(failures), first_attempt_at = VALUES(first_attempt_at), locked_until = VALUES(locked_until), updated_at = CURRENT_TIMESTAMP';
            $pdo->prepare($sql)->execute(['key' => $key, 'failures' => $failures, 'first_attempt' => $firstAttempt, 'locked_until' => $lockedUntil]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public static function clear(string $key): void
    {
        Database::connection()->prepare('DELETE FROM login_attempts WHERE attempt_key = :key')->execute(['key' => $key]);
    }
}
