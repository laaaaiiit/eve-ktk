CREATE TABLE IF NOT EXISTS lab_shared_users (
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (lab_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_lab_shared_users_user_id ON lab_shared_users (user_id);
