-- Admin controls for the PHP application. Supabase Auth is intentionally not
-- used; the existing PHP password/session/CSRF contract remains authoritative.

ALTER TABLE public.users
    ADD COLUMN IF NOT EXISTS is_admin BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS is_suspended BOOLEAN NOT NULL DEFAULT FALSE;

CREATE INDEX IF NOT EXISTS users_admin_state_index
    ON public.users(is_admin, is_suspended, id);

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
