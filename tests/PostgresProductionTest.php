<?php

declare(strict_types=1);

define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

function production_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = Database::connection();
production_assert(Database::isPostgres(), 'Production verification requires PostgreSQL.');

$rlsTables = $pdo->query("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relrowsecurity = true")->fetchAll(PDO::FETCH_COLUMN);
foreach (['users', 'projects', 'sessions', 'login_attempts', 'provider_cache_entries'] as $table) {
    production_assert(in_array($table, $rlsTables, true), 'RLS must be enabled on ' . $table . '.');
}

$sessionId = 'test-' . bin2hex(random_bytes(16));
$sessions = new DatabaseSessionHandler(1800);
production_assert($sessions->open('', 'wtfcode_session'), 'Database session handler must open.');
production_assert($sessions->write($sessionId, 'user_id|i:42;'), 'Database session write must succeed.');
production_assert($sessions->read($sessionId) === 'user_id|i:42;', 'Database session data must round-trip.');
production_assert($sessions->destroy($sessionId), 'Database session destroy must succeed.');
production_assert($sessions->close(), 'Database session handler must close and release locks.');

$rateKey = hash('sha256', 'production-test-' . random_bytes(16));
for ($attempt = 0; $attempt < 5; $attempt++) LoginRateLimiter::failed($rateKey);
production_assert(LoginRateLimiter::blocked($rateKey), 'Five failures must activate login throttling.');
LoginRateLimiter::clear($rateKey);
production_assert(!LoginRateLimiter::blocked($rateKey), 'Successful-login cleanup must clear throttling state.');

$pdo->beginTransaction();
try {
    $token = bin2hex(random_bytes(8));
    $owner = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)', ['name' => 'Production owner', 'email' => 'owner-' . $token . '@wtfcode.local', 'password_hash' => password_hash($token, PASSWORD_DEFAULT)]);
    $other = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)', ['name' => 'Other owner', 'email' => 'other-' . $token . '@wtfcode.local', 'password_hash' => password_hash($token, PASSWORD_DEFAULT)]);
    $project = Database::insert('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)', ['user_id' => $owner, 'name' => 'Ownership test', 'repository_url' => 'https://github.com/octocat/Hello-World', 'local_path' => '', 'status' => 'ready']);
    production_assert(Project::findForUser($project, $owner) !== null, 'The owner must be able to load the project.');
    production_assert(Project::findForUser($project, $other) === null, 'Another account must not load the project.');
    Database::insert("INSERT INTO analysis_jobs (project_id, analysis_profile, state, changed_paths_json, created_at) VALUES (:project_id, :profile, :state, :paths, CURRENT_TIMESTAMP - INTERVAL '20 minutes')", ['project_id' => $project, 'profile' => 'quick', 'state' => 'running', 'paths' => '[]']);
    $staleJob = AnalysisJobStore::latest($project);
    production_assert(($staleJob['state'] ?? null) === 'failed', 'Stale hosted jobs must not remain permanently running.');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

fwrite(STDOUT, "PostgreSQL production state, RLS, sessions, throttling, and ownership checks passed.\n");
