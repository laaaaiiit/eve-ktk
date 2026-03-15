CREATE TABLE IF NOT EXISTS lab_node_ports (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    node_id UUID NOT NULL REFERENCES lab_nodes(id) ON DELETE CASCADE,
    name VARCHAR(64) NOT NULL,
    port_type VARCHAR(32) NOT NULL,
    network_id UUID REFERENCES lab_networks(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_lab_node_ports_node_id ON lab_node_ports (node_id);
CREATE INDEX IF NOT EXISTS idx_lab_node_ports_network_id ON lab_node_ports (network_id);

CREATE OR REPLACE FUNCTION set_lab_node_ports_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_lab_node_ports_set_updated_at ON lab_node_ports;
CREATE TRIGGER trg_lab_node_ports_set_updated_at
BEFORE UPDATE ON lab_node_ports
FOR EACH ROW
EXECUTE FUNCTION set_lab_node_ports_updated_at();
