<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function foundation_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class FoundationFixtureProvider implements AnalyzerProviderInterface
{
    /** @param array<string, mixed> $graph */
    public function __construct(private readonly string $providerId, private readonly array $graph, private readonly bool $throw = false)
    {
    }

    public function id(): string { return $this->providerId; }
    public function version(): string { return '1.0.0'; }
    public function supportedLanguages(): array { return ['PHP']; }
    public function capabilities(): array { return ['symbols', 'references']; }
    public function isAvailable(): bool { return true; }
    public function healthCheck(): AnalyzerHealth { return new AnalyzerHealth('ready', 'Fixture provider ready.', $this->version()); }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        if ($this->throw) throw new RuntimeException('fixture explosion');
        return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, $this->graph, durationMs: 1);
    }
}

$symbolA = [
    'key' => 'provider-a-auth', 'path' => 'src/Auth.php', 'language' => 'PHP', 'type' => 'class',
    'name' => 'Auth', 'qualified_name' => 'App\\Auth', 'parent_key' => null, 'signature' => null,
    'visibility' => 'public', 'exported' => false, 'start_line' => 3, 'end_line' => 20,
    'confidence' => 'medium', 'metadata' => [],
];
$symbolB = $symbolA;
$symbolB['key'] = 'provider-b-auth';
$targetA = $symbolA;
$targetA['key'] = 'provider-a-user';
$targetA['name'] = 'User';
$targetA['qualified_name'] = 'App\\User';
$targetA['start_line'] = 22;
$targetA['end_line'] = 40;
$targetB = $targetA;
$targetB['key'] = 'provider-b-user';
$relationshipA = [
    'source_key' => 'provider-a-auth', 'target_key' => 'provider-a-user', 'external_name' => null,
    'target_name' => 'User', 'type' => 'calls', 'confidence' => 'medium', 'evidence_path' => 'src/Auth.php',
    'line_start' => 12, 'line_end' => 12, 'excerpt' => null, 'metadata' => [],
];
$relationshipB = $relationshipA;
$relationshipB['source_key'] = 'provider-b-auth';
$relationshipB['target_key'] = 'provider-b-user';
$graphA = ['symbols' => [$symbolA, $targetA], 'relationships' => [$relationshipA], 'routes' => [], 'stats' => []];
$graphB = ['symbols' => [$symbolB, $targetB], 'relationships' => [$relationshipB], 'routes' => [], 'stats' => []];
$fused = (new EvidenceFusion())->fuse([
    (new FoundationFixtureProvider('engine-a', $graphA))->analyze(new AnalysisRequest(__DIR__, [])),
    (new FoundationFixtureProvider('engine-b', $graphB))->analyze(new AnalysisRequest(__DIR__, [])),
]);
foundation_assert(count($fused['symbols']) === 2, 'Equivalent symbols from two engines must fuse into one identity each');
foundation_assert(count($fused['relationships']) === 1, 'Equivalent relationships from two engines must not be duplicated');
foundation_assert(($fused['relationships'][0]['metadata']['source_count'] ?? 0) === 2, 'Fused relationships must retain both evidence sources');
foundation_assert(($fused['relationships'][0]['metadata']['confidence_label'] ?? '') === 'confirmed', 'Independent engine agreement must promote the explanation label');
foundation_assert(($fused['relationships'][0]['metadata']['provenance'][0]['evidence_file'] ?? '') === 'src/Auth.php', 'Provenance must retain the evidence file');

$request = new AnalysisRequest(__DIR__ . '/fixtures/v2/plain-php', [[
    'path' => 'src/AuthService.php', 'language' => 'PHP', 'content' => "<?php class AuthService {}", 'lines' => 1,
]]);
$registry = new AnalyzerRegistry([
    new NativeAnalyzerProvider(),
    new FoundationFixtureProvider('exploding-engine', [], true),
]);
$isolated = (new AnalysisCoordinator($registry))->analyze($request);
foundation_assert(($isolated['stats']['symbols'] ?? 0) > 0, 'A failed optional provider must not discard successful native analysis');
foundation_assert(($isolated['stats']['engines_failed'] ?? 0) === 1, 'Provider failures must be represented in engine status');
foundation_assert(count($isolated['engine_runs'] ?? []) === 2, 'Every attempted provider must produce a run summary');

$literal = 'literal;echo SHOULD_NOT_RUN';
$process = (new SafeProcessRunner())->run(new ProcessRunRequest(
    [PHP_BINARY, '-r', 'echo $argv[1];', $literal],
    __DIR__,
    5,
    4096,
    4096,
));
foundation_assert($process->succeeded(), 'Safe process runner should execute a trusted binary with an argument array');
foundation_assert($process->stdout === $literal, 'Process arguments must be passed literally without shell interpretation');

$limited = (new SafeProcessRunner())->run(new ProcessRunRequest(
    [PHP_BINARY, '-r', 'echo str_repeat("x", 5000);'],
    __DIR__,
    5,
    1024,
    1024,
));
foundation_assert(strlen($limited->stdout) === 1024 && $limited->stdoutTruncated, 'Process output must be capped and marked as truncated');

$timed = (new SafeProcessRunner())->run(new ProcessRunRequest(
    [PHP_BINARY, '-r', 'sleep(2);'],
    __DIR__,
    1,
    4096,
    4096,
));
foundation_assert($timed->timedOut && !$timed->succeeded(), 'Long-running analyzers must time out independently');

$environmentRejected = false;
try {
    new ProcessRunRequest([PHP_BINARY, '-v'], __DIR__, environment: ['UNSAFE_REPOSITORY_VALUE' => 'x']);
} catch (InvalidArgumentException) {
    $environmentRejected = true;
}
foundation_assert($environmentRejected, 'Analyzer processes must reject environment keys outside the allowlist');

echo "WTFCode V3 foundation checks passed.\n";
