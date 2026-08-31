<?php

declare(strict_types=1);

final class GitHubInstallationRepository
{
    /** @return array<int, array<string, mixed>> */
    public static function allForUser(int $userId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM github_installations WHERE user_id = :user_id ORDER BY account_login');
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM github_installations WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        return $statement->fetch() ?: null;
    }

    /** @param array<string, mixed> $installation */
    public static function saveForUser(int $userId, array $installation): array
    {
        $githubId = (int) ($installation['id'] ?? 0);
        $account = is_array($installation['account'] ?? null) ? $installation['account'] : [];
        if ($githubId < 1 || (int) ($account['id'] ?? 0) < 1 || trim((string) ($account['login'] ?? '')) === '') {
            throw new GitHubAccessException('GitHub returned an incomplete installation. Please try connecting again.');
        }

        $existing = Database::connection()->prepare('SELECT id, user_id FROM github_installations WHERE github_installation_id = :github_id AND user_id = :user_id LIMIT 1');
        $existing->execute(['github_id' => $githubId, 'user_id' => $userId]);
        $row = $existing->fetch();

        $values = [
            'user_id' => $userId,
            'github_id' => $githubId,
            'account_id' => (int) $account['id'],
            'account_login' => substr((string) $account['login'], 0, 255),
            'account_type' => substr((string) ($account['type'] ?? 'User'), 0, 40),
            'repository_selection' => substr((string) ($installation['repository_selection'] ?? 'selected'), 0, 30),
            'permissions_json' => json_encode($installation['permissions'] ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'suspended_at' => $installation['suspended_at'] ?? null,
        ];

        if ($row) {
            $statement = Database::connection()->prepare('UPDATE github_installations SET account_id = :account_id, account_login = :account_login, account_type = :account_type, repository_selection = :repository_selection, permissions_json = :permissions_json, suspended_at = :suspended_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id');
            $statement->execute([
                'account_id' => $values['account_id'],
                'account_login' => $values['account_login'],
                'account_type' => $values['account_type'],
                'repository_selection' => $values['repository_selection'],
                'permissions_json' => $values['permissions_json'],
                'suspended_at' => $values['suspended_at'],
                'id' => (int) $row['id'],
                'user_id' => $userId,
            ]);
            $id = (int) $row['id'];
        } else {
            $id = Database::insert('INSERT INTO github_installations (user_id, github_installation_id, account_id, account_login, account_type, repository_selection, permissions_json, suspended_at) VALUES (:user_id, :github_id, :account_id, :account_login, :account_type, :repository_selection, :permissions_json, :suspended_at)', $values);
        }

        $saved = self::findForUser($id, $userId);
        if ($saved === null) throw new RuntimeException('The GitHub installation could not be loaded after saving.');
        return $saved;
    }
}
