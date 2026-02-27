<?php

declare(strict_types=1);

function listCloudMappings(PDO $db): array
{
    $sql = "SELECT cu.id,
                   cu.cloud_id,
                   c.name AS cloud_name,
                   c.pnet,
                   cu.user_id,
                   u.username,
                   cu.created_at,
                   cu.updated_at
            FROM cloud_users cu
            INNER JOIN clouds c ON c.id = cu.cloud_id
            INNER JOIN users u ON u.id = cu.user_id
            ORDER BY c.name ASC, u.username ASC, cu.id ASC";
    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function getCloudMappingById(PDO $db, string $mappingId): ?array
{
    $sql = "SELECT cu.id,
                   cu.cloud_id,
                   c.name AS cloud_name,
                   c.pnet,
                   cu.user_id,
                   u.username,
                   cu.created_at,
                   cu.updated_at
            FROM cloud_users cu
            INNER JOIN clouds c ON c.id = cu.cloud_id
            INNER JOIN users u ON u.id = cu.user_id
            WHERE cu.id = :id
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', $mappingId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function resolveOrCreateCloud(PDO $db, string $cloudName, string $pnet): string
{
    $find = $db->prepare('SELECT id FROM clouds WHERE name = :name AND pnet = :pnet LIMIT 1');
    $find->bindValue(':name', $cloudName, PDO::PARAM_STR);
    $find->bindValue(':pnet', $pnet, PDO::PARAM_STR);
    $find->execute();
    $existingId = $find->fetchColumn();
    if ($existingId !== false) {
        return (string) $existingId;
    }

    $insert = $db->prepare('INSERT INTO clouds (name, pnet) VALUES (:name, :pnet) RETURNING id');
    $insert->bindValue(':name', $cloudName, PDO::PARAM_STR);
    $insert->bindValue(':pnet', $pnet, PDO::PARAM_STR);
    $insert->execute();

    return (string) $insert->fetchColumn();
}

function mappingExists(PDO $db, string $cloudId, string $userId, ?string $excludeMappingId = null): bool
{
    if ($excludeMappingId !== null) {
        $stmt = $db->prepare('SELECT 1 FROM cloud_users WHERE cloud_id = :cloud_id AND user_id = :user_id AND id <> :exclude_id LIMIT 1');
        $stmt->bindValue(':exclude_id', $excludeMappingId, PDO::PARAM_STR);
    } else {
        $stmt = $db->prepare('SELECT 1 FROM cloud_users WHERE cloud_id = :cloud_id AND user_id = :user_id LIMIT 1');
    }
    $stmt->bindValue(':cloud_id', $cloudId, PDO::PARAM_STR);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function createCloudMapping(PDO $db, string $cloudId, string $userId): string
{
    $stmt = $db->prepare('INSERT INTO cloud_users (cloud_id, user_id) VALUES (:cloud_id, :user_id) RETURNING id');
    $stmt->bindValue(':cloud_id', $cloudId, PDO::PARAM_STR);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    return (string) $stmt->fetchColumn();
}

function updateCloudMapping(PDO $db, string $mappingId, string $cloudId, string $userId): bool
{
    $stmt = $db->prepare('UPDATE cloud_users SET cloud_id = :cloud_id, user_id = :user_id, updated_at = NOW() WHERE id = :id');
    $stmt->bindValue(':id', $mappingId, PDO::PARAM_STR);
    $stmt->bindValue(':cloud_id', $cloudId, PDO::PARAM_STR);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    return true;
}

function deleteCloudMapping(PDO $db, string $mappingId): bool
{
    $stmt = $db->prepare('DELETE FROM cloud_users WHERE id = :id');
    $stmt->bindValue(':id', $mappingId, PDO::PARAM_STR);
    $stmt->execute();
    return true;
}

function cleanupOrphanClouds(PDO $db): void
{
    $db->exec('DELETE FROM clouds c WHERE NOT EXISTS (SELECT 1 FROM cloud_users cu WHERE cu.cloud_id = c.id)');
}

function listPnetsFromInterfaces(string $rootFile = '/etc/network/interfaces'): array
{
    $visited = [];
    $pnets = [];
    parseInterfacesFile($rootFile, $visited, $pnets);
    $pnets = array_values(array_filter(array_unique($pnets), static function ($name): bool {
        return $name !== 'pnet0';
    }));
    usort($pnets, 'strnatcasecmp');
    return $pnets;
}

function listMappedPnetsForUser(PDO $db, string $userId): array
{
    $userId = trim($userId);
    if ($userId === '') {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT DISTINCT c.pnet
         FROM cloud_users cu
         INNER JOIN clouds c ON c.id = cu.cloud_id
         WHERE cu.user_id = :user_id"
    );
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $pnets = [];
    foreach ($rows as $row) {
        $pnet = strtolower(trim((string) ($row['pnet'] ?? '')));
        if (preg_match('/^pnet[0-9]+$/', $pnet) !== 1 || $pnet === 'pnet0') {
            continue;
        }
        $pnets[$pnet] = true;
    }
    $result = array_keys($pnets);
    usort($result, 'strnatcasecmp');
    return $result;
}

function parseInterfacesFile(string $filePath, array &$visited, array &$pnets): void
{
    $realPath = realpath($filePath) ?: $filePath;
    if (isset($visited[$realPath])) {
        return;
    }
    $visited[$realPath] = true;

    if (!is_readable($filePath)) {
        return;
    }

    $lines = @file($filePath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return;
    }

    $baseDir = dirname($filePath);

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (preg_match('/^(?:auto|allow-hotplug|iface)\s+([a-zA-Z0-9_.:-]+)/', $trimmed, $m)) {
            if (preg_match('/^pnet[0-9]+$/', $m[1])) {
                $pnets[] = $m[1];
            }
        }

        if (preg_match('/^(?:source|source-directory)\s+(.+)$/', $trimmed, $m)) {
            $pattern = trim($m[1]);
            if ($pattern === '') {
                continue;
            }
            if ($pattern[0] !== '/') {
                $pattern = $baseDir . '/' . $pattern;
            }
            $matches = glob($pattern, GLOB_NOSORT) ?: [];
            foreach ($matches as $matchFile) {
                if (is_dir($matchFile)) {
                    $children = glob(rtrim($matchFile, '/') . '/*', GLOB_NOSORT) ?: [];
                    foreach ($children as $child) {
                        parseInterfacesFile($child, $visited, $pnets);
                    }
                    continue;
                }
                parseInterfacesFile($matchFile, $visited, $pnets);
            }
        }
    }
}
