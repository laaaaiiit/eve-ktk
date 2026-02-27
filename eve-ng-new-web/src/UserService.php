<?php

declare(strict_types=1);

function roleNameIsAdmin(string $roleName): bool
{
    return strtolower(trim($roleName)) === 'admin';
}

function roleNameIsSystemProtected(string $roleName): bool
{
    $normalized = strtolower(trim($roleName));
    return in_array($normalized, ['admin', 'user'], true);
}

function activeUsersWithRolesManageCount(PDO $db): int
{
    $stmt = $db->query(
        "SELECT COUNT(*) FROM (
             SELECT u.id
             FROM users u
             INNER JOIN role_permissions rp ON rp.role_id = u.role_id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE u.is_blocked = FALSE
               AND LOWER(p.code) IN ('roles.manage', 'page.management.roles.view')
             GROUP BY u.id
             HAVING COUNT(DISTINCT LOWER(p.code)) = 2
         ) AS eligible"
    );
    return (int) $stmt->fetchColumn();
}

function assertRolesManageAccessRemaining(PDO $db): void
{
    if (activeUsersWithRolesManageCount($db) < 1) {
        throw new RuntimeException('roles_manage_lockout');
    }
}

function listRoles(PDO $db, bool $includeAdmin = true): array
{
    try {
        $sql = "SELECT r.id,
                       r.name,
                       COUNT(DISTINCT u.id) AS users_count,
                       COUNT(DISTINCT rp.permission_id) AS permissions_count
                FROM roles r
                LEFT JOIN users u ON u.role_id = r.id
                LEFT JOIN role_permissions rp ON rp.role_id = r.id
                GROUP BY r.id, r.name
                ORDER BY LOWER(r.name) ASC";
        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
    } catch (Throwable $e) {
        $sql = "SELECT r.id,
                       r.name,
                       COUNT(DISTINCT u.id) AS users_count
                FROM roles r
                LEFT JOIN users u ON u.role_id = r.id
                GROUP BY r.id, r.name
                ORDER BY LOWER(r.name) ASC";
        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
    }
    $mapped = array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'users_count' => (int) ($row['users_count'] ?? 0),
            'permissions_count' => (int) ($row['permissions_count'] ?? 0),
        ];
    }, $rows);

    if ($includeAdmin) {
        return $mapped;
    }

    return array_values(array_filter($mapped, static function (array $row): bool {
        return !roleNameIsAdmin((string) ($row['name'] ?? ''));
    }));
}

function getRoleById(PDO $db, string $roleId): ?array
{
    $stmt = $db->prepare('SELECT id, name FROM roles WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $roleId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    return [
        'id' => (string) ($row['id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
    ];
}

function roleIdIsAdmin(PDO $db, string $roleId): bool
{
    $role = getRoleById($db, $roleId);
    if ($role === null) {
        return false;
    }
    return roleNameIsAdmin((string) ($role['name'] ?? ''));
}

function normalizeRoleName(string $name): string
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 64) {
        throw new InvalidArgumentException('role_name_invalid');
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        throw new InvalidArgumentException('role_name_invalid');
    }
    return $name;
}

function roleNameExists(PDO $db, string $name, ?string $excludeRoleId = null): bool
{
    $sql = 'SELECT 1 FROM roles WHERE LOWER(name) = LOWER(:name)';
    if ($excludeRoleId !== null) {
        $sql .= ' AND id <> :exclude_id';
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    if ($excludeRoleId !== null) {
        $stmt->bindValue(':exclude_id', $excludeRoleId, PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function createRole(PDO $db, string $name): array
{
    $name = normalizeRoleName($name);
    if (roleNameExists($db, $name)) {
        throw new RuntimeException('role_name_exists');
    }

    $stmt = $db->prepare('INSERT INTO roles (name) VALUES (:name) RETURNING id, name');
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('role_create_failed');
    }
    return [
        'id' => (string) ($row['id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
    ];
}

function updateRoleName(PDO $db, string $roleId, string $name): array
{
    $name = normalizeRoleName($name);
    $role = getRoleById($db, $roleId);
    if ($role === null) {
        throw new RuntimeException('role_not_found');
    }
    if (roleNameExists($db, $name, $roleId)) {
        throw new RuntimeException('role_name_exists');
    }

    $stmt = $db->prepare('UPDATE roles SET name = :name WHERE id = :id RETURNING id, name');
    $stmt->bindValue(':id', $roleId, PDO::PARAM_STR);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('role_not_found');
    }
    return [
        'id' => (string) ($row['id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
    ];
}

function roleUsersCount(PDO $db, string $roleId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE role_id = :role_id');
    $stmt->bindValue(':role_id', $roleId, PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function deleteRoleById(PDO $db, string $roleId): bool
{
    $role = getRoleById($db, $roleId);
    if ($role === null) {
        throw new RuntimeException('role_not_found');
    }

    if (roleNameIsSystemProtected((string) ($role['name'] ?? ''))) {
        throw new RuntimeException('role_system_protected');
    }
    if (roleUsersCount($db, $roleId) > 0) {
        throw new RuntimeException('role_in_use');
    }

    $stmt = $db->prepare('DELETE FROM roles WHERE id = :id');
    $stmt->bindValue(':id', $roleId, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->rowCount() > 0;
}

function listPermissionCatalog(PDO $db): array
{
    $stmt = $db->query('SELECT id, code, title, category FROM permissions ORDER BY category ASC, code ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    return array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'code' => strtolower(trim((string) ($row['code'] ?? ''))),
            'title' => (string) ($row['title'] ?? ''),
            'category' => (string) ($row['category'] ?? 'general'),
        ];
    }, $rows);
}

function listRolePermissionIds(PDO $db, string $roleId): array
{
    $stmt = $db->prepare('SELECT permission_id FROM role_permissions WHERE role_id = :role_id');
    $stmt->bindValue(':role_id', $roleId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $ids = [];
    foreach ($rows as $row) {
        $id = trim((string) ($row['permission_id'] ?? ''));
        if ($id !== '') {
            $ids[$id] = true;
        }
    }
    return array_keys($ids);
}

function listRolePermissions(PDO $db, string $roleId): array
{
    $stmt = $db->prepare(
        "SELECT p.id, p.code, p.title, p.category
         FROM role_permissions rp
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = :role_id
         ORDER BY p.category ASC, p.code ASC"
    );
    $stmt->bindValue(':role_id', $roleId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    return array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'code' => strtolower(trim((string) ($row['code'] ?? ''))),
            'title' => (string) ($row['title'] ?? ''),
            'category' => (string) ($row['category'] ?? 'general'),
        ];
    }, $rows);
}

function normalizePermissionIds(array $permissionIds): array
{
    $result = [];
    foreach ($permissionIds as $id) {
        $id = strtolower(trim((string) $id));
        if ($id === '') {
            continue;
        }
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $id) !== 1) {
            throw new InvalidArgumentException('permission_id_invalid');
        }
        $result[$id] = true;
    }
    return array_keys($result);
}

function ensurePermissionsExist(PDO $db, array $permissionIds): void
{
    if (empty($permissionIds)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
    $sql = "SELECT id FROM permissions WHERE id IN ($placeholders)";
    $stmt = $db->prepare($sql);
    foreach (array_values($permissionIds) as $idx => $id) {
        $stmt->bindValue($idx + 1, $id, PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $found = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $id = strtolower(trim((string) ($row['id'] ?? '')));
            if ($id !== '') {
                $found[$id] = true;
            }
        }
    }
    foreach ($permissionIds as $id) {
        if (!isset($found[$id])) {
            throw new InvalidArgumentException('permission_id_invalid');
        }
    }
}

function setRolePermissionsByIds(PDO $db, string $roleId, array $permissionIds): array
{
    $role = getRoleById($db, $roleId);
    if ($role === null) {
        throw new RuntimeException('role_not_found');
    }

    $permissionIds = normalizePermissionIds($permissionIds);
    ensurePermissionsExist($db, $permissionIds);

    $db->beginTransaction();
    try {
        $deleteStmt = $db->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
        $deleteStmt->bindValue(':role_id', $roleId, PDO::PARAM_STR);
        $deleteStmt->execute();

        if (!empty($permissionIds)) {
            $insertStmt = $db->prepare(
                "INSERT INTO role_permissions (role_id, permission_id)
                 VALUES (:role_id, :permission_id)
                 ON CONFLICT (role_id, permission_id) DO NOTHING"
            );
            foreach ($permissionIds as $permissionId) {
                $insertStmt->bindValue(':role_id', $roleId, PDO::PARAM_STR);
                $insertStmt->bindValue(':permission_id', $permissionId, PDO::PARAM_STR);
                $insertStmt->execute();
            }
        }

        assertRolesManageAccessRemaining($db);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return listRolePermissions($db, $roleId);
}

function listUsers(PDO $db, bool $includeAdmin = true): array
{
    $sql = "SELECT u.id, u.username, u.role_id, r.name AS role, u.lang, u.theme, u.updated_at, u.is_blocked
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            ORDER BY u.id ASC";
    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }

    if ($includeAdmin) {
        return $rows;
    }

    return array_values(array_filter($rows, static function (array $row): bool {
        return !roleNameIsAdmin((string) ($row['role'] ?? ''));
    }));
}

function createUser(PDO $db, string $username, string $password, string $roleId, bool $isBlocked, string $lang, string $theme): array
{
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $sql = 'INSERT INTO users (username, password_hash, role_id, is_blocked, lang, theme) VALUES (:username, :password_hash, :role_id, :is_blocked, :lang, :theme) RETURNING id';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $stmt->bindValue(':password_hash', $hash, PDO::PARAM_STR);
    $stmt->bindValue(':role_id', $roleId, PDO::PARAM_STR);
    $stmt->bindValue(':is_blocked', $isBlocked, PDO::PARAM_BOOL);
    $stmt->bindValue(':lang', $lang, PDO::PARAM_STR);
    $stmt->bindValue(':theme', $theme, PDO::PARAM_STR);
    $stmt->execute();
    $created = $stmt->fetch();
    return $created === false ? [] : $created;
}

function updateUser(PDO $db, string $userId, string $roleId, bool $isBlocked, ?string $newPassword, string $lang, string $theme): bool
{
    $db->beginTransaction();
    try {
        if ($newPassword !== null && $newPassword !== '') {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $sql = 'UPDATE users SET role_id = :role_id, is_blocked = :is_blocked, password_hash = :password_hash, lang = :lang, theme = :theme, updated_at = NOW() WHERE id = :id';
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':password_hash', $hash, PDO::PARAM_STR);
        } else {
            $sql = 'UPDATE users SET role_id = :role_id, is_blocked = :is_blocked, lang = :lang, theme = :theme, updated_at = NOW() WHERE id = :id';
            $stmt = $db->prepare($sql);
        }

        $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':role_id', $roleId, PDO::PARAM_STR);
        $stmt->bindValue(':is_blocked', $isBlocked, PDO::PARAM_BOOL);
        $stmt->bindValue(':lang', $lang, PDO::PARAM_STR);
        $stmt->bindValue(':theme', $theme, PDO::PARAM_STR);
        $stmt->execute();

        assertRolesManageAccessRemaining($db);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return true;
}

function updateOwnPreferences(PDO $db, string $userId, string $lang, string $theme): bool
{
    $stmt = $db->prepare('UPDATE users SET lang = :lang, theme = :theme, updated_at = NOW() WHERE id = :id');
    $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
    $stmt->bindValue(':lang', $lang, PDO::PARAM_STR);
    $stmt->bindValue(':theme', $theme, PDO::PARAM_STR);
    $stmt->execute();
    return true;
}

function deleteUser(PDO $db, string $userId): bool
{
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
        $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        assertRolesManageAccessRemaining($db);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return true;
}

function getUserById(PDO $db, string $userId): ?array
{
    $sql = "SELECT u.id, u.username, u.role_id, r.name AS role, u.lang, u.theme, u.updated_at, u.is_blocked
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function userIdIsAdmin(PDO $db, string $userId): bool
{
    $user = getUserById($db, $userId);
    if ($user === null) {
        return false;
    }
    return roleNameIsAdmin((string) ($user['role'] ?? $user['role_name'] ?? ''));
}

function roleExists(PDO $db, string $roleId): bool
{
    $stmt = $db->prepare('SELECT 1 FROM roles WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $roleId, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function usernameExists(PDO $db, string $username): bool
{
    $stmt = $db->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}
