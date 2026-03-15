CREATE TABLE IF NOT EXISTS cloud_users (
    id BIGSERIAL PRIMARY KEY,
    cloud_id BIGINT NOT NULL REFERENCES clouds(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT cloud_users_cloud_user_uniq UNIQUE (cloud_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_cloud_users_user_id ON cloud_users (user_id);
CREATE INDEX IF NOT EXISTS idx_cloud_users_cloud_id ON cloud_users (cloud_id);
