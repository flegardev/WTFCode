<?php

declare(strict_types=1);

abstract class NodeWorkerAnalyzerProvider implements AnalyzerProviderInterface
{
    public function __construct(private readonly SafeProcessRunner $runner = new SafeProcessRunner())
    {
    }

    abstract protected function workerFile(): string;
    abstract protected function dependencyMarker(): string;
    protected function maxFiles(): int { return 750; }
    protected function maxInputBytes(): int { return 8_388_608; }

    public function isAvailable(): bool
    {
        return ToolDetector::findExecutable('node') !== null
            && is_file($this->root() . DIRECTORY_SEPARATOR . $this->workerFile())
            && is_file($this->root() . DIRECTORY_SEPARATOR . $this->dependencyMarker());
    }

    public function healthCheck(): AnalyzerHealth
    {
        return $this->isAvailable()
            ? new AnalyzerHealth('ready', 'Isolated Node worker and pinned dependencies are available.', $this->version())
            : new AnalyzerHealth('unavailable', 'Node worker dependencies are not installed.', $this->version());
    }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        if (!$this->isAvailable()) return AnalyzerResult::unavailable($this->id(), $this->version(), 'Node worker is unavailable.');
        $supported = array_flip($this->supportedLanguages());
        $files = [];
        $inputBytes = 0;
        $selectionLimited = false;
        foreach ($request->files() as $file) {
            if (!isset($supported[(string) ($file['language'] ?? '')])) continue;
            $content = (string) ($file['content'] ?? '');
            if (count($files) >= $this->maxFiles() || $inputBytes + strlen($content) > $this->maxInputBytes()) {
                $selectionLimited = true;
                break;
            }
            $files[] = [
                'path' => (string) ($file['path'] ?? ''),
                'language' => (string) ($file['language'] ?? 'Unknown'),
                'lines' => max(1, (int) ($file['lines'] ?? 1)),
                'content' => $content,
            ];
            $inputBytes += strlen($content);
        }
        if ($files === []) return new AnalyzerResult($this->id(), $this->version(), AnalyzerResult::SUCCESS, self::emptyGraph(), durationMs: 0, message: 'No supported files were present.');
        $payload = json_encode(['repository' => $request->repositoryRoot(), 'files' => $files], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $node = ToolDetector::findExecutable('node');
        if ($node === null) return AnalyzerResult::unavailable($this->id(), $this->version(), 'Node executable is unavailable.');
        $result = $this->runner->run(new ProcessRunRequest(
            [$node, $this->root() . DIRECTORY_SEPARATOR . $this->workerFile()],
            $this->root(),
            60,
            16_777_216,
            524_288,
            stdin: $payload,
        ));
        if (!$result->succeeded() || $result->stdoutTruncated) {
            $reason = $result->timedOut ? 'timed out' : ($result->stdoutTruncated ? 'exceeded its output limit' : 'exited with code ' . $result->exitCode);
            return AnalyzerResult::failed($this->id(), $this->version(), 'Node worker ' . $reason . '.', $result->durationMs);
        }
        try {
            $graph = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($graph) || !is_array($graph['symbols'] ?? null) || !is_array($graph['relationships'] ?? null) || !is_array($graph['routes'] ?? null)) {
                throw new UnexpectedValueException('Worker returned an invalid graph envelope.');
            }
            $errors = is_array($graph['errors'] ?? null) ? $graph['errors'] : [];
            unset($graph['errors']);
            $partial = $errors !== [] || $selectionLimited;
            $messages = [];
            if ($errors !== []) $messages[] = count($errors) . ' file(s) contained syntax the worker could not fully parse.';
            if ($selectionLimited) $messages[] = 'Worker input was capped at ' . $this->maxFiles() . ' files or ' . $this->maxInputBytes() . ' bytes.';
            return new AnalyzerResult(
                $this->id(), $this->version(), $partial ? AnalyzerResult::PARTIAL : AnalyzerResult::SUCCESS,
                $graph, [], $result->durationMs,
                $messages === [] ? null : implode(' ', $messages),
            );
        } catch (Throwable $exception) {
            return AnalyzerResult::failed($this->id(), $this->version(), 'Node worker returned invalid JSON.', $result->durationMs);
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    private static function emptyGraph(): array
    {
        return ['symbols' => [], 'relationships' => [], 'routes' => [], 'stats' => ['symbols' => 0, 'relationships' => 0, 'routes' => 0]];
    }
}
