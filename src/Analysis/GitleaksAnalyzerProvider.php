<?php

declare(strict_types=1);

final class GitleaksAnalyzerProvider implements AnalyzerProviderInterface
{
    public function __construct(private readonly SafeProcessRunner $runner = new SafeProcessRunner()) {}
    public function id(): string { return 'gitleaks'; }
    public function version(): string { return '8.30.1'; }
    public function supportedLanguages(): array { return ['*']; }
    public function capabilities(): array { return ['secrets', 'security']; }
    public function isAvailable(): bool { return ToolDetector::findExecutable('gitleaks') !== null && is_file($this->config()); }
    public function healthCheck(): AnalyzerHealth { return $this->isAvailable() ? new AnalyzerHealth('ready', 'Gitleaks and the pinned trusted ruleset are available.', $this->version()) : new AnalyzerHealth('unavailable', 'Gitleaks or its trusted ruleset is unavailable.'); }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        $binary = ToolDetector::findExecutable('gitleaks');
        if ($binary === null || !is_file($this->config())) return AnalyzerResult::unavailable($this->id(), 'unavailable', 'Gitleaks is unavailable.');
        $history = $request->profile() === AnalysisProfile::MAXIMUM && is_dir($request->repositoryRoot() . DIRECTORY_SEPARATOR . '.git');
        $command = [
            $binary, $history ? 'git' : 'dir', '--no-banner', '--no-color', '--redact=100', '--report-format', 'json', '--report-path', '-',
            '--exit-code', '0', '--timeout', '45', '--max-target-megabytes', '1', '--config', $this->config(),
        ];
        if ($history) array_push($command, '--log-opts=--all');
        $command[] = $request->repositoryRoot();
        $result = $this->runner->run(new ProcessRunRequest($command, dirname(__DIR__, 2), 60, 8_388_608, 524_288));
        if (!$result->succeeded() || $result->stdoutTruncated) return AnalyzerResult::failed($this->id(), $this->version(), $result->timedOut ? 'Gitleaks timed out.' : 'Gitleaks failed or exceeded its output limit.', $result->durationMs);
        try { $rows = trim($result->stdout) === '' ? [] : json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR); } catch (Throwable) { return AnalyzerResult::failed($this->id(), $this->version(), 'Gitleaks returned invalid JSON.', $result->durationMs); }
        $findings = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) continue;
            $path = $this->relativePath($request->repositoryRoot(), (string) ($row['File'] ?? ''));
            $finding = [
                'severity' => 'risk', 'type' => 'possible_exposed_secret', 'title' => 'Possible exposed secret',
                'explanation' => 'A value matches a secret-detection rule. If it is real, rotate it, remove it from Git history, and load it from protected environment configuration.',
                'confidence' => 'high',
                'path' => $path, 'evidence' => [
                    'rule_id' => substr((string) ($row['RuleID'] ?? 'gitleaks'), 0, 120),
                    'secret_type' => substr((string) ($row['Description'] ?? $row['RuleID'] ?? 'secret'), 0, 160),
                    'line' => max(1, (int) ($row['StartLine'] ?? 1)),
                    'line_end' => max(1, (int) ($row['EndLine'] ?? $row['StartLine'] ?? 1)),
                    'commit' => preg_match('/^[a-f0-9]{7,64}$/i', (string) ($row['Commit'] ?? '')) ? (string) $row['Commit'] : null,
                    'fingerprint' => hash('sha256', implode('|', [(string) ($row['RuleID'] ?? ''), $path, (string) ($row['StartLine'] ?? ''), (string) ($row['Commit'] ?? '')])),
                    'masked' => true,
                    'masked_preview' => '<redacted>',
                    'context' => $history ? 'git-history' : 'working-tree',
                ],
            ];
            $findings[] = SensitiveDataSanitizer::finding($finding);
            if (count($findings) >= 1000) break;
        }
        $graph = ['symbols' => [], 'relationships' => [], 'routes' => [], 'stats' => ['symbols' => 0, 'relationships' => 0, 'routes' => 0]];
        return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, $graph, $findings, $result->durationMs);
    }

    private function config(): string { return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'gitleaks.toml'; }

    private function relativePath(string $root, string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (str_starts_with(strtolower($normalized), strtolower($prefix))) $normalized = substr($normalized, strlen($prefix));
        return ltrim(substr($normalized, 0, 500), './');
    }
}
