<?php

declare(strict_types=1);

final class AnalysisJobStore
{
    public static function create(int $projectId, string $profile, ?string $commit, ?string $previousCommit, array $changedPaths): int
    {
        $statement = Database::connection()->prepare('INSERT INTO analysis_jobs (project_id, analysis_profile, state, commit_sha, previous_commit_sha, changed_paths_json) VALUES (:project_id, :profile, :state, :commit, :previous, :paths)');
        $statement->execute(['project_id' => $projectId, 'profile' => AnalysisProfile::normalize($profile), 'state' => 'queued', 'commit' => $commit, 'previous' => $previousCommit, 'paths' => json_encode(array_slice(array_values(array_unique($changedPaths)), 0, 1000), JSON_UNESCAPED_SLASHES)]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function running(int $jobId): void { self::state($jobId, 'running', null, 'started_at'); }
    public static function finish(int $jobId, string $state, ?string $error = null): void { self::state($jobId, in_array($state, ['completed', 'partial', 'failed'], true) ? $state : 'failed', $error, 'finished_at'); }

    /** @param array<int, array<string, mixed>> $runs */
    public static function steps(int $jobId, array $runs): void
    {
        $statement = Database::connection()->prepare('INSERT INTO analysis_job_steps (job_id, provider_id, provider_version, state, cache_hit, incremental, files_analyzed, duration_ms, message, finished_at) VALUES (:job_id, :provider, :version, :state, :cache_hit, :incremental, :files, :duration, :message, NOW())');
        foreach ($runs as $run) {
            $state = match ($run['status'] ?? '') { 'success' => 'completed', 'partial', 'unavailable' => 'partial', default => 'failed' };
            $statement->execute(['job_id' => $jobId, 'provider' => substr((string) ($run['engine'] ?? 'unknown'), 0, 80), 'version' => substr((string) ($run['engine_version'] ?? 'unknown'), 0, 100), 'state' => $state, 'cache_hit' => !empty($run['cache_hit']) ? 1 : 0, 'incremental' => !empty($run['incremental']) ? 1 : 0, 'files' => max(0, (int) ($run['files_analyzed'] ?? 0)), 'duration' => max(0, (int) ($run['duration_ms'] ?? 0)), 'message' => isset($run['message']) ? substr((string) $run['message'], 0, 500) : null]);
        }
    }

    public static function latest(int $projectId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM analysis_jobs WHERE project_id = :project_id ORDER BY id DESC LIMIT 1');
        $statement->execute(['project_id' => $projectId]);
        $job = $statement->fetch() ?: null;
        if ($job === null) return null;
        $steps = Database::connection()->prepare('SELECT * FROM analysis_job_steps WHERE job_id = :job_id ORDER BY id');
        $steps->execute(['job_id' => $job['id']]);
        $job['steps'] = $steps->fetchAll();
        return $job;
    }

    private static function state(int $jobId, string $state, ?string $error, string $timestamp): void
    {
        $statement = Database::connection()->prepare("UPDATE analysis_jobs SET state = :state, error_message = :error, $timestamp = NOW() WHERE id = :id");
        $statement->execute(['state' => $state, 'error' => $error === null ? null : substr(SensitiveDataSanitizer::text($error), 0, 500), 'id' => $jobId]);
    }
}
