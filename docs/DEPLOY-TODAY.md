# Deploy WTFCode today

This is the shortest safe path. It intentionally stops before clicking Vercel's final deploy button in the repository-preparation workflow.

1. Create a Supabase project and save the database password.
2. In Supabase **SQL Editor**, run all of `supabase/production-schema.sql`.
3. In Supabase **Connect**, copy the **Session pooler** URL on port `5432`; replace and URL-encode the password; keep `sslmode=require`.
4. Disable the Supabase Data API if nothing else uses it. Do not create or copy any Supabase browser keys for WTFCode.
5. In Vercel, import `flegardev/WTFCode` as project `wtf-code`, choose `Container`, use root `./`, and leave Build, Install, and Output blank.
6. Add the seven variables listed in `docs/DEPLOY-VERCEL.md` exactly. Vercel supplies `PORT`.
7. Click **Deploy**.
8. Verify `/health`, registration, logout/login, a small public repository import, reload persistence, a cold start/redeploy, cookies, headers, browser console, network failures, and runtime logs.

If `/health` reports `degraded`, re-check that `DATABASE_URL` is the session-pooler URL on `5432`, the password is URL-encoded, `DB_SSLMODE=require`, and the schema completed successfully. Do not paste the URL into tickets or logs.
