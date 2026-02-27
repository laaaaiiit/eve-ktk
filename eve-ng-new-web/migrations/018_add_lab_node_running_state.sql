ALTER TABLE lab_nodes
  ADD COLUMN IF NOT EXISTS is_running BOOLEAN NOT NULL DEFAULT FALSE;

CREATE INDEX IF NOT EXISTS idx_lab_nodes_is_running ON lab_nodes(is_running);
