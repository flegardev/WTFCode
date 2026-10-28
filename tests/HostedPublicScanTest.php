<?php

declare(strict_types=1);

use WTFCode\Application\ScanJobRunner;

define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
if (!in_array(app_config()['host'], ['localhost', '127.0.0.1'], true) || app_config()['environment'] !== 'production') {
    throw new RuntimeException('Run only with APP_ENV=production and a disposable localhost database.');
}
$owner = null;
try {
    $owner = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :hash)', ['name' => 'Hosted cleanup test', 'email' => bin2hex(random_bytes(8)) . '@cleanup.local', 'hash' => 'not-a-login']);
    $queued = Project::queueFromGithub($owner, 'Hello World public cleanup sample', 'https://github.com/octocat/Hello-World');
    if (isset($queued['error'])) throw new RuntimeException($queued['error']);
    $projectId = (int) $queued['project']['id'];
    $job = AnalysisJobStore::claimNext('hosted-cleanup-test');
    if ($job === null || (int) $job['project_id'] !== $projectId) throw new RuntimeException('Use an otherwise idle disposable queue for this test.');
    $result = (new ScanJobRunner())->run($job);
    if (!in_array($result['state'], ['completed', 'partial'], true)) throw new RuntimeException('The real public scan did not finish.');
    $project = Project::findForUser($projectId, $owner);
    if ($project['local_path'] !== '') throw new RuntimeException('Production must not retain checkout paths.');
    if ((glob(RepositoryImporter::storageRoot() . '/repos/project-' . $projectId . '-*') ?: []) !== []) throw new RuntimeException('The production checkout survived worker cleanup.');
    echo "Real public GitHub import -> durable job -> worker -> evidence -> hosted checkout cleanup passed.\n";
} finally {
    if ($owner !== null) Database::connection()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $owner]);
}
