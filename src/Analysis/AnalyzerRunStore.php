<?php

declare(strict_types=1);

final class AnalyzerRunStore
{
    /** @param array<int, array<string, mixed>> $engineRuns */
    public static function persist(int $projectId, int $scanRunId, array $engineRuns): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO analysis_provider_runs (project_id, scan_run_id, engine_id, engine_version, status, duration_ms, symbols_count, relationships_count, routes_count, packages_count, findings_count, message) VALUES (:project_id, :scan_run_id, :engine_id, :engine_version, :status, :duration_ms, :symbols_count, :relationships_count, :routes_count, :packages_count, :findings_count, :message)'
        );
        foreach ($engineRuns as $run) {
            $status = in_array($run['status'] ?? '', ['success', 'partial', 'unavailable', 'failed'], true) ? $run['status'] : 'failed';
            $statement->execute([
                'project_id' => $projectId,
                'scan_run_id' => $scanRunId,
                'engine_id' => substr((string) ($run['engine'] ?? 'unknown'), 0, 80),
                'engine_version' => substr((string) ($run['engine_version'] ?? 'unknown'), 0, 100),
                'status' => $status,
                'duration_ms' => max(0, (int) ($run['duration_ms'] ?? 0)),
                'symbols_count' => max(0, (int) ($run['symbols'] ?? 0)),
                'relationships_count' => max(0, (int) ($run['relationships'] ?? 0)),
                'routes_count' => max(0, (int) ($run['routes'] ?? 0)),
                'packages_count' => max(0, (int) ($run['packages'] ?? 0)),
                'findings_count' => max(0, (int) ($run['findings'] ?? 0)),
                'message' => isset($run['message']) ? substr((string) $run['message'], 0, 500) : null,
            ]);
        }
    }
}
