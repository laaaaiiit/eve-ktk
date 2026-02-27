CREATE TABLE IF NOT EXISTS lab_check_settings (
    lab_id UUID PRIMARY KEY REFERENCES labs(id) ON DELETE CASCADE,
    grading_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    pass_percent NUMERIC(5,2) NOT NULL DEFAULT 60.00,
    updated_by UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT lab_check_settings_pass_percent_range CHECK (pass_percent >= 0.00 AND pass_percent <= 100.00)
);

CREATE TABLE IF NOT EXISTS lab_check_grade_scales (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    min_percent NUMERIC(5,2) NOT NULL,
    grade_label VARCHAR(64) NOT NULL,
    order_index INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT lab_check_grade_scales_min_percent_range CHECK (min_percent >= 0.00 AND min_percent <= 100.00)
);

CREATE INDEX IF NOT EXISTS idx_lab_check_grade_scales_lab_id
    ON lab_check_grade_scales(lab_id);

CREATE TABLE IF NOT EXISTS lab_check_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    node_id UUID NOT NULL REFERENCES lab_nodes(id) ON DELETE CASCADE,
    title VARCHAR(255) NOT NULL,
    transport VARCHAR(16) NOT NULL DEFAULT 'auto',
    shell_type VARCHAR(16) NOT NULL DEFAULT 'auto',
    command_text TEXT NOT NULL,
    match_mode VARCHAR(24) NOT NULL DEFAULT 'contains',
    expected_text TEXT NOT NULL,
    hint_text TEXT NOT NULL DEFAULT '',
    show_expected_to_learner BOOLEAN NOT NULL DEFAULT FALSE,
    show_output_to_learner BOOLEAN NOT NULL DEFAULT FALSE,
    points INTEGER NOT NULL DEFAULT 1,
    timeout_seconds INTEGER NOT NULL DEFAULT 12,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    order_index INTEGER NOT NULL DEFAULT 0,
    ssh_host VARCHAR(255) NULL,
    ssh_port INTEGER NULL,
    ssh_username VARCHAR(255) NULL,
    ssh_password TEXT NULL,
    created_by UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_by UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT lab_check_items_points_range CHECK (points >= 0 AND points <= 100000),
    CONSTRAINT lab_check_items_timeout_range CHECK (timeout_seconds >= 1 AND timeout_seconds <= 240),
    CONSTRAINT lab_check_items_transport_allowed CHECK (transport IN ('auto', 'console', 'ssh')),
    CONSTRAINT lab_check_items_shell_type_allowed CHECK (shell_type IN ('auto', 'ios', 'sh', 'cmd', 'powershell')),
    CONSTRAINT lab_check_items_match_mode_allowed CHECK (match_mode IN ('contains', 'equals', 'regex', 'not_contains')),
    CONSTRAINT lab_check_items_ssh_port_range CHECK (ssh_port IS NULL OR (ssh_port >= 1 AND ssh_port <= 65535))
);

CREATE INDEX IF NOT EXISTS idx_lab_check_items_lab_id
    ON lab_check_items(lab_id);

CREATE INDEX IF NOT EXISTS idx_lab_check_items_node_id
    ON lab_check_items(node_id);

CREATE TABLE IF NOT EXISTS lab_check_runs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    started_by UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    started_by_username VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'running',
    total_items INTEGER NOT NULL DEFAULT 0,
    passed_items INTEGER NOT NULL DEFAULT 0,
    failed_items INTEGER NOT NULL DEFAULT 0,
    error_items INTEGER NOT NULL DEFAULT 0,
    total_points INTEGER NOT NULL DEFAULT 0,
    earned_points INTEGER NOT NULL DEFAULT 0,
    score_percent NUMERIC(5,2) NOT NULL DEFAULT 0.00,
    grade_label VARCHAR(64) NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ NULL,
    duration_ms INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT lab_check_runs_status_allowed CHECK (status IN ('running', 'completed', 'failed')),
    CONSTRAINT lab_check_runs_score_percent_range CHECK (score_percent >= 0.00 AND score_percent <= 100.00)
);

CREATE INDEX IF NOT EXISTS idx_lab_check_runs_lab_id
    ON lab_check_runs(lab_id, started_at DESC);

CREATE INDEX IF NOT EXISTS idx_lab_check_runs_started_by
    ON lab_check_runs(started_by);

CREATE TABLE IF NOT EXISTS lab_check_run_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    run_id UUID NOT NULL REFERENCES lab_check_runs(id) ON DELETE CASCADE,
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    check_item_id UUID NULL REFERENCES lab_check_items(id) ON DELETE SET NULL,
    node_id UUID NULL REFERENCES lab_nodes(id) ON DELETE SET NULL,
    node_name VARCHAR(255) NOT NULL DEFAULT '',
    check_title VARCHAR(255) NOT NULL DEFAULT '',
    transport VARCHAR(16) NOT NULL DEFAULT 'auto',
    shell_type VARCHAR(16) NOT NULL DEFAULT 'auto',
    command_text TEXT NOT NULL DEFAULT '',
    expected_text TEXT NOT NULL DEFAULT '',
    match_mode VARCHAR(24) NOT NULL DEFAULT 'contains',
    hint_text TEXT NOT NULL DEFAULT '',
    show_expected_to_learner BOOLEAN NOT NULL DEFAULT FALSE,
    show_output_to_learner BOOLEAN NOT NULL DEFAULT FALSE,
    status VARCHAR(16) NOT NULL DEFAULT 'failed',
    is_passed BOOLEAN NOT NULL DEFAULT FALSE,
    points INTEGER NOT NULL DEFAULT 0,
    earned_points INTEGER NOT NULL DEFAULT 0,
    output_text TEXT NULL,
    error_text TEXT NULL,
    duration_ms INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT lab_check_run_items_status_allowed CHECK (status IN ('passed', 'failed', 'error', 'skipped')),
    CONSTRAINT lab_check_run_items_points_range CHECK (points >= 0 AND points <= 100000),
    CONSTRAINT lab_check_run_items_earned_points_range CHECK (earned_points >= 0 AND earned_points <= 100000),
    CONSTRAINT lab_check_run_items_transport_allowed CHECK (transport IN ('auto', 'console', 'ssh')),
    CONSTRAINT lab_check_run_items_shell_type_allowed CHECK (shell_type IN ('auto', 'ios', 'sh', 'cmd', 'powershell')),
    CONSTRAINT lab_check_run_items_match_mode_allowed CHECK (match_mode IN ('contains', 'equals', 'regex', 'not_contains'))
);

CREATE INDEX IF NOT EXISTS idx_lab_check_run_items_run_id
    ON lab_check_run_items(run_id);

CREATE INDEX IF NOT EXISTS idx_lab_check_run_items_lab_id
    ON lab_check_run_items(lab_id);
