<?php

declare(strict_types=1);

const ALPHA_SCHEMA_VERSION = 3;
const ALPHA_IGNORED_DIRECTORIES = ['.git', '.idea', '.vscode', '.playwright-cli', 'node_modules', 'vendor', '.next', 'dist', 'build', 'coverage', '.turbo', '.cache', 'storage', 'tmp', 'temp'];

/** @return array<string, mixed> */
function alpha_load_corpus(string $root): array
{
    $path = $root . '/tests/alpha/corpus.json';
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_array($data['repositories'] ?? null)) throw new RuntimeException('Alpha corpus is invalid.');
    return $data;
}

/** @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,duration_ms:int} */
function alpha_process(array $command, string $cwd, int $timeoutSeconds, int $maxOutputBytes = 2_097_152): array
{
    $environment = array_merge(getenv() ?: [], [
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_GLOBAL' => dirname(__DIR__, 2) . '/storage/.empty-git-config',
        'GIT_TERMINAL_PROMPT' => '0',
    ]);
    $started = hrtime(true);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Process could not start.', 'timed_out' => false, 'duration_ms' => 0];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = $stderr = '';
    $timedOut = false;
    $reportedExit = -1;
    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) { $reportedExit = (int) ($status['exitcode'] ?? -1); break; }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        if (strlen($stdout) + strlen($stderr) > $maxOutputBytes) {
            $stderr .= '\nProcess output exceeded the Alpha safety budget.';
            proc_terminate($process);
            break;
        }
        if ((hrtime(true) - $started) / 1_000_000_000 > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(100_000);
    }
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedExit = proc_close($process);
    $exitCode = $reportedExit >= 0 ? $reportedExit : $closedExit;
    return [
        'exit_code' => $timedOut ? 124 : $exitCode,
        'stdout' => substr($stdout, 0, $maxOutputBytes),
        'stderr' => substr($stderr, 0, $maxOutputBytes),
        'timed_out' => $timedOut,
        'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
    ];
}

function alpha_delete_workspace(string $workspace, string $base): void
{
    $normalizedBase = str_replace('\\', '/', rtrim((string) realpath($base), '\\/')) . '/';
    $normalizedWorkspace = str_replace('\\', '/', $workspace);
    if (!str_starts_with($normalizedWorkspace, $normalizedBase) || !is_dir($workspace)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $path = $item->getPathname();
        if ($item->isLink() || !$item->isDir()) { @chmod($path, 0666); @unlink($path); }
        else { @chmod($path, 0777); @rmdir($path); }
    }
    @rmdir($workspace);
}

/** @param array<string,mixed> $repo */
function alpha_prepare_repository(string $root, array $repo): array
{
    $base = $root . '/storage/alpha-corpus';
    $emptyHooks = $root . '/storage/alpha-empty-hooks';
    if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) throw new RuntimeException('Could not create Alpha corpus storage.');
    if (!is_dir($emptyHooks) && !mkdir($emptyHooks, 0700, true) && !is_dir($emptyHooks)) throw new RuntimeException('Could not create empty Git hooks directory.');
    $workspace = $base . '/' . $repo['name'];
    $expected = strtolower((string) $repo['commit_sha']);
    $marker = $workspace . '/.alpha-pinned-commit';
    if (($repo['source'] ?? 'public_github') === 'explicit_local_repository') {
        if (is_file($marker) && trim((string) file_get_contents($marker)) === $expected) return ['path' => $workspace, 'clone_duration_ms' => 0, 'reused' => true];
        if (is_dir($workspace)) alpha_delete_workspace($workspace, $base);
        $localPath = realpath($root . '/' . (string) ($repo['local_path'] ?? ''));
        if ($localPath === false || !is_dir($localPath . '/.git')) throw new RuntimeException('Explicitly authorized local repository is unavailable.');
        $started = hrtime(true);
        $verify = alpha_process(['git', '-C', $localPath, 'cat-file', '-e', $expected . '^{commit}'], $root, 15);
        $clone = $verify['exit_code'] === 0 ? alpha_process(['git', '-c', 'protocol.file.allow=always', '-c', 'core.hooksPath=' . $emptyHooks, 'clone', '--quiet', '--no-local', '--no-checkout', '--no-tags', '--no-recurse-submodules', $localPath, $workspace], $root, 120, 524_288) : $verify;
        $checkout = $clone['exit_code'] === 0 ? alpha_process(['git', '-c', 'core.hooksPath=' . $emptyHooks, '-C', $workspace, 'checkout', '--quiet', '--detach', $expected], $root, 30, 524_288) : $clone;
        if ($checkout['exit_code'] !== 0 || !is_dir($workspace . '/.git')) { if (is_dir($workspace)) alpha_delete_workspace($workspace, $base); throw new RuntimeException('Authorized local exact-commit clone failed.'); }
        file_put_contents($marker, $expected, LOCK_EX);
        return ['path' => $workspace, 'clone_duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000), 'reused' => false];
    }
    if (is_dir($workspace . '/.git')) {
        $head = alpha_process(['git', '-c', 'core.hooksPath=' . $emptyHooks, '-C', $workspace, 'rev-parse', 'HEAD'], $root, 10);
        if ($head['exit_code'] === 0 && strtolower(trim($head['stdout'])) === $expected) return ['path' => $workspace, 'clone_duration_ms' => 0, 'reused' => true];
        alpha_delete_workspace($workspace, $base);
    }
    if (is_dir($workspace)) alpha_delete_workspace($workspace, $base);
    if (!mkdir($workspace, 0700, true) && !is_dir($workspace)) throw new RuntimeException('Could not create repository workspace.');
    $started = hrtime(true);
    foreach ([
        ['git', '-c', 'core.hooksPath=' . $emptyHooks, 'init', '--quiet', $workspace],
        ['git', '-c', 'core.hooksPath=' . $emptyHooks, '-C', $workspace, 'remote', 'add', 'origin', (string) $repo['url']],
        ['git', '-c', 'protocol.file.allow=never', '-c', 'core.hooksPath=' . $emptyHooks, '-C', $workspace, 'fetch', '--quiet', '--depth=1', '--no-tags', '--no-recurse-submodules', 'origin', $expected],
        ['git', '-c', 'core.hooksPath=' . $emptyHooks, '-C', $workspace, 'checkout', '--quiet', '--detach', 'FETCH_HEAD'],
    ] as $command) {
        $result = alpha_process($command, $root, 180, 524_288);
        if ($result['exit_code'] !== 0) {
            alpha_delete_workspace($workspace, $base);
            throw new RuntimeException('Safe Git import failed: ' . trim($result['stderr'] ?: $result['stdout']));
        }
    }
    $head = alpha_process(['git', '-C', $workspace, 'rev-parse', 'HEAD'], $root, 10);
    if ($head['exit_code'] !== 0 || strtolower(trim($head['stdout'])) !== $expected) {
        alpha_delete_workspace($workspace, $base);
        throw new RuntimeException('Imported repository did not match its pinned commit.');
    }
    return ['path' => $workspace, 'clone_duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000), 'reused' => false];
}

/** @return array{files_discovered:int,repository_bytes:int} */
function alpha_inventory(string $root): array
{
    $files = 0;
    $bytes = 0;
    $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filtered = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $entry): bool {
        if ($entry->isLink()) return false;
        return !$entry->isDir() || !in_array($entry->getFilename(), ALPHA_IGNORED_DIRECTORIES, true);
    });
    foreach (new RecursiveIteratorIterator($filtered) as $item) {
        if (!$item->isFile() || $item->isLink()) continue;
        $files++;
        $bytes += $item->getSize();
    }
    return ['files_discovered' => $files, 'repository_bytes' => $bytes];
}

/** @return array<string,mixed> */
function alpha_scan_repository(string $path, string $profile, string $commit): array
{
    if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
    $inventory = alpha_inventory($path);
    $started = hrtime(true);
    $inspection = (new RepoScanner())->inspect($path, $profile, $commit);
    $duration = (int) round((hrtime(true) - $started) / 1_000_000);
    $graph = $inspection['symbol_graph'] ?? [];
    $stats = $graph['stats'] ?? [];
    $providerFailures = [];
    $partialProviders = [];
    $providers = [];
    foreach ($graph['engine_runs'] ?? [] as $run) {
        $item = array_intersect_key($run, array_flip(['engine','engine_version','status','duration_ms','cache_hit','incremental','files_analyzed','message','symbols','relationships','routes','packages','findings']));
        $providers[] = $item;
        if (($run['status'] ?? '') === AnalyzerResult::FAILED) $providerFailures[] = (string) ($run['engine'] ?? 'unknown');
        if (($run['status'] ?? '') === AnalyzerResult::PARTIAL) $partialProviders[] = (string) ($run['engine'] ?? 'unknown');
    }
    $slowest = null;
    foreach ($providers as $provider) if ($slowest === null || (int) ($provider['duration_ms'] ?? 0) > (int) ($slowest['duration_ms'] ?? 0)) $slowest = $provider;
    $services = $tables = [];
    foreach ($graph['symbols'] ?? [] as $symbol) {
        $type = (string) ($symbol['type'] ?? '');
        if ($type === 'external_service') $services[] = (string) ($symbol['name'] ?? '');
        if (in_array($type, ['database_table','table','model'], true)) $tables[] = (string) ($symbol['name'] ?? '');
    }
    $services = array_values(array_unique(array_filter($services)));
    $tables = array_values(array_unique(array_filter($tables)));
    sort($services, SORT_NATURAL | SORT_FLAG_CASE);
    sort($tables, SORT_NATURAL | SORT_FLAG_CASE);
    $findingTypes = $findingSeverities = [];
    $scanLimitReasons = [];
    $findingSample = [];
    foreach ($inspection['findings'] ?? [] as $finding) {
        $type = (string) ($finding['type'] ?? 'unknown');
        $severity = (string) ($finding['severity'] ?? 'unknown');
        $findingTypes[$type] = ($findingTypes[$type] ?? 0) + 1;
        $findingSeverities[$severity] = ($findingSeverities[$severity] ?? 0) + 1;
        if ($type === 'scan_limit') $scanLimitReasons[] = (string) ($finding['explanation'] ?? 'Scanner limit reached.');
        if (count($findingSample) < 20) $findingSample[] = array_intersect_key($finding, array_flip(['type','severity','title','path']));
    }
    ksort($findingTypes);
    ksort($findingSeverities);
    $limitFlags = array_filter($stats, static fn (mixed $value, string $key): bool => (str_contains($key, 'limit') || str_contains($key, 'truncat')) && (int) $value > 0, ARRAY_FILTER_USE_BOTH);
    $partialReasons = $scanLimitReasons;
    if ($partialProviders !== []) $partialReasons[] = 'Partial analyzer providers: ' . implode(', ', $partialProviders) . '.';
    if ($providerFailures !== []) $partialReasons[] = 'Failed analyzer providers: ' . implode(', ', $providerFailures) . '.';
    if ($limitFlags !== []) $partialReasons[] = 'Graph or enrichment limits: ' . implode(', ', array_keys($limitFlags)) . '.';
    $features = array_map(static fn (array $feature): array => [
        'label' => (string) ($feature['label'] ?? ''),
        'confidence' => (string) ($feature['confidence'] ?? 'unknown'),
        'score' => (int) ($feature['score'] ?? 0),
        'symbols' => count($feature['symbols'] ?? []),
        'evidence' => array_map(static fn (array $evidence): array => array_intersect_key($evidence, array_flip(['path','line','signals','confidence'])), array_slice($feature['evidence'] ?? [], 0, 10)),
    ], $graph['features'] ?? []);
    $routes = array_map(static fn (array $route): array => array_intersect_key($route, array_flip(['method','route_path','path','handler_key','framework','line','confidence'])), array_slice($graph['routes'] ?? [], 0, 50));
    $nodes = array_map(static fn (array $node): array => array_intersect_key($node, array_flip(['key','type','label','explanation','evidence'])), $inspection['nodes'] ?? []);
    return SensitiveDataSanitizer::scrub([
        'status' => $partialReasons === [] ? 'success' : 'partial',
        'scan_duration_ms' => $duration,
        'peak_php_memory_bytes' => memory_get_peak_usage(true),
        'files_discovered' => $inventory['files_discovered'],
        'repository_bytes' => $inventory['repository_bytes'],
        'files_analyzed' => count($inspection['files'] ?? []),
        'files_skipped' => max(0, $inventory['files_discovered'] - count($inspection['files'] ?? [])),
        'stack' => $inspection['stack'] ?? [],
        'overview' => (string) ($inspection['overview'] ?? ''),
        'architecture_nodes' => $nodes,
        'symbols' => count($graph['symbols'] ?? []),
        'relationships' => count($graph['relationships'] ?? []),
        'routes' => count($graph['routes'] ?? []),
        'route_sample' => $routes,
        'features' => count($features),
        'feature_clusters' => $features,
        'services' => $services,
        'tables' => $tables,
        'packages' => count($graph['packages'] ?? []),
        'security_findings' => array_sum(array_intersect_key($findingTypes, array_flip(['secret','secret_leak','hardcoded_secret','dependency_vulnerability','process_or_dynamic_execution','unsafe_file_operation']))),
        'findings' => count($inspection['findings'] ?? []),
        'finding_types' => $findingTypes,
        'finding_severities' => $findingSeverities,
        'finding_sample' => $findingSample,
        'provider_failures' => $providerFailures,
        'partial_providers' => $partialProviders,
        'providers' => $providers,
        'slowest_provider' => $slowest === null ? null : ['engine' => $slowest['engine'] ?? 'unknown', 'duration_ms' => (int) ($slowest['duration_ms'] ?? 0)],
        'graph_truncation' => array_filter($limitFlags, static fn (string $key): bool => !str_starts_with($key, 'product_'), ARRAY_FILTER_USE_KEY),
        'product_intelligence_truncation' => (int) ($stats['product_intelligence_limited'] ?? 0) === 1,
        'partial_reasons' => array_values(array_unique($partialReasons)),
    ]);
}

/** @param array<string,mixed> $repo */
function alpha_scorecard_template(array $repo): string
{
    $fields = ['Stack accuracy','Architecture accuracy','Feature accuracy','Route accuracy','Database understanding','Service understanding','Feature tracing','Blast radius usefulness','Git/change explanation','Security usefulness','Explanation clarity','Evidence quality','False-positive control','Overall usefulness'];
    $lines = ['# Alpha scorecard — ' . $repo['name'], '', '- Repository: ' . $repo['url'], '- Commit: `' . $repo['commit_sha'] . '`', '- Reviewer: UNSCORED', '- Review date: UNSCORED', ''];
    foreach ($fields as $field) $lines[] = '- ' . $field . ' (1–5): UNSCORED';
    return implode("\n", array_merge($lines, [
        '', '## Primary usefulness question', '', 'Did WTFCode teach you something useful that you did not understand before? **UNSCORED**', '', 'What did it teach you?', '', 'UNSCORED',
        '', '## Trust question', '', 'Would you trust WTFCode before asking an AI coding agent to change this repository? **UNSCORED**', '', 'Reason:', '', 'UNSCORED',
        '', '## Confusion and truthfulness', '', 'What output confused you the most?', '', 'UNSCORED', '', 'What did WTFCode confidently say that turned out to be wrong?', '', 'UNSCORED',
        '', '## Manual probes', '', '- Relevant feature traces and hop review: UNSCORED', '- Where-does-this-button-go sample: UNSCORED', '- Blast-radius sample: UNSCORED', '- Can-I-delete-this sample: UNSCORED', '- Database/auth/service checks: UNSCORED', '- Confidence calibration samples: UNSCORED', '- Explanation-mode comparison: UNSCORED', '- Test recommendation quality: UNSCORED', '- Safe prompt quality: UNSCORED',
        '', '## Product value', '', '- Approximate import-to-first-useful-insight time: UNSCORED', '- Best insight WTFCode found: UNSCORED', '- Value category: UNSCORED', '', 'Notes:', '', 'UNSCORED', ''
    ]));
}

function alpha_write_json(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Could not create output directory.');
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('Could not publish Alpha JSON atomically.'); }
}

function alpha_ensure_scorecard(string $root, array $repo): void
{
    $directory = $root . '/tests/alpha/scorecards';
    if (!is_dir($directory)) mkdir($directory, 0700, true);
    $path = $directory . '/' . $repo['name'] . '.md';
    if (!is_file($path)) file_put_contents($path, alpha_scorecard_template($repo));
}

/** @return array<int,array<string,mixed>> */
function alpha_run_results(string $root): array
{
    $results = [];
    foreach (glob($root . '/tests/alpha/runs/*.json') ?: [] as $path) {
        try { $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { continue; }
        if (is_array($data)) $results[] = $data;
    }
    return $results;
}

function alpha_generate_reports(string $root): void
{
    $results = alpha_run_results($root);
    $latest = [];
    foreach ($results as $result) {
        $key = (string) ($result['repository']['name'] ?? '') . ':' . (string) ($result['profile'] ?? '');
        if ($key !== '') $latest[$key] = $result;
    }
    $quick = [];
    foreach ($latest as $key => $result) if (($result['profile'] ?? '') === AnalysisProfile::QUICK) $quick[(string) ($result['repository']['name'] ?? $key)] = $result;
    $statusCounts = ['success' => 0, 'partial' => 0, 'failed' => 0];
    foreach ($quick as $result) $statusCounts[$result['status'] ?? 'failed'] = ($statusCounts[$result['status'] ?? 'failed'] ?? 0) + 1;
    $failureData = json_decode((string) file_get_contents($root . '/tests/alpha/failures.json'), true) ?: [];
    $open = ['P0' => 0, 'P1' => 0, 'P2' => 0, 'P3' => 0];
    $fixed = 0;
    foreach ($failureData['failures'] ?? [] as $failure) {
        if (($failure['status'] ?? '') === 'fixed') $fixed++;
        elseif (in_array($failure['status'] ?? '', ['open','confirmed'], true)) $open[$failure['severity'] ?? 'P3']++;
    }
    $reports = $root . '/tests/alpha/reports';
    if (!is_dir($reports)) mkdir($reports, 0700, true);
    $reviews = ['completed' => 0, 'Yes' => 0, 'Somewhat' => 0, 'No' => 0];
    foreach (glob($root . '/tests/alpha/scorecards/*.md') ?: [] as $scorecard) {
        $text = (string) file_get_contents($scorecard);
        if (!preg_match('/^- Reviewer:\s*(?!UNSCORED)(.+)$/mi', $text)) continue;
        $reviews['completed']++;
        if (preg_match('/Did WTFCode teach you something useful[^\n]*\*\*(Yes|Somewhat|No)\*\*/i', $text, $match)) {
            $answer = ucfirst(strtolower($match[1]));
            $reviews[$answer]++;
        }
    }
    $dashboard = [
        '# WTFCode Alpha dashboard', '',
        '- Repositories tested (Quick): ' . count($quick),
        '- Repository/profile runs: ' . count($latest),
        '- Successful scans: ' . $statusCounts['success'],
        '- Partial scans: ' . $statusCounts['partial'],
        '- Failed scans: ' . $statusCounts['failed'], '',
        '## Open failures', '',
        '- P0: ' . $open['P0'], '- P1: ' . $open['P1'], '- P2: ' . $open['P2'], '- P3: ' . $open['P3'],
        '- Regression fixes: ' . $fixed, '',
        '## Human review', '',
        '- Human reviews completed: ' . $reviews['completed'],
        '- Useful insight Yes / Somewhat / No: ' . $reviews['Yes'] . ' / ' . $reviews['Somewhat'] . ' / ' . $reviews['No'], '',
        'Machine scan success is not a human usefulness score.', ''
    ];
    file_put_contents($reports . '/dashboard.md', implode("\n", $dashboard));
    $performance = ['# Alpha performance', '', 'Generated from isolated static-analysis runs. Cache hits are reported by providers; a partial run is not counted as complete.', ''];
    foreach ([AnalysisProfile::QUICK, AnalysisProfile::DEEP, AnalysisProfile::MAXIMUM] as $profile) {
        $profileResults = array_values(array_filter($latest, static fn (array $result): bool => ($result['profile'] ?? '') === $profile));
        if ($profileResults === []) continue;
        $durations = array_map(static fn (array $result): int => (int) ($result['machine']['scan_duration_ms'] ?? 0), $profileResults);
        sort($durations);
        $median = $durations[intdiv(count($durations), 2)] ?? 0;
        $minimum = $durations[0] ?? 0;
        $maximum = $durations[array_key_last($durations)] ?? 0;
        $outliers = array_values(array_map(static fn (array $result): string => (string) ($result['repository']['name'] ?? '?'), array_filter($profileResults, static fn (array $result): bool => (int) ($result['machine']['scan_duration_ms'] ?? 0) > $median * 2)));
        $nearMemory = array_values(array_map(static fn (array $result): string => (string) ($result['repository']['name'] ?? '?'), array_filter($profileResults, static fn (array $result): bool => (int) ($result['machine']['peak_php_memory_bytes'] ?? 0) >= 110 * 1048576)));
        $truncated = count(array_filter($profileResults, static fn (array $result): bool => !empty($result['machine']['partial_reasons'])));
        $performance[] = sprintf('## %s summary', ucfirst($profile));
        $performance[] = '';
        $performance[] = sprintf('- Runs: %d; median %.2fs; range %.2f–%.2fs.', count($profileResults), $median / 1000, $minimum / 1000, $maximum / 1000);
        $performance[] = '- Duration outliers (>2× median): ' . ($outliers === [] ? 'none' : implode(', ', $outliers)) . '.';
        $performance[] = '- Near the 128 MiB worker ceiling (>=110 MiB): ' . ($nearMemory === [] ? 'none' : implode(', ', $nearMemory)) . '.';
        $performance[] = '- Runs with disclosed partial reasons: ' . $truncated . '.';
        $performance[] = '';
    }
    $performance = array_merge($performance, ['## Per-run evidence', '', '| Repository | Profile | Status | Discovered | Analyzed | Skipped | Duration | Peak MiB | Slowest provider | Partial reason |', '|---|---:|---:|---:|---:|---:|---:|---:|---|---|']);
    usort($results, static fn (array $a, array $b): int => strcmp(($a['repository']['name'] ?? '') . ($a['profile'] ?? ''), ($b['repository']['name'] ?? '') . ($b['profile'] ?? '')));
    foreach ($results as $result) {
        $machine = $result['machine'] ?? [];
        $partial = empty($machine['partial_reasons']) ? 'none' : implode('; ', array_map(static fn (string $reason): string => str_replace('|', '\\|', $reason), $machine['partial_reasons']));
        $performance[] = sprintf('| %s | %s | %s | %d | %d | %d | %.2fs | %.1f | %s | %s |', $result['repository']['name'] ?? '?', $result['profile'] ?? '?', $result['status'] ?? 'failed', $machine['files_discovered'] ?? 0, $machine['files_analyzed'] ?? 0, $machine['files_skipped'] ?? 0, ($machine['scan_duration_ms'] ?? 0) / 1000, ($machine['peak_php_memory_bytes'] ?? 0) / 1048576, $machine['slowest_provider']['engine'] ?? 'n/a', $partial);
    }
    file_put_contents($reports . '/performance.md', implode("\n", $performance) . "\n");
    if (!is_file($reports . '/confidence-calibration.md')) file_put_contents($reports . '/confidence-calibration.md', "# Confidence calibration\n\nNo relationships have been manually calibrated yet. Sample size: 0. No statistical claim is made.\n\n| Repository | Fact | Reported confidence | Manual truth | Classification |\n|---|---|---|---|---|\n");

    $corpus = json_decode((string) file_get_contents($root . '/tests/alpha/corpus.json'), true);
    $repositories = is_array($corpus['repositories'] ?? null) ? $corpus['repositories'] : [];
    foreach (array_chunk($repositories, 5) as $index => $batch) {
        $names = array_column($batch, 'name');
        $batchFailures = array_values(array_filter($failureData['failures'] ?? [], static function (array $failure) use ($names): bool {
            $repos = array_map('trim', explode(',', (string) ($failure['repo'] ?? '')));
            return array_intersect($names, $repos) !== [] || ($failure['repo'] ?? '') === 'corpus';
        }));
        $severity = ['P0' => 0, 'P1' => 0, 'P2' => 0, 'P3' => 0];
        foreach ($batchFailures as $failure) $severity[$failure['severity'] ?? 'P3']++;
        $slowestName = 'not run'; $slowestMs = -1; $limitations = [];
        foreach ($names as $name) {
            $result = $quick[$name] ?? null;
            if ($result === null) continue;
            $duration = (int) ($result['machine']['scan_duration_ms'] ?? 0);
            if ($duration > $slowestMs) { $slowestMs = $duration; $slowestName = $name . ' (' . number_format($duration / 1000, 2) . 's)'; }
            foreach ($result['machine']['partial_reasons'] ?? [] as $reason) $limitations[] = $name . ': ' . $reason;
        }
        $worst = $batchFailures === [] ? 'None recorded.' : ($batchFailures[0]['id'] . ': ' . $batchFailures[0]['description']);
        $fixedFailures = array_values(array_filter($batchFailures, static fn (array $failure): bool => ($failure['status'] ?? '') === 'fixed'));
        $lines = [
            '# Alpha batch ' . ($index + 1), '',
            'Repositories tested: ' . implode(', ', $names) . '.', '',
            '## Failure accounting', '',
            sprintf('- Failures recorded: %d (P0 %d / P1 %d / P2 %d / P3 %d).', count($batchFailures), $severity['P0'], $severity['P1'], $severity['P2'], $severity['P3']),
            '- Fixes made: ' . ($fixedFailures === [] ? 'none in this batch' : implode(', ', array_column($fixedFailures, 'id'))) . '.',
            '- Regressions added: ' . ($fixedFailures === [] ? 'none' : implode('; ', array_column($fixedFailures, 'regression_test'))) . '.', '',
            '## Product observations', '',
            '- Best insight: UNSCORED until human review.',
            '- Worst false positive: ' . $worst,
            '- Performance outlier: ' . $slowestName . '.',
            '- Unresolved limitation: ' . ($limitations === [] ? 'No Quick-profile limitation was recorded.' : implode(' ', array_slice(array_unique($limitations), 0, 3))), '',
            'Machine output is evidence for review, not a human usefulness score.', ''
        ];
        file_put_contents($reports . '/batch-' . ($index + 1) . '.md', implode("\n", $lines));
    }
}
