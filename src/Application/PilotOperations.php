<?php

declare(strict_types=1);

namespace WTFCode\Application;

use Database;
use RepositoryImporter;

final class PilotOperations
{
    public static function heartbeat(string $workerId): void
    {
        Database::connection()->prepare('INSERT INTO worker_heartbeats(worker_id) VALUES (?) ON CONFLICT(worker_id) DO UPDATE SET last_seen_at = CURRENT_TIMESTAMP')->execute([$workerId]);
    }

    public static function cleanup(): void
    {
        $pdo = Database::connection();
        if (PilotAccess::mode() !== 'open') {
            self::clearLegacyProviderCache();
        }
        $root = app_config()['storage_path'] . '/repos';
        if (is_dir($root) && !is_link($root)) {
            foreach (new \DirectoryIterator($root) as $entry) {
                if ($entry->isDot() || !preg_match('/^project-(\d+)(?:-[a-f0-9]{12})?$/D', $entry->getFilename(), $m)) {
                    continue;
                }
                $q = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM projects WHERE id = ?) AS present, EXISTS(SELECT 1 FROM analysis_jobs WHERE project_id = ? AND state = 'running' AND leased_until > CURRENT_TIMESTAMP) AS active");
                $q->execute([(int) $m[1], (int) $m[1]]);
                $state = $q->fetch();
                $present = filter_var($state['present'], FILTER_VALIDATE_BOOLEAN);
                $active = filter_var($state['active'], FILTER_VALIDATE_BOOLEAN);
                if (!$present || (!$active && $entry->getMTime() < time() - 7200)) {
                    RepositoryImporter::cleanup($entry->getPathname());
                    clearstatcache(true, $entry->getPathname());
                    if (file_exists($entry->getPathname()) || is_link($entry->getPathname())) {
                        \Logger::warning('Temporary repository cleanup deferred', ['project_id' => (int) $m[1]]);
                    }
                }
            }
        }
        $pdo->exec("DELETE FROM storage_cleanup_tasks WHERE created_at < CURRENT_TIMESTAMP - INTERVAL '24 hours'");
        $pdo->exec("DELETE FROM account_tokens WHERE expires_at < CURRENT_TIMESTAMP OR consumed_at IS NOT NULL");
        $pdo->exec("DELETE FROM account_link_requests WHERE created_at < CURRENT_TIMESTAMP - INTERVAL '24 hours'");
        $pdo->exec("DELETE FROM pilot_feedback WHERE created_at < CURRENT_TIMESTAMP - INTERVAL '30 days'");
        $pdo->exec("DELETE FROM worker_heartbeats WHERE last_seen_at < CURRENT_TIMESTAMP - INTERVAL '1 day'");
        $pdo->exec('DELETE FROM sessions WHERE expires_at <= CURRENT_TIMESTAMP');
    }

    /** The legacy content-addressed accelerator has no owner key. Pilot scans
     * bypass it; invalidate expendable caches without removing scan records. */
    public static function clearLegacyProviderCache(): void
    {
        Database::connection()->exec('DELETE FROM provider_cache_entries');
        $root = app_config()['storage_path'] . '/cache/providers';
        if (!is_dir($root) || is_link($root)) {
            return;
        }
        foreach (new \DirectoryIterator($root) as $provider) {
            if ($provider->isDot() || $provider->isLink() || !$provider->isDir() || !preg_match('/^[a-z0-9._-]+$/iD', $provider->getFilename())) {
                continue;
            }
            foreach (new \DirectoryIterator($provider->getPathname()) as $entry) {
                if ($entry->isDot() || $entry->isDir() || !preg_match('/^(?:[a-f0-9]{64}\.json|cache-[a-z0-9]+)$/iD', $entry->getFilename())) {
                    continue;
                }
                if (!@unlink($entry->getPathname())) {
                    \Logger::warning('Legacy provider cache cleanup deferred');
                }
            }
        }
    }

    /** Private CLI/admin information. Never expose this payload on /health.php. */
    public static function status(): array
    {
        $pdo = Database::connection();
        $result = $pdo->query("SELECT (SELECT COUNT(*) FROM worker_heartbeats WHERE last_seen_at > CURRENT_TIMESTAMP - INTERVAL '5 minutes') AS live_workers, COUNT(*) FILTER (WHERE state = 'queued') AS queued, EXTRACT(EPOCH FROM CURRENT_TIMESTAMP - MIN(created_at) FILTER (WHERE state = 'queued')) AS oldest_queued_seconds, COUNT(*) FILTER (WHERE state = 'failed' AND updated_at > CURRENT_TIMESTAMP - INTERVAL '1 day') AS failed_24h, COUNT(*) FILTER (WHERE state IN ('completed', 'partial') AND finished_at > CURRENT_TIMESTAMP - INTERVAL '1 day') AS completed_24h, AVG(EXTRACT(EPOCH FROM finished_at - started_at)) FILTER (WHERE state IN ('completed','partial')) AS average_scan_seconds FROM analysis_jobs")->fetch();
        $result['database'] = 'ready';
        $result['legacy_cache_entries'] = (int) $pdo->query('SELECT COUNT(*) FROM provider_cache_entries')->fetchColumn();
        $result['mail_pending'] = (int) $pdo->query('SELECT COUNT(*) FROM account_link_requests')->fetchColumn();
        $result['mail_exhausted'] = (int) $pdo->query('SELECT COUNT(*) FROM account_link_requests WHERE attempts >= 3')->fetchColumn();
        $result['cleanup_tombstones'] = (int) $pdo->query('SELECT COUNT(*) FROM storage_cleanup_tasks')->fetchColumn();
        $result['disk_free_bytes'] = @disk_free_space(app_config()['storage_path']);
        $result['feedback'] = $pdo->query('SELECT category, COUNT(*) AS count FROM pilot_feedback GROUP BY category')->fetchAll();
        return $result;
    }
}
