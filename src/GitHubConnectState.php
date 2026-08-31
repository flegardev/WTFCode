<?php

declare(strict_types=1);

final class GitHubConnectState
{
    private const LIFETIME_SECONDS = 600;

    public static function begin(int $userId): string
    {
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $_SESSION['github_connect_state'] = [
            'hash' => hash('sha256', $state),
            'user_id' => $userId,
            'expires_at' => time() + self::LIFETIME_SECONDS,
        ];
        return $state;
    }

    public static function consume(string $state, int $userId): bool
    {
        $known = $_SESSION['github_connect_state'] ?? null;
        unset($_SESSION['github_connect_state']);
        if (!is_array($known) || $state === '' || (int) ($known['user_id'] ?? 0) !== $userId || (int) ($known['expires_at'] ?? 0) < time()) return false;
        $hash = (string) ($known['hash'] ?? '');
        return $hash !== '' && hash_equals($hash, hash('sha256', $state));
    }
}
