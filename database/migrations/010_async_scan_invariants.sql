-- Enforce durable queue invariants for legacy MySQL 8 installations.
-- Apply once after 009_change_guard_pairing.sql.

-- Queued jobs that already consumed their retry budget cannot be claimed.
UPDATE analysis_jobs
SET state = 'failed',
    error_message = CASE
        WHEN error_message IS NULL OR TRIM(error_message) = ''
            THEN 'Retry budget exhausted before queue invariant migration.'
        ELSE error_message
    END,
    current_stage = 'Retry budget exhausted',
    finished_at = COALESCE(finished_at, CURRENT_TIMESTAMP),
    lease_token = NULL,
    leased_until = NULL,
    heartbeat_at = NULL,
    worker_id = NULL
WHERE state = 'queued'
  AND attempt_count >= max_attempts;

-- Historical installations may predate the one-active-job contract. Keep the
-- newest active row and fail older duplicates before creating the unique key.
UPDATE analysis_jobs AS job
INNER JOIN (
    SELECT id
    FROM (
        SELECT id,
               ROW_NUMBER() OVER (PARTITION BY project_id ORDER BY id DESC) AS active_rank
        FROM analysis_jobs
        WHERE state IN ('queued', 'running')
    ) AS ranked_active_jobs
    WHERE active_rank > 1
) AS duplicate_active_jobs ON duplicate_active_jobs.id = job.id
SET job.state = 'failed',
    job.error_message = 'Superseded by a newer active analysis during queue invariant migration.',
    job.current_stage = 'Superseded',
    job.finished_at = COALESCE(job.finished_at, CURRENT_TIMESTAMP),
    job.lease_token = NULL,
    job.leased_until = NULL,
    job.heartbeat_at = NULL,
    job.worker_id = NULL;

ALTER TABLE analysis_jobs
    ADD CONSTRAINT analysis_jobs_queued_attempt_budget_check
    CHECK (state <> 'queued' OR attempt_count < max_attempts);

-- A functional index creates its generated storage internally and remains
-- compatible with this table's cascading foreign keys on MySQL 8.0.13+.
CREATE UNIQUE INDEX analysis_jobs_one_active_per_project_unique
    ON analysis_jobs ((CASE WHEN state IN ('queued', 'running') THEN project_id ELSE NULL END));

CREATE INDEX analysis_jobs_project_state_id_index
    ON analysis_jobs (project_id, state, id);
