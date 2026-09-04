<?php

declare(strict_types=1);

final class ChangeGuardService
{
    /** @return array<string, mixed> */
    public static function capture(
        int $projectId,
        int $userId,
        string $phase,
        string $label = '',
        string $intended = '',
        ?int $beforeSnapshotId = null,
    ): array {
        if (!in_array($phase, ['before', 'after'], true)) {
            throw new InvalidArgumentException('Change Guard phase must be before or after.');
        }

        $label = substr(trim($label), 0, 180);
        $intended = substr(trim($intended), 0, 500);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            self::lockOwnedProject($pdo, $projectId, $userId);
            $snapshot = SensitiveDataSanitizer::scrub(self::snapshot($projectId));
            $scanRunId = filter_var($snapshot['scan_run_id'] ?? null, FILTER_VALIDATE_INT);
            if ($scanRunId === false || $scanRunId === null || $scanRunId < 1) {
                throw new InvalidArgumentException('Run a successful analysis before capturing a Change Guard snapshot.');
            }

            $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($phase === 'before') {
                if (self::pendingBeforeRow($pdo, $projectId, $userId) !== null) {
                    throw new InvalidArgumentException('Finish the pending Change Guard comparison before capturing another before snapshot.');
                }

                $pairKey = bin2hex(random_bytes(16));
                $beforeSnapshotId = null;
            } else {
                if ($beforeSnapshotId === null || $beforeSnapshotId < 1) {
                    throw new InvalidArgumentException('Choose the pending before snapshot for this comparison.');
                }

                $before = self::unpairedBefore($pdo, $projectId, $userId, $beforeSnapshotId);
                if ($before === null) {
                    throw new InvalidArgumentException('The selected before snapshot is unavailable or already paired.');
                }

                $beforeScanRunId = filter_var($before['scan_run_id'] ?? null, FILTER_VALIDATE_INT);
                if ($beforeScanRunId === false || $beforeScanRunId === null || $scanRunId <= $beforeScanRunId) {
                    throw new InvalidArgumentException('Run and complete a new analysis before capturing the after snapshot.');
                }

                $pairKey = (string) $before['pair_key'];
                if ($label === '') {
                    $label = (string) $before['label'];
                }
                if ($intended === '') {
                    $intended = (string) $before['intended_change'];
                }
            }

            $id = Database::insert(
                'INSERT INTO change_guard_snapshots (project_id, user_id, pair_key, before_snapshot_id, phase, label, intended_change, commit_sha, scan_run_id, snapshot_json, fingerprint) VALUES (:project_id, :user_id, :pair_key, :before_snapshot_id, :phase, :label, :intended, :commit_sha, :scan_run_id, :snapshot_json, :fingerprint)',
                [
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'pair_key' => $pairKey,
                    'before_snapshot_id' => $beforeSnapshotId,
                    'phase' => $phase,
                    'label' => $label,
                    'intended' => $intended,
                    'commit_sha' => $snapshot['commit_sha'],
                    'scan_run_id' => $scanRunId,
                    'snapshot_json' => $json,
                    'fingerprint' => hash('sha256', $json),
                ],
            );
            $pdo->commit();

            return [
                'id' => $id,
                'phase' => $phase,
                'pair_key' => $pairKey,
                'before_snapshot_id' => $beforeSnapshotId,
                'snapshot' => $snapshot,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public static function pendingBefore(int $projectId, int $userId): ?array
    {
        $pdo = Database::connection();
        $before = self::pendingBeforeRow($pdo, $projectId, $userId);
        if ($before === null) {
            return null;
        }

        $currentScan = SymbolRepository::latestScan($projectId);
        $before['current_scan_run_id'] = isset($currentScan['id']) ? (int) $currentScan['id'] : null;
        $before['can_capture_after'] = $before['current_scan_run_id'] !== null
            && $before['current_scan_run_id'] > (int) $before['scan_run_id'];

        return $before;
    }

    /** @return array<string, mixed>|null */
    public static function latestComparison(int $projectId, int $userId): ?array
    {
        $pdo = Database::connection();
        $afterStatement = $pdo->prepare(
            "SELECT after_snapshot.*
             FROM change_guard_snapshots after_snapshot
             INNER JOIN change_guard_snapshots before_snapshot
                ON before_snapshot.id = after_snapshot.before_snapshot_id
               AND before_snapshot.project_id = after_snapshot.project_id
               AND before_snapshot.user_id = after_snapshot.user_id
               AND before_snapshot.pair_key = after_snapshot.pair_key
               AND before_snapshot.phase = 'before'
             WHERE after_snapshot.project_id = :project_id
               AND after_snapshot.user_id = :user_id
               AND after_snapshot.phase = 'after'
             ORDER BY after_snapshot.id DESC
             LIMIT 1",
        );
        $afterStatement->execute(['project_id' => $projectId, 'user_id' => $userId]);
        $after = $afterStatement->fetch();
        if (!is_array($after)) {
            return null;
        }

        $beforeStatement = $pdo->prepare(
            "SELECT * FROM change_guard_snapshots
             WHERE id = :before_snapshot_id
               AND project_id = :project_id
               AND user_id = :user_id
               AND pair_key = :pair_key
               AND phase = 'before'
             LIMIT 1",
        );
        $beforeStatement->execute([
            'before_snapshot_id' => (int) $after['before_snapshot_id'],
            'project_id' => $projectId,
            'user_id' => $userId,
            'pair_key' => (string) $after['pair_key'],
        ]);
        $before = $beforeStatement->fetch();
        if (!is_array($before)) {
            return null;
        }

        $beforeData = json_decode((string) $before['snapshot_json'], true) ?: [];
        $afterData = json_decode((string) $after['snapshot_json'], true) ?: [];
        $deltas = [];
        foreach (['routes', 'schema', 'dependencies', 'critical_symbols', 'security', 'architecture'] as $kind) {
            $old = array_fill_keys(array_map('strval', $beforeData[$kind] ?? []), true);
            $new = array_fill_keys(array_map('strval', $afterData[$kind] ?? []), true);
            $deltas[$kind] = [
                'added' => array_keys(array_diff_key($new, $old)),
                'removed' => array_keys(array_diff_key($old, $new)),
            ];
        }
        $changedKinds = array_keys(array_filter(
            $deltas,
            static fn (array $delta): bool => $delta['added'] !== [] || $delta['removed'] !== [],
        ));

        return [
            'pair_key' => (string) $after['pair_key'],
            'before' => $before,
            'after' => $after,
            'deltas' => $deltas,
            'changed_kinds' => $changedKinds,
            'what_changed' => $changedKinds === []
                ? 'No evidence-set change was detected between the two captured scans.'
                : 'Changed evidence areas: ' . implode(', ', $changedKinds) . '.',
            'may_break' => array_values(array_intersect(
                $changedKinds,
                ['routes', 'schema', 'dependencies', 'critical_symbols', 'security'],
            )),
            'tests' => self::testRecommendations($changedKinds),
            'limitations' => 'Snapshots compare normalized scan evidence. Runtime behavior, generated code, and unscanned dynamic paths remain unknown.',
        ];
    }

    private static function lockOwnedProject(PDO $pdo, int $projectId, int $userId): void
    {
        $statement = $pdo->prepare('SELECT id FROM projects WHERE id = :project_id AND user_id = :user_id FOR UPDATE');
        $statement->execute(['project_id' => $projectId, 'user_id' => $userId]);
        if ($statement->fetchColumn() === false) {
            throw new InvalidArgumentException('Project not found.');
        }
    }

    /** @return array<string, mixed>|null */
    private static function pendingBeforeRow(PDO $pdo, int $projectId, int $userId): ?array
    {
        $statement = $pdo->prepare(
            "SELECT before_snapshot.*
             FROM change_guard_snapshots before_snapshot
             LEFT JOIN change_guard_snapshots after_snapshot
                ON after_snapshot.before_snapshot_id = before_snapshot.id
               AND after_snapshot.phase = 'after'
             WHERE before_snapshot.project_id = :project_id
               AND before_snapshot.user_id = :user_id
               AND before_snapshot.phase = 'before'
               AND after_snapshot.id IS NULL
             ORDER BY before_snapshot.id DESC
             LIMIT 1",
        );
        $statement->execute(['project_id' => $projectId, 'user_id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function unpairedBefore(PDO $pdo, int $projectId, int $userId, int $beforeSnapshotId): ?array
    {
        $statement = $pdo->prepare(
            "SELECT before_snapshot.*
             FROM change_guard_snapshots before_snapshot
             LEFT JOIN change_guard_snapshots after_snapshot
                ON after_snapshot.before_snapshot_id = before_snapshot.id
               AND after_snapshot.phase = 'after'
             WHERE before_snapshot.id = :before_snapshot_id
               AND before_snapshot.project_id = :project_id
               AND before_snapshot.user_id = :user_id
               AND before_snapshot.phase = 'before'
               AND after_snapshot.id IS NULL
             LIMIT 1",
        );
        $statement->execute([
            'before_snapshot_id' => $beforeSnapshotId,
            'project_id' => $projectId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private static function snapshot(int $projectId): array
    {
        $pdo = Database::connection();
        $scan = SymbolRepository::latestScan($projectId);
        $routes = array_map(
            static fn (array $route): string => $route['http_method'] . ' ' . $route['route_path'],
            SymbolRepository::routes($projectId),
        );
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
            $securityFacts[] = implode(':', [
                (string) $finding['finding_type'],
                (string) $finding['severity'],
                (string) ($finding['file_path'] ?? ''),
                (string) ($evidence['rule_id'] ?? ''),
            ]);
        }
        $graph = SymbolRepository::graph($projectId, 300);
        $architecture = [];
        foreach ($graph['nodes'] as $node) {
            $architecture[] = $node['architecture'] . '>' . $node['subsystem'];
        }

        return [
            'scan_run_id' => isset($scan['id']) ? (int) $scan['id'] : null,
            'commit_sha' => $scan['commit_sha'] ?? null,
            'routes' => array_values(array_unique($routes)),
            'schema' => array_values(array_unique($schema->fetchAll(PDO::FETCH_COLUMN))),
            'dependencies' => array_values(array_unique($packages->fetchAll(PDO::FETCH_COLUMN))),
            'critical_symbols' => array_values(array_unique($symbols->fetchAll(PDO::FETCH_COLUMN))),
            'security' => array_values(array_unique($securityFacts)),
            'architecture' => array_values(array_unique($architecture)),
        ];
    }

    /** @return array<int, string> */
    private static function testRecommendations(array $changedKinds): array
    {
        $tests = ['Run the repository test suite for the changed files.'];
        if (in_array('routes', $changedKinds, true)) {
            $tests[] = 'Exercise affected API and browser request flows, including authorization failures.';
        }
        if (in_array('schema', $changedKinds, true)) {
            $tests[] = 'Apply migrations to a disposable database and verify rollback/data compatibility.';
        }
        if (in_array('dependencies', $changedKinds, true)) {
            $tests[] = 'Run lockfile integrity, build, and dependency vulnerability checks.';
        }
        if (in_array('security', $changedKinds, true)) {
            $tests[] = 'Rerun the Security profile and manually review changed trust boundaries.';
        }

        return $tests;
    }
}
