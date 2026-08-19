<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$fixtures = [
    'plain-php' => [
        'expected' => ['symbol:class:AuthService', 'symbol:method:login', 'symbol:table:users', 'symbol:environment_variable:DATABASE_URL', 'route:ANY:/login.php', 'relationship:instantiates'],
        'forbidden' => ['symbol:table:documentation', 'symbol:external_service:example.com'],
    ],
    'laravel' => [
        'expected' => ['symbol:controller:AccountController', 'symbol:model:Account', 'symbol:table:accounts', 'route:GET:/account'],
        'forbidden' => ['symbol:table:customers'],
    ],
    'next-react' => [
        'expected' => ['symbol:component:AccountCard', 'symbol:hook:useAccount', 'route:GET:/account', 'route:GET:/api/accounts', 'route:POST:/api/accounts', 'relationship:renders'],
        'forbidden' => [],
    ],
    'fastapi' => ['expected' => ['symbol:schema:Account', 'symbol:environment_variable:AUTH_MODE', 'route:GET:/accounts/{account_id}'], 'forbidden' => []],
    'django' => ['expected' => ['symbol:model:Account', 'symbol:function:account_detail', 'route:ANY:/accounts/<int:account_id>/'], 'forbidden' => ['symbol:class:FastAPI']],
    'sql' => ['expected' => ['symbol:table:customers', 'symbol:table:orders', 'symbol:column:customer_id', 'symbol:view:customer_totals', 'relationship:foreign_key_to'], 'forbidden' => []],
];

function fusion_benchmark_files(string $root): array
{
    return (new ReflectionMethod(RepoScanner::class, 'discoverFiles'))->invoke(new RepoScanner(), $root);
}

function fusion_benchmark_facts(array $graph): array
{
    $facts = [];
    foreach ($graph['symbols'] ?? [] as $symbol) $facts['symbol:' . $symbol['type'] . ':' . $symbol['name']] = true;
    foreach ($graph['routes'] ?? [] as $route) $facts['route:' . strtoupper((string) $route['method']) . ':' . $route['route_path']] = true;
    foreach ($graph['relationships'] ?? [] as $edge) $facts['relationship:' . $edge['type']] = true;
    return $facts;
}

function fusion_benchmark_score(array $facts, array $expected, array $forbidden): array
{
    $true = count(array_filter($expected, static fn (string $fact): bool => isset($facts[$fact])));
    $false = count(array_filter($forbidden, static fn (string $fact): bool => isset($facts[$fact])));
    $missed = count($expected) - $true;
    return ['true_positive' => $true, 'false_positive_curated' => $false, 'missed' => $missed];
}

function fusion_benchmark_quality(array $graph): array
{
    $all = array_merge($graph['symbols'] ?? [], $graph['relationships'] ?? [], $graph['routes'] ?? []);
    return [
        'symbols' => count($graph['symbols'] ?? []), 'relationships' => count($graph['relationships'] ?? []), 'routes' => count($graph['routes'] ?? []),
        'resolved_relationships' => count(array_filter($graph['relationships'] ?? [], static fn (array $edge): bool => ($edge['target_key'] ?? null) !== null)),
        'confirmed_facts' => count(array_filter($all, static fn (array $fact): bool => ($fact['metadata']['confidence_label'] ?? null) === 'confirmed')),
        'multi_engine_facts' => count(array_filter($all, static fn (array $fact): bool => (int) ($fact['metadata']['source_count'] ?? 0) > 1)),
    ];
}

$providers = [new PhpAstAnalyzerProvider(), new TreeSitterAnalyzerProvider(), new TypeScriptSemanticAnalyzerProvider(), new AstGrepAnalyzerProvider(), new CtagsAnalyzerProvider()];
$aggregate = ['v3_native' => ['true_positive' => 0, 'false_positive_curated' => 0, 'missed' => 0, 'duration_ms' => 0], 'v3_fused' => ['true_positive' => 0, 'false_positive_curated' => 0, 'missed' => 0, 'duration_ms' => 0]];
$quality = ['v3_native' => ['symbols' => 0, 'relationships' => 0, 'routes' => 0, 'resolved_relationships' => 0, 'confirmed_facts' => 0, 'multi_engine_facts' => 0], 'v3_fused' => ['symbols' => 0, 'relationships' => 0, 'routes' => 0, 'resolved_relationships' => 0, 'confirmed_facts' => 0, 'multi_engine_facts' => 0]];
$single = [];
foreach ($providers as $provider) $single[$provider->id()] = ['true_positive' => 0, 'false_positive_curated' => 0, 'missed' => 0, 'duration_ms' => 0, 'available' => $provider->isAvailable()];
$fixtureRows = [];
$cacheRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-fusion-cache-' . bin2hex(random_bytes(5));

foreach ($fixtures as $name => $truth) {
    $root = __DIR__ . '/fixtures/v2/' . $name;
    $files = fusion_benchmark_files($root);
    $request = new AnalysisRequest($root, $files, AnalysisProfile::QUICK);
    $started = hrtime(true);
    $native = (new NativeAnalyzerProvider())->analyze($request);
    $nativeDuration = (int) round((hrtime(true) - $started) / 1_000_000);
    $nativeScore = fusion_benchmark_score(fusion_benchmark_facts($native->graph), $truth['expected'], $truth['forbidden']);
    foreach ($nativeScore as $key => $value) $aggregate['v3_native'][$key] += $value;
    foreach (fusion_benchmark_quality($native->graph) as $key => $value) $quality['v3_native'][$key] += $value;
    $aggregate['v3_native']['duration_ms'] += $nativeDuration;

    $singleRows = [];
    foreach ($providers as $provider) {
        if (!$provider->isAvailable()) continue;
        $started = hrtime(true); $result = $provider->analyze($request); $duration = (int) round((hrtime(true) - $started) / 1_000_000);
        $score = fusion_benchmark_score(fusion_benchmark_facts($result->graph), $truth['expected'], $truth['forbidden']);
        foreach ($score as $key => $value) $single[$provider->id()][$key] += $value;
        $single[$provider->id()]['duration_ms'] += $duration;
        $singleRows[$provider->id()] = $score + ['duration_ms' => $duration, 'status' => $result->status];
    }

    $started = hrtime(true);
    $fused = (new AnalysisCoordinator(new AnalyzerRegistry(), new EvidenceFusion(), new ProviderCache($cacheRoot)))->analyze($request);
    $fusedDuration = (int) round((hrtime(true) - $started) / 1_000_000);
    $fusedScore = fusion_benchmark_score(fusion_benchmark_facts($fused), $truth['expected'], $truth['forbidden']);
    foreach ($fusedScore as $key => $value) $aggregate['v3_fused'][$key] += $value;
    foreach (fusion_benchmark_quality($fused) as $key => $value) $quality['v3_fused'][$key] += $value;
    $aggregate['v3_fused']['duration_ms'] += $fusedDuration;
    $fixtureRows[$name] = ['expected_count' => count($truth['expected']), 'native' => $nativeScore + ['duration_ms' => $nativeDuration], 'single' => $singleRows, 'fused' => $fusedScore + ['duration_ms' => $fusedDuration]];
}

$metric = static function (array $row): array {
    $recallDenominator = $row['true_positive'] + $row['missed'];
    $precisionDenominator = $row['true_positive'] + $row['false_positive_curated'];
    return $row + ['recall' => $recallDenominator === 0 ? null : round($row['true_positive'] / $recallDenominator, 4), 'curated_precision' => $precisionDenominator === 0 ? null : round($row['true_positive'] / $precisionDenominator, 4)];
};
$aggregate['v3_native'] = $metric($aggregate['v3_native']);
$aggregate['v3_fused'] = $metric($aggregate['v3_fused']);
foreach ($single as &$row) $row = $metric($row); unset($row);
$availableSingles = array_filter($single, static fn (array $row): bool => $row['available']);
uasort($availableSingles, static fn (array $a, array $b): int => ($b['true_positive'] <=> $a['true_positive']) ?: ($a['false_positive_curated'] <=> $b['false_positive_curated']));
$bestId = array_key_first($availableSingles);
$baseline = json_decode((string) file_get_contents(__DIR__ . '/baselines/v2-final.json'), true) ?: [];
$report = [
    'generated_at' => gmdate(DATE_ATOM), 'analysis_version' => AnalysisEngine::VERSION,
    'metric_definition' => 'Recall uses 27 curated positive facts across six versioned local fixtures. Curated precision counts only explicitly enumerated forbidden facts and is not whole-repository precision.',
    'v2_native_historical' => ['accuracy_metrics' => null, 'reason' => 'The preserved V2 baseline recorded regression pass/fail and dogfood counts, not fact-level predictions; precision and recall cannot be reconstructed honestly.', 'regression_status' => $baseline['test_status']['tests/V2AnalysisTest.php']['status'] ?? 'unknown', 'dogfood' => $baseline['dogfood'] ?? null],
    'v3_native' => $aggregate['v3_native'] + ['quality' => $quality['v3_native']], 'single_analyzers' => $single,
    'best_single_external' => $bestId === null ? null : ['provider' => $bestId] + $availableSingles[$bestId],
    'v3_fused' => $aggregate['v3_fused'] + ['quality' => $quality['v3_fused']], 'fixtures' => $fixtureRows,
    'comparison' => 'On these curated facts fused recall equals native recall; fusion adds independently confirmed facts and semantic relationships. The benchmark does not claim a precision gain beyond the enumerated forbidden facts.',
    'peak_memory_bytes' => memory_get_peak_usage(true),
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;

if (is_dir($cacheRoot)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    @rmdir($cacheRoot);
}
