<?php

declare(strict_types=1);

require_once __DIR__ . '/LabRuntimeService.php';
require_once __DIR__ . '/AppLogService.php';
require_once __DIR__ . '/LabCheckService.php';

function normalizeLabTaskAction(string $action): string
{
    $action = strtolower(trim($action));
    if (!in_array($action, ['start', 'stop', 'lab_check'], true)) {
        throw new InvalidArgumentException('action_invalid');
    }
    return $action;
}

function isLabTaskPowerAction(string $action): bool
{
    $action = normalizeLabTaskAction($action);
    return $action === 'start' || $action === 'stop';
}

function normalizeLabTaskWorkerMode(string $mode): string
{
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['any', 'start', 'stop', 'check'], true)) {
        return 'any';
    }
    return $mode;
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

function normalizeLabCheckTaskProgress(array $payload = [], array $resultData = []): array
{
    $run = [];
    if (isset($resultData['run']) && is_array($resultData['run'])) {
        $run = (array) $resultData['run'];
    }

    $total = isset($payload['checks_total']) ? (int) $payload['checks_total'] : 0;
    $done = isset($payload['checks_done']) ? (int) $payload['checks_done'] : 0;
    $percent = isset($payload['checks_percent']) ? (int) $payload['checks_percent'] : -1;
    $runId = trim((string) ($payload['checks_run_id'] ?? ''));

    if (!empty($run)) {
        $runTotal = isset($run['total_items']) ? (int) $run['total_items'] : 0;
        $runDone = (int) ($run['passed_items'] ?? 0) + (int) ($run['failed_items'] ?? 0) + (int) ($run['error_items'] ?? 0);
        if ($runTotal > 0) {
            $total = max($total, $runTotal);
        }
        if ($runDone > 0) {
            $done = max($done, $runDone);
        }
        if ($percent < 0) {
            $runPercent = isset($run['score_percent']) ? (float) $run['score_percent'] : -1.0;
            if ($runPercent >= 0.0) {
                $percent = (int) round($runPercent);
            }
        }
        if ($runId === '') {
            $runId = trim((string) ($run['id'] ?? ''));
        }
    }

    if ($total < 0) {
        $total = 0;
    }
    if ($done < 0) {
        $done = 0;
    }
    if ($total > 0 && $done > $total) {
        $done = $total;
    }
    if ($percent < 0) {
        $percent = $total > 0 ? (int) round(($done / $total) * 100.0) : 0;
    }
    if ($percent < 0) {
        $percent = 0;
    } elseif ($percent > 100) {
        $percent = 100;
    }

    return [
        'total' => $total,
        'done' => $done,
        'percent' => $percent,
        'run_id' => $runId !== '' ? $runId : null,
    ];
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
    $workerMode = null;
    if (is_array($workerMeta)) {
        $workerIdRaw = trim((string) ($workerMeta['id'] ?? ''));
        $workerNameRaw = trim((string) ($workerMeta['name'] ?? ''));
        $workerHostRaw = trim((string) ($workerMeta['host'] ?? ''));
        $workerSlotRaw = isset($workerMeta['slot']) ? (int) $workerMeta['slot'] : 0;
        $workerPidRaw = isset($workerMeta['pid']) ? (int) $workerMeta['pid'] : 0;
        $workerModeRaw = normalizeLabTaskWorkerMode((string) ($workerMeta['mode'] ?? 'any'));
        $workerId = $workerIdRaw !== '' ? $workerIdRaw : null;
        $workerHost = $workerHostRaw !== '' ? $workerHostRaw : null;
        $workerSlot = $workerSlotRaw > 0 ? $workerSlotRaw : null;
        $workerPid = $workerPidRaw > 0 ? $workerPidRaw : null;
        $workerMode = $workerModeRaw;
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
    $bulkOperationId = null;
    $bulkOperationLabel = null;
    $bulkOperationTotal = null;
    $bulkOperationIndex = null;
    if (is_array($payload)) {
        $bulkIdRaw = trim((string) ($payload['bulk_operation_id'] ?? ''));
        $bulkLabelRaw = trim((string) ($payload['bulk_operation_label'] ?? ''));
        $bulkTotalRaw = isset($payload['bulk_operation_total']) ? (int) $payload['bulk_operation_total'] : 0;
        $bulkIndexRaw = isset($payload['bulk_operation_index']) ? (int) $payload['bulk_operation_index'] : 0;
        $bulkOperationId = $bulkIdRaw !== '' ? $bulkIdRaw : null;
        $bulkOperationLabel = $bulkLabelRaw !== '' ? $bulkLabelRaw : null;
        $bulkOperationTotal = $bulkTotalRaw > 0 ? $bulkTotalRaw : null;
        $bulkOperationIndex = $bulkIndexRaw > 0 ? $bulkIndexRaw : null;
    }
    $checkProgress = normalizeLabCheckTaskProgress(is_array($payload) ? $payload : [], is_array($resultData) ? $resultData : []);
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
        'worker_mode' => $workerMode,
        'bulk_operation_id' => $bulkOperationId,
        'bulk_operation_label' => $bulkOperationLabel,
        'bulk_operation_total' => $bulkOperationTotal,
        'bulk_operation_index' => $bulkOperationIndex,
        'progress_total' => (int) ($checkProgress['total'] ?? 0),
        'progress_done' => (int) ($checkProgress['done'] ?? 0),
        'progress_pct' => (int) ($checkProgress['percent'] ?? 0),
        'run_id' => isset($checkProgress['run_id']) ? (string) $checkProgress['run_id'] : null,
        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        'started_at' => isset($row['started_at']) ? (string) $row['started_at'] : null,
        'finished_at' => isset($row['finished_at']) ? (string) $row['finished_at'] : null,
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
    ];
}

function normalizeLabTaskEnqueueMeta(array $meta): array
{
    $out = [];
    $bulkId = trim((string) ($meta['bulk_operation_id'] ?? ''));
    if ($bulkId !== '' && preg_match('/^[a-zA-Z0-9._:-]{1,80}$/', $bulkId)) {
        $out['bulk_operation_id'] = $bulkId;
    }

    $bulkLabel = trim((string) ($meta['bulk_operation_label'] ?? ''));
    if ($bulkLabel !== '') {
        if (strlen($bulkLabel) > 120) {
            $bulkLabel = substr($bulkLabel, 0, 120);
        }
        $out['bulk_operation_label'] = $bulkLabel;
    }

    $bulkTotal = isset($meta['bulk_operation_total']) ? (int) $meta['bulk_operation_total'] : 0;
    if ($bulkTotal > 0 && $bulkTotal <= 5000) {
        $out['bulk_operation_total'] = $bulkTotal;
    }

    $bulkIndex = isset($meta['bulk_operation_index']) ? (int) $meta['bulk_operation_index'] : 0;
    if ($bulkIndex > 0 && $bulkIndex <= 5000) {
        $out['bulk_operation_index'] = $bulkIndex;
    }

    return $out;
}

function normalizeLabTaskStatusFilter(string $status): string
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['pending', 'running', 'done', 'failed'], true)) {
        return '';
    }
    return $status;
}

function labTaskGroupsTableExists(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) {
        return (bool) $cache;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = ANY (current_schemas(true))
               AND table_name = 'lab_task_groups'
             LIMIT 1"
        );
        $stmt->execute();
        $cache = $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        $cache = false;
    }
    return (bool) $cache;
}

function backfillLabTaskGroupsIfEmpty(PDO $db): void
{
    if (!labTaskGroupsTableExists($db)) {
        return;
    }
    try {
        $existsStmt = $db->query('SELECT 1 FROM lab_task_groups LIMIT 1');
        if ($existsStmt !== false && $existsStmt->fetchColumn() !== false) {
            return;
        }

        $db->exec(
            "INSERT INTO lab_task_groups (
                 id, lab_id, action, status, label, requested_by_user_id, requested_by,
                 total, queued, running, done, failed, skipped, attempts, progress_pct, error_text,
                 created_at, started_at, finished_at, updated_at
             )
             SELECT
                 agg.group_id AS id,
                 agg.lab_id,
                 agg.action,
                 CASE
                     WHEN agg.pending_count = 0 AND agg.running_count = 0 THEN
                         CASE WHEN agg.failed_count > 0 THEN 'failed' ELSE 'done' END
                     WHEN agg.running_count > 0 OR agg.started_at IS NOT NULL THEN 'running'
                     ELSE 'pending'
                 END AS status,
                 agg.label,
                 agg.requested_by_user_id,
                 agg.requested_by,
                 agg.total_count AS total,
                 agg.pending_count AS queued,
                 agg.running_count AS running,
                 agg.done_count AS done,
                 agg.failed_count AS failed,
                 0 AS skipped,
                 agg.max_attempts AS attempts,
                 CASE
                     WHEN agg.total_count > 0 THEN LEAST(
                         100,
                         GREATEST(0, ROUND((((agg.done_count + agg.failed_count)::numeric / agg.total_count::numeric) * 100.0))::int)
                     )
                     ELSE 100
                 END AS progress_pct,
                 NULL::text AS error_text,
                 agg.created_at,
                 agg.started_at,
                 CASE
                     WHEN agg.pending_count = 0 AND agg.running_count = 0 THEN agg.finished_at
                     ELSE NULL
                 END AS finished_at,
                 agg.updated_at
             FROM (
                 SELECT
                     t.payload->>'bulk_operation_id' AS group_id,
                     (ARRAY_AGG(t.lab_id ORDER BY t.created_at DESC))[1] AS lab_id,
                     (ARRAY_AGG(t.action ORDER BY t.created_at DESC))[1] AS action,
                     (ARRAY_AGG(NULLIF(t.payload->>'bulk_operation_label', '') ORDER BY t.created_at DESC))[1] AS label,
                     (ARRAY_AGG(t.requested_by_user_id ORDER BY t.created_at DESC))[1] AS requested_by_user_id,
                     (ARRAY_AGG(NULLIF(t.requested_by, '') ORDER BY t.created_at DESC))[1] AS requested_by,
                     COUNT(*)::int AS total_count,
                     SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END)::int AS pending_count,
                     SUM(CASE WHEN t.status = 'running' THEN 1 ELSE 0 END)::int AS running_count,
                     SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END)::int AS done_count,
                     SUM(CASE WHEN t.status = 'failed' THEN 1 ELSE 0 END)::int AS failed_count,
                     COALESCE(MAX(t.attempts), 0)::int AS max_attempts,
                     MIN(t.created_at) AS created_at,
                     MIN(t.started_at) FILTER (WHERE t.started_at IS NOT NULL) AS started_at,
                     MAX(t.finished_at) FILTER (WHERE t.finished_at IS NOT NULL) AS finished_at,
                     MAX(t.updated_at) AS updated_at
                 FROM lab_tasks t
                 WHERE COALESCE(t.payload->>'bulk_operation_id', '') <> ''
                   AND t.action IN ('start', 'stop')
                 GROUP BY t.payload->>'bulk_operation_id'
             ) agg
             ON CONFLICT (id) DO NOTHING"
        );
    } catch (Throwable $e) {
        // Keep listing resilient even if historical backfill fails.
    }
}

function mapLabTaskGroupRow(array $row): array
{
    $workerNameRaw = isset($row['worker_name']) ? trim((string) $row['worker_name']) : '';
    return [
        'id' => (string) ($row['id'] ?? ''),
        'lab_id' => (string) ($row['lab_id'] ?? ''),
        'lab_name' => isset($row['lab_name']) ? (string) $row['lab_name'] : null,
        'lab_author_username' => isset($row['lab_author_username']) ? (string) $row['lab_author_username'] : null,
        'action' => (string) ($row['action'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'label' => isset($row['label']) ? (string) $row['label'] : null,
        'requested_by_user_id' => isset($row['requested_by_user_id']) ? (string) $row['requested_by_user_id'] : null,
        'requested_by' => isset($row['requested_by']) ? (string) $row['requested_by'] : null,
        'total' => isset($row['total']) ? (int) $row['total'] : 0,
        'queued' => isset($row['queued']) ? (int) $row['queued'] : 0,
        'running' => isset($row['running']) ? (int) $row['running'] : 0,
        'done' => isset($row['done']) ? (int) $row['done'] : 0,
        'failed' => isset($row['failed']) ? (int) $row['failed'] : 0,
        'skipped' => isset($row['skipped']) ? (int) $row['skipped'] : 0,
        'attempts' => isset($row['attempts']) ? (int) $row['attempts'] : 0,
        'progress_pct' => isset($row['progress_pct']) ? (int) $row['progress_pct'] : 0,
        'worker_name' => $workerNameRaw !== '' ? $workerNameRaw : null,
        'error_text' => isset($row['error_text']) ? (string) $row['error_text'] : null,
        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        'started_at' => isset($row['started_at']) ? (string) $row['started_at'] : null,
        'finished_at' => isset($row['finished_at']) ? (string) $row['finished_at'] : null,
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
    ];
}

function upsertLabTaskGroup(PDO $db, array $viewer, string $groupId, string $labId, string $action, string $label, int $total): ?array
{
    if (!labTaskGroupsTableExists($db)) {
        return null;
    }
    $groupId = trim($groupId);
    if ($groupId === '') {
        return null;
    }
    $action = normalizeLabTaskAction($action);
    if (!isLabTaskPowerAction($action)) {
        throw new InvalidArgumentException('group_action_invalid');
    }
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    $viewerName = trim((string) ($viewer['username'] ?? ''));
    $safeLabel = trim($label);
    if (strlen($safeLabel) > 160) {
        $safeLabel = substr($safeLabel, 0, 160);
    }
    $total = max(0, $total);
    $stmt = $db->prepare(
        "INSERT INTO lab_task_groups (
             id, lab_id, action, status, label, requested_by_user_id, requested_by, total, queued, running, done, failed, skipped, attempts, progress_pct
         ) VALUES (
             :id, :lab_id, :action, 'pending', :label, :requested_by_user_id, :requested_by, :total, 0, 0, 0, 0, 0, 0, 0
         )
         ON CONFLICT (id)
         DO UPDATE SET
             lab_id = EXCLUDED.lab_id,
             action = EXCLUDED.action,
             label = COALESCE(NULLIF(EXCLUDED.label, ''), lab_task_groups.label),
             requested_by_user_id = COALESCE(EXCLUDED.requested_by_user_id, lab_task_groups.requested_by_user_id),
             requested_by = COALESCE(NULLIF(EXCLUDED.requested_by, ''), lab_task_groups.requested_by),
             total = GREATEST(lab_task_groups.total, EXCLUDED.total),
             updated_at = NOW()
         RETURNING id, lab_id, action, status, label, requested_by_user_id, requested_by,
                   total, queued, running, done, failed, skipped, attempts, progress_pct, error_text,
                   created_at, started_at, finished_at, updated_at"
    );
    $stmt->bindValue(':id', $groupId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->bindValue(':action', $action, PDO::PARAM_STR);
    $stmt->bindValue(':label', $safeLabel !== '' ? $safeLabel : null, $safeLabel !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':requested_by_user_id', $viewerId !== '' ? $viewerId : null, $viewerId !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':requested_by', $viewerName !== '' ? $viewerName : null, $viewerName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':total', $total, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? mapLabTaskGroupRow($row) : null;
}

function syncLabTaskGroupProgress(PDO $db, string $groupId): ?array
{
    if (!labTaskGroupsTableExists($db)) {
        return null;
    }
    $gid = trim($groupId);
    if ($gid === '') {
        return null;
    }

    $db->beginTransaction();
    try {
        $lockStmt = $db->prepare(
            "SELECT id, total
             FROM lab_task_groups
             WHERE id = :id
             FOR UPDATE"
        );
        $lockStmt->bindValue(':id', $gid, PDO::PARAM_STR);
        $lockStmt->execute();
        $current = $lockStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            $db->commit();
            return null;
        }

        $aggStmt = $db->prepare(
            "SELECT
                COUNT(*) AS total_rows,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_rows,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running_rows,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done_rows,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_rows,
                COALESCE(MAX(attempts), 0) AS max_attempts,
                MAX(started_at) AS max_started_at
             FROM lab_tasks
             WHERE payload->>'bulk_operation_id' = :gid"
        );
        $aggStmt->bindValue(':gid', $gid, PDO::PARAM_STR);
        $aggStmt->execute();
        $agg = $aggStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($agg)) {
            $db->commit();
            return null;
        }

        $total = max((int) ($current['total'] ?? 0), (int) ($agg['total_rows'] ?? 0));
        $pending = (int) ($agg['pending_rows'] ?? 0);
        $running = (int) ($agg['running_rows'] ?? 0);
        $done = (int) ($agg['done_rows'] ?? 0);
        $failed = (int) ($agg['failed_rows'] ?? 0);
        $completed = max(0, $done + $failed);
        $attempts = (int) ($agg['max_attempts'] ?? 0);
        $pct = $total > 0 ? (int) round((min($total, $completed) / $total) * 100) : 100;
        $status = 'pending';
        $startedAt = isset($agg['max_started_at']) && $agg['max_started_at'] !== null ? (string) $agg['max_started_at'] : null;
        $finishedAt = null;
        if ($pending === 0 && $running === 0) {
            $status = $failed > 0 ? 'failed' : 'done';
            $finishedAt = gmdate('c');
        } elseif ($running > 0 || $startedAt !== null) {
            $status = 'running';
        }

        $updStmt = $db->prepare(
            "UPDATE lab_task_groups
             SET status = :status,
                 total = :total,
                 queued = :queued,
                 running = :running,
                 done = :done,
                 failed = :failed,
                 attempts = :attempts,
                 progress_pct = :progress_pct,
                 started_at = COALESCE(started_at, :started_at),
                 finished_at = :finished_at,
                 updated_at = NOW()
             WHERE id = :id
             RETURNING id, lab_id, action, status, label, requested_by_user_id, requested_by,
                       total, queued, running, done, failed, skipped, attempts, progress_pct, error_text,
                       created_at, started_at, finished_at, updated_at"
        );
        $updStmt->bindValue(':id', $gid, PDO::PARAM_STR);
        $updStmt->bindValue(':status', $status, PDO::PARAM_STR);
        $updStmt->bindValue(':total', $total, PDO::PARAM_INT);
        $updStmt->bindValue(':queued', min($total, $completed + $pending + $running), PDO::PARAM_INT);
        $updStmt->bindValue(':running', $running, PDO::PARAM_INT);
        $updStmt->bindValue(':done', $done, PDO::PARAM_INT);
        $updStmt->bindValue(':failed', $failed, PDO::PARAM_INT);
        $updStmt->bindValue(':attempts', $attempts, PDO::PARAM_INT);
        $updStmt->bindValue(':progress_pct', max(0, min(100, $pct)), PDO::PARAM_INT);
        $updStmt->bindValue(':started_at', $startedAt, $startedAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $updStmt->bindValue(':finished_at', $finishedAt, $finishedAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $updStmt->execute();
        $row = $updStmt->fetch(PDO::FETCH_ASSOC);
        $db->commit();
        return is_array($row) ? mapLabTaskGroupRow($row) : null;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
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

function findActiveLabCheckTaskForLab(PDO $db, string $labId, string $requestedByUserId = ''): ?array
{
    $labId = trim($labId);
    if ($labId === '') {
        return null;
    }
    $requestedByUserId = trim($requestedByUserId);

    $sql = "SELECT id, lab_id, node_id, action, status, payload, result_data, error_text, attempts,
                   requested_by_user_id, requested_by,
                   created_at, started_at, finished_at, updated_at
            FROM lab_tasks
            WHERE lab_id = :lab_id
              AND action = 'lab_check'
              AND status IN ('pending', 'running')";
    if ($requestedByUserId !== '') {
        $sql .= " AND requested_by_user_id = :requested_by_user_id";
    }
    $sql .= " ORDER BY created_at ASC LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    if ($requestedByUserId !== '') {
        $stmt->bindValue(':requested_by_user_id', $requestedByUserId, PDO::PARAM_STR);
    }
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : mapLabTaskRow($row);
}

function enqueueLabCheckTask(PDO $db, array $viewer, string $labId): array
{
    $labId = trim($labId);
    if ($labId === '') {
        throw new RuntimeException('Lab not found');
    }
    if (!viewerCanViewLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }
    $viewerUsername = trim((string) ($viewer['username'] ?? ''));
    $viewerRole = strtolower(trim((string) ($viewer['role_name'] ?? $viewer['role'] ?? '')));

    $items = labCheckLoadItemsRaw($db, $labId, true);
    if (!is_array($items) || count($items) === 0) {
        throw new RuntimeException('No checks configured');
    }

    $checkNodeId = '';
    foreach ($items as $item) {
        $nid = trim((string) ($item['node_id'] ?? ''));
        if ($nid !== '') {
            $checkNodeId = $nid;
            break;
        }
    }
    if ($checkNodeId === '') {
        throw new RuntimeException('No checks configured');
    }

    $payloadBase = [
        'requested_at' => gmdate('c'),
        'requested_from_ip' => clientIp(),
        'requested_by_role' => $viewerRole !== '' ? $viewerRole : 'user',
        'checks_total' => count($items),
    ];
    $payloadJson = json_encode($payloadBase, JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadJson) || trim($payloadJson) === '') {
        $payloadJson = '{}';
    }

    $task = null;
    $db->beginTransaction();
    try {
        $lockLabStmt = $db->prepare(
            "SELECT id
             FROM labs
             WHERE id = :lab_id
             FOR UPDATE"
        );
        $lockLabStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $lockLabStmt->execute();
        if ($lockLabStmt->fetchColumn() === false) {
            throw new RuntimeException('Lab not found');
        }

        $activeTask = findActiveLabCheckTaskForLab($db, $labId);
        if ($activeTask !== null) {
            $db->commit();
            return [
                'queued' => false,
                'reason' => 'task_in_progress',
                'task' => $activeTask,
            ];
        }

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
                'lab_check',
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
        $insertStmt->bindValue(':node_id', $checkNodeId, PDO::PARAM_STR);
        $insertStmt->bindValue(':payload', $payloadJson, PDO::PARAM_STR);
        $insertStmt->bindValue(':requested_by_user_id', $viewerId, PDO::PARAM_STR);
        $insertStmt->bindValue(':requested_by', $viewerUsername !== '' ? $viewerUsername : null, $viewerUsername !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        try {
            $insertStmt->execute();
        } catch (Throwable $e) {
            $sqlState = trim((string) ($e instanceof PDOException ? $e->getCode() : ''));
            if ($sqlState === '23505') {
                $active = findActiveLabCheckTaskForLab($db, $labId);
                if ($active !== null) {
                    $db->commit();
                    return [
                        'queued' => false,
                        'reason' => 'task_in_progress',
                        'task' => $active,
                    ];
                }
            }
            throw $e;
        }
        $row = $insertStmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Task insert failed');
        }
        $task = mapLabTaskRow($row);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    if (!is_array($task)) {
        throw new RuntimeException('Task insert failed');
    }

    kickLabTaskWorkerAsync('check');
    v2AppLogWrite('task_worker', 'OK', v2AppLogAttachUser([
        'event' => 'lab_check_task_enqueue',
        'task_id' => (string) ($task['id'] ?? ''),
        'lab_id' => $labId,
        'node_id' => $checkNodeId,
        'checks_total' => count($items),
        'queued' => true,
    ], $viewer));

    return [
        'queued' => true,
        'task' => $task,
    ];
}

function enqueueLabNodePowerTask(PDO $db, array $viewer, string $labId, string $nodeId, string $action, array $meta = []): array
{
    $action = normalizeLabTaskAction($action);
    if (!isLabTaskPowerAction($action)) {
        throw new InvalidArgumentException('action_invalid');
    }
    $meta = normalizeLabTaskEnqueueMeta($meta);
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
    $payloadBase = [
        'requested_at' => gmdate('c'),
        'requested_from_ip' => $viewerIp,
    ];
    if (!empty($meta)) {
        $payloadBase = array_merge($payloadBase, $meta);
    }
    $payloadJson = json_encode($payloadBase, JSON_UNESCAPED_UNICODE);
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

    kickLabTaskWorkerAsync($action === 'stop' ? 'stop' : 'start');

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

function listRecentLabTasksForViewer(PDO $db, array $viewer, int $limit = 150, string $statusFilter = '', int $page = 1): array
{
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $isAdmin = (($viewer['role_name'] ?? '') === 'admin');
    $limit = max(1, min(500, $limit));
    $page = max(1, $page);
    $offset = ($page - 1) * $limit;
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

    $baseFrom = " FROM lab_tasks t
            JOIN labs l ON l.id = t.lab_id
            LEFT JOIN users owner ON owner.id = l.author_user_id
            LEFT JOIN lab_nodes n ON n.id = t.node_id";

    $countSql = "SELECT COUNT(*)" . $baseFrom;
    if (!empty($where)) {
        $countSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $countStmt = $db->prepare($countSql);
    if ($status !== '') {
        $countStmt->bindValue(':status', $status, PDO::PARAM_STR);
    }
    if (!$isAdmin) {
        $countStmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / max(1, $limit)));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $sql = "SELECT t.id, t.lab_id, l.name AS lab_name, owner.username AS lab_author_username,
                   t.node_id, n.name AS node_name,
                   t.action, t.status, t.payload, t.result_data, t.error_text, t.attempts,
                   t.requested_by_user_id, t.requested_by,
                   t.created_at, t.started_at, t.finished_at, t.updated_at
            " . $baseFrom;
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY t.created_at DESC LIMIT :limit_rows OFFSET :offset_rows';

    $stmt = $db->prepare($sql);
    if ($status !== '') {
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    }
    if (!$isAdmin) {
        $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [
            'items' => [],
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }
    return [
        'items' => array_map(static function (array $row): array {
            return mapLabTaskRow($row);
        }, $rows),
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ];
}

function listLabTaskGroupsForViewer(PDO $db, array $viewer, int $limit = 150, string $statusFilter = '', int $page = 1): array
{
    if (!labTaskGroupsTableExists($db)) {
        return [
            'items' => [],
            'meta' => ['page' => 1, 'limit' => max(1, min(500, $limit)), 'total' => 0, 'total_pages' => 1],
        ];
    }
    backfillLabTaskGroupsIfEmpty($db);

    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $isAdmin = (($viewer['role_name'] ?? '') === 'admin');
    $limit = max(1, min(500, $limit));
    $page = max(1, $page);
    $offset = ($page - 1) * $limit;
    $status = normalizeLabTaskStatusFilter($statusFilter);

    $where = [];
    if ($status !== '') {
        $where[] = 'g.status = :status';
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

    $baseFrom = " FROM lab_task_groups g
            JOIN labs l ON l.id = g.lab_id
            LEFT JOIN users owner ON owner.id = l.author_user_id
            LEFT JOIN LATERAL (
                SELECT STRING_AGG(DISTINCT w.worker_name, ', ' ORDER BY w.worker_name) AS worker_name
                FROM (
                    SELECT COALESCE(
                               NULLIF(t.payload->'worker'->>'id', ''),
                               NULLIF(t.payload->'worker'->>'name', ''),
                               NULLIF(t.result_data->'worker'->>'id', ''),
                               NULLIF(t.result_data->'worker'->>'name', '')
                           ) AS worker_name
                    FROM lab_tasks t
                    WHERE t.payload->>'bulk_operation_id' = g.id
                ) w
                WHERE w.worker_name IS NOT NULL
            ) gw ON TRUE";

    $countSql = "SELECT COUNT(*)" . $baseFrom;
    if (!empty($where)) {
        $countSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $countStmt = $db->prepare($countSql);
    if ($status !== '') {
        $countStmt->bindValue(':status', $status, PDO::PARAM_STR);
    }
    if (!$isAdmin) {
        $countStmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / max(1, $limit)));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $sql = "SELECT g.id, g.lab_id, l.name AS lab_name, owner.username AS lab_author_username,
                   g.action, g.status, g.label, g.requested_by_user_id, g.requested_by,
                   g.total, g.queued, g.running, g.done, g.failed, g.skipped, g.attempts, g.progress_pct,
                   gw.worker_name,
                   g.error_text, g.created_at, g.started_at, g.finished_at, g.updated_at
            " . $baseFrom;
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY g.created_at DESC LIMIT :limit_rows OFFSET :offset_rows';

    $stmt = $db->prepare($sql);
    if ($status !== '') {
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    }
    if (!$isAdmin) {
        $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    return [
        'items' => array_map(static function (array $row): array {
            return mapLabTaskGroupRow($row);
        }, $rows),
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ];
}

function listLabTaskGroupsForLab(PDO $db, array $viewer, string $labId, int $limit = 100): array
{
    if (!labTaskGroupsTableExists($db)) {
        return [];
    }
    backfillLabTaskGroupsIfEmpty($db);
    if (!viewerCanViewLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }
    $limit = max(1, min(500, $limit));
    $stmt = $db->prepare(
        "SELECT g.id, g.lab_id, l.name AS lab_name, owner.username AS lab_author_username,
                g.action, g.status, g.label, g.requested_by_user_id, g.requested_by,
                g.total, g.queued, g.running, g.done, g.failed, g.skipped, g.attempts, g.progress_pct,
                gw.worker_name,
                g.error_text, g.created_at, g.started_at, g.finished_at, g.updated_at
         FROM lab_task_groups g
         JOIN labs l ON l.id = g.lab_id
         LEFT JOIN users owner ON owner.id = l.author_user_id
         LEFT JOIN LATERAL (
             SELECT STRING_AGG(DISTINCT w.worker_name, ', ' ORDER BY w.worker_name) AS worker_name
             FROM (
                 SELECT COALESCE(
                            NULLIF(t.payload->'worker'->>'id', ''),
                            NULLIF(t.payload->'worker'->>'name', ''),
                            NULLIF(t.result_data->'worker'->>'id', ''),
                            NULLIF(t.result_data->'worker'->>'name', '')
                        ) AS worker_name
                 FROM lab_tasks t
                 WHERE t.payload->>'bulk_operation_id' = g.id
             ) w
             WHERE w.worker_name IS NOT NULL
         ) gw ON TRUE
         WHERE g.lab_id = :lab_id
         ORDER BY g.created_at DESC
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
        return mapLabTaskGroupRow($row);
    }, $rows);
}

function enqueueLabBulkPowerTask(PDO $db, array $viewer, string $labId, string $action): array
{
    $action = normalizeLabTaskAction($action);
    if (!isLabTaskPowerAction($action)) {
        throw new InvalidArgumentException('action_invalid');
    }
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $nodesStmt = $db->prepare(
        "SELECT id, name, is_running, power_state
         FROM lab_nodes
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC"
    );
    $nodesStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $nodesStmt->execute();
    $nodes = $nodesStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($nodes)) {
        $nodes = [];
    }

    $candidateIds = [];
    foreach ($nodes as $node) {
        $nodeId = trim((string) ($node['id'] ?? ''));
        if ($nodeId === '') {
            continue;
        }
        $state = strtolower(trim((string) ($node['power_state'] ?? '')));
        $isRunning = !empty($node['is_running']) || $state === 'running';
        if ($action === 'start') {
            if ($isRunning || $state === 'starting') {
                continue;
            }
        } else {
            if ((!$isRunning && $state === 'stopped') || $state === 'stopping') {
                continue;
            }
        }
        $candidateIds[] = $nodeId;
    }

    if (count($candidateIds) === 0) {
        return [
            'group' => null,
            'queued' => 0,
            'skipped' => count($nodes),
            'failed' => 0,
            'task_ids' => [],
        ];
    }

    $activeStmt = $db->prepare(
        "SELECT DISTINCT node_id
         FROM lab_tasks
         WHERE status IN ('pending', 'running')
           AND lab_id = :lab_id"
    );
    $activeStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $activeStmt->execute();
    $activeRows = $activeStmt->fetchAll(PDO::FETCH_ASSOC);
    $activeMap = [];
    if (is_array($activeRows)) {
        foreach ($activeRows as $row) {
            $nid = trim((string) ($row['node_id'] ?? ''));
            if ($nid !== '') {
                $activeMap[$nid] = true;
            }
        }
    }

    $finalNodeIds = [];
    $skipped = 0;
    foreach ($candidateIds as $nodeId) {
        if (isset($activeMap[$nodeId])) {
            $skipped++;
            continue;
        }
        $finalNodeIds[] = $nodeId;
    }
    if (count($finalNodeIds) === 0) {
        return [
            'group' => null,
            'queued' => 0,
            'skipped' => $skipped,
            'failed' => 0,
            'task_ids' => [],
        ];
    }

    $groupId = function_exists('uuidv4') ? uuidv4() : ('group_' . bin2hex(random_bytes(8)));
    $groupLabel = ($action === 'start' ? 'Включение' : 'Выключение') . ': ' . count($finalNodeIds) . ' nodes';
    $group = upsertLabTaskGroup($db, $viewer, $groupId, $labId, $action, $groupLabel, count($finalNodeIds));

    $viewerId = (string) ($viewer['id'] ?? '');
    $viewerUsername = (string) ($viewer['username'] ?? '');
    $payloadBase = [
        'requested_at' => gmdate('c'),
        'requested_from_ip' => clientIp(),
        'bulk_operation_id' => $groupId,
        'bulk_operation_label' => $groupLabel,
        'bulk_operation_total' => count($finalNodeIds),
    ];

    $queued = 0;
    $failed = 0;
    $taskIds = [];
    $nextPowerState = $action === 'start' ? 'starting' : 'stopping';
    $db->beginTransaction();
    try {
        foreach ($finalNodeIds as $idx => $nodeId) {
            $payload = $payloadBase;
            $payload['bulk_operation_index'] = $idx + 1;
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if (!is_string($payloadJson)) {
                $payloadJson = '{}';
            }
            $insertStmt = $db->prepare(
                "INSERT INTO lab_tasks (
                    lab_id, node_id, action, status, payload, requested_by_user_id, requested_by
                 ) VALUES (
                    :lab_id, :node_id, :action, 'pending', CAST(:payload AS jsonb), :requested_by_user_id, :requested_by
                 )
                 RETURNING id"
            );
            $insertStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
            $insertStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
            $insertStmt->bindValue(':action', $action, PDO::PARAM_STR);
            $insertStmt->bindValue(':payload', $payloadJson, PDO::PARAM_STR);
            $insertStmt->bindValue(':requested_by_user_id', $viewerId !== '' ? $viewerId : null, $viewerId !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertStmt->bindValue(':requested_by', $viewerUsername !== '' ? $viewerUsername : null, $viewerUsername !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertStmt->execute();
            $taskId = (string) ($insertStmt->fetchColumn() ?: '');
            if ($taskId === '') {
                $failed++;
                continue;
            }
            $taskIds[] = $taskId;
            $queued++;
        }
        if ($queued > 0) {
            $nodesUpd = $db->prepare(
                "UPDATE lab_nodes
                 SET power_state = :power_state,
                     last_error = NULL,
                     power_updated_at = NOW(),
                     updated_at = NOW()
                 WHERE lab_id = :lab_id
                   AND id = :node_id"
            );
            foreach ($finalNodeIds as $nodeId) {
                $nodesUpd->bindValue(':power_state', $nextPowerState, PDO::PARAM_STR);
                $nodesUpd->bindValue(':lab_id', $labId, PDO::PARAM_STR);
                $nodesUpd->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
                $nodesUpd->execute();
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    if ($group !== null) {
        syncLabTaskGroupProgress($db, $groupId);
    }
    $settings = getTaskQueueSettings($db);
    if ($action === 'stop') {
        $slots = max(1, (int) ($settings['stop_worker_slots'] ?? ($settings['worker_slots'] ?? 1)));
        kickLabTaskWorkersAsync($slots, 'stop');
    } else {
        $slots = max(1, (int) ($settings['start_worker_slots'] ?? ($settings['worker_slots'] ?? 1)));
        kickLabTaskWorkersAsync($slots, 'start');
    }

    return [
        'group' => $group !== null ? syncLabTaskGroupProgress($db, $groupId) : null,
        'queued' => $queued,
        'skipped' => $skipped,
        'failed' => $failed,
        'task_ids' => $taskIds,
    ];
}

function stopLabTaskGroup(PDO $db, array $viewer, string $groupId): array
{
    $groupId = trim($groupId);
    if ($groupId === '' || !preg_match('/^[a-zA-Z0-9._:-]{1,80}$/', $groupId)) {
        throw new InvalidArgumentException('group_id_invalid');
    }

    $stmt = $db->prepare(
        "SELECT id
         FROM lab_tasks
         WHERE status IN ('pending', 'running')
           AND payload->>'bulk_operation_id' = :group_id
         ORDER BY created_at ASC"
    );
    $stmt->bindValue(':group_id', $groupId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || count($rows) === 0) {
        return [
            'group_id' => $groupId,
            'total' => 0,
            'stopped' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }

    $stopped = 0;
    $skipped = 0;
    $forbidden = 0;
    $errors = [];
    foreach ($rows as $row) {
        $taskId = trim((string) ($row['id'] ?? ''));
        if ($taskId === '') {
            $skipped += 1;
            continue;
        }
        try {
            stopLabTask($db, $viewer, $taskId);
            $stopped += 1;
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if ($msg === 'Task already finished') {
                $skipped += 1;
            } elseif ($msg === 'Forbidden') {
                $forbidden += 1;
            } else {
                $errors[] = ['task_id' => $taskId, 'error' => $msg];
            }
        } catch (Throwable $e) {
            $errors[] = ['task_id' => $taskId, 'error' => $e->getMessage()];
        }
    }

    if ($stopped === 0 && $forbidden > 0 && count($errors) === 0) {
        throw new RuntimeException('Forbidden');
    }

    $group = syncLabTaskGroupProgress($db, $groupId);
    return [
        'group_id' => $groupId,
        'total' => count($rows),
        'stopped' => $stopped,
        'skipped' => $skipped,
        'errors' => $errors,
        'group' => $group,
    ];
}

function clearLabTaskHistory(PDO $db, array $viewer): array
{
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }
    if (($viewer['role_name'] ?? '') !== 'admin') {
        throw new RuntimeException('Forbidden');
    }

    $stmt = $db->prepare(
        "DELETE FROM lab_tasks
         WHERE status IN ('done', 'failed')"
    );
    $stmt->execute();
    $deleted = (int) $stmt->rowCount();

    $context = v2AppLogAttachUser([
        'event' => 'task_history_clear',
        'deleted' => $deleted,
    ], $viewer);
    v2AppLogWrite('task_worker', 'OK', $context);

    return [
        'deleted' => $deleted,
    ];
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
        $groupId = trim((string) ($mapped['bulk_operation_id'] ?? ''));
        if ($groupId !== '') {
            syncLabTaskGroupProgress($db, $groupId);
        }
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
	$action = normalizeLabTaskAction((string) ($task['action'] ?? ''));
	if (!viewerCanEditLab($db, $viewer, $labId)) {
		throw new RuntimeException('Forbidden');
	}
	if (in_array($status, ['pending', 'running'], true)) {
		throw new RuntimeException('Task already in progress');
	}

	if ($action === 'lab_check') {
		$enqueue = enqueueLabCheckTask($db, $viewer, $labId);
		return [
			'original_task' => $task,
			'enqueue' => $enqueue,
		];
	}

	if ($labId === '' || $nodeId === '' || !isLabTaskPowerAction($action)) {
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

function kickLabTaskWorkerAsync(string $mode = 'any'): void
{
    $euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
    if ($euid !== 0) {
        // In production v2, lab_tasks should be handled by a dedicated root worker service.
        return;
    }

    $script = dirname(__DIR__) . '/bin/run_lab_tasks_once.php';
    if (!is_file($script)) {
        return;
    }
    $mode = normalizeLabTaskWorkerMode($mode);
    $php = v2LabTaskWorkerPhpBinary();
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --mode=' . escapeshellarg($mode) . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

function kickLabTaskWorkersAsync(int $count, string $mode = 'any'): void
{
    $n = max(1, min(32, $count));
    for ($i = 0; $i < $n; $i++) {
        kickLabTaskWorkerAsync($mode);
    }
}

function getLabTaskWorkersStatus(PDO $db): array
{
    $settings = getTaskQueueSettings($db);
    $workerStartSlots = max(1, (int) ($settings['start_worker_slots'] ?? ($settings['worker_slots'] ?? taskQueueParallelLimitDefault())));
    $workerStopSlots = max(1, (int) ($settings['stop_worker_slots'] ?? ($settings['worker_slots'] ?? taskQueueParallelLimitDefault())));
    $workerCheckSlots = max(1, (int) ($settings['check_worker_slots'] ?? ($settings['worker_slots'] ?? taskQueueParallelLimitDefault())));
    $parallelLimit = max(1, (int) ($settings['power_parallel_limit'] ?? taskQueueParallelLimitDefault()));

    $countsStmt = $db->prepare(
        "SELECT status, COUNT(*) AS total
         FROM lab_tasks
         WHERE status IN ('pending', 'running')
         GROUP BY status"
    );
    $countsStmt->execute();
    $countsRows = $countsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($countsRows)) {
        $countsRows = [];
    }
    $pending = 0;
    $running = 0;
    foreach ($countsRows as $row) {
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $total = (int) ($row['total'] ?? 0);
        if ($status === 'pending') {
            $pending = $total;
        } elseif ($status === 'running') {
            $running = $total;
        }
    }

    $activeSlotsStmt = $db->prepare(
        "SELECT DISTINCT
                CASE
                    WHEN COALESCE(payload->'worker'->>'slot', '') ~ '^[0-9]+$'
                        THEN (payload->'worker'->>'slot')::int
                    ELSE NULL
                END AS slot,
                NULLIF(COALESCE(payload->'worker'->>'id', ''), '') AS worker_id
         FROM lab_tasks
         WHERE status = 'running'"
    );
    $activeSlotsStmt->execute();
    $slotRows = $activeSlotsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($slotRows)) {
        $slotRows = [];
    }
    $activeSlots = [];
    $activeWorkerIds = [];
    foreach ($slotRows as $row) {
        $slot = isset($row['slot']) ? (int) $row['slot'] : 0;
        if ($slot > 0) {
            $activeSlots[] = $slot;
        }
        $wid = trim((string) ($row['worker_id'] ?? ''));
        if ($wid !== '') {
            $activeWorkerIds[] = $wid;
        }
    }
    $activeSlots = array_values(array_unique($activeSlots));
    sort($activeSlots, SORT_NUMERIC);
    $activeWorkerIds = array_values(array_unique($activeWorkerIds));
    sort($activeWorkerIds, SORT_NATURAL);

    return [
        'worker_slots' => $workerStartSlots + $workerStopSlots + $workerCheckSlots,
        'start_worker_slots' => $workerStartSlots,
        'stop_worker_slots' => $workerStopSlots,
        'check_worker_slots' => $workerCheckSlots,
        'power_parallel_limit' => $parallelLimit,
        'pending_tasks' => $pending,
        'running_tasks' => $running,
        'active_worker_slots' => $activeSlots,
        'active_worker_ids' => $activeWorkerIds,
    ];
}

function restartLabTaskWorkers(PDO $db): array
{
    $statusBefore = getLabTaskWorkersStatus($db);
    $startTriggered = max(1, (int) ($statusBefore['start_worker_slots'] ?? 1));
    $stopTriggered = max(1, (int) ($statusBefore['stop_worker_slots'] ?? 1));
    $checkTriggered = max(1, (int) ($statusBefore['check_worker_slots'] ?? 1));
    kickLabTaskWorkersAsync($startTriggered, 'start');
    kickLabTaskWorkersAsync($stopTriggered, 'stop');
    kickLabTaskWorkersAsync($checkTriggered, 'check');
    $statusAfter = getLabTaskWorkersStatus($db);
    return [
        'triggered' => $startTriggered + $stopTriggered + $checkTriggered,
        'triggered_start' => $startTriggered,
        'triggered_stop' => $stopTriggered,
        'triggered_check' => $checkTriggered,
        'before' => $statusBefore,
        'after' => $statusAfter,
    ];
}

function labTaskSemaphoreKeyClass(): int
{
    return 240214;
}

function labTaskStopSemaphoreKeyClass(): int
{
    return 240216;
}

function labTaskStartSemaphoreKeyClass(): int
{
    return 240215;
}

function labTaskCheckSemaphoreKeyClass(): int
{
    return 240217;
}

function taskQueueParallelLimitDefault(): int
{
    $raw = getenv('EVE_TASK_PARALLEL_LIMIT');
    if ($raw === false || trim((string) $raw) === '') {
        return 2;
    }
    $n = (int) $raw;
    return max(1, min(32, $n));
}

function taskQueueParallelLimitBounds(): array
{
    return ['min' => 1, 'max' => 32];
}

function taskQueueSettingsHasColumn(PDO $db, string $column): bool
{
    static $cache = [];
    $column = strtolower(trim($column));
    if ($column === '') {
        return false;
    }
    if (array_key_exists($column, $cache)) {
        return (bool) $cache[$column];
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1
             FROM information_schema.columns
             WHERE table_schema = ANY (current_schemas(true))
               AND table_name = 'task_queue_settings'
               AND column_name = :column
             LIMIT 1"
        );
        $stmt->bindValue(':column', $column, PDO::PARAM_STR);
        $stmt->execute();
        $cache[$column] = $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        $cache[$column] = false;
    }
    return (bool) $cache[$column];
}

function getTaskQueueSettings(PDO $db): array
{
    $bounds = taskQueueParallelLimitBounds();
    $fallback = taskQueueParallelLimitDefault();
    $hasCheckWorkerSlots = taskQueueSettingsHasColumn($db, 'check_worker_slots');
    $selectCols = $hasCheckWorkerSlots
        ? 'power_parallel_limit, worker_slots, start_worker_slots, stop_worker_slots, check_worker_slots, fast_stop_vios'
        : 'power_parallel_limit, worker_slots, start_worker_slots, stop_worker_slots, fast_stop_vios';
    try {
        $stmt = $db->prepare(
            "SELECT {$selectCols}
             FROM task_queue_settings
             WHERE id = 1
             LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [
                'power_parallel_limit' => $fallback,
                'worker_slots' => $fallback,
                'start_worker_slots' => $fallback,
                'stop_worker_slots' => $fallback,
                'check_worker_slots' => $fallback,
                'fast_stop_vios' => true,
                'min_limit' => $bounds['min'],
                'max_limit' => $bounds['max'],
            ];
        }
        $limit = (int) ($row['power_parallel_limit'] ?? $fallback);
        $limit = max($bounds['min'], min($bounds['max'], $limit));
        $workerSlots = isset($row['worker_slots']) ? (int) $row['worker_slots'] : $fallback;
        $workerSlots = max($bounds['min'], min($bounds['max'], $workerSlots));
        $startWorkerSlots = isset($row['start_worker_slots']) ? (int) $row['start_worker_slots'] : $workerSlots;
        $stopWorkerSlots = isset($row['stop_worker_slots']) ? (int) $row['stop_worker_slots'] : $workerSlots;
        $checkWorkerSlots = isset($row['check_worker_slots']) ? (int) $row['check_worker_slots'] : $workerSlots;
        $startWorkerSlots = max($bounds['min'], min($bounds['max'], $startWorkerSlots));
        $stopWorkerSlots = max($bounds['min'], min($bounds['max'], $stopWorkerSlots));
        $checkWorkerSlots = max($bounds['min'], min($bounds['max'], $checkWorkerSlots));
        $fastStopVios = !array_key_exists('fast_stop_vios', $row) || (bool) $row['fast_stop_vios'];
        return [
            'power_parallel_limit' => $limit,
            'worker_slots' => $workerSlots,
            'start_worker_slots' => $startWorkerSlots,
            'stop_worker_slots' => $stopWorkerSlots,
            'check_worker_slots' => $checkWorkerSlots,
            'fast_stop_vios' => $fastStopVios,
            'min_limit' => $bounds['min'],
            'max_limit' => $bounds['max'],
        ];
    } catch (Throwable $e) {
        return [
            'power_parallel_limit' => $fallback,
            'worker_slots' => $fallback,
            'start_worker_slots' => $fallback,
            'stop_worker_slots' => $fallback,
            'check_worker_slots' => $fallback,
            'fast_stop_vios' => true,
            'min_limit' => $bounds['min'],
            'max_limit' => $bounds['max'],
        ];
    }
}

function updateTaskQueueSettings(
    PDO $db,
    int $powerParallelLimit,
    ?int $workerSlots = null,
    ?int $startWorkerSlots = null,
    ?int $stopWorkerSlots = null,
    ?int $checkWorkerSlots = null,
    ?bool $fastStopVios = null
): array
{
    $bounds = taskQueueParallelLimitBounds();
    $limit = max($bounds['min'], min($bounds['max'], (int) $powerParallelLimit));
    $existing = getTaskQueueSettings($db);
    $legacySlots = $workerSlots === null
        ? max($bounds['min'], min($bounds['max'], (int) ($existing['worker_slots'] ?? $limit)))
        : max($bounds['min'], min($bounds['max'], (int) $workerSlots));
    $startSlots = $startWorkerSlots === null
        ? max($bounds['min'], min($bounds['max'], (int) ($existing['start_worker_slots'] ?? $legacySlots)))
        : max($bounds['min'], min($bounds['max'], (int) $startWorkerSlots));
    $stopSlots = $stopWorkerSlots === null
        ? max($bounds['min'], min($bounds['max'], (int) ($existing['stop_worker_slots'] ?? $legacySlots)))
        : max($bounds['min'], min($bounds['max'], (int) $stopWorkerSlots));
    $checkSlots = $checkWorkerSlots === null
        ? max($bounds['min'], min($bounds['max'], (int) ($existing['check_worker_slots'] ?? $legacySlots)))
        : max($bounds['min'], min($bounds['max'], (int) $checkWorkerSlots));
    $vios = $fastStopVios === null ? !empty($existing['fast_stop_vios']) : $fastStopVios;
    $hasCheckWorkerSlots = taskQueueSettingsHasColumn($db, 'check_worker_slots');
    if ($hasCheckWorkerSlots) {
        $stmt = $db->prepare(
            "INSERT INTO task_queue_settings (id, power_parallel_limit, worker_slots, start_worker_slots, stop_worker_slots, check_worker_slots, fast_stop_vios, updated_at)
             VALUES (1, :limit, :worker_slots, :start_worker_slots, :stop_worker_slots, :check_worker_slots, :fast_stop_vios, NOW())
             ON CONFLICT (id) DO UPDATE
             SET power_parallel_limit = EXCLUDED.power_parallel_limit,
                 worker_slots = EXCLUDED.worker_slots,
                 start_worker_slots = EXCLUDED.start_worker_slots,
                 stop_worker_slots = EXCLUDED.stop_worker_slots,
                 check_worker_slots = EXCLUDED.check_worker_slots,
                 fast_stop_vios = EXCLUDED.fast_stop_vios,
                 updated_at = NOW()"
        );
    } else {
        $stmt = $db->prepare(
            "INSERT INTO task_queue_settings (id, power_parallel_limit, worker_slots, start_worker_slots, stop_worker_slots, fast_stop_vios, updated_at)
             VALUES (1, :limit, :worker_slots, :start_worker_slots, :stop_worker_slots, :fast_stop_vios, NOW())
             ON CONFLICT (id) DO UPDATE
             SET power_parallel_limit = EXCLUDED.power_parallel_limit,
                 worker_slots = EXCLUDED.worker_slots,
                 start_worker_slots = EXCLUDED.start_worker_slots,
                 stop_worker_slots = EXCLUDED.stop_worker_slots,
                 fast_stop_vios = EXCLUDED.fast_stop_vios,
                 updated_at = NOW()"
        );
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':worker_slots', $hasCheckWorkerSlots ? ($startSlots + $stopSlots + $checkSlots) : ($startSlots + $stopSlots), PDO::PARAM_INT);
    $stmt->bindValue(':start_worker_slots', $startSlots, PDO::PARAM_INT);
    $stmt->bindValue(':stop_worker_slots', $stopSlots, PDO::PARAM_INT);
    if ($hasCheckWorkerSlots) {
        $stmt->bindValue(':check_worker_slots', $checkSlots, PDO::PARAM_INT);
    }
    $stmt->bindValue(':fast_stop_vios', $vios, PDO::PARAM_BOOL);
    $stmt->execute();
    return getTaskQueueSettings($db);
}

function labTaskWorkerSemaphoreKeyClass(string $mode): int
{
    $mode = normalizeLabTaskWorkerMode($mode);
    if ($mode === 'start') {
        return labTaskSemaphoreKeyClass();
    }
    if ($mode === 'stop') {
        return labTaskStopSemaphoreKeyClass();
    }
    if ($mode === 'check') {
        return labTaskCheckSemaphoreKeyClass();
    }
    return labTaskSemaphoreKeyClass();
}

function tryAcquireLabTaskSlot(PDO $db, int $maxParallel, string $mode = 'any'): int
{
    $maxParallel = max(1, $maxParallel);
    $classKey = labTaskWorkerSemaphoreKeyClass($mode);
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

function releaseLabTaskSlot(PDO $db, int $slot, string $mode = 'any'): void
{
    if ($slot < 1) {
        return;
    }
    $stmt = $db->prepare("SELECT pg_advisory_unlock(:class_key, :slot_key)");
    $stmt->bindValue(':class_key', labTaskWorkerSemaphoreKeyClass($mode), PDO::PARAM_INT);
    $stmt->bindValue(':slot_key', $slot, PDO::PARAM_INT);
    $stmt->execute();
}

function tryAcquireLabTaskStartSlot(PDO $db, int $maxParallel): int
{
    $maxParallel = max(1, $maxParallel);
    $classKey = labTaskStartSemaphoreKeyClass();
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

function releaseLabTaskStartSlot(PDO $db, int $slot): void
{
    if ($slot < 1) {
        return;
    }
    $stmt = $db->prepare("SELECT pg_advisory_unlock(:class_key, :slot_key)");
    $stmt->bindValue(':class_key', labTaskStartSemaphoreKeyClass(), PDO::PARAM_INT);
    $stmt->bindValue(':slot_key', $slot, PDO::PARAM_INT);
    $stmt->execute();
}

function buildLabTaskWorkerMeta(int $slot, string $mode = 'any'): array
{
    $slot = max(1, $slot);
    $mode = normalizeLabTaskWorkerMode($mode);
    $pid = function_exists('getmypid') ? (int) getmypid() : 0;
    $host = trim((string) @php_uname('n'));
    if ($host === '') {
        $host = 'localhost';
    }
    $id = ($mode === 'start'
        ? 'start-worker-'
        : ($mode === 'stop'
            ? 'stop-worker-'
            : ($mode === 'check' ? 'check-worker-' : 'worker-'))) . $slot;
    return [
        'id' => $id,
        'name' => $id . '@' . $host . ($pid > 0 ? ('#' . $pid) : ''),
        'mode' => $mode,
        'slot' => $slot,
        'pid' => $pid > 0 ? $pid : null,
        'host' => $host,
    ];
}

function claimNextPendingLabTask(
    PDO $db,
    array $workerMeta = [],
    int $userParallelLimit = 2,
    bool $allowStart = true,
    bool|int $allowStop = true,
    int $maxWorkerSlots = 0,
    bool $allowLabCheck = true
): ?array
{
    // Backward compatibility for older call signature where arg#5 was maxWorkerSlots.
    if (is_int($allowStop) && $maxWorkerSlots < 1) {
        $maxWorkerSlots = $allowStop;
        $allowStop = true;
    }
    $allowStop = (bool) $allowStop;

    $workerJson = json_encode($workerMeta, JSON_UNESCAPED_UNICODE);
    if (!is_string($workerJson) || trim($workerJson) === '') {
        $workerJson = '{}';
    }
    $workerSlot = max(1, (int) ($workerMeta['slot'] ?? 0));
    $maxWorkerSlots = max(1, $maxWorkerSlots > 0 ? $maxWorkerSlots : $workerSlot);

    $db->beginTransaction();
    try {
        $pickStmt = $db->prepare(
            "SELECT id, action, payload
             FROM lab_tasks t
             WHERE t.status = 'pending'
               AND (
                   (t.action = 'stop' AND CAST(:allow_stop AS integer) = 1)
                   OR (t.action = 'lab_check' AND CAST(:allow_lab_check AS integer) = 1)
                   OR (
                       t.action = 'start'
                       AND CAST(:allow_start AS integer) = 1
                       AND
                       (
                           (
                               CASE
                                   WHEN COALESCE(t.payload->>'bulk_owner_slot', '') ~ '^[0-9]+$'
                                       THEN (t.payload->>'bulk_owner_slot')::int
                                   ELSE 0
                               END
                           ) IN (0, CAST(:worker_slot AS integer))
                           OR
                           (
                               CASE
                                   WHEN COALESCE(t.payload->>'bulk_owner_slot', '') ~ '^[0-9]+$'
                                       THEN (t.payload->>'bulk_owner_slot')::int
                                   ELSE 0
                               END
                           ) > CAST(:max_worker_slots AS integer)
                       )
                   )
               )
             ORDER BY CASE WHEN t.action = 'stop' THEN 0 WHEN t.action = 'lab_check' THEN 1 ELSE 2 END, t.created_at ASC
             FOR UPDATE SKIP LOCKED
             LIMIT 1"
        );
        $pickStmt->bindValue(':allow_stop', $allowStop ? 1 : 0, PDO::PARAM_INT);
        $pickStmt->bindValue(':allow_start', $allowStart ? 1 : 0, PDO::PARAM_INT);
        $pickStmt->bindValue(':allow_lab_check', $allowLabCheck ? 1 : 0, PDO::PARAM_INT);
        $pickStmt->bindValue(':worker_slot', $workerSlot, PDO::PARAM_INT);
        $pickStmt->bindValue(':max_worker_slots', $maxWorkerSlots, PDO::PARAM_INT);
        $pickStmt->execute();
        $pickedRow = $pickStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($pickedRow) || empty($pickedRow['id'])) {
            $db->commit();
            return null;
        }
        $taskId = (string) $pickedRow['id'];
        $taskAction = strtolower(trim((string) ($pickedRow['action'] ?? '')));
        $taskPayload = decodeJsonOrNull($pickedRow['payload'] ?? null);
        if (!is_array($taskPayload)) {
            $taskPayload = [];
        }
        $bulkOperationId = trim((string) ($taskPayload['bulk_operation_id'] ?? ''));

        // Pin grouped start tasks to one worker slot to prevent cross-worker splitting.
        if ($taskAction === 'start' && $bulkOperationId !== '') {
            $ownStmt = $db->prepare(
                "UPDATE lab_tasks
                 SET payload = COALESCE(payload, '{}'::jsonb)
                               || jsonb_build_object('bulk_owner_slot', CAST(:worker_slot AS integer))
                 WHERE status = 'pending'
                   AND action = 'start'
                   AND payload->>'bulk_operation_id' = :bulk_operation_id
                   AND (
                       (
                           CASE
                               WHEN COALESCE(payload->>'bulk_owner_slot', '') ~ '^[0-9]+$'
                                   THEN (payload->>'bulk_owner_slot')::int
                               ELSE 0
                           END
                       ) = 0
                       OR
                       (
                           CASE
                               WHEN COALESCE(payload->>'bulk_owner_slot', '') ~ '^[0-9]+$'
                                   THEN (payload->>'bulk_owner_slot')::int
                               ELSE 0
                           END
                       ) > CAST(:max_worker_slots AS integer)
                   )"
            );
            $ownStmt->bindValue(':worker_slot', $workerSlot, PDO::PARAM_INT);
            $ownStmt->bindValue(':max_worker_slots', $maxWorkerSlots, PDO::PARAM_INT);
            $ownStmt->bindValue(':bulk_operation_id', $bulkOperationId, PDO::PARAM_STR);
            $ownStmt->execute();
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

function labTaskCpuMaxPercent(): float
{
    $raw = getenv('EVE_TASK_CPU_MAX_PERCENT');
    if ($raw === false || trim((string) $raw) === '') {
        return 80.0;
    }
    $value = (float) $raw;
    if (!is_finite($value)) {
        return 80.0;
    }
    if ($value < 1.0) {
        $value = 1.0;
    } elseif ($value > 100.0) {
        $value = 100.0;
    }
    return $value;
}

function labTaskCpuUsagePercent(): ?float
{
    if (function_exists('systemCpuUsagePercent')) {
        $usage = systemCpuUsagePercent();
        return is_numeric($usage) ? (float) $usage : null;
    }

    $readSnapshot = static function (): ?array {
        $raw = @file_get_contents('/proc/stat');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $line = trim((string) strtok($raw, "\n"));
        if (!preg_match('/^cpu\s+(.+)$/', $line, $m)) {
            return null;
        }
        $parts = preg_split('/\s+/', trim((string) $m[1]));
        if (!is_array($parts) || count($parts) < 4) {
            return null;
        }
        $values = array_map(static function ($v): float {
            return is_numeric($v) ? (float) $v : 0.0;
        }, $parts);
        $idle = ($values[3] ?? 0.0) + ($values[4] ?? 0.0);
        $total = array_sum($values);
        return ['total' => $total, 'idle' => $idle];
    };

    $s1 = $readSnapshot();
    if (!is_array($s1)) {
        return null;
    }
    usleep(120000);
    $s2 = $readSnapshot();
    if (!is_array($s2)) {
        return null;
    }
    $deltaTotal = (float) $s2['total'] - (float) $s1['total'];
    $deltaIdle = (float) $s2['idle'] - (float) $s1['idle'];
    if ($deltaTotal <= 0.0) {
        return null;
    }
    $usage = (($deltaTotal - $deltaIdle) / $deltaTotal) * 100.0;
    if ($usage < 0.0) {
        $usage = 0.0;
    } elseif ($usage > 100.0) {
        $usage = 100.0;
    }
    return round($usage, 2);
}

function hasPendingStartLabTasks(PDO $db): bool
{
    $stmt = $db->prepare(
        "SELECT 1
         FROM lab_tasks
         WHERE status = 'pending'
           AND action = 'start'
         LIMIT 1"
    );
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function labTaskStartMinIntervalMs(): int
{
    $raw = getenv('EVE_TASK_START_MIN_INTERVAL_MS');
    if ($raw === false || trim((string) $raw) === '') {
        return 400;
    }
    $value = (int) $raw;
    if ($value < 0) {
        $value = 0;
    } elseif ($value > 10000) {
        $value = 10000;
    }
    return $value;
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
           AND status = 'running'
         RETURNING payload"
    );
    $stmt->bindValue(':result_data', $json, PDO::PARAM_STR);
    $stmt->bindValue(':id', $taskId, PDO::PARAM_STR);
    $stmt->execute();
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($updated)) {
        v2AppLogWrite('task_worker', 'ERROR', [
            'event' => 'task_done_skipped',
            'task_id' => $taskId,
        ]);
        return false;
    }
    $payload = decodeJsonOrNull($updated['payload'] ?? null);
    $groupId = is_array($payload) ? trim((string) ($payload['bulk_operation_id'] ?? '')) : '';
    if ($groupId !== '') {
        syncLabTaskGroupProgress($db, $groupId);
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
           AND status = 'running'
         RETURNING payload"
    );
    $stmt->bindValue(':result_data', $json, PDO::PARAM_STR);
    $stmt->bindValue(':error_text', $trimmedError, PDO::PARAM_STR);
    $stmt->bindValue(':id', $taskId, PDO::PARAM_STR);
    $stmt->execute();
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($updated)) {
        v2AppLogWrite('task_worker', 'ERROR', [
            'event' => 'task_failed_skipped',
            'task_id' => $taskId,
            'error' => $trimmedError,
        ]);
        return false;
    }
    $payload = decodeJsonOrNull($updated['payload'] ?? null);
    $groupId = is_array($payload) ? trim((string) ($payload['bulk_operation_id'] ?? '')) : '';
    if ($groupId !== '') {
        syncLabTaskGroupProgress($db, $groupId);
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

    $preferFastStop = ($action === 'stop');
    $runtimeResult = ($action === 'start')
        ? startLabNodeRuntime($db, $labId, $nodeId)
        : stopLabNodeRuntime($db, $labId, $nodeId, $preferFastStop);

    return [
        'action' => $action,
        'applied' => true,
        'runtime' => $runtimeResult,
        'node' => getLabNodePowerSnapshot($db, $labId, $nodeId),
    ];
}

function updateLabCheckTaskProgress(PDO $db, string $taskId, int $totalItems, int $doneItems, string $runId = ''): void
{
    $taskId = trim($taskId);
    if ($taskId === '') {
        return;
    }
    $total = max(0, $totalItems);
    $done = max(0, $doneItems);
    if ($total > 0 && $done > $total) {
        $done = $total;
    }
    $percent = $total > 0 ? (int) round(($done / $total) * 100.0) : 0;
    if ($percent < 0) {
        $percent = 0;
    } elseif ($percent > 100) {
        $percent = 100;
    }
    $patch = [
        'checks_total' => $total,
        'checks_done' => $done,
        'checks_percent' => $percent,
    ];
    $runId = trim($runId);
    if ($runId !== '') {
        $patch['checks_run_id'] = $runId;
    }
    $patchJson = json_encode($patch, JSON_UNESCAPED_UNICODE);
    if (!is_string($patchJson) || trim($patchJson) === '') {
        return;
    }
    $stmt = $db->prepare(
        "UPDATE lab_tasks
         SET payload = COALESCE(payload, '{}'::jsonb) || CAST(:payload_patch AS jsonb),
             updated_at = NOW()
         WHERE id = :id
           AND status = 'running'"
    );
    $stmt->bindValue(':payload_patch', $patchJson, PDO::PARAM_STR);
    $stmt->bindValue(':id', $taskId, PDO::PARAM_STR);
    $stmt->execute();
}

function applyLabCheckTaskAction(PDO $db, array $task): array
{
    $taskId = (string) ($task['id'] ?? '');
    $labId = (string) ($task['lab_id'] ?? '');
    if ($taskId === '' || $labId === '') {
        throw new RuntimeException('Task payload is invalid');
    }

    $viewerId = trim((string) ($task['requested_by_user_id'] ?? ''));
    if ($viewerId === '') {
        throw new RuntimeException('Task requester is missing');
    }
    $viewerUsername = trim((string) ($task['requested_by'] ?? ''));
    $payload = is_array($task['payload'] ?? null) ? (array) $task['payload'] : decodeJsonOrNull($task['payload'] ?? null);
    if (!is_array($payload)) {
        $payload = [];
    }
    $viewerRole = strtolower(trim((string) ($payload['requested_by_role'] ?? '')));
    if ($viewerRole === '') {
        $viewerRole = 'user';
    }
    $viewer = [
        'id' => $viewerId,
        'username' => $viewerUsername,
        'role_name' => $viewerRole,
    ];

    $runDetail = labCheckRunForViewer($db, $viewer, $labId, static function (array $progress) use ($db, $taskId): void {
        $totalItems = isset($progress['total_items']) ? (int) $progress['total_items'] : 0;
        $doneItems = isset($progress['completed_items']) ? (int) $progress['completed_items'] : 0;
        $runId = trim((string) ($progress['run_id'] ?? ''));
        updateLabCheckTaskProgress($db, $taskId, $totalItems, $doneItems, $runId);
    });
    $run = is_array($runDetail['run'] ?? null) ? (array) $runDetail['run'] : [];

    $finalTotal = isset($run['total_items']) ? (int) $run['total_items'] : 0;
    $finalDone = (int) ($run['passed_items'] ?? 0) + (int) ($run['failed_items'] ?? 0) + (int) ($run['error_items'] ?? 0);
    updateLabCheckTaskProgress($db, $taskId, $finalTotal, $finalDone, (string) ($run['id'] ?? ''));

    return [
        'action' => 'lab_check',
        'applied' => true,
        'run' => [
            'id' => (string) ($run['id'] ?? ''),
            'status' => (string) ($run['status'] ?? ''),
            'total_items' => isset($run['total_items']) ? (int) $run['total_items'] : 0,
            'passed_items' => isset($run['passed_items']) ? (int) $run['passed_items'] : 0,
            'failed_items' => isset($run['failed_items']) ? (int) $run['failed_items'] : 0,
            'error_items' => isset($run['error_items']) ? (int) $run['error_items'] : 0,
            'total_points' => isset($run['total_points']) ? (int) $run['total_points'] : 0,
            'earned_points' => isset($run['earned_points']) ? (int) $run['earned_points'] : 0,
            'score_percent' => isset($run['score_percent']) ? (float) $run['score_percent'] : 0.0,
            'grade_label' => isset($run['grade_label']) && $run['grade_label'] !== null ? (string) $run['grade_label'] : null,
            'started_at' => isset($run['started_at']) ? (string) $run['started_at'] : null,
            'finished_at' => isset($run['finished_at']) ? (string) $run['finished_at'] : null,
            'duration_ms' => isset($run['duration_ms']) ? (int) $run['duration_ms'] : 0,
        ],
    ];
}

function applyLabTaskAction(PDO $db, array $task): array
{
    $action = normalizeLabTaskAction((string) ($task['action'] ?? ''));
    if ($action === 'lab_check') {
        return applyLabCheckTaskAction($db, $task);
    }
    return applyNodePowerAction($db, $task);
}

function runLabTaskWorker(PDO $db, int $maxParallel = 0, int $maxTasksPerRun = 2000, string $mode = 'any'): int
{
    $mode = normalizeLabTaskWorkerMode($mode);
    $settings = null;
    if ($maxParallel < 1) {
        $settings = getTaskQueueSettings($db);
        if ($mode === 'start') {
            $maxParallel = max(1, (int) ($settings['start_worker_slots'] ?? ($settings['worker_slots'] ?? taskQueueParallelLimitDefault())));
        } elseif ($mode === 'stop') {
            $maxParallel = max(1, (int) ($settings['stop_worker_slots'] ?? ($settings['worker_slots'] ?? taskQueueParallelLimitDefault())));
        } elseif ($mode === 'check') {
            $maxParallel = max(1, (int) ($settings['check_worker_slots'] ?? ($settings['worker_slots'] ?? taskQueueParallelLimitDefault())));
        } else {
            $maxParallel = max(1, (int) ($settings['worker_slots'] ?? taskQueueParallelLimitDefault()));
        }
    }
    $slot = tryAcquireLabTaskSlot($db, $maxParallel, $mode);
    if ($slot < 1) {
        v2AppLogWrite('task_worker', 'ERROR', [
            'event' => 'worker_slot_busy',
            'max_parallel' => max(1, $maxParallel),
            'mode' => $mode,
        ]);
        return 0;
    }

    $workerMeta = buildLabTaskWorkerMeta($slot, $mode);
    v2AppLogWrite('task_worker', 'OK', [
        'event' => 'worker_started',
        'slot' => $slot,
        'worker' => $workerMeta,
        'mode' => $mode,
        'max_parallel' => max(1, $maxParallel),
        'max_tasks_per_run' => max(1, $maxTasksPerRun),
    ]);

    $processed = 0;
    $idleThrottleLoops = 0;
    if (!is_array($settings)) {
        $settings = getTaskQueueSettings($db);
    }
    $userParallelLimit = max(1, (int) ($settings['power_parallel_limit'] ?? $maxParallel));
    $cpuLimit = labTaskCpuMaxPercent();
    $startMinIntervalMs = labTaskStartMinIntervalMs();
    $lastStartCompletedAt = 0.0;
    try {
        while ($processed < max(1, $maxTasksPerRun)) {
            $cpuUsage = labTaskCpuUsagePercent();
            $cpuHigh = is_numeric($cpuUsage) && ((float) $cpuUsage >= $cpuLimit);
            $allowStart = ($mode === 'start' || $mode === 'any') && !$cpuHigh;
            $allowStop = ($mode === 'stop' || $mode === 'any');
            $allowLabCheck = ($mode === 'check' || $mode === 'any');
            $task = claimNextPendingLabTask($db, $workerMeta, $userParallelLimit, $allowStart, $allowStop, $maxParallel, $allowLabCheck);
            if ($task === null) {
                if (($mode === 'start' || $mode === 'any') && $cpuHigh && hasPendingStartLabTasks($db)) {
                    if (($idleThrottleLoops % 12) === 0) {
                        v2AppLogWrite('task_worker', 'OK', [
                            'event' => 'cpu_throttle_wait',
                            'slot' => $slot,
                            'mode' => $mode,
                            'cpu_usage_percent' => $cpuUsage,
                            'cpu_limit_percent' => $cpuLimit,
                        ]);
                    }
                    $idleThrottleLoops++;
                    usleep(500000);
                    continue;
                }
                break;
            }
            $idleThrottleLoops = 0;
            $taskId = (string) ($task['id'] ?? '');
            $labId = (string) ($task['lab_id'] ?? '');
            $nodeId = (string) ($task['node_id'] ?? '');
            $taskAction = normalizeLabTaskAction((string) ($task['action'] ?? ''));
            $startSemaphoreSlot = 0;
            try {
                if ($taskAction === 'start') {
                    while ($startSemaphoreSlot < 1) {
                        $startSemaphoreSlot = tryAcquireLabTaskStartSlot($db, $userParallelLimit);
                        if ($startSemaphoreSlot < 1) {
                            usleep(200000);
                        }
                    }
                }
                $result = applyLabTaskAction($db, $task);
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
                if ($failedMarked && $labId !== '' && $nodeId !== '' && isLabTaskPowerAction($taskAction)) {
                    setNodePowerError($db, $labId, $nodeId, $e->getMessage());
                }
            } finally {
                if ($startSemaphoreSlot > 0) {
                    releaseLabTaskStartSlot($db, $startSemaphoreSlot);
                }
            }
            if ($taskAction === 'start' && $startMinIntervalMs > 0) {
                $now = microtime(true);
                $elapsedMs = (int) round(($now - $lastStartCompletedAt) * 1000.0);
                if ($lastStartCompletedAt > 0.0 && $elapsedMs < $startMinIntervalMs) {
                    usleep(($startMinIntervalMs - $elapsedMs) * 1000);
                }
                $lastStartCompletedAt = microtime(true);
            }
            $processed++;
        }
    } finally {
        v2AppLogWrite('task_worker', 'OK', [
            'event' => 'worker_finished',
            'slot' => $slot,
            'mode' => $mode,
            'worker' => $workerMeta,
            'processed' => $processed,
        ]);
        releaseLabTaskSlot($db, $slot, $mode);
    }

    return $processed;
}
