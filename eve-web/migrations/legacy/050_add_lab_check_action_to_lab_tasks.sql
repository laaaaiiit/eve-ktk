ALTER TABLE lab_tasks
    DROP CONSTRAINT IF EXISTS lab_tasks_action_chk;

ALTER TABLE lab_tasks
    ADD CONSTRAINT lab_tasks_action_chk
    CHECK (action IN ('start', 'stop', 'lab_check'));

CREATE INDEX IF NOT EXISTS idx_lab_tasks_action_status_created_at
    ON lab_tasks(action, status, created_at);
