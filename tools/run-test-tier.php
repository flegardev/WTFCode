<?php

declare(strict_types=1);

$tiers = [
    'portable' => [
        'tests/UnitTest.php',
        'tests/V2AnalysisTest.php',
        'tests/V3FoundationTest.php',
        'tests/V3ModesTest.php',
        'tests/V3FailureIsolationTest.php',
        'tests/V3FeatureTest.php',
        'tests/V3FeatureEvidenceTest.php',
        'tests/V3FalsePositiveTest.php',
        'tests/GitHubAppTest.php',
        'tests/AlphaRunnerTest.php',
    ],
    'analyzers' => [
        'tests/V3AstTest.php',
        'tests/V3StructuralTest.php',
    ],
    'integration' => [
        'tests/V2IntegrationTest.php',
        'tests/V3IntegrationTest.php',
        'tests/V3GraphTest.php',
        'tests/V3ChangeTest.php',
        'tests/V3ExplanationTest.php',
        'tests/V3PerformanceTest.php',
    ],
    'security' => [
        'tests/V3SecurityTest.php',
        'tests/PostgresProductionTest.php',
    ],
    'e2e' => [
        'tests/PublicRepositoryImportTest.php',
    ],
];

$requirements = [
    'portable' => 'Composer dependencies only; no database or external analyzer binaries.',
    'analyzers' => 'Composer dependencies and npm ci.',
    'integration' => 'A disposable migrated PostgreSQL database.',
    'security' => 'A disposable migrated PostgreSQL database and all binaries pinned in config/tool-manifest.json.',
    'e2e' => 'Outbound GitHub access and Git; this tier clones a public fixture without executing it.',
];

$tier = strtolower(trim((string) ($argv[1] ?? '')));
if ($tier === '--list') {
    foreach (array_keys($tiers) as $name) {
        fwrite(STDOUT, sprintf("%-12s %s%s", $name, $requirements[$name], PHP_EOL));
    }
    exit(0);
}
if (!isset($tiers[$tier])) {
    fwrite(STDERR, 'Usage: php tools/run-test-tier.php <' . implode('|', array_keys($tiers)) . '>' . PHP_EOL);
    fwrite(STDERR, 'Use --list to show each tier\'s external requirements.' . PHP_EOL);
    exit(2);
}

$projectRoot = dirname(__DIR__);
$failures = [];
fwrite(STDOUT, sprintf("Running %s tests. %s%s", $tier, $requirements[$tier], PHP_EOL));

foreach ($tiers[$tier] as $relativePath) {
    $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path)) {
        fwrite(STDERR, sprintf("MISSING %s%s", $relativePath, PHP_EOL));
        $failures[] = $relativePath;
        continue;
    }

    fwrite(STDOUT, sprintf("%s--- %s ---%s", PHP_EOL, $relativePath, PHP_EOL));
    $descriptorSpec = [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['file', 'php://stdout', 'w'],
        2 => ['file', 'php://stderr', 'w'],
    ];
    $process = proc_open(
        [PHP_BINARY, $path],
        $descriptorSpec,
        $pipes,
        $projectRoot,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        fwrite(STDERR, sprintf("FAILED %s: PHP process could not be started.%s", $relativePath, PHP_EOL));
        $failures[] = $relativePath;
        continue;
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fwrite(STDERR, sprintf("FAILED %s (exit %d)%s", $relativePath, $exitCode, PHP_EOL));
        $failures[] = $relativePath;
    }
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("%s%s tier failed: %s%s", PHP_EOL, ucfirst($tier), implode(', ', $failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf("%s%s tier passed (%d scripts).%s", PHP_EOL, ucfirst($tier), count($tiers[$tier]), PHP_EOL));
