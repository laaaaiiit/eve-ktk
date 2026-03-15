INSERT INTO permissions (code, title, category)
VALUES ('page.system.vm_console.view', 'Open VM Console page', 'page.system')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code = 'page.system.vm_console.view'
WHERE LOWER(r.name) = 'admin'
ON CONFLICT (role_id, permission_id) DO NOTHING;
