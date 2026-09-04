<?php

declare(strict_types=1);

final class AnalysisCoordinator
{
    public function __construct(
        private readonly AnalyzerRegistry $registry = new AnalyzerRegistry(),
        private readonly EvidenceFusion $fusion = new EvidenceFusion(),
        private readonly ProviderCache $cache = new ProviderCache(),
    ) {
    }

    /**
     * @param null|callable(string, int, int): void $checkpoint
     * @return array<string, mixed>
     */
    public function analyze(AnalysisRequest $request, ?callable $checkpoint = null): array
    {
        $results = [];
        $providers = $this->registry->all();
        $providerTotal = count($providers);
        foreach ($providers as $index => $provider) {
            $id = 'provider-' . $index;
            $version = 'unknown';
            try {
                $id = $provider->id();
                $version = $provider->version();
                if (!AnalysisProfile::includes($request->profile(), $id)) continue;
                if ($checkpoint !== null) $checkpoint($id, $index + 1, $providerTotal);
                if (!$provider->isAvailable()) {
                    $results[] = AnalyzerResult::unavailable($id, $version, 'Analyzer is not installed or not supported on this machine.');
                    continue;
                }
                $cached = $this->cache->load($request, $provider);
                if ($cached !== null) { $results[] = $cached; continue; }
                $analysisRequest = $request;
                $previous = null;
                $incremental = false;
                if ($this->canIncrement($request, $id)) {
                    $previous = $this->cache->load($request, $provider, $request->previousRevision());
                    if ($previous !== null) {
                        $analysisRequest = $request->subset($this->neighborhoodPaths($previous, $request->changedPaths()));
                        $incremental = true;
                    }
                }
                $result = $this->compact($provider->analyze($analysisRequest));
                if ($incremental && $previous !== null && in_array($result->status, [AnalyzerResult::SUCCESS, AnalyzerResult::PARTIAL], true)) {
                    $result = $this->mergeIncremental($previous, $result, array_column($analysisRequest->files(), 'path'));
                }
                $execution = ['cache_hit' => false, 'incremental' => $incremental, 'files_analyzed' => count($analysisRequest->files()), 'cache_key' => $this->cache->key($request, $provider)];
                $result = new AnalyzerResult($result->engine, $result->engineVersion, $result->status, $result->graph, $result->findings, $result->durationMs, $result->message, $execution);
                $this->cache->save($request, $provider, $result);
                $results[] = $result;
            } catch (ScanLeaseLostException $exception) {
                throw $exception;
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
        $graph['packages'] = array_slice($graph['packages'] ?? [], 0, 10000);
        $limited = $original !== [count($graph['symbols']), count($graph['relationships']), count($graph['routes'])];
        if (!$limited) return $result;
        $graph['stats']['provider_output_limited'] = 1;
        $graph['stats']['provider_original_symbols'] = $original[0];
        $graph['stats']['provider_original_relationships'] = $original[1];
        $message = trim(($result->message ? $result->message . ' ' : '') . 'Provider evidence was compacted to the platform fusion budget.');
        return new AnalyzerResult($result->engine, $result->engineVersion, AnalyzerResult::PARTIAL, $graph, $result->findings, $result->durationMs, $message, $result->execution);
    }

    private function canIncrement(AnalysisRequest $request, string $provider): bool
    {
        $changed = $request->changedPaths();
        if ($request->previousRevision() === null || $changed === [] || count($changed) > 100) return false;
        if (count($request->files()) > 0 && count($changed) / count($request->files()) > 0.3) return false;
        return in_array($provider, ['wtfcode-native', 'php-parser', 'tree-sitter', 'typescript-semantic', 'ast-grep', 'ctags'], true);
    }

    /** @return array<int, string> */
    private function neighborhoodPaths(AnalyzerResult $previous, array $changedPaths): array
    {
        $paths = array_fill_keys($changedPaths, true);
        $symbolPaths = [];
        foreach ($previous->graph['symbols'] ?? [] as $symbol) $symbolPaths[$symbol['key']] = $symbol['path'];
        foreach ($previous->graph['relationships'] ?? [] as $edge) {
            $sourcePath = $symbolPaths[$edge['source_key'] ?? ''] ?? null;
            $targetPath = $symbolPaths[$edge['target_key'] ?? ''] ?? null;
            $evidencePath = $edge['evidence_path'] ?? null;
            if (($sourcePath !== null && isset($paths[$sourcePath])) || ($targetPath !== null && isset($paths[$targetPath])) || ($evidencePath !== null && isset($paths[$evidencePath]))) {
                if ($sourcePath !== null) $paths[$sourcePath] = true;
                if ($targetPath !== null) $paths[$targetPath] = true;
                if (count($paths) >= 200) break;
            }
        }
        return array_keys($paths);
    }

    private function mergeIncremental(AnalyzerResult $previous, AnalyzerResult $fresh, array $reanalyzedPaths): AnalyzerResult
    {
        $replace = array_fill_keys(array_filter($reanalyzedPaths, 'is_string'), true);
        $oldGraph = $previous->graph;
        $newGraph = $fresh->graph;
        $symbols = array_values(array_filter($oldGraph['symbols'] ?? [], static fn (array $symbol): bool => !isset($replace[$symbol['path'] ?? ''])));
        $symbols = array_merge($symbols, $newGraph['symbols'] ?? []);
        $allowedKeys = array_fill_keys(array_filter(array_column($symbols, 'key'), 'is_string'), true);
        $relationships = array_values(array_filter($oldGraph['relationships'] ?? [], static function (array $edge) use ($replace, $allowedKeys): bool {
            if (isset($replace[$edge['evidence_path'] ?? ''])) return false;
            return (($edge['source_key'] ?? null) === null || isset($allowedKeys[$edge['source_key']])) && (($edge['target_key'] ?? null) === null || isset($allowedKeys[$edge['target_key']]));
        }));
        $relationships = array_merge($relationships, $newGraph['relationships'] ?? []);
        $routes = array_values(array_filter($oldGraph['routes'] ?? [], static fn (array $route): bool => !isset($replace[$route['path'] ?? ''])));
        $routes = array_merge($routes, $newGraph['routes'] ?? []);
        $findings = array_values(array_filter($previous->findings, static fn (array $finding): bool => !isset($replace[$finding['path'] ?? ''])));
        $findings = array_merge($findings, $fresh->findings);
        $graph = $oldGraph;
        $graph['symbols'] = $symbols; $graph['relationships'] = $relationships; $graph['routes'] = $routes;
        if (($newGraph['packages'] ?? []) !== []) $graph['packages'] = $newGraph['packages'];
        $graph['stats'] = ['symbols' => count($symbols), 'relationships' => count($relationships), 'routes' => count($routes), 'files_with_symbols' => count(array_unique(array_column($symbols, 'path'))), 'incremental' => 1];
        return new AnalyzerResult($fresh->engine, $fresh->engineVersion, $fresh->status, $graph, $findings, $fresh->durationMs, 'Incrementally analyzed ' . count($reanalyzedPaths) . ' changed or neighboring files.');
    }
}
