ALTER TABLE change_guard_snapshots
    ADD COLUMN pair_key CHAR(32),
    ADD COLUMN before_snapshot_id BIGINT;

-- Historical rows were captured before explicit pair identities existed. Keep
-- them addressable without guessing which before row belonged to which after row.
UPDATE change_guard_snapshots
SET pair_key = md5('legacy-change-guard:' || id::text)
WHERE pair_key IS NULL;

ALTER TABLE change_guard_snapshots
    ALTER COLUMN pair_key SET NOT NULL,
    ADD CONSTRAINT change_guard_before_snapshot_fk
        FOREIGN KEY (before_snapshot_id) REFERENCES change_guard_snapshots(id) ON DELETE CASCADE,
    ADD CONSTRAINT change_guard_before_snapshot_unique UNIQUE (before_snapshot_id),
    ADD CONSTRAINT change_guard_pair_phase_unique UNIQUE (project_id, user_id, pair_key, phase);

CREATE INDEX change_guard_pending_before_index
    ON change_guard_snapshots(project_id, user_id, phase, before_snapshot_id, id DESC);
