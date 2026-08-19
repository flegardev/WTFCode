<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function graph_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$limit = new ReflectionMethod(SymbolGraphStore::class, 'limit');
$unicodeBoundary = $limit->invoke(null, str_repeat('x', 999) . '—tail', 1000);
graph_assert(preg_match('//u', $unicodeBoundary) === 1, 'Persisted source evidence must remain valid UTF-8 when truncated at a multibyte boundary');

$pdo = Database::connection();
$token = bin2hex(random_bytes(6));
$userId = null;

try {
    $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)')->execute(['name' => 'Graph test', 'email' => 'graph-' . $token . '@wtfcode.local', 'password_hash' => password_hash($token, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $fixture = __DIR__ . '/fixtures/v2/next-react';
    $pdo->prepare('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)')->execute(['user_id' => $userId, 'name' => 'Graph fixture', 'repository_url' => 'https://github.com/wtfcode-graph/' . $token . '.git', 'local_path' => $fixture, 'status' => 'scanning']);
    $projectId = (int) $pdo->lastInsertId();
    (new RepoScanner())->scan($projectId, $fixture, AnalysisProfile::QUICK);

    $graph = SymbolRepository::graph($projectId, 300);
    graph_assert($graph['nodes'] !== [], 'Graph should expose normalized nodes');
    graph_assert($graph['edges'] !== [], 'Graph should expose connected relationships');
    graph_assert(count($graph['nodes']) <= 300 && count($graph['edges']) <= 700, 'Graph payload must remain bounded');
    foreach (['subsystem', 'architecture', 'feature', 'framework', 'risk'] as $field) graph_assert(isset($graph['nodes'][0][$field]), 'Graph nodes must include the ' . $field . ' filter dimension');
    graph_assert(in_array($graph['nodes'][0]['risk'], ['low', 'medium', 'high'], true), 'Graph risk must be a defined category');

    $script = (string) file_get_contents(__DIR__ . '/../public/assets/js/symbol-map.js');
    graph_assert(str_contains($script, '.dijkstra('), 'Graph UI should implement confidence-weighted shortest paths');
    foreach (['architecture', 'subsystem', 'feature', 'file', 'symbol'] as $level) graph_assert(str_contains($script, "'" . $level . "'"), 'Graph UI should implement ' . $level . ' level of detail');
    foreach (['relationship', 'framework', 'risk', 'confidence'] as $filter) graph_assert(str_contains($script, $filter), 'Graph UI should retain the ' . $filter . ' filter');
} finally {
    if ($userId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
}

echo "WTFCode V3 graph UX checks passed.\n";
