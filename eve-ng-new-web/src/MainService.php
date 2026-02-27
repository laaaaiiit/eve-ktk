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
    array $sharedWithUsernames = []
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
        $sql = "INSERT INTO labs (name, description, author_user_id, folder_id, is_shared, is_mirror, collaborate_allowed)
                VALUES (:name, :description, :author_user_id, :folder_id, :is_shared, FALSE, :collaborate_allowed)
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

    if ($role !== 'admin') {
        return listMainEntriesForUser($db, $viewerId, $path);
    }

    $scope = parseAdminExplorerPath($path);
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

    $relativePath = (string) $scope['relative_path'];
    try {
        $data = listMainEntriesForUser($db, (string) $target['id'], $relativePath);
    } catch (RuntimeException $e) {
        throw new RuntimeException('Path not found');
    }
    $prefix = (string) $scope['prefix'];

    $entries = array_map(static function ($entry) use ($prefix) {
        $entry['path'] = remapScopedPath($prefix, (string) ($entry['path'] ?? '/'), false);
        if (($entry['type'] ?? '') === 'lab') {
            $entry['can_stop_nodes'] = false;
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
        return createMainFolder($db, $viewerId, $path, $name);
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
    array $sharedWithUsernames = []
): array
{
    $role = strtolower(trim((string) ($viewer['role_name'] ?? $viewer['role'] ?? '')));
    $viewerId = (string) ($viewer['id'] ?? '');
    $canPublish = rbacUserHasPermission($db, $viewer, 'main.lab.publish');
    $canShare = rbacUserHasPermission($db, $viewer, 'main.lab.share');
    if ($viewerId === '') {
        throw new InvalidArgumentException('Invalid viewer');
    }

    if (($isShared || $collaborateAllowed) && !$canPublish) {
        throw new RuntimeException('main_publish_forbidden');
    }
    if (!empty($sharedWithUsernames) && !$canShare) {
        throw new RuntimeException('main_share_forbidden');
    }

    if ($role !== 'admin') {
        return createMainLab($db, $viewerId, $path, $name, $description, $isShared, $collaborateAllowed, $sharedWithUsernames);
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
        $sharedWithUsernames
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

function getMainLabDetailsForViewer(PDO $db, array $viewer, string $labId): array
{
    $entry = ensureMainEntryPermission($db, $viewer, 'lab', $labId);

    $stmt = $db->prepare(
        "SELECT id, name, description, is_shared, collaborate_allowed, updated_at,
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
    array $sharedWithUsernames = []
): array
{
    $entry = ensureMainEntryPermission($db, $viewer, 'lab', $labId);
    $canPublish = rbacUserHasPermission($db, $viewer, 'main.lab.publish');
    $canShare = rbacUserHasPermission($db, $viewer, 'main.lab.share');

    $name = normalizeExplorerName($name);
    $description = $description === null ? null : trim($description);
    if ($description === '') {
        $description = null;
    }

    if (!$canPublish || !$canShare) {
        $currentStmt = $db->prepare(
            "SELECT is_shared, collaborate_allowed
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

    $db->beginTransaction();
    try {
        $updateStmt = $db->prepare(
            "UPDATE labs
             SET name = :name,
                 description = :description,
                 is_shared = :is_shared,
                 collaborate_allowed = :collaborate_allowed,
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

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    if (!empty($removedSharedUserIds)) {
        deleteLocalCopiesForSourceLabUsers($db, (string) $entry['id'], $removedSharedUserIds);
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
                l.is_shared
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

function createSharedLabLocalCopyForViewer(PDO $db, array $viewer, string $sourceLabId, bool $forceReset = false): array
{
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $sourceLab = mainResolveSharedSourceLabForViewer($db, $viewer, $sourceLabId);
    $existing = getSharedLabLocalCopyForViewer($db, $viewer, $sourceLabId);
    if ($existing !== null && !$forceReset) {
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
        $existingStmt = $db->prepare(
            "SELECT id
             FROM labs
             WHERE author_user_id = :viewer_id
               AND source_lab_id = :source_lab_id"
        );
        $existingStmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
        $existingStmt->bindValue(':source_lab_id', $sourceLabId, PDO::PARAM_STR);
        $existingStmt->execute();
        $rows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            deleteMainEntryForViewer($db, $viewer, 'lab', $id);
        }
    }

    $newLabId = '';
    $newLabName = '';
    $nodeMap = [];
    $sourceOwnerId = (string) ($sourceLab['author_user_id'] ?? '');
    $db->beginTransaction();
    try {
        $baseName = trim((string) ($sourceLab['name'] ?? 'lab'));
        $copyName = mainUniqueLabName($db, $viewerId, $baseName, null);
        $insertLab = $db->prepare(
            "INSERT INTO labs (name, description, author_user_id, folder_id, is_shared, is_mirror, collaborate_allowed, source_lab_id)
             VALUES (:name, :description, :author_user_id, NULL, FALSE, TRUE, FALSE, :source_lab_id)
             RETURNING id, name"
        );
        $insertLab->bindValue(':name', $copyName, PDO::PARAM_STR);
        $insertLab->bindValue(':description', (string) ($sourceLab['description'] ?? ''), PDO::PARAM_STR);
        $insertLab->bindValue(':author_user_id', $viewerId, PDO::PARAM_STR);
        $insertLab->bindValue(':source_lab_id', $sourceLabId, PDO::PARAM_STR);
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
            "SELECT id, node_id, name, port_type, network_id
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
            "INSERT INTO lab_node_ports (node_id, name, port_type, network_id)
             VALUES (:node_id, :name, :port_type, :network_id)
             RETURNING id"
        );
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
            $insertPort->execute();
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
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    foreach ($nodeMap as $oldNodeId => $newNodeId) {
        $sourceNodeDir = mainRuntimeNodeSourceDir($sourceLabId, (string) $oldNodeId, $sourceOwnerId);
        if ($sourceNodeDir === '') {
            continue;
        }
        $targetNodeDir = v2RuntimeNodeDir($newLabId, (string) $newNodeId, $viewerId);
        try {
            mainCopyDirectoryRecursive($sourceNodeDir, $targetNodeDir);
            mainRuntimeClearVolatileFiles($targetNodeDir);
        } catch (Throwable $e) {
            // Best-effort runtime copy.
        }
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

function deleteLocalCopiesForSourceLabUsers(PDO $db, string $sourceLabId, array $userIds): void
{
    $localCopyIds = listLabIdsBySourceAndUsers($db, $sourceLabId, $userIds);
    if (empty($localCopyIds)) {
        return;
    }
    deleteLabsWithRuntimeCleanup($db, $localCopyIds);
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
