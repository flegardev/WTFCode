-- WTFCode V3 phase 1: provider execution records and fused evidence provenance.
-- Apply once to a database that already includes migration 002.

ALTER TABLE scan_runs
    ADD COLUMN analysis_profile VARCHAR(20) NOT NULL DEFAULT 'quick' AFTER analysis_version,
    ADD COLUMN engine_status_json JSON NULL AFTER analyzer_stats_json;

ALTER TABLE code_symbols
    ADD COLUMN provenance_json JSON NULL AFTER metadata_json;

ALTER TABLE symbol_relationships
    ADD COLUMN provenance_json JSON NULL AFTER metadata_json;

ALTER TABLE code_routes
    ADD COLUMN provenance_json JSON NULL AFTER metadata_json;

CREATE TABLE analysis_provider_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    scan_run_id BIGINT UNSIGNED NOT NULL,
    engine_id VARCHAR(80) NOT NULL,
    engine_version VARCHAR(100) NOT NULL,
    status ENUM('success', 'partial', 'unavailable', 'failed') NOT NULL,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    symbols_count INT UNSIGNED NOT NULL DEFAULT 0,
    relationships_count INT UNSIGNED NOT NULL DEFAULT 0,
    routes_count INT UNSIGNED NOT NULL DEFAULT 0,
    findings_count INT UNSIGNED NOT NULL DEFAULT 0,
    message VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT analysis_provider_runs_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT analysis_provider_runs_scan_fk FOREIGN KEY (scan_run_id) REFERENCES scan_runs (id) ON DELETE CASCADE,
    UNIQUE KEY analysis_provider_runs_scan_engine_unique (scan_run_id, engine_id),
    KEY analysis_provider_runs_project_status_index (project_id, status, created_at)
) ENGINE=InnoDB;
