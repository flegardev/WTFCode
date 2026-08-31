-- Durable scan queue for legacy MySQL 8 installations.
-- Apply once after 007_runtime_state.sql. MySQL has no partial unique indexes,
-- so enqueue serializes on the owning projects row before checking active jobs.

ALTER TABLE analysis_jobs
    ADD COLUMN requested_by_user_id BIGINT UNSIGNED NULL AFTER project_id,
    ADD COLUMN job_kind VARCHAR(20) NOT NULL DEFAULT 'rescan' AFTER analysis_profile,
    ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER state,
    ADD COLUMN max_attempts INT UNSIGNED NOT NULL DEFAULT 3 AFTER attempt_count,
    ADD COLUMN available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER max_attempts,
    ADD COLUMN lease_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER available_at,
    ADD COLUMN leased_until TIMESTAMP NULL AFTER lease_token,
    ADD COLUMN heartbeat_at TIMESTAMP NULL AFTER leased_until,
    ADD COLUMN worker_id VARCHAR(100) NULL AFTER heartbeat_at,
    ADD COLUMN current_stage VARCHAR(120) NOT NULL DEFAULT 'Queued' AFTER worker_id,
    ADD COLUMN progress_current INT UNSIGNED NOT NULL DEFAULT 0 AFTER current_stage,
    ADD COLUMN progress_total INT UNSIGNED NOT NULL DEFAULT 0 AFTER progress_current,
    ADD COLUMN scan_run_id BIGINT UNSIGNED NULL AFTER progress_total,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER finished_at,
    ADD CONSTRAINT analysis_jobs_requested_by_user_fk FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT analysis_jobs_scan_run_fk FOREIGN KEY (scan_run_id) REFERENCES scan_runs(id) ON DELETE SET NULL,
    ADD CONSTRAINT analysis_jobs_job_kind_check CHECK (job_kind IN ('initial', 'rescan')),
    ADD CONSTRAINT analysis_jobs_attempts_check CHECK (max_attempts BETWEEN 1 AND 10),
    ADD CONSTRAINT analysis_jobs_progress_check CHECK (progress_total = 0 OR progress_current <= progress_total),
    ADD KEY analysis_jobs_claim_index (state, available_at, id),
    ADD KEY analysis_jobs_expired_lease_index (state, leased_until, id),
    ADD KEY analysis_jobs_requester_created_index (requested_by_user_id, created_at),
    ADD KEY analysis_jobs_scan_run_index (scan_run_id);

UPDATE analysis_jobs AS job
INNER JOIN projects AS project ON project.id = job.project_id
SET job.requested_by_user_id = project.user_id
WHERE job.requested_by_user_id IS NULL;
