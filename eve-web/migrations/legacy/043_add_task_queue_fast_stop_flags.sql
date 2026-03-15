ALTER TABLE task_queue_settings
    ADD COLUMN IF NOT EXISTS fast_stop_vios boolean;

UPDATE task_queue_settings
SET fast_stop_vios = COALESCE(fast_stop_vios, TRUE)
WHERE id = 1;

ALTER TABLE task_queue_settings
    ALTER COLUMN fast_stop_vios SET DEFAULT TRUE;

ALTER TABLE task_queue_settings
    ALTER COLUMN fast_stop_vios SET NOT NULL;
