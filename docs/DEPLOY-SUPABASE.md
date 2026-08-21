# Supabase production database

WTFCode uses Supabase only as managed PostgreSQL. Authentication, sessions, CSRF, rate limiting, and ownership checks stay in PHP. Do not configure Supabase Auth, an anon key, a publishable key, or a service-role key in Vercel.

## Create and initialize the project

1. In Supabase, create a project in the region nearest the intended Vercel traffic. Generate and save a strong database password in a password manager.
2. Link the repository with `supabase link --project-ref <project-ref>` and deploy the versioned schema with `supabase db push`.
3. Open **Connect** and choose the **Session pooler** connection string. Use the pooler on port `5432`; do not use transaction mode on `6543`, because WTFCode and PDO use prepared statements.
4. Replace the password placeholder. Percent-encode reserved URL characters in the username or password. The final secret should look like `postgresql://USER:PASSWORD@HOST:5432/postgres?sslmode=require`.
5. If the Data API is not needed for another application, disable it in the project API/Data API settings. The schema also revokes browser API roles and enables RLS without browser policies, but disabling the unused API is the clearest boundary.

## Verify before Vercel

From the production image or another PHP 8.4 environment with `pdo_pgsql`:

```sh
APP_ENV=production DATABASE_URL='postgresql://...' DB_SSLMODE=require php tools/check-database.php
```

For a fresh empty PostgreSQL database outside the linked Supabase workflow, `php tools/migrate.php` can apply the consolidated `supabase/production-schema.sql`. On Supabase, use the version-controlled files in `supabase/migrations/`; preview with `supabase db push --dry-run`, apply with `supabase db push`, and verify with `supabase migration list`. Future migrations must be created with `supabase migration new <description>`.

## Backup and recovery

Supabase is the durable system of record for accounts, sessions, project metadata, analysis evidence, and learning state. Configure backups appropriate to the plan, test a restore before launch, and export the schema before risky migrations. Temporary repository clones and container files are intentionally not backup targets.
