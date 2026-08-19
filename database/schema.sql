-- WTFCode: a workspace-safe repository understanding MVP for MySQL 8+
CREATE DATABASE IF NOT EXISTS wtfcode
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE wtfcode;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB;

CREATE TABLE projects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(140) NOT NULL,
    repository_url VARCHAR(500) NOT NULL,
    local_path VARCHAR(500) NOT NULL,
    status ENUM('queued', 'cloning', 'scanning', 'ready', 'failed') NOT NULL DEFAULT 'queued',
    stack_json JSON NULL,
    overview TEXT NULL,
    last_scan_at TIMESTAMP NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT projects_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    KEY projects_user_updated_index (user_id, updated_at),
    UNIQUE KEY projects_user_repository_unique (user_id, repository_url)
) ENGINE=InnoDB;

CREATE TABLE project_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    path VARCHAR(500) NOT NULL,
    language VARCHAR(50) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    line_count INT UNSIGNED NOT NULL DEFAULT 0,
    content_hash CHAR(64) NOT NULL,
    role_name VARCHAR(80) NOT NULL DEFAULT 'source',
    plain_summary TEXT NULL,
    symbols_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT project_files_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    UNIQUE KEY project_files_project_path_unique (project_id, path),
    KEY project_files_project_role_index (project_id, role_name),
    KEY project_files_project_hash_index (project_id, content_hash)
) ENGINE=InnoDB;

CREATE TABLE project_dependencies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    source_file_id BIGINT UNSIGNED NOT NULL,
    target_file_id BIGINT UNSIGNED NULL,
    target_path VARCHAR(500) NOT NULL,
    relationship_type ENUM('imports', 'requires', 'uses') NOT NULL DEFAULT 'imports',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT project_dependencies_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT project_dependencies_source_fk FOREIGN KEY (source_file_id) REFERENCES project_files (id) ON DELETE CASCADE,
    CONSTRAINT project_dependencies_target_fk FOREIGN KEY (target_file_id) REFERENCES project_files (id) ON DELETE SET NULL,
    UNIQUE KEY project_dependency_unique (source_file_id, target_path, relationship_type),
    KEY project_dependencies_target_index (target_file_id)
) ENGINE=InnoDB;

CREATE TABLE architecture_nodes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    node_key VARCHAR(100) NOT NULL,
    node_type VARCHAR(50) NOT NULL,
    label VARCHAR(160) NOT NULL,
    plain_explanation TEXT NOT NULL,
    evidence_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT architecture_nodes_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    UNIQUE KEY architecture_nodes_project_key_unique (project_id, node_key)
) ENGINE=InnoDB;

CREATE TABLE architecture_edges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    from_node_id BIGINT UNSIGNED NOT NULL,
    to_node_id BIGINT UNSIGNED NOT NULL,
    relationship_label VARCHAR(160) NOT NULL,
    evidence_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT architecture_edges_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT architecture_edges_from_fk FOREIGN KEY (from_node_id) REFERENCES architecture_nodes (id) ON DELETE CASCADE,
    CONSTRAINT architecture_edges_to_fk FOREIGN KEY (to_node_id) REFERENCES architecture_nodes (id) ON DELETE CASCADE,
    UNIQUE KEY architecture_edge_unique (project_id, from_node_id, to_node_id, relationship_label)
) ENGINE=InnoDB;

CREATE TABLE scan_findings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    severity ENUM('info', 'attention', 'risk') NOT NULL DEFAULT 'info',
    finding_type VARCHAR(80) NOT NULL,
    title VARCHAR(255) NOT NULL,
    plain_explanation TEXT NOT NULL,
    file_path VARCHAR(500) NULL,
    evidence_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT scan_findings_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    KEY scan_findings_project_severity_index (project_id, severity)
) ENGINE=InnoDB;

CREATE TABLE scan_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    commit_sha CHAR(64) NULL,
    analysis_version VARCHAR(20) NOT NULL DEFAULT 'v1',
    analysis_profile VARCHAR(20) NOT NULL DEFAULT 'quick',
    files_scanned INT UNSIGNED NOT NULL DEFAULT 0,
    findings_count INT UNSIGNED NOT NULL DEFAULT 0,
    analyzer_stats_json JSON NULL,
    engine_status_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT scan_runs_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    KEY scan_runs_project_created_index (project_id, created_at),
    KEY scan_runs_project_version_index (project_id, analysis_version, created_at)
) ENGINE=InnoDB;

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
    provenance_json JSON NULL,
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
    provenance_json JSON NULL,
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
    provenance_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT code_routes_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT code_routes_scan_run_fk FOREIGN KEY (scan_run_id) REFERENCES scan_runs (id) ON DELETE CASCADE,
    CONSTRAINT code_routes_file_fk FOREIGN KEY (file_id) REFERENCES project_files (id) ON DELETE CASCADE,
    CONSTRAINT code_routes_handler_fk FOREIGN KEY (handler_symbol_id) REFERENCES code_symbols (id) ON DELETE SET NULL,
    UNIQUE KEY code_routes_scan_key_unique (scan_run_id, route_key),
    KEY code_routes_project_path_index (project_id, route_path(190)),
    KEY code_routes_handler_index (handler_symbol_id)
) ENGINE=InnoDB;

CREATE TABLE learning_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    lesson_key VARCHAR(120) NOT NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT learning_progress_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT learning_progress_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    UNIQUE KEY learning_progress_unique (user_id, project_id, lesson_key),
    KEY learning_progress_project_user_index (project_id, user_id)
) ENGINE=InnoDB;
