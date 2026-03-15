CREATE TABLE IF NOT EXISTS lab_check_task_settings (
    lab_id UUID PRIMARY KEY REFERENCES labs(id) ON DELETE CASCADE,
    intro_text TEXT NOT NULL DEFAULT '',
    updated_by UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS lab_check_task_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    task_text TEXT NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    order_index INTEGER NOT NULL DEFAULT 0,
    created_by UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_by UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_lab_check_task_items_lab_id
    ON lab_check_task_items(lab_id);

CREATE TABLE IF NOT EXISTS lab_check_task_marks (
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    task_item_id UUID NOT NULL REFERENCES lab_check_task_items(id) ON DELETE CASCADE,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    is_done BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (lab_id, task_item_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_lab_check_task_marks_lab_user
    ON lab_check_task_marks(lab_id, user_id);
