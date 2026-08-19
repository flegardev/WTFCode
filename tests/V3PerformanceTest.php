<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function performance_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function performance_git(string $root, array $arguments): void
{
    $result = (new SafeProcessRunner())->run(new ProcessRunRequest(array_merge(['git', '-C', $root], $arguments), $root, 20, 262144, 262144));
    if (!$result->succeeded()) throw new RuntimeException('Git fixture command failed: ' . implode(' ', $arguments));
}

$plainRequest = new AnalysisRequest(__DIR__, [['path' => 'one.php', 'language' => 'PHP', 'content' => '<?php function one() {}', 'hash' => hash('sha256', 'one')]]);
$otherRequest = new AnalysisRequest(__DIR__, [['path' => 'two.php', 'language' => 'PHP', 'content' => '<?php function two() {}', 'hash' => hash('sha256', 'two')]]);
performance_assert($plainRequest->revision() !== $otherRequest->revision(), 'Non-Git cache revisions must include file paths and content hashes');

$pdo = Database::connection();
$token = bin2hex(random_bytes(6));
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-performance-' . $token;
mkdir($directory, 0700, true);
$userId = null;
try {
    for ($index = 0; $index < 10; $index++) file_put_contents($directory . DIRECTORY_SEPARATOR . 'Service' . $index . '.php', "<?php\nfunction service" . $index . "(): int { return " . $index . "; }\n");
    performance_git($directory, ['init']);
    performance_git($directory, ['add', '.']);
    performance_git($directory, ['-c', 'user.name=WTFCode Test', '-c', 'user.email=test@wtfcode.local', 'commit', '-m', 'initial']);

    $currentCommit = new ReflectionMethod(RepoScanner::class, 'currentCommit');
    performance_assert(is_string($currentCommit->invoke(new RepoScanner(), $directory)), 'A clean Git worktree should use its commit as the scan revision');
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'dirty.php', '<?php function dirtyWorktree(): void {}');
    performance_assert($currentCommit->invoke(new RepoScanner(), $directory) === null, 'A dirty Git worktree must fall back to a content digest so provider cache entries cannot go stale');
    unlink($directory . DIRECTORY_SEPARATOR . 'dirty.php');

    $firstInspection = (new RepoScanner())->inspect($directory, AnalysisProfile::QUICK);
    $secondInspection = (new RepoScanner())->inspect($directory, AnalysisProfile::QUICK);
    $secondRuns = $secondInspection['symbol_graph']['engine_runs'];
    performance_assert(count(array_filter($secondRuns, static fn (array $run): bool => !empty($run['cache_hit']))) === count($secondRuns), 'Unchanged provider output must be reused from an exact content-addressed cache');

    $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)')->execute(['name' => 'Performance test', 'email' => 'performance-' . $token . '@wtfcode.local', 'password_hash' => password_hash($token, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)')->execute(['user_id' => $userId, 'name' => 'Performance fixture', 'repository_url' => 'https://github.com/wtfcode-performance/' . $token . '.git', 'local_path' => $directory, 'status' => 'scanning']);
    $projectId = (int) $pdo->lastInsertId();
    (new RepoScanner())->scan($projectId, $directory, AnalysisProfile::QUICK);

    file_put_contents($directory . DIRECTORY_SEPARATOR . 'Service3.php', "<?php\nfunction service3(): int { return 3; }\nfunction changedFeature(): string { return 'changed'; }\n");
    performance_git($directory, ['add', 'Service3.php']);
    performance_git($directory, ['-c', 'user.name=WTFCode Test', '-c', 'user.email=test@wtfcode.local', 'commit', '-m', 'change one service']);
    (new RepoScanner())->scan($projectId, $directory, AnalysisProfile::QUICK);
    $job = AnalysisJobStore::latest($projectId);
    performance_assert($job !== null && in_array($job['state'], ['completed', 'partial'], true), 'Analysis job must reach a terminal success state');
    $incremental = array_values(array_filter($job['steps'], static fn (array $step): bool => (bool) $step['incremental']));
    performance_assert($incremental !== [], 'A one-file Git change should trigger eligible incremental providers');
    performance_assert((int) $incremental[0]['files_analyzed'] < 10, 'Incremental analysis should inspect fewer files than the full repository when the graph permits it');
    performance_assert((int) $pdo->query('SELECT COUNT(*) FROM analysis_job_steps WHERE job_id = ' . (int) $job['id'])->fetchColumn() === count(AnalysisProfile::providers(AnalysisProfile::QUICK)), 'Every profile provider must have a visible job step');
} finally {
    if ($userId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    if (is_dir($directory)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        @rmdir($directory);
    }
}

echo "WTFCode V3 performance and job checks passed.\n";
