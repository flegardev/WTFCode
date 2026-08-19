<?php

declare(strict_types=1);

final class EvidenceFusion
{
    private const MAX_SYMBOLS = 8000;
    private const MAX_RELATIONSHIPS = 16000;
    private const MAX_ROUTES = 4000;

    /** @param array<int, AnalyzerResult> $results @return array<string, mixed> */
    public function fuse(array $results): array
    {
        $symbols = [];
        $relationships = [];
        $routes = [];
        $findings = [];
        $packages = [];
        $vulnerabilityIndex = [];
        $symbolIdentityToKey = [];
        $providerKeyMap = [];

        foreach ($this->successful($results) as $result) {
            foreach ($result->graph['symbols'] ?? [] as $symbol) {
                $identity = $this->symbolIdentity($symbol);
                $existingKey = $symbolIdentityToKey[$identity] ?? null;
                if ($existingKey === null && count($symbols) >= self::MAX_SYMBOLS) continue;
                $key = $existingKey ?? (string) ($symbol['key'] ?? hash('sha256', $identity));
                if ($existingKey === null && isset($symbols[$key])) {
                    $key = hash('sha256', 'fused|' . $identity);
                }
                $providerKeyMap[$result->engine][(string) ($symbol['key'] ?? $key)] = $key;
                $symbol['key'] = $key;
                $symbol['metadata'] = $this->withProvenance(
                    is_array($symbol['metadata'] ?? null) ? $symbol['metadata'] : [],
                    $this->provenance($result, $symbol, 'declaration:' . (string) ($symbol['type'] ?? 'unknown')),
                );
                if ($existingKey === null) {
                    $symbolIdentityToKey[$identity] = $key;
                    $symbols[$key] = $symbol;
                } else {
                    $symbols[$key] = $this->mergeFact($symbols[$key], $symbol);
                }
            }
        }

        foreach ($results as $result) {
            foreach ($result->findings as $finding) {
                if (!is_array($finding)) continue;
                $finding = SensitiveDataSanitizer::finding($finding);
                $finding['evidence'] = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];
                $finding['evidence']['engine'] = $result->engine;
                $finding['evidence']['engine_version'] = $result->engineVersion;
                $finding['confidence'] = EvidenceConfidence::label(
                    $result->engine,
                    (string) ($finding['confidence'] ?? 'medium'),
                    1,
                    ($finding['evidence']['context'] ?? 'runtime') === 'documentation',
                );
                $finding['evidence']['confidence'] = $finding['confidence'];
                $identity = $this->findingIdentity($finding, $vulnerabilityIndex);
                if (!isset($findings[$identity])) {
                    $findings[$identity] = $finding;
                } else {
                    $findings[$identity] = $this->mergeFinding($findings[$identity], $finding);
                }
                $this->indexVulnerability($identity, $findings[$identity], $vulnerabilityIndex);
            }
        }

        foreach ($this->successful($results) as $result) {
            foreach ($result->graph['packages'] ?? [] as $package) {
                if (!is_array($package)) continue;
                $identity = strtolower((string) ($package['purl'] ?? ''));
                if ($identity === '') $identity = strtolower(implode('|', [(string) ($package['ecosystem'] ?? ''), (string) ($package['name'] ?? ''), (string) ($package['version'] ?? '')]));
                if ($identity === '||') continue;
                $package['providers'] = array_values(array_unique(array_merge($package['providers'] ?? [], [$result->engine])));
                if (!isset($packages[$identity])) $packages[$identity] = $package;
                else $packages[$identity] = $this->mergePackage($packages[$identity], $package);
                if (count($packages) >= 10000) break 2;
            }
        }

        foreach ($this->successful($results) as $result) {
            foreach ($result->graph['symbols'] ?? [] as $symbol) {
                $originalKey = (string) ($symbol['key'] ?? '');
                $key = $providerKeyMap[$result->engine][$originalKey] ?? null;
                $parent = $symbol['parent_key'] ?? null;
                if ($key !== null && $parent !== null && isset($providerKeyMap[$result->engine][(string) $parent])) {
                    $symbols[$key]['parent_key'] = $providerKeyMap[$result->engine][(string) $parent];
                }
            }

            foreach ($result->graph['relationships'] ?? [] as $relationship) {
                $source = $relationship['source_key'] ?? null;
                $target = $relationship['target_key'] ?? null;
                if ($source !== null) $relationship['source_key'] = $providerKeyMap[$result->engine][(string) $source] ?? null;
                if ($target !== null) $relationship['target_key'] = $providerKeyMap[$result->engine][(string) $target] ?? null;
                $relationship['metadata'] = $this->withProvenance(
                    is_array($relationship['metadata'] ?? null) ? $relationship['metadata'] : [],
                    $this->provenance($result, $relationship, (string) ($relationship['type'] ?? 'relationship')),
                );
                $identity = $this->relationshipIdentity($relationship);
                if (!isset($relationships[$identity]) && count($relationships) >= self::MAX_RELATIONSHIPS) continue;
                $relationships[$identity] = isset($relationships[$identity])
                    ? $this->mergeFact($relationships[$identity], $relationship)
                    : $relationship;
            }

            foreach ($result->graph['routes'] ?? [] as $route) {
                $handler = $route['handler_key'] ?? null;
                if ($handler !== null) $route['handler_key'] = $providerKeyMap[$result->engine][(string) $handler] ?? null;
                $route['metadata'] = $this->withProvenance(
                    is_array($route['metadata'] ?? null) ? $route['metadata'] : [],
                    $this->provenance($result, $route, 'route'),
                );
                $identity = $this->routeIdentity($route);
                if (!isset($routes[$identity]) && count($routes) >= self::MAX_ROUTES) continue;
                $routes[$identity] = isset($routes[$identity])
                    ? $this->mergeFact($routes[$identity], $route)
                    : $route;
            }
        }

        $engineRuns = array_map(static fn (AnalyzerResult $result): array => $result->summary(), $results);
        return [
            'symbols' => array_values($symbols),
            'relationships' => array_values($relationships),
            'routes' => array_values($routes),
            'packages' => array_values($packages),
            'stats' => [
                'symbols' => count($symbols),
                'relationships' => count($relationships),
                'routes' => count($routes),
                'packages' => count($packages),
                'findings' => count($findings),
                'files_with_symbols' => count(array_unique(array_column($symbols, 'path'))),
                'symbol_limit_reached' => count($symbols) >= self::MAX_SYMBOLS ? 1 : $this->limitReached($results, 'symbol_limit_reached'),
                'relationship_limit_reached' => count($relationships) >= self::MAX_RELATIONSHIPS ? 1 : $this->limitReached($results, 'relationship_limit_reached'),
                'route_limit_reached' => count($routes) >= self::MAX_ROUTES ? 1 : $this->limitReached($results, 'route_limit_reached'),
                'engines_succeeded' => count(array_filter($results, static fn (AnalyzerResult $result): bool => $result->status === AnalyzerResult::SUCCESS)),
                'engines_failed' => count(array_filter($results, static fn (AnalyzerResult $result): bool => $result->status === AnalyzerResult::FAILED)),
                'engines_unavailable' => count(array_filter($results, static fn (AnalyzerResult $result): bool => $result->status === AnalyzerResult::UNAVAILABLE)),
            ],
            'engine_runs' => $engineRuns,
            'findings' => array_values($findings),
        ];
    }

    /** @param array<int, AnalyzerResult> $results @return array<int, AnalyzerResult> */
    private function successful(array $results): array
    {
        return array_values(array_filter($results, static fn (AnalyzerResult $result): bool => in_array($result->status, [AnalyzerResult::SUCCESS, AnalyzerResult::PARTIAL], true)));
    }

    /** @param array<string, mixed> $symbol */
    private function symbolIdentity(array $symbol): string
    {
        return strtolower(implode('|', [
            (string) ($symbol['language'] ?? 'unknown'),
            str_replace('\\', '/', (string) ($symbol['path'] ?? '')),
            (string) ($symbol['qualified_name'] ?? $symbol['name'] ?? ''),
            (string) ($symbol['type'] ?? 'unknown'),
            (string) max(1, (int) ($symbol['start_line'] ?? 1)),
            (string) max(1, (int) ($symbol['end_line'] ?? $symbol['start_line'] ?? 1)),
        ]));
    }

    /** @param array<string, mixed> $relationship */
    private function relationshipIdentity(array $relationship): string
    {
        return hash('sha256', implode('|', [
            (string) ($relationship['source_key'] ?? ''),
            (string) ($relationship['target_key'] ?? ''),
            strtolower((string) ($relationship['external_name'] ?? $relationship['target_name'] ?? '')),
            strtolower((string) ($relationship['type'] ?? '')),
            str_replace('\\', '/', (string) ($relationship['evidence_path'] ?? '')),
            (string) max(1, (int) ($relationship['line_start'] ?? 1)),
        ]));
    }

    /** @param array<string, mixed> $route */
    private function routeIdentity(array $route): string
    {
        return hash('sha256', strtolower(implode('|', [
            str_replace('\\', '/', (string) ($route['path'] ?? '')),
            (string) ($route['method'] ?? 'ANY'),
            (string) ($route['route_path'] ?? ''),
            (string) max(1, (int) ($route['line'] ?? 1)),
        ])));
    }

    /** @param array<string, mixed> $fact */
    private function provenance(AnalyzerResult $result, array $fact, string $rawType): array
    {
        $file = (string) ($fact['evidence_path'] ?? $fact['path'] ?? '');
        $start = max(1, (int) ($fact['line_start'] ?? $fact['line'] ?? $fact['start_line'] ?? 1));
        $end = max($start, (int) ($fact['line_end'] ?? $fact['end_line'] ?? $start));
        return [
            'engine' => $result->engine,
            'engine_version' => $result->engineVersion,
            'analysis_version' => AnalysisEngine::VERSION,
            'confidence' => (string) ($fact['confidence'] ?? 'medium'),
            'evidence_file' => $file,
            'evidence_line' => $start,
            'evidence_range' => [$start, $end],
            'raw_evidence_type' => $rawType,
            'evidence_rank' => EvidenceConfidence::rank($result->engine),
        ];
    }

    /** @param array<string, mixed> $metadata @param array<string, mixed> $provenance @return array<string, mixed> */
    private function withProvenance(array $metadata, array $provenance): array
    {
        $existing = is_array($metadata['provenance'] ?? null) ? $metadata['provenance'] : [];
        $fingerprint = static fn (array $item): string => implode('|', [
            (string) ($item['engine'] ?? ''),
            (string) ($item['engine_version'] ?? ''),
            (string) ($item['evidence_file'] ?? ''),
            (string) ($item['evidence_line'] ?? ''),
            (string) ($item['raw_evidence_type'] ?? ''),
        ]);
        $seen = array_fill_keys(array_map($fingerprint, array_filter($existing, 'is_array')), true);
        if (!isset($seen[$fingerprint($provenance)])) $existing[] = $provenance;
        $metadata['provenance'] = array_values($existing);
        $metadata['engines'] = array_values(array_unique(array_column($metadata['provenance'], 'engine')));
        $metadata['source_count'] = count($metadata['provenance']);
        $metadata['confidence_label'] = $this->confidenceLabel($metadata['provenance']);
        return $metadata;
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $incoming @return array<string, mixed> */
    private function mergeFact(array $current, array $incoming): array
    {
        $metadata = is_array($current['metadata'] ?? null) ? $current['metadata'] : [];
        foreach (($incoming['metadata']['provenance'] ?? []) as $provenance) {
            if (is_array($provenance)) $metadata = $this->withProvenance($metadata, $provenance);
        }
        $current['metadata'] = $metadata;
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        if (($rank[$incoming['confidence'] ?? 'medium'] ?? 2) > ($rank[$current['confidence'] ?? 'medium'] ?? 2)) {
            $current['confidence'] = $incoming['confidence'];
        }
        $current['metadata']['confidence_label'] = $this->confidenceLabel($current['metadata']['provenance'] ?? []);
        return $current;
    }

    /** @param array<int, array<string, mixed>> $provenance */
    private function confidenceLabel(array $provenance): string
    {
        $label = 'heuristic';
        $sources = count(array_unique(array_column($provenance, 'engine')));
        foreach ($provenance as $item) {
            if (!is_array($item)) continue;
            $candidate = EvidenceConfidence::label(
                (string) ($item['engine'] ?? ''),
                (string) ($item['confidence'] ?? 'medium'),
                $sources,
                ($item['context'] ?? 'runtime') === 'documentation',
            );
            $label = EvidenceConfidence::stronger($label, $candidate);
        }
        return $label;
    }

    /** @param array<string, string> $vulnerabilityIndex @param array<string, mixed> $finding */
    private function findingIdentity(array $finding, array $vulnerabilityIndex): string
    {
        if (($finding['type'] ?? '') === 'dependency_vulnerability') {
            $evidence = $finding['evidence'] ?? [];
            $packageKey = strtolower(implode('|', [(string) ($evidence['ecosystem'] ?? ''), (string) ($evidence['package'] ?? ''), (string) ($evidence['installed_version'] ?? '')]));
            $ids = array_values(array_unique(array_filter(array_merge([(string) ($evidence['vulnerability_id'] ?? '')], is_array($evidence['aliases'] ?? null) ? $evidence['aliases'] : []), 'is_string')));
            foreach ($ids as $id) {
                $known = $vulnerabilityIndex[$packageKey . '|' . strtolower($id)] ?? null;
                if ($known !== null) return $known;
            }
            return hash('sha256', 'vulnerability|' . $packageKey . '|' . strtolower($ids[0] ?? 'unknown'));
        }
        return hash('sha256', implode('|', [(string) ($finding['type'] ?? ''), (string) ($finding['path'] ?? ''), (string) ($finding['evidence']['line'] ?? ''), (string) ($finding['evidence']['rule_id'] ?? '')]));
    }

    /** @param array<string, mixed> $finding @param array<string, string> $vulnerabilityIndex */
    private function indexVulnerability(string $identity, array $finding, array &$vulnerabilityIndex): void
    {
        if (($finding['type'] ?? '') !== 'dependency_vulnerability') return;
        $evidence = $finding['evidence'] ?? [];
        $packageKey = strtolower(implode('|', [(string) ($evidence['ecosystem'] ?? ''), (string) ($evidence['package'] ?? ''), (string) ($evidence['installed_version'] ?? '')]));
        $ids = array_merge([(string) ($evidence['vulnerability_id'] ?? '')], is_array($evidence['aliases'] ?? null) ? $evidence['aliases'] : []);
        foreach (array_filter($ids, 'is_string') as $id) $vulnerabilityIndex[$packageKey . '|' . strtolower($id)] = $identity;
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $incoming @return array<string, mixed> */
    private function mergeFinding(array $current, array $incoming): array
    {
        $currentEvidence = is_array($current['evidence'] ?? null) ? $current['evidence'] : [];
        $incomingEvidence = is_array($incoming['evidence'] ?? null) ? $incoming['evidence'] : [];
        foreach (['aliases', 'fixed_versions', 'confirmation_sources'] as $key) {
            $currentEvidence[$key] = array_values(array_unique(array_merge(is_array($currentEvidence[$key] ?? null) ? $currentEvidence[$key] : [], is_array($incomingEvidence[$key] ?? null) ? $incomingEvidence[$key] : [])));
        }
        $engines = array_values(array_unique(array_merge(is_array($currentEvidence['engines'] ?? null) ? $currentEvidence['engines'] : [(string) ($currentEvidence['engine'] ?? '')], [(string) ($incomingEvidence['engine'] ?? '')])));
        $currentEvidence['engines'] = array_values(array_filter($engines));
        $current['evidence'] = $currentEvidence;
        $severity = ['info' => 1, 'attention' => 2, 'risk' => 3];
        if (($severity[$incoming['severity'] ?? 'info'] ?? 1) > ($severity[$current['severity'] ?? 'info'] ?? 1)) $current['severity'] = $incoming['severity'];
        if (count($currentEvidence['engines']) > 1) $current['confidence'] = 'confirmed';
        $currentEvidence['confidence'] = $current['confidence'] ?? 'likely';
        $current['evidence'] = $currentEvidence;
        return $current;
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $incoming @return array<string, mixed> */
    private function mergePackage(array $current, array $incoming): array
    {
        foreach (['licenses', 'locations', 'providers'] as $key) $current[$key] = array_values(array_unique(array_merge($current[$key] ?? [], $incoming[$key] ?? [])));
        $rank = ['detected' => 1, 'declared' => 2, 'resolved' => 3];
        if (($rank[$incoming['classification'] ?? 'detected'] ?? 1) > ($rank[$current['classification'] ?? 'detected'] ?? 1)) $current['classification'] = $incoming['classification'];
        return $current;
    }

    /** @param array<int, AnalyzerResult> $results */
    private function limitReached(array $results, string $key): int
    {
        foreach ($results as $result) {
            if ((int) ($result->graph['stats'][$key] ?? 0) === 1) return 1;
        }
        return 0;
    }
}
