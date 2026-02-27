CREATE TABLE IF NOT EXISTS labs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    author_user_id BIGINT NOT NULL REFERENCES users(id),
    is_shared BOOLEAN NOT NULL DEFAULT FALSE,
    is_mirror BOOLEAN NOT NULL DEFAULT FALSE,
    collaborate_allowed BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_labs_author_user_id ON labs (author_user_id);
CREATE INDEX IF NOT EXISTS idx_labs_is_shared ON labs (is_shared);

CREATE OR REPLACE FUNCTION set_labs_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_labs_set_updated_at ON labs;
CREATE TRIGGER trg_labs_set_updated_at
BEFORE UPDATE ON labs
FOR EACH ROW
EXECUTE FUNCTION set_labs_updated_at();
