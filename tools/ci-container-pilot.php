<?php

declare(strict_types=1);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli' || getenv('WTF_DISPOSABLE_CONTAINER_TEST') !== 'true' || app_config()['host'] !== 'database' || app_config()['database'] !== 'wtfcode') {
    throw new RuntimeException('This fixture is restricted to the explicitly disposable Compose database.');
}
$pdo = Database::connection();
if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    throw new RuntimeException('The container smoke test requires an empty disposable account store.');
}
$results = [];
foreach (['octocat/Hello-World', 'gothinkster/node-express-realworld-example-app'] as $repository) {
    $password = bin2hex(random_bytes(16));
    $owner = Database::insert('INSERT INTO users(name,email,password_hash) VALUES (?, ?, ?)', ['Container fixture', bin2hex(random_bytes(8)) . '@container.local', password_hash($password, PASSWORD_DEFAULT)]);
    try {
        $start = microtime(true);
        $queued = Project::queueFromGithub($owner, '', 'https://github.com/' . $repository);
        if (isset($queued['error'])) throw new RuntimeException($queued['error']);
        $id = (int) $queued['project']['id'];
        $deadline = microtime(true) + 240;
        do {
            usleep(200000);
            $job = AnalysisJobStore::latestForUser($id, $owner);
            $state = (string) ($job['state'] ?? '');
        } while (!in_array($state, ['completed', 'partial', 'failed'], true) && microtime(true) < $deadline);
        if (!in_array($state, ['completed', 'partial'], true)) throw new RuntimeException('The separate container worker did not finish the real public scan.');
        $project = Project::findForUser($id, $owner);
        if (($project['local_path'] ?? null) !== '' || ($project['status'] ?? '') !== 'ready') throw new RuntimeException('Production checkout paths must not be retained.');
        $export = \WTFCode\Application\AccountData::export($owner);
        if (count($export['projects']) !== 1) throw new RuntimeException('The container export lacks the owned project.');
        $files = count($export['projects'][0]['project_files']);
        // Hello-World contains only extensionless README, not application source.
        // RealWorld must provide actual analyzable source and persisted evidence.
        if (($repository === 'octocat/Hello-World' && $files !== 0) || ($repository !== 'octocat/Hello-World' && $files === 0)) throw new RuntimeException('The source/README-only fixture outcome differs from its public repository contents.');
        $results[] = ['repository' => $repository, 'project_id' => $id, 'state' => $state, 'seconds' => round(microtime(true) - $start, 3), 'files' => $files, 'readme_only' => $files === 0, 'commit' => SymbolRepository::latestScan($id)['commit_sha'] ?? null];
    } finally {
        if (!\WTFCode\Application\AccountData::delete($owner, $password, 'DELETE')) throw new RuntimeException('Disposable container account cleanup failed.');
    }
}
if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0 || (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() !== 0 || (int) $pdo->query('SELECT COUNT(*) FROM analysis_jobs')->fetchColumn() !== 0) throw new RuntimeException('Container deletion left owned records behind.');
echo json_encode(['status' => 'passed', 'separate_supervised_worker' => true, 'account_export_and_deletion' => true, 'scans' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

