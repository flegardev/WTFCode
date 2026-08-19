<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__);
$profiles = [];
foreach ([AnalysisProfile::QUICK, AnalysisProfile::DEEP, AnalysisProfile::SECURITY, AnalysisProfile::MAXIMUM] as $profile) {
    if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
    $started = hrtime(true);
    $inspection = (new RepoScanner())->inspect($root, $profile);
    $graph = $inspection['symbol_graph'];
    $findingTypes = [];
    foreach ($inspection['findings'] as $finding) $findingTypes[(string) ($finding['type'] ?? 'unknown')] = ($findingTypes[(string) ($finding['type'] ?? 'unknown')] ?? 0) + 1;
    ksort($findingTypes);
    $services = array_values(array_unique(array_map(static fn (array $symbol): string => (string) $symbol['name'], array_filter($graph['symbols'] ?? [], static fn (array $symbol): bool => ($symbol['type'] ?? '') === 'external_service'))));
    sort($services, SORT_NATURAL | SORT_FLAG_CASE);
    $limitFlags = array_filter($graph['stats'] ?? [], static fn (mixed $value, string $key): bool => (str_contains($key, 'limit') || str_contains($key, 'truncat')) && (int) $value > 0, ARRAY_FILTER_USE_BOTH);
    $profiles[$profile] = [
        'files' => count($inspection['files']), 'symbols' => count($graph['symbols'] ?? []),
        'relationships' => count($graph['relationships'] ?? []), 'routes' => count($graph['routes'] ?? []),
        'features' => count($graph['features'] ?? []), 'findings' => count($inspection['findings']),
        'packages' => count($graph['packages'] ?? []), 'disagreements' => count($graph['disagreements'] ?? []),
        'feature_clusters' => array_map(static fn (array $feature): array => ['label' => $feature['label'], 'score' => $feature['score'], 'symbols' => count($feature['symbols'] ?? []), 'confidence' => $feature['confidence'] ?? 'unknown'], $graph['features'] ?? []),
        'external_services' => $services, 'finding_types' => $findingTypes,
        'secret_findings' => array_sum(array_intersect_key($findingTypes, array_flip(['secret', 'secret_leak', 'hardcoded_secret']))),
        'dependency_vulnerability_findings' => (int) ($findingTypes['dependency_vulnerability'] ?? 0),
        'limit_flags' => $limitFlags,
        'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000), 'peak_php_memory_bytes' => memory_get_peak_usage(true),
        'providers' => array_map(static fn (array $run): array => array_intersect_key($run, array_flip(['engine', 'engine_version', 'status', 'duration_ms', 'symbols', 'relationships', 'routes', 'packages', 'findings', 'cache_hit', 'incremental', 'files_analyzed', 'message'])), $graph['engine_runs'] ?? []),
    ];
}

$pdo = Database::connection();
$token = bin2hex(random_bytes(6));
$userId = null;
$git = [];
try {
    $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)')->execute(['name' => 'V3 dogfood', 'email' => 'dogfood-' . $token . '@wtfcode.local', 'password_hash' => password_hash($token, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)')->execute(['user_id' => $userId, 'name' => 'WTFCode dogfood', 'repository_url' => 'https://github.com/flegardev/WTFCode.git?dogfood=' . $token, 'local_path' => $root, 'status' => 'ready']);
    $projectId = (int) $pdo->lastInsertId();
    foreach ([
        'foundation_to_ast' => ['8ea9276', '8f62122', 'Add AST and semantic analyzers'],
        'ast_to_structural' => ['8f62122', 'f224283', 'Add ast-grep and ripgrep structural analysis'],
        'structural_to_security' => ['f224283', '892b0b5', 'Add Gitleaks integration'],
    ] as $name => [$from, $to, $intent]) {
        $comparison = GitDiffService::compare(['id' => $projectId, 'local_path' => $root], $from, $to, $intent);
        $semantic = $comparison['semantic_delta'] ?? [];
        $git[$name] = [
            'from' => $from, 'to' => $to, 'intent' => $intent, 'summary' => $comparison['summary'] ?? ($comparison['error'] ?? 'unknown'),
            'areas' => array_keys($comparison['groups'] ?? []),
            'symbols_added' => count($comparison['symbol_delta']['added'] ?? []), 'symbols_removed' => count($comparison['symbol_delta']['removed'] ?? []), 'symbols_changed' => count($comparison['symbol_delta']['changed'] ?? []),
            'routes_changed' => count($semantic['routes']['added'] ?? []) + count($semantic['routes']['removed'] ?? []),
            'schema_changed' => count($semantic['schema']['added'] ?? []) + count($semantic['schema']['removed'] ?? []),
            'dependencies_changed' => count($semantic['dependencies']['added'] ?? []) + count($semantic['dependencies']['removed'] ?? []),
            'security_boundaries_changed' => count($semantic['security']['added'] ?? []) + count($semantic['security']['removed'] ?? []),
            'architecture_changed' => count($semantic['architecture']['added'] ?? []) + count($semantic['architecture']['removed'] ?? []),
            'scope_drift' => $comparison['scope_drift'] ?? null,
        ];
    }
} finally {
    if ($userId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
}

echo json_encode(SensitiveDataSanitizer::scrub(['generated_at' => gmdate(DATE_ATOM), 'analysis_version' => AnalysisEngine::VERSION, 'profiles' => $profiles, 'git_dogfood' => $git]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
