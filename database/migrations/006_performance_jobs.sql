-- WTFCode V3 phase 10: analysis jobs, provider steps, and fusion disagreements.
-- Apply once after 005_change_intelligence.sql.

CREATE TABLE analysis_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    analysis_profile VARCHAR(20) NOT NULL,
    state ENUM('queued','running','completed','partial','failed') NOT NULL DEFAULT 'queued',
    commit_sha CHAR(64) NULL,
    previous_commit_sha CHAR(64) NULL,
    changed_paths_json JSON NULL,
    error_message VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    CONSTRAINT analysis_jobs_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    KEY analysis_jobs_project_created_index (project_id, created_at),
    KEY analysis_jobs_state_created_index (state, created_at)
) ENGINE=InnoDB;

CREATE TABLE analysis_job_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT UNSIGNED NOT NULL,
    provider_id VARCHAR(80) NOT NULL,
    provider_version VARCHAR(100) NOT NULL,
    state ENUM('queued','running','completed','partial','failed') NOT NULL,
    cache_hit TINYINT(1) NOT NULL DEFAULT 0,
    incremental TINYINT(1) NOT NULL DEFAULT 0,
    files_analyzed INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    message VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    CONSTRAINT analysis_job_steps_job_fk FOREIGN KEY (job_id) REFERENCES analysis_jobs (id) ON DELETE CASCADE,
    UNIQUE KEY analysis_job_steps_job_provider_unique (job_id, provider_id),
    KEY analysis_job_steps_state_index (state, created_at)
) ENGINE=InnoDB;

CREATE TABLE engine_disagreements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    scan_run_id BIGINT UNSIGNED NOT NULL,
    fact_identity CHAR(64) NOT NULL,
    fact_type VARCHAR(80) NOT NULL,
    provider_results_json JSON NOT NULL,
    resolution VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT engine_disagreements_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT engine_disagreements_scan_fk FOREIGN KEY (scan_run_id) REFERENCES scan_runs (id) ON DELETE CASCADE,
    UNIQUE KEY engine_disagreements_scan_fact_unique (scan_run_id, fact_identity),
    KEY engine_disagreements_project_type_index (project_id, fact_type)
) ENGINE=InnoDB;
