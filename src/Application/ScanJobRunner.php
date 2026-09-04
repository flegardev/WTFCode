<?php

declare(strict_types=1);

namespace WTFCode\Application;

use AnalysisJobStore;
use Database;
use GitHubAccessException;
use Logger;
use RepoScanner;
use RepositoryImporter;
use RuntimeException;
use Throwable;

final class ScanJobRunner
{
    /** @param array<string, mixed> $claimedJob @return array{job_id: int, state: string} */
    public function run(array $claimedJob): array
    {
        $jobId = $this->positiveInt($claimedJob['id'] ?? null);
        $projectId = $this->positiveInt($claimedJob['project_id'] ?? null);
        $leaseToken = (string) ($claimedJob['lease_token'] ?? '');
        if ($jobId === null || $projectId === null || preg_match('/^[a-f0-9]{64}$/', $leaseToken) !== 1) {
            throw new RuntimeException('The claimed scan job is invalid.');
        }

        $project = $this->claimedProject($jobId, $projectId, $leaseToken);
        if ($project === null) {
            AnalysisJobStore::finish($jobId, 'failed', 'The scan job no longer belongs to its requesting project.', $leaseToken);

            return ['job_id' => $jobId, 'state' => 'failed'];
        }

        $jobKind = ($claimedJob['job_kind'] ?? '') === 'initial' ? 'initial' : 'rescan';
        $path = null;

        try {
            if (!AnalysisJobStore::heartbeat($jobId, $leaseToken, 'Preparing repository', 1, 4)) {
                throw new RuntimeException('The scan worker no longer owns this analysis.');
            }

            $this->markPreparing($projectId, $jobKind);
            $path = RepositoryImporter::workingCopy($project);
            $this->markScanning($projectId, $path, $jobKind);

            (new RepoScanner())->scan(
                $projectId,
                $path,
                (string) ($claimedJob['analysis_profile'] ?? 'quick'),
                $jobId,
                $leaseToken,
            );

            $finished = AnalysisJobStore::find($jobId);

            return ['job_id' => $jobId, 'state' => (string) ($finished['state'] ?? 'completed')];
        } catch (Throwable $exception) {
            Logger::error('Background repository analysis failed', [
                'job_id' => $jobId,
                'project_id' => $projectId,
                'type' => get_class($exception),
            ]);
            $safeError = $this->safeUserError($exception);
            $retryState = AnalysisJobStore::retry($jobId, $leaseToken, $safeError);
            if ($retryState !== null) {
                $projectError = $retryState === 'queued'
                    ? $safeError . ' A retry is queued.'
                    : $safeError;
                $this->markAttemptFailure($projectId, $retryState, $projectError);
            }

            return ['job_id' => $jobId, 'state' => $retryState ?? 'lost'];
        } finally {
            if ($path !== null && RepositoryImporter::ephemeral()) {
                RepositoryImporter::cleanup($path);
                Database::connection()->prepare("UPDATE projects SET local_path = '' WHERE id = :id")->execute(['id' => $projectId]);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function claimedProject(int $jobId, int $projectId, string $leaseToken): ?array
    {
        $statement = Database::connection()->prepare(
            "SELECT project.*
             FROM projects project
             INNER JOIN analysis_jobs job
                ON job.project_id = project.id
               AND job.requested_by_user_id = project.user_id
             WHERE job.id = :job_id
               AND job.project_id = :project_id
               AND job.state = 'running'
               AND job.lease_token = :lease_token
             LIMIT 1",
        );
        $statement->execute(['job_id' => $jobId, 'project_id' => $projectId, 'lease_token' => $leaseToken]);
        $project = $statement->fetch();

        return is_array($project) ? $project : null;
    }

    private function markPreparing(int $projectId, string $jobKind): void
    {
        if ($jobKind !== 'initial') {
            return;
        }
        Database::connection()->prepare("UPDATE projects SET status = 'cloning', last_error = NULL WHERE id = :id")->execute(['id' => $projectId]);
    }

    private function markScanning(int $projectId, string $path, string $jobKind): void
    {
        $storedPath = RepositoryImporter::ephemeral() ? '' : $path;
        $statement = Database::connection()->prepare(
            "UPDATE projects
             SET local_path = :path,
                 status = CASE WHEN :initial_job = 1 OR last_scan_at IS NULL THEN 'scanning' ELSE status END,
                 last_error = NULL
             WHERE id = :id",
        );
        $statement->execute([
            'path' => $storedPath,
            'initial_job' => $jobKind === 'initial' ? 1 : 0,
            'id' => $projectId,
        ]);
    }

    private function markAttemptFailure(int $projectId, string $jobState, string $safeError): void
    {
        $statement = Database::connection()->prepare(
            "UPDATE projects
             SET status = CASE
                    WHEN last_scan_at IS NOT NULL THEN 'ready'
                    WHEN :terminal = 1 THEN 'failed'
                    ELSE 'queued'
                 END,
                 last_error = :error
             WHERE id = :id",
        );
        $statement->execute([
            'terminal' => $jobState === 'failed' ? 1 : 0,
            'error' => substr($safeError, 0, 500),
            'id' => $projectId,
        ]);
    }

    private function safeUserError(Throwable $exception): string
    {
        if ($exception instanceof GitHubAccessException) {
            return $exception->safeMessage();
        }

        return 'The repository analysis did not finish.';
    }

    private function positiveInt(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed !== false && $parsed !== null && $parsed > 0 ? (int) $parsed : null;
    }
}
