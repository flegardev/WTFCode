<?php

declare(strict_types=1);

final class PromptSafetyService
{
    /** @return array{prompt: string, evidence: array<int, array<string, mixed>>, notes: array<int, string>} */
    public static function build(int $projectId, string $request): array
    {
        $request = trim($request);
        $nodes = Project::architecture($projectId)['nodes'];
        $trace = self::traceForRequest($projectId, $request);
        $notes = [];
        $evidencePaths = [];
        foreach ($nodes as $node) {
            if ($node['node_key'] === 'auth') $notes[] = 'Preserve the existing authentication and authorization behavior; do not weaken protected routes.';
            if ($node['node_key'] === 'database' || $node['node_key'] === 'supabase') $notes[] = 'Treat data access and schema behavior as a compatibility boundary; do not delete or rename fields without an explicit migration plan.';
            if ($node['node_key'] === 'deployment' || $node['node_key'] === 'docker') $notes[] = 'Do not alter deployment or runtime configuration unless the requested feature requires it.';
        }
        foreach ($trace['files'] as $file) $evidencePaths[] = $file['path'];
        if ($trace['entry_points'] !== []) $notes[] = 'Preserve the traced route contracts, request methods, middleware, and authorization checks unless a route change is explicitly requested.';
        if ($trace['tables'] !== []) $notes[] = 'The requested area reaches persisted data. Keep schema and query compatibility, and require an explicit migration for structural changes.';
        if ($trace['services'] !== [] || $trace['environment'] !== []) $notes[] = 'Do not expose secrets or replace environment-backed service configuration with hard-coded values.';
        foreach ($nodes as $node) {
            $paths = json_decode((string) ($node['evidence_json'] ?? ''), true);
            if (!is_array($paths)) continue;
            foreach ($paths as $path) {
                if (is_string($path)) $evidencePaths[] = $path;
            }
        }
        $architectureFiles = Project::filesForPaths($projectId, $evidencePaths);
        $fallbackFiles = Project::files($projectId, '', 24);
        $evidence = self::selectEvidence($evidencePaths, array_merge($architectureFiles, $fallbackFiles));
        $files = array_column($evidence, 'path');
        $scope = $files === [] ? 'No individual files were confidently mapped yet.' : implode(', ', $files);
        $symbolScope = array_slice(array_values(array_unique(array_map(static fn (array $symbol): string => $symbol['type'] . ' ' . $symbol['name'], $trace['symbols']))), 0, 12);
        $routeScope = array_slice(array_values(array_unique(array_map(static fn (array $route): string => $route['label'], $trace['entry_points']))), 0, 12);
        $notes[] = 'Reuse existing patterns in the files listed below. If the request needs another file, explain why before changing it.';
        $notes[] = 'Keep the change small, maintain the current stack, and add or update the project’s existing tests where applicable.';
        $notes[] = 'Afterward, report files changed, behavior changed, any migration/configuration impact, and tests run.';
        $prompt = "You are modifying an existing project, not starting over.\n\nRequested outcome:\n" . ($request === '' ? '[Describe the feature or fix here.]' : $request)
            . "\n\nEvidence-backed starting files:\n" . $scope
            . "\n\nRelevant symbols:\n" . ($symbolScope === [] ? 'No normalized symbol matched confidently.' : implode(', ', $symbolScope))
            . "\n\nRelevant routes:\n" . ($routeScope === [] ? 'No route matched confidently.' : implode(', ', $routeScope))
            . "\n\nConstraints:\n- " . implode("\n- ", array_values(array_unique($notes)))
            . "\n\nFirst, state your plan and the exact files and symbols you expect to edit. Treat low-confidence or unresolved edges as questions to verify. Do not invent files, APIs, tables, or behavior that are not supported by repository evidence.";
        return ['prompt' => $prompt, 'evidence' => $evidence, 'notes' => array_values(array_unique($notes)), 'trace' => $trace];
    }

    private static function traceForRequest(int $projectId, string $request): array
    {
        $empty = FeatureTracer::trace($projectId, '');
        if ($request === '') return $empty;
        $stop = ['this', 'that', 'with', 'from', 'where', 'when', 'make', 'change', 'update', 'create', 'remove', 'feature', 'screen', 'page', 'existing', 'project'];
        $words = array_values(array_unique(array_filter(preg_split('/[^a-z0-9_.-]+/i', strtolower($request)) ?: [], static fn (string $word): bool => strlen($word) >= 4 && !in_array($word, $stop, true))));
        $best = $empty;
        $bestScore = 0;
        foreach (array_slice($words, 0, 6) as $word) {
            $trace = FeatureTracer::trace($projectId, $word);
            $score = count($trace['entry_points']) * 4 + count($trace['symbols']) * 2 + count($trace['hops']) + count($trace['files']);
            if ($score > $bestScore) { $best = $trace; $bestScore = $score; }
        }
        return $best;
    }

    /**
     * Prefer files that are direct architecture evidence and materially useful
     * for a safe change prompt. This keeps source-control metadata from taking
     * over merely because it sorts before application code.
     *
     * @param array<int, string> $evidencePaths
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    public static function selectEvidence(array $evidencePaths, array $files, int $limit = 8): array
    {
        $evidenceLookup = array_fill_keys(array_values(array_filter($evidencePaths, 'is_string')), true);
        $unique = [];
        foreach ($files as $file) {
            $path = $file['path'] ?? null;
            if (!is_string($path) || isset($unique[$path])) continue;
            $file['_architecture_evidence'] = isset($evidenceLookup[$path]);
            $unique[$path] = $file;
        }
        $selected = array_values($unique);
        usort($selected, static function (array $left, array $right): int {
            $score = static function (array $file): int {
                $role = (string) ($file['role_name'] ?? 'source');
                $roleScore = match ($role) {
                    'authentication' => 0,
                    'data model' => 1,
                    'api endpoint', 'route' => 2,
                    'middleware' => 3,
                    'source' => 4,
                    'configuration' => 5,
                    default => 6,
                };
                $path = strtolower((string) ($file['path'] ?? ''));
                $lowSignal = str_starts_with($path, '.github/') || preg_match('#(?:^|/)(?:readme|changelog|license)(?:\.|$)#', $path) === 1;
                return ($file['_architecture_evidence'] ? 0 : 20) + $roleScore + ($lowSignal ? 20 : 0);
            };
            return $score($left) <=> $score($right) ?: strcmp((string) $left['path'], (string) $right['path']);
        });
        foreach ($selected as &$file) unset($file['_architecture_evidence']);
        unset($file);
        return array_slice($selected, 0, max(1, min($limit, 12)));
    }
}
