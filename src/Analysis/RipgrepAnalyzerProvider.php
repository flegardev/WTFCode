<?php

declare(strict_types=1);

final class RipgrepAnalyzerProvider implements AnalyzerProviderInterface
{
    public function __construct(private readonly SafeProcessRunner $runner = new SafeProcessRunner()) {}
    public function id(): string { return 'ripgrep'; }
    public function version(): string { return '15.2.0'; }
    public function supportedLanguages(): array { return ['*']; }
    public function capabilities(): array { return ['search', 'security', 'dependencies']; }
    public function isAvailable(): bool { return ToolDetector::findExecutable('rg') !== null; }
    public function healthCheck(): AnalyzerHealth { return $this->isAvailable() ? new AnalyzerHealth('ready', 'Bounded repository search is available.', $this->version()) : new AnalyzerHealth('unavailable', 'ripgrep was not found.'); }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        $rg = ToolDetector::findExecutable('rg');
        if ($rg === null) return AnalyzerResult::unavailable($this->id(), 'unavailable', 'ripgrep is not installed.');
        $pattern = '(?:\beval\s*\(|\bshell_exec\s*\(|\bexec\s*\(|child_process|Runtime\.getRuntime\(\)\.exec|ProcessBuilder\s*\(|\$_(?:ENV|SERVER)\s*\[|process\.env\.[A-Z][A-Z0-9_]*|getenv\s*\()';
        $result = $this->runner->run(new ProcessRunRequest([
            $rg, '--json', '--no-messages', '--hidden', '--max-filesize', '256K',
            '--glob', '!.git/**', '--glob', '!node_modules/**', '--glob', '!vendor/**', '--glob', '!storage/**',
            '--glob', '!dist/**', '--glob', '!build/**', '-e', $pattern, '.',
        ], $request->repositoryRoot(), 30, 8_388_608, 262_144));
        if ($result->timedOut || !in_array($result->exitCode, [0, 1], true) || $result->stdoutTruncated) {
            return AnalyzerResult::failed($this->id(), $this->version(), $result->timedOut ? 'ripgrep timed out.' : 'ripgrep failed or exceeded its output limit.', $result->durationMs);
        }
        $runtimeLanguages = array_flip(['PHP', 'JavaScript', 'TypeScript', 'Python', 'Ruby', 'Go', 'Java', 'C', 'C++', 'C#', 'Rust']);
        $allowed = [];
        foreach ($request->files() as $file) {
            $path = str_replace('\\', '/', (string) ($file['path'] ?? ''));
            if (!isset($runtimeLanguages[(string) ($file['language'] ?? '')]) || preg_match('#(^|/)(?:tests?|specs?|fixtures?|rules?|workers?)(/|$)#i', $path) === 1) continue;
            $allowed[$path] = (string) ($file['language'] ?? 'Unknown');
        }
        $findings = [];
        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            if ($line === '') continue;
            try { $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR); } catch (Throwable) { continue; }
            if (($event['type'] ?? '') !== 'match') continue;
            $path = str_replace('\\', '/', ltrim((string) ($event['data']['path']['text'] ?? ''), './\\'));
            if (!isset($allowed[$path])) continue;
            $lineNumber = max(1, (int) ($event['data']['line_number'] ?? 1));
            $sourceLine = (string) ($event['data']['lines']['text'] ?? '');
            if (str_contains($sourceLine, 'wtfcode.rg.') || preg_match('/\$(?:pattern|rules?)\s*=/', $sourceLine) === 1) continue;
            $matched = strtolower((string) ($event['data']['submatches'][0]['match']['text'] ?? ''));
            $process = preg_match('/eval|exec|child_process|processbuilder/', $matched) === 1;
            $findings[] = [
                'severity' => $process ? 'risk' : 'info',
                'type' => $process ? 'process_or_dynamic_execution' : 'environment_access',
                'title' => $process ? 'Process or dynamic execution surface' : 'Environment configuration access',
                'explanation' => $process ? 'This file contains an execution API. Review how its arguments are built and whether outside input can reach them.' : 'This file reads environment configuration. Values are intentionally not collected.',
                'confidence' => 'low',
                'path' => $path,
                'evidence' => ['rule_id' => $process ? 'wtfcode.rg.execution' : 'wtfcode.rg.environment', 'line' => $lineNumber, 'language' => $allowed[$path], 'context' => 'runtime'],
            ];
            if (count($findings) >= 1000) break;
        }
        $graph = ['symbols' => [], 'relationships' => [], 'routes' => [], 'stats' => ['symbols' => 0, 'relationships' => 0, 'routes' => 0]];
        return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, $graph, $findings, $result->durationMs);
    }
}
