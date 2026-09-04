-- Explicit Change Guard snapshot pairing for legacy MySQL installations.
-- Apply once after 008_async_scan_queue.sql.

ALTER TABLE change_guard_snapshots
    ADD COLUMN pair_key CHAR(32) NULL AFTER user_id,
    ADD COLUMN before_snapshot_id BIGINT UNSIGNED NULL AFTER pair_key;

-- Existing snapshots predate explicit pairing. Give each a stable identity without
-- inventing relationships between historical before/after rows.
UPDATE change_guard_snapshots
SET pair_key = LOWER(MD5(CONCAT('legacy-change-guard:', id)))
WHERE pair_key IS NULL;

ALTER TABLE change_guard_snapshots
    MODIFY pair_key CHAR(32) NOT NULL,
    ADD CONSTRAINT change_guard_before_snapshot_fk
        FOREIGN KEY (before_snapshot_id) REFERENCES change_guard_snapshots (id) ON DELETE CASCADE,
    ADD CONSTRAINT change_guard_before_snapshot_unique UNIQUE (before_snapshot_id),
    ADD CONSTRAINT change_guard_pair_phase_unique UNIQUE (project_id, user_id, pair_key, phase),
    ADD KEY change_guard_pending_before_index (project_id, user_id, phase, before_snapshot_id, id);
