<?php

declare(strict_types=1);

final class GitDiffService
{
    public static function commits(array $project): array
    {
        $output = self::run((string) $project['local_path'], ['log', '--format=%H%x09%s', '-n', '20']);
        $commits = [];
        foreach ($output as $line) {
            [$sha, $message] = array_pad(explode("\t", $line, 2), 2, '');
            if (preg_match('/^[a-f0-9]{40}$/i', $sha)) $commits[] = ['sha' => $sha, 'message' => $message];
        }
        return $commits;
    }

    public static function compare(array $project, string $from, string $to, string $intendedChange = ''): array
    {
        if (!self::isSafeRef($from) || !self::isSafeRef($to) || $from === $to) return ['error' => 'Choose two different commit references from this repository.'];
        $path = (string) $project['local_path'];
        $stat = self::run($path, ['diff', '--stat', $from, $to, '--']);
        $changes = self::run($path, ['diff', '--name-status', $from, $to, '--']);
        if ($changes === []) return ['error' => 'No file changes were found between those commits.'];
        $groups = [];
        $risky = [];
        foreach ($changes as $line) {
            $parts = explode("\t", $line);
            $status = $parts[0] ?? '?';
            $file = $parts[count($parts) - 1] ?? $line;
            $category = self::categoryFor($file);
            $risk = self::riskFor($file, $status);
            $entry = ['status' => $status, 'path' => $file, 'risk' => $risk];
            $groups[$category][] = $entry;
            if ($risk !== null) $risky[] = $entry;
        }
        $paths = [];
        foreach ($groups as $entries) foreach ($entries as $entry) $paths[] = $entry['path'];
        $symbolDelta = self::symbolDelta($path, $from, $to, $paths);
        $semanticDelta = self::semanticDelta($path, $from, $to, $paths);
        $impact = SymbolRepository::impactForPaths((int) $project['id'], $paths);
        $scopeDrift = self::scopeDrift($intendedChange, array_keys($groups), $semanticDelta);
        $summary = 'Git reports ' . count($changes) . ' changed file' . (count($changes) === 1 ? '' : 's') . ' across ' . count($groups) . ' area' . (count($groups) === 1 ? '' : 's') . '. ' . ($risky === [] ? 'No high-risk path patterns were identified by the conservative review rules.' : count($risky) . ' file' . (count($risky) === 1 ? '' : 's') . ' deserve extra review because they touch authentication, data, configuration, or removals.');
        return ['summary' => $summary, 'stat' => $stat, 'changes' => $changes, 'groups' => $groups, 'risky' => $risky, 'symbol_delta' => $symbolDelta, 'semantic_delta' => $semanticDelta, 'architecture_diff' => $semanticDelta['architecture'], 'scope_drift' => $scopeDrift, 'impact' => $impact];
    }

    /** @return array<string, array<string, array<int, array<string, mixed>>>> */
    private static function semanticDelta(string $repositoryPath, string $from, string $to, array $paths): array
    {
        $before = self::semanticFactsAtRef($repositoryPath, $from, $paths);
        $after = self::semanticFactsAtRef($repositoryPath, $to, $paths);
        $result = [];
        foreach (['routes', 'schema', 'dependencies', 'security', 'environment', 'architecture'] as $kind) {
            $beforeIndex = [];
            foreach ($before[$kind] as $fact) $beforeIndex[$fact['identity']] = $fact;
            $afterIndex = [];
            foreach ($after[$kind] as $fact) $afterIndex[$fact['identity']] = $fact;
            $result[$kind] = [
                'added' => array_values(array_slice(array_diff_key($afterIndex, $beforeIndex), 0, 150)),
                'removed' => array_values(array_slice(array_diff_key($beforeIndex, $afterIndex), 0, 150)),
            ];
        }
        return $result;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private static function semanticFactsAtRef(string $repositoryPath, string $ref, array $paths): array
    {
        $facts = ['routes' => [], 'schema' => [], 'dependencies' => [], 'security' => [], 'environment' => [], 'architecture' => []];
        $architecture = [];
        foreach (array_slice($paths, 0, 120) as $path) {
            $content = self::contentAtRef($repositoryPath, $ref, $path);
            if ($content === null) continue;
            $lines = preg_split('/\R/', $content) ?: [];
            foreach ($lines as $offset => $line) {
                $number = $offset + 1;
                foreach (self::routeFacts($path, $line, $number) as $fact) $facts['routes'][] = $fact;
                foreach (self::schemaFacts($path, $line, $number) as $fact) $facts['schema'][] = $fact;
                if (preg_match_all('/(?:process\.env\.|import\.meta\.env\.|getenv\s*\(\s*[\'\"]|env\s*\(\s*[\'\"]|os\.(?:getenv|environ\.get)\s*\(\s*[\'\"])([A-Z][A-Z0-9_]+)/', $line, $matches)) {
                    foreach ($matches[1] as $name) $facts['environment'][] = self::fact('environment', $name, $path, $number, ['name' => $name]);
                }
                foreach ([
                    'Authentication boundary' => '/\b(?:auth|authorize|session|jwt|password_verify|middleware)\b/i',
                    'Process execution' => '/\b(?:exec|shell_exec|system|proc_open|child_process|subprocess|os\.system)\b/i',
                    'Upload boundary' => '/\b(?:upload|multipart|\$_FILES|move_uploaded_file)\b/i',
                    'Secret-shaped value' => '/(?:github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|-----BEGIN [A-Z ]*PRIVATE KEY-----)/',
                ] as $boundary => $pattern) if (preg_match($pattern, $line)) {
                    $facts['security'][] = self::fact('security', $boundary . '|' . trim(preg_replace('/\s+/', ' ', $line) ?? $line), $path, $number, ['boundary' => $boundary, 'preview' => $boundary === 'Secret-shaped value' ? '<redacted>' : substr(trim($line), 0, 180)]);
                }
            }
            foreach (self::dependencyFacts($path, $content) as $fact) $facts['dependencies'][] = $fact;
            $category = self::categoryFor($path);
            if ($category !== 'Documentation' && $category !== 'Tests') $architecture[$category][] = $path;
        }
        foreach ($architecture as $area => $evidence) $facts['architecture'][] = self::fact('architecture', $area, $evidence[0], 1, ['area' => $area, 'evidence_paths' => array_slice(array_values(array_unique($evidence)), 0, 12)]);
        foreach ($facts as &$items) {
            $indexed = [];
            foreach ($items as $item) $indexed[$item['identity']] = $item;
            $items = array_values($indexed);
        }
        unset($items);
        return $facts;
    }

    /** @return array<int, array<string, mixed>> */
    private static function routeFacts(string $path, string $line, int $number): array
    {
        $facts = [];
        $patterns = [
            '/(?:Route::|\b(?:app|router)\.)(get|post|put|patch|delete|options|head)\s*\(\s*[\'\"]([^\'\"]+)/i',
            '/@(?:app|router)\.(get|post|put|patch|delete)\s*\(\s*[\'\"]([^\'\"]+)/i',
        ];
        foreach ($patterns as $pattern) if (preg_match($pattern, $line, $match)) {
            $method = strtoupper($match[1]); $route = $match[2];
            $facts[] = self::fact('route', $method . ' ' . $route, $path, $number, compact('method', 'route'));
        }
        if (preg_match('#(?:^|/)(?:app|pages)/api/(.+?)/(?:route|index)\.(?:js|ts|tsx|jsx)$#i', str_replace('\\', '/', $path), $match) && preg_match('/export\s+(?:async\s+)?function\s+(GET|POST|PUT|PATCH|DELETE)/', $line, $method)) {
            $route = '/api/' . preg_replace('/\[(?:\.\.\.)?([^]]+)\]/', '{$1}', $match[1]);
            $facts[] = self::fact('route', $method[1] . ' ' . $route, $path, $number, ['method' => $method[1], 'route' => $route]);
        }
        return $facts;
    }

    /** @return array<int, array<string, mixed>> */
    private static function schemaFacts(string $path, string $line, int $number): array
    {
        $facts = [];
        if (preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"\[]?([A-Za-z_][A-Za-z0-9_.]*)/i', $line, $match)) {
            $facts[] = self::fact('schema', strtoupper($match[1]) . ' table ' . strtolower($match[2]), $path, $number, ['operation' => strtoupper($match[1]), 'table' => $match[2]]);
        }
        if (preg_match('/\b(?:ADD|DROP|RENAME|ALTER)\s+(?:COLUMN\s+)?[`"\[]?([A-Za-z_][A-Za-z0-9_]*)/i', $line, $match)) {
            $facts[] = self::fact('schema', trim(strtolower(preg_replace('/\s+/', ' ', $line) ?? $line)), $path, $number, ['column' => $match[1], 'statement' => substr(trim($line), 0, 240)]);
        }
        return $facts;
    }

    /** @return array<int, array<string, mixed>> */
    private static function dependencyFacts(string $path, string $content): array
    {
        $basename = strtolower(basename($path));
        $facts = [];
        if (in_array($basename, ['package.json', 'composer.json'], true)) {
            $json = json_decode($content, true);
            foreach (['dependencies', 'devDependencies', 'require', 'require-dev'] as $group) foreach (is_array($json[$group] ?? null) ? $json[$group] : [] as $name => $version) {
                $facts[] = self::fact('dependency', strtolower((string) $name) . '@' . (string) $version, $path, 1, ['name' => (string) $name, 'version' => (string) $version, 'group' => $group]);
            }
        } elseif (preg_match('/^(?:requirements[^\/]*\.txt|go\.mod|cargo\.toml)$/', $basename)) {
            foreach (preg_split('/\R/', $content) ?: [] as $offset => $line) if (preg_match('/^\s*([A-Za-z0-9_.\/-]+)\s*(?:==|=|\s+v)([^\s;]+)/', $line, $match)) {
                $facts[] = self::fact('dependency', strtolower($match[1]) . '@' . $match[2], $path, $offset + 1, ['name' => $match[1], 'version' => $match[2]]);
            }
        }
        return $facts;
    }

    /** @return array<string, mixed> */
    private static function fact(string $type, string $identity, string $path, int $line, array $extra): array
    {
        return ['identity' => hash('sha256', strtolower($type . '|' . $identity)), 'type' => $type, 'path' => $path, 'line' => $line] + $extra;
    }

    /** @return array<string, mixed> */
    private static function scopeDrift(string $intendedChange, array $actualGroups, array $semanticDelta): array
    {
        $intendedChange = trim(substr($intendedChange, 0, 500));
        if ($intendedChange === '') return ['assessed' => false, 'intended' => '', 'expected_areas' => [], 'also_changed' => [], 'label' => null];
        $expected = [];
        foreach ([
            'Authentication and access' => '/auth|login|oauth|session|password|permission/i', 'Data and schema' => '/database|schema|table|column|migration|data/i',
            'Configuration and dependencies' => '/dependency|package|config|deploy|docker|environment/i', 'API and server behavior' => '/api|route|endpoint|webhook|server/i',
            'User interface' => '/ui|frontend|component|page|button|form|style/i', 'Tests' => '/test|spec|coverage/i', 'Documentation' => '/readme|docs|documentation/i',
        ] as $area => $pattern) if (preg_match($pattern, $intendedChange)) $expected[] = $area;
        if ($expected === []) $expected[] = 'Application code';
        $actual = array_values(array_unique($actualGroups));
        $genericAreas = ['Tests'];
        if ($expected !== ['Application code']) $genericAreas[] = 'Application code';
        $also = array_values(array_diff($actual, $expected, $genericAreas));
        return ['assessed' => true, 'intended' => $intendedChange, 'expected_areas' => $expected, 'actual_areas' => $actual, 'also_changed' => $also, 'label' => $also === [] ? 'No potential scope drift detected' : 'Potential scope drift', 'note' => 'This compares declared intent with static change areas; it does not prove an intent violation.'];
    }

    private static function symbolDelta(string $repositoryPath, string $from, string $to, array $paths): array
    {
        $before = [];
        $after = [];
        foreach (array_slice($paths, 0, 80) as $path) {
            $language = self::languageFor($path);
            if ($language === null) continue;
            $beforeContent = self::contentAtRef($repositoryPath, $from, $path);
            $afterContent = self::contentAtRef($repositoryPath, $to, $path);
            if ($beforeContent !== null) $before = array_merge($before, self::declarations($path, $language, $beforeContent));
            if ($afterContent !== null) $after = array_merge($after, self::declarations($path, $language, $afterContent));
        }
        $beforeByKey = [];
        foreach ($before as $symbol) $beforeByKey[$symbol['type'] . '|' . $symbol['qualified_name']] = $symbol;
        $afterByKey = [];
        foreach ($after as $symbol) $afterByKey[$symbol['type'] . '|' . $symbol['qualified_name']] = $symbol;
        $added = array_values(array_diff_key($afterByKey, $beforeByKey));
        $removed = array_values(array_diff_key($beforeByKey, $afterByKey));
        $changed = [];
        foreach (array_intersect_key($afterByKey, $beforeByKey) as $key => $symbol) {
            if (($symbol['signature'] ?? '') === ($beforeByKey[$key]['signature'] ?? '')) continue;
            $changed[] = ['type' => $symbol['type'], 'name' => $symbol['name'], 'qualified_name' => $symbol['qualified_name'], 'path' => $symbol['path'], 'before_signature' => $beforeByKey[$key]['signature'], 'after_signature' => $symbol['signature']];
        }
        return ['added' => array_slice($added, 0, 100), 'removed' => array_slice($removed, 0, 100), 'changed' => array_slice($changed, 0, 100), 'files_analyzed' => count(array_unique(array_merge(array_column($before, 'path'), array_column($after, 'path')))), 'truncated' => count($paths) > 80];
    }

    private static function declarations(string $path, string $language, string $content): array
    {
        $graph = (new AnalysisEngine(null, []))->analyse([['path' => $path, 'language' => $language, 'content' => $content, 'lines' => substr_count($content, "\n") + 1]]);
        return array_values(array_filter($graph['symbols'], static fn (array $symbol): bool => in_array($symbol['type'], ['class', 'interface', 'trait', 'enum', 'controller', 'model', 'component', 'hook', 'function', 'method', 'schema', 'view', 'procedure', 'trigger'], true)));
    }

    private static function languageFor(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => 'PHP',
            'js', 'jsx' => 'JavaScript',
            'ts', 'tsx' => 'TypeScript',
            'vue' => 'Vue',
            'py' => 'Python',
            'sql' => 'SQL',
            default => null,
        };
    }

    private static function contentAtRef(string $path, string $ref, string $file): ?string
    {
        $output = self::runRaw($path, ['show', $ref . ':' . str_replace('\\', '/', $file)]);
        if ($output === null || strlen($output) > 262144 || str_contains($output, "\0")) return null;
        return $output;
    }

    private static function categoryFor(string $path): string
    {
        $path = strtolower($path);
        return match (true) {
            preg_match('/(?:auth|login|session|middleware|guard)/', $path) === 1 => 'Authentication and access',
            preg_match('/(?:migration|schema|prisma|database|\.sql$|model)/', $path) === 1 => 'Data and schema',
            preg_match('/(?:package\.json|composer\.json|requirements|\.env|docker|vercel|config|lock)/', $path) === 1 => 'Configuration and dependencies',
            preg_match('/(?:api|route|controller|endpoint)/', $path) === 1 => 'API and server behavior',
            preg_match('/(?:component|page|view|\.css$|\.scss$)/', $path) === 1 => 'User interface',
            preg_match('/(?:test|spec)/', $path) === 1 => 'Tests',
            preg_match('/(?:readme|\.md$|docs)/', $path) === 1 => 'Documentation',
            default => 'Application code',
        };
    }

    private static function riskFor(string $path, string $status): ?string
    {
        if (str_starts_with($status, 'D')) return 'Removed file';
        $path = strtolower($path);
        if (preg_match('/(?:auth|login|session|middleware|guard|migration|schema|prisma|database|\.sql$|\.env|docker|vercel|config)/', $path)) return 'Sensitive area';
        return null;
    }

    private static function isSafeRef(string $ref): bool
    {
        return !str_starts_with($ref, '-') && preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $ref) === 1;
    }

    /** @return array<int, string> */
    private static function run(string $path, array $arguments): array
    {
        $output = self::runRaw($path, $arguments);
        return $output === null ? [] : array_values(array_filter(preg_split('/\r?\n/', trim($output)) ?: [], static fn (string $line): bool => $line !== ''));
    }

    private static function runRaw(string $path, array $arguments): ?string
    {
        if (!is_dir($path . DIRECTORY_SEPARATOR . '.git')) return null;
        $result = (new SafeProcessRunner())->run(new ProcessRunRequest(array_merge(['git', '-C', $path], $arguments), $path, 20, 4_194_304, 262_144));
        return $result->succeeded() && !$result->stdoutTruncated ? $result->stdout : null;
    }
}
