<?php

declare(strict_types=1);

final class SemgrepAnalyzerProvider implements AnalyzerProviderInterface
{
    public function __construct(private readonly SafeProcessRunner $runner = new SafeProcessRunner()) {}
    public function id(): string { return 'semgrep'; }
    public function version(): string { return 'cli'; }
    public function supportedLanguages(): array { return ['PHP', 'JavaScript', 'TypeScript', 'Python']; }
    public function capabilities(): array { return ['security', 'static-analysis', 'cwe', 'owasp']; }
    public function isAvailable(): bool { return ToolDetector::findExecutable('semgrep') !== null && is_file($this->rules()); }
    public function healthCheck(): AnalyzerHealth { return $this->isAvailable() ? new AnalyzerHealth('ready', 'Semgrep and the pinned WTFCode ruleset are available.', $this->version()) : new AnalyzerHealth('unavailable', 'Semgrep is not installed; other security analyzers continue.'); }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        $binary = ToolDetector::findExecutable('semgrep');
        if ($binary === null || !is_file($this->rules())) return AnalyzerResult::unavailable($this->id(), $this->version(), 'Semgrep is unavailable.');
        $result = $this->runner->run(new ProcessRunRequest([
            $binary, 'scan', '--json', '--quiet', '--metrics=off', '--disable-version-check', '--no-rewrite-rule-ids',
            '--max-target-bytes=262144', '--timeout=5', '--timeout-threshold=3', '--config', $this->rules(), $request->repositoryRoot(),
        ], dirname(__DIR__, 2), 90, 16_777_216, 524_288));
        if ($result->timedOut || !in_array($result->exitCode, [0, 1], true) || $result->stdoutTruncated) {
            return AnalyzerResult::failed($this->id(), $this->version(), $result->timedOut ? 'Semgrep timed out.' : 'Semgrep failed or exceeded its output limit.', $result->durationMs);
        }
        try { $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR); }
        catch (Throwable) { return AnalyzerResult::failed($this->id(), $this->version(), 'Semgrep returned invalid JSON.', $result->durationMs); }
        return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, self::emptyGraph(), self::normalizeReport($report, $request->repositoryRoot()), $result->durationMs);
    }

    /** @param array<string, mixed> $report @return array<int, array<string, mixed>> */
    public static function normalizeReport(array $report, string $root = ''): array
    {
        $findings = [];
        foreach (is_array($report['results'] ?? null) ? $report['results'] : [] as $row) {
            if (!is_array($row)) continue;
            $extra = is_array($row['extra'] ?? null) ? $row['extra'] : [];
            $metadata = is_array($extra['metadata'] ?? null) ? $extra['metadata'] : [];
            $rule = substr((string) ($row['check_id'] ?? 'semgrep'), 0, 180);
            $cwe = self::strings($metadata['cwe'] ?? []);
            $owasp = self::strings($metadata['owasp'] ?? []);
            $severity = strtoupper((string) ($extra['severity'] ?? 'INFO'));
            $path = self::relative($root, (string) ($row['path'] ?? ''));
            $findings[] = [
                'severity' => in_array($severity, ['ERROR', 'CRITICAL', 'HIGH'], true) ? 'risk' : (in_array($severity, ['WARNING', 'MEDIUM'], true) ? 'attention' : 'info'),
                'type' => 'code_security_finding',
                'title' => self::plainTitle($rule, $cwe),
                'explanation' => self::plainExplanation($rule, (string) ($extra['message'] ?? 'Review this code path and its input boundaries.')),
                'confidence' => 'high',
                'path' => $path,
                'evidence' => [
                    'rule_id' => $rule,
                    'line' => max(1, (int) ($row['start']['line'] ?? 1)),
                    'line_end' => max(1, (int) ($row['end']['line'] ?? $row['start']['line'] ?? 1)),
                    'severity' => $severity,
                    'cwe' => $cwe,
                    'owasp' => $owasp,
                    'context' => 'runtime',
                ],
            ];
            if (count($findings) >= 2000) break;
        }
        return $findings;
    }

    /** @return array<int, string> */
    private static function strings(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        return array_slice(array_values(array_unique(array_map(static fn ($item): string => substr(trim((string) $item), 0, 120), array_filter($values, static fn ($item): bool => is_scalar($item))))), 0, 12);
    }

    /** @param array<int, string> $cwe */
    private static function plainTitle(string $rule, array $cwe): string
    {
        $signal = strtolower($rule . ' ' . implode(' ', $cwe));
        return match (true) {
            str_contains($signal, 'command') || str_contains($signal, 'cwe-78') => 'Potential command injection',
            str_contains($signal, 'sql') || str_contains($signal, 'cwe-89') => 'Potential SQL injection',
            str_contains($signal, 'eval') || str_contains($signal, 'code-injection') => 'Potential dynamic code execution',
            str_contains($signal, 'path') || str_contains($signal, 'cwe-22') => 'Potential unsafe file path',
            default => 'Code security finding',
        };
    }

    private static function plainExplanation(string $rule, string $message): string
    {
        $signal = strtolower($rule);
        if (str_contains($signal, 'command')) return 'External data may influence a system command. Trace the value to its source and use an argument-array API or strict allowlist.';
        if (str_contains($signal, 'sql')) return 'External data may influence a database query. Use parameterized queries and verify the input boundary.';
        if (str_contains($signal, 'eval')) return 'Text is evaluated as code. Confirm that outside data cannot reach this call and replace dynamic evaluation where possible.';
        $clean = SensitiveDataSanitizer::text(strip_tags($message));
        return substr($clean === '' ? 'Review this code path and its input boundaries.' : $clean, 0, 700);
    }

    private static function relative(string $root, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        return str_starts_with(strtolower($path), strtolower($prefix)) ? substr($path, strlen($prefix)) : ltrim($path, './');
    }

    /** @return array<string, mixed> */
    private static function emptyGraph(): array { return ['symbols' => [], 'relationships' => [], 'routes' => [], 'stats' => ['symbols' => 0, 'relationships' => 0, 'routes' => 0]]; }
    private function rules(): string { return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'semgrep.yml'; }
}
