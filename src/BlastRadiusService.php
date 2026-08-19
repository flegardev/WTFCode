<?php

declare(strict_types=1);

final class BlastRadiusService
{
    /** @return array<string, mixed> */
    public static function forSymbol(int $projectId, int $symbolId): array
    {
        $relationships = SymbolRepository::projectRelationships($projectId);
        $incoming = [];
        $outgoing = [];
        foreach ($relationships as $relationship) {
            if ($relationship['target_symbol_id'] !== null) $incoming[(int) $relationship['target_symbol_id']][] = $relationship;
            if ($relationship['source_symbol_id'] !== null) $outgoing[(int) $relationship['source_symbol_id']][] = $relationship;
        }
        $visited = [$symbolId => true];
        $queue = [[$symbolId, 0]];
        $dependents = [];
        while ($queue !== [] && count($dependents) < 120) {
            [$targetId, $depth] = array_shift($queue);
            if ($depth >= 6) continue;
            foreach ($incoming[$targetId] ?? [] as $relationship) {
                $sourceId = $relationship['source_symbol_id'] === null ? null : (int) $relationship['source_symbol_id'];
                if ($sourceId === null || isset($visited[$sourceId])) continue;
                $visited[$sourceId] = true;
                $dependents[] = [
                    'id' => $sourceId, 'name' => $relationship['source_name'], 'type' => $relationship['source_type'], 'file_id' => (int) $relationship['source_file_id'], 'path' => $relationship['source_path'],
                    'relationship' => $relationship['relationship_type'], 'depth' => $depth + 1, 'confidence' => $relationship['confidence'],
                    'evidence_path' => $relationship['evidence_path'], 'line' => $relationship['evidence_line_start'] === null ? null : (int) $relationship['evidence_line_start'],
                ];
                $queue[] = [$sourceId, $depth + 1];
            }
        }
        $affectedIds = array_keys($visited);
        $affectedLookup = array_fill_keys($affectedIds, true);
        $tables = [];
        $services = [];
        $unknowns = [];
        foreach ($affectedIds as $id) {
            foreach ($outgoing[$id] ?? [] as $relationship) {
                $targetId = $relationship['target_symbol_id'] === null ? null : (int) $relationship['target_symbol_id'];
                if ($targetId === null) {
                    $unknowns[] = ['name' => $relationship['target_external_name'], 'relationship' => $relationship['relationship_type'], 'confidence' => $relationship['confidence']];
                    continue;
                }
                $item = ['id' => $targetId, 'name' => $relationship['target_name'], 'type' => $relationship['target_type'], 'path' => $relationship['target_path'], 'file_id' => (int) $relationship['target_file_id'], 'confidence' => $relationship['confidence']];
                if ($relationship['target_type'] === 'table') $tables[$targetId] = $item;
                if (in_array($relationship['target_type'], ['external_service', 'environment_variable'], true)) $services[$targetId] = $item;
            }
        }
        $routes = array_values(array_filter(SymbolRepository::routes($projectId), static fn (array $route): bool => $route['handler_symbol_id'] !== null && isset($affectedLookup[(int) $route['handler_symbol_id']])));
        $files = [];
        foreach ($dependents as $dependent) $files[$dependent['file_id']] = ['id' => $dependent['file_id'], 'path' => $dependent['path'], 'minimum_depth' => $dependent['depth'], 'confidence' => $dependent['confidence']];
        $score = count($dependents) + count($routes) * 3 + count($tables) * 3 + count($services) * 2;
        $risk = $score >= 15 ? 'high' : ($score >= 5 ? 'medium' : 'low');
        return [
            'risk' => $risk,
            'direct' => array_values(array_filter($dependents, static fn (array $item): bool => $item['depth'] === 1)),
            'transitive' => array_values(array_filter($dependents, static fn (array $item): bool => $item['depth'] > 1)),
            'files' => array_values($files),
            'routes' => $routes,
            'tables' => array_values($tables),
            'services' => array_values($services),
            'unknowns' => array_slice(array_values(array_unique($unknowns, SORT_REGULAR)), 0, 30),
            'truncated' => count($dependents) >= 120,
            'checklist' => self::checklist($risk, $routes, $tables, $services),
        ];
    }

    private static function checklist(string $risk, array $routes, array $tables, array $services): array
    {
        $items = ['Read the direct callers and consumers before changing the symbol contract.', 'Run the repository tests closest to the evidence files, then the broader project checks.'];
        if ($routes !== []) $items[] = 'Exercise the affected HTTP or UI routes with both expected and unauthorized inputs.';
        if ($tables !== []) $items[] = 'Review query, schema, validation, and migration compatibility for the listed data structures.';
        if ($services !== []) $items[] = 'Verify external-service and environment-variable behavior without exposing secrets.';
        if ($risk === 'high') $items[] = 'Split the change into smaller commits and compare the final Git impact against this trace.';
        return $items;
    }
}
