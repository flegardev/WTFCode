<?php

declare(strict_types=1);

final class SymbolRepository
{
    public static function latestScan(int $projectId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM scan_runs WHERE project_id = :project_id ORDER BY id DESC LIMIT 1');
        $statement->execute(['project_id' => $projectId]);
        return $statement->fetch() ?: null;
    }

    public static function counts(int $projectId): array
    {
        $statement = Database::connection()->prepare("SELECT COUNT(*) AS symbol_count, COUNT(DISTINCT file_id) AS symbol_file_count, SUM(symbol_type = 'table') AS table_count, SUM(symbol_type = 'external_service') AS service_count, SUM(symbol_type = 'environment_variable') AS env_count FROM code_symbols WHERE project_id = :project_id");
        $statement->execute(['project_id' => $projectId]);
        $counts = $statement->fetch() ?: [];
        $route = Database::connection()->prepare('SELECT COUNT(*) FROM code_routes WHERE project_id = :project_id');
        $route->execute(['project_id' => $projectId]);
        $counts['route_count'] = (int) $route->fetchColumn();
        return $counts;
    }

    /** @return array{symbols: array<int, array<string, mixed>>, total: int, page: int, pages: int} */
    public static function symbolsPage(int $projectId, string $search = '', string $type = '', int $page = 1, int $perPage = 60): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = 'cs.project_id = :project_id';
        $params = ['project_id' => $projectId];
        if ($search !== '') {
            $where .= ' AND (cs.name LIKE :name OR cs.qualified_name LIKE :qualified OR pf.path LIKE :path)';
            $params += ['name' => '%' . $search . '%', 'qualified' => '%' . $search . '%', 'path' => '%' . $search . '%'];
        }
        if ($type !== '') {
            $where .= ' AND cs.symbol_type = :symbol_type';
            $params['symbol_type'] = $type;
        }
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM code_symbols cs INNER JOIN project_files pf ON pf.id = cs.file_id WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $statement = Database::connection()->prepare("SELECT cs.*, pf.path, pf.role_name, pf.language AS file_language, (SELECT COUNT(*) FROM symbol_relationships sr WHERE sr.source_symbol_id = cs.id) AS outgoing_count, (SELECT COUNT(*) FROM symbol_relationships sr WHERE sr.target_symbol_id = cs.id) AS incoming_count FROM code_symbols cs INNER JOIN project_files pf ON pf.id = cs.file_id WHERE $where ORDER BY FIELD(cs.confidence, 'high', 'medium', 'low'), FIELD(cs.symbol_type, 'route_handler', 'controller', 'model', 'class', 'component', 'function', 'method', 'table', 'module'), cs.name LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        return ['symbols' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function types(int $projectId): array
    {
        $statement = Database::connection()->prepare('SELECT symbol_type, COUNT(*) AS total FROM code_symbols WHERE project_id = :project_id GROUP BY symbol_type ORDER BY total DESC, symbol_type');
        $statement->execute(['project_id' => $projectId]);
        return $statement->fetchAll();
    }

    public static function symbol(int $projectId, int $symbolId): ?array
    {
        $statement = Database::connection()->prepare('SELECT cs.*, pf.path, pf.role_name, pf.line_count, parent.name AS parent_name, parent.symbol_type AS parent_type FROM code_symbols cs INNER JOIN project_files pf ON pf.id = cs.file_id LEFT JOIN code_symbols parent ON parent.id = cs.parent_symbol_id WHERE cs.project_id = :project_id AND cs.id = :symbol_id LIMIT 1');
        $statement->execute(['project_id' => $projectId, 'symbol_id' => $symbolId]);
        return $statement->fetch() ?: null;
    }

    public static function fileSymbols(int $projectId, int $fileId): array
    {
        $statement = Database::connection()->prepare("SELECT cs.*, (SELECT COUNT(*) FROM symbol_relationships sr WHERE sr.source_symbol_id = cs.id) AS outgoing_count, (SELECT COUNT(*) FROM symbol_relationships sr WHERE sr.target_symbol_id = cs.id) AS incoming_count FROM code_symbols cs WHERE cs.project_id = :project_id AND cs.file_id = :file_id ORDER BY cs.start_line, FIELD(cs.symbol_type, 'module', 'class', 'interface', 'trait', 'controller', 'model', 'function', 'method', 'property', 'column')");
        $statement->execute(['project_id' => $projectId, 'file_id' => $fileId]);
        return $statement->fetchAll();
    }

    public static function search(int $projectId, string $query, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $statement = Database::connection()->prepare("SELECT cs.*, pf.path, pf.role_name FROM code_symbols cs INNER JOIN project_files pf ON pf.id = cs.file_id WHERE cs.project_id = :project_id AND (cs.name LIKE :name OR cs.qualified_name LIKE :qualified OR pf.path LIKE :path) ORDER BY (LOWER(cs.name) = LOWER(:exact)) DESC, FIELD(cs.confidence, 'high', 'medium', 'low'), FIELD(cs.symbol_type, 'route_handler', 'controller', 'model', 'class', 'component', 'function', 'method', 'table', 'module') LIMIT $limit");
        $search = '%' . $query . '%';
        $statement->execute(['project_id' => $projectId, 'name' => $search, 'qualified' => $search, 'path' => $search, 'exact' => $query]);
        return $statement->fetchAll();
    }

    public static function relationships(int $projectId, int $symbolId, string $direction = 'outgoing'): array
    {
        $outgoing = $direction !== 'incoming';
        $column = $outgoing ? 'sr.source_symbol_id' : 'sr.target_symbol_id';
        $statement = Database::connection()->prepare("SELECT sr.*, source.name AS source_name, source.symbol_type AS source_type, source_file.path AS source_path, target.name AS target_name, target.symbol_type AS target_type, target_file.path AS target_path, evidence.path AS evidence_path FROM symbol_relationships sr LEFT JOIN code_symbols source ON source.id = sr.source_symbol_id LEFT JOIN project_files source_file ON source_file.id = source.file_id LEFT JOIN code_symbols target ON target.id = sr.target_symbol_id LEFT JOIN project_files target_file ON target_file.id = target.file_id INNER JOIN project_files evidence ON evidence.id = sr.evidence_file_id WHERE sr.project_id = :project_id AND $column = :symbol_id ORDER BY FIELD(sr.confidence, 'high', 'medium', 'low'), sr.relationship_type, sr.evidence_line_start LIMIT 200");
        $statement->execute(['project_id' => $projectId, 'symbol_id' => $symbolId]);
        return $statement->fetchAll();
    }

    public static function routes(int $projectId, string $search = ''): array
    {
        $where = 'cr.project_id = :project_id';
        $params = ['project_id' => $projectId];
        if ($search !== '') {
            $where .= ' AND (cr.route_path LIKE :route_path OR cr.route_name LIKE :route_name OR cr.http_method = :method OR pf.path LIKE :file_path)';
            $params += ['route_path' => '%' . $search . '%', 'route_name' => '%' . $search . '%', 'method' => strtoupper($search), 'file_path' => '%' . $search . '%'];
        }
        $statement = Database::connection()->prepare("SELECT cr.*, pf.path, handler.name AS handler_name, handler.symbol_type AS handler_type FROM code_routes cr INNER JOIN project_files pf ON pf.id = cr.file_id LEFT JOIN code_symbols handler ON handler.id = cr.handler_symbol_id WHERE $where ORDER BY cr.route_path, cr.http_method, cr.id LIMIT 500");
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public static function tables(int $projectId): array
    {
        $statement = Database::connection()->prepare("SELECT cs.*, pf.path, (SELECT COUNT(*) FROM code_symbols child WHERE child.parent_symbol_id = cs.id AND child.symbol_type = 'column') AS column_count, (SELECT COUNT(*) FROM symbol_relationships sr WHERE sr.target_symbol_id = cs.id OR sr.source_symbol_id = cs.id) AS relationship_count FROM code_symbols cs INNER JOIN project_files pf ON pf.id = cs.file_id WHERE cs.project_id = :project_id AND cs.symbol_type IN ('table', 'model', 'schema') ORDER BY cs.name, JSON_EXTRACT(cs.metadata_json, '$.reference_only'), pf.path LIMIT 500");
        $statement->execute(['project_id' => $projectId]);
        return $statement->fetchAll();
    }

    public static function services(int $projectId): array
    {
        $statement = Database::connection()->prepare("SELECT cs.*, pf.path, (SELECT COUNT(*) FROM symbol_relationships sr WHERE sr.target_symbol_id = cs.id) AS reference_count FROM code_symbols cs INNER JOIN project_files pf ON pf.id = cs.file_id WHERE cs.project_id = :project_id AND cs.symbol_type IN ('external_service', 'environment_variable') ORDER BY cs.symbol_type, cs.name, pf.path LIMIT 500");
        $statement->execute(['project_id' => $projectId]);
        return $statement->fetchAll();
    }

    public static function graph(int $projectId, int $limit = 180): array
    {
        $limit = max(20, min(300, $limit));
        $nodes = Database::connection()->prepare("SELECT cs.id, cs.name, cs.qualified_name, cs.symbol_type, cs.confidence, cs.start_line, cs.metadata_json, pf.id AS file_id, pf.path, pf.role_name, pf.language FROM code_symbols cs INNER JOIN project_files pf ON pf.id = cs.file_id WHERE cs.project_id = :project_id ORDER BY (SELECT COUNT(*) FROM symbol_relationships sr WHERE sr.source_symbol_id = cs.id OR sr.target_symbol_id = cs.id) DESC, FIELD(cs.confidence, 'high', 'medium', 'low') LIMIT $limit");
        $nodes->execute(['project_id' => $projectId]);
        $nodeRows = $nodes->fetchAll();
        foreach ($nodeRows as &$node) {
            try { $metadata = json_decode((string) ($node['metadata_json'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR); }
            catch (Throwable) { $metadata = []; }
            $node['subsystem'] = self::graphSubsystem((string) $node['symbol_type'], (string) $node['path'], (string) $node['role_name']);
            $node['architecture'] = self::graphArchitecture((string) $node['subsystem']);
            $node['feature'] = self::graphFeature((string) $node['name'], (string) $node['path'], (string) $node['symbol_type']);
            $node['framework'] = (string) ($metadata['framework'] ?? 'Unspecified');
            $node['risk'] = self::graphRisk((string) $node['symbol_type'], (string) $node['name'], (string) $node['path']);
            unset($node['metadata_json']);
        }
        unset($node);
        if ($nodeRows === []) return ['nodes' => [], 'edges' => []];
        $ids = array_map('intval', array_column($nodeRows, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $edges = Database::connection()->prepare("SELECT sr.id, sr.source_symbol_id, sr.target_symbol_id, sr.relationship_type, sr.confidence, sr.evidence_line_start, pf.path AS evidence_path FROM symbol_relationships sr INNER JOIN project_files pf ON pf.id = sr.evidence_file_id WHERE sr.project_id = ? AND sr.source_symbol_id IN ($placeholders) AND sr.target_symbol_id IN ($placeholders) AND sr.confidence IN ('high', 'medium') ORDER BY FIELD(sr.confidence, 'high', 'medium'), sr.id LIMIT 700");
        $edges->execute(array_merge([$projectId], $ids, $ids));
        return ['nodes' => $nodeRows, 'edges' => $edges->fetchAll()];
    }

    private static function graphSubsystem(string $type, string $path, string $role): string
    {
        $signal = strtolower($type . ' ' . $path . ' ' . $role);
        return match (true) {
            preg_match('/auth|session|login|permission|policy|guard/', $signal) === 1 => 'Identity and access',
            preg_match('/component|hook|frontend|view|template|\.tsx|\.jsx|\.vue|\.svelte/', $signal) === 1 => 'Frontend',
            preg_match('/route|controller|middleware|api|server.action|webhook|resolver/', $signal) === 1 => 'API and routing',
            preg_match('/table|model|schema|column|migration|database|repository/', $signal) === 1 => 'Data',
            preg_match('/external.service|service:|client|integration|stripe|supabase|firebase|openai/', $signal) === 1 => 'Integrations',
            preg_match('/environment|config|deployment|docker|vercel|cloudflare/', $signal) === 1 => 'Configuration',
            default => 'Application core',
        };
    }

    private static function graphArchitecture(string $subsystem): string
    {
        return match ($subsystem) {
            'Frontend' => 'Interface',
            'Data' => 'Data layer',
            'Integrations' => 'External systems',
            'Configuration' => 'Operations',
            default => 'Application',
        };
    }

    private static function graphFeature(string $name, string $path, string $type): string
    {
        $signal = strtolower($name . ' ' . $path . ' ' . $type);
        return match (true) {
            preg_match('/login|sign.?in|register|auth|session/', $signal) === 1 => 'Authentication',
            preg_match('/checkout|payment|stripe|billing|invoice/', $signal) === 1 => 'Payments',
            preg_match('/upload|file|storage|attachment/', $signal) === 1 => 'Uploads',
            preg_match('/search|query|filter/', $signal) === 1 => 'Search',
            preg_match('/admin|dashboard|manage/', $signal) === 1 => 'Administration',
            preg_match('/notification|email|message/', $signal) === 1 => 'Notifications',
            preg_match('/route|controller|api|webhook/', $signal) === 1 => 'API',
            preg_match('/table|model|schema|database/', $signal) === 1 => 'Data',
            default => 'Core behavior',
        };
    }

    private static function graphRisk(string $type, string $name, string $path): string
    {
        $signal = strtolower($type . ' ' . $name . ' ' . $path);
        if (preg_match('/auth|password|session|secret|process|exec|delete|unlink|payment|webhook/', $signal) === 1) return 'high';
        if (preg_match('/route|controller|table|model|schema|environment|external.service|upload|write/', $signal) === 1) return 'medium';
        return 'low';
    }

    public static function impactForPaths(int $projectId, array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths, 'is_string')));
        if ($paths === []) return ['symbols' => [], 'routes' => [], 'tables' => []];
        $placeholders = implode(',', array_fill(0, count($paths), '?'));
        $symbols = Database::connection()->prepare("SELECT cs.id, cs.name, cs.symbol_type, cs.confidence, pf.path FROM code_symbols cs INNER JOIN project_files pf ON pf.id = cs.file_id WHERE cs.project_id = ? AND pf.path IN ($placeholders) AND cs.symbol_type <> 'module' ORDER BY pf.path, cs.start_line");
        $symbols->execute(array_merge([$projectId], $paths));
        $routes = Database::connection()->prepare("SELECT DISTINCT cr.http_method, cr.route_path, cr.framework, route_file.path, handler.name AS handler_name FROM code_routes cr INNER JOIN project_files route_file ON route_file.id = cr.file_id LEFT JOIN code_symbols handler ON handler.id = cr.handler_symbol_id LEFT JOIN project_files handler_file ON handler_file.id = handler.file_id WHERE cr.project_id = ? AND (route_file.path IN ($placeholders) OR handler_file.path IN ($placeholders)) ORDER BY cr.route_path");
        $routes->execute(array_merge([$projectId], $paths, $paths));
        $symbolRows = $symbols->fetchAll();
        return ['symbols' => $symbolRows, 'routes' => $routes->fetchAll(), 'tables' => array_values(array_filter($symbolRows, static fn (array $symbol): bool => in_array($symbol['symbol_type'], ['table', 'model', 'schema'], true)))];
    }

    public static function projectRelationships(int $projectId): array
    {
        $statement = Database::connection()->prepare("SELECT sr.*, source.file_id AS source_file_id, source.name AS source_name, source.symbol_type AS source_type, source_file.path AS source_path, target.file_id AS target_file_id, target.name AS target_name, target.symbol_type AS target_type, target_file.path AS target_path, evidence.path AS evidence_path FROM symbol_relationships sr LEFT JOIN code_symbols source ON source.id = sr.source_symbol_id LEFT JOIN project_files source_file ON source_file.id = source.file_id LEFT JOIN code_symbols target ON target.id = sr.target_symbol_id LEFT JOIN project_files target_file ON target_file.id = target.file_id INNER JOIN project_files evidence ON evidence.id = sr.evidence_file_id WHERE sr.project_id = :project_id AND sr.confidence IN ('high', 'medium') ORDER BY sr.id");
        $statement->execute(['project_id' => $projectId]);
        return $statement->fetchAll();
    }
}
