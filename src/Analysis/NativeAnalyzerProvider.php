<?php

declare(strict_types=1);

final class NativeAnalyzerProvider implements AnalyzerProviderInterface
{
    public function id(): string
    {
        return 'wtfcode-native';
    }

    public function version(): string
    {
        return AnalysisEngine::NATIVE_VERSION;
    }

    public function supportedLanguages(): array
    {
        return ['PHP', 'JavaScript', 'TypeScript', 'Python', 'SQL', 'Vue'];
    }

    public function capabilities(): array
    {
        return ['syntax', 'symbols', 'references', 'calls', 'routes', 'inheritance', 'database', 'dependencies'];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        $started = hrtime(true);
        try {
            $graph = (new AnalysisEngine())->analyse($request->files());
            return new AnalyzerResult(
                $this->id(),
                $this->version(),
                AnalyzerResult::SUCCESS,
                $graph,
                durationMs: (int) round((hrtime(true) - $started) / 1_000_000),
            );
        } catch (Throwable $exception) {
            return AnalyzerResult::failed(
                $this->id(),
                $this->version(),
                'Native analysis failed: ' . $exception->getMessage(),
                (int) round((hrtime(true) - $started) / 1_000_000),
            );
        }
    }

    public function healthCheck(): AnalyzerHealth
    {
        return new AnalyzerHealth('ready', 'Built-in read-only analyzer is available.', $this->version());
    }
}
