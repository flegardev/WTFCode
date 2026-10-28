<?php

declare(strict_types=1);

// Operator-only export of one deliberately allowlisted public sample. No scan,
// network request, arbitrary repository export or customer session is involved.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('WTF_CODE_NO_SESSION', true);
require __DIR__ . '/../bootstrap.php';
$id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$statement = Database::connection()->prepare('SELECT * FROM projects WHERE id = ?');
$statement->execute([$id ?: 0]);
$project = $statement->fetch();
$repository = 'https://github.com/gothinkster/node-express-realworld-example-app';
if (!$project || preg_replace('/\\.git$/', '', rtrim((string) $project['repository_url'], '/')) !== $repository || $project['github_installation_id'] !== null || $project['status'] !== 'ready') {
    fwrite(STDERR, "Only a completed public URL scan of the allowlisted sample can be exported.\n"); exit(1);
}
$scan = SymbolRepository::latestScan((int) $id);
if (!$scan || !preg_match('/^[a-f0-9]{40}$/', (string) $scan['commit_sha'])) { throw new RuntimeException('A verified commit is required.'); }
$architecture = Project::architecture((int) $id);
$imports = Database::connection()->prepare('SELECT pd.target_path, MIN(pf.path) AS path, COUNT(DISTINCT pd.source_file_id) AS file_count FROM project_dependencies pd INNER JOIN project_files pf ON pf.id = pd.source_file_id WHERE pd.project_id = ? AND pd.target_file_id IS NULL GROUP BY pd.target_path ORDER BY file_count DESC, pd.target_path');
$imports->execute([(int) $id]);
$dependencies = [];
foreach ($imports->fetchAll() as $import) {
    if (preg_match('#^(?:@[a-z0-9_.-]+/)?[a-z0-9_-]+(?:/[a-z0-9_.-]+)*$#i', (string) $import['target_path']) !== 1) continue;
    $dependencies[$import['target_path']] ??= ['name' => $import['target_path'], 'path' => $import['path'], 'files' => (int) $import['file_count']];
}
$question = 'Where does authentication happen?';
$answer = ExplanationService::answerQuestion((int) $id, $question);
$target = 'src/app/routes/auth/auth.ts';
$impact = (new WTFCode\Application\ChangeImpactService())->forTarget((int) $id, $target, 'file');
$export = [
    'repository' => $repository, 'commit' => $scan['commit_sha'], 'scanned_at' => $scan['created_at'], 'profile' => $scan['analysis_profile'],
    'summary' => WTFCode\Application\ProjectUnderstanding::summary($architecture['nodes'], SymbolRepository::routes((int) $id), json_decode($project['stack_json'], true) ?: []),
    'systems' => array_map(static fn(array $node): array => ['key' => $node['node_key'], 'label' => $node['label'], 'explanation' => $node['plain_explanation'], 'paths' => array_slice(json_decode($node['evidence_json'], true) ?: [], 0, 3)], $architecture['nodes']),
    'connections' => array_map(static fn(array $edge): array => ['from' => $edge['from_label'], 'to' => $edge['to_label'], 'relationship' => $edge['relationship_label'], 'certainty' => 'inferred'], $architecture['edges']),
    'dependencies' => array_slice(array_values($dependencies), 0, 6),
    'question' => $question, 'answer' => $answer['answer'], 'references' => array_slice($answer['evidence_packet']['evidence'], 0, 4),
    'change' => ['target' => $target, 'matched' => $impact['resolved_target_count'], 'files' => array_column($impact['files'], 'path'), 'checks' => $impact['recommendations'], 'limitations' => $impact['limitations']],
];
$directory = __DIR__ . '/../public/assets/data';
if (!is_dir($directory)) mkdir($directory, 0755, true);
file_put_contents($directory . '/public-example.json', json_encode(SensitiveDataSanitizer::scrub($export), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
echo "Exported public sample at commit " . $scan['commit_sha'] . ".\n";

