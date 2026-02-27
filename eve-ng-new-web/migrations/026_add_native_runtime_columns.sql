ALTER TABLE lab_nodes
  ADD COLUMN IF NOT EXISTS runtime_pid INTEGER,
  ADD COLUMN IF NOT EXISTS runtime_console_port INTEGER,
  ADD COLUMN IF NOT EXISTS runtime_started_at TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS runtime_stopped_at TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_lab_nodes_runtime_pid
  ON lab_nodes(runtime_pid)
  WHERE runtime_pid IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_lab_nodes_runtime_started_at
  ON lab_nodes(runtime_started_at DESC);
