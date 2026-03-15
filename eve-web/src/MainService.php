<?php

declare(strict_types=1);

require_once __DIR__ . '/RbacService.php';

function getMainNodeStats(PDO $db, string $userId): array
{
    $sql = "SELECT
                COUNT(n.id) AS total_nodes,
                COUNT(n.id) FILTER (WHERE n.is_running = TRUE) AS running_nodes
            FROM labs l
            LEFT JOIN lab_nodes n ON n.lab_id = l.id
            WHERE l.author_user_id = :user_id";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_nodes' => (int) ($row['total_nodes'] ?? 0),
        'running_nodes' => (int) ($row['running_nodes'] ?? 0),
    ];
}

function mainDecodeBase64Json(string $encoded): array
{
    $encoded = trim($encoded);
    if ($encoded === '') {
        return [];
    }
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || $decoded === '') {
        return [];
    }
    $json = json_decode($decoded, true);
    if (!is_array($json)) {
        return [];
    }
    return $json;
}

function mainEncodeBase64Json(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return '';
    }
    return base64_encode($json);
}

function mainExportIncludeCheckSecrets(): bool
{
    $raw = strtolower(trim((string) getenv('EVE_EXPORT_INCLUDE_CHECK_SECRETS')));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function mainShiftTimestampByMicroseconds(string $value, int $offsetMicros): string
{
    $value = trim($value);
    if ($value === '' || $offsetMicros <= 0) {
        return $value;
    }
    try {
        $dt = new DateTimeImmutable($value);
        $seconds = intdiv($offsetMicros, 1000000);
        $micros = $offsetMicros % 1000000;
        $delta = sprintf('+%d seconds +%d microseconds', $seconds, $micros);
        $shifted = $dt->modify($delta);
        if ($shifted instanceof DateTimeImmutable) {
            return $shifted->format('Y-m-d H:i:s.uP');
        }
    } catch (Throwable $e) {
        return $value;
    }
    return $value;
}

function mainRemapLinkLayoutTail(string $tail, array $primaryMap, array $secondaryMap = []): string
{
    if ($tail === '') {
        return $tail;
    }
    if (isset($primaryMap[$tail])) {
        return (string) $primaryMap[$tail];
    }
    if (isset($secondaryMap[$tail])) {
        return (string) $secondaryMap[$tail];
    }
    $parts = explode(':', $tail, 2);
    $base = (string) ($parts[0] ?? '');
    $suffix = isset($parts[1]) ? (':' . $parts[1]) : '';
    if ($base !== '' && isset($primaryMap[$base])) {
        return (string) $primaryMap[$base] . $suffix;
    }
    if ($base !== '' && isset($secondaryMap[$base])) {
        return (string) $secondaryMap[$base] . $suffix;
    }
    return $tail;
}

function mainRemapLinkLayoutForCopy(array $layout, array $networkIdMap, array $portIdMap): array
{
    $labelsRaw = is_array($layout['labels'] ?? null) ? (array) $layout['labels'] : [];
    $bendsRaw = is_array($layout['bends'] ?? null) ? (array) $layout['bends'] : [];

    $labels = [];
    foreach ($labelsRaw as $keyRaw => $rowRaw) {
        if (!is_array($rowRaw)) {
            continue;
        }
        $key = (string) $keyRaw;
        if (stripos($key, 'lbl:') === 0) {
            $tail = substr($key, 4);
            $key = 'lbl:' . mainRemapLinkLayoutTail($tail, $portIdMap, $networkIdMap);
        }
        $labels[$key] = $rowRaw;
    }

    $bends = [];
    foreach ($bendsRaw as $keyRaw => $rowRaw) {
        if (!is_array($rowRaw)) {
            continue;
        }
        $key = (string) $keyRaw;
        if (stripos($key, 'net:') === 0) {
            $tail = substr($key, 4);
            $key = 'net:' . mainRemapLinkLayoutTail($tail, $networkIdMap);
        } elseif (stripos($key, 'att:') === 0) {
            $tail = substr($key, 4);
            $key = 'att:' . mainRemapLinkLayoutTail($tail, $portIdMap);
        }
        $bends[$key] = $rowRaw;
    }

    return [
        'labels' => $labels,
        'bends' => $bends,
    ];
}

function listShareTargetUsers(PDO $db, string $excludeUserId): array
{
    $sql = "SELECT u.id, u.username, r.name AS role_name
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.id <> :exclude_user_id
              AND u.is_blocked = FALSE
            ORDER BY LOWER(u.username) ASC";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':exclude_user_id', $excludeUserId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }

    return array_map(static function ($row) {
        return [
            'id' => (string) $row['id'],
            'username' => (string) $row['username'],
            'role' => (string) ($row['role_name'] ?? ''),
        ];
    }, $rows);
}

function mainSharedFolderName(): string
{
    return 'Shared';
}

function isReservedSharedFolderName(string $name): bool
{
    return strcasecmp(trim($name), mainSharedFolderName()) === 0;
}

function isSharedVirtualPath(string $path): bool
{
    return strcasecmp(normalizeMainPath($path), '/' . mainSharedFolderName()) === 0;
}

function listSharedPublishedLabsForUser(PDO $db, string $viewerUserId): array
{
    $sql = "SELECT l.id,
                   l.name,
                   l.description,
                   l.collaborate_allowed,
                   l.updated_at,
                   l.author_user_id,
                   u.username AS author_username,
                   EXISTS (
                     SELECT 1
                     FROM labs lc
                     WHERE lc.author_user_id = :viewer_id
                       AND lc.source_lab_id = l.id
                   ) AS has_local_copy
            FROM labs l
            INNER JOIN lab_shared_users su ON su.lab_id = l.id AND su.user_id = :viewer_id
            INNER JOIN users u ON u.id = l.author_user_id
            WHERE l.is_shared = TRUE
              AND l.author_user_id <> :viewer_id
            ORDER BY LOWER(l.name) ASC, l.created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':viewer_id', $viewerUserId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    return array_map(static function ($row) {
        $name = (string) ($row['name'] ?? '');
        return [
            'type' => 'lab',
            'id' => (string) ($row['id'] ?? ''),
            'name' => $name,
            'updated' => (string) ($row['updated_at'] ?? ''),
            'path' => '/' . mainSharedFolderName() . '/' . $name,
            'shared_entry' => true,
            'shared_author_user_id' => (string) ($row['author_user_id'] ?? ''),
            'shared_author_username' => (string) ($row['author_username'] ?? ''),
            'collaborate_allowed' => !empty($row['collaborate_allowed']),
            'has_local_copy' => !empty($row['has_local_copy']),
            'can_stop_nodes' => !empty($row['has_local_copy']),
            'description' => (string) ($row['description'] ?? ''),
        ];
    }, $rows);
}

function normalizeMainPath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || $path === '/') {
        return '/';
    }

    $parts = array_values(array_filter(explode('/', trim($path, '/')), static function ($v) {
        return $v !== '';
    }));
    foreach ($parts as $part) {
        if ($part === '.' || $part === '..') {
            throw new InvalidArgumentException('Invalid path');
        }
    }

    return '/' . implode('/', $parts);
}

function normalizeExplorerName(string $name): string
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 255) {
        throw new InvalidArgumentException('Invalid name');
    }
    if (strpos($name, '/') !== false || strpos($name, '\\') !== false || $name === '.' || $name === '..') {
        throw new InvalidArgumentException('Invalid name');
    }
    return $name;
}

function resolveFolderByPath(PDO $db, string $ownerUserId, string $path): ?array
{
    $virtualPath = normalizeMainPath($path);
    if ($virtualPath === '/') {
        return null;
    }

    $parts = explode('/', trim($virtualPath, '/'));
    $parentId = null;
    $current = null;

    foreach ($parts as $part) {
        if ($parentId === null) {
            $sql = "SELECT id, name
                    FROM lab_folders
                    WHERE owner_user_id = :owner_user_id
                      AND name = :name
                      AND parent_id IS NULL
                    LIMIT 1";
        } else {
            $sql = "SELECT id, name
                    FROM lab_folders
                    WHERE owner_user_id = :owner_user_id
                      AND name = :name
                      AND parent_id = :parent_id
                    LIMIT 1";
        }
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_STR);
        $stmt->bindValue(':name', $part, PDO::PARAM_STR);
        if ($parentId !== null) {
            $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Path not found');
        }
        $current = $row;
        $parentId = (string) $row['id'];
    }

    return $current;
}

function buildVirtualPath(PDO $db, string $folderId): string
{
    $parts = [];
    $cursor = $folderId;

    while ($cursor !== null) {
        $stmt = $db->prepare('SELECT id, parent_id, name FROM lab_folders WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $cursor, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            break;
        }
        array_unshift($parts, (string) $row['name']);
        $cursor = $row['parent_id'] ? (string) $row['parent_id'] : null;
    }

    return '/' . implode('/', $parts);
}

function listMainEntriesForUser(PDO $db, string $ownerUserId, string $path = '/'): array
{
    $virtualPath = normalizeMainPath($path);
    if (isSharedVirtualPath($virtualPath)) {
        return [
            'is_admin_root' => false,
            'current_path' => '/' . mainSharedFolderName(),
            'parent_path' => '/',
            'entries' => listSharedPublishedLabsForUser($db, $ownerUserId),
        ];
    }
    if (preg_match('#^/' . preg_quote(mainSharedFolderName(), '#') . '/#i', $virtualPath) === 1) {
        throw new RuntimeException('Path not found');
    }
    try {
        $folder = resolveFolderByPath($db, $ownerUserId, $virtualPath);
    } catch (RuntimeException $e) {
        if ($virtualPath !== '/') {
            throw $e;
        }
        $folder = null;
    }
    $parentId = $folder ? (string) $folder['id'] : null;

    if ($parentId === null) {
        $foldersSql = "SELECT id, name, updated_at
                       FROM lab_folders
                       WHERE owner_user_id = :owner_user_id
                         AND parent_id IS NULL
                       ORDER BY LOWER(name) ASC";
    } else {
        $foldersSql = "SELECT id, name, updated_at
                       FROM lab_folders
                       WHERE owner_user_id = :owner_user_id
                         AND parent_id = :parent_id
                       ORDER BY LOWER(name) ASC";
    }
    $foldersStmt = $db->prepare($foldersSql);
    $foldersStmt->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_STR);
    if ($parentId !== null) {
        $foldersStmt->bindValue(':parent_id', $parentId, PDO::PARAM_STR);
    }
    $foldersStmt->execute();
    $foldersRows = $foldersStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($foldersRows)) {
        $foldersRows = [];
    }

    if ($parentId === null) {
        $labsSql = "SELECT id, name, updated_at
                    FROM labs
                    WHERE author_user_id = :owner_user_id
                      AND folder_id IS NULL
                      AND source_lab_id IS NULL
                    ORDER BY LOWER(name) ASC";
    } else {
        $labsSql = "SELECT id, name, updated_at
                    FROM labs
                    WHERE author_user_id = :owner_user_id
                      AND folder_id = :folder_id
                      AND source_lab_id IS NULL
                    ORDER BY LOWER(name) ASC";
    }
    $labsStmt = $db->prepare($labsSql);
    $labsStmt->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_STR);
    if ($parentId !== null) {
        $labsStmt->bindValue(':folder_id', $parentId, PDO::PARAM_STR);
    }
    $labsStmt->execute();
    $labsRows = $labsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($labsRows)) {
        $labsRows = [];
    }

    $folders = array_map(static function ($row) use ($virtualPath) {
        $name = (string) $row['name'];
        return [
            'type' => 'folder',
            'id' => (string) $row['id'],
            'name' => $name,
            'updated' => (string) $row['updated_at'],
            'path' => ($virtualPath === '/' ? '' : $virtualPath) . '/' . $name,
        ];
    }, $foldersRows);

    if ($virtualPath === '/') {
        $folders = array_values(array_filter($folders, static function ($row) {
            return !isReservedSharedFolderName((string) ($row['name'] ?? ''));
        }));
    }

    $labs = array_map(static function ($row) use ($virtualPath) {
        $name = (string) $row['name'];
        return [
            'type' => 'lab',
            'id' => (string) $row['id'],
            'name' => $name,
            'updated' => (string) $row['updated_at'],
            'path' => ($virtualPath === '/' ? '' : $virtualPath) . '/' . $name,
            'can_stop_nodes' => true,
        ];
    }, $labsRows);

    $parentPath = '/';
    if ($folder !== null) {
        $stmt = $db->prepare('SELECT parent_id FROM lab_folders WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', (string) $folder['id'], PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $parentFolderId = $row['parent_id'] ?? null;
        if ($parentFolderId !== null) {
            $parentPath = buildVirtualPath($db, (string) $parentFolderId);
        }
    }

    $entries = array_merge($folders, $labs);
    if ($virtualPath === '/') {
        array_unshift($entries, [
            'type' => 'folder',
            'id' => 'system-shared',
            'name' => mainSharedFolderName(),
            'updated' => '',
            'path' => '/' . mainSharedFolderName(),
            'system_folder' => true,
            'description' => 'Published labs are shown here',
        ]);
    }

    return [
        'is_admin_root' => false,
        'current_path' => $virtualPath,
        'parent_path' => $parentPath,
        'entries' => $entries,
    ];
}

function createMainFolder(PDO $db, string $ownerUserId, string $path, string $name): array
{
    $virtualPath = normalizeMainPath($path);
    $name = normalizeExplorerName($name);
    if ($virtualPath === '/' && isReservedSharedFolderName($name)) {
        throw new InvalidArgumentException('Reserved folder name');
    }
    $parentFolder = resolveFolderByPath($db, $ownerUserId, $virtualPath);
    $parentId = $parentFolder ? (string) $parentFolder['id'] : null;

    $sql = "INSERT INTO lab_folders (owner_user_id, parent_id, name)
            VALUES (:owner_user_id, :parent_id, :name)
            RETURNING id, name, updated_at";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_STR);
    if ($parentId === null) {
        $stmt->bindValue(':parent_id', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_STR);
    }
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Failed to create folder');
    }

    return [
        'id' => (string) $row['id'],
        'type' => 'folder',
        'name' => (string) $row['name'],
        'updated' => (string) $row['updated_at'],
        'path' => ($virtualPath === '/' ? '' : $virtualPath) . '/' . (string) $row['name'],
    ];
}

function createMainLab(
    PDO $db,
    string $ownerUserId,
    string $path,
    string $name,
    ?string $description = null,
    bool $isShared = false,
    bool $collaborateAllowed = false,
    array $sharedWithUsernames = [],
    bool $topologyLocked = false,
    bool $topologyAllowWipe = false
): array
{
    $virtualPath = normalizeMainPath($path);
    $name = normalizeExplorerName($name);
    $description = $description === null ? null : trim($description);
    if ($description === '') {
        $description = null;
    }
    if ($collaborateAllowed && !$isShared) {
        throw new InvalidArgumentException('Shared lab is required for collaboration');
    }
    if (!$isShared && !empty($sharedWithUsernames)) {
        throw new InvalidArgumentException('Shared lab is required for user sharing');
    }

    $normalizedSharedUsers = [];
    foreach ($sharedWithUsernames as $rawUsername) {
        $candidate = trim((string) $rawUsername);
        if ($candidate === '') {
            continue;
        }
        $normalizedSharedUsers[strtolower($candidate)] = $candidate;
    }
    $normalizedSharedUsers = array_values($normalizedSharedUsers);

    $parentFolder = resolveFolderByPath($db, $ownerUserId, $virtualPath);
    $parentId = $parentFolder ? (string) $parentFolder['id'] : null;

    $db->beginTransaction();
    try {
        $sql = "INSERT INTO labs (name, description, author_user_id, folder_id, is_shared, is_mirror, collaborate_allowed, topology_locked, topology_allow_wipe)
                VALUES (:name, :description, :author_user_id, :folder_id, :is_shared, FALSE, :collaborate_allowed, :topology_locked, :topology_allow_wipe)
                RETURNING id, name, updated_at";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        if ($description === null) {
            $stmt->bindValue(':description', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':description', $description, PDO::PARAM_STR);
        }
        $stmt->bindValue(':author_user_id', $ownerUserId, PDO::PARAM_STR);
        if ($parentId === null) {
            $stmt->bindValue(':folder_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':folder_id', $parentId, PDO::PARAM_STR);
        }
        $stmt->bindValue(':is_shared', $isShared, PDO::PARAM_BOOL);
        $stmt->bindValue(':collaborate_allowed', $collaborateAllowed, PDO::PARAM_BOOL);
        $stmt->bindValue(':topology_locked', $topologyLocked, PDO::PARAM_BOOL);
        $stmt->bindValue(':topology_allow_wipe', $topologyAllowWipe, PDO::PARAM_BOOL);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Failed to create lab');
        }

        if ($isShared && !empty($normalizedSharedUsers)) {
            $resolveStmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1");
            $insertShareStmt = $db->prepare(
                "INSERT INTO lab_shared_users (lab_id, user_id)
                 SELECT :ins_lab_id, :ins_user_id
                 WHERE NOT EXISTS (
                     SELECT 1
                     FROM lab_shared_users
                     WHERE lab_id = :chk_lab_id
                       AND user_id = :chk_user_id
                 )"
            );
            foreach ($normalizedSharedUsers as $shareUsername) {
                $resolveStmt->bindValue(':username', $shareUsername, PDO::PARAM_STR);
                $resolveStmt->execute();
                $userRow = $resolveStmt->fetch(PDO::FETCH_ASSOC);
                if ($userRow === false) {
                    throw new InvalidArgumentException('Shared user not found: ' . $shareUsername);
                }
                $targetUserId = (string) $userRow['id'];
                if ($targetUserId === $ownerUserId) {
                    continue;
                }
                $insertShareStmt->bindValue(':ins_lab_id', (string) $row['id'], PDO::PARAM_STR);
                $insertShareStmt->bindValue(':ins_user_id', $targetUserId, PDO::PARAM_STR);
                $insertShareStmt->bindValue(':chk_lab_id', (string) $row['id'], PDO::PARAM_STR);
                $insertShareStmt->bindValue(':chk_user_id', $targetUserId, PDO::PARAM_STR);
                $insertShareStmt->execute();
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'id' => (string) $row['id'],
        'type' => 'lab',
        'name' => (string) $row['name'],
        'updated' => (string) $row['updated_at'],
        'path' => ($virtualPath === '/' ? '' : $virtualPath) . '/' . (string) $row['name'],
    ];
}

function findUserByUsername(PDO $db, string $username): ?array
{
    $stmt = $db->prepare("SELECT id, username, updated_at FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1");
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function parseAdminExplorerPath(string $path): array
{
    $virtualPath = normalizeMainPath($path);
    if ($virtualPath === '/' || $virtualPath === '/users') {
        return [
            'is_admin_root' => true,
            'username' => null,
            'relative_path' => '/',
            'prefix' => '/',
        ];
    }

    $parts = explode('/', trim($virtualPath, '/'));
    if (count($parts) < 2 || $parts[0] !== 'users') {
        throw new RuntimeException('Path not found');
    }

    $username = $parts[1];
    $rest = array_slice($parts, 2);
    $relative = '/' . implode('/', $rest);
    if ($relative === '/') {
        $relative = '/';
    }

    return [
        'is_admin_root' => false,
        'username' => $username,
        'relative_path' => normalizeMainPath($relative),
        'prefix' => '/users/' . $username,
    ];
}

function viewerCanBrowseAllMainUserFolders(PDO $db, array $viewer): bool
{
    if (viewerRoleName($viewer) === 'admin') {
        return true;
    }
    return rbacUserHasPermission($db, $viewer, 'main.users.browse_all');
}

function resolveMainWritePathForViewer(PDO $db, array $viewer, string $path): array
{
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new InvalidArgumentException('Invalid viewer');
    }

    $virtualPath = normalizeMainPath($path);
    if ($virtualPath !== '/users' && strpos($virtualPath, '/users/') !== 0) {
        return [
            'relative_path' => $virtualPath,
            'path_prefix' => '',
        ];
    }

    if (!viewerCanBrowseAllMainUserFolders($db, $viewer)) {
        throw new RuntimeException('Path not found');
    }

    $scope = parseAdminExplorerPath($virtualPath);
    if (!empty($scope['is_admin_root'])) {
        throw new InvalidArgumentException('Invalid path');
    }
    $target = findUserByUsername($db, (string) ($scope['username'] ?? ''));
    if ($target === null) {
        throw new RuntimeException('Path not found');
    }
    if ((string) ($target['id'] ?? '') !== $viewerId) {
        throw new RuntimeException('Forbidden');
    }

    return [
        'relative_path' => normalizeMainPath((string) ($scope['relative_path'] ?? '/')),
        'path_prefix' => (string) ($scope['prefix'] ?? ''),
    ];
}

function remapScopedPath(string $prefix, string $path, bool $forParent): string
{
    $path = normalizeMainPath($path);
    $prefix = normalizeMainPath($prefix);

    if ($forParent && $path === '/') {
        return '/';
    }
    if (!$forParent && $path === '/') {
        return $prefix;
    }

    return ($prefix === '/' ? '' : $prefix) . $path;
}

function listMainEntriesForViewer(PDO $db, array $viewer, string $path = '/'): array
{
    $role = strtolower(trim((string) ($viewer['role_name'] ?? $viewer['role'] ?? '')));
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        throw new InvalidArgumentException('Invalid viewer');
    }

    $virtualPath = normalizeMainPath($path);
    $canBrowseAllUserFolders = viewerCanBrowseAllMainUserFolders($db, $viewer);
    if (!$canBrowseAllUserFolders) {
        return listMainEntriesForUser($db, $viewerId, $virtualPath);
    }
    if ($role !== 'admin' && $virtualPath !== '/' && $virtualPath !== '/users' && strpos($virtualPath, '/users/') !== 0) {
        return listMainEntriesForUser($db, $viewerId, $virtualPath);
    }

    $scope = parseAdminExplorerPath($virtualPath);
    if ((bool) $scope['is_admin_root']) {
        $stmt = $db->query("SELECT id, username, updated_at FROM users ORDER BY LOWER(username) ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $entries = array_map(static function ($row) {
            $username = (string) $row['username'];
            return [
                'type' => 'folder',
                'id' => (string) $row['id'],
                'name' => $username,
                'updated' => (string) ($row['updated_at'] ?? ''),
                'path' => '/users/' . $username,
            ];
        }, $rows);

        return [
            'is_admin_root' => true,
            'current_path' => '/',
            'parent_path' => '/',
            'entries' => $entries,
        ];
    }

    $target = findUserByUsername($db, (string) $scope['username']);
    if ($target === null) {
        throw new RuntimeException('Path not found');
    }
    $isOwnScope = ((string) ($target['id'] ?? '') === $viewerId);

    $relativePath = (string) $scope['relative_path'];
    try {
        $data = listMainEntriesForUser($db, (string) $target['id'], $relativePath);
    } catch (RuntimeException $e) {
        throw new RuntimeException('Path not found');
    }
    $prefix = (string) $scope['prefix'];

    $entries = array_map(static function ($entry) use ($prefix, $role, $isOwnScope) {
        $entry['path'] = remapScopedPath($prefix, (string) ($entry['path'] ?? '/'), false);
        if (($entry['type'] ?? '') === 'lab' && !$isOwnScope) {
            $entry['can_stop_nodes'] = false;
        }
        if ($role !== 'admin' && !$isOwnScope) {
            $entry['can_manage'] = false;
        }
        return $entry;
    }, $data['entries']);

    $relativeCurrentPath = normalizeMainPath((string) ($data['current_path'] ?? '/'));
    $relativeParentPath = normalizeMainPath((string) ($data['parent_path'] ?? '/'));
    $currentPath = remapScopedPath($prefix, $relativeCurrentPath, false);
    if ($relativeParentPath === '/') {
        $parentPath = ($relativeCurrentPath === '/') ? '/' : normalizeMainPath($prefix);
    } else {
        $parentPath = remapScopedPath($prefix, $relativeParentPath, false);
    }

    return [
        'is_admin_root' => false,
        'current_path' => $currentPath,
        'parent_path' => $parentPath,
        'entries' => $entries,
    ];
}

function createMainFolderForViewer(PDO $db, array $viewer, string $path, string $name): array
{
    $role = strtolower(trim((string) ($viewer['role_name'] ?? $viewer['role'] ?? '')));
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        throw new InvalidArgumentException('Invalid viewer');
    }

    if ($role !== 'admin') {
        $writePath = resolveMainWritePathForViewer($db, $viewer, $path);
        return createMainFolder($db, $viewerId, (string) ($writePath['relative_path'] ?? '/'), $name);
    }

    $scope = parseAdminExplorerPath($path);
    if ((bool) $scope['is_admin_root']) {
        throw new InvalidArgumentException('Invalid path');
    }

    $target = findUserByUsername($db, (string) $scope['username']);
    if ($target === null) {
        throw new RuntimeException('Path not found');
    }

    $created = createMainFolder($db, (string) $target['id'], (string) $scope['relative_path'], $name);
    $created['path'] = remapScopedPath((string) $scope['prefix'], (string) $created['path'], false);
    return $created;
}

function createMainLabForViewer(
    PDO $db,
    array $viewer,
    string $path,
    string $name,
    ?string $description = null,
    bool $isShared = false,
    bool $collaborateAllowed = false,
    array $sharedWithUsernames = [],
    bool $topologyLocked = false,
    bool $topologyAllowWipe = false
): array
{
    $role = strtolower(trim((string) ($viewer['role_name'] ?? $viewer['role'] ?? '')));
    $viewerId = (string) ($viewer['id'] ?? '');
    $canPublish = rbacUserHasPermission($db, $viewer, 'main.lab.publish');
    $canShare = rbacUserHasPermission($db, $viewer, 'main.lab.share');
    $canTopologyLock = rbacUserHasPermission($db, $viewer, 'main.lab.topology_lock.manage');
    if ($viewerId === '') {
        throw new InvalidArgumentException('Invalid viewer');
    }

    if (($isShared || $collaborateAllowed) && !$canPublish) {
        throw new RuntimeException('main_publish_forbidden');
    }
    if (!empty($sharedWithUsernames) && !$canShare) {
        throw new RuntimeException('main_share_forbidden');
    }
    if (($topologyLocked || $topologyAllowWipe) && !$canTopologyLock) {
        throw new RuntimeException('main_topology_lock_forbidden');
    }

    if ($role !== 'admin') {
        $writePath = resolveMainWritePathForViewer($db, $viewer, $path);
        return createMainLab(
            $db,
            $viewerId,
            (string) ($writePath['relative_path'] ?? '/'),
            $name,
            $description,
            $isShared,
            $collaborateAllowed,
            $sharedWithUsernames,
            $topologyLocked,
            $topologyAllowWipe
        );
    }

    $scope = parseAdminExplorerPath($path);
    if ((bool) $scope['is_admin_root']) {
        throw new InvalidArgumentException('Invalid path');
    }

    $target = findUserByUsername($db, (string) $scope['username']);
    if ($target === null) {
        throw new RuntimeException('Path not found');
    }

    $created = createMainLab(
        $db,
        (string) $target['id'],
        (string) $scope['relative_path'],
        $name,
        $description,
        $isShared,
        $collaborateAllowed,
        $sharedWithUsernames,
        $topologyLocked,
        $topologyAllowWipe
    );
    $created['path'] = remapScopedPath((string) $scope['prefix'], (string) $created['path'], false);
    return $created;
}

function viewerRoleName(array $viewer): string
{
    return strtolower(trim((string) ($viewer['role_name'] ?? $viewer['role'] ?? '')));
}

function ensureMainEntryPermission(PDO $db, array $viewer, string $type, string $entryId): array
{
    $role = viewerRoleName($viewer);
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        throw new InvalidArgumentException('Invalid viewer');
    }

    if ($type === 'folder') {
        $stmt = $db->prepare('SELECT id, owner_user_id, name, updated_at FROM lab_folders WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $entryId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Entry not found');
        }
        if ($role !== 'admin' && (string) $row['owner_user_id'] !== $viewerId) {
            throw new RuntimeException('Forbidden');
        }
        return [
            'id' => (string) $row['id'],
            'owner_user_id' => (string) $row['owner_user_id'],
            'name' => (string) $row['name'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    if ($type === 'lab') {
        $stmt = $db->prepare('SELECT id, author_user_id, name, updated_at FROM labs WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $entryId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Entry not found');
        }
        if ($role !== 'admin' && (string) $row['author_user_id'] !== $viewerId) {
            throw new RuntimeException('Forbidden');
        }
        return [
            'id' => (string) $row['id'],
            'owner_user_id' => (string) $row['author_user_id'],
            'name' => (string) $row['name'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    throw new InvalidArgumentException('Invalid entry type');
}

function renameMainEntryForViewer(PDO $db, array $viewer, string $type, string $entryId, string $newName): array
{
    $newName = normalizeExplorerName($newName);
    if ($type === 'folder' && isReservedSharedFolderName($newName)) {
        throw new InvalidArgumentException('Reserved folder name');
    }
    $entry = ensureMainEntryPermission($db, $viewer, $type, $entryId);

    if ($type === 'folder') {
        $stmt = $db->prepare('UPDATE lab_folders SET name = :name WHERE id = :id RETURNING id, name, updated_at');
        $stmt->bindValue(':id', $entryId, PDO::PARAM_STR);
        $stmt->bindValue(':name', $newName, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Entry not found');
        }
        return [
            'id' => (string) $row['id'],
            'type' => 'folder',
            'name' => (string) $row['name'],
            'updated' => (string) $row['updated_at'],
        ];
    }

    $stmt = $db->prepare('UPDATE labs SET name = :name WHERE id = :id RETURNING id, name, updated_at');
    $stmt->bindValue(':id', $entryId, PDO::PARAM_STR);
    $stmt->bindValue(':name', $newName, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Entry not found');
    }
    return [
        'id' => (string) $row['id'],
        'type' => 'lab',
        'name' => (string) $row['name'],
        'updated' => (string) $row['updated_at'],
    ];
}

function moveMainLabForViewer(PDO $db, array $viewer, string $labId, string $destinationPath): array
{
    $entry = ensureMainEntryPermission($db, $viewer, 'lab', $labId);
    $scope = mainResolveImportScopeForViewer($db, $viewer, $destinationPath);
    $destinationOwnerId = trim((string) ($scope['owner_user_id'] ?? ''));
    $destinationRelativePath = (string) ($scope['relative_path'] ?? '/');
    $destinationPrefix = (string) ($scope['path_prefix'] ?? '');

    if ($destinationOwnerId === '') {
        throw new RuntimeException('Path not found');
    }
    if ($destinationOwnerId !== (string) ($entry['owner_user_id'] ?? '')) {
        throw new RuntimeException('Forbidden');
    }

    $destinationFolder = resolveFolderByPath($db, $destinationOwnerId, $destinationRelativePath);
    $destinationFolderId = $destinationFolder === null ? null : (string) ($destinationFolder['id'] ?? '');
    if ($destinationFolder !== null && $destinationFolderId === '') {
        throw new RuntimeException('Path not found');
    }

    $currentStmt = $db->prepare(
        "SELECT id, author_user_id, name, folder_id, updated_at
         FROM labs
         WHERE id = :id
         LIMIT 1"
    );
    $currentStmt->bindValue(':id', (string) $entry['id'], PDO::PARAM_STR);
    $currentStmt->execute();
    $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
    if ($current === false) {
        throw new RuntimeException('Entry not found');
    }
    $currentFolderId = isset($current['folder_id']) && $current['folder_id'] !== null
        ? trim((string) $current['folder_id'])
        : null;
    if ($currentFolderId === '') {
        $currentFolderId = null;
    }

    if ($currentFolderId === $destinationFolderId) {
        $basePath = $destinationRelativePath;
        $resultPath = ($basePath === '/' ? '' : $basePath) . '/' . (string) ($current['name'] ?? '');
        if ($destinationPrefix !== '') {
            $resultPath = remapScopedPath($destinationPrefix, $resultPath, false);
        }
        return [
            'id' => (string) ($current['id'] ?? ''),
            'type' => 'lab',
            'name' => (string) ($current['name'] ?? ''),
            'updated' => (string) ($current['updated_at'] ?? ''),
            'path' => $resultPath,
        ];
    }

    $existsSql = "SELECT 1
                  FROM labs
                  WHERE author_user_id = :owner_user_id
                    AND name = :name
                    AND id <> :id";
    if ($destinationFolderId === null) {
        $existsSql .= " AND folder_id IS NULL";
    } else {
        $existsSql .= " AND folder_id = :folder_id";
    }
    $existsSql .= " LIMIT 1";
    $existsStmt = $db->prepare($existsSql);
    $existsStmt->bindValue(':owner_user_id', $destinationOwnerId, PDO::PARAM_STR);
    $existsStmt->bindValue(':name', (string) ($current['name'] ?? ''), PDO::PARAM_STR);
    $existsStmt->bindValue(':id', (string) ($current['id'] ?? ''), PDO::PARAM_STR);
    if ($destinationFolderId !== null) {
        $existsStmt->bindValue(':folder_id', $destinationFolderId, PDO::PARAM_STR);
    }
    $existsStmt->execute();
    if ($existsStmt->fetchColumn() !== false) {
        throw new RuntimeException('Entry already exists in destination');
    }

    $updateStmt = $db->prepare(
        "UPDATE labs
         SET folder_id = :folder_id,
             updated_at = NOW()
         WHERE id = :id
         RETURNING id, name, updated_at"
    );
    if ($destinationFolderId === null) {
        $updateStmt->bindValue(':folder_id', null, PDO::PARAM_NULL);
    } else {
        $updateStmt->bindValue(':folder_id', $destinationFolderId, PDO::PARAM_STR);
    }
    $updateStmt->bindValue(':id', (string) ($current['id'] ?? ''), PDO::PARAM_STR);
    $updateStmt->execute();
    $updated = $updateStmt->fetch(PDO::FETCH_ASSOC);
    if ($updated === false) {
        throw new RuntimeException('Entry not found');
    }

    $basePath = $destinationRelativePath;
    $resultPath = ($basePath === '/' ? '' : $basePath) . '/' . (string) ($updated['name'] ?? '');
    if ($destinationPrefix !== '') {
        $resultPath = remapScopedPath($destinationPrefix, $resultPath, false);
    }

    return [
        'id' => (string) ($updated['id'] ?? ''),
        'type' => 'lab',
        'name' => (string) ($updated['name'] ?? ''),
        'updated' => (string) ($updated['updated_at'] ?? ''),
        'path' => $resultPath,
    ];
}

function getMainLabDetailsForViewer(PDO $db, array $viewer, string $labId): array
{
    $entry = ensureMainEntryPermission($db, $viewer, 'lab', $labId);

    $stmt = $db->prepare(
        "SELECT id, name, description, is_shared, collaborate_allowed, topology_locked, topology_allow_wipe, updated_at,
                (SELECT COUNT(*) FROM lab_nodes n WHERE n.lab_id = labs.id AND (n.is_running = TRUE OR n.power_state = 'running')) AS running_nodes_count
         FROM labs
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->bindValue(':id', (string) $entry['id'], PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Entry not found');
    }

    $sharedStmt = $db->prepare(
        "SELECT u.username
         FROM lab_shared_users su
         INNER JOIN users u ON u.id = su.user_id
         WHERE su.lab_id = :lab_id
         ORDER BY LOWER(u.username) ASC"
    );
    $sharedStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
    $sharedStmt->execute();
    $sharedRows = $sharedStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($sharedRows)) {
        $sharedRows = [];
    }
    $sharedWith = array_values(array_map(static function ($r) {
        return (string) ($r['username'] ?? '');
    }, $sharedRows));

    return [
        'id' => (string) $row['id'],
        'type' => 'lab',
        'name' => (string) $row['name'],
        'description' => (string) ($row['description'] ?? ''),
        'is_shared' => !empty($row['is_shared']),
        'collaborate_allowed' => !empty($row['collaborate_allowed']),
        'topology_locked' => !empty($row['topology_locked']),
        'topology_allow_wipe' => !empty($row['topology_allow_wipe']),
        'has_running_nodes' => ((int) ($row['running_nodes_count'] ?? 0)) > 0,
        'shared_with' => array_values(array_filter($sharedWith, static function ($v) {
            return trim((string) $v) !== '';
        })),
        'updated' => (string) ($row['updated_at'] ?? ''),
    ];
}

function updateMainLabForViewer(
    PDO $db,
    array $viewer,
    string $labId,
    string $name,
    ?string $description = null,
    bool $isShared = false,
    bool $collaborateAllowed = false,
    array $sharedWithUsernames = [],
    bool $topologyLocked = false,
    bool $topologyAllowWipe = false,
    ?callable $progressCallback = null
): array
{
    mainSafeProgressCallback($progressCallback, 'preparing', []);

    $entry = ensureMainEntryPermission($db, $viewer, 'lab', $labId);
    $canPublish = rbacUserHasPermission($db, $viewer, 'main.lab.publish');
    $canShare = rbacUserHasPermission($db, $viewer, 'main.lab.share');
    $canTopologyLock = rbacUserHasPermission($db, $viewer, 'main.lab.topology_lock.manage');

    $name = normalizeExplorerName($name);
    $description = $description === null ? null : trim($description);
    if ($description === '') {
        $description = null;
    }

    if (!$canPublish || !$canShare || !$canTopologyLock) {
        $currentStmt = $db->prepare(
            "SELECT is_shared, collaborate_allowed, topology_locked, topology_allow_wipe
             FROM labs
             WHERE id = :id
             LIMIT 1"
        );
        $currentStmt->bindValue(':id', (string) $entry['id'], PDO::PARAM_STR);
        $currentStmt->execute();
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
        if ($current === false) {
            throw new RuntimeException('Entry not found');
        }

        if (!$canPublish) {
            $isShared = !empty($current['is_shared']);
            $collaborateAllowed = !empty($current['collaborate_allowed']);
        }
        if (!$canShare) {
            if ($isShared) {
                $sharedStmt = $db->prepare(
                    "SELECT u.username
                     FROM lab_shared_users su
                     INNER JOIN users u ON u.id = su.user_id
                     WHERE su.lab_id = :lab_id
                     ORDER BY LOWER(u.username) ASC"
                );
                $sharedStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
                $sharedStmt->execute();
                $sharedRows = $sharedStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($sharedRows)) {
                    $sharedRows = [];
                }
                $sharedWithUsernames = array_values(array_filter(array_map(static function ($row) {
                    return trim((string) ($row['username'] ?? ''));
                }, $sharedRows), static function ($name) {
                    return $name !== '';
                }));
            } else {
                $sharedWithUsernames = [];
            }
        }
        if (!$canTopologyLock) {
            $topologyLocked = !empty($current['topology_locked']);
            $topologyAllowWipe = !empty($current['topology_allow_wipe']);
        }
    }

    if ($collaborateAllowed && !$isShared) {
        throw new InvalidArgumentException('Shared lab is required for collaboration');
    }
    if (!$isShared && !empty($sharedWithUsernames)) {
        throw new InvalidArgumentException('Shared lab is required for user sharing');
    }

    $normalizedSharedUsers = [];
    foreach ($sharedWithUsernames as $rawUsername) {
        $candidate = trim((string) $rawUsername);
        if ($candidate === '') {
            continue;
        }
        $normalizedSharedUsers[strtolower($candidate)] = $candidate;
    }
    $normalizedSharedUsers = array_values($normalizedSharedUsers);

    $currentShareStmt = $db->prepare(
        "SELECT su.user_id
         FROM lab_shared_users su
         WHERE su.lab_id = :lab_id"
    );
    $currentShareStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
    $currentShareStmt->execute();
    $currentShareRows = $currentShareStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($currentShareRows)) {
        $currentShareRows = [];
    }
    $currentSharedUserIds = [];
    foreach ($currentShareRows as $shareRow) {
        $shareUserId = trim((string) ($shareRow['user_id'] ?? ''));
        if ($shareUserId !== '') {
            $currentSharedUserIds[$shareUserId] = true;
        }
    }

    $targetSharedUsers = [];
    if ($isShared && !empty($normalizedSharedUsers)) {
        $resolveShareStmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1");
        foreach ($normalizedSharedUsers as $shareUsername) {
            $resolveShareStmt->bindValue(':username', $shareUsername, PDO::PARAM_STR);
            $resolveShareStmt->execute();
            $userRow = $resolveShareStmt->fetch(PDO::FETCH_ASSOC);
            if ($userRow === false) {
                throw new InvalidArgumentException('Shared user not found: ' . $shareUsername);
            }
            $targetUserId = trim((string) ($userRow['id'] ?? ''));
            if ($targetUserId === '' || $targetUserId === (string) $entry['owner_user_id']) {
                continue;
            }
            $targetSharedUsers[$targetUserId] = $shareUsername;
        }
    }

    $removedSharedUserIds = array_values(array_diff(array_keys($currentSharedUserIds), array_keys($targetSharedUsers)));

    $runningStmt = $db->prepare(
        "SELECT COUNT(*) AS cnt
         FROM lab_nodes
         WHERE lab_id = :lab_id
           AND (is_running = TRUE OR power_state = 'running')"
    );
    $runningStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
    $runningStmt->execute();
    $runningRow = $runningStmt->fetch(PDO::FETCH_ASSOC);
    $runningCount = (int) ($runningRow['cnt'] ?? 0);
    if ($runningCount > 0) {
        throw new RuntimeException('Lab has running nodes');
    }

    mainSafeProgressCallback($progressCallback, 'updating_metadata', []);
    $db->beginTransaction();
    try {
        $updateStmt = $db->prepare(
            "UPDATE labs
             SET name = :name,
                 description = :description,
                 is_shared = :is_shared,
                 collaborate_allowed = :collaborate_allowed,
                 topology_locked = :topology_locked,
                 topology_allow_wipe = :topology_allow_wipe,
                 updated_at = NOW()
             WHERE id = :id
             RETURNING id, name, updated_at"
        );
        $updateStmt->bindValue(':id', (string) $entry['id'], PDO::PARAM_STR);
        $updateStmt->bindValue(':name', $name, PDO::PARAM_STR);
        if ($description === null) {
            $updateStmt->bindValue(':description', null, PDO::PARAM_NULL);
        } else {
            $updateStmt->bindValue(':description', $description, PDO::PARAM_STR);
        }
        $updateStmt->bindValue(':is_shared', $isShared, PDO::PARAM_BOOL);
        $updateStmt->bindValue(':collaborate_allowed', $collaborateAllowed, PDO::PARAM_BOOL);
        $updateStmt->bindValue(':topology_locked', $topologyLocked, PDO::PARAM_BOOL);
        $updateStmt->bindValue(':topology_allow_wipe', $topologyAllowWipe, PDO::PARAM_BOOL);
        $updateStmt->execute();
        $row = $updateStmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Entry not found');
        }

        $clearShareStmt = $db->prepare("DELETE FROM lab_shared_users WHERE lab_id = :lab_id");
        $clearShareStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
        $clearShareStmt->execute();

        if ($isShared && !empty($targetSharedUsers)) {
            $insertShareStmt = $db->prepare(
                "INSERT INTO lab_shared_users (lab_id, user_id)
                 SELECT :ins_lab_id, :ins_user_id
                 WHERE NOT EXISTS (
                     SELECT 1
                     FROM lab_shared_users
                     WHERE lab_id = :chk_lab_id
                       AND user_id = :chk_user_id
                 )"
            );
            foreach (array_keys($targetSharedUsers) as $targetUserId) {
                $insertShareStmt->bindValue(':ins_lab_id', (string) $entry['id'], PDO::PARAM_STR);
                $insertShareStmt->bindValue(':ins_user_id', $targetUserId, PDO::PARAM_STR);
                $insertShareStmt->bindValue(':chk_lab_id', (string) $entry['id'], PDO::PARAM_STR);
                $insertShareStmt->bindValue(':chk_user_id', $targetUserId, PDO::PARAM_STR);
                $insertShareStmt->execute();
            }
        }

        // Mirror copies should inherit topology restriction policy.
        $syncCopiesStmt = $db->prepare(
            "UPDATE labs
             SET topology_locked = :topology_locked,
                 topology_allow_wipe = :topology_allow_wipe,
                 updated_at = NOW()
             WHERE source_lab_id = :source_lab_id"
        );
        $syncCopiesStmt->bindValue(':topology_locked', $topologyLocked, PDO::PARAM_BOOL);
        $syncCopiesStmt->bindValue(':topology_allow_wipe', $topologyAllowWipe, PDO::PARAM_BOOL);
        $syncCopiesStmt->bindValue(':source_lab_id', (string) $entry['id'], PDO::PARAM_STR);
        $syncCopiesStmt->execute();

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    mainSafeProgressCallback($progressCallback, 'metadata_updated', []);

    if (!empty($removedSharedUserIds)) {
        deleteLocalCopiesForSourceLabUsers(
            $db,
            (string) $entry['id'],
            $removedSharedUserIds,
            $progressCallback
        );
    } else {
        mainSafeProgressCallback($progressCallback, 'cleanup_stats', [
            'labs_total' => 0,
            'nodes_total' => 0,
        ]);
        mainSafeProgressCallback($progressCallback, 'cleanup_done', [
            'labs_deleted' => 0,
            'labs_total' => 0,
            'nodes_stopped' => 0,
            'nodes_total' => 0,
        ]);
    }

    return [
        'id' => (string) $row['id'],
        'type' => 'lab',
        'name' => (string) $row['name'],
        'updated' => (string) $row['updated_at'],
    ];
}

function mainResolveSharedSourceLabForViewer(PDO $db, array $viewer, string $sourceLabId): array
{
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $stmt = $db->prepare(
        "SELECT l.id,
                l.name,
                l.description,
                l.author_user_id,
                l.collaborate_allowed,
                l.is_shared,
                l.topology_locked,
                l.topology_allow_wipe
         FROM labs l
         INNER JOIN lab_shared_users su ON su.lab_id = l.id AND su.user_id = :viewer_id
         WHERE l.id = :lab_id
           AND l.is_shared = TRUE
           AND l.author_user_id <> :viewer_id
         LIMIT 1"
    );
    $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $sourceLabId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Shared lab not found');
    }
    return [
        'id' => (string) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'author_user_id' => (string) ($row['author_user_id'] ?? ''),
        'collaborate_allowed' => !empty($row['collaborate_allowed']),
        'topology_locked' => !empty($row['topology_locked']),
        'topology_allow_wipe' => !empty($row['topology_allow_wipe']),
    ];
}

function mainUniqueLabName(PDO $db, string $ownerUserId, string $baseName, ?string $folderId = null): string
{
    $baseName = trim($baseName);
    if ($baseName === '') {
        $baseName = 'lab-copy';
    }
    $name = $baseName;
    $suffix = 2;
    while (true) {
        $sql = "SELECT 1
                FROM labs
                WHERE author_user_id = :owner_user_id
                  AND name = :name";
        if ($folderId === null) {
            $sql .= " AND folder_id IS NULL";
        } else {
            $sql .= " AND folder_id = :folder_id";
        }
        $sql .= " LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_STR);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        if ($folderId !== null) {
            $stmt->bindValue(':folder_id', $folderId, PDO::PARAM_STR);
        }
        $stmt->execute();
        if ($stmt->fetchColumn() === false) {
            return $name;
        }
        $name = $baseName . ' (' . $suffix . ')';
        $suffix += 1;
    }
}

function mainIsCloudNetworkType(string $networkType): bool
{
    $value = strtolower(trim($networkType));
    if ($value === 'cloud') {
        return true;
    }
    return $value !== '' && preg_match('/^pnet[0-9]+$/', $value) === 1;
}

function mainViewerCloudProfiles(PDO $db, string $viewerUserId): array
{
    if ($viewerUserId === '') {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT c.name, c.pnet
         FROM cloud_users cu
         INNER JOIN clouds c ON c.id = cu.cloud_id
         WHERE cu.user_id = :user_id
         ORDER BY LOWER(c.name) ASC, c.pnet ASC"
    );
    $stmt->bindValue(':user_id', $viewerUserId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $result = [];
    foreach ($rows as $row) {
        $pnet = strtolower(trim((string) ($row['pnet'] ?? '')));
        if ($pnet === '' || preg_match('/^pnet[0-9]+$/', $pnet) !== 1) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = strtoupper($pnet);
        }
        $result[] = [
            'name' => $name,
            'pnet' => $pnet,
        ];
    }
    return $result;
}

function mainPickCloudProfileForCopy(array $cloudProfiles, array $cloudProfilesByPnet, string $sourceNetworkType, int &$rrIndex): ?array
{
    $sourceType = strtolower(trim($sourceNetworkType));
    if ($sourceType !== '' && isset($cloudProfilesByPnet[$sourceType]) && is_array($cloudProfilesByPnet[$sourceType]) && !empty($cloudProfilesByPnet[$sourceType])) {
        return $cloudProfilesByPnet[$sourceType][0];
    }
    if (empty($cloudProfiles)) {
        return null;
    }
    $index = $rrIndex;
    if ($index < 0) {
        $index = 0;
    }
    $picked = $cloudProfiles[$index % count($cloudProfiles)];
    $rrIndex = $index + 1;
    return is_array($picked) ? $picked : null;
}

function mainCopyDirectoryRecursive(string $sourceDir, string $targetDir): void
{
    if (!is_dir($sourceDir)) {
        return;
    }
    if (!@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Failed to create target directory: ' . $targetDir);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $sourcePath = $item->getPathname();
        $relative = substr($sourcePath, strlen(rtrim($sourceDir, '/')) + 1);
        if (!is_string($relative) || $relative === '') {
            continue;
        }
        $targetPath = rtrim($targetDir, '/') . '/' . $relative;
        if ($item->isDir()) {
            if (!@mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                throw new RuntimeException('Failed to create target directory: ' . $targetPath);
            }
            continue;
        }
        $base = strtolower((string) $item->getBasename());
        // Runtime PID/log files are volatile and may be unreadable for web user.
        // They are not part of node config state and should not abort copy.
        if (preg_match('/\.(pid|log)$/', $base) === 1) {
            continue;
        }
        if (!@is_readable($sourcePath)) {
            continue;
        }
        if (!@copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Failed to copy runtime file: ' . $sourcePath);
        }
    }
}

function mainRuntimeNodeSourceDir(string $labId, string $nodeId, string $ownerUserId): string
{
    $ownerSegment = normalizeRuntimeOwnerSegment($ownerUserId);
    if ($ownerSegment !== '') {
        $preferred = v2RuntimeNodeDir($labId, $nodeId, $ownerSegment);
        if (is_dir($preferred)) {
            return $preferred;
        }
    }
    $fallback = v2RuntimeNodeDir($labId, $nodeId, '');
    if (is_dir($fallback)) {
        return $fallback;
    }
    return '';
}

function mainRuntimeClearVolatileFiles(string $nodeDir): void
{
    if (!is_dir($nodeDir)) {
        return;
    }
    foreach (glob(rtrim($nodeDir, '/') . '/*.pid') ?: [] as $pidFile) {
        @unlink($pidFile);
    }
    foreach (glob(rtrim($nodeDir, '/') . '/*.log') ?: [] as $logFile) {
        @unlink($logFile);
    }
}

function getSharedLabLocalCopyForViewer(PDO $db, array $viewer, string $sourceLabId): ?array
{
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT id, name, updated_at
         FROM labs
         WHERE author_user_id = :viewer_id
           AND source_lab_id = :source_lab_id
         ORDER BY updated_at DESC
         LIMIT 1"
    );
    $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    $stmt->bindValue(':source_lab_id', $sourceLabId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    return [
        'id' => (string) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'updated' => (string) ($row['updated_at'] ?? ''),
    ];
}

function mainSafeProgressCallback(?callable $progressCallback, string $stage, array $payload = []): void
{
    if ($progressCallback === null) {
        return;
    }
    try {
        $progressCallback($stage, $payload);
    } catch (Throwable $e) {
        // Best-effort callback.
    }
}

function mainListSharedLocalCopyIdsForViewer(PDO $db, string $viewerId, string $sourceLabId): array
{
    $viewerId = trim($viewerId);
    $sourceLabId = trim($sourceLabId);
    if ($viewerId === '' || $sourceLabId === '') {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT id
         FROM labs
         WHERE author_user_id = :viewer_id
           AND source_lab_id = :source_lab_id
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    $stmt->bindValue(':source_lab_id', $sourceLabId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $ids = [];
    foreach ($rows as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    return mainNormalizeLabIds($ids);
}

function mainCountNodesForLabs(PDO $db, array $labIds): int
{
    $labIds = mainNormalizeLabIds($labIds);
    if (empty($labIds)) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($labIds), '?'));
    $stmt = $db->prepare("SELECT COUNT(*) FROM lab_nodes WHERE lab_id IN ($placeholders)");
    foreach ($labIds as $idx => $labId) {
        $stmt->bindValue($idx + 1, $labId, PDO::PARAM_STR);
    }
    $stmt->execute();
    return (int) ($stmt->fetchColumn() ?: 0);
}

function mainCountNodesForLab(PDO $db, string $labId): int
{
    $labId = trim($labId);
    if ($labId === '') {
        return 0;
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM lab_nodes WHERE lab_id = :lab_id");
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    return (int) ($stmt->fetchColumn() ?: 0);
}

function mainForceStopNodeForReset(PDO $db, string $labId, string $nodeId): void
{
    try {
        if (function_exists('stopLabNodeRuntime')) {
            stopLabNodeRuntime($db, $labId, $nodeId, true);
        }
    } catch (Throwable $e) {
        // Fall through to force cleanup path.
    }

    $runtimePid = 0;
    $nodeType = '';
    if (function_exists('runtimeLoadNodeContext')) {
        try {
            $ctx = runtimeLoadNodeContext($db, $labId, $nodeId);
            $runtimePid = (int) ($ctx['runtime_pid'] ?? 0);
            $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
        } catch (Throwable $e) {
            // Fallback to direct SQL below.
        }
    }
    if ($runtimePid <= 1 || $nodeType === '') {
        try {
            $stmt = $db->prepare(
                "SELECT runtime_pid, node_type
                 FROM lab_nodes
                 WHERE lab_id = :lab_id
                   AND id = :node_id
                 LIMIT 1"
            );
            $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
            $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $runtimePid = max($runtimePid, (int) ($row['runtime_pid'] ?? 0));
                if ($nodeType === '') {
                    $nodeType = strtolower(trim((string) ($row['node_type'] ?? '')));
                }
            }
        } catch (Throwable $e) {
            // Ignore SQL fallback failures.
        }
    }

    $targetPids = [];
    if ($runtimePid > 1) {
        $targetPids[] = $runtimePid;
    }
    if (function_exists('runtimeListNodePidsByEnv')) {
        try {
            $extraPids = runtimeListNodePidsByEnv($labId, $nodeId, [], false);
            if (is_array($extraPids)) {
                foreach ($extraPids as $extraPid) {
                    $extraPid = (int) $extraPid;
                    if ($extraPid > 1) {
                        $targetPids[] = $extraPid;
                    }
                }
            }
        } catch (Throwable $e) {
            // Ignore env-scan failures.
        }
    }
    $targetPids = array_values(array_unique(array_filter($targetPids, static function ($value): bool {
        return (int) $value > 1;
    })));
    if (!empty($targetPids) && function_exists('runtimeTerminateNodePids')) {
        try {
            runtimeTerminateNodePids($targetPids, 1.5);
        } catch (Throwable $e) {
            // Ignore force terminate failures.
        }
    }

    if (function_exists('setNodeStoppedState')) {
        try {
            setNodeStoppedState($db, $labId, $nodeId, null);
        } catch (Throwable $e) {
            // Ignore state update failures.
        }
    }
    if (function_exists('cleanupLabNodeRuntimeArtifacts')) {
        try {
            cleanupLabNodeRuntimeArtifacts($labId, $nodeId);
        } catch (Throwable $e) {
            // Ignore runtime cleanup failures.
        }
    }
}

function mainForceStopLabsForReset(PDO $db, array $labIds, ?callable $progressCallback = null): array
{
    $labIds = mainNormalizeLabIds($labIds);
    if (empty($labIds)) {
        mainSafeProgressCallback($progressCallback, 'stopping_old_nodes_start', ['current' => 0, 'total' => 0]);
        return ['total' => 0, 'stopped' => 0];
    }

    $placeholders = implode(',', array_fill(0, count($labIds), '?'));
    $stmt = $db->prepare(
        "SELECT lab_id, id
         FROM lab_nodes
         WHERE lab_id IN ($placeholders)
         ORDER BY created_at ASC, id ASC"
    );
    foreach ($labIds as $idx => $labId) {
        $stmt->bindValue($idx + 1, $labId, PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    $total = count($rows);
    $stopped = 0;
    mainSafeProgressCallback($progressCallback, 'stopping_old_nodes_start', ['current' => 0, 'total' => $total]);
    foreach ($rows as $row) {
        $labId = trim((string) ($row['lab_id'] ?? ''));
        $nodeId = trim((string) ($row['id'] ?? ''));
        if ($labId === '' || $nodeId === '') {
            continue;
        }
        mainForceStopNodeForReset($db, $labId, $nodeId);
        $stopped++;
        mainSafeProgressCallback($progressCallback, 'stopping_old_nodes_progress', [
            'current' => $stopped,
            'total' => $total,
            'lab_id' => $labId,
            'node_id' => $nodeId,
        ]);
    }

    return ['total' => $total, 'stopped' => $stopped];
}

function createSharedLabLocalCopyForViewer(
    PDO $db,
    array $viewer,
    string $sourceLabId,
    bool $forceReset = false,
    ?callable $progressCallback = null
): array
{
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    mainSafeProgressCallback($progressCallback, 'preparing', []);

    $sourceLab = mainResolveSharedSourceLabForViewer($db, $viewer, $sourceLabId);
    $existing = getSharedLabLocalCopyForViewer($db, $viewer, $sourceLabId);
    if ($existing !== null && !$forceReset) {
        mainSafeProgressCallback($progressCallback, 'done_existing', [
            'lab_id' => (string) $existing['id'],
            'lab_name' => (string) $existing['name'],
        ]);
        return [
            'mode' => 'local',
            'created' => false,
            'reset' => false,
            'lab_id' => (string) $existing['id'],
            'lab_name' => (string) $existing['name'],
            'source_lab_id' => $sourceLabId,
        ];
    }

    if ($existing !== null && $forceReset) {
        $existingLabIds = mainListSharedLocalCopyIdsForViewer($db, $viewerId, $sourceLabId);
        $oldLabsTotal = count($existingLabIds);
        mainForceStopLabsForReset($db, $existingLabIds, $progressCallback);
        mainSafeProgressCallback($progressCallback, 'deleting_old_copy_start', [
            'current' => 0,
            'total' => $oldLabsTotal,
        ]);
        $deleted = 0;
        foreach ($existingLabIds as $id) {
            deleteMainEntryForViewer($db, $viewer, 'lab', $id);
            $deleted++;
            mainSafeProgressCallback($progressCallback, 'deleting_old_copy_progress', [
                'current' => $deleted,
                'total' => $oldLabsTotal,
                'lab_id' => (string) $id,
            ]);
        }
    }

    $newLabId = '';
    $newLabName = '';
    $nodeMap = [];
    $sourceOwnerId = (string) ($sourceLab['author_user_id'] ?? '');
    mainSafeProgressCallback($progressCallback, 'cloning_lab_data_start', []);
    $db->beginTransaction();
    try {
        $baseName = trim((string) ($sourceLab['name'] ?? 'lab'));
        $copyName = mainUniqueLabName($db, $viewerId, $baseName, null);
        $insertLab = $db->prepare(
            "INSERT INTO labs (name, description, author_user_id, folder_id, is_shared, is_mirror, collaborate_allowed, source_lab_id, topology_locked, topology_allow_wipe)
             VALUES (:name, :description, :author_user_id, NULL, FALSE, TRUE, FALSE, :source_lab_id, :topology_locked, :topology_allow_wipe)
             RETURNING id, name"
        );
        $insertLab->bindValue(':name', $copyName, PDO::PARAM_STR);
        $insertLab->bindValue(':description', (string) ($sourceLab['description'] ?? ''), PDO::PARAM_STR);
        $insertLab->bindValue(':author_user_id', $viewerId, PDO::PARAM_STR);
        $insertLab->bindValue(':source_lab_id', $sourceLabId, PDO::PARAM_STR);
        $insertLab->bindValue(':topology_locked', !empty($sourceLab['topology_locked']), PDO::PARAM_BOOL);
        $insertLab->bindValue(':topology_allow_wipe', !empty($sourceLab['topology_allow_wipe']), PDO::PARAM_BOOL);
        $insertLab->execute();
        $labRow = $insertLab->fetch(PDO::FETCH_ASSOC);
        if ($labRow === false) {
            throw new RuntimeException('Failed to create local copy');
        }
        $newLabId = (string) ($labRow['id'] ?? '');
        $newLabName = (string) ($labRow['name'] ?? '');
        if ($newLabId === '') {
            throw new RuntimeException('Failed to create local copy');
        }

        $networkIdMap = [];
        $networkStmt = $db->prepare(
            "SELECT id, name, network_type, left_pos, top_pos, visibility, icon
             FROM lab_networks
             WHERE lab_id = :lab_id
             ORDER BY created_at ASC, id ASC"
        );
        $networkStmt->bindValue(':lab_id', $sourceLabId, PDO::PARAM_STR);
        $networkStmt->execute();
        $networkRows = $networkStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($networkRows)) {
            $networkRows = [];
        }
        $viewerCloudProfiles = mainViewerCloudProfiles($db, $viewerId);
        $viewerCloudProfilesByPnet = [];
        foreach ($viewerCloudProfiles as $profile) {
            $pnet = strtolower(trim((string) ($profile['pnet'] ?? '')));
            if ($pnet === '') {
                continue;
            }
            if (!isset($viewerCloudProfilesByPnet[$pnet])) {
                $viewerCloudProfilesByPnet[$pnet] = [];
            }
            $viewerCloudProfilesByPnet[$pnet][] = $profile;
        }
        $cloudRoundRobinIndex = 0;
        $insertNetwork = $db->prepare(
            "INSERT INTO lab_networks (lab_id, name, network_type, left_pos, top_pos, visibility, icon)
             VALUES (:lab_id, :name, :network_type, :left_pos, :top_pos, :visibility, :icon)
             RETURNING id"
        );
        foreach ($networkRows as $row) {
            $oldNetworkId = (string) ($row['id'] ?? '');
            if ($oldNetworkId === '') {
                continue;
            }
            $networkName = (string) ($row['name'] ?? '');
            $networkType = (string) ($row['network_type'] ?? '');
            if (mainIsCloudNetworkType($networkType)) {
                $pickedCloud = mainPickCloudProfileForCopy(
                    $viewerCloudProfiles,
                    $viewerCloudProfilesByPnet,
                    $networkType,
                    $cloudRoundRobinIndex
                );
                if ($pickedCloud !== null) {
                    $networkType = (string) ($pickedCloud['pnet'] ?? $networkType);
                    $pickedName = trim((string) ($pickedCloud['name'] ?? ''));
                    if ($pickedName !== '') {
                        $networkName = $pickedName;
                    }
                } else {
                    // Keep cloud object visible but unassigned to any concrete pnet.
                    $networkType = 'cloud';
                    if (trim($networkName) === '') {
                        $networkName = 'Cloud';
                    }
                }
            }
            $insertNetwork->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
            $insertNetwork->bindValue(':name', $networkName, PDO::PARAM_STR);
            $insertNetwork->bindValue(':network_type', $networkType, PDO::PARAM_STR);
            if ($row['left_pos'] === null) {
                $insertNetwork->bindValue(':left_pos', null, PDO::PARAM_NULL);
            } else {
                $insertNetwork->bindValue(':left_pos', (int) $row['left_pos'], PDO::PARAM_INT);
            }
            if ($row['top_pos'] === null) {
                $insertNetwork->bindValue(':top_pos', null, PDO::PARAM_NULL);
            } else {
                $insertNetwork->bindValue(':top_pos', (int) $row['top_pos'], PDO::PARAM_INT);
            }
            $insertNetwork->bindValue(':visibility', isset($row['visibility']) ? (int) $row['visibility'] : 1, PDO::PARAM_INT);
            $insertNetwork->bindValue(':icon', (string) ($row['icon'] ?? ''), PDO::PARAM_STR);
            $insertNetwork->execute();
            $newRow = $insertNetwork->fetch(PDO::FETCH_ASSOC);
            if ($newRow === false || empty($newRow['id'])) {
                throw new RuntimeException('Failed to clone networks');
            }
            $networkIdMap[$oldNetworkId] = (string) $newRow['id'];
        }

        $nodeStmt = $db->prepare(
            "SELECT id, name, node_type, template, image, icon, console,
                    left_pos, top_pos, delay_ms, ethernet_count, serial_count,
                    cpu, ram_mb, nvram_mb, first_mac, qemu_options, qemu_version,
                    qemu_arch, qemu_nic
             FROM lab_nodes
             WHERE lab_id = :lab_id
             ORDER BY created_at ASC, id ASC"
        );
        $nodeStmt->bindValue(':lab_id', $sourceLabId, PDO::PARAM_STR);
        $nodeStmt->execute();
        $nodeRows = $nodeStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($nodeRows)) {
            $nodeRows = [];
        }
        $insertNode = $db->prepare(
            "INSERT INTO lab_nodes (
                lab_id, name, node_type, template, image, icon, console,
                left_pos, top_pos, delay_ms, ethernet_count, serial_count,
                cpu, ram_mb, nvram_mb, first_mac, qemu_options, qemu_version,
                qemu_arch, qemu_nic, is_running, power_state, last_error, power_updated_at,
                runtime_pid, runtime_console_port, runtime_started_at, runtime_stopped_at
             )
             VALUES (
                :lab_id, :name, :node_type, :template, :image, :icon, :console,
                :left_pos, :top_pos, :delay_ms, :ethernet_count, :serial_count,
                :cpu, :ram_mb, :nvram_mb, :first_mac, :qemu_options, :qemu_version,
                :qemu_arch, :qemu_nic, FALSE, 'stopped', NULL, NOW(),
                NULL, NULL, NULL, NULL
             )
             RETURNING id"
        );
        foreach ($nodeRows as $row) {
            $oldNodeId = (string) ($row['id'] ?? '');
            if ($oldNodeId === '') {
                continue;
            }
            $insertNode->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
            $insertNode->bindValue(':name', (string) ($row['name'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':node_type', (string) ($row['node_type'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':template', (string) ($row['template'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':image', (string) ($row['image'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':icon', (string) ($row['icon'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':console', (string) ($row['console'] ?? ''), PDO::PARAM_STR);
            if ($row['left_pos'] === null) {
                $insertNode->bindValue(':left_pos', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':left_pos', (int) $row['left_pos'], PDO::PARAM_INT);
            }
            if ($row['top_pos'] === null) {
                $insertNode->bindValue(':top_pos', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':top_pos', (int) $row['top_pos'], PDO::PARAM_INT);
            }
            $insertNode->bindValue(':delay_ms', isset($row['delay_ms']) ? (int) $row['delay_ms'] : 0, PDO::PARAM_INT);
            if ($row['ethernet_count'] === null) {
                $insertNode->bindValue(':ethernet_count', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':ethernet_count', (int) $row['ethernet_count'], PDO::PARAM_INT);
            }
            if ($row['serial_count'] === null) {
                $insertNode->bindValue(':serial_count', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':serial_count', (int) $row['serial_count'], PDO::PARAM_INT);
            }
            if ($row['cpu'] === null) {
                $insertNode->bindValue(':cpu', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':cpu', (int) $row['cpu'], PDO::PARAM_INT);
            }
            if ($row['ram_mb'] === null) {
                $insertNode->bindValue(':ram_mb', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':ram_mb', (int) $row['ram_mb'], PDO::PARAM_INT);
            }
            if ($row['nvram_mb'] === null) {
                $insertNode->bindValue(':nvram_mb', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':nvram_mb', (int) $row['nvram_mb'], PDO::PARAM_INT);
            }
            $firstMac = isset($row['first_mac']) ? trim((string) $row['first_mac']) : '';
            if ($firstMac === '') {
                $insertNode->bindValue(':first_mac', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':first_mac', $firstMac, PDO::PARAM_STR);
            }
            $insertNode->bindValue(':qemu_options', (string) ($row['qemu_options'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':qemu_version', (string) ($row['qemu_version'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':qemu_arch', (string) ($row['qemu_arch'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':qemu_nic', (string) ($row['qemu_nic'] ?? ''), PDO::PARAM_STR);
            $insertNode->execute();
            $newNode = $insertNode->fetch(PDO::FETCH_ASSOC);
            if ($newNode === false || empty($newNode['id'])) {
                throw new RuntimeException('Failed to clone nodes');
            }
            $nodeMap[$oldNodeId] = (string) $newNode['id'];
        }

        $portIdMap = [];
        $portStmt = $db->prepare(
            "SELECT id, node_id, name, port_type, network_id, created_at, updated_at
             FROM lab_node_ports
             WHERE node_id IN (
                SELECT id FROM lab_nodes WHERE lab_id = :lab_id
             )
             ORDER BY created_at ASC, id ASC"
        );
        $portStmt->bindValue(':lab_id', $sourceLabId, PDO::PARAM_STR);
        $portStmt->execute();
        $portRows = $portStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($portRows)) {
            $portRows = [];
        }
        $insertPort = $db->prepare(
            "INSERT INTO lab_node_ports (node_id, name, port_type, network_id, created_at, updated_at)
             VALUES (:node_id, :name, :port_type, :network_id, :created_at, :updated_at)
             RETURNING id"
        );
        $portCloneOrder = 0;
        foreach ($portRows as $row) {
            $oldPortId = (string) ($row['id'] ?? '');
            $oldNodeId = (string) ($row['node_id'] ?? '');
            if ($oldNodeId === '' || !isset($nodeMap[$oldNodeId])) {
                continue;
            }
            $insertPort->bindValue(':node_id', $nodeMap[$oldNodeId], PDO::PARAM_STR);
            $insertPort->bindValue(':name', (string) ($row['name'] ?? ''), PDO::PARAM_STR);
            $insertPort->bindValue(':port_type', (string) ($row['port_type'] ?? ''), PDO::PARAM_STR);
            $oldNetworkId = isset($row['network_id']) ? (string) $row['network_id'] : '';
            if ($oldNetworkId !== '' && isset($networkIdMap[$oldNetworkId])) {
                $insertPort->bindValue(':network_id', $networkIdMap[$oldNetworkId], PDO::PARAM_STR);
            } else {
                $insertPort->bindValue(':network_id', null, PDO::PARAM_NULL);
            }
            $createdAt = isset($row['created_at']) ? trim((string) $row['created_at']) : '';
            if ($createdAt === '') {
                $insertPort->bindValue(':created_at', null, PDO::PARAM_NULL);
            } else {
                $createdAtShifted = mainShiftTimestampByMicroseconds($createdAt, $portCloneOrder);
                $insertPort->bindValue(':created_at', $createdAtShifted, PDO::PARAM_STR);
            }
            $updatedAt = isset($row['updated_at']) ? trim((string) $row['updated_at']) : '';
            if ($updatedAt === '') {
                $insertPort->bindValue(':updated_at', null, PDO::PARAM_NULL);
            } else {
                $insertPort->bindValue(':updated_at', $updatedAt, PDO::PARAM_STR);
            }
            $insertPort->execute();
            $portCloneOrder += 1;
            $newPort = $insertPort->fetch(PDO::FETCH_ASSOC);
            if ($newPort === false || empty($newPort['id'])) {
                throw new RuntimeException('Failed to clone ports');
            }
            if ($oldPortId !== '') {
                $portIdMap[$oldPortId] = (string) $newPort['id'];
            }
        }

        $objectStmt = $db->prepare(
            "SELECT object_type, name, data_base64
             FROM lab_objects
             WHERE lab_id = :lab_id
             ORDER BY created_at ASC, id ASC"
        );
        $objectStmt->bindValue(':lab_id', $sourceLabId, PDO::PARAM_STR);
        $objectStmt->execute();
        $objectRows = $objectStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($objectRows)) {
            $objectRows = [];
        }
        $insertObject = $db->prepare(
            "INSERT INTO lab_objects (lab_id, object_type, name, data_base64)
             VALUES (:lab_id, :object_type, :name, :data_base64)"
        );
        foreach ($objectRows as $row) {
            $objectType = (string) ($row['object_type'] ?? '');
            $objectName = (string) ($row['name'] ?? '');
            $dataBase64 = (string) ($row['data_base64'] ?? '');
            if (strtolower(trim($objectType)) === 'link_layout') {
                $layout = mainDecodeBase64Json($dataBase64);
                if (!empty($layout)) {
                    $remapped = mainRemapLinkLayoutForCopy($layout, $networkIdMap, $portIdMap);
                    $encoded = mainEncodeBase64Json($remapped);
                    if ($encoded !== '') {
                        $dataBase64 = $encoded;
                    }
                }
            }
            $insertObject->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
            $insertObject->bindValue(':object_type', $objectType, PDO::PARAM_STR);
            $insertObject->bindValue(':name', $objectName, PDO::PARAM_STR);
            $insertObject->bindValue(':data_base64', $dataBase64, PDO::PARAM_STR);
            $insertObject->execute();
        }

        if (function_exists('labCheckCloneConfigForLabCopy')) {
            labCheckCloneConfigForLabCopy($db, $sourceLabId, $newLabId, $nodeMap, $viewerId);
        }

        $db->commit();
        mainSafeProgressCallback($progressCallback, 'cloning_lab_data_done', [
            'runtime_total' => count($nodeMap),
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $runtimeTotal = count($nodeMap);
    $runtimeCurrent = 0;
    mainSafeProgressCallback($progressCallback, 'copying_runtime_start', [
        'current' => 0,
        'total' => $runtimeTotal,
    ]);
    foreach ($nodeMap as $oldNodeId => $newNodeId) {
        $sourceNodeDir = mainRuntimeNodeSourceDir($sourceLabId, (string) $oldNodeId, $sourceOwnerId);
        if ($sourceNodeDir === '') {
            $runtimeCurrent++;
            mainSafeProgressCallback($progressCallback, 'copying_runtime_progress', [
                'current' => $runtimeCurrent,
                'total' => $runtimeTotal,
                'old_node_id' => (string) $oldNodeId,
                'new_node_id' => (string) $newNodeId,
            ]);
            continue;
        }
        $targetNodeDir = v2RuntimeNodeDir($newLabId, (string) $newNodeId, $viewerId);
        try {
            mainCopyDirectoryRecursive($sourceNodeDir, $targetNodeDir);
            mainRuntimeClearVolatileFiles($targetNodeDir);
        } catch (Throwable $e) {
            // Best-effort runtime copy.
        }
        $runtimeCurrent++;
        mainSafeProgressCallback($progressCallback, 'copying_runtime_progress', [
            'current' => $runtimeCurrent,
            'total' => $runtimeTotal,
            'old_node_id' => (string) $oldNodeId,
            'new_node_id' => (string) $newNodeId,
        ]);
    }

    return [
        'mode' => 'local',
        'created' => true,
        'reset' => $forceReset,
        'lab_id' => $newLabId,
        'lab_name' => $newLabName,
        'source_lab_id' => $sourceLabId,
    ];
}

function openSharedLabCollaborativeForViewer(PDO $db, array $viewer, string $sourceLabId): array
{
    $sourceLab = mainResolveSharedSourceLabForViewer($db, $viewer, $sourceLabId);
    if (empty($sourceLab['collaborate_allowed'])) {
        throw new RuntimeException('Collaboration is disabled');
    }
    return [
        'mode' => 'collaborate',
        'lab_id' => (string) $sourceLab['id'],
        'source_lab_id' => (string) $sourceLab['id'],
    ];
}

function listLocalWorksForSourceLab(PDO $db, array $viewer, string $sourceLabId): array
{
    if (!rbacUserHasPermission($db, $viewer, 'users.manage')) {
        throw new RuntimeException('Forbidden');
    }

    $sourceStmt = $db->prepare(
        "SELECT id, author_user_id
         FROM labs
         WHERE id = :id
         LIMIT 1"
    );
    $sourceStmt->bindValue(':id', $sourceLabId, PDO::PARAM_STR);
    $sourceStmt->execute();
    $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);
    if ($source === false) {
        throw new RuntimeException('Entry not found');
    }

    $stmt = $db->prepare(
        "SELECT l.id, l.name, l.updated_at, l.created_at, u.username
         FROM labs l
         INNER JOIN users u ON u.id = l.author_user_id
         WHERE l.source_lab_id = :source_lab_id
         ORDER BY LOWER(u.username) ASC, l.updated_at DESC"
    );
    $stmt->bindValue(':source_lab_id', $sourceLabId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    return array_map(static function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $rows);
}

function mainStopLabNodesByLabId(PDO $db, array $viewer, string $labId): array
{
    $stmt = $db->prepare(
        "SELECT id::text AS id
         FROM lab_nodes
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    $total = 0;
    $done = 0;
    $queued = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];

    foreach ($rows as $row) {
        $nodeId = trim((string) ($row['id'] ?? ''));
        if ($nodeId === '') {
            continue;
        }
        $total += 1;
        try {
            $result = enqueueLabNodePowerTask($db, $viewer, $labId, $nodeId, 'stop');
            if (!empty($result['queued'])) {
                $queued += 1;
                $done += 1;
                continue;
            }
            $reason = strtolower(trim((string) ($result['reason'] ?? '')));
            if ($reason === 'already_in_target_state' || $reason === 'task_in_progress') {
                $skipped += 1;
                continue;
            }
            $done += 1;
        } catch (Throwable $e) {
            $failed += 1;
            if (count($errors) < 10) {
                $errors[] = [
                    'node_id' => $nodeId,
                    'message' => (string) $e->getMessage(),
                ];
            }
        }
    }

    return [
        'lab_id' => $labId,
        'action' => 'stop',
        'total' => $total,
        'done' => $done,
        'queued' => $queued,
        'skipped' => $skipped,
        'failed' => $failed,
        'errors' => $errors,
    ];
}

function stopMainLabNodesForViewer(PDO $db, array $viewer, string $labId): array
{
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $stmt = $db->prepare(
        "SELECT id, name, author_user_id
         FROM labs
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->bindValue(':id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Entry not found');
    }
    if ((string) ($row['author_user_id'] ?? '') !== $viewerId) {
        throw new RuntimeException('Forbidden');
    }

    $result = mainStopLabNodesByLabId($db, $viewer, (string) $row['id']);
    $result['lab_name'] = (string) ($row['name'] ?? '');
    $result['mode'] = 'owner';
    return $result;
}

function stopSharedLabLocalCopyNodesForViewer(PDO $db, array $viewer, string $sourceLabId): array
{
    $sourceLab = mainResolveSharedSourceLabForViewer($db, $viewer, $sourceLabId);
    $localCopy = getSharedLabLocalCopyForViewer($db, $viewer, $sourceLabId);
    if ($localCopy === null || empty($localCopy['id'])) {
        throw new RuntimeException('Local copy not found');
    }

    $result = mainStopLabNodesByLabId($db, $viewer, (string) $localCopy['id']);
    $result['lab_name'] = (string) ($localCopy['name'] ?? '');
    $result['source_lab_id'] = $sourceLabId;
    $result['source_lab_name'] = (string) ($sourceLab['name'] ?? '');
    $result['mode'] = 'shared_local';
    return $result;
}

function listLabIdsForFolderTree(PDO $db, string $ownerUserId, string $folderId): array
{
    $stmt = $db->prepare(
        "WITH RECURSIVE folder_tree AS (
             SELECT id
             FROM lab_folders
             WHERE id = :folder_id
           UNION ALL
             SELECT f.id
             FROM lab_folders f
             INNER JOIN folder_tree t ON t.id = f.parent_id
         )
         SELECT l.id
         FROM labs l
         WHERE l.author_user_id = :owner_user_id
           AND l.folder_id IN (SELECT id FROM folder_tree)
         ORDER BY l.created_at ASC, l.id ASC"
    );
    $stmt->bindValue(':folder_id', $folderId, PDO::PARAM_STR);
    $stmt->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $ids = [];
    foreach ($rows as $row) {
        $id = isset($row['id']) ? (string) $row['id'] : '';
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function listLabIdsBySourceAndUsers(PDO $db, string $sourceLabId, array $userIds): array
{
    $sourceLabId = trim($sourceLabId);
    if ($sourceLabId === '') {
        return [];
    }

    $normalizedUsers = [];
    foreach ($userIds as $userId) {
        $id = trim((string) $userId);
        if ($id !== '') {
            $normalizedUsers[$id] = true;
        }
    }
    if (empty($normalizedUsers)) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT id
         FROM labs
         WHERE source_lab_id = :source_lab_id
           AND author_user_id = :author_user_id
         ORDER BY created_at ASC, id ASC"
    );
    $ids = [];
    foreach (array_keys($normalizedUsers) as $userId) {
        $stmt->bindValue(':source_lab_id', $sourceLabId, PDO::PARAM_STR);
        $stmt->bindValue(':author_user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            $labId = trim((string) ($row['id'] ?? ''));
            if ($labId !== '') {
                $ids[$labId] = true;
            }
        }
    }

    return array_keys($ids);
}

function listLabIdsWithDerivedCopies(PDO $db, array $rootLabIds): array
{
    $depthByLab = [];
    $queue = [];
    foreach ($rootLabIds as $labId) {
        $id = trim((string) $labId);
        if ($id === '' || isset($depthByLab[$id])) {
            continue;
        }
        $depthByLab[$id] = 0;
        $queue[] = ['id' => $id, 'depth' => 0];
    }

    if (empty($queue)) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT id
         FROM labs
         WHERE source_lab_id = :source_lab_id
         ORDER BY created_at ASC, id ASC"
    );

    $idx = 0;
    while ($idx < count($queue)) {
        $current = $queue[$idx];
        $idx++;
        $currentId = (string) ($current['id'] ?? '');
        $currentDepth = (int) ($current['depth'] ?? 0);
        if ($currentId === '') {
            continue;
        }

        $stmt->bindValue(':source_lab_id', $currentId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            $childId = trim((string) ($row['id'] ?? ''));
            if ($childId === '') {
                continue;
            }
            $childDepth = $currentDepth + 1;
            $knownDepth = $depthByLab[$childId] ?? null;
            if ($knownDepth !== null && $knownDepth >= $childDepth) {
                continue;
            }
            $depthByLab[$childId] = $childDepth;
            $queue[] = ['id' => $childId, 'depth' => $childDepth];
        }
    }

    arsort($depthByLab, SORT_NUMERIC);
    return array_keys($depthByLab);
}

function mainNormalizeLabIds(array $labIds): array
{
    $result = [];
    foreach ($labIds as $labId) {
        $id = trim((string) $labId);
        if ($id === '') {
            continue;
        }
        $result[$id] = true;
    }
    return array_keys($result);
}

function countSharedLabsByIds(PDO $db, array $labIds): int
{
    $ids = mainNormalizeLabIds($labIds);
    if (empty($ids)) {
        return 0;
    }

    $params = [];
    $binds = [];
    foreach ($ids as $idx => $id) {
        $key = ':lab_' . $idx;
        $params[] = $key;
        $binds[$key] = $id;
    }

    $sql = "SELECT COUNT(*) AS c
            FROM labs
            WHERE is_shared = TRUE
              AND id IN (" . implode(', ', $params) . ")";
    $stmt = $db->prepare($sql);
    foreach ($binds as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['c'] ?? 0);
}

function countLabNodesByLabIds(PDO $db, array $labIds): int
{
    $ids = mainNormalizeLabIds($labIds);
    if (empty($ids)) {
        return 0;
    }

    $params = [];
    $binds = [];
    foreach ($ids as $idx => $id) {
        $key = ':lab_' . $idx;
        $params[] = $key;
        $binds[$key] = $id;
    }

    $sql = "SELECT COUNT(*) AS c
            FROM lab_nodes
            WHERE lab_id IN (" . implode(', ', $params) . ")";
    $stmt = $db->prepare($sql);
    foreach ($binds as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['c'] ?? 0);
}

function buildMainEntryDeleteScopeForEntry(PDO $db, string $type, string $entryId, array $entry): array
{
    $ownerUserId = trim((string) ($entry['owner_user_id'] ?? ''));
    $rootLabIds = [];

    if ($type === 'folder') {
        if ($ownerUserId === '') {
            throw new RuntimeException('Entry not found');
        }
        $rootLabIds = listLabIdsForFolderTree($db, $ownerUserId, $entryId);
    } elseif ($type === 'lab') {
        $rootLabIds = [$entryId];
    } else {
        throw new InvalidArgumentException('Invalid entry type');
    }

    $rootLabIds = mainNormalizeLabIds($rootLabIds);
    $orderedLabIds = empty($rootLabIds)
        ? []
        : listLabIdsWithDerivedCopies($db, $rootLabIds);
    $orderedLabIds = mainNormalizeLabIds($orderedLabIds);
    $rootCount = count($rootLabIds);
    $totalCount = count($orderedLabIds);

    return [
        'entry' => [
            'id' => (string) ($entry['id'] ?? ''),
            'owner_user_id' => $ownerUserId,
            'name' => (string) ($entry['name'] ?? ''),
            'updated_at' => (string) ($entry['updated_at'] ?? ''),
        ],
        'owner_user_id' => $ownerUserId,
        'root_lab_ids' => $rootLabIds,
        'ordered_lab_ids' => $orderedLabIds,
        'stats' => [
            'root_lab_count' => $rootCount,
            'total_lab_count' => $totalCount,
            'derived_copy_count' => max(0, $totalCount - $rootCount),
            'published_root_count' => countSharedLabsByIds($db, $rootLabIds),
            'total_node_count' => countLabNodesByLabIds($db, $orderedLabIds),
        ],
    ];
}

function buildMainEntryDeleteScopeForViewer(PDO $db, array $viewer, string $type, string $entryId): array
{
    $entry = ensureMainEntryPermission($db, $viewer, $type, $entryId);
    return buildMainEntryDeleteScopeForEntry($db, $type, $entryId, $entry);
}

function getLabOwnerUserIdByLabId(PDO $db, string $labId): string
{
    $stmt = $db->prepare(
        "SELECT author_user_id
         FROM labs
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->bindValue(':id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return '';
    }
    return trim((string) ($row['author_user_id'] ?? ''));
}

function markLabTasksAsDeleteFailed(PDO $db, string $labId): void
{
    try {
        $stmt = $db->prepare(
            "UPDATE lab_tasks
             SET status = 'failed',
                 error_text = CASE
                     WHEN COALESCE(error_text, '') = '' THEN 'Cancelled: lab is being deleted'
                     ELSE error_text
                 END,
                 finished_at = COALESCE(finished_at, NOW()),
                 updated_at = NOW()
             WHERE lab_id = :lab_id
               AND status IN ('pending', 'running')"
        );
        $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $stmt->execute();
    } catch (Throwable $e) {
        // Best-effort: keep deletion flow.
    }
}

function deleteSingleLabWithRuntimeCleanup(PDO $db, string $labId, ?callable $nodeProgressCallback = null): void
{
    $labId = trim($labId);
    if ($labId === '') {
        return;
    }

    $ownerUserId = getLabOwnerUserIdByLabId($db, $labId);
    if ($ownerUserId === '') {
        return;
    }

    $guardAcquired = false;
    try {
        if (function_exists('acquireLabDeletionGuard')) {
            $guardAcquired = acquireLabDeletionGuard($db, $labId);
            if (!$guardAcquired) {
                throw new RuntimeException('Lab delete is already in progress');
            }
        }

        markLabTasksAsDeleteFailed($db, $labId);
        stopAndCleanupLabRuntimeBeforeDelete($db, $labId, $ownerUserId, $nodeProgressCallback);

        $deleteStmt = $db->prepare('DELETE FROM labs WHERE id = :id');
        $deleteStmt->bindValue(':id', $labId, PDO::PARAM_STR);
        $deleteStmt->execute();
    } finally {
        if ($guardAcquired && function_exists('releaseLabDeletionGuard')) {
            releaseLabDeletionGuard($db, $labId);
        }
    }
}

function deleteLabsWithRuntimeCleanup(
    PDO $db,
    array $rootLabIds,
    ?callable $progressCallback = null,
    ?array $orderedLabIdsOverride = null,
    ?callable $nodeProgressCallback = null
): void {
    $orderedLabIds = is_array($orderedLabIdsOverride)
        ? mainNormalizeLabIds($orderedLabIdsOverride)
        : listLabIdsWithDerivedCopies($db, $rootLabIds);

    $total = count($orderedLabIds);
    $current = 0;
    foreach ($orderedLabIds as $labId) {
        deleteSingleLabWithRuntimeCleanup($db, (string) $labId, $nodeProgressCallback);
        $current++;
        if ($progressCallback !== null) {
            try {
                $progressCallback($current, $total, (string) $labId);
            } catch (Throwable $e) {
                // Best-effort callback should never break deletion.
            }
        }
    }
}

function deleteLocalCopiesForSourceLabUsers(
    PDO $db,
    string $sourceLabId,
    array $userIds,
    ?callable $progressCallback = null
): void
{
    $localCopyIds = listLabIdsBySourceAndUsers($db, $sourceLabId, $userIds);
    $orderedLabIds = empty($localCopyIds)
        ? []
        : listLabIdsWithDerivedCopies($db, $localCopyIds);
    $orderedLabIds = mainNormalizeLabIds($orderedLabIds);

    $labsTotal = count($orderedLabIds);
    $nodesTotal = countLabNodesByLabIds($db, $orderedLabIds);
    mainSafeProgressCallback($progressCallback, 'cleanup_stats', [
        'labs_total' => $labsTotal,
        'nodes_total' => $nodesTotal,
    ]);

    if ($labsTotal < 1) {
        mainSafeProgressCallback($progressCallback, 'cleanup_done', [
            'labs_deleted' => 0,
            'labs_total' => 0,
            'nodes_stopped' => 0,
            'nodes_total' => 0,
        ]);
        return;
    }

    $stoppedNodes = 0;
    if ($nodesTotal > 0) {
        mainSafeProgressCallback($progressCallback, 'cleanup_stopping_start', [
            'current' => 0,
            'total' => $nodesTotal,
        ]);
    }

    $nodeProgressCallback = null;
    if ($nodesTotal > 0 && $progressCallback !== null) {
        $nodeProgressCallback = static function (string $event, array $payload) use (&$stoppedNodes, $nodesTotal, $progressCallback): void {
            if ($event !== 'node') {
                return;
            }
            $stoppedNodes++;
            mainSafeProgressCallback($progressCallback, 'cleanup_stopping_progress', [
                'current' => max(0, min($nodesTotal, $stoppedNodes)),
                'total' => $nodesTotal,
                'lab_id' => (string) ($payload['lab_id'] ?? ''),
                'node_id' => (string) ($payload['node_id'] ?? ''),
            ]);
        };
    }

    mainSafeProgressCallback($progressCallback, 'cleanup_deleting_start', [
        'current' => 0,
        'total' => $labsTotal,
    ]);
    deleteLabsWithRuntimeCleanup(
        $db,
        $localCopyIds,
        static function (int $current, int $total, string $labId) use ($progressCallback): void {
            mainSafeProgressCallback($progressCallback, 'cleanup_deleting_progress', [
                'current' => $current,
                'total' => $total,
                'lab_id' => $labId,
            ]);
        },
        $orderedLabIds,
        $nodeProgressCallback
    );
    mainSafeProgressCallback($progressCallback, 'cleanup_done', [
        'labs_deleted' => $labsTotal,
        'labs_total' => $labsTotal,
        'nodes_stopped' => $nodesTotal,
        'nodes_total' => $nodesTotal,
    ]);
}

function stopAndCleanupLabRuntimeBeforeDelete(
    PDO $db,
    string $labId,
    string $ownerUserId = '',
    ?callable $nodeProgressCallback = null
): void
{
    $nodeStmt = $db->prepare(
        "SELECT id
         FROM lab_nodes
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC, id ASC"
    );
    $nodeStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $nodeStmt->execute();
    $nodeRows = $nodeStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($nodeRows)) {
        $nodeRows = [];
    }

    $nodesTotal = count($nodeRows);
    if ($nodeProgressCallback !== null) {
        try {
            $nodeProgressCallback('start', [
                'lab_id' => $labId,
                'total' => $nodesTotal,
            ]);
        } catch (Throwable $e) {
            // Best-effort callback should never break cleanup.
        }
    }

    $nodeIndex = 0;
    foreach ($nodeRows as $row) {
        $nodeId = isset($row['id']) ? (string) $row['id'] : '';
        if ($nodeId === '') {
            continue;
        }
        if (function_exists('stopLabNodeRuntime')) {
            try {
                stopLabNodeRuntime($db, $labId, $nodeId);
            } catch (Throwable $e) {
                // Keep deleting lab even if node process is already gone.
            }
        }
        if (function_exists('cleanupLabNodeRuntimeArtifacts')) {
            try {
                cleanupLabNodeRuntimeArtifacts($labId, $nodeId, $ownerUserId);
            } catch (Throwable $e) {
                // Best-effort cleanup.
            }
        }
        $nodeIndex++;
        if ($nodeProgressCallback !== null) {
            try {
                $nodeProgressCallback('node', [
                    'lab_id' => $labId,
                    'node_id' => $nodeId,
                    'current' => $nodeIndex,
                    'total' => $nodesTotal,
                ]);
            } catch (Throwable $e) {
                // Best-effort callback should never break cleanup.
            }
        }
    }

    if (function_exists('runtimeDeleteDirectoryTree') && function_exists('v2RuntimeLabDir')) {
        $paths = [];
        $ownerUserId = trim($ownerUserId);
        if ($ownerUserId !== '') {
            $paths[] = v2RuntimeLabDir($labId, $ownerUserId);
        }
        $paths[] = v2RuntimeLabDir($labId, '');
        $paths = array_values(array_unique(array_filter($paths, static function ($v) {
            return is_string($v) && $v !== '';
        })));
        foreach ($paths as $path) {
            try {
                runtimeDeleteDirectoryTree($path);
            } catch (Throwable $e) {
                // Best-effort cleanup.
            }
        }
    }
}

function deleteMainEntryForViewerWithScope(
    PDO $db,
    string $type,
    string $entryId,
    array $scope,
    ?callable $stageCallback = null
): void {
    $orderedLabIds = mainNormalizeLabIds((array) ($scope['ordered_lab_ids'] ?? []));
    $totalNodes = max(0, (int) (($scope['stats']['total_node_count'] ?? 0)));
    $globalStoppedNodes = 0;

    $nodeProgressCallback = null;
    if ($stageCallback !== null && $totalNodes > 0) {
        try {
            $stageCallback('stopping_nodes_start', [
                'current' => 0,
                'total' => $totalNodes,
            ]);
        } catch (Throwable $e) {
            // Best-effort callback.
        }
        $nodeProgressCallback = static function (string $event, array $payload) use (&$globalStoppedNodes, $totalNodes, $stageCallback): void {
            if ($event !== 'node' || $stageCallback === null) {
                return;
            }
            $globalStoppedNodes++;
            $current = max(0, min($totalNodes, $globalStoppedNodes));
            try {
                $stageCallback('stopping_nodes_progress', [
                    'current' => $current,
                    'total' => $totalNodes,
                    'lab_id' => (string) ($payload['lab_id'] ?? ''),
                    'node_id' => (string) ($payload['node_id'] ?? ''),
                ]);
            } catch (Throwable $e) {
                // Best-effort callback.
            }
        };
    }

    if (!empty($orderedLabIds)) {
        if ($stageCallback !== null) {
            try {
                $stageCallback('deleting_labs_start', [
                    'current' => 0,
                    'total' => count($orderedLabIds),
                ]);
            } catch (Throwable $e) {
                // Best-effort callback.
            }
        }
        deleteLabsWithRuntimeCleanup(
            $db,
            $orderedLabIds,
            static function (int $current, int $total, string $labId) use ($stageCallback): void {
                if ($stageCallback === null) {
                    return;
                }
                try {
                    $stageCallback('deleting_labs_progress', [
                        'current' => $current,
                        'total' => $total,
                        'lab_id' => $labId,
                    ]);
                } catch (Throwable $e) {
                    // Best-effort callback.
                }
            },
            $orderedLabIds,
            $nodeProgressCallback
        );
    }

    if ($type === 'folder') {
        if ($stageCallback !== null) {
            try {
                $stageCallback('deleting_folder', []);
            } catch (Throwable $e) {
                // Best-effort callback.
            }
        }
        $stmt = $db->prepare('DELETE FROM lab_folders WHERE id = :id');
        $stmt->bindValue(':id', $entryId, PDO::PARAM_STR);
        $stmt->execute();
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Entry not found');
        }
        return;
    }

    // Lab is already deleted in deleteLabsWithRuntimeCleanup().
}

function deleteMainEntryForViewer(PDO $db, array $viewer, string $type, string $entryId): void
{
    $scope = buildMainEntryDeleteScopeForViewer($db, $viewer, $type, $entryId);
    deleteMainEntryForViewerWithScope($db, $type, $entryId, $scope, null);
}

function mainAsyncPhpBinary(): string
{
    $candidates = [PHP_BINARY, '/usr/bin/php', '/usr/local/bin/php', 'php'];
    foreach ($candidates as $candidate) {
        if ($candidate === 'php') {
            return $candidate;
        }
        if ($candidate !== '' && @is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'php';
}

function mainDeleteProgressNow(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function mainDeleteProgressDir(): string
{
    $runtimeRoot = function_exists('v2RuntimeRootDir')
        ? (string) v2RuntimeRootDir()
        : '/opt/unetlab/data/v2-runtime';
    return rtrim($runtimeRoot, '/') . '/main-delete-progress';
}

function mainDeleteProgressEnsureDir(): string
{
    $dir = mainDeleteProgressDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to prepare delete progress directory');
    }
    @chmod($dir, 02775);
    return $dir;
}

function mainDeleteProgressNormalizeOperationId(string $operationId): string
{
    $normalized = strtolower(trim($operationId));
    if ($normalized === '') {
        return '';
    }
    if (preg_match('/^[a-z0-9._-]{8,128}$/', $normalized) !== 1) {
        return '';
    }
    return $normalized;
}

function mainDeleteProgressFilePath(string $operationId): string
{
    $safeId = mainDeleteProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    return rtrim(mainDeleteProgressDir(), '/') . '/' . $safeId . '.json';
}

function mainDeleteProgressRead(string $operationId): ?array
{
    $safeId = mainDeleteProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        return null;
    }
    $path = rtrim(mainDeleteProgressDir(), '/') . '/' . $safeId . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function mainDeleteProgressWrite(string $operationId, array $payload): array
{
    $safeId = mainDeleteProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    $dir = mainDeleteProgressEnsureDir();
    $path = rtrim($dir, '/') . '/' . $safeId . '.json';

    $payload['operation_id'] = $safeId;
    $payload['updated_at'] = mainDeleteProgressNow();

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode delete progress payload');
    }
    $tmpPath = $path . '.tmp.' . uniqid('', true);
    if (@file_put_contents($tmpPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to write delete progress payload');
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Failed to persist delete progress payload');
    }
    @chmod($path, 0664);

    return $payload;
}

function mainDeleteProgressPatch(string $operationId, array $changes): ?array
{
    $current = mainDeleteProgressRead($operationId);
    if (!is_array($current)) {
        return null;
    }
    $next = $current;
    foreach ($changes as $key => $value) {
        if ($key === 'progress' && is_array($value)) {
            $existing = is_array($next['progress'] ?? null) ? $next['progress'] : [];
            $next['progress'] = array_merge($existing, $value);
            continue;
        }
        if ($key === 'nodes' && is_array($value)) {
            $existing = is_array($next['nodes'] ?? null) ? $next['nodes'] : [];
            $next['nodes'] = array_merge($existing, $value);
            continue;
        }
        if ($key === 'stats' && is_array($value)) {
            $existing = is_array($next['stats'] ?? null) ? $next['stats'] : [];
            $next['stats'] = array_merge($existing, $value);
            continue;
        }
        $next[$key] = $value;
    }
    return mainDeleteProgressWrite($operationId, $next);
}

function mainDeleteProgressCleanupExpired(int $maxAgeSeconds = 172800): void
{
    if ($maxAgeSeconds < 60) {
        $maxAgeSeconds = 60;
    }
    $dir = mainDeleteProgressDir();
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
    foreach ($files as $path) {
        $mtime = @filemtime($path);
        if (!is_int($mtime)) {
            continue;
        }
        if (($now - $mtime) > $maxAgeSeconds) {
            @unlink($path);
        }
    }
}

function mainDeleteProgressGenerateOperationId(): string
{
    try {
        $suffix = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $suffix = substr(hash('sha256', uniqid('main_delete', true)), 0, 24);
    }
    return 'del_' . gmdate('YmdHis') . '_' . $suffix;
}

function mainDeleteProgressCreateForScope(array $viewer, string $type, string $entryId, array $scope): array
{
    mainDeleteProgressCleanupExpired();

    $operationId = mainDeleteProgressGenerateOperationId();
    $entry = is_array($scope['entry'] ?? null) ? $scope['entry'] : [];
    $stats = is_array($scope['stats'] ?? null) ? $scope['stats'] : [];

    $payload = [
        'operation_id' => $operationId,
        'type' => $type,
        'entry_id' => $entryId,
        'entry_name' => (string) ($entry['name'] ?? ''),
        'requested_by_user_id' => trim((string) ($viewer['id'] ?? '')),
        'requested_by_username' => trim((string) ($viewer['username'] ?? '')),
        'requested_at' => mainDeleteProgressNow(),
        'started_at' => null,
        'finished_at' => null,
        'status' => 'queued',
        'stage' => 'queued',
        'message' => 'Queued',
        'progress' => [
            'current' => 0,
            'total' => (int) ($stats['total_lab_count'] ?? 0),
        ],
        'nodes' => [
            'current' => 0,
            'total' => (int) ($stats['total_node_count'] ?? 0),
        ],
        'stats' => [
            'root_lab_count' => (int) ($stats['root_lab_count'] ?? 0),
            'total_lab_count' => (int) ($stats['total_lab_count'] ?? 0),
            'derived_copy_count' => (int) ($stats['derived_copy_count'] ?? 0),
            'published_root_count' => (int) ($stats['published_root_count'] ?? 0),
            'total_node_count' => (int) ($stats['total_node_count'] ?? 0),
        ],
    ];

    return mainDeleteProgressWrite($operationId, $payload);
}

function getMainDeleteProgressForViewer(PDO $db, array $viewer, string $operationId): array
{
    unset($db);

    $safeId = mainDeleteProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    $payload = mainDeleteProgressRead($safeId);
    if (!is_array($payload)) {
        throw new RuntimeException('Delete operation not found');
    }

    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }
    $requesterId = trim((string) ($payload['requested_by_user_id'] ?? ''));
    $role = viewerRoleName($viewer);
    if ($role !== 'admin' && $requesterId !== '' && $requesterId !== $viewerId) {
        throw new RuntimeException('Forbidden');
    }

    return $payload;
}

function queueMainEntryDeleteForViewer(PDO $db, array $viewer, string $type, string $entryId): array
{
    $scope = buildMainEntryDeleteScopeForViewer($db, $viewer, $type, $entryId);
    $entry = is_array($scope['entry'] ?? null) ? $scope['entry'] : [];
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $operation = mainDeleteProgressCreateForScope($viewer, $type, $entryId, $scope);
    $operationId = trim((string) ($operation['operation_id'] ?? ''));
    if ($operationId === '') {
        throw new RuntimeException('Failed to initialize delete progress');
    }

    $script = dirname(__DIR__) . '/bin/delete_main_entry_async.php';
    if (!is_file($script)) {
        mainDeleteProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'finished_at' => mainDeleteProgressNow(),
            'message' => 'Delete worker script is missing',
        ]);
        throw new RuntimeException('Delete worker script is missing');
    }

    $php = mainAsyncPhpBinary();
    $cmd = escapeshellarg($php)
        . ' ' . escapeshellarg($script)
        . ' --user-id=' . escapeshellarg($viewerId)
        . ' --type=' . escapeshellarg($type)
        . ' --entry-id=' . escapeshellarg($entryId)
        . ' --operation-id=' . escapeshellarg($operationId)
        . ' > /dev/null 2>&1 &';

    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    if ($rc !== 0) {
        mainDeleteProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'finished_at' => mainDeleteProgressNow(),
            'message' => 'Failed to queue delete task',
        ]);
        throw new RuntimeException('Failed to queue delete task');
    }

    return [
        'queued' => true,
        'type' => $type,
        'id' => $entryId,
        'name' => (string) ($entry['name'] ?? ''),
        'operation_id' => $operationId,
        'delete_progress' => $operation,
    ];
}

function mainLocalCopyProgressDir(): string
{
    $runtimeRoot = function_exists('v2RuntimeRootDir')
        ? (string) v2RuntimeRootDir()
        : '/opt/unetlab/data/v2-runtime';
    return rtrim($runtimeRoot, '/') . '/main-local-copy-progress';
}

function mainLocalCopyProgressEnsureDir(): string
{
    $dir = mainLocalCopyProgressDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to prepare local copy progress directory');
    }
    @chmod($dir, 02775);
    return $dir;
}

function mainLocalCopyProgressNormalizeOperationId(string $operationId): string
{
    $normalized = strtolower(trim($operationId));
    if ($normalized === '') {
        return '';
    }
    if (preg_match('/^[a-z0-9._-]{8,128}$/', $normalized) !== 1) {
        return '';
    }
    return $normalized;
}

function mainLocalCopyProgressRead(string $operationId): ?array
{
    $safeId = mainLocalCopyProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        return null;
    }
    $path = rtrim(mainLocalCopyProgressDir(), '/') . '/' . $safeId . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function mainLocalCopyProgressWrite(string $operationId, array $payload): array
{
    $safeId = mainLocalCopyProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    $dir = mainLocalCopyProgressEnsureDir();
    $path = rtrim($dir, '/') . '/' . $safeId . '.json';

    $payload['operation_id'] = $safeId;
    $payload['updated_at'] = mainDeleteProgressNow();

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode local copy progress payload');
    }
    $tmpPath = $path . '.tmp.' . uniqid('', true);
    if (@file_put_contents($tmpPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to write local copy progress payload');
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Failed to persist local copy progress payload');
    }
    @chmod($path, 0664);

    return $payload;
}

function mainLocalCopyProgressPatch(string $operationId, array $changes): ?array
{
    $current = mainLocalCopyProgressRead($operationId);
    if (!is_array($current)) {
        return null;
    }
    $next = $current;
    foreach ($changes as $key => $value) {
        if (in_array($key, ['progress', 'stats', 'result', 'stopping_old_nodes', 'deleting_old_copy', 'runtime_copy'], true) && is_array($value)) {
            $existing = is_array($next[$key] ?? null) ? $next[$key] : [];
            $next[$key] = array_merge($existing, $value);
            continue;
        }
        $next[$key] = $value;
    }
    return mainLocalCopyProgressWrite($operationId, $next);
}

function mainLocalCopyProgressCleanupExpired(int $maxAgeSeconds = 172800): void
{
    if ($maxAgeSeconds < 60) {
        $maxAgeSeconds = 60;
    }
    $dir = mainLocalCopyProgressDir();
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
    foreach ($files as $path) {
        $mtime = @filemtime($path);
        if (!is_int($mtime)) {
            continue;
        }
        if (($now - $mtime) > $maxAgeSeconds) {
            @unlink($path);
        }
    }
}

function mainLocalCopyProgressGenerateOperationId(): string
{
    try {
        $suffix = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $suffix = substr(hash('sha256', uniqid('main_local_copy', true)), 0, 24);
    }
    return 'lcopy_' . gmdate('YmdHis') . '_' . $suffix;
}

function mainLocalCopyProgressCreate(array $viewer, string $sourceLabId, bool $forceReset, array $stats = []): array
{
    mainLocalCopyProgressCleanupExpired();

    $operationId = mainLocalCopyProgressGenerateOperationId();
    $payload = [
        'operation_id' => $operationId,
        'source_lab_id' => $sourceLabId,
        'reset' => $forceReset,
        'requested_by_user_id' => trim((string) ($viewer['id'] ?? '')),
        'requested_by_username' => trim((string) ($viewer['username'] ?? '')),
        'requested_at' => mainDeleteProgressNow(),
        'started_at' => null,
        'finished_at' => null,
        'status' => 'queued',
        'stage' => 'queued',
        'message' => 'Queued',
        'progress' => [
            'current' => 0,
            'total' => max(1, (int) ($stats['progress_total'] ?? 1)),
        ],
        'stopping_old_nodes' => [
            'current' => 0,
            'total' => (int) ($stats['old_node_count'] ?? 0),
        ],
        'deleting_old_copy' => [
            'current' => 0,
            'total' => (int) ($stats['old_lab_count'] ?? 0),
        ],
        'runtime_copy' => [
            'current' => 0,
            'total' => (int) ($stats['source_node_count'] ?? 0),
        ],
        'stats' => [
            'old_lab_count' => (int) ($stats['old_lab_count'] ?? 0),
            'old_node_count' => (int) ($stats['old_node_count'] ?? 0),
            'source_node_count' => (int) ($stats['source_node_count'] ?? 0),
        ],
        'result' => [],
    ];

    return mainLocalCopyProgressWrite($operationId, $payload);
}

function getMainLocalCopyProgressForViewer(PDO $db, array $viewer, string $operationId): array
{
    unset($db);

    $safeId = mainLocalCopyProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    $payload = mainLocalCopyProgressRead($safeId);
    if (!is_array($payload)) {
        throw new RuntimeException('Local copy operation not found');
    }

    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }
    $requesterId = trim((string) ($payload['requested_by_user_id'] ?? ''));
    $role = viewerRoleName($viewer);
    if ($role !== 'admin' && $requesterId !== '' && $requesterId !== $viewerId) {
        throw new RuntimeException('Forbidden');
    }

    return $payload;
}

function queueSharedLabLocalCopyForViewer(PDO $db, array $viewer, string $sourceLabId, bool $forceReset = false): array
{
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    mainResolveSharedSourceLabForViewer($db, $viewer, $sourceLabId);
    $existingLabIds = mainListSharedLocalCopyIdsForViewer($db, $viewerId, $sourceLabId);
    $oldLabCount = count($existingLabIds);
    $oldNodeCount = mainCountNodesForLabs($db, $existingLabIds);
    $sourceNodeCount = mainCountNodesForLab($db, $sourceLabId);
    $progressTotal = 3 + ($forceReset ? ($oldNodeCount + $oldLabCount) : 0) + $sourceNodeCount;
    $operation = mainLocalCopyProgressCreate($viewer, $sourceLabId, $forceReset, [
        'old_lab_count' => $oldLabCount,
        'old_node_count' => $oldNodeCount,
        'source_node_count' => $sourceNodeCount,
        'progress_total' => $progressTotal,
    ]);
    $operationId = trim((string) ($operation['operation_id'] ?? ''));
    if ($operationId === '') {
        throw new RuntimeException('Failed to initialize local copy progress');
    }

    $script = dirname(__DIR__) . '/bin/create_shared_local_copy_async.php';
    if (!is_file($script)) {
        mainLocalCopyProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'finished_at' => mainDeleteProgressNow(),
            'message' => 'Local copy worker script is missing',
        ]);
        throw new RuntimeException('Local copy worker script is missing');
    }

    $php = mainAsyncPhpBinary();
    $cmd = escapeshellarg($php)
        . ' ' . escapeshellarg($script)
        . ' --user-id=' . escapeshellarg($viewerId)
        . ' --source-lab-id=' . escapeshellarg($sourceLabId)
        . ' --force-reset=' . escapeshellarg($forceReset ? '1' : '0')
        . ' --operation-id=' . escapeshellarg($operationId)
        . ' > /dev/null 2>&1 &';

    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    if ($rc !== 0) {
        mainLocalCopyProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'finished_at' => mainDeleteProgressNow(),
            'message' => 'Failed to queue local copy task',
        ]);
        throw new RuntimeException('Failed to queue local copy task');
    }

    return [
        'queued' => true,
        'source_lab_id' => $sourceLabId,
        'reset' => $forceReset,
        'operation_id' => $operationId,
        'local_copy_progress' => $operation,
    ];
}

function mainLabUpdateProgressDir(): string
{
    $runtimeRoot = function_exists('v2RuntimeRootDir')
        ? (string) v2RuntimeRootDir()
        : '/opt/unetlab/data/v2-runtime';
    return rtrim($runtimeRoot, '/') . '/main-lab-update-progress';
}

function mainLabUpdateProgressEnsureDir(): string
{
    $dir = mainLabUpdateProgressDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to prepare lab update progress directory');
    }
    @chmod($dir, 02775);
    return $dir;
}

function mainLabUpdateProgressNormalizeOperationId(string $operationId): string
{
    $normalized = strtolower(trim($operationId));
    if ($normalized === '') {
        return '';
    }
    if (preg_match('/^[a-z0-9._-]{8,128}$/', $normalized) !== 1) {
        return '';
    }
    return $normalized;
}

function mainLabUpdateProgressRead(string $operationId): ?array
{
    $safeId = mainLabUpdateProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        return null;
    }
    $path = rtrim(mainLabUpdateProgressDir(), '/') . '/' . $safeId . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function mainLabUpdateProgressWrite(string $operationId, array $payload): array
{
    $safeId = mainLabUpdateProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    $dir = mainLabUpdateProgressEnsureDir();
    $path = rtrim($dir, '/') . '/' . $safeId . '.json';

    $payload['operation_id'] = $safeId;
    $payload['updated_at'] = mainDeleteProgressNow();

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode lab update progress payload');
    }
    $tmpPath = $path . '.tmp.' . uniqid('', true);
    if (@file_put_contents($tmpPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to write lab update progress payload');
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Failed to persist lab update progress payload');
    }
    @chmod($path, 0664);

    return $payload;
}

function mainLabUpdateProgressPatch(string $operationId, array $changes): ?array
{
    $current = mainLabUpdateProgressRead($operationId);
    if (!is_array($current)) {
        return null;
    }
    $next = $current;
    foreach ($changes as $key => $value) {
        if (in_array($key, ['progress', 'cleanup', 'result', 'internal'], true) && is_array($value)) {
            $existing = is_array($next[$key] ?? null) ? $next[$key] : [];
            $next[$key] = array_merge($existing, $value);
            continue;
        }
        $next[$key] = $value;
    }
    return mainLabUpdateProgressWrite($operationId, $next);
}

function mainLabUpdateProgressCleanupExpired(int $maxAgeSeconds = 172800): void
{
    if ($maxAgeSeconds < 60) {
        $maxAgeSeconds = 60;
    }
    $dir = mainLabUpdateProgressDir();
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
    foreach ($files as $path) {
        $mtime = @filemtime($path);
        if (!is_int($mtime)) {
            continue;
        }
        if (($now - $mtime) > $maxAgeSeconds) {
            @unlink($path);
        }
    }
}

function mainLabUpdateProgressGenerateOperationId(): string
{
    try {
        $suffix = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $suffix = substr(hash('sha256', uniqid('main_lab_update', true)), 0, 24);
    }
    return 'upd_' . gmdate('YmdHis') . '_' . $suffix;
}

function mainLabUpdateNormalizeSharedUsers(array $sharedWithUsernames): array
{
    $out = [];
    foreach ($sharedWithUsernames as $rawUsername) {
        $candidate = trim((string) $rawUsername);
        if ($candidate === '') {
            continue;
        }
        $out[] = $candidate;
    }
    return array_values(array_unique($out));
}

function mainLabUpdateProgressCreate(
    array $viewer,
    string $labId,
    string $labName,
    array $request
): array {
    mainLabUpdateProgressCleanupExpired();

    $operationId = mainLabUpdateProgressGenerateOperationId();
    $payload = [
        'operation_id' => $operationId,
        'type' => 'main_lab_update',
        'lab_id' => $labId,
        'lab_name' => $labName,
        'requested_by_user_id' => trim((string) ($viewer['id'] ?? '')),
        'requested_by_username' => trim((string) ($viewer['username'] ?? '')),
        'requested_at' => mainDeleteProgressNow(),
        'started_at' => null,
        'finished_at' => null,
        'status' => 'queued',
        'stage' => 'queued',
        'message' => 'Queued',
        'progress' => [
            'current' => 0,
            'total' => 3,
        ],
        'cleanup' => [
            'labs_total' => 0,
            'labs_deleted' => 0,
            'nodes_total' => 0,
            'nodes_stopped' => 0,
        ],
        'result' => [],
        'internal' => [
            'lab_id' => $labId,
            'name' => (string) ($request['name'] ?? ''),
            'description' => array_key_exists('description', $request)
                ? ($request['description'] === null ? null : (string) $request['description'])
                : null,
            'is_shared' => !empty($request['is_shared']),
            'collaborate_allowed' => !empty($request['collaborate_allowed']),
            'topology_locked' => !empty($request['topology_locked']),
            'topology_allow_wipe' => !empty($request['topology_allow_wipe']),
            'shared_with' => mainLabUpdateNormalizeSharedUsers((array) ($request['shared_with'] ?? [])),
        ],
    ];

    return mainLabUpdateProgressWrite($operationId, $payload);
}

function mainLabUpdateProgressPublicPayload(array $payload): array
{
    unset($payload['internal']);
    return $payload;
}

function mainLabUpdateProgressGetForViewerRaw(PDO $db, array $viewer, string $operationId): array
{
    unset($db);

    $safeId = mainLabUpdateProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    $payload = mainLabUpdateProgressRead($safeId);
    if (!is_array($payload)) {
        throw new RuntimeException('Lab update operation not found');
    }

    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }
    $requesterId = trim((string) ($payload['requested_by_user_id'] ?? ''));
    $role = viewerRoleName($viewer);
    if ($role !== 'admin' && $requesterId !== '' && $requesterId !== $viewerId) {
        throw new RuntimeException('Forbidden');
    }

    return $payload;
}

function getMainLabUpdateProgressForViewer(PDO $db, array $viewer, string $operationId): array
{
    $payload = mainLabUpdateProgressGetForViewerRaw($db, $viewer, $operationId);
    return mainLabUpdateProgressPublicPayload($payload);
}

function queueMainLabUpdateForViewer(
    PDO $db,
    array $viewer,
    string $labId,
    string $name,
    ?string $description = null,
    bool $isShared = false,
    bool $collaborateAllowed = false,
    array $sharedWithUsernames = [],
    bool $topologyLocked = false,
    bool $topologyAllowWipe = false
): array {
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $entry = ensureMainEntryPermission($db, $viewer, 'lab', $labId);
    $entryLabId = trim((string) ($entry['id'] ?? ''));
    if ($entryLabId === '') {
        throw new RuntimeException('Entry not found');
    }

    $runningStmt = $db->prepare(
        "SELECT COUNT(*) AS cnt
         FROM lab_nodes
         WHERE lab_id = :lab_id
           AND (is_running = TRUE OR power_state = 'running')"
    );
    $runningStmt->bindValue(':lab_id', $entryLabId, PDO::PARAM_STR);
    $runningStmt->execute();
    $runningRow = $runningStmt->fetch(PDO::FETCH_ASSOC);
    $runningCount = (int) ($runningRow['cnt'] ?? 0);
    if ($runningCount > 0) {
        throw new RuntimeException('Lab has running nodes');
    }

    $operation = mainLabUpdateProgressCreate($viewer, $entryLabId, (string) ($entry['name'] ?? ''), [
        'name' => $name,
        'description' => $description,
        'is_shared' => $isShared,
        'collaborate_allowed' => $collaborateAllowed,
        'topology_locked' => $topologyLocked,
        'topology_allow_wipe' => $topologyAllowWipe,
        'shared_with' => $sharedWithUsernames,
    ]);
    $operationId = trim((string) ($operation['operation_id'] ?? ''));
    if ($operationId === '') {
        throw new RuntimeException('Failed to initialize lab update progress');
    }

    $script = dirname(__DIR__) . '/bin/update_main_lab_async.php';
    if (!is_file($script)) {
        mainLabUpdateProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'finished_at' => mainDeleteProgressNow(),
            'message' => 'Lab update worker script is missing',
        ]);
        throw new RuntimeException('Lab update worker script is missing');
    }

    $php = mainAsyncPhpBinary();
    $cmd = escapeshellarg($php)
        . ' ' . escapeshellarg($script)
        . ' --user-id=' . escapeshellarg($viewerId)
        . ' --operation-id=' . escapeshellarg($operationId)
        . ' > /dev/null 2>&1 &';

    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    if ($rc !== 0) {
        mainLabUpdateProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'finished_at' => mainDeleteProgressNow(),
            'message' => 'Failed to queue lab update task',
        ]);
        throw new RuntimeException('Failed to queue lab update task');
    }

    return [
        'queued' => true,
        'lab_id' => $entryLabId,
        'operation_id' => $operationId,
        'update_progress' => mainLabUpdateProgressPublicPayload($operation),
    ];
}

function mainExportProgressDir(): string
{
    $runtimeRoot = function_exists('v2RuntimeRootDir')
        ? (string) v2RuntimeRootDir()
        : '/opt/unetlab/data/v2-runtime';
    return rtrim($runtimeRoot, '/') . '/main-export-progress';
}

function mainExportProgressEnsureDir(): string
{
    $dir = mainExportProgressDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to prepare export progress directory');
    }
    @chmod($dir, 02775);
    return $dir;
}

function mainExportProgressNormalizeOperationId(string $operationId): string
{
    $normalized = strtolower(trim($operationId));
    if ($normalized === '') {
        return '';
    }
    if (preg_match('/^[a-z0-9._-]{8,128}$/', $normalized) !== 1) {
        return '';
    }
    return $normalized;
}

function mainExportProgressRead(string $operationId): ?array
{
    $safeId = mainExportProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        return null;
    }
    $path = rtrim(mainExportProgressDir(), '/') . '/' . $safeId . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function mainExportProgressWrite(string $operationId, array $payload): array
{
    $safeId = mainExportProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    $dir = mainExportProgressEnsureDir();
    $path = rtrim($dir, '/') . '/' . $safeId . '.json';

    $payload['operation_id'] = $safeId;
    $payload['updated_at'] = mainDeleteProgressNow();

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode export progress payload');
    }
    $tmpPath = $path . '.tmp.' . uniqid('', true);
    if (@file_put_contents($tmpPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to write export progress payload');
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Failed to persist export progress payload');
    }
    @chmod($path, 0664);

    return $payload;
}

function mainExportProgressPatch(string $operationId, array $changes): ?array
{
    $current = mainExportProgressRead($operationId);
    if (!is_array($current)) {
        return null;
    }
    $next = $current;
    foreach ($changes as $key => $value) {
        if (in_array($key, ['progress', 'runtime_copy', 'packing', 'result', 'internal'], true) && is_array($value)) {
            $existing = is_array($next[$key] ?? null) ? $next[$key] : [];
            $next[$key] = array_merge($existing, $value);
            continue;
        }
        $next[$key] = $value;
    }
    return mainExportProgressWrite($operationId, $next);
}

function mainExportProgressCleanupPayloadArtifacts(array $payload): void
{
    $internal = is_array($payload['internal'] ?? null) ? $payload['internal'] : [];
    $workDir = trim((string) ($internal['work_dir'] ?? ''));
    $archivePath = trim((string) ($internal['archive_path'] ?? ''));
    if ($workDir === '' && $archivePath === '') {
        return;
    }
    cleanupMainLabArchiveExport([
        'work_dir' => $workDir,
        'archive_path' => $archivePath,
    ]);
}

function mainExportProgressCleanupExpired(int $maxAgeSeconds = 172800): void
{
    if ($maxAgeSeconds < 60) {
        $maxAgeSeconds = 60;
    }
    $dir = mainExportProgressDir();
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
    foreach ($files as $path) {
        $mtime = @filemtime($path);
        if (!is_int($mtime)) {
            continue;
        }
        if (($now - $mtime) > $maxAgeSeconds) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    mainExportProgressCleanupPayloadArtifacts($decoded);
                }
            }
            @unlink($path);
        }
    }
}

function mainExportProgressGenerateOperationId(): string
{
    try {
        $suffix = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $suffix = substr(hash('sha256', uniqid('main_export', true)), 0, 24);
    }
    return 'exp_' . gmdate('YmdHis') . '_' . $suffix;
}

function mainExportProgressCreate(
    array $viewer,
    string $labId,
    string $labName,
    bool $includeRuntime,
    bool $includeChecks,
    int $runtimeNodeTotal = 0
): array {
    mainExportProgressCleanupExpired();

    $runtimeNodeTotal = max(0, $runtimeNodeTotal);
    // Fixed phases: preparing, collecting, writing, packing-start, finalizing(done).
    // Runtime copy adds per-node progress only when includeRuntime is enabled.
    // Packing is weighted to provide visible progress updates while tar is running.
    $packingWeight = 40;
    $progressTotal = 5 + $packingWeight + ($includeRuntime ? $runtimeNodeTotal : 0);
    if ($progressTotal < 1) {
        $progressTotal = 1;
    }
    $operationId = mainExportProgressGenerateOperationId();
    $payload = [
        'operation_id' => $operationId,
        'lab_id' => $labId,
        'lab_name' => $labName,
        'requested_by_user_id' => trim((string) ($viewer['id'] ?? '')),
        'requested_by_username' => trim((string) ($viewer['username'] ?? '')),
        'requested_at' => mainDeleteProgressNow(),
        'started_at' => null,
        'finished_at' => null,
        'status' => 'queued',
        'stage' => 'queued',
        'message' => 'Queued',
        'options' => [
            'include_runtime' => $includeRuntime,
            'include_checks' => $includeChecks,
        ],
        'progress' => [
            'current' => 0,
            'total' => $progressTotal,
        ],
        'runtime_copy' => [
            'current' => 0,
            'total' => $includeRuntime ? $runtimeNodeTotal : 0,
            'copied' => 0,
            'skipped' => 0,
        ],
        'packing' => [
            'processed_bytes' => 0,
            'current_bytes' => 0,
            'total_bytes' => 0,
            'eta_seconds' => null,
            'weight' => $packingWeight,
        ],
        'result' => [],
        'internal' => [
            'archive_path' => '',
            'work_dir' => '',
            'content_type' => '',
        ],
    ];

    return mainExportProgressWrite($operationId, $payload);
}

function mainExportProgressGetForViewerRaw(PDO $db, array $viewer, string $operationId): array
{
    unset($db);

    $safeId = mainExportProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    $payload = mainExportProgressRead($safeId);
    if (!is_array($payload)) {
        throw new RuntimeException('Export operation not found');
    }

    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }
    $requesterId = trim((string) ($payload['requested_by_user_id'] ?? ''));
    $role = viewerRoleName($viewer);
    if ($role !== 'admin' && $requesterId !== '' && $requesterId !== $viewerId) {
        throw new RuntimeException('Forbidden');
    }

    return $payload;
}

function mainExportProgressPublicPayload(array $payload): array
{
    unset($payload['internal']);
    return $payload;
}

function getMainExportProgressForViewer(PDO $db, array $viewer, string $operationId): array
{
    $payload = mainExportProgressGetForViewerRaw($db, $viewer, $operationId);
    return mainExportProgressPublicPayload($payload);
}

function queueMainLabExportForViewer(
    PDO $db,
    array $viewer,
    string $labId,
    bool $includeRuntime = false,
    bool $includeChecks = true
): array {
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $entry = ensureMainEntryPermission($db, $viewer, 'lab', $labId);
    $entryLabId = trim((string) ($entry['id'] ?? ''));
    if ($entryLabId === '') {
        throw new RuntimeException('Entry not found');
    }
    $labName = (string) ($entry['name'] ?? '');
    $runtimeNodeTotal = $includeRuntime ? mainCountNodesForLab($db, $entryLabId) : 0;

    $operation = mainExportProgressCreate(
        $viewer,
        $entryLabId,
        $labName,
        $includeRuntime,
        $includeChecks,
        $runtimeNodeTotal
    );
    $operationId = trim((string) ($operation['operation_id'] ?? ''));
    if ($operationId === '') {
        throw new RuntimeException('Failed to initialize export progress');
    }

    $script = dirname(__DIR__) . '/bin/create_main_lab_export_async.php';
    if (!is_file($script)) {
        mainExportProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'finished_at' => mainDeleteProgressNow(),
            'message' => 'Export worker script is missing',
        ]);
        throw new RuntimeException('Export worker script is missing');
    }

    $php = mainAsyncPhpBinary();
    $cmd = escapeshellarg($php)
        . ' ' . escapeshellarg($script)
        . ' --user-id=' . escapeshellarg($viewerId)
        . ' --lab-id=' . escapeshellarg($entryLabId)
        . ' --include-runtime=' . escapeshellarg($includeRuntime ? '1' : '0')
        . ' --include-checks=' . escapeshellarg($includeChecks ? '1' : '0')
        . ' --operation-id=' . escapeshellarg($operationId)
        . ' > /dev/null 2>&1 &';

    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    if ($rc !== 0) {
        mainExportProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'finished_at' => mainDeleteProgressNow(),
            'message' => 'Failed to queue export task',
        ]);
        throw new RuntimeException('Failed to queue export task');
    }

    return [
        'queued' => true,
        'lab_id' => $entryLabId,
        'lab_name' => $labName,
        'operation_id' => $operationId,
        'export_progress' => mainExportProgressPublicPayload($operation),
    ];
}

function resolveMainLabExportDownloadForViewer(PDO $db, array $viewer, string $operationId): array
{
    $payload = mainExportProgressGetForViewerRaw($db, $viewer, $operationId);
    $status = strtolower(trim((string) ($payload['status'] ?? '')));
    if ($status !== 'done') {
        throw new RuntimeException('Export is not ready');
    }

    $internal = is_array($payload['internal'] ?? null) ? $payload['internal'] : [];
    $archivePath = trim((string) ($internal['archive_path'] ?? ''));
    $workDir = trim((string) ($internal['work_dir'] ?? ''));
    $contentType = trim((string) ($internal['content_type'] ?? ''));
    if ($contentType === '') {
        $contentType = 'application/gzip';
    }
    $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
    $archiveName = trim((string) ($result['archive_name'] ?? ''));
    if ($archiveName === '') {
        $archiveName = 'lab-export.evev2lab.tgz';
    }

    if ($archivePath === '' || !is_file($archivePath)) {
        mainExportProgressCleanupPayloadArtifacts($payload);
        mainExportProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'message' => 'Export archive not found',
            'finished_at' => mainDeleteProgressNow(),
            'internal' => [
                'archive_path' => '',
                'work_dir' => '',
                'content_type' => '',
            ],
        ]);
        throw new RuntimeException('Export archive not found');
    }

    return [
        'operation_id' => mainExportProgressNormalizeOperationId($operationId),
        'archive_path' => $archivePath,
        'archive_name' => $archiveName,
        'content_type' => $contentType,
        'work_dir' => $workDir,
        'payload' => $payload,
    ];
}

function mainImportProgressDir(): string
{
    $runtimeRoot = function_exists('v2RuntimeRoot')
        ? rtrim((string) v2RuntimeRoot(), '/')
        : '/opt/unetlab/data/v2-runtime';
    return rtrim($runtimeRoot, '/') . '/main-import-progress';
}

function mainImportProgressEnsureDir(): string
{
    $dir = mainImportProgressDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to prepare import progress directory');
    }
    @chmod($dir, 0775);
    return $dir;
}

function mainImportProgressNormalizeOperationId(string $operationId): string
{
    $normalized = strtolower(trim($operationId));
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/[^a-z0-9._-]/', '', $normalized);
    if (!is_string($normalized)) {
        return '';
    }
    if (strlen($normalized) < 8 || strlen($normalized) > 128) {
        return '';
    }
    return $normalized;
}

function mainImportProgressRead(string $operationId): ?array
{
    $safeId = mainImportProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        return null;
    }
    $path = rtrim(mainImportProgressDir(), '/') . '/' . $safeId . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function mainImportProgressWrite(string $operationId, array $payload): array
{
    $safeId = mainImportProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    $dir = mainImportProgressEnsureDir();
    $path = rtrim($dir, '/') . '/' . $safeId . '.json';

    $payload['operation_id'] = $safeId;
    $payload['updated_at'] = mainDeleteProgressNow();

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode import progress payload');
    }

    if (@file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to write import progress payload');
    }
    if (!@chmod($path, 0664)) {
        throw new RuntimeException('Failed to persist import progress payload');
    }

    return $payload;
}

function mainImportProgressPatch(string $operationId, array $changes): ?array
{
    $current = mainImportProgressRead($operationId);
    if (!is_array($current)) {
        return null;
    }
    $next = array_replace_recursive($current, $changes);
    if (!isset($next['operation_id']) || trim((string) $next['operation_id']) === '') {
        $next['operation_id'] = mainImportProgressNormalizeOperationId($operationId);
    }
    if (!isset($next['requested_at']) || trim((string) $next['requested_at']) === '') {
        $next['requested_at'] = mainDeleteProgressNow();
    }
    if (!isset($next['status']) || trim((string) $next['status']) === '') {
        $next['status'] = 'queued';
    }
    if (!isset($next['stage']) || trim((string) $next['stage']) === '') {
        $next['stage'] = 'queued';
    }
    if (!isset($next['progress']) || !is_array($next['progress'])) {
        $next['progress'] = ['current' => 0, 'total' => 1];
    }
    return mainImportProgressWrite($operationId, $next);
}

function mainImportProgressCleanupPayloadArtifacts(array $payload): void
{
    $internal = is_array($payload['internal'] ?? null) ? $payload['internal'] : [];
    $archivePath = trim((string) ($internal['archive_path'] ?? ''));
    $uploadWorkDir = trim((string) ($internal['upload_work_dir'] ?? ''));

    if ($uploadWorkDir !== '' && is_dir($uploadWorkDir)) {
        mainLabArchiveRemoveDir($uploadWorkDir);
    } elseif ($archivePath !== '' && is_file($archivePath)) {
        @unlink($archivePath);
    }
}

function mainImportProgressCleanupExpired(int $maxAgeSeconds = 172800): void
{
    if ($maxAgeSeconds < 60) {
        $maxAgeSeconds = 60;
    }
    $dir = mainImportProgressDir();
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    $it = @new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
    if (!$it instanceof FilesystemIterator) {
        return;
    }
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            continue;
        }
        $mtime = (int) $file->getMTime();
        if ($mtime > 0 && ($now - $mtime) > $maxAgeSeconds) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    mainImportProgressCleanupPayloadArtifacts($decoded);
                }
            }
            @unlink($path);
        }
    }
}

function mainImportProgressGenerateOperationId(): string
{
    try {
        $suffix = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $suffix = substr(hash('sha256', uniqid('main_import', true)), 0, 24);
    }
    return 'imp_' . gmdate('YmdHis') . '_' . $suffix;
}

function mainNormalizeImportLabName(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    try {
        $normalized = normalizeExplorerName($value);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Invalid lab name');
    }
    if ($normalized === '') {
        throw new InvalidArgumentException('Invalid lab name');
    }
    return $normalized;
}

function mainImportProgressCreate(
    array $viewer,
    string $targetPath,
    string $archiveName,
    int $archiveSizeBytes = 0,
    string $requestedLabName = ''
): array
{
    mainImportProgressCleanupExpired();
    $operationId = mainImportProgressGenerateOperationId();
    $payload = [
        'operation_id' => $operationId,
        'type' => 'main_lab_import',
        'target_path' => $targetPath,
        'requested_lab_name' => $requestedLabName,
        'requested_by_user_id' => trim((string) ($viewer['id'] ?? '')),
        'requested_by_username' => trim((string) ($viewer['username'] ?? '')),
        'requested_at' => mainDeleteProgressNow(),
        'started_at' => null,
        'finished_at' => null,
        'status' => 'queued',
        'stage' => 'queued',
        'message' => 'Queued',
        'progress' => [
            'current' => 0,
            'total' => 1,
        ],
        'archive' => [
            'name' => $archiveName,
            'size_bytes' => max(0, (int) $archiveSizeBytes),
        ],
        'result' => [],
        'stats' => [
            'db_total' => 0,
            'runtime_total' => 0,
            'runtime_copied' => 0,
            'runtime_skipped' => 0,
        ],
        'internal' => [
            'archive_path' => '',
            'upload_work_dir' => '',
        ],
    ];
    return mainImportProgressWrite($operationId, $payload);
}

function mainImportProgressGetForViewerRaw(PDO $db, array $viewer, string $operationId): array
{
    unset($db);
    $safeId = mainImportProgressNormalizeOperationId($operationId);
    if ($safeId === '') {
        throw new InvalidArgumentException('Invalid operation id');
    }
    $payload = mainImportProgressRead($safeId);
    if (!is_array($payload)) {
        throw new RuntimeException('Import operation not found');
    }

    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }
    $requesterId = trim((string) ($payload['requested_by_user_id'] ?? ''));
    $role = viewerRoleName($viewer);
    if ($role !== 'admin' && $requesterId !== '' && $requesterId !== $viewerId) {
        throw new RuntimeException('Forbidden');
    }
    return $payload;
}

function mainImportProgressPublicPayload(array $payload): array
{
    unset($payload['internal']);
    return $payload;
}

function getMainImportProgressForViewer(PDO $db, array $viewer, string $operationId): array
{
    $payload = mainImportProgressGetForViewerRaw($db, $viewer, $operationId);
    return mainImportProgressPublicPayload($payload);
}

function mainMoveUploadedFileToPath(string $tmpPath, string $targetPath): void
{
    if ($tmpPath === '' || $targetPath === '') {
        throw new InvalidArgumentException('Invalid upload path');
    }
    if (!is_file($tmpPath)) {
        throw new RuntimeException('Uploaded archive is missing');
    }

    $dir = dirname($targetPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to prepare upload directory');
    }

    if (@move_uploaded_file($tmpPath, $targetPath)) {
        @chmod($targetPath, 0664);
        return;
    }
    if (@rename($tmpPath, $targetPath)) {
        @chmod($targetPath, 0664);
        return;
    }

    $in = @fopen($tmpPath, 'rb');
    if (!is_resource($in)) {
        throw new RuntimeException('Failed to read uploaded archive');
    }
    $out = @fopen($targetPath, 'wb');
    if (!is_resource($out)) {
        @fclose($in);
        throw new RuntimeException('Failed to store uploaded archive');
    }
    $ok = true;
    while (!feof($in)) {
        $chunk = @fread($in, 1024 * 1024);
        if ($chunk === false) {
            $ok = false;
            break;
        }
        if ($chunk === '') {
            continue;
        }
        $written = @fwrite($out, $chunk);
        if (!is_int($written) || $written <= 0) {
            $ok = false;
            break;
        }
    }
    @fclose($in);
    @fclose($out);
    if (!$ok) {
        @unlink($targetPath);
        throw new RuntimeException('Failed to store uploaded archive');
    }
    @chmod($targetPath, 0664);
    @unlink($tmpPath);
}

function queueMainLabImportForViewer(
    PDO $db,
    array $viewer,
    string $targetPath,
    string $uploadedTmpPath,
    string $uploadedName = '',
    int $uploadedSizeBytes = 0,
    string $labNameOverride = ''
): array {
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $scope = mainResolveImportScopeForViewer($db, $viewer, $targetPath);
    $relativePath = (string) ($scope['relative_path'] ?? '/');
    $pathPrefix = (string) ($scope['path_prefix'] ?? '');
    $normalizedTargetPath = ($pathPrefix === '')
        ? normalizeMainPath($relativePath)
        : remapScopedPath($pathPrefix, normalizeMainPath($relativePath), false);

    $archiveNameRaw = trim($uploadedName);
    if ($archiveNameRaw === '') {
        $archiveNameRaw = 'lab-import.evev2lab.tgz';
    }
    $archiveNameSafe = preg_replace('/[^A-Za-z0-9._-]/', '_', $archiveNameRaw);
    if (!is_string($archiveNameSafe) || trim($archiveNameSafe) === '') {
        $archiveNameSafe = 'lab-import.evev2lab.tgz';
    }
    $normalizedLabNameOverride = mainNormalizeImportLabName($labNameOverride);

    $operation = mainImportProgressCreate(
        $viewer,
        $normalizedTargetPath,
        $archiveNameSafe,
        $uploadedSizeBytes,
        $normalizedLabNameOverride
    );
    $operationId = trim((string) ($operation['operation_id'] ?? ''));
    if ($operationId === '') {
        throw new RuntimeException('Failed to initialize import progress');
    }

    $uploadWorkDir = '';
    try {
        $uploadWorkDir = mainLabArchiveMakeTempDir('import-upload');
        $archivePath = rtrim($uploadWorkDir, '/') . '/upload-archive.bin';
        mainMoveUploadedFileToPath($uploadedTmpPath, $archivePath);

        mainImportProgressPatch($operationId, [
            'status' => 'queued',
            'stage' => 'queued',
            'message' => 'Queued',
            'internal' => [
                'archive_path' => $archivePath,
                'upload_work_dir' => $uploadWorkDir,
            ],
        ]);
    } catch (Throwable $e) {
        if ($uploadWorkDir !== '' && is_dir($uploadWorkDir)) {
            mainLabArchiveRemoveDir($uploadWorkDir);
        }
        mainImportProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'message' => $e->getMessage(),
            'finished_at' => mainDeleteProgressNow(),
            'internal' => [
                'archive_path' => '',
                'upload_work_dir' => '',
            ],
        ]);
        throw $e;
    }

    $script = dirname(__DIR__) . '/bin/create_main_lab_import_async.php';
    if (!is_file($script)) {
        $payload = mainImportProgressRead($operationId);
        if (is_array($payload)) {
            mainImportProgressCleanupPayloadArtifacts($payload);
        }
        mainImportProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'message' => 'Import worker script is missing',
            'finished_at' => mainDeleteProgressNow(),
            'internal' => [
                'archive_path' => '',
                'upload_work_dir' => '',
            ],
        ]);
        throw new RuntimeException('Import worker script is missing');
    }

    $php = mainAsyncPhpBinary();
    $cmd = escapeshellarg($php)
        . ' ' . escapeshellarg($script)
        . ' --user-id=' . escapeshellarg($viewerId)
        . ' --target-path=' . escapeshellarg($normalizedTargetPath)
        . ' --lab-name=' . escapeshellarg($normalizedLabNameOverride)
        . ' --operation-id=' . escapeshellarg($operationId)
        . ' > /dev/null 2>&1 &';

    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    if ($rc !== 0) {
        $payload = mainImportProgressRead($operationId);
        if (is_array($payload)) {
            mainImportProgressCleanupPayloadArtifacts($payload);
        }
        mainImportProgressPatch($operationId, [
            'status' => 'failed',
            'stage' => 'failed',
            'message' => 'Failed to queue import task',
            'finished_at' => mainDeleteProgressNow(),
            'internal' => [
                'archive_path' => '',
                'upload_work_dir' => '',
            ],
        ]);
        throw new RuntimeException('Failed to queue import task');
    }

    $payload = mainImportProgressRead($operationId);
    if (!is_array($payload)) {
        $payload = $operation;
    }
    return [
        'queued' => true,
        'operation_id' => $operationId,
        'import_progress' => mainImportProgressPublicPayload($payload),
    ];
}

function mainLabArchiveTempRootDir(): string
{
    return '/opt/unetlab/data/tmp/main-lab-archives';
}

function mainLabArchiveEnsureDir(string $path): void
{
    if (!is_dir($path)) {
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to prepare archive directory');
        }
    }
    @chmod($path, 02775);
}

function mainLabArchiveMakeTempDir(string $prefix): string
{
    $root = mainLabArchiveTempRootDir();
    mainLabArchiveEnsureDir($root);
    $safePrefix = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($prefix)));
    if (!is_string($safePrefix) || $safePrefix === '') {
        $safePrefix = 'job';
    }
    try {
        $suffix = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $suffix = substr(hash('sha256', uniqid($safePrefix, true)), 0, 12);
    }
    $dir = rtrim($root, '/') . '/' . $safePrefix . '-' . gmdate('YmdHis') . '-' . $suffix;
    mainLabArchiveEnsureDir($dir);
    return $dir;
}

function mainLabArchiveRemoveDir(string $path): void
{
    $path = rtrim(trim($path), '/');
    if ($path === '' || !is_dir($path)) {
        return;
    }
    $root = rtrim(mainLabArchiveTempRootDir(), '/');
    if ($root === '' || strpos($path, $root . '/') !== 0) {
        return;
    }
    try {
        $it = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $item) {
            /** @var SplFileInfo $item */
            $target = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($target);
            } else {
                @unlink($target);
            }
        }
        @rmdir($path);
    } catch (Throwable $e) {
        // Best-effort cleanup.
    }
}

function mainLabArchiveSafeFilenamePart(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'lab';
    }
    $value = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $value);
    if (!is_string($value) || $value === '') {
        return 'lab';
    }
    return trim($value, '._-') ?: 'lab';
}

function mainLabArchiveRunTar(array $args): void
{
    $parts = ['/bin/tar'];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }
    $cmd = implode(' ', $parts) . ' 2>&1';
    $output = [];
    $rc = 0;
    @exec($cmd, $output, $rc);
    if ($rc !== 0) {
        $details = trim(implode("\n", $output));
        if ($details === '') {
            $details = 'tar command failed';
        }
        throw new RuntimeException($details);
    }
}

function mainDirectorySizeBytes(string $path): int
{
    $path = rtrim(trim($path), '/');
    if ($path === '' || !is_dir($path)) {
        return 0;
    }
    $total = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            /** @var SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            $size = $item->getSize();
            if (is_int($size) && $size > 0) {
                $total += $size;
            }
        }
    } catch (Throwable $e) {
        return max(0, $total);
    }
    return max(0, $total);
}

function mainLabArchiveRunTarWithProgress(array $args, string $archivePath, int $sourceBytes = 0, ?callable $stageCallback = null): void
{
    $sourceBytes = max(0, $sourceBytes);
    $checkpointMarker = '__EVECHK__';
    $checkpointBlocks = 2048; // 1 MiB checkpoints (2048 * 512 bytes).
    $checkpointBytesStep = $checkpointBlocks * 512;
    $checkpointArg = '--checkpoint=' . $checkpointBlocks;
    $checkpointActionArg = '--checkpoint-action=echo=' . $checkpointMarker . '%u';
    $tarArgs = array_merge([$checkpointArg, $checkpointActionArg], $args);

    if (!function_exists('proc_open')) {
        try {
            mainLabArchiveRunTar($tarArgs);
        } catch (RuntimeException $e) {
            $details = (string) $e->getMessage();
            if (stripos($details, '--checkpoint') === false && stripos($details, 'checkpoint-action') === false) {
                throw $e;
            }
            mainLabArchiveRunTar($args);
        }
        clearstatcache(true, $archivePath);
        $size = is_file($archivePath) ? (int) (@filesize($archivePath) ?: 0) : 0;
        mainSafeProgressCallback($stageCallback, 'packing_archive_progress', [
            'processed_bytes' => $sourceBytes > 0 ? $sourceBytes : $size,
            'current_bytes' => $size,
            'total_bytes' => $sourceBytes,
            'eta_seconds' => 0,
            'finalizing' => false,
        ]);
        return;
    }

    $parts = ['/bin/tar'];
    foreach ($tarArgs as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }
    $cmd = implode(' ', $parts);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        try {
            mainLabArchiveRunTar($tarArgs);
        } catch (RuntimeException $e) {
            $details = (string) $e->getMessage();
            if (stripos($details, '--checkpoint') === false && stripos($details, 'checkpoint-action') === false) {
                throw $e;
            }
            mainLabArchiveRunTar($args);
        }
        clearstatcache(true, $archivePath);
        $size = is_file($archivePath) ? (int) (@filesize($archivePath) ?: 0) : 0;
        mainSafeProgressCallback($stageCallback, 'packing_archive_progress', [
            'processed_bytes' => $sourceBytes > 0 ? $sourceBytes : $size,
            'current_bytes' => $size,
            'total_bytes' => $sourceBytes,
            'eta_seconds' => 0,
            'finalizing' => false,
        ]);
        return;
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        @fclose($pipes[0]);
    }
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        @stream_set_blocking($pipes[1], false);
    }
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        @stream_set_blocking($pipes[2], false);
    }

    $readPipe = static function ($pipe): string {
        if (!is_resource($pipe)) {
            return '';
        }
        $buffer = '';
        while (true) {
            $chunk = @fread($pipe, 8192);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
            if (strlen($buffer) > 1048576) {
                $buffer = substr($buffer, -1048576);
            }
        }
        return $buffer;
    };

    $startedAt = microtime(true);
    $lastEmitAt = 0.0;
    $lastProcessedBytes = -1;
    $stdoutTail = '';
    $stderrTail = '';
    $checkpointNo = 0;
    $lastExitCode = null;

    while (true) {
        $status = @proc_get_status($proc);
        $running = is_array($status) && !empty($status['running']);
        if (is_array($status) && !$running) {
            $statusExitCode = (int) ($status['exitcode'] ?? -1);
            if ($statusExitCode >= 0) {
                $lastExitCode = $statusExitCode;
            }
        }

        $stdoutChunk = $readPipe($pipes[1] ?? null);
        if ($stdoutChunk !== '') {
            $stdoutTail .= $stdoutChunk;
            if (strlen($stdoutTail) > 1048576) {
                $stdoutTail = substr($stdoutTail, -1048576);
            }
            if (preg_match_all('/' . preg_quote($checkpointMarker, '/') . '([0-9]+)/', $stdoutChunk, $matches) > 0) {
                foreach ((array) ($matches[1] ?? []) as $rawValue) {
                    $num = (int) $rawValue;
                    if ($num > $checkpointNo) {
                        $checkpointNo = $num;
                    }
                }
            }
        }
        $stderrChunk = $readPipe($pipes[2] ?? null);
        if ($stderrChunk !== '') {
            $stderrTail .= $stderrChunk;
            if (strlen($stderrTail) > 1048576) {
                $stderrTail = substr($stderrTail, -1048576);
            }
            if (preg_match_all('/' . preg_quote($checkpointMarker, '/') . '([0-9]+)/', $stderrChunk, $matches) > 0) {
                foreach ((array) ($matches[1] ?? []) as $rawValue) {
                    $num = (int) $rawValue;
                    if ($num > $checkpointNo) {
                        $checkpointNo = $num;
                    }
                }
            }
        }

        clearstatcache(true, $archivePath);
        $currentBytes = is_file($archivePath) ? (int) (@filesize($archivePath) ?: 0) : 0;
        $processedBytes = max(0, $checkpointNo * $checkpointBytesStep);
        if (!$running && $sourceBytes > 0) {
            $processedBytes = max($processedBytes, $sourceBytes);
        }
        if ($sourceBytes > 0 && $processedBytes > $sourceBytes) {
            $processedBytes = $sourceBytes;
        }
        $now = microtime(true);
        $needEmit = ($processedBytes !== $lastProcessedBytes) || (($now - $lastEmitAt) >= 0.5) || !$running;
        if ($needEmit) {
            $etaSeconds = null;
            if ($sourceBytes > 0 && $processedBytes > 0) {
                $elapsed = max(0.001, $now - $startedAt);
                $rate = $processedBytes / $elapsed;
                if ($rate > 1.0) {
                    $remainingBytes = max(0, $sourceBytes - $processedBytes);
                    $etaSeconds = (int) ceil($remainingBytes / $rate);
                }
            }
            mainSafeProgressCallback($stageCallback, 'packing_archive_progress', [
                'processed_bytes' => $processedBytes,
                'current_bytes' => $currentBytes,
                'total_bytes' => $sourceBytes,
                'eta_seconds' => $etaSeconds,
                'finalizing' => ($sourceBytes > 0 && $processedBytes >= $sourceBytes && $running),
            ]);
            $lastEmitAt = $now;
            $lastProcessedBytes = $processedBytes;
        }

        if (!$running) {
            break;
        }
        usleep(250000);
    }

    if (isset($pipes[1]) && is_resource($pipes[1])) {
        $tail = $readPipe($pipes[1]);
        if ($tail !== '') {
            $stdoutTail .= $tail;
        }
        @fclose($pipes[1]);
    }
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        $tail = $readPipe($pipes[2]);
        if ($tail !== '') {
            $stderrTail .= $tail;
        }
        @fclose($pipes[2]);
    }

    $rc = @proc_close($proc);
    if (!is_int($rc)) {
        $rc = 1;
    }
    if ($rc === -1 && $lastExitCode !== null) {
        $rc = (int) $lastExitCode;
    }
    if ($rc !== 0) {
        $details = trim($stderrTail);
        if ($details === '') {
            $details = trim($stdoutTail);
        }
        if ($details !== '') {
            $lines = preg_split('/\r?\n/', $details) ?: [];
            $filtered = [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^(\/bin\/)?tar:\s+__EVECHK__[0-9]+$/i', $line) === 1) {
                    continue;
                }
                $filtered[] = $line;
            }
            if (!empty($filtered)) {
                $details = implode("\n", $filtered);
            } else {
                $details = '';
            }
        }
        if (
            $details !== ''
            && (stripos($details, '--checkpoint') !== false || stripos($details, 'checkpoint-action') !== false)
        ) {
            @unlink($archivePath);
            mainLabArchiveRunTar($args);
            clearstatcache(true, $archivePath);
            $size = is_file($archivePath) ? (int) (@filesize($archivePath) ?: 0) : 0;
            mainSafeProgressCallback($stageCallback, 'packing_archive_progress', [
                'processed_bytes' => $sourceBytes > 0 ? $sourceBytes : $size,
                'current_bytes' => $size,
                'total_bytes' => $sourceBytes,
                'eta_seconds' => 0,
                'finalizing' => false,
            ]);
            return;
        }
        if ($details === '') {
            $details = 'tar command failed';
        }
        throw new RuntimeException($details);
    }
}

function mainLabArchiveListEntries(string $archivePath): array
{
    $cmd = '/bin/tar -tzf ' . escapeshellarg($archivePath) . ' 2>&1';
    $output = [];
    $rc = 0;
    @exec($cmd, $output, $rc);
    if ($rc !== 0) {
        $details = trim(implode("\n", $output));
        throw new RuntimeException($details !== '' ? $details : 'Invalid archive');
    }
    $result = [];
    foreach ($output as $line) {
        $entry = trim((string) $line);
        if ($entry === '') {
            continue;
        }
        $result[] = $entry;
    }
    return $result;
}

function mainLabArchiveEntryIsSafe(string $entry): bool
{
    $entry = trim(str_replace('\\', '/', $entry));
    if ($entry === '' || strpos($entry, "\0") !== false) {
        return false;
    }
    if (preg_match('#^([a-zA-Z]:)?/#', $entry) === 1) {
        return false;
    }
    $parts = explode('/', $entry);
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            return false;
        }
    }
    return true;
}

function mainLabArchiveFindFile(string $baseDir, string $filename): string
{
    $target = rtrim($baseDir, '/') . '/' . ltrim($filename, '/');
    if (is_file($target)) {
        return $target;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        if (!$item->isFile()) {
            continue;
        }
        if (strcasecmp($item->getFilename(), $filename) === 0) {
            return $item->getPathname();
        }
    }
    return '';
}

function mainResolveImportScopeForViewer(PDO $db, array $viewer, string $path): array
{
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }
    $role = viewerRoleName($viewer);
    if ($role !== 'admin') {
        $writePath = resolveMainWritePathForViewer($db, $viewer, $path);
        return [
            'owner_user_id' => $viewerId,
            'relative_path' => (string) ($writePath['relative_path'] ?? '/'),
            'path_prefix' => (string) ($writePath['path_prefix'] ?? ''),
        ];
    }

    $scope = parseAdminExplorerPath($path);
    if (!empty($scope['is_admin_root'])) {
        throw new InvalidArgumentException('Invalid path');
    }
    $target = findUserByUsername($db, (string) $scope['username']);
    if ($target === null) {
        throw new RuntimeException('Path not found');
    }
    return [
        'owner_user_id' => (string) $target['id'],
        'relative_path' => normalizeMainPath((string) $scope['relative_path']),
        'path_prefix' => (string) ($scope['prefix'] ?? ''),
    ];
}

function mainExportLabArchiveForViewer(
    PDO $db,
    array $viewer,
    string $labId,
    bool $includeRuntime = false,
    bool $includeChecks = true,
    ?callable $stageCallback = null
): array {
    mainSafeProgressCallback($stageCallback, 'preparing', []);
    $entry = ensureMainEntryPermission($db, $viewer, 'lab', $labId);

    $labStmt = $db->prepare(
        "SELECT id, name, description, author_user_id, topology_locked, topology_allow_wipe
         FROM labs
         WHERE id = :id
         LIMIT 1"
    );
    $labStmt->bindValue(':id', (string) $entry['id'], PDO::PARAM_STR);
    $labStmt->execute();
    $labRow = $labStmt->fetch(PDO::FETCH_ASSOC);
    if ($labRow === false) {
        throw new RuntimeException('Entry not found');
    }

    $networkStmt = $db->prepare(
        "SELECT id, name, network_type, left_pos, top_pos, visibility, icon
         FROM lab_networks
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC, id ASC"
    );
    $networkStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
    $networkStmt->execute();
    $networkRows = $networkStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($networkRows)) {
        $networkRows = [];
    }

    $nodeStmt = $db->prepare(
        "SELECT id, name, node_type, template, image, icon, console,
                left_pos, top_pos, delay_ms, ethernet_count, serial_count,
                cpu, ram_mb, nvram_mb, first_mac, qemu_options, qemu_version,
                qemu_arch, qemu_nic
         FROM lab_nodes
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC, id ASC"
    );
    $nodeStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
    $nodeStmt->execute();
    $nodeRows = $nodeStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($nodeRows)) {
        $nodeRows = [];
    }

    $portStmt = $db->prepare(
        "SELECT id, node_id, name, port_type, network_id, created_at, updated_at
         FROM lab_node_ports
         WHERE node_id IN (SELECT id FROM lab_nodes WHERE lab_id = :lab_id)
         ORDER BY created_at ASC, id ASC"
    );
    $portStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
    $portStmt->execute();
    $portRows = $portStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($portRows)) {
        $portRows = [];
    }

    $objectStmt = $db->prepare(
        "SELECT object_type, name, data_base64
         FROM lab_objects
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC, id ASC"
    );
    $objectStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
    $objectStmt->execute();
    $objectRows = $objectStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($objectRows)) {
        $objectRows = [];
    }

    $checksData = ['included' => false];
    if ($includeChecks) {
        $checksData = [
            'included' => true,
            'settings' => null,
            'grades' => [],
            'items' => [],
            'tasks' => [
                'settings' => null,
                'items' => [],
            ],
        ];

        $settingsStmt = $db->prepare(
            "SELECT grading_enabled, pass_percent
             FROM lab_check_settings
             WHERE lab_id = :lab_id
             LIMIT 1"
        );
        $settingsStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
        $settingsStmt->execute();
        $settingsRow = $settingsStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($settingsRow)) {
            $checksData['settings'] = [
                'grading_enabled' => !empty($settingsRow['grading_enabled']),
                'pass_percent' => isset($settingsRow['pass_percent']) ? (float) $settingsRow['pass_percent'] : 60.0,
            ];
        }

        $gradesStmt = $db->prepare(
            "SELECT min_percent, grade_label, order_index
             FROM lab_check_grade_scales
             WHERE lab_id = :lab_id
             ORDER BY order_index ASC, min_percent DESC, id ASC"
        );
        $gradesStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
        $gradesStmt->execute();
        $gradeRows = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($gradeRows)) {
            $gradeRows = [];
        }
        foreach ($gradeRows as $grade) {
            $checksData['grades'][] = [
                'min_percent' => isset($grade['min_percent']) ? (float) $grade['min_percent'] : 0.0,
                'grade_label' => (string) ($grade['grade_label'] ?? ''),
                'order_index' => (int) ($grade['order_index'] ?? 0),
            ];
        }

        $itemsStmt = $db->prepare(
            "SELECT node_id, title, transport, shell_type, command_text, match_mode, expected_text, hint_text,
                    show_expected_to_learner, show_output_to_learner, points, timeout_seconds,
                    is_enabled, is_required, order_index, ssh_host, ssh_port, ssh_username, ssh_password
             FROM lab_check_items
             WHERE lab_id = :lab_id
             ORDER BY order_index ASC, created_at ASC, id ASC"
        );
        $itemsStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
        $itemsStmt->execute();
        $itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($itemRows)) {
            $itemRows = [];
        }
        foreach ($itemRows as $item) {
            $checksData['items'][] = [
                'node_id' => (string) ($item['node_id'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'transport' => (string) ($item['transport'] ?? 'auto'),
                'shell_type' => (string) ($item['shell_type'] ?? 'auto'),
                'command_text' => (string) ($item['command_text'] ?? ''),
                'match_mode' => (string) ($item['match_mode'] ?? 'contains'),
                'expected_text' => (string) ($item['expected_text'] ?? ''),
                'hint_text' => (string) ($item['hint_text'] ?? ''),
                'show_expected_to_learner' => !empty($item['show_expected_to_learner']),
                'show_output_to_learner' => !empty($item['show_output_to_learner']),
                'points' => (int) ($item['points'] ?? 0),
                'timeout_seconds' => (int) ($item['timeout_seconds'] ?? 12),
                'is_enabled' => !empty($item['is_enabled']),
                'is_required' => !empty($item['is_required']),
                'order_index' => (int) ($item['order_index'] ?? 0),
                'ssh_host' => (string) ($item['ssh_host'] ?? ''),
                'ssh_port' => ($item['ssh_port'] === null ? null : (int) $item['ssh_port']),
                'ssh_username' => (string) ($item['ssh_username'] ?? ''),
                'ssh_password' => mainExportIncludeCheckSecrets()
                    ? (
                        function_exists('labCheckDecryptSecret')
                            ? labCheckDecryptSecret((string) ($item['ssh_password'] ?? ''))
                            : (string) ($item['ssh_password'] ?? '')
                    )
                    : '',
            ];
        }

        $taskSettingsStmt = $db->prepare(
            "SELECT intro_text
             FROM lab_check_task_settings
             WHERE lab_id = :lab_id
             LIMIT 1"
        );
        $taskSettingsStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
        $taskSettingsStmt->execute();
        $taskSettingsRow = $taskSettingsStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($taskSettingsRow)) {
            $checksData['tasks']['settings'] = [
                'intro_text' => (string) ($taskSettingsRow['intro_text'] ?? ''),
            ];
        }

        $taskItemsStmt = $db->prepare(
            "SELECT task_text, is_enabled, order_index
             FROM lab_check_task_items
             WHERE lab_id = :lab_id
             ORDER BY order_index ASC, created_at ASC, id ASC"
        );
        $taskItemsStmt->bindValue(':lab_id', (string) $entry['id'], PDO::PARAM_STR);
        $taskItemsStmt->execute();
        $taskItemsRows = $taskItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($taskItemsRows)) {
            $taskItemsRows = [];
        }
        foreach ($taskItemsRows as $taskItem) {
            $checksData['tasks']['items'][] = [
                'task_text' => (string) ($taskItem['task_text'] ?? ''),
                'is_enabled' => !empty($taskItem['is_enabled']),
                'order_index' => (int) ($taskItem['order_index'] ?? 0),
            ];
        }
    }

    $payload = [
        'format' => 'eve_ng_v2_lab_export',
        'version' => 1,
        'exported_at' => gmdate('c'),
        'lab' => [
            'name' => (string) ($labRow['name'] ?? ''),
            'description' => (string) ($labRow['description'] ?? ''),
            'topology_locked' => !empty($labRow['topology_locked']),
            'topology_allow_wipe' => !empty($labRow['topology_allow_wipe']),
        ],
        'networks' => [],
        'nodes' => [],
        'ports' => [],
        'objects' => [],
        'checks' => $checksData,
    ];
    mainSafeProgressCallback($stageCallback, 'collecting_data', [
        'nodes_total' => count($nodeRows),
        'networks_total' => count($networkRows),
        'ports_total' => count($portRows),
        'objects_total' => count($objectRows),
    ]);

    foreach ($networkRows as $row) {
        $payload['networks'][] = [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'network_type' => (string) ($row['network_type'] ?? ''),
            'left_pos' => ($row['left_pos'] === null ? null : (int) $row['left_pos']),
            'top_pos' => ($row['top_pos'] === null ? null : (int) $row['top_pos']),
            'visibility' => (int) ($row['visibility'] ?? 1),
            'icon' => (string) ($row['icon'] ?? ''),
        ];
    }
    foreach ($nodeRows as $row) {
        $payload['nodes'][] = [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'node_type' => (string) ($row['node_type'] ?? ''),
            'template' => (string) ($row['template'] ?? ''),
            'image' => (string) ($row['image'] ?? ''),
            'icon' => (string) ($row['icon'] ?? ''),
            'console' => (string) ($row['console'] ?? ''),
            'left_pos' => ($row['left_pos'] === null ? null : (int) $row['left_pos']),
            'top_pos' => ($row['top_pos'] === null ? null : (int) $row['top_pos']),
            'delay_ms' => (int) ($row['delay_ms'] ?? 0),
            'ethernet_count' => ($row['ethernet_count'] === null ? null : (int) $row['ethernet_count']),
            'serial_count' => ($row['serial_count'] === null ? null : (int) $row['serial_count']),
            'cpu' => ($row['cpu'] === null ? null : (int) $row['cpu']),
            'ram_mb' => ($row['ram_mb'] === null ? null : (int) $row['ram_mb']),
            'nvram_mb' => ($row['nvram_mb'] === null ? null : (int) $row['nvram_mb']),
            'first_mac' => (string) ($row['first_mac'] ?? ''),
            'qemu_options' => (string) ($row['qemu_options'] ?? ''),
            'qemu_version' => (string) ($row['qemu_version'] ?? ''),
            'qemu_arch' => (string) ($row['qemu_arch'] ?? ''),
            'qemu_nic' => (string) ($row['qemu_nic'] ?? ''),
        ];
    }
    foreach ($portRows as $row) {
        $payload['ports'][] = [
            'id' => (string) ($row['id'] ?? ''),
            'node_id' => (string) ($row['node_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'port_type' => (string) ($row['port_type'] ?? ''),
            'network_id' => (string) ($row['network_id'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
    foreach ($objectRows as $row) {
        $payload['objects'][] = [
            'object_type' => (string) ($row['object_type'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'data_base64' => (string) ($row['data_base64'] ?? ''),
        ];
    }

    $workDir = '';
    try {
        $workDir = mainLabArchiveMakeTempDir('export');
        $bundleDir = rtrim($workDir, '/') . '/bundle';
        mainLabArchiveEnsureDir($bundleDir);

        $runtimeCopied = 0;
        $runtimeSkipped = 0;
        if ($includeRuntime) {
            $runtimeTotal = count($payload['nodes']);
            mainSafeProgressCallback($stageCallback, 'runtime_copy_start', [
                'current' => 0,
                'total' => $runtimeTotal,
                'copied' => 0,
                'skipped' => 0,
            ]);
            $runtimeDir = rtrim($bundleDir, '/') . '/runtime';
            mainLabArchiveEnsureDir($runtimeDir);
            $ownerUserId = (string) ($labRow['author_user_id'] ?? '');
            $runtimeCurrent = 0;
            foreach ($payload['nodes'] as $node) {
                $runtimeCurrent++;
                $oldNodeId = trim((string) ($node['id'] ?? ''));
                if ($oldNodeId === '') {
                    $runtimeSkipped++;
                    mainSafeProgressCallback($stageCallback, 'runtime_copy_progress', [
                        'current' => $runtimeCurrent,
                        'total' => $runtimeTotal,
                        'copied' => $runtimeCopied,
                        'skipped' => $runtimeSkipped,
                    ]);
                    continue;
                }
                $sourceNodeDir = mainRuntimeNodeSourceDir((string) $entry['id'], $oldNodeId, $ownerUserId);
                if ($sourceNodeDir === '') {
                    $runtimeSkipped++;
                    mainSafeProgressCallback($stageCallback, 'runtime_copy_progress', [
                        'current' => $runtimeCurrent,
                        'total' => $runtimeTotal,
                        'copied' => $runtimeCopied,
                        'skipped' => $runtimeSkipped,
                    ]);
                    continue;
                }
                $targetNodeDir = rtrim($runtimeDir, '/') . '/' . $oldNodeId;
                try {
                    mainCopyDirectoryRecursive($sourceNodeDir, $targetNodeDir);
                    mainRuntimeClearVolatileFiles($targetNodeDir);
                    $runtimeCopied++;
                } catch (Throwable $e) {
                    $runtimeSkipped++;
                }
                mainSafeProgressCallback($stageCallback, 'runtime_copy_progress', [
                    'current' => $runtimeCurrent,
                    'total' => $runtimeTotal,
                    'copied' => $runtimeCopied,
                    'skipped' => $runtimeSkipped,
                ]);
            }
        }

        mainSafeProgressCallback($stageCallback, 'writing_payload', []);
        $manifest = [
        'format' => 'eve_ng_v2_lab_export',
        'version' => 1,
        'created_at' => gmdate('c'),
        'lab_name' => (string) ($labRow['name'] ?? 'lab'),
        'includes' => [
            'runtime' => $includeRuntime,
            'checks' => $includeChecks,
        ],
        'stats' => [
            'nodes' => count($payload['nodes']),
            'networks' => count($payload['networks']),
            'ports' => count($payload['ports']),
            'objects' => count($payload['objects']),
            'checks_items' => (int) count((array) ($payload['checks']['items'] ?? [])),
            'checks_tasks_items' => (int) count((array) (($payload['checks']['tasks']['items'] ?? []))),
            'runtime_nodes_copied' => $runtimeCopied,
            'runtime_nodes_skipped' => $runtimeSkipped,
        ],
        ];

        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($manifestJson) || !is_string($payloadJson)) {
            throw new RuntimeException('Failed to prepare export payload');
        }
        if (@file_put_contents(rtrim($bundleDir, '/') . '/manifest.json', $manifestJson . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Failed to write export manifest');
        }
        if (@file_put_contents(rtrim($bundleDir, '/') . '/lab.json', $payloadJson . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Failed to write export data');
        }

        $safeName = mainLabArchiveSafeFilenamePart((string) ($labRow['name'] ?? 'lab'));
        $archiveName = $safeName . '-' . gmdate('Ymd-His') . '.evev2lab.tgz';
        $archivePath = rtrim($workDir, '/') . '/' . $archiveName;

        $bundleSizeBytes = mainDirectorySizeBytes($bundleDir);
        mainSafeProgressCallback($stageCallback, 'packing_archive_start', [
            'archive_name' => $archiveName,
            'total_bytes' => $bundleSizeBytes,
        ]);
        mainLabArchiveRunTarWithProgress(
            ['-czf', $archivePath, '-C', $bundleDir, '.'],
            $archivePath,
            $bundleSizeBytes,
            $stageCallback
        );

        clearstatcache(true, $archivePath);
        if (!is_file($archivePath)) {
            throw new RuntimeException('Failed to create lab archive');
        }

        $sizeBytes = (int) (@filesize($archivePath) ?: 0);
        mainSafeProgressCallback($stageCallback, 'done', [
            'archive_name' => $archiveName,
            'size_bytes' => $sizeBytes,
        ]);

        return [
            'archive_path' => $archivePath,
            'archive_name' => $archiveName,
            'content_type' => 'application/gzip',
            'size_bytes' => $sizeBytes,
            'work_dir' => $workDir,
        ];
    } catch (Throwable $e) {
        if ($workDir !== '') {
            mainLabArchiveRemoveDir($workDir);
        }
        throw $e;
    }
}

function cleanupMainLabArchiveExport(array $export): void
{
    $workDir = trim((string) ($export['work_dir'] ?? ''));
    if ($workDir !== '') {
        mainLabArchiveRemoveDir($workDir);
        return;
    }
    $archivePath = trim((string) ($export['archive_path'] ?? ''));
    if ($archivePath !== '' && is_file($archivePath)) {
        @unlink($archivePath);
    }
}

function importMainLabArchiveForViewer(
    PDO $db,
    array $viewer,
    string $targetPath,
    string $archivePath,
    string $labNameOverride = '',
    ?callable $stageCallback = null
): array
{
    mainSafeProgressCallback($stageCallback, 'validating_archive', []);
    $scope = mainResolveImportScopeForViewer($db, $viewer, $targetPath);
    $ownerUserId = (string) ($scope['owner_user_id'] ?? '');
    $relativePath = (string) ($scope['relative_path'] ?? '/');
    $pathPrefix = (string) ($scope['path_prefix'] ?? '');
    if ($ownerUserId === '') {
        throw new RuntimeException('Forbidden');
    }

    $relativePath = normalizeMainPath($relativePath);
    $archivePath = trim($archivePath);
    if ($archivePath === '' || !is_file($archivePath)) {
        throw new InvalidArgumentException('Invalid archive');
    }

    $workDir = mainLabArchiveMakeTempDir('import');
    $extractDir = rtrim($workDir, '/') . '/bundle';
    mainLabArchiveEnsureDir($extractDir);

    try {
        $entries = mainLabArchiveListEntries($archivePath);
    } catch (Throwable $e) {
        mainLabArchiveRemoveDir($workDir);
        throw new InvalidArgumentException('Invalid archive');
    }
    if (empty($entries)) {
        mainLabArchiveRemoveDir($workDir);
        throw new InvalidArgumentException('Invalid archive');
    }
    foreach ($entries as $entry) {
        if (!mainLabArchiveEntryIsSafe((string) $entry)) {
            mainLabArchiveRemoveDir($workDir);
            throw new InvalidArgumentException('Invalid archive');
        }
    }

    try {
        mainSafeProgressCallback($stageCallback, 'extracting_archive', []);
        mainLabArchiveRunTar(['-xzf', $archivePath, '-C', $extractDir]);
    } catch (Throwable $e) {
        mainLabArchiveRemoveDir($workDir);
        throw new InvalidArgumentException('Invalid archive');
    }

    $manifestPath = mainLabArchiveFindFile($extractDir, 'manifest.json');
    $payloadPath = mainLabArchiveFindFile($extractDir, 'lab.json');
    if ($manifestPath === '' || $payloadPath === '') {
        mainLabArchiveRemoveDir($workDir);
        throw new InvalidArgumentException('Invalid archive');
    }

    $manifestRaw = @file_get_contents($manifestPath);
    $payloadRaw = @file_get_contents($payloadPath);
    if (!is_string($manifestRaw) || !is_string($payloadRaw)) {
        mainLabArchiveRemoveDir($workDir);
        throw new InvalidArgumentException('Invalid archive');
    }
    $manifest = json_decode($manifestRaw, true);
    $payload = json_decode($payloadRaw, true);
    if (!is_array($manifest) || !is_array($payload)) {
        mainLabArchiveRemoveDir($workDir);
        throw new InvalidArgumentException('Invalid archive');
    }
    if ((string) ($manifest['format'] ?? '') !== 'eve_ng_v2_lab_export') {
        mainLabArchiveRemoveDir($workDir);
        throw new InvalidArgumentException('Invalid archive');
    }

    $labPayload = is_array($payload['lab'] ?? null) ? $payload['lab'] : [];
    $normalizedNameOverride = mainNormalizeImportLabName($labNameOverride);
    if ($normalizedNameOverride !== '') {
        $baseName = $normalizedNameOverride;
    } else {
        $baseNameRaw = trim((string) ($labPayload['name'] ?? ''));
        if ($baseNameRaw === '') {
            $baseNameRaw = trim((string) ($manifest['lab_name'] ?? ''));
        }
        if ($baseNameRaw === '') {
            $baseNameRaw = 'imported-lab';
        }
        try {
            $baseName = normalizeExplorerName($baseNameRaw);
        } catch (Throwable $e) {
            $baseName = 'imported-lab';
        }
    }
    $description = trim((string) ($labPayload['description'] ?? ''));
    if ($description === '') {
        $description = null;
    }

    $parentFolder = resolveFolderByPath($db, $ownerUserId, $relativePath);
    $parentId = $parentFolder ? (string) $parentFolder['id'] : null;
    $newLabName = mainUniqueLabName($db, $ownerUserId, $baseName, $parentId);

    $canTopologyLock = rbacUserHasPermission($db, $viewer, 'main.lab.topology_lock.manage');
    $topologyLocked = $canTopologyLock ? !empty($labPayload['topology_locked']) : false;
    $topologyAllowWipe = $canTopologyLock ? !empty($labPayload['topology_allow_wipe']) : false;
    if (!$topologyLocked) {
        $topologyAllowWipe = false;
    }

    $networks = is_array($payload['networks'] ?? null) ? $payload['networks'] : [];
    $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
    $ports = is_array($payload['ports'] ?? null) ? $payload['ports'] : [];
    $objects = is_array($payload['objects'] ?? null) ? $payload['objects'] : [];
    $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : ['included' => false];
    $checksSettings = is_array($checks['settings'] ?? null) ? $checks['settings'] : null;
    $checkGrades = is_array($checks['grades'] ?? null) ? $checks['grades'] : [];
    $checkItems = is_array($checks['items'] ?? null) ? $checks['items'] : [];
    $checkTasks = is_array($checks['tasks'] ?? null) ? $checks['tasks'] : [];
    $checkTaskSettings = is_array($checkTasks['settings'] ?? null) ? $checkTasks['settings'] : [];
    $checkTaskItems = is_array($checkTasks['items'] ?? null) ? $checkTasks['items'] : [];

    $dbImportTotal = 1 + count($networks) + count($nodes) + count($ports) + count($objects);
    if (!empty($checks['included'])) {
        if (is_array($checksSettings)) {
            $dbImportTotal += 1;
        }
        $dbImportTotal += 1; // task settings upsert
        $dbImportTotal += count($checkGrades) + count($checkItems) + count($checkTaskItems);
    }
    if ($dbImportTotal < 1) {
        $dbImportTotal = 1;
    }
    $runtimeEstimateTotal = count($nodes);
    if ($runtimeEstimateTotal < 0) {
        $runtimeEstimateTotal = 0;
    }
    mainSafeProgressCallback($stageCallback, 'payload_loaded', [
        'db_total' => $dbImportTotal,
        'runtime_total' => $runtimeEstimateTotal,
        'stats' => [
            'networks_total' => count($networks),
            'nodes_total' => count($nodes),
            'ports_total' => count($ports),
            'objects_total' => count($objects),
            'check_grades_total' => count($checkGrades),
            'check_items_total' => count($checkItems),
            'check_task_items_total' => count($checkTaskItems),
        ],
    ]);

    if (count($nodes) > 100) {
        mainLabArchiveRemoveDir($workDir);
        throw new RuntimeException('Lab node limit is 100');
    }

    $dbImportCurrent = 0;
    mainSafeProgressCallback($stageCallback, 'db_import_start', [
        'current' => 0,
        'total' => $dbImportTotal,
    ]);
    $db->beginTransaction();
    $newLabId = '';
    $updatedAt = '';
    $networkIdMap = [];
    $nodeIdMap = [];
    $portIdMap = [];

    try {
        $insertLab = $db->prepare(
            "INSERT INTO labs (name, description, author_user_id, folder_id, is_shared, is_mirror, collaborate_allowed, topology_locked, topology_allow_wipe)
             VALUES (:name, :description, :author_user_id, :folder_id, FALSE, FALSE, FALSE, :topology_locked, :topology_allow_wipe)
             RETURNING id, updated_at"
        );
        $insertLab->bindValue(':name', $newLabName, PDO::PARAM_STR);
        if ($description === null) {
            $insertLab->bindValue(':description', null, PDO::PARAM_NULL);
        } else {
            $insertLab->bindValue(':description', $description, PDO::PARAM_STR);
        }
        $insertLab->bindValue(':author_user_id', $ownerUserId, PDO::PARAM_STR);
        if ($parentId === null) {
            $insertLab->bindValue(':folder_id', null, PDO::PARAM_NULL);
        } else {
            $insertLab->bindValue(':folder_id', $parentId, PDO::PARAM_STR);
        }
        $insertLab->bindValue(':topology_locked', $topologyLocked, PDO::PARAM_BOOL);
        $insertLab->bindValue(':topology_allow_wipe', $topologyAllowWipe, PDO::PARAM_BOOL);
        $insertLab->execute();
        $labRow = $insertLab->fetch(PDO::FETCH_ASSOC);
        if ($labRow === false || empty($labRow['id'])) {
            throw new RuntimeException('Failed to import lab');
        }
        $newLabId = (string) $labRow['id'];
        $updatedAt = (string) ($labRow['updated_at'] ?? '');
        $dbImportCurrent++;
        mainSafeProgressCallback($stageCallback, 'db_import_progress', [
            'current' => $dbImportCurrent,
            'total' => $dbImportTotal,
        ]);

        $insertNetwork = $db->prepare(
            "INSERT INTO lab_networks (lab_id, name, network_type, left_pos, top_pos, visibility, icon)
             VALUES (:lab_id, :name, :network_type, :left_pos, :top_pos, :visibility, :icon)
             RETURNING id"
        );
        $networkOrder = 0;
        foreach ($networks as $row) {
            $dbImportCurrent++;
            if (!is_array($row)) {
                mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                    'current' => $dbImportCurrent,
                    'total' => $dbImportTotal,
                ]);
                continue;
            }
            $oldNetworkId = trim((string) ($row['id'] ?? ''));
            if ($oldNetworkId === '') {
                $oldNetworkId = 'net:' . $networkOrder;
            }
            $networkOrder++;

            $name = trim((string) ($row['name'] ?? ''));
            $networkType = strtolower(trim((string) ($row['network_type'] ?? '')));
            if ($networkType === '') {
                $networkType = 'bridge';
            }
            $visibility = isset($row['visibility']) ? (int) $row['visibility'] : 1;

            $insertNetwork->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
            $insertNetwork->bindValue(':name', $name, PDO::PARAM_STR);
            $insertNetwork->bindValue(':network_type', substr($networkType, 0, 64), PDO::PARAM_STR);
            if (!array_key_exists('left_pos', $row) || $row['left_pos'] === null || $row['left_pos'] === '') {
                $insertNetwork->bindValue(':left_pos', null, PDO::PARAM_NULL);
            } else {
                $insertNetwork->bindValue(':left_pos', (int) $row['left_pos'], PDO::PARAM_INT);
            }
            if (!array_key_exists('top_pos', $row) || $row['top_pos'] === null || $row['top_pos'] === '') {
                $insertNetwork->bindValue(':top_pos', null, PDO::PARAM_NULL);
            } else {
                $insertNetwork->bindValue(':top_pos', (int) $row['top_pos'], PDO::PARAM_INT);
            }
            $insertNetwork->bindValue(':visibility', $visibility, PDO::PARAM_INT);
            $insertNetwork->bindValue(':icon', (string) ($row['icon'] ?? ''), PDO::PARAM_STR);
            $insertNetwork->execute();
            $newNetwork = $insertNetwork->fetch(PDO::FETCH_ASSOC);
            if ($newNetwork === false || empty($newNetwork['id'])) {
                throw new RuntimeException('Failed to import networks');
            }
            $networkIdMap[$oldNetworkId] = (string) $newNetwork['id'];
            mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                'current' => $dbImportCurrent,
                'total' => $dbImportTotal,
            ]);
        }

        $insertNode = $db->prepare(
            "INSERT INTO lab_nodes (
                lab_id, name, node_type, template, image, icon, console,
                left_pos, top_pos, delay_ms, ethernet_count, serial_count,
                cpu, ram_mb, nvram_mb, first_mac, qemu_options, qemu_version, qemu_arch, qemu_nic,
                is_running, power_state, last_error, power_updated_at,
                runtime_pid, runtime_console_port, runtime_started_at, runtime_stopped_at
             ) VALUES (
                :lab_id, :name, :node_type, :template, :image, :icon, :console,
                :left_pos, :top_pos, :delay_ms, :ethernet_count, :serial_count,
                :cpu, :ram_mb, :nvram_mb, :first_mac, :qemu_options, :qemu_version, :qemu_arch, :qemu_nic,
                FALSE, 'stopped', NULL, NOW(),
                NULL, NULL, NULL, NULL
             )
             RETURNING id"
        );
        $nodeOrder = 0;
        foreach ($nodes as $row) {
            $dbImportCurrent++;
            if (!is_array($row)) {
                mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                    'current' => $dbImportCurrent,
                    'total' => $dbImportTotal,
                ]);
                continue;
            }
            $oldNodeId = trim((string) ($row['id'] ?? ''));
            if ($oldNodeId === '') {
                $oldNodeId = 'node:' . $nodeOrder;
            }
            $nodeOrder++;

            $nodeType = strtolower(trim((string) ($row['node_type'] ?? '')));
            if ($nodeType === '') {
                mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                    'current' => $dbImportCurrent,
                    'total' => $dbImportTotal,
                ]);
                continue;
            }

            $insertNode->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
            $insertNode->bindValue(':name', substr((string) ($row['name'] ?? ''), 0, 255), PDO::PARAM_STR);
            $insertNode->bindValue(':node_type', substr($nodeType, 0, 64), PDO::PARAM_STR);
            $insertNode->bindValue(':template', substr((string) ($row['template'] ?? ''), 0, 128), PDO::PARAM_STR);
            $insertNode->bindValue(':image', substr((string) ($row['image'] ?? ''), 0, 255), PDO::PARAM_STR);
            $insertNode->bindValue(':icon', substr((string) ($row['icon'] ?? ''), 0, 255), PDO::PARAM_STR);
            $insertNode->bindValue(':console', substr((string) ($row['console'] ?? ''), 0, 32), PDO::PARAM_STR);

            if (!array_key_exists('left_pos', $row) || $row['left_pos'] === null || $row['left_pos'] === '') {
                $insertNode->bindValue(':left_pos', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':left_pos', (int) $row['left_pos'], PDO::PARAM_INT);
            }
            if (!array_key_exists('top_pos', $row) || $row['top_pos'] === null || $row['top_pos'] === '') {
                $insertNode->bindValue(':top_pos', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':top_pos', (int) $row['top_pos'], PDO::PARAM_INT);
            }
            $insertNode->bindValue(':delay_ms', isset($row['delay_ms']) ? (int) $row['delay_ms'] : 0, PDO::PARAM_INT);

            if (!array_key_exists('ethernet_count', $row) || $row['ethernet_count'] === null || $row['ethernet_count'] === '') {
                $insertNode->bindValue(':ethernet_count', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':ethernet_count', (int) $row['ethernet_count'], PDO::PARAM_INT);
            }
            if (!array_key_exists('serial_count', $row) || $row['serial_count'] === null || $row['serial_count'] === '') {
                $insertNode->bindValue(':serial_count', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':serial_count', (int) $row['serial_count'], PDO::PARAM_INT);
            }
            if (!array_key_exists('cpu', $row) || $row['cpu'] === null || $row['cpu'] === '') {
                $insertNode->bindValue(':cpu', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':cpu', (int) $row['cpu'], PDO::PARAM_INT);
            }
            if (!array_key_exists('ram_mb', $row) || $row['ram_mb'] === null || $row['ram_mb'] === '') {
                $insertNode->bindValue(':ram_mb', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':ram_mb', (int) $row['ram_mb'], PDO::PARAM_INT);
            }
            if (!array_key_exists('nvram_mb', $row) || $row['nvram_mb'] === null || $row['nvram_mb'] === '') {
                $insertNode->bindValue(':nvram_mb', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':nvram_mb', (int) $row['nvram_mb'], PDO::PARAM_INT);
            }

            $firstMac = strtolower(trim((string) ($row['first_mac'] ?? '')));
            if ($firstMac === '' || preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $firstMac) !== 1) {
                $insertNode->bindValue(':first_mac', null, PDO::PARAM_NULL);
            } else {
                $insertNode->bindValue(':first_mac', $firstMac, PDO::PARAM_STR);
            }
            $insertNode->bindValue(':qemu_options', (string) ($row['qemu_options'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':qemu_version', (string) ($row['qemu_version'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':qemu_arch', (string) ($row['qemu_arch'] ?? ''), PDO::PARAM_STR);
            $insertNode->bindValue(':qemu_nic', (string) ($row['qemu_nic'] ?? ''), PDO::PARAM_STR);
            $insertNode->execute();
            $newNode = $insertNode->fetch(PDO::FETCH_ASSOC);
            if ($newNode === false || empty($newNode['id'])) {
                throw new RuntimeException('Failed to import nodes');
            }
            $nodeIdMap[$oldNodeId] = (string) $newNode['id'];
            mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                'current' => $dbImportCurrent,
                'total' => $dbImportTotal,
            ]);
        }

        $insertPort = $db->prepare(
            "INSERT INTO lab_node_ports (node_id, name, port_type, network_id, created_at, updated_at)
             VALUES (:node_id, :name, :port_type, :network_id, :created_at, :updated_at)
             RETURNING id"
        );
        $portOrder = 0;
        foreach ($ports as $row) {
            $dbImportCurrent++;
            if (!is_array($row)) {
                mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                    'current' => $dbImportCurrent,
                    'total' => $dbImportTotal,
                ]);
                continue;
            }
            $oldNodeId = trim((string) ($row['node_id'] ?? ''));
            if ($oldNodeId === '' || !isset($nodeIdMap[$oldNodeId])) {
                mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                    'current' => $dbImportCurrent,
                    'total' => $dbImportTotal,
                ]);
                continue;
            }
            $oldPortId = trim((string) ($row['id'] ?? ''));
            if ($oldPortId === '') {
                $oldPortId = 'port:' . $portOrder;
            }

            $insertPort->bindValue(':node_id', (string) $nodeIdMap[$oldNodeId], PDO::PARAM_STR);
            $insertPort->bindValue(':name', substr((string) ($row['name'] ?? ''), 0, 64), PDO::PARAM_STR);
            $insertPort->bindValue(':port_type', substr((string) ($row['port_type'] ?? ''), 0, 32), PDO::PARAM_STR);

            $oldNetworkId = trim((string) ($row['network_id'] ?? ''));
            if ($oldNetworkId !== '' && isset($networkIdMap[$oldNetworkId])) {
                $insertPort->bindValue(':network_id', (string) $networkIdMap[$oldNetworkId], PDO::PARAM_STR);
            } else {
                $insertPort->bindValue(':network_id', null, PDO::PARAM_NULL);
            }

            $createdAt = trim((string) ($row['created_at'] ?? ''));
            if ($createdAt === '') {
                $insertPort->bindValue(':created_at', null, PDO::PARAM_NULL);
            } else {
                $insertPort->bindValue(':created_at', mainShiftTimestampByMicroseconds($createdAt, $portOrder), PDO::PARAM_STR);
            }
            $updatedAtPort = trim((string) ($row['updated_at'] ?? ''));
            if ($updatedAtPort === '') {
                $insertPort->bindValue(':updated_at', null, PDO::PARAM_NULL);
            } else {
                $insertPort->bindValue(':updated_at', $updatedAtPort, PDO::PARAM_STR);
            }

            $insertPort->execute();
            $newPort = $insertPort->fetch(PDO::FETCH_ASSOC);
            if ($newPort === false || empty($newPort['id'])) {
                throw new RuntimeException('Failed to import ports');
            }
            $portIdMap[$oldPortId] = (string) $newPort['id'];
            $portOrder++;
            mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                'current' => $dbImportCurrent,
                'total' => $dbImportTotal,
            ]);
        }

        $insertObject = $db->prepare(
            "INSERT INTO lab_objects (lab_id, object_type, name, data_base64)
             VALUES (:lab_id, :object_type, :name, :data_base64)"
        );
        foreach ($objects as $row) {
            $dbImportCurrent++;
            if (!is_array($row)) {
                mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                    'current' => $dbImportCurrent,
                    'total' => $dbImportTotal,
                ]);
                continue;
            }
            $objectType = (string) ($row['object_type'] ?? '');
            $objectName = (string) ($row['name'] ?? '');
            $dataBase64 = (string) ($row['data_base64'] ?? '');
            if (strtolower(trim($objectType)) === 'link_layout') {
                $layout = mainDecodeBase64Json($dataBase64);
                if (!empty($layout)) {
                    $remapped = mainRemapLinkLayoutForCopy($layout, $networkIdMap, $portIdMap);
                    $encoded = mainEncodeBase64Json($remapped);
                    if ($encoded !== '') {
                        $dataBase64 = $encoded;
                    }
                }
            }
            $insertObject->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
            $insertObject->bindValue(':object_type', $objectType, PDO::PARAM_STR);
            $insertObject->bindValue(':name', $objectName, PDO::PARAM_STR);
            $insertObject->bindValue(':data_base64', $dataBase64, PDO::PARAM_STR);
            $insertObject->execute();
            mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                'current' => $dbImportCurrent,
                'total' => $dbImportTotal,
            ]);
        }

        if (!empty($checks['included'])) {
            $settings = $checksSettings;
            if (is_array($settings)) {
                $upsertSettings = $db->prepare(
                    "INSERT INTO lab_check_settings (lab_id, grading_enabled, pass_percent, updated_by, created_at, updated_at)
                     VALUES (:lab_id, :grading_enabled, :pass_percent, :updated_by, NOW(), NOW())
                     ON CONFLICT (lab_id)
                     DO UPDATE SET grading_enabled = EXCLUDED.grading_enabled,
                                   pass_percent = EXCLUDED.pass_percent,
                                   updated_by = EXCLUDED.updated_by,
                                   updated_at = NOW()"
                );
                $upsertSettings->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
                $upsertSettings->bindValue(':grading_enabled', !empty($settings['grading_enabled']), PDO::PARAM_BOOL);
                $upsertSettings->bindValue(':pass_percent', isset($settings['pass_percent']) ? (float) $settings['pass_percent'] : 60.0);
                $upsertSettings->bindValue(':updated_by', (string) ($viewer['id'] ?? ''), PDO::PARAM_STR);
                $upsertSettings->execute();
                $dbImportCurrent++;
                mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                    'current' => $dbImportCurrent,
                    'total' => $dbImportTotal,
                ]);
            }

            $grades = $checkGrades;
            if (!empty($grades)) {
                $deleteGrades = $db->prepare('DELETE FROM lab_check_grade_scales WHERE lab_id = :lab_id');
                $deleteGrades->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
                $deleteGrades->execute();

                $insertGrade = $db->prepare(
                    "INSERT INTO lab_check_grade_scales (lab_id, min_percent, grade_label, order_index, created_at, updated_at)
                     VALUES (:lab_id, :min_percent, :grade_label, :order_index, NOW(), NOW())"
                );
                foreach ($grades as $grade) {
                    $dbImportCurrent++;
                    if (!is_array($grade)) {
                        mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                            'current' => $dbImportCurrent,
                            'total' => $dbImportTotal,
                        ]);
                        continue;
                    }
                    $insertGrade->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
                    $insertGrade->bindValue(':min_percent', isset($grade['min_percent']) ? (float) $grade['min_percent'] : 0.0);
                    $insertGrade->bindValue(':grade_label', (string) ($grade['grade_label'] ?? ''), PDO::PARAM_STR);
                    $insertGrade->bindValue(':order_index', isset($grade['order_index']) ? (int) $grade['order_index'] : 0, PDO::PARAM_INT);
                    $insertGrade->execute();
                    mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                        'current' => $dbImportCurrent,
                        'total' => $dbImportTotal,
                    ]);
                }
            }

            $items = $checkItems;
            if (!empty($items)) {
                $deleteItems = $db->prepare('DELETE FROM lab_check_items WHERE lab_id = :lab_id');
                $deleteItems->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
                $deleteItems->execute();

                $insertItem = $db->prepare(
                    "INSERT INTO lab_check_items (
                        lab_id, node_id, title, transport, shell_type, command_text, match_mode, expected_text, hint_text,
                        show_expected_to_learner, show_output_to_learner, points, timeout_seconds, is_enabled, is_required,
                        order_index, ssh_host, ssh_port, ssh_username, ssh_password, created_by, updated_by, created_at, updated_at
                    ) VALUES (
                        :lab_id, :node_id, :title, :transport, :shell_type, :command_text, :match_mode, :expected_text, :hint_text,
                        :show_expected_to_learner, :show_output_to_learner, :points, :timeout_seconds, :is_enabled, :is_required,
                        :order_index, :ssh_host, :ssh_port, :ssh_username, :ssh_password, :created_by, :updated_by, NOW(), NOW()
                    )"
                );

                $orderIndex = 0;
                foreach ($items as $item) {
                    $dbImportCurrent++;
                    if (!is_array($item)) {
                        mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                            'current' => $dbImportCurrent,
                            'total' => $dbImportTotal,
                        ]);
                        continue;
                    }
                    $oldNodeId = trim((string) ($item['node_id'] ?? ''));
                    if ($oldNodeId === '' || !isset($nodeIdMap[$oldNodeId])) {
                        mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                            'current' => $dbImportCurrent,
                            'total' => $dbImportTotal,
                        ]);
                        continue;
                    }
                    $insertItem->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
                    $insertItem->bindValue(':node_id', (string) $nodeIdMap[$oldNodeId], PDO::PARAM_STR);
                    $insertItem->bindValue(':title', (string) ($item['title'] ?? ''), PDO::PARAM_STR);
                    $insertItem->bindValue(':transport', (string) ($item['transport'] ?? 'auto'), PDO::PARAM_STR);
                    $insertItem->bindValue(':shell_type', (string) ($item['shell_type'] ?? 'auto'), PDO::PARAM_STR);
                    $insertItem->bindValue(':command_text', (string) ($item['command_text'] ?? ''), PDO::PARAM_STR);
                    $insertItem->bindValue(':match_mode', (string) ($item['match_mode'] ?? 'contains'), PDO::PARAM_STR);
                    $insertItem->bindValue(':expected_text', (string) ($item['expected_text'] ?? ''), PDO::PARAM_STR);
                    $insertItem->bindValue(':hint_text', (string) ($item['hint_text'] ?? ''), PDO::PARAM_STR);
                    $insertItem->bindValue(':show_expected_to_learner', !empty($item['show_expected_to_learner']), PDO::PARAM_BOOL);
                    $insertItem->bindValue(':show_output_to_learner', !empty($item['show_output_to_learner']), PDO::PARAM_BOOL);
                    $insertItem->bindValue(':points', isset($item['points']) ? (int) $item['points'] : 0, PDO::PARAM_INT);
                    $insertItem->bindValue(':timeout_seconds', isset($item['timeout_seconds']) ? (int) $item['timeout_seconds'] : 12, PDO::PARAM_INT);
                    $insertItem->bindValue(':is_enabled', !empty($item['is_enabled']), PDO::PARAM_BOOL);
                    $insertItem->bindValue(':is_required', !empty($item['is_required']), PDO::PARAM_BOOL);
                    $insertItem->bindValue(':order_index', $orderIndex, PDO::PARAM_INT);

                    $sshHost = trim((string) ($item['ssh_host'] ?? ''));
                    if ($sshHost === '') {
                        $insertItem->bindValue(':ssh_host', null, PDO::PARAM_NULL);
                        $insertItem->bindValue(':ssh_port', null, PDO::PARAM_NULL);
                        $insertItem->bindValue(':ssh_username', null, PDO::PARAM_NULL);
                        $insertItem->bindValue(':ssh_password', null, PDO::PARAM_NULL);
                    } else {
                        $insertItem->bindValue(':ssh_host', $sshHost, PDO::PARAM_STR);
                        $portValue = isset($item['ssh_port']) ? (int) $item['ssh_port'] : 22;
                        if ($portValue <= 0 || $portValue > 65535) {
                            $portValue = 22;
                        }
                        $insertItem->bindValue(':ssh_port', $portValue, PDO::PARAM_INT);
                        $sshUsername = trim((string) ($item['ssh_username'] ?? ''));
                        if ($sshUsername === '') {
                            $insertItem->bindValue(':ssh_username', null, PDO::PARAM_NULL);
                        } else {
                            $insertItem->bindValue(':ssh_username', $sshUsername, PDO::PARAM_STR);
                        }
                        $sshPassword = (string) ($item['ssh_password'] ?? '');
                        if ($sshPassword === '') {
                            $insertItem->bindValue(':ssh_password', null, PDO::PARAM_NULL);
                        } else {
                            $insertItem->bindValue(':ssh_password', $sshPassword, PDO::PARAM_STR);
                        }
                    }

                    $actorUserId = trim((string) ($viewer['id'] ?? ''));
                    if ($actorUserId === '') {
                        $insertItem->bindValue(':created_by', null, PDO::PARAM_NULL);
                        $insertItem->bindValue(':updated_by', null, PDO::PARAM_NULL);
                    } else {
                        $insertItem->bindValue(':created_by', $actorUserId, PDO::PARAM_STR);
                        $insertItem->bindValue(':updated_by', $actorUserId, PDO::PARAM_STR);
                    }
                    $insertItem->execute();
                    $orderIndex++;
                    mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                        'current' => $dbImportCurrent,
                        'total' => $dbImportTotal,
                    ]);
                }
            }

            $taskSettings = $checkTaskSettings;
            $taskItems = $checkTaskItems;
            $actorUserId = trim((string) ($viewer['id'] ?? ''));

            $taskIntroText = trim((string) ($taskSettings['intro_text'] ?? ''));
            if (strlen($taskIntroText) > 24000) {
                $taskIntroText = substr($taskIntroText, 0, 24000);
            }

            $upsertTaskSettings = $db->prepare(
                "INSERT INTO lab_check_task_settings (lab_id, intro_text, updated_by, created_at, updated_at)
                 VALUES (:lab_id, :intro_text, :updated_by, NOW(), NOW())
                 ON CONFLICT (lab_id)
                 DO UPDATE SET intro_text = EXCLUDED.intro_text,
                               updated_by = EXCLUDED.updated_by,
                               updated_at = NOW()"
            );
            $upsertTaskSettings->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
            $upsertTaskSettings->bindValue(':intro_text', $taskIntroText, PDO::PARAM_STR);
            if ($actorUserId === '') {
                $upsertTaskSettings->bindValue(':updated_by', null, PDO::PARAM_NULL);
            } else {
                $upsertTaskSettings->bindValue(':updated_by', $actorUserId, PDO::PARAM_STR);
            }
            $upsertTaskSettings->execute();
            $dbImportCurrent++;
            mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                'current' => $dbImportCurrent,
                'total' => $dbImportTotal,
            ]);

            $deleteTaskItems = $db->prepare('DELETE FROM lab_check_task_items WHERE lab_id = :lab_id');
            $deleteTaskItems->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
            $deleteTaskItems->execute();

            if (!empty($taskItems)) {
                $insertTaskItem = $db->prepare(
                    "INSERT INTO lab_check_task_items (
                        lab_id, task_text, is_enabled, order_index, created_by, updated_by, created_at, updated_at
                    ) VALUES (
                        :lab_id, :task_text, :is_enabled, :order_index, :created_by, :updated_by, NOW(), NOW()
                    )"
                );
                $taskOrderIndex = 0;
                foreach ($taskItems as $taskItem) {
                    $dbImportCurrent++;
                    if (!is_array($taskItem)) {
                        mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                            'current' => $dbImportCurrent,
                            'total' => $dbImportTotal,
                        ]);
                        continue;
                    }
                    $taskText = trim((string) ($taskItem['task_text'] ?? $taskItem['text'] ?? ''));
                    if ($taskText === '') {
                        mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                            'current' => $dbImportCurrent,
                            'total' => $dbImportTotal,
                        ]);
                        continue;
                    }
                    if (strlen($taskText) > 4000) {
                        $taskText = substr($taskText, 0, 4000);
                    }
                    $insertTaskItem->bindValue(':lab_id', $newLabId, PDO::PARAM_STR);
                    $insertTaskItem->bindValue(':task_text', $taskText, PDO::PARAM_STR);
                    $insertTaskItem->bindValue(':is_enabled', !empty($taskItem['is_enabled']), PDO::PARAM_BOOL);
                    $insertTaskItem->bindValue(':order_index', $taskOrderIndex, PDO::PARAM_INT);
                    if ($actorUserId === '') {
                        $insertTaskItem->bindValue(':created_by', null, PDO::PARAM_NULL);
                        $insertTaskItem->bindValue(':updated_by', null, PDO::PARAM_NULL);
                    } else {
                        $insertTaskItem->bindValue(':created_by', $actorUserId, PDO::PARAM_STR);
                        $insertTaskItem->bindValue(':updated_by', $actorUserId, PDO::PARAM_STR);
                    }
                    $insertTaskItem->execute();
                    $taskOrderIndex++;
                    mainSafeProgressCallback($stageCallback, 'db_import_progress', [
                        'current' => $dbImportCurrent,
                        'total' => $dbImportTotal,
                    ]);
                }
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        mainLabArchiveRemoveDir($workDir);
        throw $e;
    }

    mainSafeProgressCallback($stageCallback, 'db_import_done', [
        'current' => $dbImportCurrent,
        'total' => $dbImportTotal,
    ]);

    $runtimeRoot = rtrim($extractDir, '/') . '/runtime';
    $runtimeCopied = 0;
    $runtimeSkipped = 0;
    $runtimeProcessed = 0;
    $runtimeTotal = count($nodeIdMap);
    mainSafeProgressCallback($stageCallback, 'runtime_copy_start', [
        'current' => 0,
        'total' => $runtimeTotal,
        'copied' => 0,
        'skipped' => 0,
    ]);
    if ($runtimeTotal > 0) {
        if (!is_dir($runtimeRoot)) {
            $runtimeSkipped = $runtimeTotal;
            $runtimeProcessed = $runtimeTotal;
            mainSafeProgressCallback($stageCallback, 'runtime_copy_progress', [
                'current' => $runtimeProcessed,
                'total' => $runtimeTotal,
                'copied' => $runtimeCopied,
                'skipped' => $runtimeSkipped,
            ]);
        } else {
            foreach ($nodeIdMap as $oldNodeId => $newNodeId) {
                $safeSourceKey = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $oldNodeId);
                if (!is_string($safeSourceKey) || $safeSourceKey === '') {
                    $runtimeSkipped++;
                    $runtimeProcessed++;
                    mainSafeProgressCallback($stageCallback, 'runtime_copy_progress', [
                        'current' => $runtimeProcessed,
                        'total' => $runtimeTotal,
                        'copied' => $runtimeCopied,
                        'skipped' => $runtimeSkipped,
                    ]);
                    continue;
                }
                $sourceNodeDir = rtrim($runtimeRoot, '/') . '/' . $safeSourceKey;
                if (!is_dir($sourceNodeDir)) {
                    $runtimeSkipped++;
                    $runtimeProcessed++;
                    mainSafeProgressCallback($stageCallback, 'runtime_copy_progress', [
                        'current' => $runtimeProcessed,
                        'total' => $runtimeTotal,
                        'copied' => $runtimeCopied,
                        'skipped' => $runtimeSkipped,
                    ]);
                    continue;
                }
                $targetNodeDir = v2RuntimeNodeDir($newLabId, (string) $newNodeId, $ownerUserId);
                try {
                    mainCopyDirectoryRecursive($sourceNodeDir, $targetNodeDir);
                    mainRuntimeClearVolatileFiles($targetNodeDir);
                    $runtimeCopied++;
                } catch (Throwable $e) {
                    $runtimeSkipped++;
                }
                $runtimeProcessed++;
                mainSafeProgressCallback($stageCallback, 'runtime_copy_progress', [
                    'current' => $runtimeProcessed,
                    'total' => $runtimeTotal,
                    'copied' => $runtimeCopied,
                    'skipped' => $runtimeSkipped,
                ]);
            }
        }
    }

    mainLabArchiveRemoveDir($workDir);

    $basePath = ($relativePath === '/' ? '' : $relativePath) . '/' . $newLabName;
    $resultPath = ($pathPrefix === '') ? $basePath : remapScopedPath($pathPrefix, $basePath, false);

    $result = [
        'id' => $newLabId,
        'type' => 'lab',
        'name' => $newLabName,
        'updated' => $updatedAt,
        'path' => $resultPath,
        'import' => [
            'runtime_nodes_copied' => $runtimeCopied,
            'runtime_nodes_skipped' => $runtimeSkipped,
            'checks_included' => !empty($checks['included']),
            'checks_tasks_items' => (int) count((array) (($checks['tasks']['items'] ?? []))),
        ],
    ];
    mainSafeProgressCallback($stageCallback, 'done', [
        'result' => $result,
        'runtime_copy' => [
            'current' => $runtimeProcessed,
            'total' => $runtimeTotal,
            'copied' => $runtimeCopied,
            'skipped' => $runtimeSkipped,
        ],
        'db_import' => [
            'current' => $dbImportCurrent,
            'total' => $dbImportTotal,
        ],
    ]);
    return $result;
}
