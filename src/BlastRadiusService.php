<?php

declare(strict_types=1);

/**
 * 🎓 BEGINNER NOTE: Blast Radius Analysis & Transitive Dependency Graph Traversal
 * 
 * What is "Blast Radius" in Software Engineering?
 * When you change a function or refactor a database model, what breaks across the rest of the application?
 * 
 * Algorithm:
 * 1. Graph Construction:
 *    Loads symbol relationships (calls, imports, inherits, queries) into two adjacency lists:
 *    - `$incoming`: Which symbols depend on me? (Callers / Consumers)
 *    - `$outgoing`: Which symbols do I depend on? (Callees / Dependencies)
 * 2. Breadth-First Search (BFS):
 *    Starting from the target symbol, explores incoming edges level-by-level up to depth 6
 *    to find all direct dependents (depth 1) and transitive dependents (depth 2+).
 * 3. Risk Calculation Score:
 *    `Score = Dependents + (Affected Routes × 3) + (Affected Database Tables × 3) + (External APIs × 2)`
 *    - Score >= 15: HIGH RISK (Major breaking change risk)
 *    - Score >= 5: MEDIUM RISK
 *    - Score < 5: LOW RISK
 */

final class BlastRadiusService
{
    /**
     * Computes the blast radius impact for a specific symbol in a project.
     * 
     * @param int $projectId - Project database ID
     * @param int $symbolId - ID of the symbol being modified/inspected
     * @return array<string, mixed> Structured report of direct/transitive callers and risk score
     */
    public static function forSymbol(int $projectId, int $symbolId): array
    {
        // 1. Fetch all static symbol relationships from database
        $relationships = SymbolRepository::projectRelationships($projectId);
        $incoming = [];
        $outgoing = [];
        
        foreach ($relationships as $relationship) {
            if ($relationship['target_symbol_id'] !== null) {
                $incoming[(int) $relationship['target_symbol_id']][] = $relationship;
            }
            if ($relationship['source_symbol_id'] !== null) {
                $outgoing[(int) $relationship['source_symbol_id']][] = $relationship;
            }
        }

        // 2. Execute Breadth-First Search (BFS) to discover upstream callers
        $visited = [$symbolId => true];
        $queue = [[$symbolId, 0]];
        $dependents = [];

        while ($queue !== [] && count($dependents) < 120) {
            [$targetId, $depth] = array_shift($queue);
            if ($depth >= 6) {
                continue;
            }

            foreach ($incoming[$targetId] ?? [] as $relationship) {
                $sourceId = $relationship['source_symbol_id'] === null ? null : (int) $relationship['source_symbol_id'];
                if ($sourceId === null || isset($visited[$sourceId])) {
                    continue;
                }

                $visited[$sourceId] = true;
                $dependents[] = [
                    'id' => $sourceId,
                    'name' => $relationship['source_name'],
                    'type' => $relationship['source_type'],
                    'file_id' => (int) $relationship['source_file_id'],
                    'path' => $relationship['source_path'],
                    'relationship' => $relationship['relationship_type'],
                    'depth' => $depth + 1,
                    'confidence' => $relationship['confidence'],
                    'evidence_path' => $relationship['evidence_path'],
                    'line' => $relationship['evidence_line_start'] === null ? null : (int) $relationship['evidence_line_start'],
                ];
                $queue[] = [$sourceId, $depth + 1];
            }
        }

        // 3. Map downstream database tables, external APIs, and HTTP routes
        $affectedIds = array_keys($visited);
        $affectedLookup = array_fill_keys($affectedIds, true);
        $tables = [];
        $services = [];
        $unknowns = [];

        foreach ($affectedIds as $id) {
            foreach ($outgoing[$id] ?? [] as $relationship) {
                $targetId = $relationship['target_symbol_id'] === null ? null : (int) $relationship['target_symbol_id'];
                if ($targetId === null) {
                    $unknowns[] = [
                        'name' => $relationship['target_external_name'],
                        'relationship' => $relationship['relationship_type'],
                        'confidence' => $relationship['confidence'],
                    ];
                    continue;
                }
                $item = [
                    'id' => $targetId,
                    'name' => $relationship['target_name'],
                    'type' => $relationship['target_type'],
                    'path' => $relationship['target_path'],
                    'file_id' => (int) $relationship['target_file_id'],
                    'confidence' => $relationship['confidence'],
                ];
                if ($relationship['target_type'] === 'table') {
                    $tables[$targetId] = $item;
                }
                if (in_array($relationship['target_type'], ['external_service', 'environment_variable'], true)) {
                    $services[$targetId] = $item;
                }
            }
        }

        // 4. Identify web HTTP routes whose handlers depend on the target symbol
        $routes = array_values(array_filter(
            SymbolRepository::routes($projectId),
            static fn (array $route): bool => $route['handler_symbol_id'] !== null && isset($affectedLookup[(int) $route['handler_symbol_id']])
        ));

        $files = [];
        foreach ($dependents as $dependent) {
            $files[$dependent['file_id']] = [
                'id' => $dependent['file_id'],
                'path' => $dependent['path'],
                'minimum_depth' => $dependent['depth'],
                'confidence' => $dependent['confidence'],
            ];
        }

        // 5. Calculate cumulative risk score
        $score = count($dependents) + (count($routes) * 3) + (count($tables) * 3) + (count($services) * 2);
        $risk = $score >= 15 ? 'high' : ($score >= 5 ? 'medium' : 'low');

        return [
            'risk' => $risk,
            'score' => $score,
            'direct' => array_values(array_filter($dependents, static fn (array $item): bool => $item['depth'] === 1)),
            'transitive' => array_values(array_filter($dependents, static fn (array $item): bool => $item['depth'] > 1)),
            'routes' => $routes,
            'tables' => array_values($tables),
            'services' => array_values($services),
            'unknowns' => $unknowns,
            'files' => array_values($files),
        ];
    }
}
