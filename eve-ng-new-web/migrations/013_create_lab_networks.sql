CREATE TABLE IF NOT EXISTS lab_networks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    name VARCHAR(255),
    network_type VARCHAR(64) NOT NULL,
    left_pos INTEGER,
    top_pos INTEGER,
    visibility SMALLINT NOT NULL DEFAULT 1,
    icon VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_lab_networks_lab_id ON lab_networks (lab_id);
CREATE INDEX IF NOT EXISTS idx_lab_networks_network_type ON lab_networks (network_type);

CREATE OR REPLACE FUNCTION set_lab_networks_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_lab_networks_set_updated_at ON lab_networks;
CREATE TRIGGER trg_lab_networks_set_updated_at
BEFORE UPDATE ON lab_networks
FOR EACH ROW
EXECUTE FUNCTION set_lab_networks_updated_at();
