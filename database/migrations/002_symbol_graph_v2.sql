-- WTFCode V2: normalized symbol graph and deterministic route index.
-- Apply once to a database that already includes migration 001.

ALTER TABLE scan_runs
    ADD COLUMN analysis_version VARCHAR(20) NOT NULL DEFAULT 'v1' AFTER commit_sha,
    ADD COLUMN analyzer_stats_json JSON NULL AFTER findings_count,
    ADD KEY scan_runs_project_version_index (project_id, analysis_version, created_at);

CREATE TABLE code_symbols (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    scan_run_id BIGINT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    parent_symbol_id BIGINT UNSIGNED NULL,
    symbol_key CHAR(64) NOT NULL,
    symbol_type VARCHAR(50) NOT NULL,
    language VARCHAR(40) NOT NULL,
    name VARCHAR(255) NOT NULL,
    qualified_name VARCHAR(700) NOT NULL,
    signature_text VARCHAR(1000) NULL,
    visibility ENUM('public', 'protected', 'private', 'package', 'unknown') NOT NULL DEFAULT 'unknown',
    is_exported TINYINT(1) NOT NULL DEFAULT 0,
    start_line INT UNSIGNED NOT NULL,
    end_line INT UNSIGNED NOT NULL,
    confidence ENUM('high', 'medium', 'low') NOT NULL DEFAULT 'medium',
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT code_symbols_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT code_symbols_scan_run_fk FOREIGN KEY (scan_run_id) REFERENCES scan_runs (id) ON DELETE CASCADE,
    CONSTRAINT code_symbols_file_fk FOREIGN KEY (file_id) REFERENCES project_files (id) ON DELETE CASCADE,
    CONSTRAINT code_symbols_parent_fk FOREIGN KEY (parent_symbol_id) REFERENCES code_symbols (id) ON DELETE SET NULL,
    UNIQUE KEY code_symbols_scan_key_unique (scan_run_id, symbol_key),
    KEY code_symbols_project_type_index (project_id, symbol_type, name),
    KEY code_symbols_file_line_index (file_id, start_line),
    KEY code_symbols_qualified_index (project_id, qualified_name(190))
) ENGINE=InnoDB;

CREATE TABLE symbol_relationships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    scan_run_id BIGINT UNSIGNED NOT NULL,
    source_symbol_id BIGINT UNSIGNED NULL,
    target_symbol_id BIGINT UNSIGNED NULL,
    target_external_name VARCHAR(700) NULL,
    evidence_file_id BIGINT UNSIGNED NOT NULL,
    relationship_type VARCHAR(50) NOT NULL,
    confidence ENUM('high', 'medium', 'low') NOT NULL DEFAULT 'medium',
    evidence_line_start INT UNSIGNED NULL,
    evidence_line_end INT UNSIGNED NULL,
    evidence_excerpt VARCHAR(1000) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT symbol_relationships_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT symbol_relationships_scan_run_fk FOREIGN KEY (scan_run_id) REFERENCES scan_runs (id) ON DELETE CASCADE,
    CONSTRAINT symbol_relationships_source_fk FOREIGN KEY (source_symbol_id) REFERENCES code_symbols (id) ON DELETE CASCADE,
    CONSTRAINT symbol_relationships_target_fk FOREIGN KEY (target_symbol_id) REFERENCES code_symbols (id) ON DELETE CASCADE,
    CONSTRAINT symbol_relationships_evidence_file_fk FOREIGN KEY (evidence_file_id) REFERENCES project_files (id) ON DELETE CASCADE,
    KEY symbol_relationships_source_type_index (source_symbol_id, relationship_type),
    KEY symbol_relationships_target_type_index (target_symbol_id, relationship_type),
    KEY symbol_relationships_project_external_index (project_id, target_external_name(190)),
    KEY symbol_relationships_evidence_index (evidence_file_id, evidence_line_start)
) ENGINE=InnoDB;

CREATE TABLE code_routes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    scan_run_id BIGINT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    handler_symbol_id BIGINT UNSIGNED NULL,
    route_key CHAR(64) NOT NULL,
    framework VARCHAR(50) NOT NULL,
    http_method VARCHAR(20) NOT NULL,
    route_path VARCHAR(700) NOT NULL,
    route_name VARCHAR(255) NULL,
    middleware_json JSON NULL,
    confidence ENUM('high', 'medium', 'low') NOT NULL DEFAULT 'medium',
    evidence_line INT UNSIGNED NOT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT code_routes_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT code_routes_scan_run_fk FOREIGN KEY (scan_run_id) REFERENCES scan_runs (id) ON DELETE CASCADE,
    CONSTRAINT code_routes_file_fk FOREIGN KEY (file_id) REFERENCES project_files (id) ON DELETE CASCADE,
    CONSTRAINT code_routes_handler_fk FOREIGN KEY (handler_symbol_id) REFERENCES code_symbols (id) ON DELETE SET NULL,
    UNIQUE KEY code_routes_scan_key_unique (scan_run_id, route_key),
    KEY code_routes_project_path_index (project_id, route_path(190)),
    KEY code_routes_handler_index (handler_symbol_id)
) ENGINE=InnoDB;
