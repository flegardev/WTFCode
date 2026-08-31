<?php

declare(strict_types=1);

final class SyftAnalyzerProvider implements AnalyzerProviderInterface
{
    public function __construct(private readonly SafeProcessRunner $runner = new SafeProcessRunner()) {}
    public function id(): string { return 'syft'; }
    public function version(): string { return '1.51.0'; }
    public function supportedLanguages(): array { return ['*']; }
    public function capabilities(): array { return ['dependencies', 'sbom', 'licenses']; }
    public function isAvailable(): bool { return ToolDetector::findExecutable('syft') !== null && is_file($this->config()); }
    public function healthCheck(): AnalyzerHealth { return $this->isAvailable() ? new AnalyzerHealth('ready', 'Syft is available as an optional package inventory provider.', $this->version()) : new AnalyzerHealth('unavailable', 'Syft is not installed; dependency scans continue without an SBOM.'); }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        $binary = ToolDetector::findExecutable('syft');
        if ($binary === null || !is_file($this->config())) return AnalyzerResult::unavailable($this->id(), $this->version(), 'Syft is unavailable.');
        $result = $this->runner->run(new ProcessRunRequest([
            $binary, 'scan', 'dir:' . $request->repositoryRoot(), '-o', 'syft-json', '-q', '-c', $this->config(),
            '--exclude', '**/node_modules/**', '--exclude', '**/vendor/**', '--exclude', '**/.git/**',
            '--exclude', './tools/bin/**', '--exclude', '**/tools/bin/**', '--exclude', './tools/composer.phar',
            '--exclude', './storage/**', '--exclude', '**/storage/**', '--exclude', '**/.next/**',
            '--exclude', '**/dist/**', '--exclude', '**/build/**', '--exclude', '**/coverage/**',
        ], dirname(__DIR__, 2), 120, 33_554_432, 1_048_576));
        if (!$result->succeeded() || $result->stdoutTruncated) return AnalyzerResult::failed($this->id(), $this->version(), $result->timedOut ? 'Syft timed out.' : 'Syft failed or exceeded its output limit.', $result->durationMs);
        try { $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR); }
        catch (Throwable) { return AnalyzerResult::failed($this->id(), $this->version(), 'Syft returned invalid JSON.', $result->durationMs); }
        $packages = self::normalizeReport($report, $request->repositoryRoot());
        $graph = ['symbols' => [], 'relationships' => [], 'routes' => [], 'packages' => $packages, 'stats' => ['symbols' => 0, 'relationships' => 0, 'routes' => 0, 'packages' => count($packages)]];
        return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, $graph, [], $result->durationMs);
    }

    /** @param array<string, mixed> $report @return array<int, array<string, mixed>> */
    public static function normalizeReport(array $report, string $root = ''): array
    {
        $packages = [];
        foreach (is_array($report['artifacts'] ?? null) ? $report['artifacts'] : [] as $artifact) {
            if (!is_array($artifact)) continue;
            $name = trim((string) ($artifact['name'] ?? ''));
            $version = trim((string) ($artifact['version'] ?? ''));
            if ($name === '') continue;
            $locations = [];
            foreach (is_array($artifact['locations'] ?? null) ? $artifact['locations'] : [] as $location) {
                if (!is_array($location)) continue;
                $path = (string) ($location['path'] ?? $location['realPath'] ?? '');
                if ($path !== '') $locations[] = self::relative($root, $path);
            }
            $classification = self::classification($locations);
            $licenses = [];
            foreach (is_array($artifact['licenses'] ?? null) ? $artifact['licenses'] : [] as $license) {
                if (is_string($license)) $licenses[] = $license;
                elseif (is_array($license)) $licenses[] = (string) ($license['value'] ?? $license['spdxExpression'] ?? '');
            }
            $package = [
                'name' => substr($name, 0, 255),
                'version' => substr($version, 0, 160),
                'ecosystem' => substr((string) ($artifact['type'] ?? 'unknown'), 0, 80),
                'purl' => substr((string) ($artifact['purl'] ?? ''), 0, 700),
                'classification' => $classification,
                'licenses' => array_slice(array_values(array_unique(array_filter(array_map('trim', $licenses)))), 0, 20),
                'locations' => array_slice(array_values(array_unique($locations)), 0, 20),
                'provider' => 'syft',
                'provider_version' => '1.51.0',
            ];
            $identity = strtolower(($package['purl'] ?: $package['ecosystem'] . ':' . $package['name'] . '@' . $package['version']));
            $packages[$identity] = $package;
            if (count($packages) >= 10000) break;
        }
        return array_values($packages);
    }

    /** @param array<int, string> $locations */
    private static function classification(array $locations): string
    {
        foreach ($locations as $path) if (preg_match('/(?:^|\/)(?:package-lock\.json|npm-shrinkwrap\.json|yarn\.lock|pnpm-lock\.yaml|composer\.lock|poetry\.lock|Pipfile\.lock|Cargo\.lock|go\.sum|gradle\.lockfile)$/i', $path)) return 'resolved';
        foreach ($locations as $path) if (preg_match('/(?:^|\/)(?:package\.json|composer\.json|requirements[^\/]*\.txt|pyproject\.toml|Pipfile|Cargo\.toml|go\.mod|pom\.xml|build\.gradle)$/i', $path)) return 'declared';
        return 'detected';
    }

    private static function relative(string $root, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        return str_starts_with(strtolower($path), strtolower($prefix)) ? substr($path, strlen($prefix)) : ltrim($path, './');
    }

    private function config(): string { return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'syft.yml'; }
}
