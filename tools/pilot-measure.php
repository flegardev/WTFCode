<?php

declare(strict_types=1);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
if (app_config()['environment'] !== 'testing' || !in_array(app_config()['host'], ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Disposable local testing database only.');
$repo = $argv[1] ?? '';
if (!in_array($repo, ['octocat/Hello-World', 'gothinkster/node-express-realworld-example-app'], true)) exit(2);
$password = bin2hex(random_bytes(16));
$owner = Database::insert('INSERT INTO users(name,email,password_hash) VALUES (?, ?, ?)', ['Measurement fixture', bin2hex(random_bytes(8)) . '@measurement.local', password_hash($password, PASSWORD_DEFAULT)]);
try {
    $start = hrtime(true);
    $queued = Project::queueFromGithub($owner, '', 'https://github.com/' . $repo);
    if (isset($queued['error'])) throw new RuntimeException($queued['error']);
    $id = (int) $queued['project']['id'];
    $job = AnalysisJobStore::claimNext('pilot-measure');
    if (!$job || (int) $job['project_id'] !== $id) throw new RuntimeException('Use an otherwise idle queue.');
    $result = (new \WTFCode\Application\ScanJobRunner())->run($job);
    if (!in_array($result['state'], ['completed', 'partial'], true)) throw new RuntimeException('Measurement scan failed.');
    $seconds = (hrtime(true) - $start) / 1e9;
    $bytes = 0; $directory = RepositoryImporter::storageRoot() . '/repos/project-' . $id;
    if (is_dir($directory)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile() && !$file->isLink()) $bytes += $file->getSize();
    $pdo = Database::connection(); $rowBytes = 0;
    $tables = $pdo->query("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'project_id'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!preg_match('/^[a-z_]+$/D', $table)) continue;
        $q = $pdo->prepare('SELECT COALESCE(SUM(pg_column_size(t)), 0) FROM ' . $table . ' t WHERE project_id = ?'); $q->execute([$id]); $rowBytes += (int) $q->fetchColumn();
    }
    echo json_encode(['repository' => $repo, 'commit_sha' => SymbolRepository::latestScan($id)['commit_sha'] ?? null, 'profile' => 'quick', 'state' => $result['state'], 'import_to_result_seconds' => round($seconds, 3), 'php_peak_allocated_bytes' => memory_get_peak_usage(true), 'retained_checkout_bytes_including_git' => $bytes, 'stored_project_row_bytes_excluding_indexes_toast' => $rowBytes, 'php_peak_excludes_child_processes' => true, 'filesystem_measurement_is_end_of_scan_not_peak' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally { \WTFCode\Application\AccountData::delete($owner, $password, 'DELETE'); }
