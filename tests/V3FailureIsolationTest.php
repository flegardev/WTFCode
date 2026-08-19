<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function isolation_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class IsolationProvider implements AnalyzerProviderInterface
{
    public function __construct(private readonly string $providerId, private readonly bool $available, private readonly bool $crash = false) {}
    public function id(): string { return $this->providerId; }
    public function version(): string { return 'isolation-1'; }
    public function supportedLanguages(): array { return ['PHP']; }
    public function capabilities(): array { return ['isolation-test']; }
    public function isAvailable(): bool { return $this->available; }
    public function healthCheck(): AnalyzerHealth { return new AnalyzerHealth($this->available ? 'ready' : 'optional', 'Isolation fixture.', $this->version()); }
    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        if ($this->crash) throw new RuntimeException('simulated isolated provider crash');
        return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, ['symbols' => [], 'relationships' => [], 'routes' => [], 'stats' => []]);
    }
}

$providers = [new NativeAnalyzerProvider()];
foreach (['ast-grep', 'ripgrep', 'osv-scanner', 'typescript-semantic', 'tree-sitter', 'ctags'] as $missing) $providers[] = new IsolationProvider($missing, false);
$providers[] = new IsolationProvider('gitleaks', true, true);
$providers[] = new IsolationProvider('semgrep', true, true);
$request = new AnalysisRequest(__DIR__ . '/fixtures/v2/plain-php', [['path' => 'safe.php', 'language' => 'PHP', 'content' => '<?php function safeScan() {}', 'hash' => hash('sha256', 'safe'), 'lines' => 1]], AnalysisProfile::MAXIMUM);
$result = (new AnalysisCoordinator(new AnalyzerRegistry($providers)))->analyze($request);
isolation_assert(($result['stats']['symbols'] ?? 0) > 0, 'Core native analysis must survive missing and crashing optional providers');
isolation_assert(($result['stats']['engines_unavailable'] ?? 0) === 6, 'Every missing provider must report unavailable independently');
isolation_assert(($result['stats']['engines_failed'] ?? 0) === 2, 'Every crashing provider must report failed independently');
isolation_assert(count($result['engine_runs']) === count($providers), 'Every attempted provider needs an independent run record');

echo "WTFCode V3 failure-isolation checks passed.\n";
