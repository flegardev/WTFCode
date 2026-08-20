<?php

declare(strict_types=1);

final class ChangeGuardService
{
    /** @return array<string, mixed> */
    public static function capture(int $projectId, int $userId, string $phase, string $label = '', string $intended = ''): array
    {
        if (!in_array($phase, ['before', 'after'], true)) throw new InvalidArgumentException('Change Guard phase must be before or after.');
        $snapshot = SensitiveDataSanitizer::scrub(self::snapshot($projectId));
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $id = Database::insert('INSERT INTO change_guard_snapshots (project_id, user_id, phase, label, intended_change, commit_sha, scan_run_id, snapshot_json, fingerprint) VALUES (:project_id, :user_id, :phase, :label, :intended, :commit_sha, :scan_run_id, :snapshot_json, :fingerprint)', [
            'project_id' => $projectId, 'user_id' => $userId, 'phase' => $phase,
            'label' => substr(trim($label), 0, 180), 'intended' => substr(trim($intended), 0, 500),
            'commit_sha' => $snapshot['commit_sha'], 'scan_run_id' => $snapshot['scan_run_id'],
            'snapshot_json' => $json, 'fingerprint' => hash('sha256', $json),
        ]);
        return ['id' => $id, 'phase' => $phase, 'snapshot' => $snapshot];
    }

    /** @return array<string, mixed>|null */
    public static function latestComparison(int $projectId, int $userId): ?array
    {
        $statement = Database::connection()->prepare("SELECT * FROM change_guard_snapshots WHERE project_id = :project_id AND user_id = :user_id ORDER BY id DESC LIMIT 20");
        $statement->execute(['project_id' => $projectId, 'user_id' => $userId]);
        $after = null; $before = null;
        foreach ($statement->fetchAll() as $row) {
            if ($after === null && $row['phase'] === 'after') { $after = $row; continue; }
            if ($after !== null && $row['phase'] === 'before' && (int) $row['id'] < (int) $after['id']) { $before = $row; break; }
        }
        if ($before === null || $after === null) return null;
        $beforeData = json_decode((string) $before['snapshot_json'], true) ?: [];
        $afterData = json_decode((string) $after['snapshot_json'], true) ?: [];
        $deltas = [];
        foreach (['routes', 'schema', 'dependencies', 'critical_symbols', 'security', 'architecture'] as $kind) {
            $old = array_fill_keys(array_map('strval', $beforeData[$kind] ?? []), true);
            $new = array_fill_keys(array_map('strval', $afterData[$kind] ?? []), true);
            $deltas[$kind] = ['added' => array_keys(array_diff_key($new, $old)), 'removed' => array_keys(array_diff_key($old, $new))];
        }
        $changedKinds = array_keys(array_filter($deltas, static fn (array $delta): bool => $delta['added'] !== [] || $delta['removed'] !== []));
        return [
            'before' => $before, 'after' => $after, 'deltas' => $deltas, 'changed_kinds' => $changedKinds,
            'what_changed' => $changedKinds === [] ? 'No evidence-set change was detected between the two captured scans.' : 'Changed evidence areas: ' . implode(', ', $changedKinds) . '.',
            'may_break' => array_values(array_intersect($changedKinds, ['routes', 'schema', 'dependencies', 'critical_symbols', 'security'])),
            'tests' => self::testRecommendations($changedKinds),
            'limitations' => 'Snapshots compare normalized scan evidence. Runtime behavior, generated code, and unscanned dynamic paths remain unknown.',
        ];
    }

    /** @return array<string, mixed> */
    private static function snapshot(int $projectId): array
    {
        $pdo = Database::connection();
        $scan = SymbolRepository::latestScan($projectId);
        $routes = array_map(static fn (array $route): string => $route['http_method'] . ' ' . $route['route_path'], SymbolRepository::routes($projectId));
        $symbols = $pdo->prepare("SELECT CONCAT(symbol_type, ':', qualified_name, ':', COALESCE(signature_text,'')) FROM code_symbols WHERE project_id = :project_id AND symbol_type IN ('controller','model','class','component','function','method','table','schema','external_service') ORDER BY symbol_type, qualified_name LIMIT 2000");
        $symbols->execute(['project_id' => $projectId]);
        $schema = $pdo->prepare("SELECT CONCAT(symbol_type, ':', qualified_name) FROM code_symbols WHERE project_id = :project_id AND symbol_type IN ('table','schema','column','view','procedure','trigger') ORDER BY qualified_name LIMIT 1200");
        $schema->execute(['project_id' => $projectId]);
        $packages = $pdo->prepare("SELECT CONCAT(ecosystem, ':', package_name, '@', package_version) FROM package_inventory WHERE project_id = :project_id AND scan_run_id = :scan_run_id ORDER BY ecosystem, package_name LIMIT 3000");
        $packages->execute(['project_id' => $projectId, 'scan_run_id' => (int) ($scan['id'] ?? 0)]);
        $security = $pdo->prepare('SELECT finding_type, severity, file_path, evidence_json FROM scan_findings WHERE project_id = :project_id ORDER BY finding_type, file_path LIMIT 2000');
        $security->execute(['project_id' => $projectId]);
        $securityFacts = [];
        foreach ($security->fetchAll() as $finding) {
            $evidence = json_decode((string) ($finding['evidence_json'] ?? '{}'), true);
            $securityFacts[] = implode(':', [(string) $finding['finding_type'], (string) $finding['severity'], (string) ($finding['file_path'] ?? ''), (string) ($evidence['rule_id'] ?? '')]);
        }
        $graph = SymbolRepository::graph($projectId, 300);
        $architecture = [];
        foreach ($graph['nodes'] as $node) $architecture[] = $node['architecture'] . '>' . $node['subsystem'];
        return [
            'scan_run_id' => isset($scan['id']) ? (int) $scan['id'] : null, 'commit_sha' => $scan['commit_sha'] ?? null,
            'routes' => array_values(array_unique($routes)), 'schema' => array_values(array_unique($schema->fetchAll(PDO::FETCH_COLUMN))),
            'dependencies' => array_values(array_unique($packages->fetchAll(PDO::FETCH_COLUMN))), 'critical_symbols' => array_values(array_unique($symbols->fetchAll(PDO::FETCH_COLUMN))),
            'security' => array_values(array_unique($securityFacts)), 'architecture' => array_values(array_unique($architecture)),
        ];
    }

    /** @return array<int, string> */
    private static function testRecommendations(array $changedKinds): array
    {
        $tests = ['Run the repository test suite for the changed files.'];
        if (in_array('routes', $changedKinds, true)) $tests[] = 'Exercise affected API and browser request flows, including authorization failures.';
        if (in_array('schema', $changedKinds, true)) $tests[] = 'Apply migrations to a disposable database and verify rollback/data compatibility.';
        if (in_array('dependencies', $changedKinds, true)) $tests[] = 'Run lockfile integrity, build, and dependency vulnerability checks.';
        if (in_array('security', $changedKinds, true)) $tests[] = 'Rerun the Security profile and manually review changed trust boundaries.';
        return $tests;
    }
}
