#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

function srvNowIso(): string
{
    return gmdate('c');
}

function srvNormalizeUuid(string $value): string
{
    $value = strtolower(trim($value));
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value)) {
        return '';
    }
    return $value;
}

function srvNormalizeClientId(string $value): string
{
    $uuid = srvNormalizeUuid($value);
    if ($uuid !== '') {
        return $uuid;
    }
    $value = trim($value);
    if ($value !== '' && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $value)) {
        return $value;
    }
    return '';
}

function srvNormalizeToken(string $value): string
{
    $value = strtolower(trim($value));
    if (!preg_match('/^[a-f0-9]{64}$/', $value)) {
        return '';
    }
    return $value;
}

function srvNormalizeEntity(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['node', 'network', 'object'], true) ? $value : '';
}

function srvClampFloat($value, float $min = -200000.0, float $max = 200000.0): float
{
    $num = is_numeric($value) ? (float) $value : 0.0;
    if (!is_finite($num)) {
        $num = 0.0;
    }
    if ($num < $min) {
        return $min;
    }
    if ($num > $max) {
        return $max;
    }
    return $num;
}

function srvLoadViewerForToken(PDO $db, string $token, string $labId): ?array
{
    $stmt = $db->prepare(
        "SELECT t.user_id,
                t.lab_id,
                u.username,
                r.name AS role_name,
                l.author_user_id,
                l.collaborate_allowed
         FROM lab_collab_tokens t
         INNER JOIN users u ON u.id = t.user_id
         INNER JOIN roles r ON r.id = u.role_id
         INNER JOIN labs l ON l.id = t.lab_id
         WHERE t.token = :token
           AND t.lab_id = :lab_id
           AND t.revoked_at IS NULL
           AND t.expires_at >= NOW()
         LIMIT 1"
    );
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    if (empty($row['collaborate_allowed'])) {
        return null;
    }

    $userId = (string) ($row['user_id'] ?? '');
    $role = strtolower(trim((string) ($row['role_name'] ?? '')));
    $authorUserId = (string) ($row['author_user_id'] ?? '');

    $allowed = false;
    if ($role === 'admin' || ($authorUserId !== '' && hash_equals($authorUserId, $userId))) {
        $allowed = true;
    } else {
        $shareStmt = $db->prepare(
            "SELECT 1
             FROM lab_shared_users
             WHERE lab_id = :lab_id
               AND user_id = :user_id
             LIMIT 1"
        );
        $shareStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $shareStmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $shareStmt->execute();
        $allowed = $shareStmt->fetchColumn() !== false;
    }

    if (!$allowed) {
        return null;
    }

    $touch = $db->prepare(
        "UPDATE lab_collab_tokens
         SET last_used_at = NOW()
         WHERE token = :token"
    );
    $touch->bindValue(':token', $token, PDO::PARAM_STR);
    $touch->execute();

    return [
        'user_id' => $userId,
        'lab_id' => (string) ($row['lab_id'] ?? ''),
        'username' => (string) ($row['username'] ?? ''),
        'role' => $role,
    ];
}

function srvCleanupTokens(PDO $db): void
{
    $stmt = $db->prepare(
        "DELETE FROM lab_collab_tokens
         WHERE revoked_at IS NOT NULL
            OR expires_at < NOW() - INTERVAL '10 minutes'"
    );
    $stmt->execute();
}

$options = getopt('', ['host::', 'port::', 'pid-file::', 'log-file::']);
$host = trim((string) ($options['host'] ?? '127.0.0.1'));
$port = (int) ($options['port'] ?? 6091);
$pidFile = trim((string) ($options['pid-file'] ?? ''));
$logFile = trim((string) ($options['log-file'] ?? ''));

if ($host === '') {
    $host = '127.0.0.1';
}
if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "Invalid port\n");
    exit(2);
}

$logFp = null;
if ($logFile !== '') {
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }
    $logFp = @fopen($logFile, 'ab');
}

$log = static function (string $message) use (&$logFp): void {
    $line = '[' . srvNowIso() . '] ' . $message . "\n";
    if (is_resource($logFp)) {
        @fwrite($logFp, $line);
    } else {
        @fwrite(STDERR, $line);
    }
};

$address = sprintf('tcp://%s:%d', $host, $port);
$errno = 0;
$errstr = '';
$server = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
if ($server === false) {
    $log('failed to start server on ' . $address . ': ' . $errstr . ' (' . $errno . ')');
    exit(1);
}

@stream_set_blocking($server, false);
@stream_set_write_buffer($server, 0);

if ($pidFile !== '') {
    @file_put_contents($pidFile, (string) getmypid());
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

try {
    $db = db();
} catch (Throwable $e) {
    $log('database connection failed: ' . $e->getMessage());
    @fclose($server);
    if ($pidFile !== '') {
        @unlink($pidFile);
    }
    exit(1);
}

$clients = [];
$lastTokenCleanup = time();

$sendClient = static function ($socket, array $payload) use ($log): bool {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    $frame = $json . "\n";
    $left = strlen($frame);
    $offset = 0;
    while ($left > 0) {
        $written = @fwrite($socket, substr($frame, $offset, $left));
        if (!is_int($written) || $written <= 0) {
            return false;
        }
        $offset += $written;
        $left -= $written;
    }
    return true;
};

$clientPeerPayload = static function (array $client): array {
    return [
        'peer_id' => (string) ($client['client_id'] ?? ''),
        'user_id' => (string) ($client['user_id'] ?? ''),
        'username' => (string) ($client['username'] ?? ''),
        'role' => (string) ($client['role'] ?? ''),
    ];
};

$broadcastToLab = static function (string $labId, array $payload, ?int $exceptKey = null) use (&$clients, $sendClient): void {
    foreach ($clients as $key => $client) {
        if (empty($client['authed'])) {
            continue;
        }
        if ((string) ($client['lab_id'] ?? '') !== $labId) {
            continue;
        }
        if ($exceptKey !== null && $key === $exceptKey) {
            continue;
        }
        $socket = $client['socket'] ?? null;
        if (!is_resource($socket)) {
            continue;
        }
        $sendClient($socket, $payload);
    }
};

$closeClient = static function (int $key, string $reason = 'closed') use (&$clients, $broadcastToLab, $clientPeerPayload, $log): void {
    if (!isset($clients[$key])) {
        return;
    }
    $client = $clients[$key];
    $socket = $client['socket'] ?? null;
    if (is_resource($socket)) {
        @fclose($socket);
    }

    $hadAuth = !empty($client['authed']);
    $labId = (string) ($client['lab_id'] ?? '');
    unset($clients[$key]);

    if ($hadAuth && $labId !== '') {
        $payload = array_merge([
            'type' => 'peer_leave',
            'reason' => $reason,
            'ts' => (int) round(microtime(true) * 1000),
        ], $clientPeerPayload($client));
        $broadcastToLab($labId, $payload, null);
    }

    if ($hadAuth) {
        $log('client disconnected: ' . ($client['username'] ?? 'unknown') . ' (' . $reason . ')');
    }
};

$log('collab realtime server listening on ' . $address);

while ($running) {
    if (time() - $lastTokenCleanup >= 60) {
        try {
            srvCleanupTokens($db);
        } catch (Throwable $e) {
            $log('token cleanup failed: ' . $e->getMessage());
        }
        $lastTokenCleanup = time();
    }

    $read = [$server];
    foreach ($clients as $client) {
        $socket = $client['socket'] ?? null;
        if (is_resource($socket)) {
            $read[] = $socket;
        }
    }
    $write = null;
    $except = null;
    $changed = @stream_select($read, $write, $except, 1, 0);
    if ($changed === false) {
        usleep(20000);
        continue;
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            while (true) {
                $conn = @stream_socket_accept($server, 0);
                if ($conn === false) {
                    break;
                }
                @stream_set_blocking($conn, false);
                @stream_set_write_buffer($conn, 0);
                $key = (int) $conn;
                $clients[$key] = [
                    'socket' => $conn,
                    'buffer' => '',
                    'authed' => false,
                    'connected_at' => microtime(true),
                    'last_seen' => microtime(true),
                    'client_id' => '',
                    'user_id' => '',
                    'username' => '',
                    'role' => '',
                    'lab_id' => '',
                ];
                $sendClient($conn, [
                    'type' => 'hello',
                    'ts' => (int) round(microtime(true) * 1000),
                    'server_time' => srvNowIso(),
                ]);
            }
            continue;
        }

        $key = (int) $socket;
        if (!isset($clients[$key])) {
            continue;
        }

        $chunk = @fread($socket, 65536);
        if (!is_string($chunk) || $chunk === '') {
            if (@feof($socket)) {
                $closeClient($key, 'eof');
            }
            continue;
        }

        $clients[$key]['last_seen'] = microtime(true);
        $clients[$key]['buffer'] = (string) ($clients[$key]['buffer'] ?? '') . $chunk;
        if (strlen($clients[$key]['buffer']) > 524288) {
            $closeClient($key, 'buffer_overflow');
            continue;
        }

        while (true) {
            $buf = (string) ($clients[$key]['buffer'] ?? '');
            $pos = strpos($buf, "\n");
            if ($pos === false) {
                break;
            }
            $line = trim(substr($buf, 0, $pos));
            $clients[$key]['buffer'] = (string) substr($buf, $pos + 1);
            if ($line === '') {
                continue;
            }
            if (strlen($line) > 262144) {
                $closeClient($key, 'line_too_large');
                break;
            }

            $msg = json_decode($line, true);
            if (!is_array($msg)) {
                $sendClient($socket, [
                    'type' => 'error',
                    'code' => 'bad_json',
                    'message' => 'Invalid JSON payload',
                ]);
                continue;
            }

            $type = strtolower(trim((string) ($msg['type'] ?? '')));
            if ($type === '') {
                continue;
            }

            if (empty($clients[$key]['authed'])) {
                if ($type !== 'auth') {
                    $sendClient($socket, [
                        'type' => 'error',
                        'code' => 'auth_required',
                        'message' => 'Auth required',
                    ]);
                    $closeClient($key, 'auth_required');
                    break;
                }

                $token = srvNormalizeToken((string) ($msg['token'] ?? ''));
                $labId = srvNormalizeUuid((string) ($msg['lab_id'] ?? ''));
                $clientId = srvNormalizeClientId((string) ($msg['client_id'] ?? ''));

                if ($token === '' || $labId === '' || $clientId === '') {
                    $sendClient($socket, [
                        'type' => 'error',
                        'code' => 'auth_invalid',
                        'message' => 'Invalid auth payload',
                    ]);
                    $closeClient($key, 'auth_invalid');
                    break;
                }

                try {
                    $viewer = srvLoadViewerForToken($db, $token, $labId);
                } catch (Throwable $e) {
                    $sendClient($socket, [
                        'type' => 'error',
                        'code' => 'auth_failed',
                        'message' => 'Authentication failed',
                    ]);
                    $closeClient($key, 'auth_failed');
                    break;
                }

                if (!is_array($viewer)) {
                    $sendClient($socket, [
                        'type' => 'error',
                        'code' => 'auth_denied',
                        'message' => 'Authentication denied',
                    ]);
                    $closeClient($key, 'auth_denied');
                    break;
                }

                $clients[$key]['authed'] = true;
                $clients[$key]['client_id'] = $clientId;
                $clients[$key]['user_id'] = (string) ($viewer['user_id'] ?? '');
                $clients[$key]['username'] = (string) ($viewer['username'] ?? '');
                $clients[$key]['role'] = (string) ($viewer['role'] ?? '');
                $clients[$key]['lab_id'] = (string) ($viewer['lab_id'] ?? '');

                $peers = [];
                foreach ($clients as $otherKey => $other) {
                    if ($otherKey === $key || empty($other['authed'])) {
                        continue;
                    }
                    if ((string) ($other['lab_id'] ?? '') !== $clients[$key]['lab_id']) {
                        continue;
                    }
                    $peers[] = $clientPeerPayload($other);
                }

                $sendClient($socket, [
                    'type' => 'auth_ok',
                    'ts' => (int) round(microtime(true) * 1000),
                    'server_time' => srvNowIso(),
                    'peer' => $clientPeerPayload($clients[$key]),
                    'peers' => $peers,
                ]);

                $broadcastToLab((string) $clients[$key]['lab_id'], array_merge([
                    'type' => 'peer_join',
                    'ts' => (int) round(microtime(true) * 1000),
                ], $clientPeerPayload($clients[$key])), $key);

                $log('client authenticated: ' . $clients[$key]['username'] . ' lab=' . $clients[$key]['lab_id']);
                continue;
            }

            $labId = (string) ($clients[$key]['lab_id'] ?? '');
            if ($labId === '') {
                $closeClient($key, 'auth_state_invalid');
                break;
            }

            if ($type === 'ping') {
                $sendClient($socket, [
                    'type' => 'pong',
                    'ts' => (int) round(microtime(true) * 1000),
                ]);
                continue;
            }

            if ($type === 'cursor') {
                $x = srvClampFloat($msg['x'] ?? 0);
                $y = srvClampFloat($msg['y'] ?? 0);
                $broadcastToLab($labId, array_merge([
                    'type' => 'peer_cursor',
                    'x' => (int) round($x),
                    'y' => (int) round($y),
                    'ts' => (int) round(microtime(true) * 1000),
                ], $clientPeerPayload($clients[$key])), $key);
                continue;
            }

            if ($type === 'cursor_leave') {
                $broadcastToLab($labId, array_merge([
                    'type' => 'peer_cursor_leave',
                    'ts' => (int) round(microtime(true) * 1000),
                ], $clientPeerPayload($clients[$key])), $key);
                continue;
            }

            if ($type === 'move') {
                $entity = srvNormalizeEntity((string) ($msg['entity'] ?? ''));
                $entityId = srvNormalizeUuid((string) ($msg['id'] ?? ''));
                if ($entity === '' || $entityId === '') {
                    continue;
                }

                $payload = array_merge([
                    'type' => 'peer_move',
                    'entity' => $entity,
                    'id' => $entityId,
                    'left' => (int) round(srvClampFloat($msg['left'] ?? 0)),
                    'top' => (int) round(srvClampFloat($msg['top'] ?? 0)),
                    'ts' => (int) round(microtime(true) * 1000),
                ], $clientPeerPayload($clients[$key]));

                if (array_key_exists('width', $msg)) {
                    $payload['width'] = (int) round(srvClampFloat($msg['width'], 1, 100000));
                }
                if (array_key_exists('height', $msg)) {
                    $payload['height'] = (int) round(srvClampFloat($msg['height'], 1, 100000));
                }

                $broadcastToLab($labId, $payload, $key);
                continue;
            }

            if ($type === 'link_layout') {
                $layout = $msg['layout'] ?? null;
                if (!is_array($layout)) {
                    continue;
                }
                $broadcastToLab($labId, array_merge([
                    'type' => 'peer_link_layout',
                    'layout' => $layout,
                    'ts' => (int) round(microtime(true) * 1000),
                ], $clientPeerPayload($clients[$key])), $key);
                continue;
            }

            if ($type === 'refresh') {
                $reason = trim((string) ($msg['reason'] ?? ''));
                if (strlen($reason) > 120) {
                    $reason = substr($reason, 0, 120);
                }
                $broadcastToLab($labId, array_merge([
                    'type' => 'peer_refresh',
                    'reason' => $reason,
                    'ts' => (int) round(microtime(true) * 1000),
                ], $clientPeerPayload($clients[$key])), $key);
                continue;
            }
        }
    }

    $now = microtime(true);
    foreach (array_keys($clients) as $key) {
        if (!isset($clients[$key])) {
            continue;
        }
        $client = $clients[$key];
        $idleSec = $now - (float) ($client['last_seen'] ?? $now);
        if (empty($client['authed']) && $idleSec > 15.0) {
            $closeClient($key, 'auth_timeout');
            continue;
        }
        if (!empty($client['authed']) && $idleSec > 90.0) {
            $closeClient($key, 'idle_timeout');
            continue;
        }
    }
}

foreach (array_keys($clients) as $key) {
    $closeClient($key, 'server_shutdown');
}

@fclose($server);
if ($pidFile !== '') {
    @unlink($pidFile);
}
if (is_resource($logFp)) {
    @fclose($logFp);
}

exit(0);
