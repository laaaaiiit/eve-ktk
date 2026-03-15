CREATE TABLE IF NOT EXISTS lab_task_groups (
    id VARCHAR(80) PRIMARY KEY,
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    action VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    label VARCHAR(160),
    requested_by_user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    requested_by VARCHAR(255),
    total INTEGER NOT NULL DEFAULT 0,
    queued INTEGER NOT NULL DEFAULT 0,
    running INTEGER NOT NULL DEFAULT 0,
    done INTEGER NOT NULL DEFAULT 0,
    failed INTEGER NOT NULL DEFAULT 0,
    skipped INTEGER NOT NULL DEFAULT 0,
    attempts INTEGER NOT NULL DEFAULT 0,
    progress_pct INTEGER NOT NULL DEFAULT 0,
    error_text TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at TIMESTAMPTZ,
    finished_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'lab_task_groups_action_chk'
    ) THEN
        ALTER TABLE lab_task_groups
            ADD CONSTRAINT lab_task_groups_action_chk
            CHECK (action IN ('start', 'stop'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'lab_task_groups_status_chk'
    ) THEN
        ALTER TABLE lab_task_groups
            ADD CONSTRAINT lab_task_groups_status_chk
            CHECK (status IN ('pending', 'running', 'done', 'failed'));
    END IF;
END;
$$;

CREATE INDEX IF NOT EXISTS idx_lab_task_groups_lab_id_created_at
    ON lab_task_groups(lab_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_lab_task_groups_status_created_at
    ON lab_task_groups(status, created_at DESC);

CREATE OR REPLACE FUNCTION set_lab_task_groups_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_lab_task_groups_set_updated_at ON lab_task_groups;
CREATE TRIGGER trg_lab_task_groups_set_updated_at
BEFORE UPDATE ON lab_task_groups
FOR EACH ROW
EXECUTE FUNCTION set_lab_task_groups_updated_at();
