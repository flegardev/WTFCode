<?php

declare(strict_types=1);

namespace WTFCode\Repository;

use Database;
use Logger;
use PDO;
use RuntimeException;

/**
 * Server-side control surface for the application administrator.
 *
 * This repository intentionally uses the same PostgreSQL/MySQL connection as
 * the rest of WTFCode. It never exposes database credentials or raw evidence
 * to the browser, and every mutating method is called only after Auth's
 * requireAdmin() and a CSRF check have run.
 */
final class AdminRepository
{
    /** @return array{users: int, projects: int, queued_jobs: int, running_jobs: int, findings: int} */
    public function summary(): array
    {
        $count = fn(string $table, string $where = ''): int => (int) $this->database()->query('SELECT COUNT(*) FROM ' . $table . ($where !== '' ? ' WHERE ' . $where : ''))->fetchColumn();

        return [
            'users' => $count('users'),
            'projects' => $count('projects'),
            'queued_jobs' => $count('analysis_jobs', "state = 'queued'"),
            'running_jobs' => $count('analysis_jobs', "state = 'running'"),
            'findings' => $count('scan_findings', "severity IN ('attention', 'risk')"),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function users(string $search = ''): array
    {
        $where = '';
        $parameters = [];
        if ($search !== '') {
            $where = 'WHERE LOWER(u.name) LIKE :search OR LOWER(u.email) LIKE :search';
            $parameters['search'] = '%' . strtolower($search) . '%';
        }
        $statement = $this->database()->prepare(
            'SELECT u.id, u.name, u.email, u.is_admin, u.is_suspended, u.created_at,
                    (SELECT COUNT(*) FROM projects p WHERE p.user_id = u.id) AS project_count
             FROM users u ' . $where . ' ORDER BY u.created_at DESC, u.id DESC LIMIT 100',
        );
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function projects(string $search = ''): array
    {
        $where = '';
        $parameters = [];
        if ($search !== '') {
            $where = 'WHERE LOWER(p.name) LIKE :search OR LOWER(p.repository_url) LIKE :search OR LOWER(u.email) LIKE :search';
            $parameters['search'] = '%' . strtolower($search) . '%';
        }
        $statement = $this->database()->prepare(
            'SELECT p.id, p.name, p.repository_url, p.status, p.last_scan_at, p.last_error, p.created_at,
                    u.id AS user_id, u.name AS user_name, u.email AS user_email,
                    (SELECT COUNT(*) FROM project_files pf WHERE pf.project_id = p.id) AS file_count,
                    (SELECT COUNT(*) FROM scan_findings sf WHERE sf.project_id = p.id AND sf.severity IN (\'attention\', \'risk\')) AS finding_count,
                    (SELECT job.state FROM analysis_jobs job WHERE job.project_id = p.id ORDER BY job.id DESC LIMIT 1) AS latest_job_state
             FROM projects p INNER JOIN users u ON u.id = p.user_id ' . $where . '
             ORDER BY p.updated_at DESC, p.id DESC LIMIT 100',
        );
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function jobs(): array
    {
        $statement = $this->database()->query(
            'SELECT job.id, job.project_id, job.state, job.job_kind, job.analysis_profile,
                    job.attempt_count, job.max_attempts, job.current_stage, job.error_message,
                    job.created_at, job.updated_at, p.name AS project_name, u.email AS user_email
             FROM analysis_jobs job
             INNER JOIN projects p ON p.id = job.project_id
             INNER JOIN users u ON u.id = p.user_id
             ORDER BY job.created_at DESC, job.id DESC LIMIT 100',
        );
        return $statement->fetchAll();
    }

    public function setUserFlags(int $actorId, int $userId, string $action): void
    {
        $allowed = ['make_admin', 'remove_admin', 'suspend', 'restore'];
        if (!in_array($action, $allowed, true)) {
            throw new RuntimeException('That administrator action is not available.');
        }
        if ($actorId === $userId && in_array($action, ['remove_admin', 'suspend'], true)) {
            throw new RuntimeException('You cannot remove or suspend your own administrator access.');
        }

        $pdo = $this->database();
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare('SELECT is_admin, is_suspended FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
            $select->execute(['id' => $userId]);
            $target = $select->fetch();
            if (!is_array($target)) {
                throw new RuntimeException('User not found.');
            }

            $isAdmin = in_array($action, ['make_admin', 'remove_admin'], true) ? $action === 'make_admin' : self::flag($target['is_admin'] ?? false);
            $isSuspended = in_array($action, ['suspend', 'restore'], true) ? $action === 'suspend' : self::flag($target['is_suspended'] ?? false);
            if (self::flag($target['is_admin'] ?? false) && !$isAdmin && $this->activeAdminCount($pdo) <= 1) {
                throw new RuntimeException('Keep at least one active administrator.');
            }
            if (self::flag($target['is_admin'] ?? false) && !self::flag($target['is_suspended'] ?? false) && $isSuspended && $this->activeAdminCount($pdo) <= 1) {
                throw new RuntimeException('Keep at least one active administrator.');
            }

            $update = $pdo->prepare('UPDATE users SET is_admin = :is_admin, is_suspended = :is_suspended WHERE id = :id');
            $update->bindValue(':is_admin', $isAdmin, PDO::PARAM_BOOL);
            $update->bindValue(':is_suspended', $isSuspended, PDO::PARAM_BOOL);
            $update->bindValue(':id', $userId, PDO::PARAM_INT);
            $update->execute();
            $pdo->commit();
            Logger::warning('Administrator changed user flags', ['actor_id' => $actorId, 'user_id' => $userId, 'action' => $action]);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelJob(int $actorId, int $jobId): void
    {
        $pdo = $this->database();
        $pdo->beginTransaction();
        try {
            $lookup = $pdo->prepare('SELECT project_id FROM analysis_jobs WHERE id = :id LIMIT 1 FOR UPDATE');
            $lookup->execute(['id' => $jobId]);
            $projectId = (int) $lookup->fetchColumn();
            if ($projectId < 1) {
                throw new RuntimeException('Scan job not found.');
            }
            $update = $pdo->prepare("UPDATE analysis_jobs SET state = 'failed', error_message = :error, current_stage = :stage, lease_token = NULL, leased_until = NULL, worker_id = NULL, heartbeat_at = CURRENT_TIMESTAMP, finished_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND state IN ('queued', 'running')");
            $update->execute(['error' => 'Cancelled by an administrator.', 'stage' => 'Cancelled by administrator', 'id' => $jobId]);
            if ($update->rowCount() === 1) {
                $project = $pdo->prepare("UPDATE projects SET status = CASE WHEN last_scan_at IS NULL THEN 'failed' ELSE 'ready' END, last_error = :error WHERE id = :id AND NOT EXISTS (SELECT 1 FROM analysis_jobs active WHERE active.project_id = projects.id AND active.state IN ('queued', 'running'))");
                $project->execute(['error' => 'The latest scan was cancelled by an administrator.', 'id' => $projectId]);
            }
            $pdo->commit();
            Logger::warning('Administrator cancelled analysis job', ['actor_id' => $actorId, 'job_id' => $jobId, 'project_id' => $projectId]);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteProject(int $actorId, int $projectId): void
    {
        $statement = $this->database()->prepare('DELETE FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Project not found.');
        }
        Logger::warning('Administrator deleted project', ['actor_id' => $actorId, 'project_id' => $projectId]);
    }

    private function database(): PDO
    {
        return Database::connection();
    }

    private function activeAdminCount(PDO $pdo): int
    {
        $query = Database::isPostgres()
            ? 'SELECT COUNT(*) FROM users WHERE is_admin = TRUE AND is_suspended = FALSE'
            : 'SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_suspended = 0';
        return (int) $pdo->query($query)->fetchColumn();
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || strtolower((string) $value) === 't' || strtolower((string) $value) === 'true';
    }
}
