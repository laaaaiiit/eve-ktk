INSERT INTO permissions (code, title, category)
VALUES
    ('users.manage.non_admin', 'Manage non-admin users and sessions', 'users')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code = 'users.manage.non_admin'
WHERE LOWER(r.name) = 'admin'
ON CONFLICT (role_id, permission_id) DO NOTHING;
