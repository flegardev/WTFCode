<?php

declare(strict_types=1);

final class OsvAnalyzerProvider implements AnalyzerProviderInterface
{
    public function __construct(private readonly SafeProcessRunner $runner = new SafeProcessRunner()) {}
    public function id(): string { return 'osv-scanner'; }
    public function version(): string { return '2.5.1'; }
    public function supportedLanguages(): array { return ['*']; }
    public function capabilities(): array { return ['dependencies', 'vulnerabilities']; }
    public function isAvailable(): bool { return ToolDetector::findExecutable('osv-scanner') !== null; }
    public function healthCheck(): AnalyzerHealth { return $this->isAvailable() ? new AnalyzerHealth('ready', 'OSV-Scanner is available for manifest and lockfile analysis.', $this->version()) : new AnalyzerHealth('unavailable', 'OSV-Scanner is not installed.'); }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        $binary = ToolDetector::findExecutable('osv-scanner');
        if ($binary === null) return AnalyzerResult::unavailable($this->id(), $this->version(), 'OSV-Scanner is unavailable.');
        $result = $this->runner->run(new ProcessRunRequest([
            $binary, 'scan', 'source', '--recursive', '--format=json', '--allow-no-lockfiles', '--no-resolve',
            '--no-call-analysis=go', '--no-call-analysis=rust', $request->repositoryRoot(),
        ], dirname(__DIR__, 2), 120, 33_554_432, 1_048_576));
        if ($result->timedOut || !in_array($result->exitCode, [0, 1], true) || $result->stdoutTruncated) {
            return AnalyzerResult::failed($this->id(), $this->version(), $result->timedOut ? 'OSV-Scanner timed out.' : 'OSV-Scanner failed or exceeded its output limit.', $result->durationMs);
        }
        try { $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR); }
        catch (Throwable) { return AnalyzerResult::failed($this->id(), $this->version(), 'OSV-Scanner returned invalid JSON.', $result->durationMs); }
        return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, self::emptyGraph(), self::normalizeReport($report, $request->repositoryRoot()), $result->durationMs);
    }

    /** @param array<string, mixed> $report @return array<int, array<string, mixed>> */
    public static function normalizeReport(array $report, string $root = ''): array
    {
        $findings = [];
        $walk = function (mixed $node, ?string $sourcePath = null) use (&$walk, &$findings, $root): void {
            if (!is_array($node) || count($findings) >= 4000) return;
            $source = is_array($node['source'] ?? null) ? $node['source'] : [];
            $path = (string) ($source['path'] ?? $source['file'] ?? $sourcePath ?? '');
            if (is_array($node['package'] ?? null) && is_array($node['vulnerabilities'] ?? null)) {
                $package = $node['package'];
                foreach ($node['vulnerabilities'] as $vulnerability) {
                    if (!is_array($vulnerability)) continue;
                    $finding = self::finding($package, $vulnerability, self::relative($root, $path));
                    $key = strtolower(implode('|', [$finding['evidence']['vulnerability_id'], $finding['evidence']['ecosystem'], $finding['evidence']['package'], $finding['evidence']['installed_version']]));
                    $findings[$key] = isset($findings[$key]) ? self::merge($findings[$key], $finding) : $finding;
                }
            }
            foreach ($node as $key => $child) {
                if ($key === 'vulnerabilities' || !is_array($child)) continue;
                $walk($child, $path);
            }
        };
        $walk($report);
        return array_values($findings);
    }

    /** @param array<string, mixed> $package @param array<string, mixed> $vulnerability @return array<string, mixed> */
    private static function finding(array $package, array $vulnerability, string $path): array
    {
        $id = substr((string) ($vulnerability['id'] ?? 'OSV-UNKNOWN'), 0, 100);
        $severity = strtoupper((string) ($vulnerability['database_specific']['severity'] ?? $vulnerability['severity_text'] ?? 'UNKNOWN'));
        $fixed = [];
        foreach (is_array($vulnerability['affected'] ?? null) ? $vulnerability['affected'] : [] as $affected) {
            if (!is_array($affected)) continue;
            foreach (is_array($affected['ranges'] ?? null) ? $affected['ranges'] : [] as $range) {
                foreach (is_array($range['events'] ?? null) ? $range['events'] : [] as $event) {
                    if (is_array($event) && isset($event['fixed'])) $fixed[] = substr((string) $event['fixed'], 0, 100);
                }
            }
        }
        $name = substr((string) ($package['name'] ?? 'unknown'), 0, 255);
        $ecosystem = substr((string) ($package['ecosystem'] ?? 'unknown'), 0, 80);
        $version = substr((string) ($package['version'] ?? 'unknown'), 0, 160);
        return [
            'severity' => in_array($severity, ['CRITICAL', 'HIGH'], true) ? 'risk' : (in_array($severity, ['MODERATE', 'MEDIUM'], true) ? 'attention' : 'info'),
            'type' => 'dependency_vulnerability',
            'title' => $id . ' affects ' . $name,
            'explanation' => 'The resolved dependency version appears in the OSV advisory database. Review the advisory and upgrade to a fixed version where one is listed.',
            'confidence' => 'high',
            'path' => $path === '' ? null : str_replace('\\', '/', $path),
            'evidence' => [
                'rule_id' => $id,
                'vulnerability_id' => $id,
                'package' => $name,
                'ecosystem' => $ecosystem,
                'installed_version' => $version,
                'severity' => $severity,
                'fixed_versions' => array_values(array_unique($fixed)),
                'aliases' => array_slice(array_values(array_filter($vulnerability['aliases'] ?? [], 'is_string')), 0, 20),
                'source' => 'OSV',
                'confirmation_sources' => ['osv-scanner'],
                'context' => 'dependency',
            ],
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right @return array<string, mixed> */
    private static function merge(array $left, array $right): array
    {
        $left['evidence']['fixed_versions'] = array_values(array_unique(array_merge($left['evidence']['fixed_versions'] ?? [], $right['evidence']['fixed_versions'] ?? [])));
        $left['evidence']['aliases'] = array_values(array_unique(array_merge($left['evidence']['aliases'] ?? [], $right['evidence']['aliases'] ?? [])));
        return $left;
    }

    /** @return array<string, mixed> */
    private static function emptyGraph(): array { return ['symbols' => [], 'relationships' => [], 'routes' => [], 'stats' => ['symbols' => 0, 'relationships' => 0, 'routes' => 0]]; }

    private static function relative(string $root, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        return str_starts_with(strtolower($path), strtolower($prefix)) ? substr($path, strlen($prefix)) : ltrim($path, './');
    }
}
