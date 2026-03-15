#!/usr/bin/env php
<?php

declare(strict_types=1);

function workerNowIso(): string
{
    return gmdate('c');
}

function workerSessionDir(string $sessionId): string
{
    return '/opt/unetlab/data/v2-console/sessions/' . $sessionId;
}

function workerMetaPath(string $sessionId): string
{
    return workerSessionDir($sessionId) . '/meta.json';
}

function workerOutPath(string $sessionId): string
{
    return workerSessionDir($sessionId) . '/out.log';
}

function workerInPath(string $sessionId): string
{
    return workerSessionDir($sessionId) . '/in.queue';
}

function workerStopPath(string $sessionId): string
{
    return workerSessionDir($sessionId) . '/stop.flag';
}

function workerReadMeta(string $sessionId): ?array
{
    $path = workerMetaPath($sessionId);
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

function workerWriteMeta(string $sessionId, array $meta): bool
{
    $path = workerMetaPath($sessionId);
    $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(3));
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    if (@file_put_contents($tmpPath, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return false;
    }
    return true;
}

function workerAppendOut(string $sessionId, string $chunk): int
{
    if ($chunk === '') {
        return 0;
    }
    $written = @file_put_contents(workerOutPath($sessionId), $chunk, FILE_APPEND);
    return is_int($written) ? $written : 0;
}

function workerTelnetStateInit(): array
{
    return [
        'mode' => 'data',
        'neg_verb' => null,
    ];
}

function workerTelnetConsumeChunk(string $chunk, array &$state): array
{
    $out = '';
    $reply = '';
    $len = strlen($chunk);
    for ($i = 0; $i < $len; $i++) {
        $byte = ord($chunk[$i]);
        $mode = (string) ($state['mode'] ?? 'data');

        if ($mode === 'data') {
            if ($byte === 255) { // IAC
                $state['mode'] = 'iac';
                continue;
            }
            $out .= $chunk[$i];
            continue;
        }

        if ($mode === 'iac') {
            if ($byte === 255) { // Escaped IAC data byte
                $out .= chr(255);
                $state['mode'] = 'data';
                continue;
            }
            if ($byte === 251 || $byte === 252 || $byte === 253 || $byte === 254) { // WILL/WONT/DO/DONT
                $state['neg_verb'] = $byte;
                $state['mode'] = 'neg_opt';
                continue;
            }
            if ($byte === 250) { // SB
                $state['mode'] = 'sb';
                continue;
            }
            $state['mode'] = 'data';
            $state['neg_verb'] = null;
            continue;
        }

        if ($mode === 'neg_opt') {
            $verb = (int) ($state['neg_verb'] ?? 0);
            $opt = $byte;
            if ($verb === 253 || $verb === 254) { // DO/DONT -> WONT
                $reply .= chr(255) . chr(252) . chr($opt);
            } elseif ($verb === 251 || $verb === 252) { // WILL/WONT -> DONT
                $reply .= chr(255) . chr(254) . chr($opt);
            }
            $state['mode'] = 'data';
            $state['neg_verb'] = null;
            continue;
        }

        if ($mode === 'sb') {
            if ($byte === 255) { // IAC inside SB
                $state['mode'] = 'sb_iac';
            }
            continue;
        }

        if ($mode === 'sb_iac') {
            if ($byte === 240) { // SE
                $state['mode'] = 'data';
            } elseif ($byte === 255) {
                $state['mode'] = 'sb';
            } else {
                $state['mode'] = 'sb';
            }
            continue;
        }

        $state['mode'] = 'data';
    }

    return [$out, $reply];
}

function workerEnableLowLatencySocket($stream): void
{
    if (!is_resource($stream)) {
        return;
    }

    @stream_set_read_buffer($stream, 0);
    @stream_set_write_buffer($stream, 0);

    if (!function_exists('socket_import_stream') || !function_exists('socket_set_option')) {
        return;
    }

    try {
        $socket = @socket_import_stream($stream);
        $isSocketObject = is_object($socket) && stripos(get_class($socket), 'socket') !== false;
        if (!is_resource($socket) && !$isSocketObject) {
            return;
        }
        $solTcp = defined('SOL_TCP') ? SOL_TCP : 6;
        $tcpNoDelay = defined('TCP_NODELAY') ? TCP_NODELAY : 1;
        $solSocket = defined('SOL_SOCKET') ? SOL_SOCKET : 1;
        $soKeepAlive = defined('SO_KEEPALIVE') ? SO_KEEPALIVE : 9;
        @socket_set_option($socket, $solTcp, $tcpNoDelay, 1);
        @socket_set_option($socket, $solSocket, $soKeepAlive, 1);
    } catch (Throwable $e) {
        // Low-latency tuning is best effort.
    }
}

$options = getopt('', ['session:']);
$sessionId = strtolower(trim((string) ($options['session'] ?? '')));
if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $sessionId)) {
    fwrite(STDERR, "Invalid session id\n");
    exit(2);
}

$meta = workerReadMeta($sessionId);
if (!is_array($meta)) {
    fwrite(STDERR, "Session metadata not found\n");
    exit(2);
}

$targetHost = trim((string) ($meta['target_host'] ?? '127.0.0.1'));
if ($targetHost === '') {
    $targetHost = '127.0.0.1';
}
$targetPort = (int) ($meta['target_port'] ?? 0);
if ($targetPort < 1 || $targetPort > 65535) {
    $meta['status'] = 'error';
    $meta['updated_at'] = workerNowIso();
    $meta['closed_at'] = workerNowIso();
    $meta['closed_reason'] = 'invalid_target_port';
    workerWriteMeta($sessionId, $meta);
    fwrite(STDERR, "Invalid target port\n");
    exit(1);
}

$target = sprintf('tcp://%s:%d', $targetHost, $targetPort);
$errno = 0;
$errstr = '';
$socket = @stream_socket_client($target, $errno, $errstr, 5, STREAM_CLIENT_CONNECT);
if ($socket === false) {
    $meta['status'] = 'error';
    $meta['updated_at'] = workerNowIso();
    $meta['closed_at'] = workerNowIso();
    $meta['closed_reason'] = 'connect_failed';
    $meta['worker_error'] = trim($errstr) !== '' ? trim($errstr) : ('connect_errno_' . $errno);
    workerWriteMeta($sessionId, $meta);
    fwrite(STDERR, "Connection failed: {$errstr} ({$errno})\n");
    exit(1);
}

@stream_set_blocking($socket, false);
workerEnableLowLatencySocket($socket);

$terminate = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, static function () use (&$terminate): void {
        $terminate = true;
    });
    pcntl_signal(SIGINT, static function () use (&$terminate): void {
        $terminate = true;
    });
}

$pid = function_exists('getmypid') ? ((int) getmypid()) : 0;
$meta['status'] = 'running';
$meta['worker_pid'] = $pid > 0 ? $pid : null;
$meta['worker_started_at'] = isset($meta['worker_started_at']) ? (string) $meta['worker_started_at'] : workerNowIso();
$meta['worker_last_seen_at'] = workerNowIso();
$meta['updated_at'] = workerNowIso();
$meta['closed_at'] = null;
$meta['closed_reason'] = null;
$meta['worker_error'] = null;
workerWriteMeta($sessionId, $meta);

$inputOffset = 0;
$bytesIn = (int) ($meta['bytes_in'] ?? 0);
$bytesOut = (int) ($meta['bytes_out'] ?? 0);
$lastActivity = time();
$lastHeartbeatWrite = 0;
$maxIdleSeconds = 1800;
$idleSleepUsec = 10000; // 10ms idle polling
$activeSleepUsec = 2000; // 2ms after activity bursts
$closeReason = 'worker_stopped';
$nodeConsole = strtolower(trim((string) ($meta['node_console'] ?? 'telnet')));
$telnetFilterEnabled = ($nodeConsole === '' || $nodeConsole === 'telnet');
$telnetState = workerTelnetStateInit();
$socketReplyBuffer = '';

while (true) {
    if ($terminate) {
        $closeReason = 'worker_terminated';
        break;
    }

    if (is_file(workerStopPath($sessionId))) {
        $stopReasonRaw = @file_get_contents(workerStopPath($sessionId));
        $stopReason = is_string($stopReasonRaw) ? trim($stopReasonRaw) : '';
        $closeReason = $stopReason !== '' ? $stopReason : 'client_closed';
        break;
    }

    $hadActivity = false;

    $readChunk = @fread($socket, 65536);
    if (is_string($readChunk) && $readChunk !== '') {
        $payload = $readChunk;
        if ($telnetFilterEnabled) {
            [$payload, $negReply] = workerTelnetConsumeChunk($readChunk, $telnetState);
            if ($negReply !== '') {
                $socketReplyBuffer .= $negReply;
            }
        }
        $written = workerAppendOut($sessionId, $payload);
        if ($written > 0) {
            $bytesOut += $written;
        }
        $hadActivity = true;
    } elseif ($readChunk === '' && @feof($socket)) {
        $closeReason = 'target_closed';
        break;
    }

    if ($socketReplyBuffer !== '') {
        $totalLen = strlen($socketReplyBuffer);
        $sent = 0;
        while ($sent < $totalLen) {
            $part = substr($socketReplyBuffer, $sent);
            $w = @fwrite($socket, $part);
            if (!is_int($w) || $w <= 0) {
                break;
            }
            $sent += $w;
        }
        if ($sent > 0) {
            $socketReplyBuffer = (string) substr($socketReplyBuffer, $sent);
            $bytesIn += $sent;
            $hadActivity = true;
        }
    }

    $inPath = workerInPath($sessionId);
    clearstatcache(true, $inPath);
    $inSize = @filesize($inPath);
    if ($socketReplyBuffer === '' && is_int($inSize) && $inSize > $inputOffset) {
        $readLen = min(65536, $inSize - $inputOffset);
        $queueFp = @fopen($inPath, 'rb');
        if ($queueFp !== false) {
            if (@fseek($queueFp, $inputOffset) === 0) {
                $queued = @fread($queueFp, $readLen);
                if (is_string($queued) && $queued !== '') {
                    $totalLen = strlen($queued);
                    $sent = 0;
                    while ($sent < $totalLen) {
                        $part = substr($queued, $sent);
                        $w = @fwrite($socket, $part);
                        if (!is_int($w) || $w <= 0) {
                            break;
                        }
                        $sent += $w;
                    }
                    if ($sent > 0) {
                        $inputOffset += $sent;
                        $bytesIn += $sent;
                        $hadActivity = true;
                    }
                }
            }
            @fclose($queueFp);
        }
    }

    if ($hadActivity) {
        $lastActivity = time();
    }

    $now = time();
    if (($now - $lastHeartbeatWrite) >= 2) {
        $meta = workerReadMeta($sessionId) ?: [];
        $meta['status'] = 'running';
        $meta['worker_pid'] = $pid > 0 ? $pid : null;
        $meta['worker_last_seen_at'] = workerNowIso();
        $meta['updated_at'] = workerNowIso();
        $meta['bytes_in'] = $bytesIn;
        $meta['bytes_out'] = $bytesOut;
        workerWriteMeta($sessionId, $meta);
        $lastHeartbeatWrite = $now;
    }

    if (($now - $lastActivity) > $maxIdleSeconds) {
        $closeReason = 'idle_timeout';
        break;
    }

    usleep($hadActivity ? $activeSleepUsec : $idleSleepUsec);
}

@fclose($socket);

$meta = workerReadMeta($sessionId) ?: [];
$meta['status'] = 'closed';
$meta['updated_at'] = workerNowIso();
$meta['closed_at'] = workerNowIso();
$meta['closed_reason'] = $closeReason;
$meta['worker_last_seen_at'] = workerNowIso();
$meta['bytes_in'] = $bytesIn;
$meta['bytes_out'] = $bytesOut;
workerWriteMeta($sessionId, $meta);

exit(0);
