<?php

declare(strict_types=1);

define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
function entitlement_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$pdo = Database::connection();
$owner = null;
try {
    $owner = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :hash)', ['name' => 'Quota fixture', 'email' => bin2hex(random_bytes(8)) . '@quota.local', 'hash' => 'not-a-login']);
    $pdo->beginTransaction();
    Entitlements::requireProjectSlot($owner);
    $project = Database::insert("INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:owner, 'Quota fixture', 'https://github.com/example/quota.git', '', 'queued')", ['owner' => $owner]);
    $job = AnalysisJobStore::enqueue($project, $owner, 'quick', 'initial');
    $pdo->commit();
    entitlement_assert(AnalysisJobStore::enqueue($project, $owner, 'quick', 'initial') === $job, 'Duplicate requests must consume one scan, not two.');
    entitlement_assert(Entitlements::usage($owner)['scans'] === 1, 'Queued work counts against the budget.');
    $pdo->beginTransaction();
    try {
        Entitlements::requireProjectSlot($owner);
        throw new RuntimeException('A second project was allowed.');
    } catch (DomainException) {
        $pdo->rollBack();
    }
    for ($i = 1; $i <= 5; $i++) {
        $pdo->prepare("UPDATE analysis_jobs SET state = 'failed', finished_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['id' => $job]);
        if ($i < 5) $job = AnalysisJobStore::enqueue($project, $owner, 'quick');
    }
    try {
        AnalysisJobStore::enqueue($project, $owner, 'quick');
        throw new RuntimeException('A sixth scan was allowed.');
    } catch (DomainException) {
        entitlement_assert(!$pdo->inTransaction(), 'Rejected enqueue must roll back.');
    }
    $pdo->prepare("UPDATE analysis_jobs SET created_at = CURRENT_TIMESTAMP - INTERVAL '31 days' WHERE requested_by_user_id = :id")->execute(['id' => $owner]);
    entitlement_assert(Entitlements::usage($owner)['scans'] === 0, 'Budget expires on the database clock.');
    $job = AnalysisJobStore::enqueue($project, $owner, 'quick');
    entitlement_assert($job > 0, 'A new scan is allowed after expiry.');
    $pdo->prepare('UPDATE users SET is_suspended = true WHERE id = :id')->execute(['id' => $owner]);
    try {
        AnalysisJobStore::enqueue($project, $owner, 'quick');
        throw new RuntimeException('A suspended account queued work.');
    } catch (DomainException) { /* Expected, including when an active job exists. */ }
    $_SESSION = ['user' => ['id' => $owner], 'csrf_token' => 'stale-token'];
    entitlement_assert(!Auth::isAdmin() && Auth::user() === null && !isset($_SESSION['csrf_token']), 'A suspended account must lose cached identity and CSRF state.');
    $_SESSION = ['user' => ['id' => 2147483647], 'csrf_token' => 'deleted-user-token'];
    entitlement_assert(!Auth::isAdmin() && Auth::user() === null, 'A deleted account must not remain authenticated in memory.');
    $invalid = Auth::register('Invalid password fixture', 'long-password@quota.local', str_repeat('a', 73), str_repeat('a', 73));
    entitlement_assert(isset($invalid['password']), 'Oversized bcrypt input must produce a validation error, not a server error.');
    echo "Entitlements: project cap, scan cap, coalescing, rolling expiry, rollback and suspension passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($owner !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $owner]);
}
