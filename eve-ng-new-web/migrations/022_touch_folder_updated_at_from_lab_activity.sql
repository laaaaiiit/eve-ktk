CREATE OR REPLACE FUNCTION touch_folder_ancestors(start_folder_id UUID)
RETURNS VOID AS $$
DECLARE
    current_id UUID;
BEGIN
    current_id := start_folder_id;
    WHILE current_id IS NOT NULL LOOP
        UPDATE lab_folders
        SET updated_at = NOW()
        WHERE id = current_id;

        SELECT parent_id
        INTO current_id
        FROM lab_folders
        WHERE id = current_id;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION trg_lab_folders_touch_parent()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.parent_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(NEW.parent_id);
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.parent_id IS DISTINCT FROM OLD.parent_id THEN
            IF OLD.parent_id IS NOT NULL THEN
                PERFORM touch_folder_ancestors(OLD.parent_id);
            END IF;
            IF NEW.parent_id IS NOT NULL THEN
                PERFORM touch_folder_ancestors(NEW.parent_id);
            END IF;
        ELSIF NEW.parent_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(NEW.parent_id);
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        IF OLD.parent_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(OLD.parent_id);
        END IF;
        RETURN OLD;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_lab_folders_touch_parent ON lab_folders;
CREATE TRIGGER trg_lab_folders_touch_parent
AFTER INSERT OR UPDATE OR DELETE ON lab_folders
FOR EACH ROW
EXECUTE FUNCTION trg_lab_folders_touch_parent();

CREATE OR REPLACE FUNCTION trg_labs_touch_folder_chain()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.folder_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(NEW.folder_id);
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.folder_id IS DISTINCT FROM OLD.folder_id THEN
            IF OLD.folder_id IS NOT NULL THEN
                PERFORM touch_folder_ancestors(OLD.folder_id);
            END IF;
            IF NEW.folder_id IS NOT NULL THEN
                PERFORM touch_folder_ancestors(NEW.folder_id);
            END IF;
        ELSIF NEW.folder_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(NEW.folder_id);
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        IF OLD.folder_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(OLD.folder_id);
        END IF;
        RETURN OLD;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_labs_touch_folder_chain ON labs;
CREATE TRIGGER trg_labs_touch_folder_chain
AFTER INSERT OR UPDATE OR DELETE ON labs
FOR EACH ROW
EXECUTE FUNCTION trg_labs_touch_folder_chain();

CREATE OR REPLACE FUNCTION trg_touch_labs_updated_from_activity()
RETURNS TRIGGER AS $$
DECLARE
    affected_lab UUID;
BEGIN
    IF TG_OP = 'DELETE' THEN
        affected_lab := OLD.lab_id;
    ELSE
        affected_lab := NEW.lab_id;
    END IF;

    IF affected_lab IS NOT NULL THEN
        UPDATE labs
        SET updated_at = NOW()
        WHERE id = affected_lab;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_lab_nodes_touch_labs_updated ON lab_nodes;
CREATE TRIGGER trg_lab_nodes_touch_labs_updated
AFTER INSERT OR UPDATE OR DELETE ON lab_nodes
FOR EACH ROW
EXECUTE FUNCTION trg_touch_labs_updated_from_activity();

DROP TRIGGER IF EXISTS trg_lab_networks_touch_labs_updated ON lab_networks;
CREATE TRIGGER trg_lab_networks_touch_labs_updated
AFTER INSERT OR UPDATE OR DELETE ON lab_networks
FOR EACH ROW
EXECUTE FUNCTION trg_touch_labs_updated_from_activity();

DROP TRIGGER IF EXISTS trg_lab_objects_touch_labs_updated ON lab_objects;
CREATE TRIGGER trg_lab_objects_touch_labs_updated
AFTER INSERT OR UPDATE OR DELETE ON lab_objects
FOR EACH ROW
EXECUTE FUNCTION trg_touch_labs_updated_from_activity();

CREATE OR REPLACE FUNCTION trg_touch_labs_updated_from_ports()
RETURNS TRIGGER AS $$
DECLARE
    affected_node UUID;
    affected_lab UUID;
BEGIN
    IF TG_OP = 'DELETE' THEN
        affected_node := OLD.node_id;
    ELSE
        affected_node := NEW.node_id;
    END IF;

    IF affected_node IS NULL THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    SELECT lab_id
    INTO affected_lab
    FROM lab_nodes
    WHERE id = affected_node;

    IF affected_lab IS NOT NULL THEN
        UPDATE labs
        SET updated_at = NOW()
        WHERE id = affected_lab;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_lab_node_ports_touch_labs_updated ON lab_node_ports;
CREATE TRIGGER trg_lab_node_ports_touch_labs_updated
AFTER INSERT OR UPDATE OR DELETE ON lab_node_ports
FOR EACH ROW
EXECUTE FUNCTION trg_touch_labs_updated_from_ports();
