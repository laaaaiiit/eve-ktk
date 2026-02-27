<?php

declare(strict_types=1);

function listLabManagementOverview(PDO $db): array
{
    $usersStmt = $db->query(
        "SELECT
            u.id::text AS id,
            u.username,
            r.name AS role_name,
            u.is_blocked,
            u.updated_at,
            COUNT(DISTINCT l.id) AS labs_count,
            COUNT(n.id) AS nodes_total,
            COUNT(n.id) FILTER (WHERE n.power_state = 'running') AS nodes_running,
            COUNT(n.id) FILTER (WHERE n.power_state = 'starting') AS nodes_starting,
            COUNT(n.id) FILTER (WHERE n.power_state = 'stopping') AS nodes_stopping,
            COUNT(n.id) FILTER (WHERE n.power_state = 'error') AS nodes_error
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         LEFT JOIN labs l ON l.author_user_id = u.id
         LEFT JOIN lab_nodes n ON n.lab_id = l.id
         GROUP BY u.id, u.username, r.name, u.is_blocked, u.updated_at
         ORDER BY LOWER(u.username) ASC"
    );
    $userRows = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($userRows)) {
        $userRows = [];
    }

    $labsStmt = $db->query(
        "SELECT
            l.id::text AS id,
            l.name,
            l.author_user_id::text AS owner_user_id,
            u.username AS owner_username,
            l.is_shared,
            l.collaborate_allowed,
            l.updated_at,
            COUNT(n.id) AS nodes_total,
            COUNT(n.id) FILTER (WHERE n.power_state = 'running') AS nodes_running,
            COUNT(n.id) FILTER (WHERE n.power_state = 'starting') AS nodes_starting,
            COUNT(n.id) FILTER (WHERE n.power_state = 'stopping') AS nodes_stopping,
            COUNT(n.id) FILTER (WHERE n.power_state = 'error') AS nodes_error,
            COALESCE(tasks.pending_count, 0) AS tasks_pending,
            COALESCE(tasks.running_count, 0) AS tasks_running
         FROM labs l
         INNER JOIN users u ON u.id = l.author_user_id
         LEFT JOIN lab_nodes n ON n.lab_id = l.id
         LEFT JOIN (
           SELECT
             t.lab_id,
             COUNT(*) FILTER (WHERE t.status = 'pending') AS pending_count,
             COUNT(*) FILTER (WHERE t.status = 'running') AS running_count
           FROM lab_tasks t
           GROUP BY t.lab_id
         ) tasks ON tasks.lab_id = l.id
         GROUP BY
           l.id, l.name, l.author_user_id, u.username,
           l.is_shared, l.collaborate_allowed, l.updated_at,
           tasks.pending_count, tasks.running_count
         ORDER BY LOWER(u.username) ASC, LOWER(l.name) ASC"
    );
    $labRows = $labsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($labRows)) {
        $labRows = [];
    }

    $users = array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'role' => (string) ($row['role_name'] ?? ''),
            'is_blocked' => (bool) ($row['is_blocked'] ?? false),
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            'labs_count' => (int) ($row['labs_count'] ?? 0),
            'nodes_total' => (int) ($row['nodes_total'] ?? 0),
            'nodes_running' => (int) ($row['nodes_running'] ?? 0),
            'nodes_starting' => (int) ($row['nodes_starting'] ?? 0),
            'nodes_stopping' => (int) ($row['nodes_stopping'] ?? 0),
            'nodes_error' => (int) ($row['nodes_error'] ?? 0),
        ];
    }, $userRows);

    $labs = array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'owner_user_id' => (string) ($row['owner_user_id'] ?? ''),
            'owner_username' => (string) ($row['owner_username'] ?? ''),
            'is_shared' => (bool) ($row['is_shared'] ?? false),
            'collaborate_allowed' => (bool) ($row['collaborate_allowed'] ?? false),
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            'nodes_total' => (int) ($row['nodes_total'] ?? 0),
            'nodes_running' => (int) ($row['nodes_running'] ?? 0),
            'nodes_starting' => (int) ($row['nodes_starting'] ?? 0),
            'nodes_stopping' => (int) ($row['nodes_stopping'] ?? 0),
            'nodes_error' => (int) ($row['nodes_error'] ?? 0),
            'tasks_pending' => (int) ($row['tasks_pending'] ?? 0),
            'tasks_running' => (int) ($row['tasks_running'] ?? 0),
        ];
    }, $labRows);

    return [
        'users' => $users,
        'labs' => $labs,
    ];
}

function listLabManagementNodesForLab(PDO $db, string $labId): array
{
    $stmt = $db->prepare(
        "SELECT
            n.id::text AS id,
            n.lab_id::text AS lab_id,
            n.name,
            n.node_type,
            n.template,
            n.image,
            n.power_state,
            n.is_running,
            n.last_error,
            n.power_updated_at,
            n.updated_at
         FROM lab_nodes n
         WHERE n.lab_id = :lab_id
         ORDER BY LOWER(n.name) ASC"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }

    return array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'lab_id' => (string) ($row['lab_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'node_type' => strtolower((string) ($row['node_type'] ?? '')),
            'template' => isset($row['template']) ? (string) $row['template'] : null,
            'image' => isset($row['image']) ? (string) $row['image'] : null,
            'power_state' => strtolower((string) ($row['power_state'] ?? 'stopped')),
            'is_running' => (bool) ($row['is_running'] ?? false),
            'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : null,
            'power_updated_at' => isset($row['power_updated_at']) ? (string) $row['power_updated_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }, $rows);
}

function listLabManagementTargetNodes(PDO $db, string $scopeType, ?string $scopeId): array
{
    $scope = strtolower(trim($scopeType));
    $id = trim((string) ($scopeId ?? ''));

    if ($scope === 'all') {
        $stmt = $db->query("SELECT n.lab_id::text AS lab_id, n.id::text AS node_id FROM lab_nodes n ORDER BY n.lab_id, n.id");
    } elseif ($scope === 'user') {
        if ($id === '') {
            throw new InvalidArgumentException('scope_id_required');
        }
        $stmt = $db->prepare(
            "SELECT n.lab_id::text AS lab_id, n.id::text AS node_id
             FROM lab_nodes n
             INNER JOIN labs l ON l.id = n.lab_id
             WHERE l.author_user_id = :scope_id
             ORDER BY n.lab_id, n.id"
        );
        $stmt->bindValue(':scope_id', $id, PDO::PARAM_STR);
        $stmt->execute();
    } elseif ($scope === 'lab') {
        if ($id === '') {
            throw new InvalidArgumentException('scope_id_required');
        }
        $stmt = $db->prepare(
            "SELECT n.lab_id::text AS lab_id, n.id::text AS node_id
             FROM lab_nodes n
             WHERE n.lab_id = :scope_id
             ORDER BY n.id"
        );
        $stmt->bindValue(':scope_id', $id, PDO::PARAM_STR);
        $stmt->execute();
    } elseif ($scope === 'node') {
        if ($id === '') {
            throw new InvalidArgumentException('scope_id_required');
        }
        $stmt = $db->prepare(
            "SELECT n.lab_id::text AS lab_id, n.id::text AS node_id
             FROM lab_nodes n
             WHERE n.id = :scope_id
             LIMIT 1"
        );
        $stmt->bindValue(':scope_id', $id, PDO::PARAM_STR);
        $stmt->execute();
    } else {
        throw new InvalidArgumentException('scope_type_invalid');
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_filter(array_map(static function (array $row): ?array {
        $labId = (string) ($row['lab_id'] ?? '');
        $nodeId = (string) ($row['node_id'] ?? '');
        if ($labId === '' || $nodeId === '') {
            return null;
        }
        return ['lab_id' => $labId, 'node_id' => $nodeId];
    }, $rows)));
}

function runLabManagementAction(PDO $db, array $viewer, string $scopeType, ?string $scopeId, string $action): array
{
    $normalizedAction = strtolower(trim($action));
    if (!in_array($normalizedAction, ['start', 'stop', 'wipe'], true)) {
        throw new InvalidArgumentException('action_invalid');
    }

    $targets = listLabManagementTargetNodes($db, $scopeType, $scopeId);
    if (count($targets) === 0) {
        return [
            'scope_type' => strtolower(trim($scopeType)),
            'scope_id' => $scopeId,
            'action' => $normalizedAction,
            'total' => 0,
            'done' => 0,
            'queued' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];
    }

    $done = 0;
    $queued = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];

    foreach ($targets as $target) {
        $labId = (string) $target['lab_id'];
        $nodeId = (string) $target['node_id'];
        try {
            if ($normalizedAction === 'wipe') {
                wipeLabNode($db, $viewer, $labId, $nodeId);
                $done += 1;
                continue;
            }

            $result = enqueueLabNodePowerTask($db, $viewer, $labId, $nodeId, $normalizedAction);
            if (!empty($result['queued'])) {
                $queued += 1;
                $done += 1;
            } elseif (($result['reason'] ?? '') === 'already_in_target_state') {
                $skipped += 1;
            } elseif (($result['reason'] ?? '') === 'task_in_progress') {
                $skipped += 1;
            } else {
                $done += 1;
            }
        } catch (Throwable $e) {
            $failed += 1;
            if (count($errors) < 10) {
                $errors[] = [
                    'lab_id' => $labId,
                    'node_id' => $nodeId,
                    'message' => (string) $e->getMessage(),
                ];
            }
        }
    }

    return [
        'scope_type' => strtolower(trim($scopeType)),
        'scope_id' => $scopeId,
        'action' => $normalizedAction,
        'total' => count($targets),
        'done' => $done,
        'queued' => $queued,
        'skipped' => $skipped,
        'failed' => $failed,
        'errors' => $errors,
    ];
}
