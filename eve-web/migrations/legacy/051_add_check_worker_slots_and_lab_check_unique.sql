ALTER TABLE task_queue_settings
    ADD COLUMN IF NOT EXISTS check_worker_slots INTEGER;

UPDATE task_queue_settings
SET check_worker_slots = COALESCE(check_worker_slots, start_worker_slots, worker_slots, 1)
WHERE check_worker_slots IS NULL;

ALTER TABLE task_queue_settings
    ALTER COLUMN check_worker_slots SET DEFAULT 1;

UPDATE task_queue_settings
SET check_worker_slots = 1
WHERE check_worker_slots IS NULL OR check_worker_slots < 1;

ALTER TABLE task_queue_settings
    ALTER COLUMN check_worker_slots SET NOT NULL;

ALTER TABLE task_queue_settings
    DROP CONSTRAINT IF EXISTS task_queue_settings_check_worker_slots_chk;

ALTER TABLE task_queue_settings
    ADD CONSTRAINT task_queue_settings_check_worker_slots_chk
    CHECK (check_worker_slots BETWEEN 1 AND 32);

UPDATE task_queue_settings
SET worker_slots = GREATEST(
    1,
    COALESCE(start_worker_slots, 1) + COALESCE(stop_worker_slots, 1) + COALESCE(check_worker_slots, 1)
)
WHERE id = 1;

WITH ranked AS (
    SELECT id,
           ROW_NUMBER() OVER (PARTITION BY lab_id ORDER BY created_at ASC, id ASC) AS rn
    FROM lab_tasks
    WHERE action = 'lab_check'
      AND status IN ('pending', 'running')
)
UPDATE lab_tasks t
SET status = 'failed',
    error_text = COALESCE(NULLIF(t.error_text, ''), 'Superseded by another active lab check task'),
    finished_at = COALESCE(t.finished_at, NOW()),
    updated_at = NOW()
FROM ranked r
WHERE t.id = r.id
  AND r.rn > 1;

CREATE UNIQUE INDEX IF NOT EXISTS idx_lab_tasks_lab_check_active_unique
    ON lab_tasks(lab_id)
    WHERE action = 'lab_check' AND status IN ('pending', 'running');
