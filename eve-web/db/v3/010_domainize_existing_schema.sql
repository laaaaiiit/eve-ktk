BEGIN;

CREATE SCHEMA IF NOT EXISTS auth;
CREATE SCHEMA IF NOT EXISTS infra;
CREATE SCHEMA IF NOT EXISTS labs;
CREATE SCHEMA IF NOT EXISTS runtime;
CREATE SCHEMA IF NOT EXISTS checks;

-- auth
ALTER TABLE IF EXISTS public.roles SET SCHEMA auth;
ALTER TABLE IF EXISTS public.permissions SET SCHEMA auth;
ALTER TABLE IF EXISTS public.role_permissions SET SCHEMA auth;
ALTER TABLE IF EXISTS public.users SET SCHEMA auth;
ALTER TABLE IF EXISTS public.auth_sessions SET SCHEMA auth;

-- infra
ALTER TABLE IF EXISTS public.clouds SET SCHEMA infra;
ALTER TABLE IF EXISTS public.cloud_users SET SCHEMA infra;

-- labs
ALTER TABLE IF EXISTS public.labs SET SCHEMA labs;
ALTER TABLE IF EXISTS public.lab_shared_users SET SCHEMA labs;
ALTER TABLE IF EXISTS public.lab_folders SET SCHEMA labs;
ALTER TABLE IF EXISTS public.lab_collab_tokens SET SCHEMA labs;
ALTER TABLE IF EXISTS public.lab_nodes SET SCHEMA labs;
ALTER TABLE IF EXISTS public.lab_networks SET SCHEMA labs;
ALTER TABLE IF EXISTS public.lab_node_ports SET SCHEMA labs;
ALTER TABLE IF EXISTS public.lab_objects SET SCHEMA labs;

-- runtime
ALTER TABLE IF EXISTS public.lab_tasks SET SCHEMA runtime;
ALTER TABLE IF EXISTS public.lab_task_groups SET SCHEMA runtime;
ALTER TABLE IF EXISTS public.task_queue_settings SET SCHEMA runtime;

-- checks
ALTER TABLE IF EXISTS public.lab_check_settings SET SCHEMA checks;
ALTER TABLE IF EXISTS public.lab_check_grade_scales SET SCHEMA checks;
ALTER TABLE IF EXISTS public.lab_check_items SET SCHEMA checks;
ALTER TABLE IF EXISTS public.lab_check_runs SET SCHEMA checks;
ALTER TABLE IF EXISTS public.lab_check_run_items SET SCHEMA checks;
ALTER TABLE IF EXISTS public.lab_check_task_settings SET SCHEMA checks;
ALTER TABLE IF EXISTS public.lab_check_task_items SET SCHEMA checks;
ALTER TABLE IF EXISTS public.lab_check_task_marks SET SCHEMA checks;

COMMIT;

-- reduce coupling: drop all foreign keys crossing schema boundaries
DO $$
DECLARE
    r record;
BEGIN
    FOR r IN
        SELECT
            n_src.nspname AS src_schema,
            c_src.relname AS src_table,
            con.conname AS constraint_name
        FROM pg_constraint con
        JOIN pg_class c_src ON c_src.oid = con.conrelid
        JOIN pg_namespace n_src ON n_src.oid = c_src.relnamespace
        JOIN pg_class c_dst ON c_dst.oid = con.confrelid
        JOIN pg_namespace n_dst ON n_dst.oid = c_dst.relnamespace
        WHERE con.contype = 'f'
          AND n_src.nspname <> n_dst.nspname
    LOOP
        EXECUTE format(
            'ALTER TABLE %I.%I DROP CONSTRAINT IF EXISTS %I',
            r.src_schema,
            r.src_table,
            r.constraint_name
        );
    END LOOP;
END
$$;
