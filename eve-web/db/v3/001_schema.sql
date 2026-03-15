BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;

CREATE SCHEMA IF NOT EXISTS auth;
CREATE SCHEMA IF NOT EXISTS infra;
CREATE SCHEMA IF NOT EXISTS labs;
CREATE SCHEMA IF NOT EXISTS runtime;
CREATE SCHEMA IF NOT EXISTS checks;
CREATE SCHEMA IF NOT EXISTS ops;

CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
	NEW.updated_at = NOW();
	RETURN NEW;
END;
$$;

-- =========================
-- AUTH DOMAIN
-- =========================
CREATE TABLE auth.roles (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	code text NOT NULL UNIQUE,
	title text NOT NULL,
	is_system boolean NOT NULL DEFAULT false,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE TABLE auth.permissions (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	code text NOT NULL UNIQUE,
	title text NOT NULL,
	category text NOT NULL DEFAULT 'general',
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE TABLE auth.role_permissions (
	role_id uuid NOT NULL REFERENCES auth.roles(id) ON DELETE CASCADE,
	permission_id uuid NOT NULL REFERENCES auth.permissions(id) ON DELETE CASCADE,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE auth.users (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	username text NOT NULL UNIQUE,
	password_hash text NOT NULL,
	role_id uuid NOT NULL REFERENCES auth.roles(id) ON DELETE RESTRICT,
	is_active boolean NOT NULL DEFAULT true,
	is_blocked boolean NOT NULL DEFAULT false,
	lang varchar(8) NOT NULL DEFAULT 'en',
	theme varchar(16) NOT NULL DEFAULT 'dark',
	last_seen timestamptz,
	last_ip inet,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_users_role_id ON auth.users(role_id);

CREATE TABLE auth.sessions (
	token text PRIMARY KEY,
	user_id uuid NOT NULL REFERENCES auth.users(id) ON DELETE CASCADE,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	last_activity timestamptz NOT NULL DEFAULT NOW(),
	expires_at timestamptz NOT NULL,
	ended_at timestamptz,
	ip inet,
	user_agent text
);

CREATE INDEX idx_sessions_user_id ON auth.sessions(user_id);
CREATE INDEX idx_sessions_expires_at ON auth.sessions(expires_at);
CREATE INDEX idx_sessions_last_activity ON auth.sessions(last_activity);
CREATE INDEX idx_sessions_ended_at ON auth.sessions(ended_at);

-- =========================
-- INFRA DOMAIN
-- =========================
CREATE TABLE infra.clouds (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	name text NOT NULL,
	pnet text NOT NULL,
	kind text NOT NULL DEFAULT 'bridge' CHECK (kind IN ('bridge', 'tap', 'vlan', 'other')),
	owner_user_id uuid,
	is_shared boolean NOT NULL DEFAULT true,
	meta jsonb NOT NULL DEFAULT '{}'::jsonb,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW(),
	UNIQUE (name, pnet),
	CHECK (pnet ~ '^pnet[0-9]+$')
);

CREATE INDEX idx_clouds_pnet ON infra.clouds(pnet);

CREATE TABLE infra.cloud_access (
	id bigserial PRIMARY KEY,
	cloud_id uuid NOT NULL REFERENCES infra.clouds(id) ON DELETE CASCADE,
	principal_type text NOT NULL CHECK (principal_type IN ('all', 'role', 'user')),
	principal_ref text,
	can_use boolean NOT NULL DEFAULT true,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	UNIQUE (cloud_id, principal_type, principal_ref)
);

CREATE INDEX idx_cloud_access_cloud_id ON infra.cloud_access(cloud_id);

-- =========================
-- LAB TOPOLOGY DOMAIN
-- =========================
CREATE TABLE labs.labs (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	owner_user_id uuid NOT NULL,
	source_lab_id uuid REFERENCES labs.labs(id) ON DELETE SET NULL,
	name text NOT NULL,
	folder_path text NOT NULL DEFAULT '/',
	description text NOT NULL DEFAULT '',
	is_published boolean NOT NULL DEFAULT false,
	is_shared boolean NOT NULL DEFAULT false,
	topology_locked boolean NOT NULL DEFAULT false,
	allow_wipe_when_locked boolean NOT NULL DEFAULT false,
	version integer NOT NULL DEFAULT 1,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_labs_owner ON labs.labs(owner_user_id);
CREATE INDEX idx_labs_source ON labs.labs(source_lab_id);
CREATE UNIQUE INDEX idx_labs_owner_folder_name_ci
ON labs.labs(owner_user_id, folder_path, lower(name));

CREATE TABLE labs.members (
	lab_id uuid NOT NULL REFERENCES labs.labs(id) ON DELETE CASCADE,
	user_id uuid NOT NULL,
	access_level text NOT NULL CHECK (access_level IN ('owner', 'editor', 'viewer')),
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW(),
	PRIMARY KEY (lab_id, user_id)
);

CREATE INDEX idx_members_user_id ON labs.members(user_id);

CREATE TABLE labs.nodes (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	lab_id uuid NOT NULL REFERENCES labs.labs(id) ON DELETE CASCADE,
	name text NOT NULL,
	node_type text NOT NULL,
	template text,
	image text,
	cpu integer NOT NULL DEFAULT 1 CHECK (cpu BETWEEN 1 AND 128),
	ram_mb integer NOT NULL DEFAULT 512 CHECK (ram_mb BETWEEN 64 AND 1048576),
	ethernet_ports integer NOT NULL DEFAULT 4 CHECK (ethernet_ports >= 0),
	first_port_name text,
	config jsonb NOT NULL DEFAULT '{}'::jsonb,
	x integer NOT NULL DEFAULT 0,
	y integer NOT NULL DEFAULT 0,
	width integer NOT NULL DEFAULT 80,
	height integer NOT NULL DEFAULT 80,
	z_index integer NOT NULL DEFAULT 100,
	status_hint text NOT NULL DEFAULT 'stopped' CHECK (status_hint IN ('stopped', 'starting', 'running', 'stopping', 'error')),
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_nodes_lab_id ON labs.nodes(lab_id);
CREATE INDEX idx_nodes_node_type ON labs.nodes(node_type);
CREATE INDEX idx_nodes_status_hint ON labs.nodes(status_hint);
CREATE UNIQUE INDEX idx_nodes_lab_name_ci
ON labs.nodes(lab_id, lower(name));

CREATE TABLE labs.links (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	lab_id uuid NOT NULL REFERENCES labs.labs(id) ON DELETE CASCADE,
	name text,
	link_type text NOT NULL DEFAULT 'p2p' CHECK (link_type IN ('p2p', 'cloud', 'segment')),
	points jsonb NOT NULL DEFAULT '[]'::jsonb,
	style jsonb NOT NULL DEFAULT '{}'::jsonb,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_links_lab_id ON labs.links(lab_id);

CREATE TABLE labs.link_endpoints (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	link_id uuid NOT NULL REFERENCES labs.links(id) ON DELETE CASCADE,
	endpoint_order smallint NOT NULL CHECK (endpoint_order BETWEEN 1 AND 16),
	node_id uuid REFERENCES labs.nodes(id) ON DELETE CASCADE,
	interface_name text,
	cloud_id uuid,
	cloud_pnet text,
	label_x integer,
	label_y integer,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	UNIQUE (link_id, endpoint_order),
	CHECK (
		(CASE WHEN node_id IS NULL THEN 0 ELSE 1 END) +
		(CASE WHEN cloud_pnet IS NULL THEN 0 ELSE 1 END)
		>= 1
	)
);

CREATE INDEX idx_link_endpoints_link_id ON labs.link_endpoints(link_id);
CREATE INDEX idx_link_endpoints_node_id ON labs.link_endpoints(node_id);
CREATE INDEX idx_link_endpoints_cloud_pnet ON labs.link_endpoints(cloud_pnet);

CREATE TABLE labs.objects (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	lab_id uuid NOT NULL REFERENCES labs.labs(id) ON DELETE CASCADE,
	object_type text NOT NULL CHECK (object_type IN ('shape', 'text')),
	name text NOT NULL DEFAULT '',
	x integer NOT NULL DEFAULT 0,
	y integer NOT NULL DEFAULT 0,
	width integer NOT NULL DEFAULT 120,
	height integer NOT NULL DEFAULT 60,
	z_index integer NOT NULL DEFAULT 50,
	is_locked boolean NOT NULL DEFAULT false,
	payload jsonb NOT NULL DEFAULT '{}'::jsonb,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_objects_lab_id ON labs.objects(lab_id);
CREATE INDEX idx_objects_type ON labs.objects(object_type);

-- =========================
-- RUNTIME / QUEUE DOMAIN
-- =========================
CREATE TABLE runtime.settings (
	id smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
	start_worker_slots integer NOT NULL DEFAULT 1 CHECK (start_worker_slots BETWEEN 1 AND 128),
	stop_worker_slots integer NOT NULL DEFAULT 2 CHECK (stop_worker_slots BETWEEN 1 AND 128),
	check_worker_slots integer NOT NULL DEFAULT 2 CHECK (check_worker_slots BETWEEN 1 AND 128),
	power_parallel_limit integer NOT NULL DEFAULT 2 CHECK (power_parallel_limit BETWEEN 1 AND 256),
	max_host_cpu_percent integer NOT NULL DEFAULT 80 CHECK (max_host_cpu_percent BETWEEN 20 AND 99),
	fast_stop_vios boolean NOT NULL DEFAULT true,
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

INSERT INTO runtime.settings (id)
VALUES (1)
ON CONFLICT (id) DO NOTHING;

CREATE TABLE runtime.workers (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	worker_name text NOT NULL UNIQUE,
	worker_type text NOT NULL CHECK (worker_type IN ('start', 'stop', 'check', 'generic')),
	status text NOT NULL DEFAULT 'offline' CHECK (status IN ('offline', 'idle', 'busy')),
	pid integer,
	host text,
	heartbeat_at timestamptz,
	meta jsonb NOT NULL DEFAULT '{}'::jsonb,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_workers_type_status ON runtime.workers(worker_type, status);

CREATE TABLE runtime.jobs (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	queue text NOT NULL CHECK (queue IN ('start', 'stop', 'check', 'import', 'export', 'sync', 'system')),
	action text NOT NULL,
	status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'succeeded', 'failed', 'canceled')),
	requested_by uuid,
	owner_user_id uuid,
	lab_id uuid,
	node_id uuid,
	payload jsonb NOT NULL DEFAULT '{}'::jsonb,
	progress_percent numeric(5,2) NOT NULL DEFAULT 0,
	item_total integer NOT NULL DEFAULT 0,
	item_done integer NOT NULL DEFAULT 0,
	worker_name text,
	started_at timestamptz,
	finished_at timestamptz,
	error_text text,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_jobs_status_created ON runtime.jobs(status, created_at DESC);
CREATE INDEX idx_jobs_queue_status_created ON runtime.jobs(queue, status, created_at DESC);
CREATE INDEX idx_jobs_lab_created ON runtime.jobs(lab_id, created_at DESC);

CREATE TABLE runtime.job_events (
	id bigserial PRIMARY KEY,
	job_id uuid NOT NULL REFERENCES runtime.jobs(id) ON DELETE CASCADE,
	event_level text NOT NULL DEFAULT 'info' CHECK (event_level IN ('debug', 'info', 'warn', 'error')),
	message text NOT NULL,
	payload jsonb NOT NULL DEFAULT '{}'::jsonb,
	created_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_job_events_job_id ON runtime.job_events(job_id);

CREATE TABLE runtime.node_states (
	node_id uuid PRIMARY KEY,
	lab_id uuid NOT NULL,
	state text NOT NULL DEFAULT 'stopped' CHECK (state IN ('stopped', 'starting', 'running', 'stopping', 'error', 'reloading')),
	pid integer,
	runtime_owner text,
	started_at timestamptz,
	stopped_at timestamptz,
	last_error text,
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_node_states_lab_state ON runtime.node_states(lab_id, state);

-- =========================
-- CHECKS / TASKS DOMAIN
-- =========================
CREATE TABLE checks.sets (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	lab_id uuid NOT NULL,
	title text NOT NULL DEFAULT 'Default checks',
	pass_percent integer NOT NULL DEFAULT 60 CHECK (pass_percent BETWEEN 0 AND 100),
	show_expected boolean NOT NULL DEFAULT false,
	show_output boolean NOT NULL DEFAULT false,
	grading jsonb NOT NULL DEFAULT '[]'::jsonb,
	revision integer NOT NULL DEFAULT 1,
	topology_fingerprint text,
	updated_by uuid,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW(),
	UNIQUE (lab_id)
);

CREATE TABLE checks.items (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	set_id uuid NOT NULL REFERENCES checks.sets(id) ON DELETE CASCADE,
	order_no integer NOT NULL DEFAULT 0,
	title text NOT NULL,
	node_selector text,
	transport text NOT NULL DEFAULT 'console' CHECK (transport IN ('console', 'ssh', 'qga', 'vpcs')),
	shell_type text NOT NULL DEFAULT 'auto' CHECK (shell_type IN ('auto', 'linux', 'windows', 'cisco')),
	command_text text NOT NULL,
	expected_text text NOT NULL,
	match_mode text NOT NULL DEFAULT 'contains' CHECK (match_mode IN ('contains', 'not_contains', 'equals', 'regex')),
	points integer NOT NULL DEFAULT 1 CHECK (points >= 0),
	timeout_sec integer NOT NULL DEFAULT 30 CHECK (timeout_sec BETWEEN 1 AND 600),
	is_required boolean NOT NULL DEFAULT false,
	is_active boolean NOT NULL DEFAULT true,
	meta jsonb NOT NULL DEFAULT '{}'::jsonb,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_check_items_set_order ON checks.items(set_id, order_no);

CREATE TABLE checks.runs (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	set_id uuid NOT NULL REFERENCES checks.sets(id) ON DELETE CASCADE,
	lab_id uuid NOT NULL,
	requested_by uuid,
	status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'passed', 'failed', 'error', 'canceled')),
	total_points integer NOT NULL DEFAULT 0,
	earned_points integer NOT NULL DEFAULT 0,
	score_percent numeric(5,2),
	progress_percent numeric(5,2) NOT NULL DEFAULT 0,
	items_total integer NOT NULL DEFAULT 0,
	items_done integer NOT NULL DEFAULT 0,
	started_at timestamptz,
	finished_at timestamptz,
	duration_ms integer,
	error_text text,
	created_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_check_runs_lab_created ON checks.runs(lab_id, created_at DESC);
CREATE INDEX idx_check_runs_status_created ON checks.runs(status, created_at DESC);

CREATE TABLE checks.results (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	run_id uuid NOT NULL REFERENCES checks.runs(id) ON DELETE CASCADE,
	item_id uuid REFERENCES checks.items(id) ON DELETE SET NULL,
	status text NOT NULL CHECK (status IN ('pass', 'fail', 'error', 'skipped')),
	points_earned integer NOT NULL DEFAULT 0,
	output_text text,
	matched_text text,
	error_text text,
	duration_ms integer NOT NULL DEFAULT 0,
	created_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_check_results_run ON checks.results(run_id);

CREATE TABLE checks.tasks (
	id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	lab_id uuid NOT NULL,
	order_no integer NOT NULL DEFAULT 0,
	text text NOT NULL,
	is_active boolean NOT NULL DEFAULT true,
	created_by uuid,
	updated_by uuid,
	created_at timestamptz NOT NULL DEFAULT NOW(),
	updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_tasks_lab_order ON checks.tasks(lab_id, order_no);

CREATE TABLE checks.task_marks (
	task_id uuid NOT NULL REFERENCES checks.tasks(id) ON DELETE CASCADE,
	user_id uuid NOT NULL,
	lab_id uuid NOT NULL,
	is_done boolean NOT NULL DEFAULT false,
	marked_at timestamptz NOT NULL DEFAULT NOW(),
	PRIMARY KEY (task_id, user_id)
);

CREATE INDEX idx_task_marks_lab_user ON checks.task_marks(lab_id, user_id);

-- =========================
-- OPS VIEWS (MANUAL SUPPORT)
-- =========================
CREATE VIEW ops.lab_overview AS
SELECT
	l.id AS lab_id,
	l.name AS lab_name,
	l.owner_user_id,
	l.is_shared,
	l.is_published,
	l.topology_locked,
	COUNT(DISTINCT n.id) AS nodes_total,
	COUNT(DISTINCT k.id) AS links_total,
	COUNT(DISTINCT o.id) AS objects_total,
	l.updated_at
FROM labs.labs l
LEFT JOIN labs.nodes n ON n.lab_id = l.id
LEFT JOIN labs.links k ON k.lab_id = l.id
LEFT JOIN labs.objects o ON o.lab_id = l.id
GROUP BY l.id;

CREATE VIEW ops.active_jobs AS
SELECT
	j.id,
	j.queue,
	j.action,
	j.status,
	j.lab_id,
	j.node_id,
	j.worker_name,
	j.progress_percent,
	j.item_done,
	j.item_total,
	j.created_at,
	j.started_at,
	j.updated_at
FROM runtime.jobs j
WHERE j.status IN ('pending', 'running');

CREATE VIEW ops.latest_check_runs AS
SELECT DISTINCT ON (r.lab_id)
	r.lab_id,
	r.id AS run_id,
	r.status,
	r.score_percent,
	r.progress_percent,
	r.items_done,
	r.items_total,
	r.created_at,
	r.finished_at
FROM checks.runs r
ORDER BY r.lab_id, r.created_at DESC;

-- =========================
-- UPDATED_AT TRIGGERS
-- =========================
CREATE TRIGGER trg_roles_updated_at BEFORE UPDATE ON auth.roles
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_permissions_updated_at BEFORE UPDATE ON auth.permissions
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_users_updated_at BEFORE UPDATE ON auth.users
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_clouds_updated_at BEFORE UPDATE ON infra.clouds
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_labs_updated_at BEFORE UPDATE ON labs.labs
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_members_updated_at BEFORE UPDATE ON labs.members
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_nodes_updated_at BEFORE UPDATE ON labs.nodes
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_links_updated_at BEFORE UPDATE ON labs.links
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_objects_updated_at BEFORE UPDATE ON labs.objects
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_runtime_settings_updated_at BEFORE UPDATE ON runtime.settings
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_workers_updated_at BEFORE UPDATE ON runtime.workers
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_jobs_updated_at BEFORE UPDATE ON runtime.jobs
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_node_states_updated_at BEFORE UPDATE ON runtime.node_states
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_check_sets_updated_at BEFORE UPDATE ON checks.sets
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_check_items_updated_at BEFORE UPDATE ON checks.items
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TRIGGER trg_tasks_updated_at BEFORE UPDATE ON checks.tasks
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- =========================
-- BASIC SEED FOR QUICK REVIEW
-- =========================
INSERT INTO auth.roles (code, title, is_system)
VALUES
	('admin', 'Administrator', true),
	('teacher', 'Teacher', true),
	('user', 'User', true)
ON CONFLICT (code) DO NOTHING;

INSERT INTO auth.permissions (code, title, category)
VALUES
	('page.system.status.view', 'Open System Status', 'page.system'),
	('page.system.taskqueue.view', 'Open Task Queue', 'page.system'),
	('page.management.labmgmt.view', 'Open Lab Management', 'page.management'),
	('main.lab.create', 'Create labs', 'main'),
	('main.lab.publish', 'Publish labs', 'main'),
	('labmgmt.actions', 'Run lab actions', 'labmgmt')
ON CONFLICT (code) DO NOTHING;

INSERT INTO auth.role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM auth.roles r
JOIN auth.permissions p ON p.code IN (
	'page.system.status.view',
	'page.system.taskqueue.view',
	'page.management.labmgmt.view',
	'main.lab.create',
	'main.lab.publish',
	'labmgmt.actions'
)
WHERE r.code = 'admin'
ON CONFLICT (role_id, permission_id) DO NOTHING;

COMMIT;
