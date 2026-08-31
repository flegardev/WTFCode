<?php

declare(strict_types=1);

final class ExplanationService
{
    public static function explainFile(array $file, array $imports, array $dependents, array $transitive = []): array
    {
        $directCount = count($dependents);
        $transitiveCount = count($transitive);
        $human = (string) $file['plain_summary'];
        $human .= $directCount > 0
            ? ' ' . $directCount . ' other file' . ($directCount === 1 ? '' : 's') . ' directly depend on it.'
            : ' The scanner did not find another project file that directly imports it.';
        if ($transitiveCount > 0) $human .= ' ' . $transitiveCount . ' additional file' . ($transitiveCount === 1 ? '' : 's') . ' are reachable through that static dependency chain.';
        $technical = 'WTFCode classified this as ' . $file['role_name'] . '. It contains ' . $file['line_count'] . ' lines, has ' . count($imports) . ' detected outgoing dependency ' . (count($imports) === 1 ? 'entry' : 'entries') . ', and uses static import/include evidence only.';
        $advice = $directCount + $transitiveCount > 4
            ? 'Before changing it, inspect the confirmed direct and transitive dependents below, then make one small, testable change at a time.'
            : 'Before changing it, check the connected files below and keep the change scoped to one behavior.';
        return ['human' => $human, 'technical' => $technical, 'advice' => $advice];
    }

    /** @return array<int, array<string, mixed>> */
    public static function traceFeature(int $projectId, string $query): array
    {
        $trace = FeatureTracer::trace($projectId, $query);
        $steps = [];
        foreach ($trace['files'] as $file) {
            $steps[(int) $file['id']] = ['path' => $file['path'], 'role' => 'symbol evidence', 'summary' => $file['reason'], 'id' => $file['id'], 'certainty' => ucfirst($file['confidence']) . ' confidence'];
        }
        return array_values($steps);
    }

    public static function traceFeatureV2(int $projectId, string $query): array
    {
        return FeatureTracer::trace($projectId, $query);
    }

    /** @return array<int, string> */
    public static function inferredSystems(array $files): array
    {
        $labels = [
            'authentication' => 'Authentication', 'middleware' => 'Request protection', 'api endpoint' => 'API behavior',
            'data model' => 'Application data', 'route' => 'User-facing routes', 'ui component' => 'Interface components',
            'configuration' => 'Runtime configuration',
        ];
        $systems = [];
        foreach ($files as $file) {
            if (isset($labels[$file['role_name']])) $systems[] = $labels[$file['role_name']];
        }
        return array_values(array_unique($systems));
    }

    public static function answerQuestion(int $projectId, string $question): array
    {
        return ExplanationManager::answer($projectId, $question);
    }

    public static function deterministicAnswer(int $projectId, string $question): array
    {
        $question = trim($question);
        $lower = strtolower($question);
        if ($question === '') return ['answer' => 'Ask about a file, authentication, Docker, routes, data, or how parts of this project connect.', 'evidence' => []];
        if (preg_match('/\b(?:delete|remove)\b/i', $question)) {
            $deletion = self::deletionAssessment($projectId, $question);
            if ($deletion !== null) return $deletion;
        }
        if (str_contains($lower, 'docker')) {
            $files = array_values(array_filter(Project::files($projectId, 'docker', 20), static fn (array $file): bool => str_contains(strtolower($file['path']), 'docker')));
            return $files === []
                ? ['answer' => 'This scan did not find a Dockerfile or Docker Compose file, so Docker does not appear to be part of the readable repository.', 'evidence' => []]
                : ['answer' => 'Docker configuration was detected in the files below. Open them to see which services and commands are explicitly defined.', 'evidence' => $files];
        }
        $terms = array_values(array_filter(preg_split('/[^a-z0-9_.-]+/i', $lower) ?: [], static fn (string $word): bool => strlen($word) >= 4));
        $stopWords = ['what', 'where', 'when', 'which', 'does', 'happen', 'happens', 'work', 'works', 'this', 'that', 'with', 'from', 'into', 'about', 'show', 'tell'];
        $meaningfulTerms = array_values(array_filter($terms, static fn (string $term): bool => !in_array($term, $stopWords, true)));
        if ($meaningfulTerms !== []) {
            $featurePhrase = implode(' ', array_slice($meaningfulTerms, 0, 6));
            $trace = FeatureTracer::trace($projectId, $featurePhrase);
            if ($trace['symbols'] !== [] || $trace['entry_points'] !== []) {
                $evidence = Project::filesForPaths($projectId, array_column($trace['files'], 'path'));
                $details = [];
                if ($trace['entry_points'] !== []) $details[] = count($trace['entry_points']) . ' matching route' . (count($trace['entry_points']) === 1 ? '' : 's');
                if ($trace['symbols'] !== []) $details[] = count($trace['symbols']) . ' connected symbol' . (count($trace['symbols']) === 1 ? '' : 's');
                if ($trace['tables'] !== []) $details[] = count($trace['tables']) . ' data structure' . (count($trace['tables']) === 1 ? '' : 's');
                if ($trace['services'] !== []) $details[] = count($trace['services']) . ' external service' . (count($trace['services']) === 1 ? '' : 's');
                return ['answer' => 'The strongest normalized evidence for "' . $featurePhrase . '" includes ' . implode(', ', $details) . '. Confidence is ' . $trace['confidence'] . '; open the symbol and file evidence before treating dynamic behavior as proven.', 'evidence' => $evidence, 'trace' => $trace];
            }
        }
        foreach (array_slice($terms, 0, 8) as $term) {
            $trace = FeatureTracer::trace($projectId, $term);
            if ($trace['symbols'] === [] && $trace['entry_points'] === []) continue;
            $evidence = Project::filesForPaths($projectId, array_column($trace['files'], 'path'));
            $details = [];
            if ($trace['entry_points'] !== []) $details[] = count($trace['entry_points']) . ' matching route' . (count($trace['entry_points']) === 1 ? '' : 's');
            if ($trace['symbols'] !== []) $details[] = count($trace['symbols']) . ' connected symbol' . (count($trace['symbols']) === 1 ? '' : 's');
            if ($trace['tables'] !== []) $details[] = count($trace['tables']) . ' data structure' . (count($trace['tables']) === 1 ? '' : 's');
            if ($trace['services'] !== []) $details[] = count($trace['services']) . ' external service' . (count($trace['services']) === 1 ? '' : 's');
            return ['answer' => 'The strongest normalized evidence for "' . $term . '" includes ' . implode(', ', $details) . '. Confidence is ' . $trace['confidence'] . '; open the evidence files and trace hops before treating dynamic behavior as proven.', 'evidence' => $evidence, 'trace' => $trace];
        }
        $role = null;
        if (preg_match('/(login|sign.?in|auth|session|register)/', $lower)) $role = 'authentication';
        elseif (preg_match('/(api|endpoint|request|backend)/', $lower)) $role = 'api endpoint';
        elseif (preg_match('/(database|table|model|schema)/', $lower)) $role = 'data model';
        elseif (preg_match('/(route|page|screen|ui|component)/', $lower)) $role = 'route';
        if ($role !== null) {
            $files = Project::filesByRole($projectId, $role);
            if ($files !== []) return ['answer' => 'These are the strongest static-evidence matches for the ' . $role . ' part of this project. The scan cannot prove runtime behavior, so use the linked files to verify the flow.', 'evidence' => $files];
        }
        foreach ($terms as $word) {
            $files = Project::files($projectId, $word, 12);
            if ($files !== []) return ['answer' => 'I found repository evidence related to "' . $word . '". This answer is based on scanned file metadata and static relationships, not an external AI guess.', 'evidence' => $files];
        }
        return ['answer' => 'The scanner does not have enough direct evidence to answer that confidently. Try a file name, feature name, or a question about authentication, APIs, Docker, routes, or data.', 'evidence' => []];
    }

    /** @return array{answer:string,evidence:array<int,array<string,mixed>>}|null */
    private static function deletionAssessment(int $projectId, string $question): ?array
    {
        if (!preg_match_all('/[A-Za-z0-9_@().-]+(?:[\/\\\\][A-Za-z0-9_@(). -]+)*\.[A-Za-z0-9]{1,12}/', $question, $matches)) return null;
        foreach ($matches[0] as $candidate) {
            $candidate = str_replace('\\', '/', trim($candidate, " \t\n\r\0\x0B.,;:!?\"'"));
            $basename = basename($candidate);
            $files = Project::files($projectId, $basename, 30);
            $exact = array_values(array_filter($files, static fn (array $file): bool => strcasecmp(str_replace('\\', '/', (string) ($file['path'] ?? '')), $candidate) === 0));
            $candidates = $exact !== [] ? $exact : array_values(array_filter($files, static fn (array $file): bool => strcasecmp(basename(str_replace('\\', '/', (string) ($file['path'] ?? ''))), $basename) === 0));
            foreach ($candidates as $file) {
                $path = str_replace('\\', '/', (string) ($file['path'] ?? ''));
                $direct = Project::dependents((int) $file['id']);
                $transitive = Project::transitiveDependents($projectId, (int) $file['id']);
                $critical = in_array((string) ($file['role_name'] ?? ''), ['authentication','api endpoint','data model','configuration','route'], true)
                    || preg_match('#(^|/)(?:\.env|config|middleware|migrations?)(?:[/.]|$)|(?:package|composer)\.json$#i', $path) === 1;
                $evidencePaths = array_merge([$path], array_column($direct, 'path'), array_column($transitive, 'path'));
                $evidence = Project::filesForPaths($projectId, array_slice(array_values(array_unique($evidencePaths)), 0, 16));
                if ($direct !== []) {
                    return ['answer' => sprintf('Confirmed use: %d file%s directly depend%s on %s, with %d additional transitive dependent%s detected. Do not delete it before replacing those uses and running the closest tests. Static analysis can still miss dynamic references.', count($direct), count($direct) === 1 ? '' : 's', count($direct) === 1 ? 's' : '', $path, count($transitive), count($transitive) === 1 ? '' : 's'), 'evidence' => $evidence];
                }
                if ($critical) {
                    return ['answer' => 'Possible use: no direct importer was detected for ' . $path . ', but its role or location can be loaded by framework convention or configuration. WTFCode cannot prove it is safe to delete; inspect runtime configuration, Git history, and relevant tests first.', 'evidence' => $evidence];
                }
                return ['answer' => 'No detected use: the static graph found no direct dependent for ' . $path . '. That is not proof that it is safe to delete; dynamic imports, generated code, framework conventions, and runtime configuration may be invisible. Check Git history and run the nearest build and tests before removal.', 'evidence' => $evidence];
            }
        }
        return null;
    }
}
