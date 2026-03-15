ALTER TABLE labs
    ADD COLUMN IF NOT EXISTS topology_locked BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS topology_allow_wipe BOOLEAN NOT NULL DEFAULT FALSE;

INSERT INTO permissions (code, title, category)
VALUES ('main.lab.topology_lock.manage', 'Manage topology lock and wipe policy for recipients', 'main')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code = 'main.lab.topology_lock.manage'
WHERE LOWER(r.name) = 'admin'
ON CONFLICT (role_id, permission_id) DO NOTHING;
