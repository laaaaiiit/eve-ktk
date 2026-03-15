ALTER TABLE task_queue_settings
    ADD COLUMN IF NOT EXISTS start_worker_slots INTEGER;

ALTER TABLE task_queue_settings
    ADD COLUMN IF NOT EXISTS stop_worker_slots INTEGER;

UPDATE task_queue_settings
SET start_worker_slots = COALESCE(start_worker_slots, worker_slots, 1),
    stop_worker_slots = COALESCE(stop_worker_slots, worker_slots, 1)
WHERE id = 1;

ALTER TABLE task_queue_settings
    ALTER COLUMN start_worker_slots SET DEFAULT 1;

ALTER TABLE task_queue_settings
    ALTER COLUMN stop_worker_slots SET DEFAULT 1;

UPDATE task_queue_settings
SET start_worker_slots = 1
WHERE start_worker_slots IS NULL OR start_worker_slots < 1;

UPDATE task_queue_settings
SET stop_worker_slots = 1
WHERE stop_worker_slots IS NULL OR stop_worker_slots < 1;

ALTER TABLE task_queue_settings
    ALTER COLUMN start_worker_slots SET NOT NULL;

ALTER TABLE task_queue_settings
    ALTER COLUMN stop_worker_slots SET NOT NULL;

ALTER TABLE task_queue_settings
    DROP CONSTRAINT IF EXISTS task_queue_settings_start_worker_slots_chk;

ALTER TABLE task_queue_settings
    ADD CONSTRAINT task_queue_settings_start_worker_slots_chk CHECK (start_worker_slots BETWEEN 1 AND 32);

ALTER TABLE task_queue_settings
    DROP CONSTRAINT IF EXISTS task_queue_settings_stop_worker_slots_chk;

ALTER TABLE task_queue_settings
    ADD CONSTRAINT task_queue_settings_stop_worker_slots_chk CHECK (stop_worker_slots BETWEEN 1 AND 32);
