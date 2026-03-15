INSERT INTO permissions (code, title, category)
VALUES ('main.users.browse_all', 'Browse all user folders in Main explorer', 'main')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code = 'main.users.browse_all'
WHERE LOWER(r.name) = 'admin'
ON CONFLICT (role_id, permission_id) DO NOTHING;
