UPDATE permissions
SET title = 'Full access to all users labs',
    category = 'main',
    updated_at = NOW()
WHERE code = 'main.users.browse_all';

UPDATE permissions
SET title = 'Manage VM console file transfer (upload/download/list)',
    category = 'system',
    updated_at = NOW()
WHERE code = 'system.vm_console.files.manage';
