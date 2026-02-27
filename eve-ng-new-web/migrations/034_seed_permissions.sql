INSERT INTO permissions (code, title, category)
VALUES
    ('page.management.labmgmt.view', 'Open Lab Management page', 'page.management'),
    ('page.management.usermgmt.view', 'Open User Management page', 'page.management'),
    ('page.management.cloudmgmt.view', 'Open Cloud Management page', 'page.management'),
    ('page.management.roles.view', 'Open Roles page', 'page.management'),
    ('page.system.status.view', 'Open System Status page', 'page.system'),
    ('page.system.taskqueue.view', 'Open Task Queue page', 'page.system'),
    ('page.system.logs.view', 'Open System Logs page', 'page.system'),
    ('main.folder.create', 'Create folders in Main explorer', 'main'),
    ('main.lab.create', 'Create labs in Main explorer', 'main'),
    ('main.lab.publish', 'Publish labs (shared/collaboration)', 'main'),
    ('main.lab.share', 'Manage lab shared_with users', 'main'),
    ('cloudmgmt.mapping.manage', 'Create/edit/delete cloud mappings', 'cloudmgmt'),
    ('cloudmgmt.pnet.view_all', 'View all PNET from interfaces', 'cloudmgmt'),
    ('users.manage', 'Manage users and sessions', 'users'),
    ('roles.manage', 'Manage roles and role permissions', 'roles'),
    ('labmgmt.actions', 'Run lab management actions', 'labmgmt'),
    ('system.logs.read', 'Read system logs content', 'system')
ON CONFLICT (code) DO NOTHING;
