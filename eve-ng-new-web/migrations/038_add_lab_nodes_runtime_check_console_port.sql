ALTER TABLE lab_nodes
  ADD COLUMN IF NOT EXISTS runtime_check_console_port INTEGER;
