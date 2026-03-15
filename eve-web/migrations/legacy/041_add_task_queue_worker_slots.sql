ALTER TABLE task_queue_settings
    ADD COLUMN IF NOT EXISTS worker_slots INTEGER NOT NULL DEFAULT 2;

ALTER TABLE task_queue_settings
    DROP CONSTRAINT IF EXISTS task_queue_settings_worker_slots_chk;

ALTER TABLE task_queue_settings
    ADD CONSTRAINT task_queue_settings_worker_slots_chk CHECK (worker_slots BETWEEN 1 AND 32);

UPDATE task_queue_settings
SET worker_slots = 2
WHERE worker_slots IS NULL OR worker_slots < 1;
