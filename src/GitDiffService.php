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

    public static function compare(array $project, string $from, string $to): array
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
        $impact = SymbolRepository::impactForPaths((int) $project['id'], $paths);
        $summary = 'Git reports ' . count($changes) . ' changed file' . (count($changes) === 1 ? '' : 's') . ' across ' . count($groups) . ' area' . (count($groups) === 1 ? '' : 's') . '. ' . ($risky === [] ? 'No high-risk path patterns were identified by the conservative review rules.' : count($risky) . ' file' . (count($risky) === 1 ? '' : 's') . ' deserve extra review because they touch authentication, data, configuration, or removals.');
        return ['summary' => $summary, 'stat' => $stat, 'changes' => $changes, 'groups' => $groups, 'risky' => $risky, 'symbol_delta' => $symbolDelta, 'impact' => $impact];
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
        if (!is_dir($path . DIRECTORY_SEPARATOR . '.git')) return [];
        $process = proc_open(array_merge(['git', '-C', $path], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) return [];
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0 ? array_values(array_filter(preg_split('/\r?\n/', trim((string) $output)) ?: [], static fn (string $line): bool => $line !== '')) : [];
    }

    private static function runRaw(string $path, array $arguments): ?string
    {
        if (!is_dir($path . DIRECTORY_SEPARATOR . '.git')) return null;
        $process = proc_open(array_merge(['git', '-C', $path], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) return null;
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0 ? (string) $output : null;
    }
}
