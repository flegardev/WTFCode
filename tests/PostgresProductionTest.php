<?php

declare(strict_types=1);

define('WTF_CODE_TESTING', true);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

function production_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = Database::connection();
production_assert(Database::isPostgres(), 'Production verification requires PostgreSQL.');

$rlsTables = $pdo->query("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relrowsecurity = true")->fetchAll(PDO::FETCH_COLUMN);
foreach (['users', 'projects', 'github_installations', 'sessions', 'login_attempts', 'provider_cache_entries'] as $table) {
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
    $installation = GitHubInstallationRepository::saveForUser($owner, ['id' => random_int(1000000, 2000000000), 'account' => ['id' => random_int(1000000, 2000000000), 'login' => 'owner-' . $token, 'type' => 'User'], 'repository_selection' => 'selected', 'permissions' => ['contents' => 'read']]);
    production_assert(GitHubInstallationRepository::findForUser((int) $installation['id'], $owner) !== null, 'The connection owner must load its GitHub installation.');
    production_assert(GitHubInstallationRepository::findForUser((int) $installation['id'], $other) === null, 'Another account must not load the GitHub installation.');
    $privateKey = 'test-private-key-not-used-by-mock-transport';
    $issuedTokens = [];
    $revokedTokens = [];
    $removedRepository = false;
    $revokedInstallation = false;
    GitHubAppService::configureForTests(
        static function (string $method, string $url, ?string $bearer, ?array $body) use (&$issuedTokens, &$revokedTokens, &$removedRepository, &$revokedInstallation): array {
            if ($method === 'POST' && str_contains($url, '/access_tokens')) {
                if ($revokedInstallation) throw new GitHubAccessException('not found', 'mock revoked installation', 'not_found');
                $newToken = 'ghs_test_' . bin2hex(random_bytes(16));
                $issuedTokens[] = $newToken;
                return ['token' => $newToken, 'expires_at' => gmdate(DATE_ATOM, time() + 3600)];
            }
            if ($method === 'GET' && str_contains($url, '/repositories/')) {
                if ($removedRepository) throw new GitHubAccessException('not found', 'mock removed repository', 'not_found');
                production_assert(in_array($bearer, $issuedTokens, true), 'Repository API requests must use a freshly issued installation token.');
                return ['id' => 99112233, 'name' => 'PrivateFixture', 'full_name' => 'example/PrivateFixture', 'private' => true, 'visibility' => 'private', 'default_branch' => 'main', 'owner' => ['login' => 'example']];
            }
            if ($method === 'DELETE' && str_ends_with($url, '/installation/token')) {
                $revokedTokens[] = (string) $bearer;
                return [];
            }
            throw new RuntimeException('Unexpected mock GitHub request.');
        },
        [
            'environment' => 'test',
            'github_app_id' => '12345',
            'github_app_slug' => 'wtfcode-test',
            'github_app_client_id' => 'Iv1.test',
            'github_app_client_secret' => 'test-secret',
            'github_app_private_key' => $privateKey,
            'github_app_callback_url' => 'https://example.test/github-callback.php',
        ],
    );
    $firstToken = GitHubAppService::withRepositoryAccess($owner, (int) $installation['id'], 99112233, static fn (array $repository, string $installationToken): string => $installationToken);
    $secondToken = GitHubAppService::withRepositoryAccess($owner, (int) $installation['id'], 99112233, static fn (array $repository, string $installationToken): string => $installationToken);
    production_assert($firstToken !== $secondToken, 'Each private repository operation must receive a fresh installation token.');
    production_assert($revokedTokens === [$firstToken, $secondToken], 'Each short-lived installation token must be revoked after use.');
    try {
        GitHubAppService::withRepositoryAccess($other, (int) $installation['id'], 99112233, static fn (): null => null);
        throw new RuntimeException('Another user unexpectedly used the installation.');
    } catch (GitHubAccessException $exception) {
        production_assert($exception->reason === 'installation_not_found', 'Cross-owner installation use must fail at the owner-scoped lookup.');
    }
    $removedRepository = true;
    try {
        GitHubAppService::withRepositoryAccess($owner, (int) $installation['id'], 99112233, static fn (): null => null);
        throw new RuntimeException('A removed repository unexpectedly remained authorized.');
    } catch (GitHubAccessException $exception) {
        production_assert($exception->reason === 'repository_not_granted', 'Removed repository access must return a deliberate permission error.');
    }
    $removedRepository = false;
    $revokedInstallation = true;
    try {
        GitHubAppService::withRepositoryAccess($owner, (int) $installation['id'], 99112233, static fn (): null => null);
        throw new RuntimeException('A revoked installation unexpectedly remained authorized.');
    } catch (GitHubAccessException $exception) {
        production_assert($exception->reason === 'authorization_revoked', 'Revoked installations must request reconnection.');
    }
    GitHubAppService::resetTests();
    $tokenColumns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name IN ('github_installations', 'projects') AND column_name ILIKE '%token%'")->fetchAll(PDO::FETCH_COLUMN);
    production_assert($tokenColumns === [], 'GitHub installation access tokens must not have a persistence column.');
    $project = Database::insert('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)', ['user_id' => $owner, 'name' => 'Ownership test', 'repository_url' => 'https://github.com/octocat/Hello-World', 'local_path' => '', 'status' => 'ready']);
    production_assert(Project::findForUser($project, $owner) !== null, 'The owner must be able to load the project.');
    production_assert(Project::findForUser($project, $other) === null, 'Another account must not load the project.');
    Database::insert("INSERT INTO analysis_jobs (project_id, analysis_profile, state, changed_paths_json, created_at) VALUES (:project_id, :profile, :state, :paths, CURRENT_TIMESTAMP - INTERVAL '20 minutes')", ['project_id' => $project, 'profile' => 'quick', 'state' => 'running', 'paths' => '[]']);
    AnalysisJobStore::recoverExpiredLeases($project);
    $staleJob = AnalysisJobStore::latest($project);
    production_assert(($staleJob['state'] ?? null) === 'failed', 'Stale hosted jobs must not remain permanently running.');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

fwrite(STDOUT, "PostgreSQL production state, RLS, sessions, throttling, and ownership checks passed.\n");
