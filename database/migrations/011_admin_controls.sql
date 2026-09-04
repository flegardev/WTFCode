-- Admin controls for legacy MySQL 8 installations.
-- Admin access is deliberately stored in the application users table so the
-- existing PHP session, CSRF, and ownership model remains the authority.

ALTER TABLE users
    ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash,
    ADD COLUMN is_suspended TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin,
    ADD KEY users_admin_state_index (is_admin, is_suspended, id);
