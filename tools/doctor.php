<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$checks = (new ToolDoctor())->inspect(dirname(__DIR__));
if (in_array('--json', $argv, true)) {
    echo json_encode([
        'analysis_version' => AnalysisEngine::VERSION,
        'platform' => PHP_OS_FAMILY,
        'tools' => $checks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}

echo 'WTFCode analyzer doctor (' . AnalysisEngine::VERSION . ')' . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;
foreach ($checks as $check) {
    $version = $check['version'] ? ' — ' . $check['version'] : '';
    printf("%-22s %-11s%s\n", $check['name'], $check['status'], $version);
    echo '  ' . $check['message'] . PHP_EOL;
}

$requiredMissing = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'Missing'));
exit($requiredMissing === 0 ? 0 : 1);
