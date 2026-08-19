-- WTFCode V3 phase 4: normalized security findings and package inventory.
-- Apply once after 003_multi_engine_foundation.sql.

ALTER TABLE scan_findings
    ADD COLUMN scan_run_id BIGINT UNSIGNED NULL AFTER project_id,
    ADD CONSTRAINT scan_findings_scan_run_fk FOREIGN KEY (scan_run_id) REFERENCES scan_runs (id) ON DELETE CASCADE,
    ADD KEY scan_findings_scan_type_index (scan_run_id, finding_type);

ALTER TABLE analysis_provider_runs
    ADD COLUMN packages_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER routes_count;

CREATE TABLE package_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    scan_run_id BIGINT UNSIGNED NOT NULL,
    package_name VARCHAR(255) NOT NULL,
    package_version VARCHAR(160) NOT NULL DEFAULT '',
    ecosystem VARCHAR(80) NOT NULL DEFAULT 'unknown',
    purl VARCHAR(700) NULL,
    classification ENUM('declared', 'resolved', 'detected') NOT NULL DEFAULT 'detected',
    licenses_json JSON NULL,
    locations_json JSON NULL,
    providers_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT package_inventory_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT package_inventory_scan_run_fk FOREIGN KEY (scan_run_id) REFERENCES scan_runs (id) ON DELETE CASCADE,
    UNIQUE KEY package_inventory_scan_identity_unique (scan_run_id, ecosystem, package_name, package_version),
    KEY package_inventory_project_classification_index (project_id, classification, package_name)
) ENGINE=InnoDB;
