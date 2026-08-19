<?php

declare(strict_types=1);

final class AnalysisCoordinator
{
    public function __construct(
        private readonly AnalyzerRegistry $registry = new AnalyzerRegistry(),
        private readonly EvidenceFusion $fusion = new EvidenceFusion(),
    ) {
    }

    /** @return array<string, mixed> */
    public function analyze(AnalysisRequest $request): array
    {
        $results = [];
        foreach ($this->registry->all() as $index => $provider) {
            $id = 'provider-' . $index;
            $version = 'unknown';
            try {
                $id = $provider->id();
                $version = $provider->version();
                if (!$provider->isAvailable()) {
                    $results[] = AnalyzerResult::unavailable($id, $version, 'Analyzer is not installed or not supported on this machine.');
                    continue;
                }
                $results[] = $this->compact($provider->analyze($request));
            } catch (Throwable $exception) {
                $results[] = AnalyzerResult::failed($id, $version, 'Analyzer failed independently: ' . get_class($exception));
            }
        }
        return $this->fusion->fuse($results);
    }

    private function compact(AnalyzerResult $result): AnalyzerResult
    {
        if (!in_array($result->status, [AnalyzerResult::SUCCESS, AnalyzerResult::PARTIAL], true)) return $result;
        [$symbolLimit, $relationshipLimit, $routeLimit] = match ($result->engine) {
            'wtfcode-native' => [8000, 16000, 4000],
            'php-parser' => [2500, 5000, 1000],
            'tree-sitter' => [2000, 4000, 500],
            'typescript-semantic' => [3000, 6000, 1000],
            'ctags' => [1500, 0, 0],
            default => [2000, 4000, 1000],
        };
        $graph = $result->graph;
        $original = [count($graph['symbols'] ?? []), count($graph['relationships'] ?? []), count($graph['routes'] ?? [])];
        $graph['symbols'] = array_slice($graph['symbols'] ?? [], 0, $symbolLimit);
        $allowedKeys = array_fill_keys(array_filter(array_column($graph['symbols'], 'key'), 'is_string'), true);
        $relationships = array_filter($graph['relationships'] ?? [], static function (array $edge) use ($allowedKeys): bool {
            $source = $edge['source_key'] ?? null;
            $target = $edge['target_key'] ?? null;
            return ($source === null || isset($allowedKeys[$source])) && ($target === null || isset($allowedKeys[$target]));
        });
        $graph['relationships'] = array_slice(array_values($relationships), 0, $relationshipLimit);
        $graph['routes'] = array_slice($graph['routes'] ?? [], 0, $routeLimit);
        $limited = $original !== [count($graph['symbols']), count($graph['relationships']), count($graph['routes'])];
        if (!$limited) return $result;
        $graph['stats']['provider_output_limited'] = 1;
        $graph['stats']['provider_original_symbols'] = $original[0];
        $graph['stats']['provider_original_relationships'] = $original[1];
        $message = trim(($result->message ? $result->message . ' ' : '') . 'Provider evidence was compacted to the platform fusion budget.');
        return new AnalyzerResult($result->engine, $result->engineVersion, AnalyzerResult::PARTIAL, $graph, $result->findings, $result->durationMs, $message);
    }
}
