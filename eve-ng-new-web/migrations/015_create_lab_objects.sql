CREATE TABLE IF NOT EXISTS lab_objects (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    object_type VARCHAR(32) NOT NULL,
    name VARCHAR(255),
    data_base64 TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_lab_objects_lab_id ON lab_objects (lab_id);
CREATE INDEX IF NOT EXISTS idx_lab_objects_object_type ON lab_objects (object_type);

CREATE OR REPLACE FUNCTION set_lab_objects_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_lab_objects_set_updated_at ON lab_objects;
CREATE TRIGGER trg_lab_objects_set_updated_at
BEFORE UPDATE ON lab_objects
FOR EACH ROW
EXECUTE FUNCTION set_lab_objects_updated_at();
