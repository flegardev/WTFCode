<?php

declare(strict_types=1);

final class Project
{
    /** @return array{project: array<string, mixed>}|array{error: string} */
    public static function createFromGithub(int $userId, string $name, string $repositoryUrl): array
    {
        @set_time_limit(120);
        $repositoryUrl = RepositoryImporter::normalizeGithubUrl($repositoryUrl);
        $name = trim($name);
        if ($repositoryUrl === null) return ['error' => 'Use a public HTTPS GitHub repository URL, for example https://github.com/owner/repository.'];
        if ($name === '') $name = RepositoryImporter::defaultName($repositoryUrl);
        if (text_length($name) > 140) return ['error' => 'Project names must be 140 characters or fewer.'];

        try {
            $statement = Database::connection()->prepare('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)');
            $statement->execute(['user_id' => $userId, 'name' => $name, 'repository_url' => $repositoryUrl, 'local_path' => '', 'status' => 'queued']);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') return ['error' => 'You have already imported this repository.'];
            throw $exception;
        }

        $projectId = (int) Database::connection()->lastInsertId();
        try {
            self::setStatus($projectId, 'cloning');
            $path = RepositoryImporter::clone($projectId, $repositoryUrl);
            Database::connection()->prepare('UPDATE projects SET local_path = :local_path, status = :status WHERE id = :id')->execute(['local_path' => $path, 'status' => 'scanning', 'id' => $projectId]);
            (new RepoScanner())->scan($projectId, $path);
        } catch (Throwable $exception) {
            Logger::error('Repository import failed', ['project_id' => $projectId, 'type' => get_class($exception), 'message' => $exception->getMessage()]);
            Database::connection()->prepare('UPDATE projects SET status = :status, last_error = :last_error WHERE id = :id')->execute(['status' => 'failed', 'last_error' => substr($exception->getMessage(), 0, 500), 'id' => $projectId]);
            return ['error' => 'The repository could not be imported. Check that the URL is public, Git is installed, and the server can reach GitHub.'];
        }
        return ['project' => self::findForUser($projectId, $userId)];
    }

    public static function rescan(array $project, int $userId, string $profile = AnalysisProfile::QUICK): ?string
    {
        if ((int) $project['user_id'] !== $userId) return 'Project not found.';
        try {
            @set_time_limit(120);
            self::setStatus((int) $project['id'], 'scanning');
            (new RepoScanner())->scan((int) $project['id'], (string) $project['local_path'], AnalysisProfile::normalize($profile));
        } catch (Throwable $exception) {
            Logger::error('Repository rescan failed', ['project_id' => $project['id'], 'type' => get_class($exception), 'message' => $exception->getMessage()]);
            Database::connection()->prepare('UPDATE projects SET status = :status, last_error = :last_error WHERE id = :id')->execute(['status' => 'failed', 'last_error' => substr($exception->getMessage(), 0, 500), 'id' => $project['id']]);
            return 'The repository could not be rescanned. Check that its private clone is still available.';
        }
        return null;
    }

    public static function findForUser(int $projectId, int $userId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM projects WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $projectId, 'user_id' => $userId]);
        return $statement->fetch() ?: null;
    }

    public static function allForUser(int $userId): array
    {
        $statement = Database::connection()->prepare("SELECT p.*, (SELECT COUNT(*) FROM project_files pf WHERE pf.project_id = p.id) AS file_count, (SELECT COUNT(*) FROM scan_findings sf WHERE sf.project_id = p.id AND sf.severity IN ('attention', 'risk')) AS attention_count FROM projects p WHERE p.user_id = :user_id ORDER BY p.updated_at DESC");
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public static function dashboardStats(int $userId): array
    {
        $statement = Database::connection()->prepare("SELECT COUNT(*) AS project_count, COALESCE(SUM((SELECT COUNT(*) FROM project_files pf WHERE pf.project_id = p.id)), 0) AS file_count, COALESCE(SUM((SELECT COUNT(*) FROM scan_findings sf WHERE sf.project_id = p.id AND sf.severity IN ('attention', 'risk'))), 0) AS attention_count FROM projects p WHERE p.user_id = :user_id");
        $statement->execute(['user_id' => $userId]);
        return $statement->fetch() ?: ['project_count' => 0, 'file_count' => 0, 'attention_count' => 0];
    }

    public static function files(int $projectId, string $search = '', int $limit = 100): array
    {
        return self::filesPage($projectId, $search, 1, $limit)['files'];
    }

    /** @return array{files: array<int, array<string, mixed>>, total: int, page: int, pages: int} */
    public static function filesPage(int $projectId, string $search, int $page, int $perPage = 50): array
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);
        $where = 'pf.project_id = :project_id';
        $params = ['project_id' => $projectId];
        if ($search !== '') {
            $where .= ' AND (pf.path LIKE :search_path OR pf.role_name LIKE :search_role OR pf.language LIKE :search_language)';
            $params['search_path'] = '%' . $search . '%';
            $params['search_role'] = '%' . $search . '%';
            $params['search_language'] = '%' . $search . '%';
        }
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM project_files pf WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $sql = 'SELECT pf.*, (SELECT COUNT(*) FROM project_dependencies pd WHERE pd.target_file_id = pf.id) AS dependent_count, (SELECT COUNT(*) FROM project_dependencies pd WHERE pd.source_file_id = pf.id) AS import_count FROM project_files pf WHERE ' . $where . ' ORDER BY dependent_count DESC, pf.path ASC LIMIT :limit OFFSET :offset';
        $statement = Database::connection()->prepare($sql);
        foreach ($params as $key => $value) $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        return ['files' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function file(int $projectId, int $fileId): ?array
    {
        $statement = Database::connection()->prepare('SELECT pf.*, (SELECT COUNT(*) FROM project_dependencies pd WHERE pd.target_file_id = pf.id) AS dependent_count, (SELECT COUNT(*) FROM project_dependencies pd WHERE pd.source_file_id = pf.id) AS import_count FROM project_files pf WHERE pf.project_id = :project_id AND pf.id = :file_id LIMIT 1');
        $statement->execute(['project_id' => $projectId, 'file_id' => $fileId]);
        return $statement->fetch() ?: null;
    }

    public static function fileByPath(int $projectId, string $path): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, path FROM project_files WHERE project_id = :project_id AND path = :path LIMIT 1');
        $statement->execute(['project_id' => $projectId, 'path' => $path]);
        return $statement->fetch() ?: null;
    }

    public static function dependents(int $fileId): array
    {
        $statement = Database::connection()->prepare('SELECT pf.id, pf.path, pf.role_name, pd.relationship_type FROM project_dependencies pd INNER JOIN project_files pf ON pf.id = pd.source_file_id WHERE pd.target_file_id = :file_id ORDER BY pf.path LIMIT 100');
        $statement->execute(['file_id' => $fileId]);
        return $statement->fetchAll();
    }

    public static function transitiveDependents(int $projectId, int $fileId): array
    {
        $sql = "WITH RECURSIVE dependency_tree (file_id, depth, visited) AS (
            SELECT source_file_id, 1, CAST(CONCAT(',', source_file_id, ',') AS CHAR(1000)) FROM project_dependencies WHERE project_id = :project_id_root AND target_file_id = :file_id
            UNION ALL
            SELECT pd.source_file_id, dependency_tree.depth + 1, CONCAT(dependency_tree.visited, pd.source_file_id, ',') FROM project_dependencies pd INNER JOIN dependency_tree ON pd.target_file_id = dependency_tree.file_id WHERE pd.project_id = :project_id_tree AND dependency_tree.depth < 8 AND LOCATE(CONCAT(',', pd.source_file_id, ','), dependency_tree.visited) = 0
        ) SELECT pf.id, pf.path, pf.role_name, MIN(dependency_tree.depth) AS depth FROM dependency_tree INNER JOIN project_files pf ON pf.id = dependency_tree.file_id WHERE dependency_tree.depth >= 2 GROUP BY pf.id, pf.path, pf.role_name ORDER BY depth, pf.path LIMIT 100";
        $statement = Database::connection()->prepare($sql);
        $statement->execute(['project_id_root' => $projectId, 'project_id_tree' => $projectId, 'file_id' => $fileId]);
        return $statement->fetchAll();
    }

    public static function imports(int $fileId): array
    {
        $statement = Database::connection()->prepare('SELECT pd.target_path, pd.relationship_type, pf.id AS target_id, pf.role_name FROM project_dependencies pd LEFT JOIN project_files pf ON pf.id = pd.target_file_id WHERE pd.source_file_id = :file_id ORDER BY pd.target_path LIMIT 100');
        $statement->execute(['file_id' => $fileId]);
        return $statement->fetchAll();
    }

    public static function architecture(int $projectId): array
    {
        $nodes = Database::connection()->prepare('SELECT * FROM architecture_nodes WHERE project_id = :project_id ORDER BY id');
        $nodes->execute(['project_id' => $projectId]);
        $edges = Database::connection()->prepare('SELECT ae.relationship_label, ae.evidence_json, source.node_key AS from_key, target.node_key AS to_key, source.label AS from_label, target.label AS to_label FROM architecture_edges ae INNER JOIN architecture_nodes source ON source.id = ae.from_node_id INNER JOIN architecture_nodes target ON target.id = ae.to_node_id WHERE ae.project_id = :project_id ORDER BY ae.id');
        $edges->execute(['project_id' => $projectId]);
        return ['nodes' => $nodes->fetchAll(), 'edges' => $edges->fetchAll()];
    }

    public static function architectureNode(int $projectId, string $key): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM architecture_nodes WHERE project_id = :project_id AND node_key = :node_key LIMIT 1');
        $statement->execute(['project_id' => $projectId, 'node_key' => $key]);
        return $statement->fetch() ?: null;
    }

    public static function architectureConnections(int $projectId, string $key): array
    {
        $statement = Database::connection()->prepare('SELECT ae.relationship_label, ae.evidence_json, source.node_key AS from_key, target.node_key AS to_key, source.label AS from_label, target.label AS to_label FROM architecture_edges ae INNER JOIN architecture_nodes source ON source.id = ae.from_node_id INNER JOIN architecture_nodes target ON target.id = ae.to_node_id WHERE ae.project_id = :project_id AND (source.node_key = :source_key OR target.node_key = :target_key) ORDER BY ae.id');
        $statement->execute(['project_id' => $projectId, 'source_key' => $key, 'target_key' => $key]);
        return $statement->fetchAll();
    }

    public static function filesForPaths(int $projectId, array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths, 'is_string')));
        if ($paths === []) return [];
        $placeholders = implode(', ', array_fill(0, count($paths), '?'));
        $statement = Database::connection()->prepare('SELECT * FROM project_files WHERE project_id = ? AND path IN (' . $placeholders . ') ORDER BY path');
        $statement->execute(array_merge([$projectId], $paths));
        return $statement->fetchAll();
    }

    public static function findings(int $projectId, int $limit = 12): array
    {
        $statement = Database::connection()->prepare("SELECT * FROM scan_findings WHERE project_id = :project_id ORDER BY FIELD(severity, 'risk', 'attention', 'info'), id DESC LIMIT " . max(1, min($limit, 100)));
        $statement->execute(['project_id' => $projectId]);
        return $statement->fetchAll();
    }

    public static function securityFindings(int $projectId, int $limit = 300): array
    {
        $statement = Database::connection()->prepare("SELECT * FROM scan_findings WHERE project_id = :project_id AND finding_type IN ('possible_secret', 'possible_exposed_secret', 'dependency_vulnerability', 'code_security_finding', 'dynamic_code_execution', 'process_execution', 'process_or_dynamic_execution', 'authentication_boundary', 'destructive_database_operation', 'file_operation') ORDER BY FIELD(severity, 'risk', 'attention', 'info'), id DESC LIMIT " . max(1, min($limit, 500)));
        $statement->execute(['project_id' => $projectId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            try { $row['evidence'] = json_decode((string) ($row['evidence_json'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR); }
            catch (Throwable) { $row['evidence'] = []; }
        }
        unset($row);
        return $rows;
    }

    public static function packageInventory(int $projectId, int $limit = 500): array
    {
        $statement = Database::connection()->prepare('SELECT pi.* FROM package_inventory pi INNER JOIN (SELECT MAX(id) AS id FROM scan_runs WHERE project_id = :scan_project) latest ON latest.id = pi.scan_run_id WHERE pi.project_id = :project_id ORDER BY pi.classification, pi.ecosystem, pi.package_name LIMIT ' . max(1, min($limit, 1000)));
        $statement->execute(['scan_project' => $projectId, 'project_id' => $projectId]);
        return $statement->fetchAll();
    }

    public static function analyzerRuns(int $projectId): array
    {
        $statement = Database::connection()->prepare('SELECT apr.* FROM analysis_provider_runs apr INNER JOIN (SELECT MAX(id) AS id FROM scan_runs WHERE project_id = :scan_project) latest ON latest.id = apr.scan_run_id WHERE apr.project_id = :project_id ORDER BY apr.engine_id');
        $statement->execute(['scan_project' => $projectId, 'project_id' => $projectId]);
        return $statement->fetchAll();
    }

    public static function filesByRole(int $projectId, string $role): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM project_files WHERE project_id = :project_id AND role_name = :role ORDER BY path LIMIT 30');
        $statement->execute(['project_id' => $projectId, 'role' => $role]);
        return $statement->fetchAll();
    }

    private static function setStatus(int $projectId, string $status): void
    {
        Database::connection()->prepare('UPDATE projects SET status = :status, last_error = NULL WHERE id = :id')->execute(['status' => $status, 'id' => $projectId]);
    }
}
