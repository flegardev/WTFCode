-- Exhausted queued jobs can never be claimed, so terminate any historical rows
-- before enforcing the retry-budget invariant.
UPDATE public.analysis_jobs
SET state = 'failed',
    error_message = CASE
        WHEN error_message IS NULL OR BTRIM(error_message) = ''
            THEN 'Retry budget exhausted before queue invariant migration.'
        ELSE error_message
    END,
    current_stage = 'Retry budget exhausted',
    finished_at = COALESCE(finished_at, CURRENT_TIMESTAMP),
    lease_token = NULL,
    leased_until = NULL,
    heartbeat_at = NULL,
    worker_id = NULL,
    updated_at = CURRENT_TIMESTAMP
WHERE state = 'queued'
  AND attempt_count >= max_attempts;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'analysis_jobs_queued_attempt_budget_check'
          AND conrelid = 'public.analysis_jobs'::regclass
    ) THEN
        ALTER TABLE public.analysis_jobs
            ADD CONSTRAINT analysis_jobs_queued_attempt_budget_check
            CHECK (state <> 'queued' OR attempt_count < max_attempts);
    END IF;
END
$$;
