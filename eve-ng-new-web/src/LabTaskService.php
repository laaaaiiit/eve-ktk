<?php

declare(strict_types=1);

require_once __DIR__ . '/LabRuntimeService.php';
require_once __DIR__ . '/AppLogService.php';

function normalizeLabTaskAction(string $action): string
{
    $action = strtolower(trim($action));
    if (!in_array($action, ['start', 'stop'], true)) {
        throw new InvalidArgumentException('action_invalid');
    }
    return $action;
}

function decodeJsonOrNull($value): ?array
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : null;
}

function mapLabTaskRow(array $row): array
{
    $payload = decodeJsonOrNull($row['payload'] ?? null);
    $resultData = decodeJsonOrNull($row['result_data'] ?? null);
    $requestedFromIp = null;
    if (is_array($payload) && isset($payload['requested_from_ip'])) {
        $requestedFromIp = (string) $payload['requested_from_ip'];
    }
    $workerMeta = null;
    if (is_array($payload) && isset($payload['worker']) && is_array($payload['worker'])) {
        $workerMeta = (array) $payload['worker'];
    } elseif (is_array($resultData) && isset($resultData['worker']) && is_array($resultData['worker'])) {
        $workerMeta = (array) $resultData['worker'];
    }
    $workerId = null;
    $workerName = null;
    $workerSlot = null;
    $workerPid = null;
    $workerHost = null;
    if (is_array($workerMeta)) {
        $workerIdRaw = trim((string) ($workerMeta['id'] ?? ''));
        $workerNameRaw = trim((string) ($workerMeta['name'] ?? ''));
        $workerHostRaw = trim((string) ($workerMeta['host'] ?? ''));
        $workerSlotRaw = isset($workerMeta['slot']) ? (int) $workerMeta['slot'] : 0;
        $workerPidRaw = isset($workerMeta['pid']) ? (int) $workerMeta['pid'] : 0;
        $workerId = $workerIdRaw !== '' ? $workerIdRaw : null;
        $workerHost = $workerHostRaw !== '' ? $workerHostRaw : null;
        $workerSlot = $workerSlotRaw > 0 ? $workerSlotRaw : null;
        $workerPid = $workerPidRaw > 0 ? $workerPidRaw : null;
        if ($workerNameRaw !== '') {
            $workerName = $workerNameRaw;
        } elseif ($workerId !== null || $workerSlot !== null || $workerHost !== null || $workerPid !== null) {
            $base = $workerId !== null ? $workerId : ($workerSlot !== null ? ('worker-' . $workerSlot) : 'worker');
            $parts = [$base];
            if ($workerHost !== null) {
                $parts[] = '@' . $workerHost;
            }
            if ($workerPid !== null) {
                $parts[] = '#' . $workerPid;
            }
            $workerName = implode('', $parts);
        }
    }
    return [
        'id' => (string) ($row['id'] ?? ''),
        'lab_id' => (string) ($row['lab_id'] ?? ''),
        'lab_name' => isset($row['lab_name']) ? (string) $row['lab_name'] : null,
        'lab_author_username' => isset($row['lab_author_username']) ? (string) $row['lab_author_username'] : null,
        'node_id' => (string) ($row['node_id'] ?? ''),
        'node_name' => isset($row['node_name']) ? (string) $row['node_name'] : null,
        'action' => (string) ($row['action'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'payload' => $payload,
        'result_data' => $resultData,
        'error_text' => isset($row['error_text']) ? (string) $row['error_text'] : null,
        'attempts' => isset($row['attempts']) ? (int) $row['attempts'] : 0,
        'requested_by_user_id' => isset($row['requested_by_user_id']) ? (string) $row['requested_by_user_id'] : null,
        'requested_by' => isset($row['requested_by']) ? (string) $row['requested_by'] : null,
        'requested_by_username' => isset($row['requested_by']) ? (string) $row['requested_by'] : null,
        'requested_from_ip' => $requestedFromIp,
        'worker_id' => $workerId,
        'worker_name' => $workerName,
        'worker_slot' => $workerSlot,
        'worker_pid' => $workerPid,
        'worker_host' => $workerHost,
        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        'started_at' => isset($row['started_at']) ? (string) $row['started_at'] : null,
        'finished_at' => isset($row['finished_at']) ? (string) $row['finished_at'] : null,
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
    ];
}

function normalizeLabTaskStatusFilter(string $status): string
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['pending', 'running', 'done', 'failed'], true)) {
        return '';
    }
    return $status;
}

function getLabNodePowerSnapshot(PDO $db, string $labId, string $nodeId): ?array
{
    refreshLabNodeRuntimeState($db, $labId, $nodeId);

    $stmt = $db->prepare(
        "SELECT id, lab_id, name, is_running, power_state, last_error, power_updated_at, updated_at,
                runtime_pid, runtime_console_port
         FROM lab_nodes
         WHERE id = :node_id
           AND lab_id = :lab_id
         LIMIT 1"
    );
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    return [
        'id' => (string) $row['id'],
        'lab_id' => (string) $row['lab_id'],
        'name' => (string) $row['name'],
        'is_running' => !empty($row['is_running']),
        'power_state' => (string) ($row['power_state'] ?? (!empty($row['is_running']) ? 'running' : 'stopped')),
        'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : null,
        'power_updated_at' => isset($row['power_updated_at']) ? (string) $row['power_updated_at'] : null,
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        'runtime_pid' => isset($row['runtime_pid']) ? ((int) $row['runtime_pid'] ?: null) : null,
        'runtime_console_port' => isset($row['runtime_console_port']) ? ((int) $row['runtime_console_port'] ?: null) : null,
    ];
}

function findActiveLabTaskForNode(PDO $db, string $nodeId): ?array
{
    $stmt = $db->prepare(
        "SELECT id, lab_id, node_id, action, status, payload, result_data, error_text, attempts,
                requested_by_user_id, requested_by,
                created_at, started_at, finished_at, updated_at
         FROM lab_tasks
         WHERE node_id = :node_id
           AND status IN ('pending', 'running')
         ORDER BY created_at ASC
         LIMIT 1"
    );
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : mapLabTaskRow($row);
}

function getLabTaskById(PDO $db, string $taskId): ?array
{
    $stmt = $db->prepare(
        "SELECT id, lab_id, node_id, action, status, payload, result_data, error_text, attempts,
                requested_by_user_id, requested_by,
                created_at, started_at, finished_at, updated_at
         FROM lab_tasks
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->bindValue(':id', $taskId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : mapLabTaskRow($row);
}

function enqueueLabNodePowerTask(PDO $db, array $viewer, string $labId, string $nodeId, string $action): array
{
    $action = normalizeLabTaskAction($action);
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }
    if (function_exists('isLabDeletionGuardActive') && isLabDeletionGuardActive($db, $labId)) {
        $result = [
            'queued' => false,
            'reason' => 'lab_delete_in_progress',
            'task' => null,
            'node' => getLabNodePowerSnapshot($db, $labId, $nodeId),
        ];
        $context = v2AppLogAttachUser([
            'event' => 'task_enqueue',
            'lab_id' => $labId,
            'node_id' => $nodeId,
            'action' => $action,
            'queued' => false,
            'reason' => 'lab_delete_in_progress',
        ], $viewer);
        v2AppLogWrite('task_worker', 'ERROR', $context);
        return $result;
    }

    $node = getLabNodePowerSnapshot($db, $labId, $nodeId);
    if ($node === null) {
        throw new RuntimeException('Node not found');
    }

    $nodePowerState = strtolower((string) ($node['power_state'] ?? ''));
    $nodeRunning = !empty($node['is_running']);
    if (($action === 'start' && $nodeRunning && $nodePowerState === 'running')
        || ($action === 'stop' && !$nodeRunning && $nodePowerState === 'stopped')) {
        $result = [
            'queued' => false,
            'reason' => 'already_in_target_state',
            'task' => null,
            'node' => $node,
        ];
        $context = v2AppLogAttachUser([
            'event' => 'task_enqueue',
            'lab_id' => $labId,
            'node_id' => $nodeId,
            'action' => $action,
            'queued' => false,
            'reason' => 'already_in_target_state',
        ], $viewer);
        v2AppLogWrite('task_worker', 'OK', $context);
        return $result;
    }

    $activeTask = findActiveLabTaskForNode($db, $nodeId);
    if ($activeTask !== null) {
        $result = [
            'queued' => false,
            'reason' => 'task_in_progress',
            'task' => $activeTask,
            'node' => $node,
        ];
        $context = v2AppLogAttachUser([
            'event' => 'task_enqueue',
            'lab_id' => $labId,
            'node_id' => $nodeId,
            'action' => $action,
            'queued' => false,
            'reason' => 'task_in_progress',
            'active_task_id' => (string) ($activeTask['id'] ?? ''),
        ], $viewer);
        v2AppLogWrite('task_worker', 'OK', $context);
        return $result;
    }

    $viewerId = (string) ($viewer['id'] ?? '');
    $viewerUsername = (string) ($viewer['username'] ?? '');
    $viewerIp = clientIp();
    $payloadJson = json_encode([
        'requested_at' => gmdate('c'),
        'requested_from_ip' => $viewerIp,
    ], JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadJson)) {
        $payloadJson = '{}';
    }

    $db->beginTransaction();
    try {
        $insertStmt = $db->prepare(
            "INSERT INTO lab_tasks (
                lab_id,
                node_id,
                action,
                status,
                payload,
                requested_by_user_id,
                requested_by
            ) VALUES (
                :lab_id,
                :node_id,
                :action,
                'pending',
                CAST(:payload AS jsonb),
                :requested_by_user_id,
                :requested_by
            )
            RETURNING id, lab_id, node_id, action, status, payload, result_data, error_text, attempts,
                      requested_by_user_id, requested_by,
                      created_at, started_at, finished_at, updated_at"
        );
        $insertStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $insertStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
        $insertStmt->bindValue(':action', $action, PDO::PARAM_STR);
        $insertStmt->bindValue(':payload', $payloadJson, PDO::PARAM_STR);
        $insertStmt->bindValue(':requested_by_user_id', $viewerId !== '' ? $viewerId : null, $viewerId !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insertStmt->bindValue(':requested_by', $viewerUsername !== '' ? $viewerUsername : null, $viewerUsername !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insertStmt->execute();
        $inserted = $insertStmt->fetch(PDO::FETCH_ASSOC);
        if ($inserted === false) {
            throw new RuntimeException('Task insert failed');
        }

        $nextPowerState = $action === 'start' ? 'starting' : 'stopping';
        $nodeStateStmt = $db->prepare(
            "UPDATE lab_nodes
             SET power_state = :power_state,
                 last_error = NULL,
                 power_updated_at = NOW(),
                 updated_at = NOW()
             WHERE id = :node_id
               AND lab_id = :lab_id"
        );
        $nodeStateStmt->bindValue(':power_state', $nextPowerState, PDO::PARAM_STR);
        $nodeStateStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
        $nodeStateStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $nodeStateStmt->execute();

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    kickLabTaskWorkerAsync();

    $mappedTask = mapLabTaskRow($inserted);
    $context = v2AppLogAttachUser([
        'event' => 'task_enqueue',
        'lab_id' => $labId,
        'node_id' => $nodeId,
        'action' => $action,
        'queued' => true,
        'task_id' => (string) ($mappedTask['id'] ?? ''),
    ], $viewer);
    v2AppLogWrite('task_worker', 'OK', $context);

    return [
        'queued' => true,
        'task' => $mappedTask,
        'node' => getLabNodePowerSnapshot($db, $labId, $nodeId),
    ];
}

function listLabTasksForViewer(PDO $db, array $viewer, string $labId, int $limit = 100): array
{
    if (!viewerCanViewLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $limit = max(1, min(500, $limit));
    $stmt = $db->prepare(
        "SELECT t.id, t.lab_id, l.name AS lab_name, owner.username AS lab_author_username,
                t.node_id, n.name AS node_name,
                t.action, t.status, t.payload, t.result_data, t.error_text, t.attempts,
                t.requested_by_user_id, t.requested_by,
                t.created_at, t.started_at, t.finished_at, t.updated_at
         FROM lab_tasks t
         JOIN labs l ON l.id = t.lab_id
         LEFT JOIN users owner ON owner.id = l.author_user_id
         LEFT JOIN lab_nodes n ON n.id = t.node_id
         WHERE t.lab_id = :lab_id
         ORDER BY t.created_at DESC
         LIMIT :limit_rows"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    return array_map(static function (array $row): array {
        $mapped = mapLabTaskRow($row);
        v2AppLogWrite('task_worker', 'OK', [
            'event' => 'task_claim',
            'task_id' => (string) ($mapped['id'] ?? ''),
            'lab_id' => (string) ($mapped['lab_id'] ?? ''),
            'node_id' => (string) ($mapped['node_id'] ?? ''),
            'action' => (string) ($mapped['action'] ?? ''),
            'attempts' => (int) ($mapped['attempts'] ?? 0),
        ]);
        return $mapped;
    }, $rows);
}

function listRecentLabTasksForViewer(PDO $db, array $viewer, int $limit = 150, string $statusFilter = ''): array
{
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $isAdmin = (($viewer['role_name'] ?? '') === 'admin');
    $limit = max(1, min(500, $limit));
    $status = normalizeLabTaskStatusFilter($statusFilter);

    $where = [];
    if ($status !== '') {
        $where[] = 't.status = :status';
    }
    if (!$isAdmin) {
        $where[] = "(l.author_user_id = :viewer_id
            OR EXISTS (
                SELECT 1
                FROM lab_shared_users su
                WHERE su.lab_id = l.id
                  AND su.user_id = :viewer_id
            ))";
    }

    $sql = "SELECT t.id, t.lab_id, l.name AS lab_name, owner.username AS lab_author_username,
                   t.node_id, n.name AS node_name,
                   t.action, t.status, t.payload, t.result_data, t.error_text, t.attempts,
                   t.requested_by_user_id, t.requested_by,
                   t.created_at, t.started_at, t.finished_at, t.updated_at
            FROM lab_tasks t
            JOIN labs l ON l.id = t.lab_id
            LEFT JOIN users owner ON owner.id = l.author_user_id
            LEFT JOIN lab_nodes n ON n.id = t.node_id";
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY t.created_at DESC LIMIT :limit_rows';

    $stmt = $db->prepare($sql);
    if ($status !== '') {
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    }
    if (!$isAdmin) {
        $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    return array_map(static function (array $row): array {
        return mapLabTaskRow($row);
    }, $rows);
}

function terminateLabTaskWorkerIfPossible(array $task): array
{
    $pid = isset($task['worker_pid']) ? (int) $task['worker_pid'] : 0;
    $result = [
        'attempted' => false,
        'killed' => false,
        'pid' => $pid > 1 ? $pid : null,
    ];
    if ($pid <= 1 || !function_exists('runtimePidAlive') || !runtimePidAlive($pid)) {
        return $result;
    }
    if (!function_exists('runtimeReadProcCmdline')) {
        return $result;
    }
    $cmdline = strtolower(trim((string) runtimeReadProcCmdline($pid)));
    if ($cmdline === '' || strpos($cmdline, 'run_lab_tasks_once.php') === false) {
        return $result;
    }

    $result['attempted'] = true;
    if (function_exists('posix_kill')) {
        $sigTerm = defined('SIGTERM') ? (int) SIGTERM : 15;
        $sigKill = defined('SIGKILL') ? (int) SIGKILL : 9;
        @posix_kill($pid, $sigTerm);
        usleep(300000);
        if (runtimePidAlive($pid)) {
            @posix_kill($pid, $sigKill);
            usleep(250000);
        }
    } else {
        @exec('kill -TERM ' . (int) $pid . ' >/dev/null 2>&1');
        usleep(300000);
        if (runtimePidAlive($pid)) {
            @exec('kill -KILL ' . (int) $pid . ' >/dev/null 2>&1');
            usleep(250000);
        }
    }
    $result['killed'] = function_exists('runtimePidAlive') ? !runtimePidAlive($pid) : false;
    return $result;
}

function stopLabTask(PDO $db, array $viewer, string $taskId): array
{
    $taskId = strtolower(trim($taskId));
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $taskId)) {
        throw new InvalidArgumentException('task_id_invalid');
    }

    $task = getLabTaskById($db, $taskId);
    if ($task === null) {
        throw new RuntimeException('Task not found');
    }

    $labId = (string) ($task['lab_id'] ?? '');
    $nodeId = (string) ($task['node_id'] ?? '');
    $status = strtolower(trim((string) ($task['status'] ?? '')));
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }
    if (!in_array($status, ['pending', 'running'], true)) {
        throw new RuntimeException('Task already finished');
    }

    $workerStop = ['attempted' => false, 'killed' => false, 'pid' => null];
    if ($status === 'running') {
        $workerStop = terminateLabTaskWorkerIfPossible($task);
    }

    $requestedBy = trim((string) ($viewer['username'] ?? ''));
    $errorText = $requestedBy !== '' ? ('Stopped by ' . $requestedBy) : 'Stopped manually';

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "UPDATE lab_tasks
             SET status = 'failed',
                 error_text = :error_text,
                 finished_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id
               AND status IN ('pending', 'running')
             RETURNING id, lab_id, node_id, action, status, payload, result_data, error_text, attempts,
                       requested_by_user_id, requested_by,
                       created_at, started_at, finished_at, updated_at"
        );
        $stmt->bindValue(':error_text', $errorText, PDO::PARAM_STR);
        $stmt->bindValue(':id', $taskId, PDO::PARAM_STR);
        $stmt->execute();
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($updated === false) {
            throw new RuntimeException('Task already finished');
        }

        if ($labId !== '' && $nodeId !== '') {
            refreshLabNodeRuntimeState($db, $labId, $nodeId);
            $nodeStmt = $db->prepare(
                "UPDATE lab_nodes
                 SET power_state = CASE WHEN is_running THEN 'running' ELSE 'stopped' END,
                     power_updated_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :node_id
                   AND lab_id = :lab_id
                   AND power_state IN ('starting', 'stopping')"
            );
            $nodeStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
            $nodeStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
            $nodeStmt->execute();
        }

        $db->commit();
        $mapped = mapLabTaskRow($updated);
        $mapped['worker_stop_attempted'] = !empty($workerStop['attempted']);
        $mapped['worker_stopped'] = !empty($workerStop['killed']);
        $mapped['worker_stop_pid'] = isset($workerStop['pid']) ? $workerStop['pid'] : null;
        $context = v2AppLogAttachUser([
            'event' => 'task_stop',
            'task_id' => (string) ($mapped['id'] ?? ''),
            'lab_id' => (string) ($mapped['lab_id'] ?? ''),
            'node_id' => (string) ($mapped['node_id'] ?? ''),
            'status_before' => $status,
            'worker_stop_attempted' => !empty($workerStop['attempted']),
            'worker_stopped' => !empty($workerStop['killed']),
            'worker_pid' => isset($workerStop['pid']) ? (int) $workerStop['pid'] : null,
        ], $viewer);
        v2AppLogWrite('task_worker', 'OK', $context);
        return $mapped;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function cancelPendingLabTask(PDO $db, array $viewer, string $taskId): array
{
    return stopLabTask($db, $viewer, $taskId);
}

function retryLabTask(PDO $db, array $viewer, string $taskId): array
{
	$taskId = strtolower(trim($taskId));
	if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $taskId)) {
		throw new InvalidArgumentException('task_id_invalid');
	}

	$task = getLabTaskById($db, $taskId);
	if ($task === null) {
		throw new RuntimeException('Task not found');
	}

	$status = (string) ($task['status'] ?? '');
	$labId = (string) ($task['lab_id'] ?? '');
	$nodeId = (string) ($task['node_id'] ?? '');
	$action = (string) ($task['action'] ?? '');
	if (!viewerCanEditLab($db, $viewer, $labId)) {
		throw new RuntimeException('Forbidden');
	}
	if (in_array($status, ['pending', 'running'], true)) {
		throw new RuntimeException('Task already in progress');
	}
	if ($labId === '' || $nodeId === '') {
		throw new RuntimeException('Task payload is invalid');
	}

	$enqueue = enqueueLabNodePowerTask($db, $viewer, $labId, $nodeId, $action);
	return [
		'original_task' => $task,
		'enqueue' => $enqueue,
	];
}

function forceFailActiveLabTasksScope(PDO $db, string $labId, string $errorText, ?string $nodeId = null): int
{
    $errorText = trim($errorText);
    if ($errorText === '') {
        $errorText = 'Force stopped manually';
    }
    if (strlen($errorText) > 4000) {
        $errorText = substr($errorText, 0, 4000);
    }

    $sql = "UPDATE lab_tasks
            SET status = 'failed',
                error_text = :error_text,
                finished_at = NOW(),
                updated_at = NOW()
            WHERE lab_id = :lab_id
              AND status IN ('pending', 'running')";
    if ($nodeId !== null && $nodeId !== '') {
        $sql .= " AND node_id = :node_id";
    }

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':error_text', $errorText, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    if ($nodeId !== null && $nodeId !== '') {
        $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    }
    $stmt->execute();
    return (int) $stmt->rowCount();
}

function forceStopNodeByTask(PDO $db, array $viewer, string $taskId): array
{
    $taskId = strtolower(trim($taskId));
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $taskId)) {
        throw new InvalidArgumentException('task_id_invalid');
    }

    $task = getLabTaskById($db, $taskId);
    if ($task === null) {
        throw new RuntimeException('Task not found');
    }

    $labId = (string) ($task['lab_id'] ?? '');
    $nodeId = (string) ($task['node_id'] ?? '');
    if ($labId === '' || $nodeId === '') {
        throw new RuntimeException('Task payload is invalid');
    }
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $requestedBy = trim((string) ($viewer['username'] ?? ''));
    $failReason = $requestedBy !== ''
        ? ('Force stopped by ' . $requestedBy)
        : 'Force stopped manually';
    $failedTasks = forceFailActiveLabTasksScope($db, $labId, $failReason, $nodeId);

    $stopResult = stopLabNodeRuntime($db, $labId, $nodeId);
    $nodeSnapshot = getLabNodePowerSnapshot($db, $labId, $nodeId);

    $context = v2AppLogAttachUser([
        'event' => 'task_force_stop_node',
        'task_id' => $taskId,
        'lab_id' => $labId,
        'node_id' => $nodeId,
        'failed_tasks' => $failedTasks,
        'already_stopped' => !empty($stopResult['already_stopped']),
        'forced' => !empty($stopResult['forced']),
    ], $viewer);
    v2AppLogWrite('task_worker', 'OK', $context);

    return [
        'task_id' => $taskId,
        'lab_id' => $labId,
        'node_id' => $nodeId,
        'failed_tasks' => $failedTasks,
        'stop' => $stopResult,
        'node' => $nodeSnapshot,
    ];
}

function forceStopLabByTask(PDO $db, array $viewer, string $taskId): array
{
    $taskId = strtolower(trim($taskId));
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $taskId)) {
        throw new InvalidArgumentException('task_id_invalid');
    }

    $task = getLabTaskById($db, $taskId);
    if ($task === null) {
        throw new RuntimeException('Task not found');
    }

    $labId = (string) ($task['lab_id'] ?? '');
    if ($labId === '') {
        throw new RuntimeException('Task payload is invalid');
    }
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $labStmt = $db->prepare("SELECT id, name FROM labs WHERE id = :lab_id LIMIT 1");
    $labStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $labStmt->execute();
    $labRow = $labStmt->fetch(PDO::FETCH_ASSOC);
    if ($labRow === false) {
        throw new RuntimeException('Lab not found');
    }

    $requestedBy = trim((string) ($viewer['username'] ?? ''));
    $failReason = $requestedBy !== ''
        ? ('Force stopped by ' . $requestedBy)
        : 'Force stopped manually';
    $failedTasks = forceFailActiveLabTasksScope($db, $labId, $failReason, null);

    $nodeStmt = $db->prepare(
        "SELECT id::text AS id
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

    $totalNodes = 0;
    $stoppedNodes = 0;
    $failedNodes = 0;
    $errors = [];
    foreach ($nodeRows as $row) {
        $nodeId = trim((string) ($row['id'] ?? ''));
        if ($nodeId === '') {
            continue;
        }
        $totalNodes += 1;
        try {
            stopLabNodeRuntime($db, $labId, $nodeId);
            $stoppedNodes += 1;
        } catch (Throwable $e) {
            $failedNodes += 1;
            if (count($errors) < 20) {
                $errors[] = [
                    'node_id' => $nodeId,
                    'error' => trim((string) $e->getMessage()),
                ];
            }
        }
    }

    $context = v2AppLogAttachUser([
        'event' => 'task_force_stop_lab',
        'task_id' => $taskId,
        'lab_id' => $labId,
        'failed_tasks' => $failedTasks,
        'total_nodes' => $totalNodes,
        'stopped_nodes' => $stoppedNodes,
        'failed_nodes' => $failedNodes,
    ], $viewer);
    v2AppLogWrite('task_worker', $failedNodes > 0 ? 'ERROR' : 'OK', $context);

    return [
        'task_id' => $taskId,
        'lab_id' => $labId,
        'lab_name' => (string) ($labRow['name'] ?? ''),
        'failed_tasks' => $failedTasks,
        'total_nodes' => $totalNodes,
        'stopped_nodes' => $stoppedNodes,
        'failed_nodes' => $failedNodes,
        'errors' => $errors,
    ];
}

function v2LabTaskWorkerPhpBinary(): string
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

function kickLabTaskWorkerAsync(): void
{
    $script = dirname(__DIR__) . '/bin/run_lab_tasks_once.php';
    if (!is_file($script)) {
        return;
    }
    $php = v2LabTaskWorkerPhpBinary();
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

function labTaskSemaphoreKeyClass(): int
{
    return 240214;
}

function tryAcquireLabTaskSlot(PDO $db, int $maxParallel): int
{
    $maxParallel = max(1, $maxParallel);
    $classKey = labTaskSemaphoreKeyClass();
    $stmt = $db->prepare("SELECT pg_try_advisory_lock(:class_key, :slot_key)");
    for ($slot = 1; $slot <= $maxParallel; $slot++) {
        $stmt->bindValue(':class_key', $classKey, PDO::PARAM_INT);
        $stmt->bindValue(':slot_key', $slot, PDO::PARAM_INT);
        $stmt->execute();
        if (!empty($stmt->fetchColumn())) {
            return $slot;
        }
    }
    return 0;
}

function releaseLabTaskSlot(PDO $db, int $slot): void
{
    if ($slot < 1) {
        return;
    }
    $stmt = $db->prepare("SELECT pg_advisory_unlock(:class_key, :slot_key)");
    $stmt->bindValue(':class_key', labTaskSemaphoreKeyClass(), PDO::PARAM_INT);
    $stmt->bindValue(':slot_key', $slot, PDO::PARAM_INT);
    $stmt->execute();
}

function buildLabTaskWorkerMeta(int $slot): array
{
    $slot = max(1, $slot);
    $pid = function_exists('getmypid') ? (int) getmypid() : 0;
    $host = trim((string) @php_uname('n'));
    if ($host === '') {
        $host = 'localhost';
    }
    $id = 'worker-' . $slot;
    return [
        'id' => $id,
        'name' => $id . '@' . $host . ($pid > 0 ? ('#' . $pid) : ''),
        'slot' => $slot,
        'pid' => $pid > 0 ? $pid : null,
        'host' => $host,
    ];
}

function claimNextPendingLabTask(PDO $db, array $workerMeta = []): ?array
{
    $workerJson = json_encode($workerMeta, JSON_UNESCAPED_UNICODE);
    if (!is_string($workerJson) || trim($workerJson) === '') {
        $workerJson = '{}';
    }

    $db->beginTransaction();
    try {
        $pickStmt = $db->prepare(
            "SELECT id
             FROM lab_tasks
             WHERE status = 'pending'
             ORDER BY created_at ASC
             FOR UPDATE SKIP LOCKED
             LIMIT 1"
        );
        $pickStmt->execute();
        $taskId = $pickStmt->fetchColumn();
        if ($taskId === false) {
            $db->commit();
            return null;
        }

        $runStmt = $db->prepare(
            "UPDATE lab_tasks
             SET status = 'running',
                 started_at = COALESCE(started_at, NOW()),
                 attempts = attempts + 1,
                 payload = COALESCE(payload, '{}'::jsonb) || jsonb_build_object('worker', CAST(:worker_meta AS jsonb)),
                 updated_at = NOW()
             WHERE id = :id
             RETURNING id, lab_id, node_id, action, status, payload, result_data, error_text, attempts,
                       requested_by_user_id, requested_by,
                       created_at, started_at, finished_at, updated_at"
        );
        $runStmt->bindValue(':worker_meta', $workerJson, PDO::PARAM_STR);
        $runStmt->bindValue(':id', (string) $taskId, PDO::PARAM_STR);
        $runStmt->execute();
        $row = $runStmt->fetch(PDO::FETCH_ASSOC);
        $db->commit();
        if ($row === false) {
            return null;
        }
        return mapLabTaskRow($row);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function completeLabTaskAsDone(PDO $db, string $taskId, array $resultData, array $workerMeta = []): bool
{
    if (!empty($workerMeta)) {
        $resultData['worker'] = $workerMeta;
    }
    $json = json_encode($resultData, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        $json = '{}';
    }
    $stmt = $db->prepare(
        "UPDATE lab_tasks
         SET status = 'done',
             result_data = CAST(:result_data AS jsonb),
             error_text = NULL,
             finished_at = NOW(),
             updated_at = NOW()
         WHERE id = :id
           AND status = 'running'"
    );
    $stmt->bindValue(':result_data', $json, PDO::PARAM_STR);
    $stmt->bindValue(':id', $taskId, PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->rowCount() < 1) {
        v2AppLogWrite('task_worker', 'ERROR', [
            'event' => 'task_done_skipped',
            'task_id' => $taskId,
        ]);
        return false;
    }
    v2AppLogWrite('task_worker', 'OK', [
        'event' => 'task_done',
        'task_id' => $taskId,
    ]);
    return true;
}

function completeLabTaskAsFailed(PDO $db, string $taskId, string $errorText, array $workerMeta = []): bool
{
    $trimmedError = trim($errorText);
    if ($trimmedError === '') {
        $trimmedError = 'Task failed';
    }
    if (strlen($trimmedError) > 4000) {
        $trimmedError = substr($trimmedError, 0, 4000);
    }

    $resultData = [];
    if (!empty($workerMeta)) {
        $resultData['worker'] = $workerMeta;
    }
    $json = json_encode($resultData, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        $json = '{}';
    }

    $stmt = $db->prepare(
        "UPDATE lab_tasks
         SET status = 'failed',
             result_data = CAST(:result_data AS jsonb),
             error_text = :error_text,
             finished_at = NOW(),
             updated_at = NOW()
         WHERE id = :id
           AND status = 'running'"
    );
    $stmt->bindValue(':result_data', $json, PDO::PARAM_STR);
    $stmt->bindValue(':error_text', $trimmedError, PDO::PARAM_STR);
    $stmt->bindValue(':id', $taskId, PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->rowCount() < 1) {
        v2AppLogWrite('task_worker', 'ERROR', [
            'event' => 'task_failed_skipped',
            'task_id' => $taskId,
            'error' => $trimmedError,
        ]);
        return false;
    }
    v2AppLogWrite('task_worker', 'ERROR', [
        'event' => 'task_failed',
        'task_id' => $taskId,
        'error' => $trimmedError,
    ]);
    return true;
}

function setNodePowerError(PDO $db, string $labId, string $nodeId, string $errorText): void
{
    $trimmedError = trim($errorText);
    if ($trimmedError === '') {
        $trimmedError = 'Unknown error';
    }
    if (strlen($trimmedError) > 2000) {
        $trimmedError = substr($trimmedError, 0, 2000);
    }
    $stmt = $db->prepare(
        "UPDATE lab_nodes
         SET power_state = 'error',
             last_error = :error_text,
             power_updated_at = NOW(),
             updated_at = NOW()
         WHERE id = :node_id
           AND lab_id = :lab_id"
    );
    $stmt->bindValue(':error_text', $trimmedError, PDO::PARAM_STR);
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
}

function applyNodePowerAction(PDO $db, array $task): array
{
    $taskId = (string) ($task['id'] ?? '');
    $labId = (string) ($task['lab_id'] ?? '');
    $nodeId = (string) ($task['node_id'] ?? '');
    $action = normalizeLabTaskAction((string) ($task['action'] ?? ''));

    if ($taskId === '' || $labId === '' || $nodeId === '') {
        throw new RuntimeException('Task payload is invalid');
    }
    if (function_exists('isLabDeletionGuardActive') && isLabDeletionGuardActive($db, $labId)) {
        throw new RuntimeException('Lab is being deleted');
    }

    $runtimeResult = ($action === 'start')
        ? startLabNodeRuntime($db, $labId, $nodeId)
        : stopLabNodeRuntime($db, $labId, $nodeId);

    return [
        'action' => $action,
        'applied' => true,
        'runtime' => $runtimeResult,
        'node' => getLabNodePowerSnapshot($db, $labId, $nodeId),
    ];
}

function runLabTaskWorker(PDO $db, int $maxParallel = 3, int $maxTasksPerRun = 20): int
{
    $slot = tryAcquireLabTaskSlot($db, $maxParallel);
    if ($slot < 1) {
        v2AppLogWrite('task_worker', 'ERROR', [
            'event' => 'worker_slot_busy',
            'max_parallel' => max(1, $maxParallel),
        ]);
        return 0;
    }

    $workerMeta = buildLabTaskWorkerMeta($slot);
    v2AppLogWrite('task_worker', 'OK', [
        'event' => 'worker_started',
        'slot' => $slot,
        'worker' => $workerMeta,
        'max_parallel' => max(1, $maxParallel),
        'max_tasks_per_run' => max(1, $maxTasksPerRun),
    ]);

    $processed = 0;
    try {
        while ($processed < max(1, $maxTasksPerRun)) {
            $task = claimNextPendingLabTask($db, $workerMeta);
            if ($task === null) {
                break;
            }
            $taskId = (string) ($task['id'] ?? '');
            $labId = (string) ($task['lab_id'] ?? '');
            $nodeId = (string) ($task['node_id'] ?? '');
            try {
                $result = applyNodePowerAction($db, $task);
                completeLabTaskAsDone($db, $taskId, $result, $workerMeta);
            } catch (Throwable $e) {
                $failedMarked = completeLabTaskAsFailed($db, $taskId, $e->getMessage(), $workerMeta);
                v2AppLogWrite('task_worker', 'ERROR', [
                    'event' => 'task_execute_exception',
                    'task_id' => $taskId,
                    'lab_id' => $labId,
                    'node_id' => $nodeId,
                    'error' => $e->getMessage(),
                ]);
                if ($failedMarked && $labId !== '' && $nodeId !== '') {
                    setNodePowerError($db, $labId, $nodeId, $e->getMessage());
                }
            }
            $processed++;
        }
    } finally {
        v2AppLogWrite('task_worker', 'OK', [
            'event' => 'worker_finished',
            'slot' => $slot,
            'worker' => $workerMeta,
            'processed' => $processed,
        ]);
        releaseLabTaskSlot($db, $slot);
    }

    return $processed;
}
