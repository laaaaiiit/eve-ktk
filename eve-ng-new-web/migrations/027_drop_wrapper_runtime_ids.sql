DROP TRIGGER IF EXISTS trg_assign_lab_node_runtime_id ON lab_nodes;
DROP TRIGGER IF EXISTS trg_assign_lab_network_runtime_id ON lab_networks;

DROP FUNCTION IF EXISTS assign_lab_node_runtime_id();
DROP FUNCTION IF EXISTS assign_lab_network_runtime_id();

DROP INDEX IF EXISTS idx_labs_runtime_tenant_unique;
DROP INDEX IF EXISTS idx_lab_nodes_runtime_per_lab_unique;
DROP INDEX IF EXISTS idx_lab_networks_runtime_per_lab_unique;

ALTER TABLE labs DROP CONSTRAINT IF EXISTS labs_runtime_tenant_positive_chk;
ALTER TABLE lab_nodes DROP CONSTRAINT IF EXISTS lab_nodes_runtime_node_id_positive_chk;
ALTER TABLE lab_networks DROP CONSTRAINT IF EXISTS lab_networks_runtime_network_id_positive_chk;

ALTER TABLE labs DROP COLUMN IF EXISTS runtime_tenant;
ALTER TABLE lab_nodes DROP COLUMN IF EXISTS runtime_node_id;
ALTER TABLE lab_networks DROP COLUMN IF EXISTS runtime_network_id;

DROP SEQUENCE IF EXISTS lab_runtime_tenant_seq;
