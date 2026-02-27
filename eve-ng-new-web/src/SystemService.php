<?php

declare(strict_types=1);

function readProcText(string $path): string
{
    $raw = @file_get_contents($path);
    return is_string($raw) ? trim($raw) : '';
}

function systemParseMemInfo(): array
{
    $text = readProcText('/proc/meminfo');
    if ($text === '') {
        return [
            'total_mb' => null,
            'available_mb' => null,
            'used_mb' => null,
            'used_percent' => null,
        ];
    }

    $values = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        if (!preg_match('/^([A-Za-z_]+):\s+([0-9]+)\s+kB$/', trim($line), $m)) {
            continue;
        }
        $values[$m[1]] = (int) $m[2];
    }

    $totalKb = isset($values['MemTotal']) ? (int) $values['MemTotal'] : 0;
    $availableKb = isset($values['MemAvailable']) ? (int) $values['MemAvailable'] : 0;
    if ($totalKb <= 0) {
        return [
            'total_mb' => null,
            'available_mb' => null,
            'used_mb' => null,
            'used_percent' => null,
        ];
    }
    if ($availableKb <= 0 && isset($values['MemFree'])) {
        $availableKb = (int) $values['MemFree'];
    }
    $usedKb = max(0, $totalKb - $availableKb);
    $usedPercent = ($totalKb > 0) ? round(($usedKb * 100.0) / $totalKb, 2) : null;

    return [
        'total_mb' => round($totalKb / 1024, 2),
        'available_mb' => round($availableKb / 1024, 2),
        'used_mb' => round($usedKb / 1024, 2),
        'used_percent' => $usedPercent,
    ];
}

function systemUptimeSeconds(): ?int
{
    $text = readProcText('/proc/uptime');
    if ($text === '') {
        return null;
    }
    $parts = preg_split('/\s+/', $text);
    $first = isset($parts[0]) ? (float) $parts[0] : 0.0;
    if ($first <= 0) {
        return null;
    }
    return (int) floor($first);
}

function systemLoadAvg(): array
{
    $la = @sys_getloadavg();
    if (!is_array($la) || count($la) < 3) {
        return [
            'load_1m' => null,
            'load_5m' => null,
            'load_15m' => null,
        ];
    }
    return [
        'load_1m' => round((float) $la[0], 2),
        'load_5m' => round((float) $la[1], 2),
        'load_15m' => round((float) $la[2], 2),
    ];
}

function systemDiskStats(string $path): array
{
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if (!is_numeric($total) || !is_numeric($free) || (float) $total <= 0) {
        return [
            'path' => $path,
            'total_gb' => null,
            'free_gb' => null,
            'used_gb' => null,
            'used_percent' => null,
        ];
    }
    $totalF = (float) $total;
    $freeF = (float) $free;
    $usedF = max(0.0, $totalF - $freeF);
    $usedPercent = ($totalF > 0) ? round(($usedF * 100.0) / $totalF, 2) : null;

    return [
        'path' => $path,
        'total_gb' => round($totalF / 1073741824, 2),
        'free_gb' => round($freeF / 1073741824, 2),
        'used_gb' => round($usedF / 1073741824, 2),
        'used_percent' => $usedPercent,
    ];
}

function systemCountProcessComm(array $targetNames): int
{
    $target = [];
    foreach ($targetNames as $name) {
        $v = strtolower(trim((string) $name));
        if ($v !== '') {
            $target[$v] = true;
        }
    }
    if (empty($target)) {
        return 0;
    }

    $count = 0;
    foreach (glob('/proc/[0-9]*/comm') ?: [] as $commPath) {
        $name = strtolower(trim((string) @file_get_contents($commPath)));
        if ($name === '') {
            continue;
        }
        if (isset($target[$name])) {
            $count++;
        }
    }
    return $count;
}

function systemViewerSqlScope(array $viewer, string $labAlias = 'l'): array
{
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        return ['1=0', []];
    }
    if (viewerIsAdmin($viewer)) {
        return ['1=1', []];
    }
    $sql = '(' . $labAlias . ".author_user_id = :viewer_id
        OR EXISTS (
            SELECT 1
            FROM lab_shared_users su
            WHERE su.lab_id = " . $labAlias . ".id
              AND su.user_id = :viewer_id
        ))";
    return [$sql, [':viewer_id' => $viewerId]];
}

function systemFetchAppStats(PDO $db, array $viewer): array
{
    [$scopeSql, $scopeParams] = systemViewerSqlScope($viewer, 'l');

    $labsStmt = $db->prepare("SELECT COUNT(*) FROM labs l WHERE " . $scopeSql);
    foreach ($scopeParams as $k => $v) {
        $labsStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $labsStmt->execute();
    $labsVisible = (int) $labsStmt->fetchColumn();

    $nodesStmt = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE n.power_state = 'running') AS running,
            COUNT(*) FILTER (WHERE n.power_state = 'starting') AS starting,
            COUNT(*) FILTER (WHERE n.power_state = 'stopping') AS stopping,
            COUNT(*) FILTER (WHERE n.power_state = 'error') AS error
         FROM lab_nodes n
         JOIN labs l ON l.id = n.lab_id
         WHERE " . $scopeSql
    );
    foreach ($scopeParams as $k => $v) {
        $nodesStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $nodesStmt->execute();
    $nodes = $nodesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $tasksStmt = $db->prepare(
        "SELECT
            COUNT(*) FILTER (WHERE t.status = 'pending') AS pending,
            COUNT(*) FILTER (WHERE t.status = 'running') AS running,
            COUNT(*) FILTER (WHERE t.status = 'done' AND t.created_at >= NOW() - INTERVAL '24 hours') AS done_24h,
            COUNT(*) FILTER (WHERE t.status = 'failed' AND t.created_at >= NOW() - INTERVAL '24 hours') AS failed_24h
         FROM lab_tasks t
         JOIN labs l ON l.id = t.lab_id
         WHERE " . $scopeSql
    );
    foreach ($scopeParams as $k => $v) {
        $tasksStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $tasksStmt->execute();
    $tasks = $tasksStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $isAdmin = viewerIsAdmin($viewer);
    $viewerId = (string) ($viewer['id'] ?? '');

    if ($isAdmin) {
        $userStmt = $db->query(
            "SELECT
                COUNT(*) AS users_total,
                COUNT(*) FILTER (WHERE is_blocked = TRUE) AS users_blocked
             FROM users"
        );
        $users = $userStmt ? ($userStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $sessionsStmt = $db->query(
            "SELECT
                COUNT(*) FILTER (WHERE ended_at IS NULL AND expires_at >= NOW()) AS sessions_alive,
                COUNT(*) FILTER (
                    WHERE ended_at IS NULL
                      AND expires_at >= NOW()
                      AND last_activity >= NOW() - INTERVAL '90 seconds'
                ) AS sessions_active_now,
                COUNT(DISTINCT user_id) FILTER (
                    WHERE ended_at IS NULL
                      AND expires_at >= NOW()
                      AND last_activity >= NOW() - INTERVAL '90 seconds'
                ) AS users_online
             FROM auth_sessions"
        );
        $sessions = $sessionsStmt ? ($sessionsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    } else {
        $users = [
            'users_total' => null,
            'users_blocked' => null,
        ];
        $sessionsStmt = $db->prepare(
            "SELECT
                COUNT(*) FILTER (WHERE ended_at IS NULL AND expires_at >= NOW()) AS sessions_alive,
                COUNT(*) FILTER (
                    WHERE ended_at IS NULL
                      AND expires_at >= NOW()
                      AND last_activity >= NOW() - INTERVAL '90 seconds'
                ) AS sessions_active_now
             FROM auth_sessions
             WHERE user_id = :viewer_id"
        );
        $sessionsStmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
        $sessionsStmt->execute();
        $sessions = $sessionsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $sessions['users_online'] = null;
    }

    return [
        'users_total' => isset($users['users_total']) ? (is_null($users['users_total']) ? null : (int) $users['users_total']) : null,
        'users_blocked' => isset($users['users_blocked']) ? (is_null($users['users_blocked']) ? null : (int) $users['users_blocked']) : null,
        'users_online' => isset($sessions['users_online']) ? (is_null($sessions['users_online']) ? null : (int) $sessions['users_online']) : null,
        'sessions_alive' => isset($sessions['sessions_alive']) ? (int) $sessions['sessions_alive'] : 0,
        'sessions_active_now' => isset($sessions['sessions_active_now']) ? (int) $sessions['sessions_active_now'] : 0,
        'labs_visible' => $labsVisible,
        'nodes_total' => isset($nodes['total']) ? (int) $nodes['total'] : 0,
        'nodes_running' => isset($nodes['running']) ? (int) $nodes['running'] : 0,
        'nodes_starting' => isset($nodes['starting']) ? (int) $nodes['starting'] : 0,
        'nodes_stopping' => isset($nodes['stopping']) ? (int) $nodes['stopping'] : 0,
        'nodes_error' => isset($nodes['error']) ? (int) $nodes['error'] : 0,
        'tasks_pending' => isset($tasks['pending']) ? (int) $tasks['pending'] : 0,
        'tasks_running' => isset($tasks['running']) ? (int) $tasks['running'] : 0,
        'tasks_done_24h' => isset($tasks['done_24h']) ? (int) $tasks['done_24h'] : 0,
        'tasks_failed_24h' => isset($tasks['failed_24h']) ? (int) $tasks['failed_24h'] : 0,
    ];
}

function getSystemStatusForViewer(PDO $db, array $viewer): array
{
    $startTs = microtime(true);
    $load = systemLoadAvg();
    $memory = systemParseMemInfo();
    $uptime = systemUptimeSeconds();
    $diskRoot = systemDiskStats('/');
    $diskUnetlab = systemDiskStats('/opt/unetlab');
    $appStats = systemFetchAppStats($db, $viewer);
    $dbQueryMs = round((microtime(true) - $startTs) * 1000, 2);

    $apacheCount = systemCountProcessComm(['apache2']);
    $postgresCount = systemCountProcessComm(['postgres']);

    return [
        'server' => [
            'hostname' => php_uname('n'),
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'php_version' => PHP_VERSION,
            'time_local' => date('c'),
            'time_utc' => gmdate('c'),
            'uptime_seconds' => $uptime,
            'load' => $load,
            'memory' => $memory,
            'disks' => [$diskRoot, $diskUnetlab],
        ],
        'services' => [
            'apache2' => [
                'running' => $apacheCount > 0,
                'process_count' => $apacheCount,
            ],
            'postgresql' => [
                'running' => $postgresCount > 0,
                'process_count' => $postgresCount,
            ],
        ],
        'app' => $appStats,
        'meta' => [
            'db_query_ms' => $dbQueryMs,
            'viewer_role' => (string) ($viewer['role_name'] ?? $viewer['role'] ?? ''),
        ],
    ];
}
