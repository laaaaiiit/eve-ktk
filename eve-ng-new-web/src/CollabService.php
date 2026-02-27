<?php

declare(strict_types=1);

function v2CollabDataRoot(): string
{
    return '/opt/unetlab/data/v2-collab';
}

function v2CollabNowIso(): string
{
    return gmdate('c');
}

function v2CollabPhpBinary(): string
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

function v2CollabWebsockifyBinary(): string
{
    $candidates = ['/usr/bin/websockify', '/usr/local/bin/websockify', 'websockify'];
    foreach ($candidates as $candidate) {
        if ($candidate === 'websockify') {
            return $candidate;
        }
        if (@is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'websockify';
}

function v2CollabEnsureRoots(): void
{
    $root = v2CollabDataRoot();
    if (!is_dir($root)) {
        if (!@mkdir($root, 0770, true) && !is_dir($root)) {
            throw new RuntimeException('Failed to create collaboration storage directory');
        }
    }
    @chmod($root, 0770);
}

function v2CollabEnsureWritableFile(string $path): void
{
    if ($path === '') {
        return;
    }
    $dir = dirname($path);
    if ($dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (!file_exists($path)) {
        @file_put_contents($path, '');
    }
    if (file_exists($path) && !is_writable($path)) {
        @chmod($path, 0660);
    }
}

function v2CollabRealtimeHost(): string
{
    return '127.0.0.1';
}

function v2CollabRealtimePort(): int
{
    return 6091;
}

function v2CollabProxyHost(): string
{
    return '127.0.0.1';
}

function v2CollabProxyPort(): int
{
    return 6090;
}

function v2CollabProxyPath(): string
{
    return '/collabws/';
}

function v2CollabRealtimePidPath(): string
{
    return v2CollabDataRoot() . '/realtime_server.pid';
}

function v2CollabRealtimeLockPath(): string
{
    return v2CollabDataRoot() . '/realtime_server.lock';
}

function v2CollabRealtimeLogPath(): string
{
    return v2CollabDataRoot() . '/realtime_server.log';
}

function v2CollabProxyPidPath(): string
{
    return v2CollabDataRoot() . '/websockify.pid';
}

function v2CollabProxyLockPath(): string
{
    return v2CollabDataRoot() . '/websockify.lock';
}

function v2CollabIsTcpReachable(string $host, int $port, float $timeoutSec = 0.25): bool
{
    if ($host === '' || $port < 1 || $port > 65535) {
        return false;
    }
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
    if (!is_resource($fp)) {
        return false;
    }
    @fclose($fp);
    return true;
}

function v2CollabEnsureRealtimeServerRunning(): void
{
    $host = v2CollabRealtimeHost();
    $port = v2CollabRealtimePort();
    if (v2CollabIsTcpReachable($host, $port)) {
        return;
    }

    v2CollabEnsureRoots();
    v2CollabEnsureWritableFile(v2CollabRealtimeLockPath());
    v2CollabEnsureWritableFile(v2CollabRealtimeLogPath());
    $lockFp = @fopen(v2CollabRealtimeLockPath(), 'c');
    if ($lockFp === false) {
        throw new RuntimeException('Failed to prepare collab server lock');
    }

    try {
        if (!@flock($lockFp, LOCK_EX)) {
            throw new RuntimeException('Failed to lock collab server startup');
        }

        if (v2CollabIsTcpReachable($host, $port)) {
            return;
        }

        $script = dirname(__DIR__) . '/bin/run_collab_realtime_server.php';
        if (!is_file($script)) {
            throw new RuntimeException('Collab realtime server script is missing');
        }

        $cmd = sprintf(
            'nohup %s %s --host=%s --port=%d --pid-file=%s --log-file=%s < /dev/null > /dev/null 2>&1 & echo $!',
            escapeshellarg(v2CollabPhpBinary()),
            escapeshellarg($script),
            escapeshellarg($host),
            $port,
            escapeshellarg(v2CollabRealtimePidPath()),
            escapeshellarg(v2CollabRealtimeLogPath())
        );

        $out = [];
        $rc = 1;
        @exec('/bin/bash -lc ' . escapeshellarg($cmd), $out, $rc);
        if ($rc === 0 && isset($out[0]) && preg_match('/^[0-9]+$/', trim((string) $out[0]))) {
            @file_put_contents(v2CollabRealtimePidPath(), trim((string) $out[0]));
            @chmod(v2CollabRealtimePidPath(), 0660);
        }
    } finally {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (v2CollabIsTcpReachable($host, $port)) {
            return;
        }
        usleep(50000);
    }

    throw new RuntimeException('Failed to start collaboration realtime server');
}

function v2CollabEnsureProxyRunning(): void
{
    $host = v2CollabProxyHost();
    $port = v2CollabProxyPort();
    if (v2CollabIsTcpReachable($host, $port)) {
        return;
    }

    v2CollabEnsureRoots();
    v2CollabEnsureWritableFile(v2CollabProxyLockPath());
    $lockFp = @fopen(v2CollabProxyLockPath(), 'c');
    if ($lockFp === false) {
        throw new RuntimeException('Failed to prepare collab websocket proxy lock');
    }

    try {
        if (!@flock($lockFp, LOCK_EX)) {
            throw new RuntimeException('Failed to lock collab websocket proxy startup');
        }

        if (v2CollabIsTcpReachable($host, $port)) {
            return;
        }

        $target = v2CollabRealtimeHost() . ':' . v2CollabRealtimePort();
        $cmd = sprintf(
            'nohup %s %s:%d %s < /dev/null > /dev/null 2>&1 & echo $!',
            escapeshellarg(v2CollabWebsockifyBinary()),
            escapeshellarg($host),
            $port,
            escapeshellarg($target)
        );

        $out = [];
        $rc = 1;
        @exec('/bin/bash -lc ' . escapeshellarg($cmd), $out, $rc);
        if ($rc === 0 && isset($out[0]) && preg_match('/^[0-9]+$/', trim((string) $out[0]))) {
            @file_put_contents(v2CollabProxyPidPath(), trim((string) $out[0]));
            @chmod(v2CollabProxyPidPath(), 0660);
        }
    } finally {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (v2CollabIsTcpReachable($host, $port)) {
            return;
        }
        usleep(50000);
    }

    throw new RuntimeException('Failed to start collaboration websocket proxy');
}

function v2CollabNormalizeToken(string $token): string
{
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return '';
    }
    return $token;
}

function v2CollabGenerateToken(): string
{
    return bin2hex(random_bytes(32));
}

function v2CollabGenerateUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function v2CollabCleanupExpiredTokens(PDO $db): void
{
    $stmt = $db->prepare(
        "DELETE FROM lab_collab_tokens
         WHERE revoked_at IS NOT NULL
            OR expires_at < NOW() - INTERVAL '5 minutes'"
    );
    $stmt->execute();
}

function v2CollabFetchLabAccessRow(PDO $db, array $viewer, string $labId): ?array
{
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        return null;
    }

    $isAdmin = viewerIsAdmin($viewer);
    $sql = "SELECT l.id,
                   l.is_shared,
                   l.collaborate_allowed,
                   l.author_user_id,
                   su.user_id AS shared_user_id
            FROM labs l
            LEFT JOIN lab_shared_users su ON su.lab_id = l.id AND su.user_id = :viewer_id
            WHERE l.id = :lab_id";
    if (!$isAdmin) {
        $sql .= " AND (l.author_user_id = :viewer_id OR su.user_id IS NOT NULL)";
    }
    $sql .= " LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function v2CollabOpenSession(PDO $db, array $viewer, string $labId): array
{
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $row = v2CollabFetchLabAccessRow($db, $viewer, $labId);
    if (!is_array($row)) {
        throw new RuntimeException('Forbidden');
    }

    if (empty($row['collaborate_allowed'])) {
        return [
            'enabled' => false,
            'reason' => 'collaboration_disabled'
        ];
    }

    v2CollabCleanupExpiredTokens($db);
    v2CollabEnsureRealtimeServerRunning();
    v2CollabEnsureProxyRunning();

    $token = v2CollabGenerateToken();
    $ttlSeconds = 600;

    $stmt = $db->prepare(
        "INSERT INTO lab_collab_tokens (token, lab_id, user_id, expires_at)
         VALUES (:token, :lab_id, :user_id, NOW() + (:ttl || ' seconds')::interval)
         RETURNING expires_at"
    );
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->bindValue(':user_id', $viewerId, PDO::PARAM_STR);
    $stmt->bindValue(':ttl', (string) $ttlSeconds, PDO::PARAM_STR);
    $stmt->execute();
    $inserted = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'enabled' => true,
        'ws_path' => v2CollabProxyPath(),
        'token' => $token,
        'expires_at' => is_array($inserted) ? (string) ($inserted['expires_at'] ?? '') : '',
        'client_id' => v2CollabGenerateUuid(),
        'heartbeat_sec' => 20,
    ];
}
