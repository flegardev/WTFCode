<?php

declare(strict_types=1);

final class AnalysisJobStore
{
    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const DEFAULT_LEASE_SECONDS = 3600;

    public static function enqueue(
        int $projectId,
        int $requestedByUserId,
        string $profile,
        string $jobKind = 'rescan',
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
    ): int {
        $pdo = Database::connection();
        $profile = AnalysisProfile::normalize($profile);
        $jobKind = in_array($jobKind, ['initial', 'rescan'], true) ? $jobKind : 'rescan';
        $maxAttempts = max(1, min(10, $maxAttempts));
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            // The projects row is the portable per-project mutex. PostgreSQL's
            // partial unique index and MySQL generated-column unique key are a
            // second line of defence.
            $owner = $pdo->prepare('SELECT id FROM projects WHERE id = :project_id AND user_id = :user_id FOR UPDATE');
            $owner->execute(['project_id' => $projectId, 'user_id' => $requestedByUserId]);
            if (!$owner->fetchColumn()) {
                throw new RuntimeException('Project not found.');
            }
            self::recoverExpiredLeases($projectId);

            $active = $pdo->prepare("SELECT id, state, analysis_profile, job_kind, max_attempts FROM analysis_jobs WHERE project_id = :project_id AND state IN ('queued', 'running') ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $active->execute(['project_id' => $projectId]);
            $activeJob = $active->fetch();
            if (is_array($activeJob)) {
                $activeId = (int) $activeJob['id'];
                if (($activeJob['state'] ?? '') === 'queued') {
                    $update = $pdo->prepare(
                        "UPDATE analysis_jobs
                         SET analysis_profile = :profile,
                             job_kind = CASE WHEN job_kind = 'initial' OR :job_kind = 'initial' THEN 'initial' ELSE 'rescan' END,
                             max_attempts = CASE WHEN max_attempts < :max_attempts THEN :max_attempts_update ELSE max_attempts END,
                             current_stage = :stage,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id AND state = 'queued'",
                    );
                    $update->execute([
                        'profile' => $profile,
                        'job_kind' => $jobKind,
                        'max_attempts' => $maxAttempts,
                        'max_attempts_update' => $maxAttempts,
                        'stage' => 'Waiting for a scan worker',
                        'id' => $activeId,
                    ]);
                } elseif (($activeJob['analysis_profile'] ?? '') !== $profile || ($activeJob['job_kind'] ?? '') !== $jobKind) {
                    Logger::warning('Scan request reused an already running job', [
                        'project_id' => $projectId,
                        'job_id' => $activeId,
                        'running_profile' => $activeJob['analysis_profile'] ?? null,
                        'requested_profile' => $profile,
                    ]);
                }
                if ($ownsTransaction) $pdo->commit();
                return $activeId;
            }

            $jobId = Database::insert(
                'INSERT INTO analysis_jobs (project_id, requested_by_user_id, analysis_profile, job_kind, state, max_attempts, available_at, current_stage, progress_current, progress_total) VALUES (:project_id, :user_id, :profile, :job_kind, :state, :max_attempts, CURRENT_TIMESTAMP, :stage, 0, 4)',
                [
                    'project_id' => $projectId,
                    'user_id' => $requestedByUserId,
                    'profile' => $profile,
                    'job_kind' => $jobKind,
                    'state' => 'queued',
                    'max_attempts' => $maxAttempts,
                    'stage' => 'Waiting for a scan worker',
                ],
            );
            if ($ownsTransaction) $pdo->commit();
            return $jobId;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** Compatibility path for direct, synchronous scanner calls. */
    public static function create(int $projectId, string $profile, ?string $commit, ?string $previousCommit, array $changedPaths): int
    {
        self::recoverExpiredLeases($projectId);
        $owner = Database::connection()->prepare('SELECT user_id FROM projects WHERE id = :project_id LIMIT 1');
        $owner->execute(['project_id' => $projectId]);
        $userId = $owner->fetchColumn();
        if ($userId === false) {
            throw new RuntimeException('Project not found.');
        }

        return Database::insert(
            'INSERT INTO analysis_jobs (project_id, requested_by_user_id, analysis_profile, job_kind, state, commit_sha, previous_commit_sha, changed_paths_json, available_at, current_stage, progress_current, progress_total) VALUES (:project_id, :user_id, :profile, :job_kind, :state, :commit, :previous, :paths, CURRENT_TIMESTAMP, :stage, 0, 4)',
            [
                'project_id' => $projectId,
                'user_id' => (int) $userId,
                'profile' => AnalysisProfile::normalize($profile),
                'job_kind' => 'rescan',
                'state' => 'queued',
                'commit' => $commit,
                'previous' => $previousCommit,
                'paths' => self::pathsJson($changedPaths),
                'stage' => 'Starting analysis',
            ],
        );
    }

    /** @return array<string, mixed>|null */
    public static function claimNext(string $workerId, int $leaseSeconds = self::DEFAULT_LEASE_SECONDS): ?array
    {
        self::recoverExpiredLeases();
        $pdo = Database::connection();
        if ($pdo->inTransaction()) {
            throw new LogicException('Scan jobs must be claimed outside an existing transaction.');
        }

        $workerId = substr((string) (preg_replace('/[^A-Za-z0-9_.:-]/', '-', trim($workerId)) ?: 'worker'), 0, 100);
        $leaseSeconds = max(60, min(7200, $leaseSeconds));
        $leaseToken = bin2hex(random_bytes(32));
        $leaseExpression = self::leaseExpression($leaseSeconds);

        $pdo->beginTransaction();
        try {
            $claim = $pdo->query("SELECT * FROM analysis_jobs WHERE state = 'queued' AND available_at <= CURRENT_TIMESTAMP AND attempt_count < max_attempts ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED");
            $job = $claim->fetch();
            if (!$job) {
                $pdo->commit();
                return null;
            }

            $update = $pdo->prepare("UPDATE analysis_jobs SET state = 'running', attempt_count = attempt_count + 1, lease_token = :lease_token, leased_until = $leaseExpression, heartbeat_at = CURRENT_TIMESTAMP, worker_id = :worker_id, current_stage = :stage, error_message = NULL, started_at = COALESCE(started_at, CURRENT_TIMESTAMP), finished_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND state = 'queued'");
            $update->execute([
                'lease_token' => $leaseToken,
                'worker_id' => $workerId,
                'stage' => 'Preparing repository',
                'id' => (int) $job['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The queued analysis could not be claimed.');
            }

            $read = $pdo->prepare('SELECT * FROM analysis_jobs WHERE id = :id');
            $read->execute(['id' => (int) $job['id']]);
            $claimed = $read->fetch() ?: null;
            $pdo->commit();
            return $claimed;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function running(int $jobId): void
    {
        $statement = Database::connection()->prepare("UPDATE analysis_jobs SET state = 'running', current_stage = :stage, started_at = COALESCE(started_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $statement->execute(['stage' => 'Inspecting repository', 'id' => $jobId]);
    }

    public static function heartbeat(
        int $jobId,
        string $leaseToken,
        string $stage,
        int $progressCurrent,
        int $progressTotal,
        int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
    ): bool {
        $progressCurrent = max(0, $progressCurrent);
        $progressTotal = max($progressCurrent, $progressTotal);
        $stage = substr(trim($stage) === '' ? 'Analyzing repository' : trim($stage), 0, 120);
        $leaseExpression = self::leaseExpression($leaseSeconds);
        $statement = Database::connection()->prepare("UPDATE analysis_jobs SET heartbeat_at = CURRENT_TIMESTAMP, leased_until = $leaseExpression, current_stage = :stage, progress_current = :progress_current, progress_total = :progress_total, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND state = 'running' AND lease_token = :lease_token AND (leased_until IS NULL OR leased_until >= CURRENT_TIMESTAMP)");
        $statement->execute([
            'stage' => $stage,
            'progress_current' => $progressCurrent,
            'progress_total' => $progressTotal,
            'id' => $jobId,
            'lease_token' => $leaseToken,
        ]);
        return $statement->rowCount() === 1;
    }

    public static function scanMetadata(int $jobId, ?string $leaseToken, ?string $commit, ?string $previousCommit, array $changedPaths): bool
    {
        $sql = 'UPDATE analysis_jobs SET commit_sha = :commit, previous_commit_sha = :previous, changed_paths_json = :paths, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $parameters = ['commit' => $commit, 'previous' => $previousCommit, 'paths' => self::pathsJson($changedPaths), 'id' => $jobId];
        if ($leaseToken !== null) {
            $sql .= " AND state = 'running' AND lease_token = :lease_token";
            $parameters['lease_token'] = $leaseToken;
        }
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount() === 1;
    }

    public static function associateScanRun(int $jobId, ?string $leaseToken, int $scanRunId): bool
    {
        $sql = 'UPDATE analysis_jobs SET scan_run_id = :scan_run_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $parameters = ['scan_run_id' => $scanRunId, 'id' => $jobId];
        if ($leaseToken !== null) {
            $sql .= " AND state = 'running' AND lease_token = :lease_token";
            $parameters['lease_token'] = $leaseToken;
        }
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount() === 1;
    }

    public static function finish(int $jobId, string $state, ?string $error = null, ?string $leaseToken = null): bool
    {
        $state = in_array($state, ['completed', 'partial', 'failed'], true) ? $state : 'failed';
        $stage = match ($state) {
            'completed' => 'Analysis complete',
            'partial' => 'Analysis complete with limitations',
            default => 'Analysis failed',
        };
        $sql = "UPDATE analysis_jobs SET state = :state, error_message = :error, current_stage = :stage, progress_current = CASE WHEN progress_total > 0 AND :successful = 1 THEN progress_total ELSE progress_current END, lease_token = NULL, leased_until = NULL, heartbeat_at = CURRENT_TIMESTAMP, worker_id = NULL, finished_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        $parameters = [
            'state' => $state,
            'error' => self::safeError($error),
            'stage' => $stage,
            'successful' => in_array($state, ['completed', 'partial'], true) ? 1 : 0,
            'id' => $jobId,
        ];
        if ($leaseToken !== null) {
            $sql .= " AND state = 'running' AND lease_token = :lease_token";
            $parameters['lease_token'] = $leaseToken;
        }
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount() === 1;
    }

    /** Returns queued, failed, or null when this worker no longer owns the job. */
    public static function retry(int $jobId, string $leaseToken, string $error): ?string
    {
        $pdo = Database::connection();
        if ($pdo->inTransaction()) {
            throw new LogicException('A scan retry must be scheduled outside an existing transaction.');
        }

        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare("SELECT attempt_count, max_attempts FROM analysis_jobs WHERE id = :id AND state = 'running' AND lease_token = :lease_token FOR UPDATE");
            $select->execute(['id' => $jobId, 'lease_token' => $leaseToken]);
            $job = $select->fetch();
            if (!$job) {
                $pdo->commit();
                return null;
            }

            $willRetry = (int) $job['attempt_count'] < (int) $job['max_attempts'];
            $state = $willRetry ? 'queued' : 'failed';
            $delay = $willRetry ? min(900, 30 * (2 ** max(0, (int) $job['attempt_count'] - 1))) : 0;
            $availableExpression = self::availabilityExpression($delay);
            $update = $pdo->prepare("UPDATE analysis_jobs SET state = :state, error_message = :error, current_stage = :stage, available_at = $availableExpression, lease_token = NULL, leased_until = NULL, heartbeat_at = CURRENT_TIMESTAMP, worker_id = NULL, finished_at = CASE WHEN :terminal = 1 THEN CURRENT_TIMESTAMP ELSE NULL END, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND state = 'running' AND lease_token = :lease_token");
            $update->execute([
                'state' => $state,
                'error' => self::safeError($error),
                'stage' => $willRetry ? 'Retry scheduled' : 'Analysis failed',
                'terminal' => $willRetry ? 0 : 1,
                'id' => $jobId,
                'lease_token' => $leaseToken,
            ]);
            $pdo->commit();
            return $state;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<int, array<string, mixed>> $runs */
    public static function steps(int $jobId, array $runs): void
    {
        $sql = Database::isPostgres()
            ? 'INSERT INTO analysis_job_steps (job_id, provider_id, provider_version, state, cache_hit, incremental, files_analyzed, duration_ms, message, finished_at) VALUES (:job_id, :provider, :version, :state, :cache_hit, :incremental, :files, :duration, :message, CURRENT_TIMESTAMP) ON CONFLICT (job_id, provider_id) DO UPDATE SET provider_version = EXCLUDED.provider_version, state = EXCLUDED.state, cache_hit = EXCLUDED.cache_hit, incremental = EXCLUDED.incremental, files_analyzed = EXCLUDED.files_analyzed, duration_ms = EXCLUDED.duration_ms, message = EXCLUDED.message, finished_at = EXCLUDED.finished_at'
            : 'INSERT INTO analysis_job_steps (job_id, provider_id, provider_version, state, cache_hit, incremental, files_analyzed, duration_ms, message, finished_at) VALUES (:job_id, :provider, :version, :state, :cache_hit, :incremental, :files, :duration, :message, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE provider_version = VALUES(provider_version), state = VALUES(state), cache_hit = VALUES(cache_hit), incremental = VALUES(incremental), files_analyzed = VALUES(files_analyzed), duration_ms = VALUES(duration_ms), message = VALUES(message), finished_at = VALUES(finished_at)';
        $statement = Database::connection()->prepare($sql);
        foreach ($runs as $run) {
            $state = match ($run['status'] ?? '') {
                'success' => 'completed',
                'partial', 'unavailable' => 'partial',
                default => 'failed',
            };
            $statement->execute([
                'job_id' => $jobId,
                'provider' => substr((string) ($run['engine'] ?? 'unknown'), 0, 80),
                'version' => substr((string) ($run['engine_version'] ?? 'unknown'), 0, 100),
                'state' => $state,
                'cache_hit' => !empty($run['cache_hit']) ? 1 : 0,
                'incremental' => !empty($run['incremental']) ? 1 : 0,
                'files' => max(0, (int) ($run['files_analyzed'] ?? 0)),
                'duration' => max(0, (int) ($run['duration_ms'] ?? 0)),
                'message' => isset($run['message']) ? substr((string) $run['message'], 0, 500) : null,
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    public static function latest(int $projectId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM analysis_jobs WHERE project_id = :project_id ORDER BY id DESC LIMIT 1');
        $statement->execute(['project_id' => $projectId]);
        return self::withSteps($statement->fetch() ?: null);
    }

    /** @return array<string, mixed>|null */
    public static function latestForUser(int $projectId, int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT job.*
             FROM analysis_jobs job
             INNER JOIN projects project ON project.id = job.project_id AND project.user_id = :user_id
             WHERE job.project_id = :project_id
             ORDER BY job.id DESC
             LIMIT 1',
        );
        $statement->execute(['project_id' => $projectId, 'user_id' => $userId]);

        return self::withSteps($statement->fetch() ?: null);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $jobId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM analysis_jobs WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $jobId]);
        return self::withSteps($statement->fetch() ?: null);
    }

    public static function recoverExpiredLeases(?int $projectId = null): void
    {
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();

        $legacyCutoff = Database::isPostgres()
            ? "CURRENT_TIMESTAMP - INTERVAL '15 minutes'"
            : 'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)';
        $filter = $projectId === null ? '' : ' AND project_id = :project_id';
        $parameters = $projectId === null ? [] : ['project_id' => $projectId];

        try {
            $candidates = $pdo->prepare(
                "SELECT DISTINCT project_id
                 FROM analysis_jobs
                 WHERE (
                     (state = 'running' AND ((lease_token IS NULL AND created_at < $legacyCutoff) OR (leased_until IS NOT NULL AND leased_until < CURRENT_TIMESTAMP)))
                     OR (state = 'queued' AND attempt_count >= max_attempts)
                 )$filter
                 ORDER BY project_id
                 LIMIT 100",
            );
            $candidates->execute($parameters);
            $projectIds = array_values(array_filter(array_map('intval', $candidates->fetchAll(PDO::FETCH_COLUMN)), static fn(int $id): bool => $id > 0));
            if ($projectIds === []) {
                if ($ownsTransaction) $pdo->commit();
                return;
            }

            $placeholders = [];
            $projectParameters = [];
            foreach ($projectIds as $index => $id) {
                $key = 'recovery_project_' . $index;
                $placeholders[] = ':' . $key;
                $projectParameters[$key] = $id;
            }
            $projectList = implode(', ', $placeholders);
            $lock = $pdo->prepare("SELECT id FROM projects WHERE id IN ($projectList) ORDER BY id FOR UPDATE");
            $lock->execute($projectParameters);
            $lock->fetchAll();

            $legacy = $pdo->prepare("UPDATE analysis_jobs SET state = 'failed', error_message = :error, current_stage = :stage, lease_token = NULL, leased_until = NULL, worker_id = NULL, finished_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE project_id IN ($projectList) AND state = 'running' AND lease_token IS NULL AND created_at < $legacyCutoff");
            $legacy->execute(['error' => 'The scan worker stopped before analysis could finish.', 'stage' => 'Analysis failed'] + $projectParameters);

            $exhausted = $pdo->prepare("UPDATE analysis_jobs SET state = 'failed', error_message = :error, current_stage = :stage, lease_token = NULL, leased_until = NULL, worker_id = NULL, finished_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE project_id IN ($projectList) AND ((state = 'running' AND leased_until IS NOT NULL AND leased_until < CURRENT_TIMESTAMP AND attempt_count >= max_attempts) OR (state = 'queued' AND attempt_count >= max_attempts))");
            $exhausted->execute(['error' => 'The scan worker stopped after the final attempt.', 'stage' => 'Analysis failed'] + $projectParameters);

            $retry = $pdo->prepare("UPDATE analysis_jobs SET state = 'queued', error_message = :error, current_stage = :stage, available_at = CURRENT_TIMESTAMP, lease_token = NULL, leased_until = NULL, worker_id = NULL, finished_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE project_id IN ($projectList) AND state = 'running' AND leased_until IS NOT NULL AND leased_until < CURRENT_TIMESTAMP AND attempt_count < max_attempts");
            $retry->execute(['error' => 'The previous scan worker stopped responding; retry queued.', 'stage' => 'Retry scheduled'] + $projectParameters);

            $latest = $pdo->prepare(
                "SELECT project.id, project.last_scan_at,
                        (SELECT job.state FROM analysis_jobs job WHERE job.project_id = project.id ORDER BY job.id DESC LIMIT 1) AS job_state
                 FROM projects project
                 WHERE project.id IN ($projectList)",
            );
            $latest->execute($projectParameters);
            $reconcile = $pdo->prepare('UPDATE projects SET status = :status, last_error = :error WHERE id = :id');
            foreach ($latest->fetchAll() as $project) {
                $hasEvidence = !empty($project['last_scan_at']);
                $active = in_array($project['job_state'] ?? '', ['queued', 'running'], true);
                $reconcile->execute([
                    'status' => $hasEvidence ? 'ready' : ($active ? 'queued' : 'failed'),
                    'error' => $active
                        ? 'The previous scan worker stopped responding; retry queued.'
                        : ($hasEvidence ? 'The latest rescan did not finish. Previous evidence is still available.' : 'The scan worker stopped before analysis could finish.'),
                    'id' => (int) $project['id'],
                ]);
            }

            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    /** @param array<string, mixed>|null $job @return array<string, mixed>|null */
    private static function withSteps(?array $job): ?array
    {
        if ($job === null) {
            return null;
        }
        $steps = Database::connection()->prepare('SELECT * FROM analysis_job_steps WHERE job_id = :job_id ORDER BY id');
        $steps->execute(['job_id' => (int) $job['id']]);
        $job['steps'] = $steps->fetchAll();
        return $job;
    }

    private static function pathsJson(array $changedPaths): string
    {
        return (string) json_encode(
            array_slice(array_values(array_unique(array_filter($changedPaths, 'is_string'))), 0, 1000),
            JSON_UNESCAPED_SLASHES,
        );
    }

    private static function safeError(?string $error): ?string
    {
        if ($error === null || trim($error) === '') {
            return null;
        }
        return substr(SensitiveDataSanitizer::text($error), 0, 500);
    }

    private static function leaseExpression(int $leaseSeconds): string
    {
        $seconds = max(60, min(7200, $leaseSeconds));

        return Database::isPostgres()
            ? "CURRENT_TIMESTAMP + INTERVAL '{$seconds} seconds'"
            : "DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$seconds} SECOND)";
    }

    private static function availabilityExpression(int $delaySeconds): string
    {
        $seconds = max(0, min(3600, $delaySeconds));

        return Database::isPostgres()
            ? "CURRENT_TIMESTAMP + INTERVAL '{$seconds} seconds'"
            : "DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$seconds} SECOND)";
    }
}
