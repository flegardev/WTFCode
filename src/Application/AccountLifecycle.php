<?php

declare(strict_types=1);

namespace WTFCode\Application;

use Database;
use Logger;
use LoginRateLimiter;
use Throwable;

final class AccountLifecycle
{
    /** Same response regardless of whether an account exists or delivery succeeds. */
    public static function requestLink(string $email, string $purpose): void
    {
        if (!in_array($purpose, ['verify', 'reset'], true)) {
            return;
        }
        $email = strtolower(trim($email));
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $keys = [hash('sha256', 'account-link:' . $purpose . ':' . $email), LoginRateLimiter::key('account-mail:' . $purpose)];
        foreach ($keys as $key) {
            if (LoginRateLimiter::blocked($key)) {
                return;
            }
        }
        foreach ($keys as $key) {
            LoginRateLimiter::failed($key);
        }
        Database::connection()->prepare('INSERT INTO account_link_requests(email, purpose) VALUES (?, ?)')->execute([$email, $purpose]);
    }

    /** The public request path never looks up accounts or calls a mail provider. */
    public static function drainRequests(int $limit = 5): void
    {
        $pdo = Database::connection();
        for ($i = 0; $i < max(1, min(10, $limit)); $i++) {
            $pdo->beginTransaction();
            $statement = $pdo->query("SELECT * FROM account_link_requests WHERE available_at <= CURRENT_TIMESTAMP AND (leased_until IS NULL OR leased_until < CURRENT_TIMESTAMP) AND attempts < 3 ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");
            $request = $statement->fetch();
            if (!$request) {
                $pdo->commit();
                break;
            }
            $pdo->prepare("UPDATE account_link_requests SET attempts = attempts + 1, leased_until = CURRENT_TIMESTAMP + INTERVAL '5 minutes' WHERE id = ?")->execute([$request['id']]);
            $pdo->commit();
            try {
                self::issueAndDeliver($request['email'], $request['purpose']);
                $pdo->prepare('DELETE FROM account_link_requests WHERE id = ?')->execute([$request['id']]);
            } catch (Throwable) {
                $pdo->prepare("UPDATE account_link_requests SET leased_until = NULL, available_at = CURRENT_TIMESTAMP + INTERVAL '30 seconds' WHERE id = ?")->execute([$request['id']]);
                Logger::error('Account message delivery unavailable', ['purpose' => $request['purpose']]);
            }
        }
    }

    private static function issueAndDeliver(string $email, string $purpose): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT id, email, email_verified_at, is_suspended FROM users WHERE LOWER(email) = :email FOR UPDATE');
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();
            if (!$user || filter_var($user['is_suspended'], FILTER_VALIDATE_BOOLEAN) || ($purpose === 'verify' && $user['email_verified_at'] !== null)) {
                $pdo->commit();
                // A bounded fixed-cost operation also occurs on unknown accounts.
                hash('sha256', random_bytes(32));
                return;
            }
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('DELETE FROM account_tokens WHERE user_id = ? AND purpose = ?')->execute([$user['id'], $purpose]);
            $pdo->prepare('INSERT INTO account_tokens(user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, ?)')->execute([$user['id'], $purpose, hash('sha256', $token), gmdate('Y-m-d H:i:sP', time() + ($purpose === 'reset' ? 1800 : 86400))]);
            $pdo->commit();
            AccountMail::send($email, $purpose, $token);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function consume(string $token, string $purpose, string $password = ''): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || !in_array($purpose, ['verify', 'reset'], true)) {
            return false;
        }
        if ($purpose === 'reset' && (strlen($password) < 10 || strlen($password) > 72)) {
            return false;
        }
        $pdo = Database::connection();
        $lookup = $pdo->prepare('SELECT user_id FROM account_tokens WHERE token_hash = ? AND purpose = ?');
        $lookup->execute([hash('sha256', $token), $purpose]);
        $userId = $lookup->fetchColumn();
        if (!$userId) {
            return false;
        }
        $pdo->beginTransaction();
        try {
            // Lock order matches account deletion and token issuance.
            $user = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_suspended = FALSE FOR UPDATE');
            $user->execute([$userId]);
            $proof = $pdo->prepare('SELECT id FROM account_tokens WHERE user_id = ? AND token_hash = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP FOR UPDATE');
            $proof->execute([$userId, hash('sha256', $token), $purpose]);
            $tokenId = $proof->fetchColumn();
            if (!$user->fetchColumn() || !$tokenId) {
                $pdo->rollBack();
                return false;
            }
            if ($purpose === 'verify') {
                $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP) WHERE id = ?')->execute([$userId]);
                $pdo->prepare('UPDATE account_tokens SET consumed_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$tokenId]);
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
                $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
                $pdo->prepare('DELETE FROM account_tokens WHERE user_id = ?')->execute([$userId]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
