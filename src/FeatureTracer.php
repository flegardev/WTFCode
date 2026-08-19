<?php

declare(strict_types=1);

final class FeatureTracer
{
    /** @return array<string, mixed> */
    public static function trace(int $projectId, string $query): array
    {
        $query = trim($query);
        $result = [
            'query' => $query,
            'entry_points' => [],
            'symbols' => [],
            'hops' => [],
            'files' => [],
            'tables' => [],
            'services' => [],
            'environment' => [],
            'unknowns' => [],
            'warnings' => ['This trace is deterministic static evidence. Dynamic dispatch, reflection, runtime configuration, and generated code can add paths the scanner cannot prove.'],
            'confidence' => 'low',
        ];
        if ($query === '') return $result;

        $normalizedQuery = strtolower(preg_replace('/\s+/', ' ', $query) ?? $query);
        $aliases = match ($normalizedQuery) {
            'authentication', 'login', 'signin', 'sign-in', 'session' => ['auth', 'login', 'session'],
            'repository import', 'repository importing', 'import repository' => ['RepositoryImporter', 'import', 'clone'],
            'repository scan', 'repository scanning', 'scan repository' => ['RepoScanner', 'scan'],
            'feature trace', 'feature tracing', 'trace feature' => ['FeatureTracer', 'trace'],
            'safe prompt', 'safe prompt generation', 'prompt generation' => ['PromptSafetyService', 'prompt'],
            'git compare', 'git comparison', 'git semantic comparison' => ['GitDiffService', 'compare'],
            'database', 'data', 'persistence' => ['database', 'model', 'table'],
            'frontend', 'interface' => ['component', 'page', 'view'],
            'api', 'endpoint' => ['api', 'route', 'controller'],
            default => array_values(array_unique(array_merge([$query], array_filter(preg_split('/[^a-z0-9_]+/i', $query) ?: [], static fn (string $term): bool => strlen($term) >= 3)))),
        };
        $routes = [];
        $symbols = [];
        foreach ($aliases as $alias) {
            foreach (SymbolRepository::routes($projectId, $alias) as $route) $routes[$route['id']] = $route;
            foreach (SymbolRepository::search($projectId, $alias, 24) as $symbol) $symbols[$symbol['id']] = $symbol;
        }
        $routes = array_values($routes);
        $symbols = array_values($symbols);
        $aliasNeedles = array_values(array_filter(array_map(static fn (string $alias): string => strtolower(preg_replace('/[^a-z0-9]+/i', '', $alias) ?? $alias), $aliases)));
        $relevance = static function (array $symbol) use ($aliasNeedles): int {
            $name = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $symbol['name']) ?? (string) $symbol['name']);
            $path = strtolower((string) $symbol['path']);
            $score = in_array((string) $symbol['symbol_type'], ['route_handler', 'controller', 'class', 'method', 'function', 'component', 'hook', 'module'], true) ? 30 : 0;
            foreach ($aliasNeedles as $needle) {
                if ($needle === '') continue;
                if ($name === $needle) $score += 100;
                elseif (str_contains($name, $needle)) $score += 45;
                if (str_contains(preg_replace('/[^a-z0-9]+/i', '', $path) ?? $path, $needle)) $score += 20;
            }
            if (preg_match('#(^|/)(?:tests?|specs?|fixtures?)(/|$)#i', $path) === 1) $score -= 40;
            return $score;
        };
        usort($symbols, static function (array $left, array $right) use ($relevance): int {
            $scoreOrder = $relevance($right) <=> $relevance($left);
            if ($scoreOrder !== 0) return $scoreOrder;
            $leftTest = preg_match('#(^|/)(?:tests?|specs?|fixtures?)(/|$)#i', (string) $left['path']) === 1;
            $rightTest = preg_match('#(^|/)(?:tests?|specs?|fixtures?)(/|$)#i', (string) $right['path']) === 1;
            return ($leftTest <=> $rightTest) ?: strcmp((string) $left['path'], (string) $right['path']);
        });
        $startIds = [];
        foreach (array_slice($routes, 0, 20) as $route) {
            $result['entry_points'][] = [
                'kind' => 'route', 'label' => $route['http_method'] . ' ' . $route['route_path'], 'framework' => $route['framework'],
                'file_id' => (int) $route['file_id'], 'path' => $route['path'], 'line' => (int) $route['evidence_line'],
                'handler_id' => $route['handler_symbol_id'] === null ? null : (int) $route['handler_symbol_id'], 'handler_name' => $route['handler_name'],
                'confidence' => $route['confidence'],
            ];
            if ($route['handler_symbol_id'] !== null) $startIds[] = (int) $route['handler_symbol_id'];
            $result['files'][$route['path']] = ['id' => (int) $route['file_id'], 'path' => $route['path'], 'reason' => 'Defines a matching route', 'confidence' => $route['confidence']];
        }
        foreach ($symbols as $symbol) {
            $id = (int) $symbol['id'];
            $startIds[] = $id;
            $result['symbols'][$id] = self::symbolRow($symbol, 'Matches the requested feature name or file path');
            $result['files'][$symbol['path']] = ['id' => (int) $symbol['file_id'], 'path' => $symbol['path'], 'reason' => 'Contains a matching symbol', 'confidence' => $symbol['confidence']];
        }
        $startIds = array_values(array_unique($startIds));

        $relationships = SymbolRepository::projectRelationships($projectId);
        $outgoing = [];
        foreach ($relationships as $relationship) {
            if ($relationship['source_symbol_id'] !== null) $outgoing[(int) $relationship['source_symbol_id']][] = $relationship;
        }
        $queue = [];
        foreach ($startIds as $id) $queue[] = [$id, 0];
        $visited = array_fill_keys($startIds, true);
        $hopKeys = [];
        while ($queue !== [] && count($result['hops']) < 80) {
            [$sourceId, $depth] = array_shift($queue);
            if ($depth >= 5) continue;
            foreach ($outgoing[$sourceId] ?? [] as $relationship) {
                if (count($result['hops']) >= 80) break 2;
                $hopKey = $relationship['id'] . ':' . $depth;
                if (isset($hopKeys[$hopKey])) continue;
                $hopKeys[$hopKey] = true;
                $targetId = $relationship['target_symbol_id'] === null ? null : (int) $relationship['target_symbol_id'];
                $result['hops'][] = [
                    'depth' => $depth + 1,
                    'source_id' => $relationship['source_symbol_id'] === null ? null : (int) $relationship['source_symbol_id'],
                    'source_name' => $relationship['source_name'], 'source_type' => $relationship['source_type'], 'source_path' => $relationship['source_path'],
                    'relationship' => $relationship['relationship_type'],
                    'target_id' => $targetId, 'target_name' => $relationship['target_name'] ?: $relationship['target_external_name'],
                    'target_type' => $relationship['target_type'] ?: 'external', 'target_path' => $relationship['target_path'],
                    'confidence' => $relationship['confidence'], 'evidence_path' => $relationship['evidence_path'],
                    'line' => $relationship['evidence_line_start'] === null ? null : (int) $relationship['evidence_line_start'],
                    'excerpt' => $relationship['evidence_excerpt'],
                ];
                if ($relationship['source_path']) $result['files'][$relationship['source_path']] = ['id' => (int) $relationship['source_file_id'], 'path' => $relationship['source_path'], 'reason' => 'Contains a traced relationship', 'confidence' => $relationship['confidence']];
                if ($relationship['target_path']) $result['files'][$relationship['target_path']] = ['id' => (int) $relationship['target_file_id'], 'path' => $relationship['target_path'], 'reason' => 'Receives a traced relationship', 'confidence' => $relationship['confidence']];
                if ($targetId === null) {
                    $result['unknowns'][] = ['name' => $relationship['target_external_name'], 'reason' => 'The target name was visible, but this scan could not resolve it to one internal symbol.', 'confidence' => $relationship['confidence']];
                    continue;
                }
                $target = [
                    'id' => $targetId, 'name' => $relationship['target_name'], 'type' => $relationship['target_type'], 'path' => $relationship['target_path'],
                    'file_id' => (int) $relationship['target_file_id'], 'confidence' => $relationship['confidence'], 'reason' => 'Reached through ' . $relationship['relationship_type'],
                ];
                $result['symbols'][$targetId] = $target;
                if ($relationship['target_type'] === 'table') $result['tables'][$targetId] = $target;
                elseif ($relationship['target_type'] === 'external_service') $result['services'][$targetId] = $target;
                elseif ($relationship['target_type'] === 'environment_variable') $result['environment'][$targetId] = $target;
                if (!isset($visited[$targetId])) {
                    $visited[$targetId] = true;
                    $queue[] = [$targetId, $depth + 1];
                }
            }
        }

        if ($startIds === []) {
            foreach (Project::files($projectId, $query, 12) as $file) $result['files'][$file['path']] = ['id' => (int) $file['id'], 'path' => $file['path'], 'reason' => 'Legacy file-level match; no normalized symbol matched', 'confidence' => 'low'];
            $result['warnings'][] = 'No symbol or route matched directly, so the remaining file matches are lower-confidence V1 evidence.';
        }
        if (count($result['hops']) >= 80) $result['warnings'][] = 'The trace stopped after 80 evidence hops to keep the result reviewable.';
        $result['symbols'] = array_values($result['symbols']);
        $result['files'] = array_values($result['files']);
        $result['tables'] = array_values($result['tables']);
        $result['services'] = array_values($result['services']);
        $result['environment'] = array_values($result['environment']);
        $result['unknowns'] = array_slice(array_values(array_unique($result['unknowns'], SORT_REGULAR)), 0, 30);
        $result['confidence'] = $result['entry_points'] !== [] || $result['hops'] !== [] ? (count(array_filter($result['hops'], static fn (array $hop): bool => $hop['confidence'] === 'medium')) > 0 ? 'medium' : 'high') : ($result['symbols'] !== [] ? 'medium' : 'low');
        return $result;
    }

    private static function symbolRow(array $symbol, string $reason): array
    {
        return ['id' => (int) $symbol['id'], 'name' => $symbol['name'], 'type' => $symbol['symbol_type'], 'path' => $symbol['path'], 'file_id' => (int) $symbol['file_id'], 'confidence' => $symbol['confidence'], 'reason' => $reason];
    }
}
