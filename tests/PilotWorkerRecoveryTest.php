<?php

declare(strict_types=1);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
function worker_assert(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
if (app_config()['environment'] !== 'testing' || !in_array(app_config()['host'], ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Disposable local database required.');
$pdo = Database::connection();
$owner = Database::insert('INSERT INTO users(name,email,password_hash) VALUES (?, ?, ?)', ['Crash fixture', bin2hex(random_bytes(8)) . '@crash.local', 'not-a-login']);
$marker = tempnam(sys_get_temp_dir(), 'wtf-crash-'); $child = null;
try {
    $queued = Project::queueFromGithub($owner, '', 'https://github.com/octocat/Hello-World');
    if (isset($queued['error'])) throw new RuntimeException($queued['error']);
    $id = (int) $queued['project']['id'];
    $code = 'define("WTF_CODE_NO_SESSION",true);require "bootstrap.php";$job=AnalysisJobStore::claimNext("crash-child");file_put_contents($argv[1],json_encode($job));sleep(300);';
    $child = proc_open([PHP_BINARY, '-r', $code, $marker], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, dirname(__DIR__), null, ['bypass_shell'=>true]);
    if (!is_resource($child)) throw new RuntimeException('Could not start disposable worker.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 10;
    do { usleep(50000); $claimed = json_decode((string) file_get_contents($marker), true); } while (!$claimed && microtime(true) < $deadline);
    worker_assert(is_array($claimed) && (int) $claimed['project_id'] === $id, 'Real child worker durably claims the job');
    worker_assert(proc_terminate($child, 9), 'Real child worker is forcibly terminated after claim');
    usleep(200000); worker_assert(!proc_get_status($child)['running'], 'Worker process is confirmed stopped');
    fclose($pipes[1]); fclose($pipes[2]); proc_close($child); $child = null;
    worker_assert(AnalysisJobStore::find((int)$claimed['id'])['state'] === 'running', 'Crash does not erase durable work');
    // Advance only this disposable lease/backoff instead of waiting five minutes/one hour.
    $pdo->prepare("UPDATE analysis_jobs SET leased_until = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = ?")->execute([$claimed['id']]);
    AnalysisJobStore::recoverExpiredLeases($id);
    $pdo->prepare('UPDATE analysis_jobs SET available_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$claimed['id']]);
    worker_assert(!AnalysisJobStore::heartbeat((int)$claimed['id'], $claimed['lease_token'], 'stale', 1, 4), 'Killed worker lease cannot publish after recovery');
    $retried = AnalysisJobStore::claimNext('replacement-worker');
    worker_assert((int)$retried['attempt_count'] === 2, 'Replacement worker receives the retry');
    $result = (new \WTFCode\Application\ScanJobRunner())->run($retried);
    worker_assert(in_array($result['state'], ['completed','partial'],true), 'Replacement worker completes a real public repository scan');
    $pdo->prepare('UPDATE projects SET repository_url = ? WHERE id = ?')->execute(['https://github.com/wtfcode-does-not-exist-987654321/removed-after-queue.git', $id]);
    $missingJob = AnalysisJobStore::enqueue($id, $owner, 'quick');
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $pdo->prepare('UPDATE analysis_jobs SET available_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$missingJob]);
        $missingClaim = AnalysisJobStore::claimNext('missing-repository-worker');
        worker_assert((int) $missingClaim['id'] === $missingJob, 'Unavailable repository retry ' . $attempt . ' is claimable');
        $missingResult = (new \WTFCode\Application\ScanJobRunner())->run($missingClaim);
        worker_assert($missingResult['state'] === ($attempt < 3 ? 'queued' : 'failed'), 'Unavailable remote uses bounded retry ' . $attempt);
    }
    $failed = AnalysisJobStore::find($missingJob);
    worker_assert(!str_contains($failed['error_message'], 'Stack trace') && !str_contains($failed['error_message'], 'git clone'), 'Unavailable repository exposes a safe customer error');
    try { GitHubAppService::inspectPublicRepository('https://github.com/torvalds/linux'); throw new RuntimeException('Oversized repository accepted.'); }
    catch (GitHubAccessException $e) { worker_assert($e->reason === 'repository_too_large', 'Real GitHub metadata rejects a large repository before clone'); }
    echo "Pilot worker crash/recovery passed; disposable lease/backoff clock was advanced explicitly.\n";
} finally {
    if (is_resource($child)) { proc_terminate($child,9); proc_close($child); }
    if (is_file($marker)) unlink($marker);
    $q=$pdo->prepare('SELECT id FROM projects WHERE user_id = ?');$q->execute([$owner]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $project) { $pdo->prepare('INSERT INTO storage_cleanup_tasks(project_id) VALUES (?) ON CONFLICT DO NOTHING')->execute([$project]); }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$owner]);\WTFCode\Application\PilotOperations::cleanup();
}
