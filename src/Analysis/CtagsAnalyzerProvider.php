<?php

declare(strict_types=1);

final class CtagsAnalyzerProvider implements AnalyzerProviderInterface
{
    public function __construct(private readonly SafeProcessRunner $runner = new SafeProcessRunner())
    {
    }

    public function id(): string { return 'ctags'; }
    public function version(): string { return $this->probeVersion() ?? 'unavailable'; }
    public function supportedLanguages(): array { return ['*']; }
    public function capabilities(): array { return ['symbols']; }
    public function isAvailable(): bool { return ToolDetector::findExecutable('ctags') !== null; }

    public function healthCheck(): AnalyzerHealth
    {
        $version = $this->probeVersion();
        return $version === null
            ? new AnalyzerHealth('unavailable', 'Universal Ctags was not found; richer providers continue without it.')
            : new AnalyzerHealth('ready', 'Universal Ctags JSON output is available as a symbol fallback.', $version);
    }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        $executable = ToolDetector::findExecutable('ctags');
        if ($executable === null) return AnalyzerResult::unavailable($this->id(), 'unavailable', 'Universal Ctags is not installed.');
        $filesByPath = [];
        foreach ($request->files() as $file) {
            $path = str_replace('\\', '/', (string) ($file['path'] ?? ''));
            if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) continue;
            $filesByPath[$path] = $file;
        }
        if ($filesByPath === []) return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, $this->emptyGraph());
        $result = $this->runner->run(new ProcessRunRequest(
            [$executable, '--options=NONE', '--output-format=json', '--fields=+nK', '--extras=-F', '--sort=no', '-L', '-'],
            $request->repositoryRoot(),
            60,
            16_777_216,
            524_288,
            stdin: implode("\n", array_keys($filesByPath)) . "\n",
        ));
        if (!$result->succeeded() || $result->stdoutTruncated) {
            return AnalyzerResult::failed($this->id(), $this->version(), $result->timedOut ? 'Ctags timed out.' : 'Ctags failed or exceeded its output limit.', $result->durationMs);
        }
        $graph = new SymbolGraph();
        $moduleKeys = [];
        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            if ($line === '') continue;
            try { $tag = json_decode($line, true, flags: JSON_THROW_ON_ERROR); } catch (Throwable) { continue; }
            if (!is_array($tag) || ($tag['_type'] ?? '') !== 'tag') continue;
            $path = str_replace('\\', '/', (string) ($tag['path'] ?? ''));
            if (!isset($filesByPath[$path])) continue;
            $name = trim((string) ($tag['name'] ?? ''));
            if ($name === '') continue;
            $moduleKeys[$path] ??= $graph->addModule($filesByPath[$path]);
            $scope = trim((string) ($tag['scope'] ?? ''));
            $kind = strtolower((string) ($tag['kind'] ?? 'symbol'));
            $graph->addSymbol([
                'path' => $path,
                'language' => (string) ($tag['language'] ?? $filesByPath[$path]['language'] ?? 'Unknown'),
                'type' => $this->kind($kind),
                'name' => $name,
                'qualified_name' => $scope === '' ? $name : $scope . '::' . $name,
                'parent_key' => $moduleKeys[$path],
                'start_line' => max(1, (int) ($tag['line'] ?? 1)),
                'confidence' => 'medium',
                'metadata' => ['ctags_kind' => $kind, 'scope_kind' => $tag['scopeKind'] ?? null],
            ]);
        }
        $graph->finalize();
        return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, $graph->toArray(), durationMs: $result->durationMs);
    }

    private function probeVersion(): ?string
    {
        $executable = ToolDetector::findExecutable('ctags');
        if ($executable === null) return null;
        try {
            $result = $this->runner->run(new ProcessRunRequest([$executable, '--version'], dirname(__DIR__, 2), 10, 131072, 131072));
            if (!$result->succeeded()) return null;
            return substr(trim((string) ((preg_split('/\R/', $result->stdout)[0] ?? 'Universal Ctags'))), 0, 100);
        } catch (Throwable) {
            return null;
        }
    }

    private function kind(string $kind): string
    {
        return match ($kind) {
            'class', 'interface', 'trait', 'enum', 'method', 'function', 'namespace', 'module', 'property', 'constant' => $kind,
            'member', 'field' => 'property',
            'variable', 'local' => 'variable',
            default => 'symbol',
        };
    }

    /** @return array<string, mixed> */
    private function emptyGraph(): array
    {
        return ['symbols' => [], 'relationships' => [], 'routes' => [], 'stats' => ['symbols' => 0, 'relationships' => 0, 'routes' => 0]];
    }
}
