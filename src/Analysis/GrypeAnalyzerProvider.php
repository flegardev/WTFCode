<?php

declare(strict_types=1);

final class GrypeAnalyzerProvider implements AnalyzerProviderInterface
{
    public function __construct(private readonly SafeProcessRunner $runner = new SafeProcessRunner()) {}
    public function id(): string { return 'grype'; }
    public function version(): string { return '0.117.0'; }
    public function supportedLanguages(): array { return ['*']; }
    public function capabilities(): array { return ['dependencies', 'vulnerabilities', 'sbom-correlation']; }
    public function isAvailable(): bool { return ToolDetector::findExecutable('grype') !== null && is_file($this->config()); }
    public function healthCheck(): AnalyzerHealth { return $this->isAvailable() ? new AnalyzerHealth('ready', 'Grype is available as an optional offline vulnerability confirmation provider.', $this->version()) : new AnalyzerHealth('unavailable', 'Grype is not installed; OSV remains the primary dependency vulnerability provider.'); }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        $binary = ToolDetector::findExecutable('grype');
        if ($binary === null || !is_file($this->config())) return AnalyzerResult::unavailable($this->id(), $this->version(), 'Grype is unavailable.');
        $result = $this->runner->run(new ProcessRunRequest([
            $binary, 'dir:' . $request->repositoryRoot(), '-o', 'json', '-q', '--config', $this->config(),
            '--exclude', '**/node_modules/**', '--exclude', '**/vendor/**', '--exclude', '**/.git/**',
            '--exclude', './tools/bin/**', '--exclude', '**/tools/bin/**', '--exclude', './tools/composer.phar',
            '--exclude', './storage/**', '--exclude', '**/storage/**', '--exclude', '**/.next/**',
            '--exclude', '**/dist/**', '--exclude', '**/build/**', '--exclude', '**/coverage/**',
        ], dirname(__DIR__, 2), 120, 33_554_432, 1_048_576));
        if (!$result->succeeded() || $result->stdoutTruncated) return AnalyzerResult::failed($this->id(), $this->version(), $result->timedOut ? 'Grype timed out.' : 'Grype failed or exceeded its output limit.', $result->durationMs);
        try { $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR); }
        catch (Throwable) { return AnalyzerResult::failed($this->id(), $this->version(), 'Grype returned invalid JSON.', $result->durationMs); }
        return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, self::emptyGraph(), self::normalizeReport($report, $request->repositoryRoot()), $result->durationMs);
    }

    /** @param array<string, mixed> $report @return array<int, array<string, mixed>> */
    public static function normalizeReport(array $report, string $root = ''): array
    {
        $findings = [];
        foreach (is_array($report['matches'] ?? null) ? $report['matches'] : [] as $match) {
            if (!is_array($match)) continue;
            $vulnerability = is_array($match['vulnerability'] ?? null) ? $match['vulnerability'] : [];
            $artifact = is_array($match['artifact'] ?? null) ? $match['artifact'] : [];
            $id = substr((string) ($vulnerability['id'] ?? 'GRYPE-UNKNOWN'), 0, 100);
            $name = substr((string) ($artifact['name'] ?? 'unknown'), 0, 255);
            $version = substr((string) ($artifact['version'] ?? 'unknown'), 0, 160);
            $ecosystem = self::ecosystem((string) ($artifact['type'] ?? 'unknown'));
            $severity = strtoupper((string) ($vulnerability['severity'] ?? 'UNKNOWN'));
            $fix = is_array($vulnerability['fix'] ?? null) ? $vulnerability['fix'] : [];
            $aliases = [];
            foreach (is_array($match['relatedVulnerabilities'] ?? null) ? $match['relatedVulnerabilities'] : [] as $related) if (is_array($related) && isset($related['id'])) $aliases[] = substr((string) $related['id'], 0, 100);
            $locations = is_array($artifact['locations'] ?? null) ? $artifact['locations'] : [];
            $path = '';
            foreach ($locations as $location) if (is_array($location) && isset($location['path'])) { $path = self::relative($root, (string) $location['path']); break; }
            $findings[] = [
                'severity' => in_array($severity, ['CRITICAL', 'HIGH'], true) ? 'risk' : (in_array($severity, ['MEDIUM', 'MODERATE'], true) ? 'attention' : 'info'),
                'type' => 'dependency_vulnerability',
                'title' => $id . ' affects ' . $name,
                'explanation' => 'Grype matched this resolved package against its local vulnerability database. Confirm the advisory and upgrade where a fixed version is available.',
                'confidence' => 'high',
                'path' => $path === '' ? null : $path,
                'evidence' => [
                    'rule_id' => $id,
                    'vulnerability_id' => $id,
                    'package' => $name,
                    'ecosystem' => $ecosystem,
                    'installed_version' => $version,
                    'severity' => $severity,
                    'fixed_versions' => array_slice(array_values(array_filter($fix['versions'] ?? [], 'is_string')), 0, 20),
                    'fix_state' => substr((string) ($fix['state'] ?? 'unknown'), 0, 60),
                    'aliases' => array_values(array_unique($aliases)),
                    'source' => 'Grype',
                    'confirmation_sources' => ['grype'],
                    'context' => 'dependency',
                ],
            ];
            if (count($findings) >= 4000) break;
        }
        return $findings;
    }

    private static function relative(string $root, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        return str_starts_with(strtolower($path), strtolower($prefix)) ? substr($path, strlen($prefix)) : ltrim($path, './');
    }

    private static function ecosystem(string $type): string
    {
        return match (strtolower($type)) {
            'javascript', 'node', 'npm' => 'npm',
            'php', 'composer' => 'Packagist',
            'python', 'pypi' => 'PyPI',
            'go-module', 'golang', 'go' => 'Go',
            'rust', 'cargo' => 'crates.io',
            'java', 'maven' => 'Maven',
            default => substr($type, 0, 80),
        };
    }

    /** @return array<string, mixed> */
    private static function emptyGraph(): array { return ['symbols' => [], 'relationships' => [], 'routes' => [], 'stats' => ['symbols' => 0, 'relationships' => 0, 'routes' => 0]]; }
    private function config(): string { return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'grype.yml'; }
}
