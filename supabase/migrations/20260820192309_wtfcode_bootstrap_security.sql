CREATE OR REPLACE FUNCTION wtfcode_touch_updated_at()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public
AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;
REVOKE ALL ON FUNCTION wtfcode_touch_updated_at() FROM PUBLIC;

DROP TRIGGER IF EXISTS users_touch_updated_at ON users;
CREATE TRIGGER users_touch_updated_at BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION wtfcode_touch_updated_at();
DROP TRIGGER IF EXISTS projects_touch_updated_at ON projects;
CREATE TRIGGER projects_touch_updated_at BEFORE UPDATE ON projects FOR EACH ROW EXECUTE FUNCTION wtfcode_touch_updated_at();

DO $$
DECLARE role_name TEXT;
BEGIN
    FOREACH role_name IN ARRAY ARRAY['anon', 'authenticated', 'service_role'] LOOP
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = role_name) THEN
            EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM %I', role_name);
            EXECUTE format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM %I', role_name);
            EXECUTE format('REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM %I', role_name);
        END IF;
    END LOOP;
END;
$$;

ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE projects ENABLE ROW LEVEL SECURITY;
ALTER TABLE project_files ENABLE ROW LEVEL SECURITY;
ALTER TABLE project_dependencies ENABLE ROW LEVEL SECURITY;
ALTER TABLE architecture_nodes ENABLE ROW LEVEL SECURITY;
ALTER TABLE architecture_edges ENABLE ROW LEVEL SECURITY;
ALTER TABLE scan_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE scan_findings ENABLE ROW LEVEL SECURITY;
ALTER TABLE analysis_provider_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE package_inventory ENABLE ROW LEVEL SECURITY;
ALTER TABLE code_symbols ENABLE ROW LEVEL SECURITY;
ALTER TABLE symbol_relationships ENABLE ROW LEVEL SECURITY;
ALTER TABLE code_routes ENABLE ROW LEVEL SECURITY;
ALTER TABLE learning_progress ENABLE ROW LEVEL SECURITY;
ALTER TABLE change_guard_snapshots ENABLE ROW LEVEL SECURITY;
ALTER TABLE analysis_jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE analysis_job_steps ENABLE ROW LEVEL SECURITY;
ALTER TABLE engine_disagreements ENABLE ROW LEVEL SECURITY;
ALTER TABLE sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE login_attempts ENABLE ROW LEVEL SECURITY;
ALTER TABLE provider_cache_entries ENABLE ROW LEVEL SECURITY;

INSERT INTO schema_migrations(version) VALUES ('202608200001_initial_production') ON CONFLICT (version) DO NOTHING;;
