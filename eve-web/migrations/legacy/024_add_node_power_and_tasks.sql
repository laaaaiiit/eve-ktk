ALTER TABLE lab_nodes
  ADD COLUMN IF NOT EXISTS power_state VARCHAR(16) NOT NULL DEFAULT 'stopped',
  ADD COLUMN IF NOT EXISTS last_error TEXT,
  ADD COLUMN IF NOT EXISTS power_updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'lab_nodes_power_state_chk'
    ) THEN
        ALTER TABLE lab_nodes
          ADD CONSTRAINT lab_nodes_power_state_chk
          CHECK (power_state IN ('stopped', 'starting', 'running', 'stopping', 'error'));
    END IF;
END;
$$;

UPDATE lab_nodes
SET power_state = CASE WHEN is_running THEN 'running' ELSE 'stopped' END
WHERE power_state IS NULL
   OR power_state NOT IN ('stopped', 'starting', 'running', 'stopping', 'error');

CREATE INDEX IF NOT EXISTS idx_lab_nodes_power_state ON lab_nodes(power_state);
CREATE INDEX IF NOT EXISTS idx_lab_nodes_power_updated_at ON lab_nodes(power_updated_at DESC);

CREATE TABLE IF NOT EXISTS lab_tasks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    node_id UUID NOT NULL REFERENCES lab_nodes(id) ON DELETE CASCADE,
    action VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    result_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    requested_by_user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    requested_by VARCHAR(255),
    error_text TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at TIMESTAMPTZ,
    finished_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'lab_tasks_action_chk'
    ) THEN
        ALTER TABLE lab_tasks
          ADD CONSTRAINT lab_tasks_action_chk
          CHECK (action IN ('start', 'stop'));
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'lab_tasks_status_chk'
    ) THEN
        ALTER TABLE lab_tasks
          ADD CONSTRAINT lab_tasks_status_chk
          CHECK (status IN ('pending', 'running', 'done', 'failed'));
    END IF;
END;
$$;

CREATE INDEX IF NOT EXISTS idx_lab_tasks_status_created_at ON lab_tasks(status, created_at);
CREATE INDEX IF NOT EXISTS idx_lab_tasks_lab_id_created_at ON lab_tasks(lab_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_lab_tasks_node_id_created_at ON lab_tasks(node_id, created_at DESC);

CREATE OR REPLACE FUNCTION set_lab_tasks_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_lab_tasks_set_updated_at ON lab_tasks;
CREATE TRIGGER trg_lab_tasks_set_updated_at
BEFORE UPDATE ON lab_tasks
FOR EACH ROW
EXECUTE FUNCTION set_lab_tasks_updated_at();

