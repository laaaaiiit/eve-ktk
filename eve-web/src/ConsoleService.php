<?php

declare(strict_types=1);

function v2ConsoleDataRoot(): string
{
    return '/opt/unetlab/data/v2-console';
}

function v2ConsoleSessionsRoot(): string
{
    return v2ConsoleDataRoot() . '/sessions';
}

function v2ConsoleNowIso(): string
{
    return gmdate('c');
}

function v2ConsolePhpBinary(): string
{
    $candidates = [];

    $phpBinary = defined('PHP_BINARY') ? trim((string) PHP_BINARY) : '';
    $phpBinaryBase = $phpBinary !== '' ? strtolower((string) basename($phpBinary)) : '';
    $isFpmBinary = $phpBinaryBase !== '' && strpos($phpBinaryBase, 'php-fpm') !== false;

    // Prefer CLI paths first. On FPM hosts PHP_BINARY may point to php-fpm.
    $candidates[] = '/usr/bin/php';
    $candidates[] = '/usr/local/bin/php';
    if ($phpBinary !== '' && !$isFpmBinary) {
        $candidates[] = $phpBinary;
    }
    $candidates[] = 'php';

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

function v2ConsoleEnsureRoots(): void
{
    $roots = [v2ConsoleDataRoot(), v2ConsoleSessionsRoot()];
    foreach ($roots as $dir) {
        if (is_dir($dir)) {
            continue;
        }
        if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create console storage directory');
        }
    }
}

function v2ConsoleGenerateSessionId(): string
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

function v2ConsoleNormalizeSessionId(string $sessionId): string
{
    $normalized = strtolower(trim($sessionId));
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $normalized)) {
        return '';
    }
    return $normalized;
}

function v2ConsoleSessionDir(string $sessionId): string
{
    return v2ConsoleSessionsRoot() . '/' . $sessionId;
}

function v2ConsoleSessionMetaPath(string $sessionId): string
{
    return v2ConsoleSessionDir($sessionId) . '/meta.json';
}

function v2ConsoleSessionOutPath(string $sessionId): string
{
    return v2ConsoleSessionDir($sessionId) . '/out.log';
}

function v2ConsoleSessionInPath(string $sessionId): string
{
    return v2ConsoleSessionDir($sessionId) . '/in.queue';
}

function v2ConsoleSessionStopPath(string $sessionId): string
{
    return v2ConsoleSessionDir($sessionId) . '/stop.flag';
}

function v2ConsoleSessionWorkerLogPath(string $sessionId): string
{
    return v2ConsoleSessionDir($sessionId) . '/worker.log';
}

function v2ConsoleVncTokensPath(): string
{
    return v2ConsoleDataRoot() . '/vnc_tokens.map';
}

function v2ConsoleVncProxyPath(): string
{
    return '/vncws/';
}

function v2ConsoleNormalizeVncToken(string $token): string
{
    $token = trim($token);
    if (!preg_match('/^[a-zA-Z0-9._:-]{8,128}$/', $token)) {
        return '';
    }
    return $token;
}

function v2ConsoleReadVncTokenMap(): array
{
    $path = v2ConsoleVncTokensPath();
    if (!is_file($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $result = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('/^([a-zA-Z0-9._:-]+)\s*:\s*([a-zA-Z0-9_.-]+):([0-9]{1,5})$/', $line, $m)) {
            continue;
        }
        $token = v2ConsoleNormalizeVncToken((string) $m[1]);
        $host = trim((string) $m[2]);
        $port = (int) $m[3];
        if ($token === '' || $host === '' || $port < 1 || $port > 65535) {
            continue;
        }
        $result[$token] = $host . ':' . $port;
    }

    return $result;
}

function v2ConsoleWriteVncTokenMap(array $map): void
{
    v2ConsoleEnsureRoots();

    $path = v2ConsoleVncTokensPath();
    $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(3));

    ksort($map);
    $lines = [];
    foreach ($map as $token => $target) {
        $token = v2ConsoleNormalizeVncToken((string) $token);
        $target = trim((string) $target);
        if ($token === '' || $target === '') {
            continue;
        }
        $lines[] = $token . ': ' . $target;
    }
    $payload = implode("\n", $lines);
    if ($payload !== '') {
        $payload .= "\n";
    }

    if (@file_put_contents($tmpPath, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write VNC token map');
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Failed to finalize VNC token map');
    }
}

function v2ConsoleSetVncTokenTarget(string $token, string $host, int $port): void
{
    $token = v2ConsoleNormalizeVncToken($token);
    $host = trim($host);
    if ($token === '' || $host === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid VNC token mapping');
    }
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $host)) {
        throw new RuntimeException('Invalid VNC target host');
    }

    $map = v2ConsoleReadVncTokenMap();
    $map[$token] = $host . ':' . $port;
    v2ConsoleWriteVncTokenMap($map);
}

function v2ConsoleRemoveVncToken(string $token): void
{
    $token = v2ConsoleNormalizeVncToken($token);
    if ($token === '') {
        return;
    }
    $map = v2ConsoleReadVncTokenMap();
    if (!isset($map[$token])) {
        return;
    }
    unset($map[$token]);
    try {
        v2ConsoleWriteVncTokenMap($map);
    } catch (Throwable $e) {
        // Ignore token cleanup failures.
    }
}

function v2ConsoleVncProxyHost(): string
{
    return '127.0.0.1';
}

function v2ConsoleVncProxyPort(): int
{
    return 6080;
}

function v2ConsoleVncProxyPidPath(): string
{
    return v2ConsoleDataRoot() . '/websockify.pid';
}

function v2ConsoleVncProxyLockPath(): string
{
    return v2ConsoleDataRoot() . '/websockify.lock';
}

function v2ConsoleCheckLocksRoot(): string
{
    return v2ConsoleDataRoot() . '/check-locks';
}

function v2ConsoleNodeCheckLockPath(string $labId, string $nodeId): string
{
    return rtrim(v2ConsoleCheckLocksRoot(), '/') . '/' . strtolower($labId . '--' . $nodeId) . '.lock';
}

function v2ConsoleNodeLockedByLabCheck(string $labId, string $nodeId): bool
{
    $path = v2ConsoleNodeCheckLockPath($labId, $nodeId);
    if (!is_file($path)) {
        return false;
    }
    $mtime = @filemtime($path);
    if (is_int($mtime) && $mtime > 0 && (time() - $mtime) > 7200) {
        @unlink($path);
        return false;
    }
    return true;
}

function v2ConsoleWebsockifyBinary(): string
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

function v2ConsoleIsTcpReachable(string $host, int $port, float $timeoutSec = 0.25): bool
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

function v2ConsoleEnsureVncProxyRunning(): void
{
    $host = v2ConsoleVncProxyHost();
    $port = v2ConsoleVncProxyPort();
    if (v2ConsoleIsTcpReachable($host, $port)) {
        return;
    }

    v2ConsoleEnsureRoots();

    $lockFp = @fopen(v2ConsoleVncProxyLockPath(), 'c');
    if ($lockFp === false) {
        throw new RuntimeException('Failed to prepare VNC proxy lock');
    }

    try {
        if (!@flock($lockFp, LOCK_EX)) {
            throw new RuntimeException('Failed to lock VNC proxy startup');
        }

        if (v2ConsoleIsTcpReachable($host, $port)) {
            return;
        }

        $binary = v2ConsoleWebsockifyBinary();
        $tokenSource = v2ConsoleVncTokensPath();
        if (!is_file($tokenSource)) {
            @file_put_contents($tokenSource, '');
        }

        $cmd = sprintf(
            '%s %s:%d --token-plugin TokenFile --token-source %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($binary),
            escapeshellarg($host),
            $port,
            escapeshellarg($tokenSource)
        );

        $out = [];
        $rc = 1;
        @exec('/bin/bash -lc ' . escapeshellarg($cmd), $out, $rc);
        if ($rc === 0 && isset($out[0]) && preg_match('/^[0-9]+$/', trim((string) $out[0]))) {
            @file_put_contents(v2ConsoleVncProxyPidPath(), trim((string) $out[0]));
        }
    } finally {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }

    for ($attempt = 0; $attempt < 40; $attempt++) {
        if (v2ConsoleIsTcpReachable($host, $port)) {
            return;
        }
        usleep(50000);
    }

    throw new RuntimeException('Failed to start VNC websocket proxy');
}

function v2ConsoleWriteMeta(string $sessionId, array $meta): void
{
    $path = v2ConsoleSessionMetaPath($sessionId);
    $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(3));
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode console metadata');
    }
    if (@file_put_contents($tmpPath, $json, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write console metadata');
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Failed to finalize console metadata');
    }
}

function v2ConsoleReadMeta(string $sessionId): ?array
{
    $path = v2ConsoleSessionMetaPath($sessionId);
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

function v2ConsoleCanAccessSession(array $viewer, array $meta): bool
{
    if (viewerIsAdmin($viewer)) {
        return true;
    }
    $viewerId = (string) ($viewer['id'] ?? '');
    $ownerUserId = (string) ($meta['owner_user_id'] ?? '');
    return $viewerId !== '' && $ownerUserId !== '' && hash_equals($ownerUserId, $viewerId);
}

function v2ConsoleGetSessionForViewer(array $viewer, string $sessionId): array
{
    $sessionId = v2ConsoleNormalizeSessionId($sessionId);
    if ($sessionId === '') {
        throw new InvalidArgumentException('Invalid session id');
    }
    $meta = v2ConsoleReadMeta($sessionId);
    if (!is_array($meta)) {
        throw new RuntimeException('Session not found');
    }
    if (!v2ConsoleCanAccessSession($viewer, $meta)) {
        throw new RuntimeException('Forbidden');
    }
    return $meta;
}

function v2ConsoleReadNodeRuntime(PDO $db, string $labId, string $nodeId): ?array
{
    $stmt = $db->prepare(
        "SELECT id,
                name,
                console,
                is_running,
                power_state,
                runtime_console_port,
                runtime_pid,
                last_error
         FROM lab_nodes
         WHERE lab_id = :lab_id
           AND id = :node_id
         LIMIT 1"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    $console = strtolower(trim((string) ($row['console'] ?? 'telnet')));
    if ($console === '') {
        $console = 'telnet';
    }

    return [
        'id' => (string) $row['id'],
        'name' => (string) ($row['name'] ?? 'Node'),
        'console' => $console,
        'is_running' => !empty($row['is_running']),
        'power_state' => strtolower((string) ($row['power_state'] ?? 'stopped')),
        'runtime_console_port' => isset($row['runtime_console_port']) ? ((int) $row['runtime_console_port'] ?: 0) : 0,
        'runtime_pid' => isset($row['runtime_pid']) ? ((int) $row['runtime_pid'] ?: 0) : 0,
        'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : '',
    ];
}

function v2ConsoleSpawnWorker(string $sessionId): int
{
    $script = dirname(__DIR__) . '/bin/run_console_session.php';
    if (!is_file($script)) {
        return 0;
    }
    $php = v2ConsolePhpBinary();
    $workerLogPath = v2ConsoleSessionWorkerLogPath($sessionId);
    @file_put_contents($workerLogPath, '');

    $cmd = sprintf(
        '%s %s --session=%s >> %s 2>&1 & echo $!',
        escapeshellarg($php),
        escapeshellarg($script),
        escapeshellarg($sessionId),
        escapeshellarg($workerLogPath)
    );
    $out = [];
    $rc = 1;
    @exec($cmd, $out, $rc);
    if ($rc !== 0 || !isset($out[0])) {
        return 0;
    }
    $pidRaw = trim((string) $out[0]);
    if ($pidRaw === '' || !preg_match('/^[0-9]+$/', $pidRaw)) {
        return 0;
    }
    $pid = (int) $pidRaw;
    if ($pid <= 0) {
        return 0;
    }

    // Fast sanity check: fail early if process exits immediately.
    usleep(120000);
    $alive = false;
    if (function_exists('posix_kill')) {
        $alive = @posix_kill($pid, 0);
    } else {
        $alive = @is_dir('/proc/' . $pid);
    }

    return $alive ? $pid : 0;
}

function v2ConsoleReadOutputChunk(string $path, int $offset, int $maxBytes): array
{
    if ($offset < 0) {
        $offset = 0;
    }
    if ($maxBytes < 1024) {
        $maxBytes = 1024;
    }
    if ($maxBytes > 262144) {
        $maxBytes = 262144;
    }

    if (!is_file($path)) {
        return ['', $offset, 0];
    }

    clearstatcache(true, $path);
    $size = @filesize($path);
    $size = is_int($size) ? $size : 0;
    if ($offset > $size) {
        $offset = $size;
    }
    if ($size <= $offset) {
        return ['', $offset, $size];
    }

    $toRead = min($maxBytes, $size - $offset);
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        return ['', $offset, $size];
    }

    $chunk = '';
    if (@fseek($fp, $offset) === 0) {
        $read = @fread($fp, $toRead);
        if (is_string($read)) {
            $chunk = $read;
        }
    }
    @fclose($fp);

    $nextOffset = $offset + strlen($chunk);
    return [$chunk, $nextOffset, $size];
}

function v2ConsoleUpdateMetaHeartbeat(string $sessionId): void
{
    $meta = v2ConsoleReadMeta($sessionId);
    if (!is_array($meta)) {
        return;
    }
    $meta['last_client_activity_at'] = v2ConsoleNowIso();
    $meta['updated_at'] = v2ConsoleNowIso();
    try {
        v2ConsoleWriteMeta($sessionId, $meta);
    } catch (Throwable $e) {
        // ignore heartbeat update failures
    }
}

function v2ConsoleCloseConflictingUserNodeSessions(array $viewer, string $labId, string $nodeId): void
{
    $ownerUserId = (string) ($viewer['id'] ?? '');
    if ($ownerUserId === '') {
        return;
    }
    $root = v2ConsoleSessionsRoot();
    if (!is_dir($root)) {
        return;
    }

    $entries = @scandir($root);
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        $sid = v2ConsoleNormalizeSessionId((string) $entry);
        if ($sid === '') {
            continue;
        }
        $meta = v2ConsoleReadMeta($sid);
        if (!is_array($meta)) {
            continue;
        }
        if (!hash_equals($ownerUserId, (string) ($meta['owner_user_id'] ?? ''))) {
            continue;
        }
        if (!hash_equals($labId, (string) ($meta['lab_id'] ?? ''))) {
            continue;
        }
        if (!hash_equals($nodeId, (string) ($meta['node_id'] ?? ''))) {
            continue;
        }

        $status = strtolower(trim((string) ($meta['status'] ?? '')));
        if ($status === 'closed' || $status === 'error') {
            continue;
        }

        $consoleType = strtolower(trim((string) ($meta['node_console'] ?? 'telnet')));
        if ($consoleType === 'vnc') {
            if (isset($meta['vnc_token'])) {
                v2ConsoleRemoveVncToken((string) $meta['vnc_token']);
            }
            $meta['status'] = 'closed';
            $meta['updated_at'] = v2ConsoleNowIso();
            $meta['closed_at'] = v2ConsoleNowIso();
            $meta['closed_reason'] = 'replaced_by_new_session';
            try {
                v2ConsoleWriteMeta($sid, $meta);
            } catch (Throwable $e) {
                // ignore session takeover write failures
            }
            continue;
        }

        $stopPath = v2ConsoleSessionStopPath($sid);
        @file_put_contents($stopPath, 'replaced_by_new_session');
        $meta['status'] = 'closing';
        $meta['updated_at'] = v2ConsoleNowIso();
        $meta['closed_reason'] = 'replaced_by_new_session';
        try {
            v2ConsoleWriteMeta($sid, $meta);
        } catch (Throwable $e) {
            // ignore session takeover write failures
        }
    }
}

function v2ConsoleOpenSession(PDO $db, array $viewer, string $labId, string $nodeId): array
{
    if (!viewerCanViewLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    if (v2ConsoleNodeLockedByLabCheck($labId, $nodeId)) {
        throw new RuntimeException('Node console is temporarily reserved by lab check');
    }

    v2ConsoleEnsureRoots();
    v2ConsoleGarbageCollect();

    $node = v2ConsoleReadNodeRuntime($db, $labId, $nodeId);
    if (!is_array($node)) {
        throw new RuntimeException('Node not found');
    }

    $powerState = (string) ($node['power_state'] ?? 'stopped');
    $isRunning = !empty($node['is_running']) || $powerState === 'running';
    $consolePort = (int) ($node['runtime_console_port'] ?? 0);
    $consoleType = strtolower(trim((string) ($node['console'] ?? 'telnet')));
    if ($consoleType === '') {
        $consoleType = 'telnet';
    }

    if (!$isRunning || $consolePort < 1) {
        throw new RuntimeException('Node is not running');
    }

    // Some telnet consoles behave badly with multiple concurrent clients.
    // Keep one active session per user+node by replacing older ones.
    v2ConsoleCloseConflictingUserNodeSessions($viewer, $labId, $nodeId);

    $sessionId = v2ConsoleGenerateSessionId();
    $sessionDir = v2ConsoleSessionDir($sessionId);

    if (!@mkdir($sessionDir, 0770, true) && !is_dir($sessionDir)) {
        throw new RuntimeException('Failed to create console session directory');
    }

    if (@file_put_contents(v2ConsoleSessionOutPath($sessionId), '') === false) {
        throw new RuntimeException('Failed to initialize console output log');
    }
    if (@file_put_contents(v2ConsoleSessionInPath($sessionId), '') === false) {
        throw new RuntimeException('Failed to initialize console input queue');
    }

    $isVnc = ($consoleType === 'vnc');
    $nowIso = v2ConsoleNowIso();
    $meta = [
        'session_id' => $sessionId,
        'status' => $isVnc ? 'running' : 'starting',
        'created_at' => $nowIso,
        'updated_at' => $nowIso,
        'closed_at' => null,
        'closed_reason' => null,
        'owner_user_id' => (string) ($viewer['id'] ?? ''),
        'owner_username' => (string) ($viewer['username'] ?? ''),
        'owner_role' => (string) ($viewer['role_name'] ?? $viewer['role'] ?? ''),
        'lab_id' => $labId,
        'node_id' => $nodeId,
        'node_name' => (string) ($node['name'] ?? 'Node'),
        'node_console' => $consoleType,
        'target_host' => '127.0.0.1',
        'target_port' => $consolePort,
        'target_power_state' => $powerState,
        'worker_pid' => null,
        'worker_started_at' => null,
        'worker_last_seen_at' => null,
        'worker_error' => null,
        'last_client_activity_at' => v2ConsoleNowIso(),
        'bytes_in' => 0,
        'bytes_out' => 0,
    ];

    if ($isVnc) {
        v2ConsoleEnsureVncProxyRunning();
        $vncToken = v2ConsoleNormalizeVncToken($sessionId);
        if ($vncToken === '') {
            throw new RuntimeException('Failed to prepare VNC session token');
        }
        v2ConsoleSetVncTokenTarget($vncToken, '127.0.0.1', $consolePort);
        $meta['vnc_token'] = $vncToken;
        $meta['vnc_proxy_path'] = v2ConsoleVncProxyPath();
    }

    v2ConsoleWriteMeta($sessionId, $meta);

    if (!$isVnc) {
        $pid = v2ConsoleSpawnWorker($sessionId);
        if ($pid > 0) {
            $meta['worker_pid'] = $pid;
            $meta['worker_started_at'] = v2ConsoleNowIso();
            $meta['updated_at'] = v2ConsoleNowIso();
            v2ConsoleWriteMeta($sessionId, $meta);
        } else {
            $meta['status'] = 'error';
            $meta['updated_at'] = v2ConsoleNowIso();
            $meta['closed_at'] = v2ConsoleNowIso();
            $meta['closed_reason'] = 'worker_spawn_failed';
            $meta['worker_error'] = 'Failed to start console worker process';
            v2ConsoleWriteMeta($sessionId, $meta);
        }
    }

    $response = [
        'session_id' => $sessionId,
        'status' => $isVnc ? 'running' : 'starting',
        'lab_id' => $labId,
        'node_id' => $nodeId,
        'node_name' => (string) ($node['name'] ?? 'Node'),
        'console' => $consoleType,
        'created_at' => (string) $meta['created_at'],
    ];

    if ($isVnc) {
        $token = (string) ($meta['vnc_token'] ?? '');
        $proxyPath = (string) ($meta['vnc_proxy_path'] ?? v2ConsoleVncProxyPath());
        $response['vnc_token'] = $token;
        $response['vnc_proxy_path'] = $proxyPath;
        $response['vnc_path'] = rtrim($proxyPath, '/') . '/?token=' . rawurlencode($token);
    }

    return $response;
}

function v2ConsoleReadSession(array $viewer, string $sessionId, int $offset = 0, int $waitMs = 350, int $maxBytes = 65536): array
{
    $sessionId = v2ConsoleNormalizeSessionId($sessionId);
    if ($sessionId === '') {
        throw new InvalidArgumentException('Invalid session id');
    }

    $meta = v2ConsoleGetSessionForViewer($viewer, $sessionId);
    $consoleType = strtolower(trim((string) ($meta['node_console'] ?? 'telnet')));
    if ($consoleType === '') {
        $consoleType = 'telnet';
    }

    if ($consoleType === 'vnc') {
        v2ConsoleUpdateMetaHeartbeat($sessionId);
        $meta = v2ConsoleGetSessionForViewer($viewer, $sessionId);

        $response = [
            'session_id' => $sessionId,
            'status' => (string) ($meta['status'] ?? 'running'),
            'chunk_base64' => '',
            'next_offset' => 0,
            'output_size' => 0,
            'node_id' => (string) ($meta['node_id'] ?? ''),
            'node_name' => (string) ($meta['node_name'] ?? ''),
            'console' => 'vnc',
            'closed_reason' => isset($meta['closed_reason']) ? (string) $meta['closed_reason'] : null,
        ];
        if (isset($meta['vnc_token'])) {
            $token = (string) $meta['vnc_token'];
            $proxyPath = (string) ($meta['vnc_proxy_path'] ?? v2ConsoleVncProxyPath());
            $response['vnc_token'] = $token;
            $response['vnc_proxy_path'] = $proxyPath;
            $response['vnc_path'] = rtrim($proxyPath, '/') . '/?token=' . rawurlencode($token);
        }
        return $response;
    }

    $waitMs = max(0, min(5000, $waitMs));
    $maxBytes = max(1024, min(262144, $maxBytes));
    $offset = max(0, $offset);

    $outPath = v2ConsoleSessionOutPath($sessionId);
    $deadline = microtime(true) + ($waitMs / 1000);

    $chunk = '';
    $nextOffset = $offset;
    $size = 0;

    while (true) {
        [$chunk, $nextOffset, $size] = v2ConsoleReadOutputChunk($outPath, $offset, $maxBytes);
        if ($chunk !== '') {
            break;
        }

        $status = strtolower((string) ($meta['status'] ?? 'starting'));
        if ($status === 'closed' || $status === 'error') {
            break;
        }

        if (microtime(true) >= $deadline) {
            break;
        }

        usleep(20000);
        $meta = v2ConsoleGetSessionForViewer($viewer, $sessionId);
    }

    $meta = v2ConsoleGetSessionForViewer($viewer, $sessionId);
    v2ConsoleUpdateMetaHeartbeat($sessionId);

    $response = [
        'session_id' => $sessionId,
        'status' => (string) ($meta['status'] ?? 'starting'),
        'chunk_base64' => $chunk !== '' ? base64_encode($chunk) : '',
        'next_offset' => $nextOffset,
        'output_size' => $size,
        'node_id' => (string) ($meta['node_id'] ?? ''),
        'node_name' => (string) ($meta['node_name'] ?? ''),
        'console' => $consoleType,
        'closed_reason' => isset($meta['closed_reason']) ? (string) $meta['closed_reason'] : null,
    ];
    if (isset($meta['vnc_token'])) {
        $token = (string) $meta['vnc_token'];
        $proxyPath = (string) ($meta['vnc_proxy_path'] ?? v2ConsoleVncProxyPath());
        $response['vnc_token'] = $token;
        $response['vnc_proxy_path'] = $proxyPath;
        $response['vnc_path'] = rtrim($proxyPath, '/') . '/?token=' . rawurlencode($token);
    }
    return $response;
}

function v2ConsoleWriteSession(array $viewer, string $sessionId, string $data): array
{
    $sessionId = v2ConsoleNormalizeSessionId($sessionId);
    if ($sessionId === '') {
        throw new InvalidArgumentException('Invalid session id');
    }

    $meta = v2ConsoleGetSessionForViewer($viewer, $sessionId);
    $consoleType = strtolower(trim((string) ($meta['node_console'] ?? 'telnet')));
    if ($consoleType === '') {
        $consoleType = 'telnet';
    }
    $status = strtolower((string) ($meta['status'] ?? 'starting'));
    if ($status === 'closed' || $status === 'error') {
        throw new RuntimeException('Session is closed');
    }

    if ($consoleType === 'vnc') {
        $meta['last_client_activity_at'] = v2ConsoleNowIso();
        $meta['updated_at'] = v2ConsoleNowIso();
        v2ConsoleWriteMeta($sessionId, $meta);
        return [
            'session_id' => $sessionId,
            'status' => (string) ($meta['status'] ?? 'running'),
            'written' => 0,
        ];
    }

    if ($data === '') {
        return [
            'session_id' => $sessionId,
            'status' => (string) ($meta['status'] ?? 'starting'),
            'written' => 0,
        ];
    }

    $inPath = v2ConsoleSessionInPath($sessionId);
    $fp = @fopen($inPath, 'ab');
    if ($fp === false) {
        throw new RuntimeException('Failed to open console input queue');
    }

    $written = 0;
    try {
        if (@flock($fp, LOCK_EX)) {
            $writtenBytes = @fwrite($fp, $data);
            $written = is_int($writtenBytes) ? $writtenBytes : 0;
            @fflush($fp);
            @flock($fp, LOCK_UN);
        }
    } finally {
        @fclose($fp);
    }

    $meta['last_client_activity_at'] = v2ConsoleNowIso();
    $meta['updated_at'] = v2ConsoleNowIso();
    $meta['bytes_in'] = (int) ($meta['bytes_in'] ?? 0) + $written;
    v2ConsoleWriteMeta($sessionId, $meta);

    return [
        'session_id' => $sessionId,
        'status' => (string) ($meta['status'] ?? 'starting'),
        'written' => $written,
    ];
}

function v2ConsoleCloseSession(array $viewer, string $sessionId, string $reason = 'client_closed'): array
{
    $sessionId = v2ConsoleNormalizeSessionId($sessionId);
    if ($sessionId === '') {
        throw new InvalidArgumentException('Invalid session id');
    }

    $meta = v2ConsoleGetSessionForViewer($viewer, $sessionId);
    $consoleType = strtolower(trim((string) ($meta['node_console'] ?? 'telnet')));
    if ($consoleType === '') {
        $consoleType = 'telnet';
    }
    $reason = trim($reason) !== '' ? trim($reason) : 'client_closed';

    if ($consoleType === 'vnc') {
        if (isset($meta['vnc_token'])) {
            v2ConsoleRemoveVncToken((string) $meta['vnc_token']);
        }
        $meta['status'] = 'closed';
        $meta['updated_at'] = v2ConsoleNowIso();
        $meta['closed_at'] = v2ConsoleNowIso();
        $meta['closed_reason'] = $reason;
        v2ConsoleWriteMeta($sessionId, $meta);
        return [
            'session_id' => $sessionId,
            'status' => 'closed',
        ];
    }

    $stopPath = v2ConsoleSessionStopPath($sessionId);
    @file_put_contents($stopPath, $reason);

    $meta['status'] = 'closing';
    $meta['updated_at'] = v2ConsoleNowIso();
    $meta['closed_reason'] = $reason;
    v2ConsoleWriteMeta($sessionId, $meta);

    $pid = (int) ($meta['worker_pid'] ?? 0);
    if ($pid > 0 && function_exists('posix_kill')) {
        $signal = defined('SIGTERM') ? SIGTERM : 15;
        @posix_kill($pid, $signal);
    }

    return [
        'session_id' => $sessionId,
        'status' => 'closing',
    ];
}

function v2ConsoleDeleteDirRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            v2ConsoleDeleteDirRecursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function v2ConsoleGarbageCollect(int $maxHours = 12): void
{
    $root = v2ConsoleSessionsRoot();
    if (!is_dir($root)) {
        return;
    }

    $ttl = max(1, $maxHours) * 3600;
    $now = time();

    $entries = @scandir($root);
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $dir = $root . '/' . $entry;
        if (!is_dir($dir)) {
            continue;
        }

        $metaPath = $dir . '/meta.json';
        $meta = null;
        $shouldDelete = false;

        if (is_file($metaPath)) {
            $raw = @file_get_contents($metaPath);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($meta)) {
                $status = strtolower((string) ($meta['status'] ?? ''));
                $closedAt = isset($meta['closed_at']) ? strtotime((string) $meta['closed_at']) : false;
                $updatedAt = isset($meta['updated_at']) ? strtotime((string) $meta['updated_at']) : false;
                $reference = is_int($closedAt) && $closedAt > 0
                    ? $closedAt
                    : (is_int($updatedAt) && $updatedAt > 0 ? $updatedAt : (@filemtime($metaPath) ?: $now));

                if (($status === 'closed' || $status === 'error') && ($now - $reference) > $ttl) {
                    $shouldDelete = true;
                }
            }
        } else {
            $mtime = @filemtime($dir);
            if (is_int($mtime) && ($now - $mtime) > $ttl) {
                $shouldDelete = true;
            }
        }

        if ($shouldDelete) {
            if (is_array($meta) && isset($meta['vnc_token'])) {
                v2ConsoleRemoveVncToken((string) $meta['vnc_token']);
            }
            v2ConsoleDeleteDirRecursive($dir);
        }
    }
}
