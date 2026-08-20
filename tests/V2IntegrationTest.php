<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function integration_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = Database::connection();
$token = bin2hex(random_bytes(6));
$email = 'v2-' . $token . '@wtfcode.local';
$fixture = __DIR__ . '/fixtures/v2/laravel';
$userId = null;
$projectId = null;

try {
    $userId = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)', ['name' => 'V2 Integration', 'email' => $email, 'password_hash' => password_hash($token, PASSWORD_DEFAULT)]);
    $projectId = Database::insert('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)', ['user_id' => $userId, 'name' => 'V2 fixture ' . $token, 'repository_url' => 'https://github.com/wtfcode-fixtures/' . $token . '.git', 'local_path' => $fixture, 'status' => 'scanning']);

    $first = (new RepoScanner())->scan($projectId, $fixture);
    integration_assert(($first['symbol_graph']['stats']['symbols'] ?? 0) > 0, 'First scan should persist a graph');
    $firstCount = (int) $pdo->query('SELECT COUNT(*) FROM code_symbols WHERE project_id = ' . $projectId)->fetchColumn();
    $second = (new RepoScanner())->scan($projectId, $fixture);
    $secondCount = (int) $pdo->query('SELECT COUNT(*) FROM code_symbols WHERE project_id = ' . $projectId)->fetchColumn();
    integration_assert($firstCount === $secondCount, 'Re-analysis should replace current graph rows instead of duplicating them');
    integration_assert((int) $pdo->query('SELECT COUNT(*) FROM scan_runs WHERE project_id = ' . $projectId)->fetchColumn() === 2, 'Re-analysis should preserve two versioned scan records');
    integration_assert((string) $pdo->query('SELECT analysis_version FROM scan_runs WHERE project_id = ' . $projectId . ' ORDER BY id DESC LIMIT 1')->fetchColumn() === AnalysisEngine::VERSION, 'Latest scan should report V2 analysis version');

    $symbolPage = SymbolRepository::symbolsPage($projectId, 'Account', '', 1, 20);
    integration_assert($symbolPage['total'] >= 2, 'Persisted symbols should be searchable');
    $graph = SymbolRepository::graph($projectId, 100);
    integration_assert($graph['nodes'] !== [], 'Architecture graph should return persisted nodes');
    integration_assert($graph['edges'] !== [], 'Architecture graph should keep module-level evidence edges visible');
    $routes = SymbolRepository::routes($projectId, '/account');
    integration_assert(count($routes) === 1 && $routes[0]['handler_symbol_id'] !== null, 'Persisted Laravel route should resolve its handler symbol');
    $trace = FeatureTracer::trace($projectId, 'account');
    integration_assert($trace['entry_points'] !== [] && $trace['symbols'] !== [], 'Feature trace should connect persisted route and symbol evidence');
    $phraseTrace = FeatureTracer::trace($projectId, 'account workflow');
    integration_assert($phraseTrace['symbols'] !== [], 'Multiword feature traces should fall back to meaningful query terms');
    integration_assert(count($phraseTrace['hops']) <= 80, 'Feature traces should enforce the documented evidence-hop limit');
    $answer = ExplanationService::answerQuestion($projectId, 'Where does account workflow happen?');
    integration_assert(($answer['trace']['symbols'] ?? []) !== [], 'Natural-language questions should preserve the meaningful feature phrase');
    integration_assert(in_array('Account', array_slice(array_column($answer['trace']['symbols'], 'name'), 0, 5), true), 'Question evidence should rank the directly named symbol before generic related rows');
    $blast = BlastRadiusService::forSymbol($projectId, (int) $routes[0]['handler_symbol_id']);
    integration_assert(isset($blast['risk'], $blast['direct'], $blast['checklist']), 'Blast-radius service should return review guidance');

    integration_assert(Project::findForUser($projectId, $userId) !== null, 'Owner should access the integration project');
    integration_assert(Project::findForUser($projectId, $userId + 999999) === null, 'Another user id must not access the integration project');
} finally {
    if ($userId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
}

echo "WTFCode V2 database integration checks passed.\n";
