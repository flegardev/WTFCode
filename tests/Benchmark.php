<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/** @var array<int, array{id: string, label: string, repository: string, group: string, required_stack: array<int, string>, forbidden_stack?: array<int, string>, required_nodes: array<int, string>, questions: array<int, string>}> $benchmarks */
$benchmarks = require __DIR__ . '/benchmarks/manifest.php';

/** @return never */
function benchmark_usage(string $error = ''): never
{
    if ($error !== '') fwrite(STDERR, $error . PHP_EOL . PHP_EOL);
    fwrite(STDERR, "Usage:\n  php tests/Benchmark.php --group=core\n  php tests/Benchmark.php --all\n  php tests/Benchmark.php --id=laravel\n\nOptions:\n  --group=core|extended   Run every benchmark in one group.\n  --all                   Run the full public-repository suite.\n  --id=<id>               Run one benchmark by manifest ID.\n  --json                  Print machine-readable results.\n  --keep                  Keep shallow test clones for manual inspection.\n  --cleanup               Remove only stale WTFCode benchmark directories from the system temp folder.\n");
    exit(2);
}

/** @return array{exit_code: int, output: string} */
function run_benchmark_process(array $command, string $workingDirectory): array
{
    $environment = array_merge(getenv() ?: [], ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => __DIR__ . '/../storage/.empty-git-config', 'GIT_TERMINAL_PROMPT' => '0']);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) return ['exit_code' => 1, 'output' => 'Could not start Git.'];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $startedAt = microtime(true);
    $timedOut = false;
    while (($status = proc_get_status($process))['running']) {
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (microtime(true) - $startedAt > 120) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(100000);
    }
    $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['exit_code' => $timedOut ? 1 : $exitCode, 'output' => trim($output)];
}

function delete_benchmark_directory(string $directory): bool
{
    $temporaryRoot = str_replace('\\', '/', rtrim(sys_get_temp_dir(), '\\/')) . '/';
    $normalizedDirectory = str_replace('\\', '/', $directory);
    if (!is_dir($directory) || !str_starts_with($normalizedDirectory, $temporaryRoot . 'wtfcode-benchmark-')) return !is_dir($directory);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isLink() || !$item->isDir()) {
                @chmod($path, 0666);
                @unlink($path);
            } else {
                @chmod($path, 0777);
                @rmdir($path);
            }
        }
        @chmod($directory, 0777);
        @rmdir($directory);
        clearstatcache(true, $directory);
        if (!is_dir($directory)) return true;
        usleep(300000);
    }
    return false;
}

function clean_stale_benchmarks(): int
{
    $temporaryRoot = rtrim(sys_get_temp_dir(), '\\/');
    $removed = 0;
    foreach (new DirectoryIterator($temporaryRoot) as $item) {
        if ($item->isDot() || !$item->isDir() || !str_starts_with($item->getFilename(), 'wtfcode-benchmark-')) continue;
        if (delete_benchmark_directory($item->getPathname())) $removed++;
    }
    return $removed;
}

/** @param array<int, string> $expected @param array<int, string> $actual @return array{missing: array<int, string>, detected: array<int, string>} */
function compare_benchmark_signals(array $expected, array $actual): array
{
    sort($expected);
    sort($actual);
    return ['missing' => array_values(array_diff($expected, $actual)), 'detected' => $actual];
}

$options = getopt('', ['all', 'group:', 'id:', 'json', 'keep', 'cleanup']);
if (isset($options['cleanup'])) {
    if (isset($options['all']) || isset($options['group']) || isset($options['id'])) benchmark_usage('Cleanup cannot be combined with a benchmark selector.');
    echo 'Removed ' . clean_stale_benchmarks() . " stale benchmark directories.\n";
    exit(0);
}
if (isset($options['all']) && (isset($options['group']) || isset($options['id']))) benchmark_usage('Use one selector at a time.');
if (isset($options['group']) && isset($options['id'])) benchmark_usage('Use one selector at a time.');
if (!isset($options['all']) && !isset($options['group']) && !isset($options['id'])) benchmark_usage();

$selected = array_values(array_filter($benchmarks, static function (array $benchmark) use ($options): bool {
    if (isset($options['all'])) return true;
    if (isset($options['group'])) return $benchmark['group'] === $options['group'];
    return $benchmark['id'] === $options['id'];
}));
if ($selected === []) benchmark_usage('No benchmark matched that selector.');

$temporaryRoot = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'wtfcode-benchmark-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) throw new RuntimeException('Could not create the temporary benchmark directory.');
$results = [];

try {
    foreach ($selected as $benchmark) {
        $destination = $temporaryRoot . DIRECTORY_SEPARATOR . $benchmark['id'];
        $clone = run_benchmark_process(['git', '-c', 'protocol.file.allow=never', 'clone', '--quiet', '--depth', '1', '--no-tags', '--no-recurse-submodules', $benchmark['repository'], $destination], $temporaryRoot);
        if ($clone['exit_code'] !== 0) {
            $results[] = ['id' => $benchmark['id'], 'label' => $benchmark['label'], 'status' => 'error', 'error' => 'Clone failed or timed out.', 'details' => substr($clone['output'], 0, 500)];
            continue;
        }

        $inspection = (new RepoScanner())->inspect($destination);
        $stack = compare_benchmark_signals($benchmark['required_stack'], $inspection['stack']);
        $forbiddenStack = array_values(array_intersect($benchmark['forbidden_stack'] ?? [], $inspection['stack']));
        $nodes = compare_benchmark_signals($benchmark['required_nodes'], array_column($inspection['nodes'], 'key'));
        $results[] = [
            'id' => $benchmark['id'],
            'label' => $benchmark['label'],
            'status' => $stack['missing'] === [] && $forbiddenStack === [] && $nodes['missing'] === [] ? 'pass' : 'review',
            'files_scanned' => count($inspection['files']),
            'stack' => $stack,
            'forbidden_stack_detected' => $forbiddenStack,
            'nodes' => $nodes,
            'findings' => count($inspection['findings']),
            'questions' => $benchmark['questions'],
        ];
        unset($inspection);
        gc_collect_cycles();
    }
} finally {
    if (!isset($options['keep']) && !delete_benchmark_directory($temporaryRoot)) {
        fwrite(STDERR, 'Could not remove temporary benchmark files. Run php tests/Benchmark.php --cleanup after Git releases its file handles.' . PHP_EOL);
    }
}

if (isset($options['json'])) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    foreach ($results as $result) {
        echo strtoupper($result['status']) . ' ' . $result['id'] . ' — ' . $result['label'] . PHP_EOL;
        if ($result['status'] === 'error') {
            echo '  ' . $result['error'] . ': ' . ($result['details'] ?: 'No Git output.') . PHP_EOL;
            continue;
        }
        echo '  files: ' . $result['files_scanned'] . ' | stack: ' . implode(', ', $result['stack']['detected'] ?: ['none']) . ' | nodes: ' . implode(', ', $result['nodes']['detected'] ?: ['none']) . PHP_EOL;
        if ($result['stack']['missing'] !== [] || $result['forbidden_stack_detected'] !== [] || $result['nodes']['missing'] !== []) {
            echo '  REVIEW missing stack: ' . implode(', ', $result['stack']['missing'] ?: ['none']) . ' | incompatible stack: ' . implode(', ', $result['forbidden_stack_detected'] ?: ['none']) . ' | missing nodes: ' . implode(', ', $result['nodes']['missing'] ?: ['none']) . PHP_EOL;
        }
    }
}

exit(count(array_filter($results, static fn (array $result): bool => $result['status'] === 'error')) === 0 ? 0 : 1);
