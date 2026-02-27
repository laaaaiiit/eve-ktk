INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE LOWER(r.name) = 'admin'
ON CONFLICT (role_id, permission_id) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code IN (
    'page.system.status.view',
    'page.system.taskqueue.view',
    'main.folder.create',
    'main.lab.create'
)
WHERE LOWER(r.name) = 'user'
ON CONFLICT (role_id, permission_id) DO NOTHING;
