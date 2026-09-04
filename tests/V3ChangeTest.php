<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function change_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function change_git(string $directory, array $arguments): string
{
    $result = (new SafeProcessRunner())->run(new ProcessRunRequest(array_merge(['git', '-C', $directory], $arguments), $directory, 20, 1_048_576, 262_144));
    if (!$result->succeeded()) throw new RuntimeException('The isolated Git fixture command failed.');

    return trim($result->stdout);
}

function change_remove_directory(string $directory): void
{
    $temporaryRoot = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/');
    $normalized = str_replace('\\', '/', $directory);
    $allowed = str_starts_with($normalized, $temporaryRoot . '/wtfcode-diff-')
        || str_starts_with($normalized, $temporaryRoot . '/wtfcode-change-');
    if (!$allowed || !is_dir($directory)) return;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @chmod($item->getPathname(), 0700);
            @rmdir($item->getPathname());
        } else {
            @chmod($item->getPathname(), 0600);
            @unlink($item->getPathname());
        }
    }
    @chmod($directory, 0700);
    @rmdir($directory);
}

$diffDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-diff-' . bin2hex(random_bytes(6));
mkdir($diffDirectory, 0700, true);
try {
    change_git($diffDirectory, ['init', '--quiet']);
    change_git($diffDirectory, ['config', 'user.name', 'WTFCode test']);
    change_git($diffDirectory, ['config', 'user.email', 'test@wtfcode.local']);
    file_put_contents($diffDirectory . DIRECTORY_SEPARATOR . 'composer.json', "{\n  \"require\": {\n    \"php\": \"^8.3\"\n  }\n}\n");
    change_git($diffDirectory, ['add', 'composer.json']);
    change_git($diffDirectory, ['commit', '--quiet', '-m', 'baseline dependencies']);
    $fromRevision = change_git($diffDirectory, ['rev-parse', 'HEAD']);

    file_put_contents($diffDirectory . DIRECTORY_SEPARATOR . 'composer.json', "{\n  \"require\": {\n    \"php\": \"^8.3\",\n    \"nikic/php-parser\": \"^5.0\"\n  }\n}\n");
    change_git($diffDirectory, ['add', 'composer.json']);
    change_git($diffDirectory, ['commit', '--quiet', '-m', 'add parser dependency']);
    $toRevision = change_git($diffDirectory, ['rev-parse', 'HEAD']);

    $semantic = new ReflectionMethod(GitDiffService::class, 'semanticDelta');
    $delta = $semantic->invoke(null, $diffDirectory, $fromRevision, $toRevision, ['composer.json']);
    change_assert(isset($delta['dependencies'], $delta['architecture'], $delta['security']), 'Semantic diff must cover dependencies, architecture, and security');
    change_assert($delta['dependencies']['added'] !== [] || $delta['dependencies']['removed'] !== [], 'AST engine checkpoint should expose dependency change evidence');
} finally {
    change_remove_directory($diffDirectory);
}

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
    mkdir($directory . DIRECTORY_SEPARATOR . 'routes', 0700, true);
    mkdir($directory . DIRECTORY_SEPARATOR . 'src', 0700, true);
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'billing.ts', "import { BillingService } from '../src/BillingService';\nrouter.post('/billing/invoices', BillingService.createInvoice);\n");
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'BillingService.ts', "export class BillingService {\n  createInvoice() {\n    const stripe = new StripeClient();\n    return stripe.invoices.create({ customer: 'runtime' });\n  }\n}\n");
    $userId = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)', ['name' => 'Change test', 'email' => 'change-' . $token . '@wtfcode.local', 'password_hash' => password_hash($token, PASSWORD_DEFAULT)]);
    $projectId = Database::insert('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)', ['user_id' => $userId, 'name' => 'Change fixture', 'repository_url' => 'https://github.com/wtfcode-change/' . $token . '.git', 'local_path' => $directory, 'status' => 'scanning']);
    (new RepoScanner())->scan($projectId, $directory, AnalysisProfile::QUICK);
    $changeImpact = new \WTFCode\Application\ChangeImpactService();
    $symbolImpact = $changeImpact->forTarget($projectId, 'BillingService', 'symbol');
    change_assert(in_array('Stripe', array_column($symbolImpact['services'], 'name'), true), 'Symbol impact must aggregate external services reached through blast-radius evidence');
    change_assert($symbolImpact['risk']['level'] === 'high', 'External-service boundaries must escalate change risk');
    change_assert((bool) array_filter($symbolImpact['recommendations'], static fn(string $item): bool => str_contains($item, 'timeout')), 'External-service impact must recommend timeout and retry verification');

    $fileImpact = $changeImpact->forTarget($projectId, 'routes/billing.ts', 'file');
    change_assert($fileImpact['target_type'] === 'file' && $fileImpact['files'] !== [], 'File targets must resolve as files instead of generic feature searches');
    change_assert($fileImpact['routes'] !== [], 'A route file target must retain its affected HTTP route');

    $routeImpact = $changeImpact->forTarget($projectId, 'POST /billing/invoices', 'route');
    change_assert($routeImpact['target_type'] === 'route' && count($routeImpact['routes']) === 1, 'Method-qualified route targets must resolve only the selected route');
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
    change_remove_directory($directory);
}

echo "WTFCode V3 change intelligence checks passed.\n";
