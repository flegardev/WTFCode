<?php

declare(strict_types=1);

final class DatabaseSessionHandler implements SessionHandlerInterface
{
    /** @var array<string, true> */
    private array $locks = [];

    public function __construct(private readonly int $lifetimeSeconds)
    {
    }

    public function open(string $path, string $name): bool
    {
        Database::connection();
        return true;
    }

    public function close(): bool
    {
        foreach (array_keys($this->locks) as $sessionId) $this->unlock($sessionId);
        return true;
    }

    public function read(string $id): string|false
    {
        $this->lock($id);
        $statement = Database::connection()->prepare('SELECT payload FROM sessions WHERE session_id = :id AND expires_at > CURRENT_TIMESTAMP LIMIT 1');
        $statement->execute(['id' => $id]);
        $payload = $statement->fetchColumn();
        return is_string($payload) ? $payload : '';
    }

    public function write(string $id, string $data): bool
    {
        $this->lock($id);
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $userId = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
            if ($userId !== null) {
                $owner = $pdo->prepare('SELECT session_version FROM users WHERE id = ?' . ' FOR SHARE');
                $owner->execute([$userId]);
                $version = $owner->fetchColumn();
                if ($version === false || (int) $version !== (int) ($_SESSION['user']['session_version'] ?? 0)) {
                    $pdo->prepare('DELETE FROM sessions WHERE session_id = ?')->execute([$id]);
                    if ($ownsTransaction) $pdo->commit();
                    return true;
                }
            }
            $expiresAt = gmdate('Y-m-d H:i:sP', time() + $this->lifetimeSeconds);
            $sql = Database::isPostgres()
                ? 'INSERT INTO sessions (session_id, user_id, payload, expires_at, updated_at) VALUES (:id, :user_id, :payload, :expires_at, CURRENT_TIMESTAMP) ON CONFLICT (session_id) DO UPDATE SET user_id = EXCLUDED.user_id, payload = EXCLUDED.payload, expires_at = EXCLUDED.expires_at, updated_at = CURRENT_TIMESTAMP'
                : 'INSERT INTO sessions (session_id, user_id, payload, expires_at, updated_at) VALUES (:id, :user_id, :payload, :expires_at, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), payload = VALUES(payload), expires_at = VALUES(expires_at), updated_at = CURRENT_TIMESTAMP';
            $pdo->prepare($sql)->execute(['id' => $id, 'user_id' => $userId, 'payload' => $data, 'expires_at' => $expiresAt]);
            if ($ownsTransaction) $pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public function destroy(string $id): bool
    {
        $statement = Database::connection()->prepare('DELETE FROM sessions WHERE session_id = :id');
        $result = $statement->execute(['id' => $id]);
        $this->unlock($id);
        return $result;
    }

    public function gc(int $max_lifetime): int|false
    {
        $statement = Database::connection()->prepare('DELETE FROM sessions WHERE expires_at <= CURRENT_TIMESTAMP');
        $statement->execute();
        return $statement->rowCount();
    }

    private function lock(string $id): void
    {
        if (isset($this->locks[$id]) || !Database::isPostgres()) return;
        $statement = Database::connection()->prepare('SELECT pg_advisory_lock(hashtextextended(:id, 0))');
        $statement->execute(['id' => $id]);
        $this->locks[$id] = true;
    }

    private function unlock(string $id): void
    {
        if (!isset($this->locks[$id]) || !Database::isPostgres()) return;
        $statement = Database::connection()->prepare('SELECT pg_advisory_unlock(hashtextextended(:id, 0))');
        $statement->execute(['id' => $id]);
        unset($this->locks[$id]);
    }
}
