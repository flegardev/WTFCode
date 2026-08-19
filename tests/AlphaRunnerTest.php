<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/alpha/AlphaSupport.php';

function alpha_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$corpus = alpha_load_corpus(dirname(__DIR__));
alpha_assert(count($corpus['repositories']) >= 25, 'Alpha corpus must contain at least 25 repositories.');
$names = array_column($corpus['repositories'], 'name');
alpha_assert(count($names) === count(array_unique($names)), 'Alpha repository names must be unique.');
foreach ($corpus['repositories'] as $repo) {
    alpha_assert(preg_match('#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git$#', (string) $repo['url']) === 1, 'Alpha repositories must use the public GitHub HTTPS flow.');
    alpha_assert(preg_match('/^[a-f0-9]{40}$/', (string) $repo['commit_sha']) === 1, 'Alpha repositories must pin a full commit SHA.');
    alpha_assert(str_contains(alpha_scorecard_template($repo), 'Overall usefulness (1–5): UNSCORED'), 'Human scores must start UNSCORED.');
}
$process = alpha_process([PHP_BINARY, '-r', 'usleep(200000); echo "alpha-ok";'], dirname(__DIR__), 5);
alpha_assert($process['exit_code'] === 0 && trim($process['stdout']) === 'alpha-ok', 'Alpha process isolation must preserve a successful child exit code.');

$fixture = __DIR__ . '/fixtures/v2/next-react';
$result = alpha_scan_repository($fixture, AnalysisProfile::QUICK, str_repeat('a', 40));
alpha_assert(in_array($result['status'], ['success','partial'], true), 'Fixture scan should complete.');
alpha_assert(isset($result['files_discovered'], $result['files_analyzed'], $result['files_skipped'], $result['providers']), 'Machine scorecard must contain required accounting fields.');
foreach ($result['feature_clusters'] as $feature) alpha_assert(array_key_exists('evidence', $feature), 'Machine feature summaries must retain bounded evidence for manual review.');
alpha_assert($result['files_discovered'] >= $result['files_analyzed'], 'Skipped file accounting cannot be negative.');

$failureDb = json_decode((string) file_get_contents(__DIR__ . '/alpha/failures.json'), true, 512, JSON_THROW_ON_ERROR);
alpha_assert($failureDb['allowed_categories'][0] === 'FALSE_POSITIVE', 'Failure taxonomy must remain machine readable.');
alpha_assert(in_array('accepted_limitation', $failureDb['allowed_statuses'], true), 'Failure database must support accepted limitations.');

echo "WTFCode Alpha harness checks passed.\n";
