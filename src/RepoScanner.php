<?php

declare(strict_types=1);

final class RepoScanner
{
    private array $discoveryLimits = [];

    public function scan(
        int $projectId,
        string $root,
        string $profile = AnalysisProfile::QUICK,
        ?int $existingJobId = null,
        ?string $leaseToken = null,
    ): array
    {
        if (!is_dir($root)) {
            throw new RuntimeException('The repository files are no longer available.');
        }

        $profile = AnalysisProfile::normalize($profile);
        $currentCommit = $this->currentCommit($root);
        $previousScan = SymbolRepository::latestScan($projectId);
        $previousCommit = is_string($previousScan['commit_sha'] ?? null) ? trim($previousScan['commit_sha']) : null;
        $changedPaths = $this->changedPaths($root, $previousCommit, $currentCommit);
        $workerManaged = $existingJobId !== null;
        if ($workerManaged && ($leaseToken === null || !preg_match('/^[a-f0-9]{64}$/', $leaseToken))) {
            throw new RuntimeException('The scan worker lease is invalid.');
        }
        $jobId = $existingJobId ?? AnalysisJobStore::create($projectId, $profile, $currentCommit, $previousCommit, $changedPaths);
        if ($workerManaged) {
            if (!AnalysisJobStore::scanMetadata($jobId, $leaseToken, $currentCommit, $previousCommit, $changedPaths)) {
                throw new RuntimeException('The scan worker no longer owns this analysis.');
            }
            if (!AnalysisJobStore::heartbeat($jobId, (string) $leaseToken, 'Analyzing source evidence', 2, 4)) {
                throw new RuntimeException('The scan worker no longer owns this analysis.');
            }
        } else {
            AnalysisJobStore::running($jobId);
        }
        try {
            $checkpoint = $workerManaged
                ? static function (string $providerId, int $providerIndex, int $providerTotal) use ($jobId, $leaseToken): void {
                    $stage = sprintf('Analyzing evidence: %s (%d/%d)', $providerId, $providerIndex, $providerTotal);
                    if (!AnalysisJobStore::heartbeat($jobId, (string) $leaseToken, $stage, 2, 4)) {
                        throw new ScanLeaseLostException('The scan worker lease expired during analysis.');
                    }
                }
                : null;
            $inspection = $this->inspect($root, $profile, $currentCommit, $previousCommit, $changedPaths, $checkpoint);
        } catch (Throwable $exception) {
            if (!$workerManaged) AnalysisJobStore::finish($jobId, 'failed', 'The repository analysis did not finish.');
            throw $exception;
        }
        $files = $inspection['files'];
        unset($inspection['files']);
        $analysis = $inspection;
        if ($workerManaged && !AnalysisJobStore::heartbeat($jobId, (string) $leaseToken, 'Persisting analysis evidence', 3, 4)) {
            throw new RuntimeException('The scan worker lease expired before evidence could be saved.');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $this->clearPreviousScan($projectId);
            $fileIds = $this->persistFiles($projectId, $files);
            $this->persistDependencies($projectId, $files, $fileIds);
            $nodeIds = $this->persistNodes($projectId, $analysis['nodes']);
            $this->persistEdges($projectId, $analysis['edges'], $nodeIds);
            $commit = $currentCommit;
            $scanRunId = Database::insert('INSERT INTO scan_runs (project_id, commit_sha, analysis_version, analysis_profile, files_scanned, findings_count, analyzer_stats_json, engine_status_json) VALUES (:project_id, :commit_sha, :analysis_version, :analysis_profile, :files_scanned, :findings_count, :analyzer_stats_json, :engine_status_json)', [
                    'project_id' => $projectId,
                    'commit_sha' => $commit,
                    'analysis_version' => AnalysisEngine::VERSION,
                    'analysis_profile' => $profile,
                    'files_scanned' => count($files),
                    'findings_count' => count($analysis['findings']),
                    'analyzer_stats_json' => json_encode($analysis['symbol_graph']['stats'], JSON_UNESCAPED_SLASHES),
                    'engine_status_json' => json_encode($analysis['symbol_graph']['engine_runs'] ?? [], JSON_UNESCAPED_SLASHES),
                ]);
            FindingStore::persist($projectId, $scanRunId, $analysis['findings']);
            SymbolGraphStore::persist($projectId, $scanRunId, $fileIds, $analysis['symbol_graph']);
            AnalyzerRunStore::persist($projectId, $scanRunId, $analysis['symbol_graph']['engine_runs'] ?? []);
            PackageInventoryStore::persist($projectId, $scanRunId, $analysis['symbol_graph']['packages'] ?? []);
            DisagreementStore::persist($projectId, $scanRunId, $analysis['symbol_graph']['disagreements'] ?? []);
            AnalysisJobStore::steps($jobId, $analysis['symbol_graph']['engine_runs'] ?? []);
            if (!AnalysisJobStore::associateScanRun($jobId, $leaseToken, $scanRunId)) {
                throw new RuntimeException('The analysis job could not be linked to its evidence.');
            }
            $statement = $pdo->prepare('UPDATE projects SET status = :status, stack_json = :stack_json, overview = :overview, last_scan_at = NOW(), last_error = NULL WHERE id = :id');
            $statement->execute([
                'status' => 'ready',
                'stack_json' => json_encode($analysis['stack'], JSON_UNESCAPED_SLASHES),
                'overview' => $analysis['overview'],
                'id' => $projectId,
            ]);
            $partial = count(array_filter($analysis['symbol_graph']['engine_runs'] ?? [], static fn (array $run): bool => in_array($run['status'] ?? '', ['partial', 'failed', 'unavailable'], true))) > 0;
            if (!AnalysisJobStore::finish($jobId, $partial ? 'partial' : 'completed', null, $leaseToken)) {
                throw new RuntimeException('The analysis job could not be completed by this worker.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!$workerManaged) AnalysisJobStore::finish($jobId, 'failed', 'The repository analysis did not finish.');
            throw $exception;
        }

        return $analysis + ['files_scanned' => count($files)];
    }

    /**
     * Read-only inspection used by the scanner and lightweight unit checks.
     * Source contents only exist in this method's in-memory result.
     */
    public function inspect(
        string $root,
        string $profile = AnalysisProfile::QUICK,
        ?string $currentRevision = null,
        ?string $previousRevision = null,
        array $changedPaths = [],
        ?callable $analysisCheckpoint = null,
    ): array
    {
        if (!is_dir($root)) throw new RuntimeException('The repository files are no longer available.');
        $collected = (new \WTFCode\Scanning\FileCollector())->collect($root);
        $this->discoveryLimits = $collected['limitations'];
        $files = $collected['files'];
        $analysis = $this->analyse($files);
        $analysis['symbol_graph'] = (new AnalysisCoordinator())->analyze(
            new AnalysisRequest($root, $files, $profile, $currentRevision, $previousRevision, $changedPaths),
            $analysisCheckpoint,
        );
        $analysis['symbol_graph'] = (new ProductIntelligence())->enrich($analysis['symbol_graph'], $files);
        $analysis['findings'] = array_merge($analysis['findings'], $analysis['symbol_graph']['findings'] ?? []);
        return $analysis + ['files' => $files];
    }

    /** @return array<int, string> */
    private function changedPaths(string $root, ?string $previous, ?string $current): array
    {
        if ($previous === null || $current === null || $previous === $current || !preg_match('/^[a-f0-9]{40}$/i', $previous) || !preg_match('/^[a-f0-9]{40}$/i', $current)) return [];
        try {
            $result = (new SafeProcessRunner())->run(new ProcessRunRequest(['git', '-C', $root, 'diff', '--name-only', '--diff-filter=ACDMRTUXB', $previous, $current, '--'], $root, 20, 1_048_576, 262_144));
            if (!$result->succeeded()) return [];
            return array_slice(array_values(array_filter(array_map(static fn (string $path): string => str_replace('\\', '/', trim($path)), preg_split('/\R/', $result->stdout) ?: []))), 0, 1000);
        } catch (Throwable) {
            return [];
        }
    }

    private function analyse(array $files): array
    {
        $primaryFiles = array_values(array_filter($files, static fn (array $file): bool => RuntimeEvidencePolicy::isRuntimePath((string) $file['path'])));
        $paths = array_column($primaryFiles, 'path');
        $codeFiles = array_values(array_filter($primaryFiles, static fn (array $file): bool => $file['language'] !== 'Markdown'));
        $contents = implode("\n", array_column($codeFiles, 'content'));
        $frontendContents = implode("\n", array_column(array_filter($codeFiles, static fn (array $file): bool => in_array($file['language'], ['JavaScript', 'TypeScript', 'Vue', 'Svelte'], true)), 'content'));
        $pythonContents = implode("\n", array_column(array_filter($codeFiles, static fn (array $file): bool => $file['language'] === 'Python'), 'content'));
        $runtimeFiles = array_values(array_filter($codeFiles, static fn (array $file): bool => preg_match('#(?:^|/)(?:Analysis|Analyzers?|Scanners?|Detectors?)/|(?:^|/)(?:RepoScanner|Analyzer|Detector)\.[^.]+$#i', (string) $file['path']) !== 1));
        $runtimeContents = implode("\n", array_column($runtimeFiles, 'content'));
        $stack = [];
        $nodes = [];
        $edges = [];

        $hasPattern = static fn (string $pattern): bool => preg_match($pattern, $contents) === 1;
        $hasFrontendPattern = static fn (string $pattern): bool => preg_match($pattern, $frontendContents) === 1;
        $hasPythonPattern = static fn (string $pattern): bool => preg_match($pattern, $pythonContents) === 1;
        $hasRuntimePattern = static fn (string $pattern): bool => preg_match($pattern, $runtimeContents) === 1;
        $hasPath = static fn (string $needle): bool => count(array_filter($paths, static fn (string $path): bool => str_contains(strtolower($path), strtolower($needle)))) > 0;
        $hasLanguage = static fn (string $language): bool => count(array_filter($codeFiles, static fn (array $file): bool => $file['language'] === $language)) > 0;
        $hasPackageDependency = static function (string $dependency) use ($codeFiles): bool {
            foreach ($codeFiles as $file) {
                if (basename($file['path']) !== 'package.json') continue;
                $manifest = json_decode($file['content'], true);
                if (!is_array($manifest)) continue;
                foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $group) {
                    if (isset($manifest[$group]) && is_array($manifest[$group]) && array_key_exists($dependency, $manifest[$group])) return true;
                }
            }
            return false;
        };
        $hasComposerDependency = static function (string $dependency) use ($codeFiles): bool {
            foreach ($codeFiles as $file) {
                if (basename($file['path']) !== 'composer.json') continue;
                $manifest = json_decode($file['content'], true);
                if (!is_array($manifest)) continue;
                foreach (['require', 'require-dev'] as $group) if (isset($manifest[$group]) && is_array($manifest[$group]) && array_key_exists($dependency, $manifest[$group])) return true;
            }
            return false;
        };
        $hasAuthPath = static fn (): bool => count(array_filter($paths, static fn (string $path): bool => preg_match('#(^|/)(?:auth|authentication)(?:[._/-]|$)#i', $path) === 1)) > 0;
        $authEvidence = array_slice(array_column(array_filter($codeFiles, static fn (array $file): bool => $file['role'] === 'authentication'), 'path'), 0, 12);

        if ($hasPath('next.config') || $hasPackageDependency('next') || $hasFrontendPattern('/(?:from\s+[\'\"]next(?:\/|[\'\"])|require\(\s*[\'\"]next(?:\/|[\'\"]))/i')) { $stack[] = 'Next.js'; $nodes[] = $this->node('frontend', 'frontend', 'Next.js frontend', 'This is the part of the project that renders screens in the browser.', $this->matchingPaths($paths, ['app/', 'pages/', 'next.config'])); }
        elseif ($hasPath('nuxt.config') || $hasPackageDependency('nuxt')) { $stack[] = 'Nuxt'; $nodes[] = $this->node('frontend', 'frontend', 'Nuxt frontend', 'This part renders Vue screens and may also expose Nuxt server routes.', $this->matchingPaths($paths, ['pages/', 'components/', 'server/', 'nuxt.config'])); }
        elseif ($hasPackageDependency('@sveltejs/kit') || $hasLanguage('Svelte')) { $stack[] = $hasPackageDependency('@sveltejs/kit') ? 'SvelteKit' : 'Svelte'; $nodes[] = $this->node('frontend', 'frontend', 'Svelte frontend', 'This part renders screens with Svelte components.', $this->matchingPaths($paths, ['src/routes/', 'src/lib/', '.svelte'])); }
        elseif ($hasPackageDependency('react') || $hasFrontendPattern('/(?:from\s+[\'\"]react(?:\/|[\'\"])|require\(\s*[\'\"]react(?:\/|[\'\"]))/i')) { $stack[] = 'React'; $nodes[] = $this->node('frontend', 'frontend', 'Frontend application', 'This is the part of the project that renders the user interface.', $this->matchingPaths($paths, ['components', 'src/'])); }
        elseif ($hasPackageDependency('vue') || $hasLanguage('Vue')) { $stack[] = 'Vue'; $nodes[] = $this->node('frontend', 'frontend', 'Vue frontend', 'This part of the project renders screens with Vue components.', $this->matchingPaths($paths, ['src/', 'components/', 'views/'])); }
        if ($hasPythonPattern('/(?:from\s+fastapi\s+import|import\s+fastapi\b|[\'\"]fastapi[\'\"]\s*:)/i')) { $stack[] = 'FastAPI'; $nodes[] = $this->node('api', 'api', 'FastAPI API', 'This server receives requests and runs backend rules.', $this->matchingPaths($paths, ['api/', 'routers/', 'main.py'])); }
        elseif ($hasPath('manage.py') || $hasPythonPattern('/(?:from\s+django(?:\.|\s+import)|import\s+django\b)/i')) { $stack[] = 'Django'; $nodes[] = $this->node('api', 'server', 'Django application', 'This Python project uses Django to handle requests, routes, and server-side application behavior.', $this->matchingPaths($paths, ['manage.py', 'urls.py', 'views.py', 'settings.py'])); }
        elseif ($hasPath('src/flask/') || $hasPythonPattern('/(?:from\s+flask\s+import|import\s+flask\b)/i')) { $stack[] = 'Flask'; $nodes[] = $this->node('api', 'api', 'Flask application', 'This Python project uses Flask to receive requests and run backend behavior.', $this->matchingPaths($paths, ['app.py', 'routes', 'views', 'src/flask/'])); }
        elseif ($hasPackageDependency('@nestjs/core') || $hasFrontendPattern('/from\s+[\'\"]@nestjs\/(?:common|core)[\'\"]/i')) { $stack[] = 'NestJS'; $nodes[] = $this->node('api', 'api', 'NestJS API', 'This TypeScript server organizes request handlers into controllers and services.', $this->matchingPaths($paths, ['controller', 'module', 'service'])); }
        elseif ($hasPackageDependency('express') || $hasFrontendPattern('/(?:from\s+[\'\"]express[\'\"]|require\(\s*[\'\"]express[\'\"])/i')) { $stack[] = 'Express'; $stack[] = 'Node API'; $nodes[] = $this->node('api', 'api', 'Express API', 'This Express server receives requests and runs backend rules.', $this->matchingPaths($paths, ['api/', 'routes/', 'server'])); }
        elseif ($hasComposerDependency('laravel/framework') || $hasPath('artisan')) { $stack[] = 'PHP'; $stack[] = 'Laravel'; $nodes[] = $this->node('api', 'server', 'Laravel application', 'This PHP application uses Laravel for routes and server-side behavior.', $this->matchingPaths($paths, ['app/', 'routes/', 'artisan'])); }
        elseif ($hasLanguage('PHP')) { $stack[] = 'PHP'; $nodes[] = $this->node('api', 'server', 'PHP project', 'This PHP codebase contains server-side source and may handle pages, requests, or application rules.', $this->phpApplicationEvidence($primaryFiles)); }
        if ($hasRuntimePattern('/(?:@supabase\/|supabase\.auth|\bSUPABASE_(?:URL|ANON_KEY|SERVICE_ROLE_KEY)\b)/i')) { $stack[] = 'Supabase'; $nodes[] = $this->node('supabase', 'service', 'Supabase', 'Supabase is connected as an external service for data, authentication, or both.', $this->matchingPaths($paths, ['supabase', 'lib/'])); }
        if ($hasRuntimePattern('/(?:from\s+[\'\"]firebase(?:\/|[\'\"])|initializeApp\s*\(|firebase\.auth\s*\()/i')) { $stack[] = 'Firebase'; $nodes[] = $this->node('firebase', 'service', 'Firebase', 'Firebase is connected for application services such as authentication, data, or storage.', $this->matchingPaths($paths, ['firebase', 'auth', 'firestore'])); }
        if ($hasPackageDependency('next-auth') || $hasPackageDependency('@auth/core')) $stack[] = 'Auth.js';
        if ($hasAuthPath() || $authEvidence !== []) { $nodes[] = $this->node('auth', 'auth', 'Authentication', 'This part decides who is signed in and which requests are allowed.', $authEvidence); }
        if ($hasPackageDependency('@prisma/client') || $hasPath('prisma/schema.prisma')) $stack[] = 'Prisma';
        if ($hasPackageDependency('drizzle-orm') || $hasPath('packages/drizzle-orm/')) $stack[] = 'Drizzle';
        if ($hasPattern('/(?:\bpostgres(?:ql)?\b|\bprisma\b|\bmysql\b|\bmongoose\b|\bnew\s+PDO\b|@supabase\/)/i')) { $stack[] = 'Database'; $nodes[] = $this->node('database', 'database', 'Application data', 'This is where the application keeps durable information.', $this->matchingPaths($paths, ['schema', 'migrations', 'prisma', 'models', 'database'])); }
        if ($hasPath('vercel.json') || $hasPattern('/(?:@vercel\/|\bvercel\s*[:=])/i')) { $stack[] = 'Vercel'; $nodes[] = $this->node('deployment', 'deployment', 'Vercel deployment', 'This configuration tells Vercel how to build or serve the project.', $this->matchingPaths($paths, ['vercel'])); }
        if ($hasPath('dockerfile') || $hasPath('docker-compose')) { $stack[] = 'Docker'; $nodes[] = $this->node('docker', 'deployment', 'Docker environment', 'Docker files describe a repeatable way to run parts of this project.', $this->matchingPaths($paths, ['docker'])); }

        $keys = array_column($nodes, 'key');
        $inferredEvidence = ['This connection is inferred because both systems were detected. Inspect the linked files to confirm runtime behavior.'];
        if (in_array('frontend', $keys, true) && in_array('api', $keys, true)) $edges[] = ['from' => 'frontend', 'to' => 'api', 'label' => 'likely sends requests to', 'evidence' => $inferredEvidence];
        if (in_array('frontend', $keys, true) && in_array('supabase', $keys, true)) $edges[] = ['from' => 'frontend', 'to' => 'supabase', 'label' => 'likely uses', 'evidence' => $inferredEvidence];
        if (in_array('api', $keys, true) && in_array('database', $keys, true)) $edges[] = ['from' => 'api', 'to' => 'database', 'label' => 'likely reads and writes', 'evidence' => $inferredEvidence];
        if (in_array('auth', $keys, true) && in_array('frontend', $keys, true)) $edges[] = ['from' => 'frontend', 'to' => 'auth', 'label' => 'likely uses sign-in state from', 'evidence' => $inferredEvidence];
        if (in_array('auth', $keys, true) && in_array('supabase', $keys, true)) $edges[] = ['from' => 'auth', 'to' => 'supabase', 'label' => 'likely is handled by', 'evidence' => $inferredEvidence];

        $findings = $this->findings($primaryFiles, $contents);
        foreach (array_unique($this->discoveryLimits) as $limit) {
            $findings[] = ['severity' => 'info', 'type' => 'scan_limit', 'title' => 'This scan is intentionally partial', 'explanation' => $limit . ' The results still describe scanned files, but omitted files may affect the application.', 'path' => null, 'evidence' => []];
        }
        $overview = $this->overview($stack, $nodes, count($files));
        return ['stack' => array_values(array_unique($stack)), 'nodes' => $nodes, 'edges' => $edges, 'findings' => $findings, 'overview' => $overview];
    }

    private function node(string $key, string $type, string $label, string $explanation, array $evidence): array
    {
        return compact('key', 'type', 'label', 'explanation', 'evidence');
    }

    private function matchingPaths(array $paths, array $needles): array
    {
        return array_slice(array_values(array_filter($paths, static fn (string $path): bool => array_reduce($needles, static fn (bool $found, string $needle): bool => $found || str_contains(strtolower($path), strtolower($needle)), false))), 0, 12);
    }

    /** @param array<int, array<string, mixed>> $files @return array<int, string> */
    private function phpApplicationEvidence(array $files): array
    {
        $paths = array_column(array_filter($files, static fn (array $file): bool => $file['language'] === 'PHP'), 'path');
        usort($paths, static function (string $left, string $right): int {
            $rank = static function (string $path): int {
                if ($path === 'public/index.php') return 0;
                if (str_starts_with($path, 'src/')) return 1;
                if (str_starts_with($path, 'public/')) return 2;
                return 3;
            };
            return $rank($left) <=> $rank($right) ?: strcmp($left, $right);
        });
        return array_slice($paths, 0, 12);
    }

    private function findings(array $files, string $contents): array
    {
        $findings = [];
        foreach ($files as $file) {
            if ($file['lines'] > 500) {
                $findings[] = ['severity' => 'attention', 'type' => 'large_file', 'title' => basename($file['path']) . ' is large enough to be risky to edit', 'explanation' => 'This file has ' . $file['lines'] . ' lines. Large files often combine several responsibilities, so an AI change can have wider effects than it first appears.', 'path' => $file['path'], 'evidence' => ['lines' => $file['lines']]];
            }
            if ($file['role'] === 'authentication') {
                $findings[] = ['severity' => 'attention', 'type' => 'sensitive_system', 'title' => 'Authentication is present in ' . $file['path'], 'explanation' => 'Changes here can affect who can sign in, stay signed in, or reach protected pages. Read its dependents before changing it.', 'path' => $file['path'], 'evidence' => []];
            }
            if (preg_match_all('/\b(?:TODO|FIXME)\b/i', $file['content'], $todoMatches) >= 4) {
                $findings[] = ['severity' => 'info', 'type' => 'implementation_notes', 'title' => basename($file['path']) . ' contains several implementation notes', 'explanation' => 'The scanner found ' . count($todoMatches[0]) . ' TODO or FIXME markers. They may be intentional, but this file could have unfinished behavior worth understanding first.', 'path' => $file['path'], 'evidence' => ['markers' => count($todoMatches[0])]];
            }
            if (!preg_match('/(^|\/)(?:\.env(?:\.|$)|README|CHANGELOG)/i', $file['path']) && preg_match('/(?:AKIA[0-9A-Z]{16}|github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[A-Za-z0-9-]{20,})/', $file['content'])) {
                $findings[] = ['severity' => 'risk', 'type' => 'possible_secret', 'title' => 'A credential-like value may be committed in ' . $file['path'], 'explanation' => 'This is a pattern match, not proof of a live secret. Review the file locally and rotate or remove any real credential. WTFCode does not store the matching value.', 'path' => $file['path'], 'evidence' => ['pattern_match' => true]];
            }
            foreach ($file['imports'] as $import) {
                if (str_starts_with($import, '.') && $this->resolveImport($file['path'], $import, array_fill_keys(array_column($files, 'path'), true)) === null) {
                    $findings[] = ['severity' => 'attention', 'type' => 'unresolved_local_import', 'title' => 'A local import could not be resolved from ' . $file['path'], 'explanation' => 'The scan could not match ' . $import . ' to a readable project file. It may be a path alias, generated file, or a missing import, so confirm it before changing related code.', 'path' => $file['path'], 'evidence' => ['import' => $import]];
                }
            }
        }
        $hashes = [];
        foreach ($files as $file) $hashes[$file['hash']][] = $file['path'];
        foreach ($hashes as $paths) {
            if (count($paths) > 1) $findings[] = ['severity' => 'info', 'type' => 'duplicate_content', 'title' => 'Similar code appears in more than one file', 'explanation' => 'These files have identical contents. Check whether they are intentionally duplicated before editing just one of them.', 'path' => $paths[0], 'evidence' => ['paths' => $paths]];
        }
        if (preg_match_all('/(?:process\.env\.|getenv\([\'\"]|os\.environ\[)[A-Z][A-Z0-9_]+/', $contents, $matches) && count($matches[0]) > 12) {
            $findings[] = ['severity' => 'info', 'type' => 'environment_surface', 'title' => 'This project relies on many environment variables', 'explanation' => 'Configuration values are spread across the project. Changing deployment settings without checking each variable can break features that work locally.', 'path' => null, 'evidence' => ['references' => count($matches[0])]];
        }
        if (preg_match_all('/\b(?:createClient|new\s+PrismaClient|new\s+PDO)\b/', $contents, $clientMatches) > 3) {
            $findings[] = ['severity' => 'info', 'type' => 'repeated_client_setup', 'title' => 'Several database or service clients appear to be created', 'explanation' => 'This may be a valid pattern, but repeated client setup can make configuration and connection behavior harder to follow. Verify whether the project already has a shared client module.', 'path' => null, 'evidence' => ['matches' => count($clientMatches[0])]];
        }
        foreach ($files as $file) {
            if (basename($file['path']) !== 'package.json') continue;
            $manifest = json_decode($file['content'], true);
            if (!is_array($manifest)) continue;
            $dependencies = array_merge(array_keys(is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : []), array_keys(is_array($manifest['devDependencies'] ?? null) ? $manifest['devDependencies'] : []));
            foreach (array_slice($dependencies, 0, 12) as $dependency) {
                $repositoryOccurrences = substr_count($contents, $dependency);
                $manifestOccurrences = substr_count($file['content'], $dependency);
                if ($repositoryOccurrences <= $manifestOccurrences) {
                    $findings[] = ['severity' => 'info', 'type' => 'possible_unused_dependency', 'title' => $dependency . ' has no obvious readable-file reference', 'explanation' => 'This is intentionally conservative: the package may be used through a build plugin, alias, generated code, or a subpath import. Check the manifest and build configuration before removing it.', 'path' => $file['path'], 'evidence' => ['dependency' => $dependency]];
                }
            }
        }
        return $findings;
    }

    private function overview(array $stack, array $nodes, int $fileCount): string
    {
        $technology = $stack === [] ? 'a project with no confidently identified framework' : implode(', ', $stack);
        $systems = count($nodes) === 0 ? 'WTFCode found source files but not enough clear integration evidence yet.' : 'It found ' . count($nodes) . ' connected system' . (count($nodes) === 1 ? '' : 's') . ' to explain.';
        return 'This repository has ' . $fileCount . ' readable project files and appears to use ' . $technology . '. ' . $systems;
    }

    private function clearPreviousScan(int $projectId): void
    {
        $pdo = Database::connection();
        foreach (['architecture_edges', 'architecture_nodes', 'project_dependencies', 'project_files', 'scan_findings'] as $table) {
            $pdo->prepare('DELETE FROM ' . $table . ' WHERE project_id = :project_id')->execute(['project_id' => $projectId]);
        }
    }

    private function persistFiles(int $projectId, array $files): array
    {
        $statement = Database::connection()->prepare('INSERT INTO project_files (project_id, path, language, file_size, line_count, content_hash, role_name, plain_summary, symbols_json) VALUES (:project_id, :path, :language, :file_size, :line_count, :content_hash, :role_name, :plain_summary, :symbols_json)' . (Database::isPostgres() ? ' RETURNING id' : ''));
        $ids = [];
        foreach ($files as $file) {
            $statement->execute(['project_id' => $projectId, 'path' => $file['path'], 'language' => $file['language'], 'file_size' => $file['size'], 'line_count' => $file['lines'], 'content_hash' => $file['hash'], 'role_name' => $file['role'], 'plain_summary' => $file['summary'], 'symbols_json' => json_encode($file['symbols'], JSON_UNESCAPED_SLASHES)]);
            $ids[$file['path']] = Database::isPostgres() ? (int) $statement->fetchColumn() : (int) Database::connection()->lastInsertId();
        }
        return $ids;
    }

    private function persistDependencies(int $projectId, array $files, array $ids): void
    {
        $statement = Database::connection()->prepare(Database::isPostgres()
            ? 'INSERT INTO project_dependencies (project_id, source_file_id, target_file_id, target_path, relationship_type) VALUES (:project_id, :source_file_id, :target_file_id, :target_path, :relationship_type) ON CONFLICT (source_file_id, target_path, relationship_type) DO NOTHING'
            : 'INSERT IGNORE INTO project_dependencies (project_id, source_file_id, target_file_id, target_path, relationship_type) VALUES (:project_id, :source_file_id, :target_file_id, :target_path, :relationship_type)');
        foreach ($files as $file) {
            foreach ($file['imports'] as $import) {
                $target = $this->resolveImport($file['path'], $import, $ids);
                $statement->execute(['project_id' => $projectId, 'source_file_id' => $ids[$file['path']], 'target_file_id' => $target === null ? null : $ids[$target], 'target_path' => $target ?? $import, 'relationship_type' => $file['language'] === 'PHP' ? 'requires' : 'imports']);
            }
        }
    }

    private function resolveImport(string $sourcePath, string $import, array $ids): ?string
    {
        if (!str_starts_with($import, '.')) return null;
        $base = dirname($sourcePath) . '/' . $import;
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $base)) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') { array_pop($parts); continue; }
            $parts[] = $part;
        }
        $candidate = implode('/', $parts);
        foreach ([$candidate, $candidate . '.ts', $candidate . '.tsx', $candidate . '.js', $candidate . '.jsx', $candidate . '.php', $candidate . '.py', $candidate . '.json', $candidate . '.css', $candidate . '/index.ts', $candidate . '/index.tsx', $candidate . '/index.js', $candidate . '/index.php'] as $path) {
            if (isset($ids[$path])) return $path;
        }
        return null;
    }

    private function persistNodes(int $projectId, array $nodes): array
    {
        $statement = Database::connection()->prepare('INSERT INTO architecture_nodes (project_id, node_key, node_type, label, plain_explanation, evidence_json) VALUES (:project_id, :node_key, :node_type, :label, :plain_explanation, :evidence_json)' . (Database::isPostgres() ? ' RETURNING id' : ''));
        $ids = [];
        foreach ($nodes as $node) {
            $statement->execute(['project_id' => $projectId, 'node_key' => $node['key'], 'node_type' => $node['type'], 'label' => $node['label'], 'plain_explanation' => $node['explanation'], 'evidence_json' => json_encode($node['evidence'], JSON_UNESCAPED_SLASHES)]);
            $ids[$node['key']] = Database::isPostgres() ? (int) $statement->fetchColumn() : (int) Database::connection()->lastInsertId();
        }
        return $ids;
    }

    private function persistEdges(int $projectId, array $edges, array $nodeIds): void
    {
        $statement = Database::connection()->prepare(Database::isPostgres()
            ? 'INSERT INTO architecture_edges (project_id, from_node_id, to_node_id, relationship_label, evidence_json) VALUES (:project_id, :from_node_id, :to_node_id, :relationship_label, :evidence_json) ON CONFLICT (project_id, from_node_id, to_node_id, relationship_label) DO NOTHING'
            : 'INSERT IGNORE INTO architecture_edges (project_id, from_node_id, to_node_id, relationship_label, evidence_json) VALUES (:project_id, :from_node_id, :to_node_id, :relationship_label, :evidence_json)');
        foreach ($edges as $edge) {
            if (!isset($nodeIds[$edge['from']], $nodeIds[$edge['to']])) continue;
            $statement->execute(['project_id' => $projectId, 'from_node_id' => $nodeIds[$edge['from']], 'to_node_id' => $nodeIds[$edge['to']], 'relationship_label' => $edge['label'], 'evidence_json' => json_encode($edge['evidence'])]);
        }
    }

    private function currentCommit(string $root): ?string
    {
        if (!is_dir($root . DIRECTORY_SEPARATOR . '.git') && !is_file($root . DIRECTORY_SEPARATOR . '.git')) return null;
        try {
            $status = (new SafeProcessRunner())->run(new ProcessRunRequest(['git', '-C', $root, 'status', '--porcelain=v1', '--untracked-files=normal'], $root, 10, 262144, 4096));
            if (!$status->succeeded() || trim($status->stdout) !== '') return null;
            $result = (new SafeProcessRunner())->run(new ProcessRunRequest(['git', '-C', $root, 'rev-parse', 'HEAD'], $root, 10, 4096, 4096));
            $output = trim($result->stdout);
            return $result->succeeded() && preg_match('/^[a-f0-9]{40}$/i', $output) ? $output : null;
        } catch (Throwable) {
            return null;
        }
    }
}
