<?php

declare(strict_types=1);

function rbacBuiltInPermissionDefinitions(): array
{
    return [
        ['code' => 'page.management.labmgmt.view', 'title' => 'Open Lab Management page', 'category' => 'page.management'],
        ['code' => 'page.management.usermgmt.view', 'title' => 'Open User Management page', 'category' => 'page.management'],
        ['code' => 'page.management.cloudmgmt.view', 'title' => 'Open Cloud Management page', 'category' => 'page.management'],
        ['code' => 'page.management.roles.view', 'title' => 'Open Roles page', 'category' => 'page.management'],
        ['code' => 'page.system.status.view', 'title' => 'Open System Status page', 'category' => 'page.system'],
        ['code' => 'page.system.taskqueue.view', 'title' => 'Open Task Queue page', 'category' => 'page.system'],
        ['code' => 'page.system.logs.view', 'title' => 'Open System Logs page', 'category' => 'page.system'],
        ['code' => 'page.system.audit.view', 'title' => 'Open System Audit page', 'category' => 'page.system'],
        ['code' => 'page.system.vm_console.view', 'title' => 'Open VM Console page', 'category' => 'page.system'],
        ['code' => 'system.vm_console.files.manage', 'title' => 'Manage VM console file transfer (upload/download/list)', 'category' => 'system'],
        ['code' => 'main.folder.create', 'title' => 'Create folders in Main explorer', 'category' => 'main'],
        ['code' => 'main.lab.create', 'title' => 'Create labs in Main explorer', 'category' => 'main'],
        ['code' => 'main.lab.export', 'title' => 'Export labs from Main explorer', 'category' => 'main'],
        ['code' => 'main.lab.import', 'title' => 'Import labs into Main explorer', 'category' => 'main'],
        ['code' => 'main.lab.publish', 'title' => 'Publish labs (shared/collaboration)', 'category' => 'main'],
        ['code' => 'main.lab.share', 'title' => 'Manage lab shared_with users', 'category' => 'main'],
        ['code' => 'main.lab.topology_lock.manage', 'title' => 'Manage topology lock and wipe policy for recipients', 'category' => 'main'],
        ['code' => 'main.users.browse_all', 'title' => 'Full access to all users labs', 'category' => 'main'],
        ['code' => 'cloudmgmt.mapping.manage', 'title' => 'Create/edit/delete cloud mappings', 'category' => 'cloudmgmt'],
        ['code' => 'cloudmgmt.pnet.view_all', 'title' => 'View all PNET cloud networks in Lab editor', 'category' => 'cloudmgmt'],
        ['code' => 'users.manage', 'title' => 'Manage users and sessions', 'category' => 'users'],
        ['code' => 'users.manage.non_admin', 'title' => 'Manage non-admin users and sessions', 'category' => 'users'],
        ['code' => 'roles.manage', 'title' => 'Manage roles and role permissions', 'category' => 'roles'],
        ['code' => 'labmgmt.actions', 'title' => 'Run lab management actions', 'category' => 'labmgmt'],
        ['code' => 'system.logs.read', 'title' => 'Read system logs content', 'category' => 'system'],
    ];
}

function rbacDefaultUserPermissionCodes(): array
{
    return [
        'page.system.status.view',
        'page.system.taskqueue.view',
        'main.folder.create',
        'main.lab.create',
    ];
}

function rbacRoleName(array $user): string
{
    return strtolower(trim((string) ($user['role_name'] ?? $user['role'] ?? '')));
}

function rbacUserHasGlobalLabsAccess(PDO $db, array $user): bool
{
    if (rbacRoleName($user) === 'admin') {
        return true;
    }
    return rbacUserHasPermission($db, $user, 'main.users.browse_all');
}

function rbacNormalizeCodes(array $codes): array
{
    $result = [];
    foreach ($codes as $code) {
        $normalized = strtolower(trim((string) $code));
        if ($normalized === '') {
            continue;
        }
        $result[$normalized] = true;
    }
    return array_keys($result);
}

function rbacListPermissionCatalog(PDO $db): array
{
    try {
        $stmt = $db->query('SELECT id, code, title, category FROM permissions ORDER BY category ASC, code ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows) && !empty($rows)) {
            return array_map(static function (array $row): array {
                return [
                    'id' => (string) ($row['id'] ?? ''),
                    'code' => strtolower(trim((string) ($row['code'] ?? ''))),
                    'title' => (string) ($row['title'] ?? ''),
                    'category' => (string) ($row['category'] ?? 'general'),
                ];
            }, $rows);
        }
    } catch (Throwable $e) {
        // Fallback below
    }

    $fallback = [];
    foreach (rbacBuiltInPermissionDefinitions() as $row) {
        $fallback[] = [
            'id' => '',
            'code' => strtolower(trim((string) ($row['code'] ?? ''))),
            'title' => (string) ($row['title'] ?? ''),
            'category' => (string) ($row['category'] ?? 'general'),
        ];
    }
    return $fallback;
}

function rbacListPermissionCodes(PDO $db): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $codes = [];
    foreach (rbacListPermissionCatalog($db) as $row) {
        $code = strtolower(trim((string) ($row['code'] ?? '')));
        if ($code !== '') {
            $codes[$code] = true;
        }
    }
    $cache = array_keys($codes);
    sort($cache, SORT_STRING);
    return $cache;
}

function rbacRolePermissionCodes(PDO $db, string $roleId): array
{
    $roleId = trim($roleId);
    if ($roleId === '') {
        return [];
    }

    static $cache = [];
    if (array_key_exists($roleId, $cache)) {
        return $cache[$roleId];
    }

    try {
        $stmt = $db->prepare(
            "SELECT p.code
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id"
        );
        $stmt->bindValue(':role_id', $roleId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $codes = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $code = strtolower(trim((string) ($row['code'] ?? '')));
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
        }
        $cache[$roleId] = array_keys($codes);
        sort($cache[$roleId], SORT_STRING);
        return $cache[$roleId];
    } catch (Throwable $e) {
        // Fallback below
    }

    $cache[$roleId] = [];
    return [];
}

function rbacUserPermissionCodes(PDO $db, array $user): array
{
    $roleName = rbacRoleName($user);
    $roleId = trim((string) ($user['role_id'] ?? ''));
    $cacheKey = trim((string) ($user['id'] ?? '')) . '|' . $roleId . '|' . $roleName;

    static $cache = [];
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if ($roleId !== '') {
        $codes = rbacRolePermissionCodes($db, $roleId);
        if (!empty($codes)) {
            $cache[$cacheKey] = rbacNormalizeCodes($codes);
            return $cache[$cacheKey];
        }
    }

    if ($roleName === 'user') {
        $cache[$cacheKey] = rbacDefaultUserPermissionCodes();
        return $cache[$cacheKey];
    }

    $cache[$cacheKey] = [];
    return [];
}

function rbacUserHasPermission(PDO $db, array $user, string $permissionCode): bool
{
    $permissionCode = strtolower(trim($permissionCode));
    if ($permissionCode === '') {
        return true;
    }
    $codes = rbacUserPermissionCodes($db, $user);
    return in_array($permissionCode, $codes, true);
}

function rbacUserHasAnyPermission(PDO $db, array $user, array $permissionCodes): bool
{
    foreach ($permissionCodes as $code) {
        if (rbacUserHasPermission($db, $user, (string) $code)) {
            return true;
        }
    }
    return false;
}

function rbacUserHasAllPermissions(PDO $db, array $user, array $permissionCodes): bool
{
    foreach ($permissionCodes as $code) {
        if (!rbacUserHasPermission($db, $user, (string) $code)) {
            return false;
        }
    }
    return true;
}
