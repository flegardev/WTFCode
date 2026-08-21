# PostgreSQL migrations

These files mirror the linked Supabase project's authoritative migration history. Deploy pending changes with `supabase db push`; do not paste schema changes directly into the production SQL Editor. `../production-schema.sql` remains a consolidated bootstrap for `php tools/migrate.php` and manual recovery outside the linked Supabase workflow.

Add future upgrades here with `supabase migration new <description>`. Do not edit an already-applied migration.
