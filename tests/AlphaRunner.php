<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/alpha/AlphaSupport.php';

function alpha_usage(string $error = ''): never
{
    if ($error !== '') fwrite(STDERR, $error . PHP_EOL . PHP_EOL);
    fwrite(STDERR, "Usage:\n  php tests/AlphaRunner.php --repo=<name> [--profile=quick|deep|maximum] [--resume]\n  php tests/AlphaRunner.php --all [--profile=quick|deep|maximum] [--resume]\n\nThe harness fetches pinned public Git commits and performs static analysis only. Imported repository code is never executed.\n");
    exit(2);
}

$options = getopt('', ['repo:', 'all', 'profile:', 'resume', 'worker:', 'path:', 'commit:']);
$root = dirname(__DIR__);
$profile = AnalysisProfile::normalize((string) ($options['profile'] ?? AnalysisProfile::QUICK));

if (isset($options['worker'])) {
    try {
        $result = alpha_scan_repository((string) ($options['path'] ?? ''), $profile, (string) ($options['commit'] ?? ''));
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage());
        exit(1);
    }
}

if (isset($options['all']) === isset($options['repo'])) alpha_usage('Choose exactly one of --all or --repo.');
$corpus = alpha_load_corpus($root);
$selected = array_values(array_filter($corpus['repositories'], static fn (array $repo): bool => isset($options['all']) || $repo['name'] === $options['repo']));
if ($selected === []) alpha_usage('No corpus repository matched the requested name.');

$exitCode = 0;
foreach ($selected as $index => $repo) {
    alpha_ensure_scorecard($root, $repo);
    $runPath = $root . '/tests/alpha/runs/' . $repo['name'] . '-' . $profile . '.json';
    if (isset($options['resume']) && is_file($runPath)) {
        $existing = json_decode((string) file_get_contents($runPath), true);
        if (($existing['repository']['commit_sha'] ?? '') === $repo['commit_sha'] && ($existing['analysis_version'] ?? '') === AnalysisEngine::VERSION && ($existing['profile'] ?? '') === $profile) {
            echo 'SKIP ' . $repo['name'] . ' — unchanged result already exists.' . PHP_EOL;
            continue;
        }
    }
    echo 'IMPORT ' . $repo['name'] . ' @ ' . substr((string) $repo['commit_sha'], 0, 12) . PHP_EOL;
    $started = hrtime(true);
    try {
        $prepared = alpha_prepare_repository($root, $repo);
        echo 'SCAN ' . $repo['name'] . ' [' . $profile . ']' . PHP_EOL;
        $worker = alpha_process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'memory_limit=128M', __FILE__, '--worker=' . $repo['name'], '--path=' . $prepared['path'], '--commit=' . $repo['commit_sha'], '--profile=' . $profile], $root, 600, 4_194_304);
        if ($worker['exit_code'] !== 0) {
            $errorOutput = trim($worker['stderr'] !== '' ? $worker['stderr'] : $worker['stdout']);
            $machine = ['failure_kind' => $worker['timed_out'] ? 'timeout' : (str_contains(strtolower($errorOutput), 'allowed memory') ? 'memory' : 'crash'), 'error' => substr($errorOutput, 0, 1000), 'scan_duration_ms' => $worker['duration_ms']];
            $status = 'failed';
            $exitCode = 1;
        } else {
            $machine = json_decode($worker['stdout'], true, 512, JSON_THROW_ON_ERROR);
            $status = (string) ($machine['status'] ?? 'failed');
        }
        $result = [
            'schema_version' => ALPHA_SCHEMA_VERSION,
            'generated_at' => gmdate(DATE_ATOM),
            'analysis_version' => AnalysisEngine::VERSION,
            'profile' => $profile,
            'static_analysis_only' => true,
            'repository' => $repo,
            'status' => $status,
            'import' => ['clone_duration_ms' => $prepared['clone_duration_ms'], 'workspace_reused' => $prepared['reused']],
            'time_to_first_result_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'time_to_first_useful_insight_ms' => null,
            'machine' => $machine,
            'human_review' => 'UNSCORED',
            'best_insight' => 'UNSCORED',
        ];
    } catch (Throwable $exception) {
        $status = 'failed';
        $exitCode = 1;
        $result = [
            'schema_version' => ALPHA_SCHEMA_VERSION, 'generated_at' => gmdate(DATE_ATOM), 'analysis_version' => AnalysisEngine::VERSION,
            'profile' => $profile, 'static_analysis_only' => true, 'repository' => $repo, 'status' => 'failed',
            'time_to_first_result_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'time_to_first_useful_insight_ms' => null,
            'machine' => ['failure_kind' => 'import_or_harness', 'error' => substr($exception->getMessage(), 0, 1000)],
            'human_review' => 'UNSCORED', 'best_insight' => 'UNSCORED',
        ];
    }
    alpha_write_json($runPath, $result);
    echo strtoupper($status) . ' ' . $repo['name'] . ' — ' . ($result['machine']['files_analyzed'] ?? 0) . ' files, ' . round(($result['machine']['peak_php_memory_bytes'] ?? 0) / 1048576, 1) . ' MiB' . PHP_EOL;
    if (($index + 1) % 5 === 0) alpha_generate_reports($root);
}
alpha_generate_reports($root);
exit($exitCode);
