INSERT INTO permissions (id, code, title, category, created_at, updated_at)
VALUES (
    '7cf9420c-5153-433e-bac6-c9acded45779',
    'page.system.audit.view',
    'Open System Audit page',
    'page.system',
    NOW(),
    NOW()
)
ON CONFLICT (code) DO UPDATE
SET
    title = EXCLUDED.title,
    category = EXCLUDED.category,
    updated_at = NOW();

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code = 'page.system.audit.view'
WHERE LOWER(TRIM(r.name)) = 'admin'
ON CONFLICT (role_id, permission_id) DO NOTHING;
