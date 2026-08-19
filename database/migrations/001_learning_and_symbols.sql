-- Apply only to a database created from an earlier WTFCode schema.
ALTER TABLE project_files ADD COLUMN symbols_json JSON NULL AFTER plain_summary;

CREATE TABLE IF NOT EXISTS learning_progress (
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
