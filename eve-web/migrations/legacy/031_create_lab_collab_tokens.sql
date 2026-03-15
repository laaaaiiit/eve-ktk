CREATE TABLE IF NOT EXISTS lab_collab_tokens (
    token TEXT PRIMARY KEY,
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    issued_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    last_used_at TIMESTAMPTZ,
    revoked_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_lab_collab_tokens_lab_id
    ON lab_collab_tokens(lab_id);

CREATE INDEX IF NOT EXISTS idx_lab_collab_tokens_user_id
    ON lab_collab_tokens(user_id);

CREATE INDEX IF NOT EXISTS idx_lab_collab_tokens_expires_at
    ON lab_collab_tokens(expires_at);
