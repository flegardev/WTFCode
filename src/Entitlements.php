<?php

declare(strict_types=1);

/** Server-owned usage policy. Payment state never comes from a browser field. */
final class Entitlements
{
    /** @return array{projects:int,scans:int,repository_bytes:int,source_bytes:int,ai_calls:int} */
    public static function limits(string $plan = 'trial'): array
    {
        return match ($plan) {
            'founder' => ['projects' => 3, 'scans' => 20, 'repository_bytes' => 104857600, 'source_bytes' => 20971520, 'ai_calls' => 0],
            'one-time' => ['projects' => 1, 'scans' => 1, 'repository_bytes' => 104857600, 'source_bytes' => 20971520, 'ai_calls' => 0],
            default => ['projects' => 1, 'scans' => 5, 'repository_bytes' => 104857600, 'source_bytes' => 20971520, 'ai_calls' => 0],
        };
    }

    /** All accounts remain trial until a reviewed, server-owned entitlement store exists. */
    public static function planForUser(int $userId): string
    {
        return 'trial';
    }

    /** @return array{plan:string,projects:int,scans:int,limits:array{projects:int,scans:int,repository_bytes:int,source_bytes:int,ai_calls:int}} */
    public static function usage(int $userId): array
    {
        $pdo = Database::connection();
        $projects = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE user_id = :user_id');
        $projects->execute(['user_id' => $userId]);
        $window = Database::isPostgres() ? "CURRENT_TIMESTAMP - INTERVAL '30 days'" : 'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)';
        $scans = $pdo->prepare('SELECT COUNT(*) FROM analysis_jobs WHERE requested_by_user_id = :user_id AND created_at >= ' . $window);
        $scans->execute(['user_id' => $userId]);
        $plan = self::planForUser($userId);

        return ['plan' => $plan, 'projects' => (int) $projects->fetchColumn(), 'scans' => (int) $scans->fetchColumn(), 'limits' => self::limits($plan)];
    }

    /** Call first in the enqueue/import transaction to serialize a customer's requests. */
    public static function lockUser(int $userId): void
    {
        $pdo = Database::connection();
        if (!$pdo->inTransaction()) {
            throw new LogicException('Usage checks require a transaction.');
        }
        $statement = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_suspended = false FOR UPDATE');
        $statement->execute(['id' => $userId]);
        if (!$statement->fetchColumn()) {
            throw new DomainException('Your account cannot queue an analysis.');
        }
    }

    public static function requireProjectSlot(int $userId): void
    {
        self::lockUser($userId);
        $usage = self::usage($userId);
        if ($usage['projects'] >= $usage['limits']['projects']) {
            throw new DomainException('Your free trial includes one project. Open your existing project to rescan it. Paid plans are not available yet.');
        }
    }

    public static function requireScanSlot(int $userId): void
    {
        $usage = self::usage($userId);
        if ($usage['scans'] >= $usage['limits']['scans']) {
            throw new DomainException('You have used all five trial scans in the last 30 days. Existing analysis remains available. Please try again after the rolling window resets.');
        }
    }
}
