-- WTFCode V3 phase 7: evidence-only Before/After AI snapshots.
-- Apply once after 004_security_intelligence.sql.

CREATE TABLE change_guard_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    phase ENUM('before', 'after') NOT NULL,
    label VARCHAR(180) NOT NULL DEFAULT '',
    intended_change VARCHAR(500) NOT NULL DEFAULT '',
    commit_sha CHAR(64) NULL,
    scan_run_id BIGINT UNSIGNED NULL,
    snapshot_json JSON NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT change_guard_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT change_guard_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT change_guard_scan_fk FOREIGN KEY (scan_run_id) REFERENCES scan_runs (id) ON DELETE SET NULL,
    KEY change_guard_project_created_index (project_id, created_at),
    KEY change_guard_project_phase_index (project_id, phase, created_at)
) ENGINE=InnoDB;
