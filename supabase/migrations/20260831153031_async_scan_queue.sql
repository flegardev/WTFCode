-- Durable, backend-only scan queue. Long repository work happens outside the
-- claim transaction; workers retain ownership with an opaque lease token.

ALTER TABLE public.analysis_jobs
    ADD COLUMN IF NOT EXISTS requested_by_user_id BIGINT,
    ADD COLUMN IF NOT EXISTS job_kind VARCHAR(20) NOT NULL DEFAULT 'rescan',
    ADD COLUMN IF NOT EXISTS attempt_count INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS max_attempts INTEGER NOT NULL DEFAULT 3,
    ADD COLUMN IF NOT EXISTS available_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS lease_token CHAR(64),
    ADD COLUMN IF NOT EXISTS leased_until TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS heartbeat_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS worker_id VARCHAR(100),
    ADD COLUMN IF NOT EXISTS current_stage VARCHAR(120) NOT NULL DEFAULT 'Queued',
    ADD COLUMN IF NOT EXISTS progress_current INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS progress_total INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS scan_run_id BIGINT,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP;

UPDATE public.analysis_jobs AS job
SET requested_by_user_id = project.user_id
FROM public.projects AS project
WHERE project.id = job.project_id
  AND job.requested_by_user_id IS NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'analysis_jobs_requested_by_user_fk'
          AND conrelid = 'public.analysis_jobs'::regclass
    ) THEN
        ALTER TABLE public.analysis_jobs
            ADD CONSTRAINT analysis_jobs_requested_by_user_fk
            FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'analysis_jobs_scan_run_fk'
          AND conrelid = 'public.analysis_jobs'::regclass
    ) THEN
        ALTER TABLE public.analysis_jobs
            ADD CONSTRAINT analysis_jobs_scan_run_fk
            FOREIGN KEY (scan_run_id) REFERENCES public.scan_runs(id) ON DELETE SET NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'analysis_jobs_job_kind_check'
          AND conrelid = 'public.analysis_jobs'::regclass
    ) THEN
        ALTER TABLE public.analysis_jobs
            ADD CONSTRAINT analysis_jobs_job_kind_check
            CHECK (job_kind IN ('initial', 'rescan'));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'analysis_jobs_attempts_check'
          AND conrelid = 'public.analysis_jobs'::regclass
    ) THEN
        ALTER TABLE public.analysis_jobs
            ADD CONSTRAINT analysis_jobs_attempts_check
            CHECK (attempt_count >= 0 AND max_attempts BETWEEN 1 AND 10);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'analysis_jobs_progress_check'
          AND conrelid = 'public.analysis_jobs'::regclass
    ) THEN
        ALTER TABLE public.analysis_jobs
            ADD CONSTRAINT analysis_jobs_progress_check
            CHECK (
                progress_current >= 0
                AND progress_total >= 0
                AND (progress_total = 0 OR progress_current <= progress_total)
            );
    END IF;
END
$$;

-- A historical deployment may have more than one active telemetry row. Keep
-- the newest row active before adding the invariant used by all new enqueues.
WITH ranked_active_jobs AS (
    SELECT id,
           ROW_NUMBER() OVER (PARTITION BY project_id ORDER BY id DESC) AS active_rank
    FROM public.analysis_jobs
    WHERE state IN ('queued', 'running')
)
UPDATE public.analysis_jobs AS job
SET state = 'failed',
    error_message = 'Superseded by a newer queued analysis.',
    current_stage = 'Superseded',
    finished_at = CURRENT_TIMESTAMP,
    lease_token = NULL,
    leased_until = NULL,
    heartbeat_at = NULL,
    worker_id = NULL,
    updated_at = CURRENT_TIMESTAMP
FROM ranked_active_jobs AS ranked
WHERE ranked.id = job.id
  AND ranked.active_rank > 1;

CREATE UNIQUE INDEX IF NOT EXISTS analysis_jobs_one_active_per_project_index
    ON public.analysis_jobs(project_id)
    WHERE state IN ('queued', 'running');
CREATE INDEX IF NOT EXISTS analysis_jobs_claim_index
    ON public.analysis_jobs(available_at, id)
    WHERE state = 'queued';
CREATE INDEX IF NOT EXISTS analysis_jobs_expired_lease_index
    ON public.analysis_jobs(leased_until, id)
    WHERE state = 'running';
CREATE INDEX IF NOT EXISTS analysis_jobs_requester_created_index
    ON public.analysis_jobs(requested_by_user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS analysis_jobs_scan_run_index
    ON public.analysis_jobs(scan_run_id)
    WHERE scan_run_id IS NOT NULL;

ALTER TABLE public.analysis_jobs ENABLE ROW LEVEL SECURITY;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.analysis_jobs TO wtfcode_app;
REVOKE ALL ON public.analysis_jobs FROM anon, authenticated, service_role;
