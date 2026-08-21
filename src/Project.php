<?php

declare(strict_types=1);

final class Project
{
    /** @return array{project: array<string, mixed>}|array{error: string} */
    public static function createFromGithub(int $userId, string $name, string $repositoryUrl, string $profile = AnalysisProfile::QUICK): array
    {
        @set_time_limit(120);
        $repositoryUrl = RepositoryImporter::normalizeGithubUrl($repositoryUrl);
        $name = trim($name);
        if ($repositoryUrl === null) return ['error' => 'Use a public HTTPS GitHub repository URL, for example https://github.com/owner/repository.'];
        if ($name === '') $name = RepositoryImporter::defaultName($repositoryUrl);
        if (text_length($name) > 140) return ['error' => 'Project names must be 140 characters or fewer.'];

        try { GitHubAppService::inspectPublicRepository($repositoryUrl); }
        catch (GitHubAccessException $exception) { return ['error' => $exception->safeMessage()]; }

        try {
            $projectId = Database::insert('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)', ['user_id' => $userId, 'name' => $name, 'repository_url' => $repositoryUrl, 'local_path' => '', 'status' => 'queued']);
        } catch (PDOException $exception) {
            if (Database::isUniqueViolation($exception)) return ['error' => 'You have already imported this repository.'];
            throw $exception;
        }

        $path = null;
        try {
            self::setStatus($projectId, 'cloning');
            $path = RepositoryImporter::clone($projectId, $repositoryUrl);
            Database::connection()->prepare('UPDATE projects SET local_path = :local_path, status = :status WHERE id = :id')->execute(['local_path' => $path, 'status' => 'scanning', 'id' => $projectId]);
            (new RepoScanner())->scan($projectId, $path, AnalysisProfile::normalize($profile));
        } catch (Throwable $exception) {
            Logger::error('Repository import failed', ['project_id' => $projectId, 'type' => get_class($exception), 'message' => $exception->getMessage()]);
            $safe = self::safeRepositoryError($exception, 'The repository could not be imported. Check the URL or connect GitHub for private access.');
            Database::connection()->prepare('UPDATE projects SET status = :status, last_error = :last_error WHERE id = :id')->execute(['status' => 'failed', 'last_error' => substr($safe, 0, 500), 'id' => $projectId]);
            return ['error' => $safe];
        } finally {
            if ($path !== null && RepositoryImporter::ephemeral()) {
                RepositoryImporter::cleanup($path);
                Database::connection()->prepare("UPDATE projects SET local_path = '' WHERE id = :id")->execute(['id' => $projectId]);
            }
        }
        return ['project' => self::findForUser($projectId, $userId)];
    }

    /** @return array{project: array<string, mixed>}|array{error: string} */
    public static function createFromGitHubInstallation(int $userId, int $installationRecordId, int $repositoryId, string $name, string $profile = AnalysisProfile::QUICK): array
    {
        @set_time_limit(120);
        $name = trim($name);
        if (text_length($name) > 140) return ['error' => 'Project names must be 140 characters or fewer.'];
        $projectId = null;
        $path = null;
        try {
            $prepared = GitHubAppService::withRepositoryAccess($userId, $installationRecordId, $repositoryId, static function (array $repository, string $token) use ($userId, $installationRecordId, $name, &$projectId, &$path): array {
                $projectName = $name === '' ? (string) $repository['name'] : $name;
                $existing = Database::connection()->prepare('SELECT id, name FROM projects WHERE user_id = :user_id AND repository_url = :repository_url LIMIT 1');
                $existing->execute(['user_id' => $userId, 'repository_url' => (string) $repository['repository_url']]);
                $ownedProject = $existing->fetch();
                if ($ownedProject) {
                    $id = (int) $ownedProject['id'];
                    Database::connection()->prepare('UPDATE projects SET name = :name, local_path = :local_path, status = :status, last_error = NULL, github_installation_id = :installation_id, github_repository_id = :repository_id, github_repository_owner = :repository_owner, github_repository_name = :repository_name, github_repository_visibility = :repository_visibility WHERE id = :id AND user_id = :user_id')->execute([
                        'name' => $name === '' ? (string) $ownedProject['name'] : $projectName,
                        'local_path' => '',
                        'status' => 'cloning',
                        'installation_id' => $installationRecordId,
                        'repository_id' => (int) $repository['id'],
                        'repository_owner' => (string) $repository['owner'],
                        'repository_name' => (string) $repository['name'],
                        'repository_visibility' => (string) $repository['visibility'],
                        'id' => $id,
                        'user_id' => $userId,
                    ]);
                } else try {
                    $id = Database::insert('INSERT INTO projects (user_id, name, repository_url, local_path, status, github_installation_id, github_repository_id, github_repository_owner, github_repository_name, github_repository_visibility) VALUES (:user_id, :name, :repository_url, :local_path, :status, :installation_id, :repository_id, :repository_owner, :repository_name, :repository_visibility)', [
                        'user_id' => $userId,
                        'name' => $projectName,
                        'repository_url' => (string) $repository['repository_url'],
                        'local_path' => '',
                        'status' => 'cloning',
                        'installation_id' => $installationRecordId,
                        'repository_id' => (int) $repository['id'],
                        'repository_owner' => (string) $repository['owner'],
                        'repository_name' => (string) $repository['name'],
                        'repository_visibility' => (string) $repository['visibility'],
                    ]);
                } catch (PDOException $exception) {
                    if (Database::isUniqueViolation($exception)) throw new GitHubAccessException('This repository changed while it was being linked. Try again.', 'Concurrent private project import.', 'concurrent_project', $exception);
                    throw $exception;
                }
                $projectId = $id;
                $copy = RepositoryImporter::cloneAuthorized($id, (string) $repository['repository_url'], $token);
                $path = $copy;
                Database::connection()->prepare('UPDATE projects SET local_path = :local_path, status = :status WHERE id = :id')->execute(['local_path' => $copy, 'status' => 'scanning', 'id' => $id]);
                return ['project_id' => $id, 'path' => $copy];
            });
            $projectId = (int) $prepared['project_id'];
            $path = (string) $prepared['path'];
            (new RepoScanner())->scan($projectId, $path, AnalysisProfile::normalize($profile));
        } catch (Throwable $exception) {
            $safe = self::safeRepositoryError($exception, 'The private repository could not be imported. Update GitHub access and try again.');
            Logger::error('Private repository import failed', ['project_id' => $projectId, 'type' => get_class($exception), 'message' => $exception->getMessage()]);
            if ($projectId !== null) Database::connection()->prepare('UPDATE projects SET status = :status, last_error = :last_error WHERE id = :id AND user_id = :user_id')->execute(['status' => 'failed', 'last_error' => substr($safe, 0, 500), 'id' => $projectId, 'user_id' => $userId]);
            return ['error' => $safe];
        } finally {
            if ($path !== null && RepositoryImporter::ephemeral()) {
                RepositoryImporter::cleanup($path);
                if ($projectId !== null) Database::connection()->prepare("UPDATE projects SET local_path = '' WHERE id = :id AND user_id = :user_id")->execute(['id' => $projectId, 'user_id' => $userId]);
            }
        }
        return ['project' => self::findForUser((int) $projectId, $userId)];
    }

    public static function rescan(array $project, int $userId, string $profile = AnalysisProfile::QUICK): ?string
    {
        if ((int) $project['user_id'] !== $userId) return 'Project not found.';
        $path = null;
        try {
            @set_time_limit(120);
            self::setStatus((int) $project['id'], 'scanning');
            $path = RepositoryImporter::workingCopy($project);
            Database::connection()->prepare('UPDATE projects SET local_path = :path WHERE id = :id')->execute(['path' => RepositoryImporter::ephemeral() ? '' : $path, 'id' => $project['id']]);
            (new RepoScanner())->scan((int) $project['id'], $path, AnalysisProfile::normalize($profile));
        } catch (Throwable $exception) {
            Logger::error('Repository rescan failed', ['project_id' => $project['id'], 'type' => get_class($exception), 'message' => $exception->getMessage()]);
            $safe = self::safeRepositoryError($exception, 'The repository could not be rescanned. Try Quick analysis again.');
            Database::connection()->prepare('UPDATE projects SET status = :status, last_error = :last_error WHERE id = :id AND user_id = :user_id')->execute(['status' => 'failed', 'last_error' => substr($safe, 0, 500), 'id' => $project['id'], 'user_id' => $userId]);
            return $safe;
        } finally {
            if ($path !== null && RepositoryImporter::ephemeral()) RepositoryImporter::cleanup($path);
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
        $sql = Database::isPostgres()
            ? "WITH RECURSIVE dependency_tree (file_id, depth, visited) AS (
                SELECT source_file_id, 1, ARRAY[source_file_id]::bigint[] FROM project_dependencies WHERE project_id = :project_id_root AND target_file_id = :file_id
                UNION ALL
                SELECT pd.source_file_id, dependency_tree.depth + 1, dependency_tree.visited || pd.source_file_id FROM project_dependencies pd INNER JOIN dependency_tree ON pd.target_file_id = dependency_tree.file_id WHERE pd.project_id = :project_id_tree AND dependency_tree.depth < 8 AND NOT (pd.source_file_id = ANY(dependency_tree.visited))
            ) SELECT pf.id, pf.path, pf.role_name, MIN(dependency_tree.depth) AS depth FROM dependency_tree INNER JOIN project_files pf ON pf.id = dependency_tree.file_id WHERE dependency_tree.depth >= 2 GROUP BY pf.id, pf.path, pf.role_name ORDER BY depth, pf.path LIMIT 100"
            : "WITH RECURSIVE dependency_tree (file_id, depth, visited) AS (
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
        $limit = max(1, min($limit, 100));
        $statement = Database::connection()->prepare("SELECT * FROM scan_findings WHERE project_id = :project_id ORDER BY CASE severity WHEN 'risk' THEN 1 WHEN 'attention' THEN 2 ELSE 3 END, id DESC LIMIT " . min(500, $limit * 20));
        $statement->execute(['project_id' => $projectId]);
        return self::deduplicateFindings($statement->fetchAll(), $limit);
    }

    /** @param array<int,array<string,mixed>> $findings @return array<int,array<string,mixed>> */
    public static function deduplicateFindings(array $findings, int $limit): array
    {
        $unique = [];
        foreach ($findings as $finding) {
            $signature = strtolower(implode('|', [(string) ($finding['finding_type'] ?? ''), (string) ($finding['title'] ?? '')]));
            if (isset($unique[$signature])) continue;
            $unique[$signature] = $finding;
            if (count($unique) >= max(1, $limit)) break;
        }
        return array_values($unique);
    }

    public static function securityFindings(int $projectId, int $limit = 300): array
    {
        $statement = Database::connection()->prepare("SELECT * FROM scan_findings WHERE project_id = :project_id AND finding_type IN ('possible_secret', 'possible_exposed_secret', 'dependency_vulnerability', 'code_security_finding', 'dynamic_code_execution', 'process_execution', 'process_or_dynamic_execution', 'authentication_boundary', 'destructive_database_operation', 'file_operation') ORDER BY CASE severity WHEN 'risk' THEN 1 WHEN 'attention' THEN 2 ELSE 3 END, id DESC LIMIT " . max(1, min($limit, 500)));
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

    private static function safeRepositoryError(Throwable $exception, string $fallback): string
    {
        return $exception instanceof GitHubAccessException ? $exception->safeMessage() : $fallback;
    }
}
