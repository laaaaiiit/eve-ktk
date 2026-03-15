INSERT INTO permissions (code, title, category)
VALUES
    ('main.lab.export', 'Export labs from Main explorer', 'main'),
    ('main.lab.import', 'Import labs into Main explorer', 'main')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code IN ('main.lab.export', 'main.lab.import')
WHERE LOWER(r.name) = 'admin'
ON CONFLICT (role_id, permission_id) DO NOTHING;
