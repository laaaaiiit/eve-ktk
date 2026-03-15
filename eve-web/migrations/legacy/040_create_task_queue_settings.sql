CREATE TABLE IF NOT EXISTS task_queue_settings (
    id SMALLINT PRIMARY KEY DEFAULT 1,
    power_parallel_limit INTEGER NOT NULL DEFAULT 2,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT task_queue_settings_singleton CHECK (id = 1),
    CONSTRAINT task_queue_settings_limit_chk CHECK (power_parallel_limit BETWEEN 1 AND 32)
);

INSERT INTO task_queue_settings (id, power_parallel_limit)
VALUES (1, 2)
ON CONFLICT (id) DO NOTHING;
