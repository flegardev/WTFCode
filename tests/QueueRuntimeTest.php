<?php

declare(strict_types=1);

define('WTF_CODE_TESTING', true);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

function queue_runtime_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = Database::connection();
$token = bin2hex(random_bytes(8));
$ownerId = null;
$otherId = null;

try {
    $ownerId = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)', [
        'name' => 'Queue owner',
        'email' => 'queue-owner-' . $token . '@wtfcode.local',
        'password_hash' => password_hash($token, PASSWORD_DEFAULT),
    ]);
    $otherId = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)', [
        'name' => 'Queue other',
        'email' => 'queue-other-' . $token . '@wtfcode.local',
        'password_hash' => password_hash($token, PASSWORD_DEFAULT),
    ]);

    $pdo->beginTransaction();
    $rolledBackProject = Database::insert('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)', [
        'user_id' => $ownerId,
        'name' => 'Rolled back queue project',
        'repository_url' => 'https://github.com/wtfcode-tests/queue-rollback-' . $token,
        'local_path' => '',
        'status' => 'queued',
    ]);
    AnalysisJobStore::enqueue($rolledBackProject, $ownerId, AnalysisProfile::QUICK, 'initial');
    $pdo->rollBack();
    $rolledBack = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE id = :id');
    $rolledBack->execute(['id' => $rolledBackProject]);
    queue_runtime_assert((int) $rolledBack->fetchColumn() === 0, 'Project and job creation must share the caller transaction.');

    $pdo->beginTransaction();
    $projectId = Database::insert('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)', [
        'user_id' => $ownerId,
        'name' => 'Durable queue project',
        'repository_url' => 'https://github.com/wtfcode-tests/queue-' . $token,
        'local_path' => '',
        'status' => 'queued',
    ]);
    $jobId = AnalysisJobStore::enqueue($projectId, $ownerId, AnalysisProfile::QUICK, 'rescan');
    $pdo->commit();

    $reusedJobId = AnalysisJobStore::enqueue($projectId, $ownerId, AnalysisProfile::MAXIMUM, 'initial');
    queue_runtime_assert($reusedJobId === $jobId, 'Concurrent queue requests must coalesce onto one active job.');
    $job = AnalysisJobStore::find($jobId);
    queue_runtime_assert(($job['analysis_profile'] ?? null) === AnalysisProfile::MAXIMUM, 'A queued job must adopt the latest requested analysis profile.');
    queue_runtime_assert(($job['job_kind'] ?? null) === 'initial', 'An initial import must not be downgraded to a rescan.');

    try {
        Database::insert("INSERT INTO analysis_jobs (project_id, requested_by_user_id, analysis_profile, job_kind, state, max_attempts, available_at, current_stage) VALUES (:project_id, :user_id, :profile, 'rescan', 'queued', 3, CURRENT_TIMESTAMP, 'Duplicate')", [
            'project_id' => $projectId,
            'user_id' => $ownerId,
            'profile' => AnalysisProfile::QUICK,
        ]);
        throw new RuntimeException('The database accepted two active jobs for one project.');
    } catch (PDOException $exception) {
        queue_runtime_assert(Database::isUniqueViolation($exception), 'The active-job database invariant must use a unique constraint.');
    }

    $claimed = AnalysisJobStore::claimNext('queue-runtime-test', 60);
    queue_runtime_assert((int) ($claimed['id'] ?? 0) === $jobId, 'The queued job must be claimable.');
    $leaseToken = (string) ($claimed['lease_token'] ?? '');
    queue_runtime_assert(preg_match('/^[a-f0-9]{64}$/', $leaseToken) === 1, 'A claim must receive an opaque lease token.');
    $staleToken = str_repeat('0', 64);
    queue_runtime_assert(!AnalysisJobStore::heartbeat($jobId, $staleToken, 'stale', 1, 4), 'A stale lease must not heartbeat.');
    queue_runtime_assert(!AnalysisJobStore::associateScanRun($jobId, $staleToken, 999999999), 'A stale lease must not associate evidence.');
    queue_runtime_assert(!AnalysisJobStore::finish($jobId, 'completed', null, $staleToken), 'A stale lease must not finish a job.');

    if (Database::isPostgres()) {
        $pdo->exec("SET TIME ZONE '+05:00'");
    } else {
        $pdo->exec("SET time_zone = '+05:00'");
    }
    queue_runtime_assert(AnalysisJobStore::retry($jobId, $leaseToken, 'Safe retry test.') === 'queued', 'A failed attempt must return to the queue while attempts remain.');
    $delaySql = Database::isPostgres()
        ? 'SELECT EXTRACT(EPOCH FROM (available_at - CURRENT_TIMESTAMP)) FROM analysis_jobs WHERE id = ' . $jobId
        : 'SELECT TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, available_at) FROM analysis_jobs WHERE id = ' . $jobId;
    $delay = (float) $pdo->query($delaySql)->fetchColumn();
    queue_runtime_assert($delay >= 25 && $delay <= 35, 'Retry backoff must use the database clock in every session timezone.');
    if (Database::isPostgres()) {
        $pdo->exec("SET TIME ZONE 'UTC'");
    } else {
        $pdo->exec("SET time_zone = '+00:00'");
    }

    $pdo->prepare('UPDATE analysis_jobs SET available_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $jobId]);
    $claimed = AnalysisJobStore::claimNext('queue-runtime-test', 60);
    queue_runtime_assert((int) ($claimed['id'] ?? 0) === $jobId, 'The retry must be claimable after its backoff.');
    $pdo->prepare("UPDATE projects SET status = 'scanning' WHERE id = :id")->execute(['id' => $projectId]);
    $expiredExpression = Database::isPostgres() ? "CURRENT_TIMESTAMP - INTERVAL '1 second'" : 'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND)';
    $pdo->exec('UPDATE analysis_jobs SET leased_until = ' . $expiredExpression . ' WHERE id = ' . $jobId);
    AnalysisJobStore::recoverExpiredLeases($projectId);
    $retryJob = AnalysisJobStore::find($jobId);
    $retryProject = Project::findForUser($projectId, $ownerId);
    queue_runtime_assert(($retryJob['state'] ?? null) === 'queued', 'An expired lease with attempts left must be requeued.');
    queue_runtime_assert(($retryProject['status'] ?? null) === 'queued', 'An initial project must return to queued when its worker dies.');

    $pdo->prepare('UPDATE analysis_jobs SET available_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $jobId]);
    $claimed = AnalysisJobStore::claimNext('queue-runtime-test', 60);
    queue_runtime_assert((int) ($claimed['attempt_count'] ?? 0) === 3, 'The final claim must consume the configured retry budget.');
    $pdo->prepare("UPDATE projects SET status = 'scanning' WHERE id = :id")->execute(['id' => $projectId]);
    $pdo->exec('UPDATE analysis_jobs SET leased_until = ' . $expiredExpression . ' WHERE id = ' . $jobId);
    AnalysisJobStore::recoverExpiredLeases($projectId);
    $failedJob = AnalysisJobStore::find($jobId);
    $failedProject = Project::findForUser($projectId, $ownerId);
    queue_runtime_assert(($failedJob['state'] ?? null) === 'failed', 'An expired final lease must terminate the job.');
    queue_runtime_assert(($failedProject['status'] ?? null) === 'failed', 'An initial project must not remain stuck after its final lease expires.');
    queue_runtime_assert(AnalysisJobStore::latestForUser($projectId, $otherId) === null, 'Another user must not read queue state.');
    queue_runtime_assert((int) (AnalysisJobStore::latestForUser($projectId, $ownerId)['id'] ?? 0) === $jobId, 'The owner must read the latest queue state.');

    try {
        Database::insert("INSERT INTO analysis_jobs (project_id, requested_by_user_id, analysis_profile, job_kind, state, attempt_count, max_attempts, available_at, current_stage) VALUES (:project_id, :user_id, :profile, 'rescan', 'queued', 3, 3, CURRENT_TIMESTAMP, 'Invalid')", [
            'project_id' => $projectId,
            'user_id' => $ownerId,
            'profile' => AnalysisProfile::QUICK,
        ]);
        throw new RuntimeException('The database accepted an exhausted queued job.');
    } catch (PDOException $exception) {
        $constraintCode = (int) ($exception->errorInfo[1] ?? 0);
        queue_runtime_assert(
            in_array((string) $exception->getCode(), ['23000', '23514'], true) || $constraintCode === 3819,
            'The retry-budget invariant must reject exhausted queued rows.',
        );
    }
} finally {
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (Database::isPostgres()) $pdo->exec("SET TIME ZONE 'UTC'");
        else $pdo->exec("SET time_zone = '+00:00'");
        if ($ownerId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $ownerId]);
        if ($otherId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $otherId]);
    } catch (Throwable) {
    }
}

echo 'WTFCode durable queue runtime checks passed on ' . Database::driver() . '.' . PHP_EOL;
