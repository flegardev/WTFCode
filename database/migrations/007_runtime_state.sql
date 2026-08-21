-- Local MySQL compatibility for durable runtime state. Production uses the versioned files in supabase/migrations/.
-- Apply once after 006_performance_jobs.sql.

CREATE TABLE sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    payload MEDIUMTEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY sessions_expires_index (expires_at)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    attempt_key CHAR(64) PRIMARY KEY,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at TIMESTAMP NOT NULL,
    locked_until TIMESTAMP NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY login_attempts_locked_index (locked_until)
) ENGINE=InnoDB;

CREATE TABLE provider_cache_entries (
    cache_key CHAR(64) PRIMARY KEY,
    provider_id VARCHAR(80) NOT NULL,
    provider_version VARCHAR(100) NOT NULL,
    analysis_version VARCHAR(20) NOT NULL,
    result_json JSON NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY provider_cache_entries_expires_index (expires_at)
) ENGINE=InnoDB;
