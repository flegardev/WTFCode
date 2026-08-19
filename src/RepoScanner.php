<?php

declare(strict_types=1);

final class RepoScanner
{
    private const MAX_FILES = 3000;
    private const MAX_FILE_SIZE = 262144;
    private const MAX_TOTAL_SCANNED_BYTES = 20971520;
    private const IGNORED_DIRECTORIES = ['.git', '.idea', '.vscode', 'node_modules', 'vendor', '.next', 'dist', 'build', 'coverage', '.turbo', '.cache', 'storage', 'tmp', 'temp'];
    private const TEXT_EXTENSIONS = ['php', 'js', 'jsx', 'ts', 'tsx', 'py', 'rb', 'go', 'java', 'cs', 'rs', 'vue', 'svelte', 'json', 'yml', 'yaml', 'toml', 'sql', 'md', 'html', 'css', 'scss', 'sh', 'env'];
    private array $discoveryLimits = [];

    public function scan(int $projectId, string $root): array
    {
        if (!is_dir($root)) {
            throw new RuntimeException('The repository files are no longer available.');
        }

        $inspection = $this->inspect($root);
        $files = $inspection['files'];
        unset($inspection['files']);
        $analysis = $inspection;
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $this->clearPreviousScan($projectId);
            $fileIds = $this->persistFiles($projectId, $files);
            $this->persistDependencies($projectId, $files, $fileIds);
            $nodeIds = $this->persistNodes($projectId, $analysis['nodes']);
            $this->persistEdges($projectId, $analysis['edges'], $nodeIds);
            $this->persistFindings($projectId, $analysis['findings']);

            $statement = $pdo->prepare('UPDATE projects SET status = :status, stack_json = :stack_json, overview = :overview, last_scan_at = NOW(), last_error = NULL WHERE id = :id');
            $statement->execute([
                'status' => 'ready',
                'stack_json' => json_encode($analysis['stack'], JSON_UNESCAPED_SLASHES),
                'overview' => $analysis['overview'],
                'id' => $projectId,
            ]);

            $commit = $this->currentCommit($root);
            $pdo->prepare('INSERT INTO scan_runs (project_id, commit_sha, analysis_version, analysis_profile, files_scanned, findings_count, analyzer_stats_json, engine_status_json) VALUES (:project_id, :commit_sha, :analysis_version, :analysis_profile, :files_scanned, :findings_count, :analyzer_stats_json, :engine_status_json)')
                ->execute([
                    'project_id' => $projectId,
                    'commit_sha' => $commit,
                    'analysis_version' => AnalysisEngine::VERSION,
                    'analysis_profile' => AnalysisProfile::QUICK,
                    'files_scanned' => count($files),
                    'findings_count' => count($analysis['findings']),
                    'analyzer_stats_json' => json_encode($analysis['symbol_graph']['stats'], JSON_UNESCAPED_SLASHES),
                    'engine_status_json' => json_encode($analysis['symbol_graph']['engine_runs'] ?? [], JSON_UNESCAPED_SLASHES),
                ]);
            $scanRunId = (int) $pdo->lastInsertId();
            SymbolGraphStore::persist($projectId, $scanRunId, $fileIds, $analysis['symbol_graph']);
            AnalyzerRunStore::persist($projectId, $scanRunId, $analysis['symbol_graph']['engine_runs'] ?? []);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $analysis + ['files_scanned' => count($files)];
    }

    /**
     * Read-only inspection used by the scanner and lightweight unit checks.
     * Source contents only exist in this method's in-memory result.
     */
    public function inspect(string $root): array
    {
        if (!is_dir($root)) throw new RuntimeException('The repository files are no longer available.');
        $this->discoveryLimits = [];
        $files = $this->discoverFiles($root);
        $analysis = $this->analyse($files);
        $analysis['symbol_graph'] = (new AnalysisCoordinator())->analyze(new AnalysisRequest($root, $files));
        $analysis['findings'] = array_merge($analysis['findings'], $analysis['symbol_graph']['findings'] ?? []);
        return $analysis + ['files' => $files];
    }

    private function discoverFiles(string $root): array
    {
        $files = [];
        $totalBytes = 0;
        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $entry): bool {
            if ($entry->isLink()) return false;
            return !$entry->isDir() || !in_array($entry->getFilename(), self::IGNORED_DIRECTORIES, true);
        });
        $iterator = new RecursiveIteratorIterator($filtered);
        foreach ($iterator as $file) {
            if (count($files) >= self::MAX_FILES) {
                $this->discoveryLimits[] = 'The scanner stopped after the 3,000-file MVP limit.';
                break;
            }
            if ($file->isLink() || !$file->isFile() || $file->getSize() > self::MAX_FILE_SIZE || $this->isIgnored($file->getPathname(), $root)) {
                continue;
            }
            if ($totalBytes + $file->getSize() > self::MAX_TOTAL_SCANNED_BYTES) {
                $this->discoveryLimits[] = 'The scanner reached its 20 MB readable-file limit.';
                break;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            $specialTextFile = in_array(basename($relative), ['Dockerfile', 'Procfile', 'Makefile', '.env.example'], true);
            if (!in_array($extension, self::TEXT_EXTENSIONS, true) && !$specialTextFile) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false || str_contains($content, "\0")) {
                continue;
            }
            $files[] = [
                'path' => $relative,
                'language' => $this->languageFor($extension, basename($relative)),
                'size' => (int) $file->getSize(),
                'lines' => substr_count($content, "\n") + 1,
                'hash' => hash('sha256', $content),
                'content' => $content,
                'role' => $this->roleFor($relative, $content),
                'summary' => $this->summaryFor($relative, $content),
                'imports' => $this->extractImports($content, $extension),
                'symbols' => $this->extractSymbols($content, $extension),
            ];
            $totalBytes += $file->getSize();
        }
        return $files;
    }

    private function isIgnored(string $path, string $root): bool
    {
        $relativeParts = explode(DIRECTORY_SEPARATOR, substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
        return count(array_intersect($relativeParts, self::IGNORED_DIRECTORIES)) > 0;
    }

    private function languageFor(string $extension, string $basename): string
    {
        if ($basename === 'Dockerfile') return 'Docker';
        return match ($extension) {
            'ts', 'tsx' => 'TypeScript', 'js', 'jsx' => 'JavaScript', 'php' => 'PHP', 'py' => 'Python',
            'vue' => 'Vue', 'svelte' => 'Svelte', 'sql' => 'SQL', 'json' => 'JSON', 'yml', 'yaml' => 'YAML',
            'css', 'scss' => 'CSS', 'html' => 'HTML', 'md' => 'Markdown', 'go' => 'Go', 'rb' => 'Ruby',
            default => $extension === '' ? 'Configuration' : strtoupper($extension),
        };
    }

    private function roleFor(string $path, string $content): string
    {
        $lower = strtolower($path);
        $extension = pathinfo($lower, PATHINFO_EXTENSION);
        $isDocumentation = $extension === 'md';
        $isRuntimeCode = in_array($extension, ['php', 'js', 'jsx', 'ts', 'tsx', 'py', 'rb', 'go', 'java', 'cs'], true);
        if (preg_match('#(^|/)(app|pages)/.+(page|route)\.(tsx?|jsx?)$#', $lower) || str_contains($lower, 'routes/')) return 'route';
        if ((!$isDocumentation && preg_match('/@(app|router)\.(get|post|put|patch|delete)/i', $content)) || str_contains($lower, 'api/')) return 'api endpoint';
        if (str_contains($lower, 'middleware')) return 'middleware';
        if (str_contains($lower, 'auth') || ($isRuntimeCode && preg_match('/(?:password_(?:hash|verify)|session_start\s*\(|\bBearer\s+|supabase\.auth\.(?:signIn|signUp|getUser|onAuthStateChange)|nextauth\/|firebase\.auth\(\))/i', $content))) return 'authentication';
        if (str_contains($lower, 'model') || str_contains($lower, 'schema') || (!$isDocumentation && preg_match('/(CREATE TABLE|prisma|sequelize|mongoose)/i', $content))) return 'data model';
        if (in_array(basename($path), ['package.json', 'composer.json', 'requirements.txt', 'Dockerfile', 'vercel.json', 'docker-compose.yml'], true)) return 'configuration';
        if (preg_match('#(^|/)(components?|ui)/#', $lower)) return 'ui component';
        return 'source';
    }

    private function summaryFor(string $path, string $content): string
    {
        $role = $this->roleFor($path, $content);
        return match ($role) {
            'route' => 'This file defines a page or route that users can reach in the application.',
            'api endpoint' => 'This file exposes server-side work that another part of the application can call.',
            'middleware' => 'This file runs before selected requests and can protect, redirect, or reshape them.',
            'authentication' => 'This file participates in identifying users or keeping them signed in.',
            'data model' => 'This file describes, queries, or changes data that the application depends on.',
            'configuration' => 'This file tells tooling or hosting platforms how the project should run.',
            'ui component' => 'This is a reusable piece of the user interface that other screens can include.',
            default => 'This is a source file that contributes application behavior or presentation.',
        };
    }

    private function extractImports(string $content, string $extension): array
    {
        $matches = [];
        if (in_array($extension, ['js', 'jsx', 'ts', 'tsx', 'vue', 'svelte'], true)) {
            preg_match_all('/(?:from\s*[\'\"]|import\s*[\'\"]|require\(\s*[\'\"])([^\'\"]+)/', $content, $matches);
        } elseif ($extension === 'php') {
            preg_match_all('/(?:require|require_once|include|include_once)\s*[\(\s]*[\'\"]([^\'\"]+)/', $content, $matches);
        } elseif ($extension === 'py') {
            preg_match_all('/(?:from|import)\s+([A-Za-z0-9_\.\/]+)/', $content, $matches);
        }
        return array_values(array_unique($matches[1] ?? []));
    }

    private function extractSymbols(string $content, string $extension): array
    {
        if (!in_array($extension, ['php', 'js', 'jsx', 'ts', 'tsx', 'py', 'vue', 'svelte'], true)) return [];
        $pattern = match ($extension) {
            'php' => '/\b(?:final\s+|abstract\s+)?(?:class|interface|trait|function)\s+([A-Za-z_][A-Za-z0-9_]*)/i',
            'py' => '/\b(?:class|def)\s+([A-Za-z_][A-Za-z0-9_]*)/',
            default => '/\b(?:class|interface|function)\s+([A-Za-z_$][A-Za-z0-9_$]*)|\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:async\s*)?\(?[^\n]*?\)?\s*=>/',
        };
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        $symbols = [];
        foreach ($matches as $match) {
            $name = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
            if ($name !== '') $symbols[] = $name;
            if (count($symbols) >= 16) break;
        }
        return array_values(array_unique($symbols));
    }

    private function analyse(array $files): array
    {
        $primaryFiles = array_values(array_filter($files, static fn (array $file): bool => preg_match('#^(?:tests?|specs?|fixtures?)/#i', (string) $file['path']) !== 1));
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
        $hasAuthPath = static fn (): bool => count(array_filter($paths, static fn (string $path): bool => preg_match('#(^|/)(?:auth|authentication)(?:[._/-]|$)#i', $path) === 1)) > 0;
        $authEvidence = array_slice(array_column(array_filter($codeFiles, static fn (array $file): bool => $file['role'] === 'authentication'), 'path'), 0, 12);

        if ($hasPath('next.config') || $hasPackageDependency('next') || $hasFrontendPattern('/(?:from\s+[\'\"]next(?:\/|[\'\"])|require\(\s*[\'\"]next(?:\/|[\'\"]))/i')) { $stack[] = 'Next.js'; $nodes[] = $this->node('frontend', 'frontend', 'Next.js frontend', 'This is the part of the project that renders screens in the browser.', $this->matchingPaths($paths, ['app/', 'pages/', 'next.config'])); }
        elseif ($hasPackageDependency('react') || $hasFrontendPattern('/(?:from\s+[\'\"]react(?:\/|[\'\"])|require\(\s*[\'\"]react(?:\/|[\'\"]))/i')) { $stack[] = 'React'; $nodes[] = $this->node('frontend', 'frontend', 'Frontend application', 'This is the part of the project that renders the user interface.', $this->matchingPaths($paths, ['components', 'src/'])); }
        elseif ($hasPackageDependency('vue') || $hasLanguage('Vue')) { $stack[] = 'Vue'; $nodes[] = $this->node('frontend', 'frontend', 'Vue frontend', 'This part of the project renders screens with Vue components.', $this->matchingPaths($paths, ['src/', 'components/', 'views/'])); }
        if ($hasPythonPattern('/(?:from\s+fastapi\s+import|import\s+fastapi\b|[\'\"]fastapi[\'\"]\s*:)/i') || ($hasLanguage('Python') && $hasPythonPattern('/@(app|router)\.(get|post|put|patch|delete)\s*\(/i'))) { $stack[] = 'FastAPI'; $nodes[] = $this->node('api', 'api', 'FastAPI API', 'This server receives requests and runs backend rules.', $this->matchingPaths($paths, ['api/', 'routers/', 'main.py'])); }
        elseif ($hasPath('manage.py') || $hasPythonPattern('/(?:from\s+django(?:\.|\s+import)|import\s+django\b)/i')) { $stack[] = 'Django'; $nodes[] = $this->node('api', 'server', 'Django application', 'This Python project uses Django to handle requests, routes, and server-side application behavior.', $this->matchingPaths($paths, ['manage.py', 'urls.py', 'views.py', 'settings.py'])); }
        elseif ($hasPackageDependency('express') || $hasFrontendPattern('/(?:from\s+[\'\"]express[\'\"]|require\(\s*[\'\"]express[\'\"])/i')) { $stack[] = 'Node API'; $nodes[] = $this->node('api', 'api', 'Backend API', 'This server receives requests and runs backend rules.', $this->matchingPaths($paths, ['api/', 'routes/', 'server'])); }
        elseif ($hasLanguage('PHP')) { $stack[] = 'PHP'; $nodes[] = $this->node('api', 'server', 'PHP project', 'This PHP codebase contains server-side source and may handle pages, requests, or application rules.', $this->phpApplicationEvidence($primaryFiles)); }
        if ($hasRuntimePattern('/(?:@supabase\/|supabase\.auth|\bSUPABASE_(?:URL|ANON_KEY|SERVICE_ROLE_KEY)\b)/i')) { $stack[] = 'Supabase'; $nodes[] = $this->node('supabase', 'service', 'Supabase', 'Supabase is connected as an external service for data, authentication, or both.', $this->matchingPaths($paths, ['supabase', 'lib/'])); }
        if ($hasAuthPath() || $authEvidence !== []) { $nodes[] = $this->node('auth', 'auth', 'Authentication', 'This part decides who is signed in and which requests are allowed.', $authEvidence); }
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
        $statement = Database::connection()->prepare('INSERT INTO project_files (project_id, path, language, file_size, line_count, content_hash, role_name, plain_summary, symbols_json) VALUES (:project_id, :path, :language, :file_size, :line_count, :content_hash, :role_name, :plain_summary, :symbols_json)');
        $ids = [];
        foreach ($files as $file) {
            $statement->execute(['project_id' => $projectId, 'path' => $file['path'], 'language' => $file['language'], 'file_size' => $file['size'], 'line_count' => $file['lines'], 'content_hash' => $file['hash'], 'role_name' => $file['role'], 'plain_summary' => $file['summary'], 'symbols_json' => json_encode($file['symbols'], JSON_UNESCAPED_SLASHES)]);
            $ids[$file['path']] = (int) Database::connection()->lastInsertId();
        }
        return $ids;
    }

    private function persistDependencies(int $projectId, array $files, array $ids): void
    {
        $statement = Database::connection()->prepare('INSERT IGNORE INTO project_dependencies (project_id, source_file_id, target_file_id, target_path, relationship_type) VALUES (:project_id, :source_file_id, :target_file_id, :target_path, :relationship_type)');
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
        $statement = Database::connection()->prepare('INSERT INTO architecture_nodes (project_id, node_key, node_type, label, plain_explanation, evidence_json) VALUES (:project_id, :node_key, :node_type, :label, :plain_explanation, :evidence_json)');
        $ids = [];
        foreach ($nodes as $node) {
            $statement->execute(['project_id' => $projectId, 'node_key' => $node['key'], 'node_type' => $node['type'], 'label' => $node['label'], 'plain_explanation' => $node['explanation'], 'evidence_json' => json_encode($node['evidence'], JSON_UNESCAPED_SLASHES)]);
            $ids[$node['key']] = (int) Database::connection()->lastInsertId();
        }
        return $ids;
    }

    private function persistEdges(int $projectId, array $edges, array $nodeIds): void
    {
        $statement = Database::connection()->prepare('INSERT IGNORE INTO architecture_edges (project_id, from_node_id, to_node_id, relationship_label, evidence_json) VALUES (:project_id, :from_node_id, :to_node_id, :relationship_label, :evidence_json)');
        foreach ($edges as $edge) {
            if (!isset($nodeIds[$edge['from']], $nodeIds[$edge['to']])) continue;
            $statement->execute(['project_id' => $projectId, 'from_node_id' => $nodeIds[$edge['from']], 'to_node_id' => $nodeIds[$edge['to']], 'relationship_label' => $edge['label'], 'evidence_json' => json_encode($edge['evidence'])]);
        }
    }

    private function persistFindings(int $projectId, array $findings): void
    {
        $statement = Database::connection()->prepare('INSERT INTO scan_findings (project_id, severity, finding_type, title, plain_explanation, file_path, evidence_json) VALUES (:project_id, :severity, :finding_type, :title, :plain_explanation, :file_path, :evidence_json)');
        foreach ($findings as $finding) {
            $statement->execute(['project_id' => $projectId, 'severity' => $finding['severity'], 'finding_type' => $finding['type'], 'title' => $finding['title'], 'plain_explanation' => $finding['explanation'], 'file_path' => $finding['path'], 'evidence_json' => json_encode($finding['evidence'])]);
        }
    }

    private function currentCommit(string $root): ?string
    {
        $process = proc_open(['git', '-C', $root, 'rev-parse', 'HEAD'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) return null;
        $output = trim((string) stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0 && preg_match('/^[a-f0-9]{40}$/i', $output) ? $output : null;
    }
}
