<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function change_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$repository = realpath(__DIR__ . '/..');
change_assert(is_string($repository), 'Repository path must resolve');
$semantic = new ReflectionMethod(GitDiffService::class, 'semanticDelta');
$delta = $semantic->invoke(null, $repository, '8ea9276', '8f62122', ['composer.json', 'package.json', 'src/Analysis/AnalyzerRegistry.php', 'workers/typescript-semantic.mjs']);
change_assert(isset($delta['dependencies'], $delta['architecture'], $delta['security']), 'Semantic diff must cover dependencies, architecture, and security');
change_assert($delta['dependencies']['added'] !== [] || $delta['dependencies']['removed'] !== [], 'AST engine checkpoint should expose dependency change evidence');

$scope = new ReflectionMethod(GitDiffService::class, 'scopeDrift');
$scopeResult = $scope->invoke(null, 'Add Google login', ['Authentication and access', 'Data and schema', 'Tests'], ['architecture' => []]);
change_assert($scopeResult['label'] === 'Potential scope drift', 'Unexpected database changes must be labeled as potential scope drift');
change_assert(str_contains($scopeResult['note'], 'does not prove'), 'Scope drift must not claim an intent violation as fact');
$alignedScope = $scope->invoke(null, 'Fix SQL table detection', ['Application code', 'Tests'], ['architecture' => []]);
change_assert($alignedScope['label'] === 'No potential scope drift detected', 'Generic application code must not be treated as drift from a specific implementation intent');
$dependencyScope = $scope->invoke(null, 'Update dependencies', ['Application code', 'Configuration and dependencies', 'Tests'], ['architecture' => []]);
change_assert($dependencyScope['label'] === 'No potential scope drift detected', 'Plural dependency intent must map to configuration and dependencies');

$pdo = Database::connection();
$token = bin2hex(random_bytes(6));
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-change-' . $token;
mkdir($directory, 0700, true);
$userId = null;
try {
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'route.php', "<?php\nfunction health() { return ['ok' => true]; }\n");
    $userId = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)', ['name' => 'Change test', 'email' => 'change-' . $token . '@wtfcode.local', 'password_hash' => password_hash($token, PASSWORD_DEFAULT)]);
    $projectId = Database::insert('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)', ['user_id' => $userId, 'name' => 'Change fixture', 'repository_url' => 'https://github.com/wtfcode-change/' . $token . '.git', 'local_path' => $directory, 'status' => 'scanning']);
    (new RepoScanner())->scan($projectId, $directory, AnalysisProfile::QUICK);
    $before = ChangeGuardService::capture($projectId, $userId, 'before', 'before', 'Add status route');
    try {
        ChangeGuardService::capture($projectId, $userId, 'after', 'after', 'Add status route', (int) $before['id']);
        throw new RuntimeException('Change Guard accepted an after snapshot without a later scan.');
    } catch (InvalidArgumentException $exception) {
        change_assert(str_contains($exception->getMessage(), 'new analysis'), 'After capture must explain that a later scan is required');
    }
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'route.php', "<?php\nfunction health() { return ['ok' => true]; }\nfunction status() { return ['status' => 'ready']; }\n");
    (new RepoScanner())->scan($projectId, $directory, AnalysisProfile::QUICK);
    $after = ChangeGuardService::capture($projectId, $userId, 'after', 'after', 'Add status route', (int) $before['id']);
    change_assert($after['pair_key'] === $before['pair_key'], 'Before and after snapshots must persist one explicit pair identity');
    change_assert((int) $after['before_snapshot_id'] === (int) $before['id'], 'After snapshots must reference their exact before snapshot');
    $comparison = ChangeGuardService::latestComparison($projectId, $userId);
    change_assert($comparison !== null, 'Before and After snapshots must produce a comparison');
    change_assert($comparison['pair_key'] === $before['pair_key'], 'Comparison must resolve only the explicitly linked pair');
    change_assert(in_array('critical_symbols', $comparison['changed_kinds'], true), 'Change Guard must detect a new critical function');
    $stored = (string) $pdo->query('SELECT snapshot_json FROM change_guard_snapshots WHERE project_id = ' . $projectId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    change_assert(!preg_match('/github_pat_|sk-proj-|PRIVATE KEY/', $stored), 'Change Guard snapshots must not contain secret-shaped values');
} finally {
    if ($userId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($directory);
}

echo "WTFCode V3 change intelligence checks passed.\n";
