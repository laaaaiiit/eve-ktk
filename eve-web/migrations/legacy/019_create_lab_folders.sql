CREATE TABLE IF NOT EXISTS lab_folders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    parent_id UUID NULL REFERENCES lab_folders(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT lab_folders_owner_parent_name_uniq UNIQUE (owner_user_id, parent_id, name)
);

CREATE INDEX IF NOT EXISTS idx_lab_folders_owner_user_id ON lab_folders(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_lab_folders_parent_id ON lab_folders(parent_id);

CREATE OR REPLACE FUNCTION set_lab_folders_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_lab_folders_set_updated_at ON lab_folders;
CREATE TRIGGER trg_lab_folders_set_updated_at
BEFORE UPDATE ON lab_folders
FOR EACH ROW
EXECUTE FUNCTION set_lab_folders_updated_at();
