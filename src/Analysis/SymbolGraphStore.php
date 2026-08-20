<?php

declare(strict_types=1);

final class SymbolGraphStore
{
    /** @param array<string, int> $fileIds @param array{symbols: array, relationships: array, routes: array, stats: array} $graph */
    public static function persist(int $projectId, int $scanRunId, array $fileIds, array $graph): void
    {
        $pdo = Database::connection();
        $symbolStatement = $pdo->prepare('INSERT INTO code_symbols (project_id, scan_run_id, file_id, symbol_key, symbol_type, language, name, qualified_name, signature_text, visibility, is_exported, start_line, end_line, confidence, metadata_json, provenance_json) VALUES (:project_id, :scan_run_id, :file_id, :symbol_key, :symbol_type, :language, :name, :qualified_name, :signature_text, :visibility, :is_exported, :start_line, :end_line, :confidence, :metadata_json, :provenance_json)' . (Database::isPostgres() ? ' RETURNING id' : ''));
        $symbolIds = [];
        foreach ($graph['symbols'] as $symbol) {
            if (!isset($fileIds[$symbol['path']])) continue;
            $symbolStatement->execute([
                'project_id' => $projectId,
                'scan_run_id' => $scanRunId,
                'file_id' => $fileIds[$symbol['path']],
                'symbol_key' => $symbol['key'],
                'symbol_type' => self::limit((string) $symbol['type'], 50),
                'language' => self::limit((string) $symbol['language'], 40),
                'name' => self::limit((string) $symbol['name'], 255),
                'qualified_name' => self::limit((string) $symbol['qualified_name'], 700),
                'signature_text' => $symbol['signature'] === null ? null : self::limit((string) $symbol['signature'], 1000),
                'visibility' => $symbol['visibility'],
                'is_exported' => $symbol['exported'] ? 1 : 0,
                'start_line' => max(1, (int) $symbol['start_line']),
                'end_line' => max((int) $symbol['start_line'], (int) $symbol['end_line']),
                'confidence' => $symbol['confidence'],
                'metadata_json' => self::json($symbol['metadata']),
                'provenance_json' => self::json(is_array($symbol['metadata']['provenance'] ?? null) ? $symbol['metadata']['provenance'] : []),
            ]);
            $symbolIds[$symbol['key']] = Database::isPostgres() ? (int) $symbolStatement->fetchColumn() : (int) $pdo->lastInsertId();
        }

        $parentStatement = $pdo->prepare('UPDATE code_symbols SET parent_symbol_id = :parent_id WHERE id = :id AND project_id = :project_id');
        foreach ($graph['symbols'] as $symbol) {
            if ($symbol['parent_key'] === null || !isset($symbolIds[$symbol['key']], $symbolIds[$symbol['parent_key']])) continue;
            $parentStatement->execute(['parent_id' => $symbolIds[$symbol['parent_key']], 'id' => $symbolIds[$symbol['key']], 'project_id' => $projectId]);
        }

        $relationshipStatement = $pdo->prepare('INSERT INTO symbol_relationships (project_id, scan_run_id, source_symbol_id, target_symbol_id, target_external_name, evidence_file_id, relationship_type, confidence, evidence_line_start, evidence_line_end, evidence_excerpt, metadata_json, provenance_json) VALUES (:project_id, :scan_run_id, :source_symbol_id, :target_symbol_id, :target_external_name, :evidence_file_id, :relationship_type, :confidence, :evidence_line_start, :evidence_line_end, :evidence_excerpt, :metadata_json, :provenance_json)');
        foreach ($graph['relationships'] as $relationship) {
            if (!isset($fileIds[$relationship['evidence_path']])) continue;
            $sourceId = $relationship['source_key'] !== null ? ($symbolIds[$relationship['source_key']] ?? null) : null;
            $targetId = $relationship['target_key'] !== null ? ($symbolIds[$relationship['target_key']] ?? null) : null;
            $external = $targetId === null ? ($relationship['external_name'] ?: $relationship['target_name']) : null;
            if ($targetId === null && ($external === null || $external === '')) continue;
            $relationshipStatement->execute([
                'project_id' => $projectId,
                'scan_run_id' => $scanRunId,
                'source_symbol_id' => $sourceId,
                'target_symbol_id' => $targetId,
                'target_external_name' => $external === null ? null : self::limit((string) $external, 700),
                'evidence_file_id' => $fileIds[$relationship['evidence_path']],
                'relationship_type' => self::limit((string) $relationship['type'], 50),
                'confidence' => $relationship['confidence'],
                'evidence_line_start' => $relationship['line_start'],
                'evidence_line_end' => $relationship['line_end'],
                'evidence_excerpt' => $relationship['excerpt'] === null ? null : self::limit((string) $relationship['excerpt'], 1000),
                'metadata_json' => self::json($relationship['metadata']),
                'provenance_json' => self::json(is_array($relationship['metadata']['provenance'] ?? null) ? $relationship['metadata']['provenance'] : []),
            ]);
        }

        $routeStatement = $pdo->prepare('INSERT INTO code_routes (project_id, scan_run_id, file_id, handler_symbol_id, route_key, framework, http_method, route_path, route_name, middleware_json, confidence, evidence_line, metadata_json, provenance_json) VALUES (:project_id, :scan_run_id, :file_id, :handler_symbol_id, :route_key, :framework, :http_method, :route_path, :route_name, :middleware_json, :confidence, :evidence_line, :metadata_json, :provenance_json)');
        foreach ($graph['routes'] as $route) {
            if (!isset($fileIds[$route['path']])) continue;
            $routeStatement->execute([
                'project_id' => $projectId,
                'scan_run_id' => $scanRunId,
                'file_id' => $fileIds[$route['path']],
                'handler_symbol_id' => $route['handler_key'] === null ? null : ($symbolIds[$route['handler_key']] ?? null),
                'route_key' => $route['key'],
                'framework' => self::limit((string) $route['framework'], 50),
                'http_method' => self::limit((string) $route['method'], 20),
                'route_path' => self::limit((string) $route['route_path'], 700),
                'route_name' => $route['name'] === null ? null : self::limit((string) $route['name'], 255),
                'middleware_json' => self::json($route['middleware']),
                'confidence' => $route['confidence'],
                'evidence_line' => $route['line'],
                'metadata_json' => self::json($route['metadata']),
                'provenance_json' => self::json(is_array($route['metadata']['provenance'] ?? null) ? $route['metadata']['provenance'] : []),
            ]);
        }
    }

    private static function json(array $value): ?string
    {
        return $value === [] ? null : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function limit(string $value, int $characters): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $characters, 'UTF-8') : preg_replace('/[^\x00-\x7F]/', '?', substr($value, 0, $characters)) ?? '';
    }
}
