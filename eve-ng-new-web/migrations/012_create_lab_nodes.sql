CREATE TABLE IF NOT EXISTS lab_nodes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lab_id UUID NOT NULL REFERENCES labs(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    node_type VARCHAR(64) NOT NULL,
    template VARCHAR(128),
    image VARCHAR(255),
    icon VARCHAR(255),
    console VARCHAR(32),
    config SMALLINT NOT NULL DEFAULT 0,
    left_pos INTEGER,
    top_pos INTEGER,
    delay_ms INTEGER NOT NULL DEFAULT 0,
    ethernet_count INTEGER,
    serial_count INTEGER,
    cpu INTEGER,
    cpu_limit INTEGER,
    ram_mb INTEGER,
    nvram_mb INTEGER,
    first_mac MACADDR,
    qemu_options TEXT,
    qemu_version VARCHAR(32),
    qemu_arch VARCHAR(32),
    qemu_nic VARCHAR(64),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_lab_nodes_lab_id ON lab_nodes (lab_id);
CREATE INDEX IF NOT EXISTS idx_lab_nodes_node_type ON lab_nodes (node_type);

CREATE OR REPLACE FUNCTION set_lab_nodes_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_lab_nodes_set_updated_at ON lab_nodes;
CREATE TRIGGER trg_lab_nodes_set_updated_at
BEFORE UPDATE ON lab_nodes
FOR EACH ROW
EXECUTE FUNCTION set_lab_nodes_updated_at();
