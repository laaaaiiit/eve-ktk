CREATE SEQUENCE IF NOT EXISTS lab_runtime_tenant_seq START WITH 1000 INCREMENT BY 1 MINVALUE 1;

ALTER TABLE labs
  ADD COLUMN IF NOT EXISTS runtime_tenant INTEGER;

UPDATE labs
SET runtime_tenant = nextval('lab_runtime_tenant_seq')
WHERE runtime_tenant IS NULL
   OR runtime_tenant <= 0;

SELECT setval(
  'lab_runtime_tenant_seq',
  GREATEST(COALESCE((SELECT MAX(runtime_tenant) FROM labs), 0), 1000)
);

ALTER TABLE labs
  ALTER COLUMN runtime_tenant SET DEFAULT nextval('lab_runtime_tenant_seq');

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'labs_runtime_tenant_positive_chk'
    ) THEN
        ALTER TABLE labs
          ADD CONSTRAINT labs_runtime_tenant_positive_chk
          CHECK (runtime_tenant > 0) NOT VALID;
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_indexes
        WHERE schemaname = 'public'
          AND indexname = 'idx_labs_runtime_tenant_unique'
    ) THEN
        CREATE UNIQUE INDEX idx_labs_runtime_tenant_unique ON labs(runtime_tenant);
    END IF;
END;
$$;

ALTER TABLE labs
  ALTER COLUMN runtime_tenant SET NOT NULL;

ALTER TABLE lab_nodes
  ADD COLUMN IF NOT EXISTS runtime_node_id INTEGER;

WITH ranked AS (
  SELECT id,
         ROW_NUMBER() OVER (PARTITION BY lab_id ORDER BY created_at ASC, id ASC) AS rn
  FROM lab_nodes
)
UPDATE lab_nodes n
SET runtime_node_id = ranked.rn
FROM ranked
WHERE n.id = ranked.id
  AND (n.runtime_node_id IS NULL OR n.runtime_node_id <= 0);

CREATE OR REPLACE FUNCTION assign_lab_node_runtime_id()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.runtime_node_id IS NULL OR NEW.runtime_node_id <= 0 THEN
        SELECT COALESCE(MAX(runtime_node_id), 0) + 1
        INTO NEW.runtime_node_id
        FROM lab_nodes
        WHERE lab_id = NEW.lab_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_assign_lab_node_runtime_id ON lab_nodes;
CREATE TRIGGER trg_assign_lab_node_runtime_id
BEFORE INSERT ON lab_nodes
FOR EACH ROW
EXECUTE FUNCTION assign_lab_node_runtime_id();

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'lab_nodes_runtime_node_id_positive_chk'
    ) THEN
        ALTER TABLE lab_nodes
          ADD CONSTRAINT lab_nodes_runtime_node_id_positive_chk
          CHECK (runtime_node_id > 0) NOT VALID;
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_indexes
        WHERE schemaname = 'public'
          AND indexname = 'idx_lab_nodes_runtime_per_lab_unique'
    ) THEN
        CREATE UNIQUE INDEX idx_lab_nodes_runtime_per_lab_unique ON lab_nodes(lab_id, runtime_node_id);
    END IF;
END;
$$;

ALTER TABLE lab_nodes
  ALTER COLUMN runtime_node_id SET NOT NULL;

ALTER TABLE lab_networks
  ADD COLUMN IF NOT EXISTS runtime_network_id INTEGER;

WITH ranked AS (
  SELECT id,
         ROW_NUMBER() OVER (PARTITION BY lab_id ORDER BY created_at ASC, id ASC) AS rn
  FROM lab_networks
)
UPDATE lab_networks n
SET runtime_network_id = ranked.rn
FROM ranked
WHERE n.id = ranked.id
  AND (n.runtime_network_id IS NULL OR n.runtime_network_id <= 0);

CREATE OR REPLACE FUNCTION assign_lab_network_runtime_id()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.runtime_network_id IS NULL OR NEW.runtime_network_id <= 0 THEN
        SELECT COALESCE(MAX(runtime_network_id), 0) + 1
        INTO NEW.runtime_network_id
        FROM lab_networks
        WHERE lab_id = NEW.lab_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_assign_lab_network_runtime_id ON lab_networks;
CREATE TRIGGER trg_assign_lab_network_runtime_id
BEFORE INSERT ON lab_networks
FOR EACH ROW
EXECUTE FUNCTION assign_lab_network_runtime_id();

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'lab_networks_runtime_network_id_positive_chk'
    ) THEN
        ALTER TABLE lab_networks
          ADD CONSTRAINT lab_networks_runtime_network_id_positive_chk
          CHECK (runtime_network_id > 0) NOT VALID;
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_indexes
        WHERE schemaname = 'public'
          AND indexname = 'idx_lab_networks_runtime_per_lab_unique'
    ) THEN
        CREATE UNIQUE INDEX idx_lab_networks_runtime_per_lab_unique ON lab_networks(lab_id, runtime_network_id);
    END IF;
END;
$$;

ALTER TABLE lab_networks
  ALTER COLUMN runtime_network_id SET NOT NULL;
