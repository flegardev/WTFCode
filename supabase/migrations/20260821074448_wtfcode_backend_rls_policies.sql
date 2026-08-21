ALTER ROLE wtfcode_app NOBYPASSRLS;
ALTER ROLE wtfcode_vercel NOBYPASSRLS;

DO $$
DECLARE
  r record;
BEGIN
  FOR r IN
    SELECT schemaname, tablename
    FROM pg_tables
    WHERE schemaname = 'public'
  LOOP
    EXECUTE format('DROP POLICY IF EXISTS wtfcode_backend_all ON %I.%I', r.schemaname, r.tablename);
    EXECUTE format(
      'CREATE POLICY wtfcode_backend_all ON %I.%I FOR ALL TO wtfcode_app USING (true) WITH CHECK (true)',
      r.schemaname,
      r.tablename
    );
  END LOOP;
END
$$;

REVOKE ALL ON ALL TABLES IN SCHEMA public FROM anon, authenticated, service_role;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated, service_role;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM anon, authenticated, service_role;;
