#!/usr/bin/env php
<?php

declare(strict_types=1);

function hostWorkerNowIso(): string
{
    return gmdate('c');
}

function hostWorkerSessionDir(string $sessionId): string
{
    return '/opt/unetlab/data/v2-console/sessions/' . $sessionId;
}

function hostWorkerMetaPath(string $sessionId): string
{
    return hostWorkerSessionDir($sessionId) . '/meta.json';
}

function hostWorkerOutPath(string $sessionId): string
{
    return hostWorkerSessionDir($sessionId) . '/out.log';
}

function hostWorkerInPath(string $sessionId): string
{
    return hostWorkerSessionDir($sessionId) . '/in.queue';
}

function hostWorkerStopPath(string $sessionId): string
{
    return hostWorkerSessionDir($sessionId) . '/stop.flag';
}

function hostWorkerReadMeta(string $sessionId): ?array
{
    $path = hostWorkerMetaPath($sessionId);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $meta = json_decode($raw, true);
    return is_array($meta) ? $meta : null;
}

function hostWorkerWriteMeta(string $sessionId, array $meta): bool
{
    $path = hostWorkerMetaPath($sessionId);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(3));
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function hostWorkerAppendOut(string $sessionId, string $chunk): int
{
    if ($chunk === '') {
        return 0;
    }
    $written = @file_put_contents(hostWorkerOutPath($sessionId), $chunk, FILE_APPEND);
    return is_int($written) ? $written : 0;
}

function hostWorkerReadAppendChunk(string $path, int $offset, int $maxBytes = 131072): array
{
    if ($offset < 0) {
        $offset = 0;
    }
    if ($maxBytes < 1024) {
        $maxBytes = 1024;
    }

    clearstatcache(true, $path);
    $size = @filesize($path);
    if (!is_int($size) || $size <= $offset) {
        return ['', $offset];
    }

    $toRead = min($maxBytes, $size - $offset);
    if ($toRead <= 0) {
        return ['', $offset];
    }

    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        return ['', $offset];
    }
    $chunk = '';
    if (@fseek($fp, $offset) === 0) {
        $read = @fread($fp, $toRead);
        if (is_string($read)) {
            $chunk = $read;
        }
    }
    @fclose($fp);

    if ($chunk === '') {
        return ['', $offset];
    }
    return [$chunk, $offset + strlen($chunk)];
}

function hostWorkerSanitizeScriptChunk(string $chunk): string
{
    if ($chunk === '') {
        return '';
    }

    // Remove util-linux script metadata lines from browser stream.
    $chunk = (string) preg_replace('/^Script started on .*?(?:\r?\n|$)/mi', '', $chunk);
    $chunk = (string) preg_replace('/^Script done on .*?(?:\r?\n|$)/mi', '', $chunk);
    return $chunk;
}

$options = getopt('', ['session:']);
$sessionId = strtolower(trim((string) ($options['session'] ?? '')));
if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $sessionId)) {
    fwrite(STDERR, "Invalid session id\n");
    exit(2);
}

$meta = hostWorkerReadMeta($sessionId);
if (!is_array($meta)) {
    fwrite(STDERR, "Session metadata not found\n");
    exit(2);
}

$scriptBin = '/usr/bin/script';
if (!is_executable($scriptBin)) {
    $meta['status'] = 'error';
    $meta['updated_at'] = hostWorkerNowIso();
    $meta['closed_at'] = hostWorkerNowIso();
    $meta['closed_reason'] = 'script_binary_missing';
    $meta['worker_error'] = 'script_binary_missing';
    hostWorkerWriteMeta($sessionId, $meta);
    fwrite(STDERR, "Missing script binary\n");
    exit(1);
}

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$scriptCapturePath = hostWorkerSessionDir($sessionId) . '/script.log';
@file_put_contents($scriptCapturePath, '');
$cmd = [$scriptBin, '-qf', '-c', 'exec /bin/login', $scriptCapturePath];
$env = [
    'TERM' => 'xterm-256color',
    'COLORTERM' => 'truecolor',
    'LANG' => 'C.UTF-8',
    'LC_ALL' => 'C.UTF-8',
];
$proc = @proc_open($cmd, $descriptors, $pipes, null, $env, ['bypass_shell' => true]);
if (!is_resource($proc)) {
    $meta['status'] = 'error';
    $meta['updated_at'] = hostWorkerNowIso();
    $meta['closed_at'] = hostWorkerNowIso();
    $meta['closed_reason'] = 'pty_start_failed';
    $meta['worker_error'] = 'pty_start_failed';
    hostWorkerWriteMeta($sessionId, $meta);
    fwrite(STDERR, "Failed to start PTY process\n");
    exit(1);
}

$stdin = $pipes[0] ?? null;
$stdout = $pipes[1] ?? null;
$stderr = $pipes[2] ?? null;
if (!is_resource($stdin) || !is_resource($stdout) || !is_resource($stderr)) {
    @proc_terminate($proc);
    $meta['status'] = 'error';
    $meta['updated_at'] = hostWorkerNowIso();
    $meta['closed_at'] = hostWorkerNowIso();
    $meta['closed_reason'] = 'pty_pipe_failed';
    $meta['worker_error'] = 'pty_pipe_failed';
    hostWorkerWriteMeta($sessionId, $meta);
    exit(1);
}

@stream_set_blocking($stdin, false);
@stream_set_blocking($stdout, false);
@stream_set_blocking($stderr, false);
@stream_set_write_buffer($stdin, 0);

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
$meta['worker_started_at'] = $meta['worker_started_at'] ?? hostWorkerNowIso();
$meta['worker_last_seen_at'] = hostWorkerNowIso();
$meta['updated_at'] = hostWorkerNowIso();
$meta['closed_at'] = null;
$meta['closed_reason'] = null;
$meta['worker_error'] = null;
hostWorkerWriteMeta($sessionId, $meta);

$inputOffset = 0;
$bytesIn = (int) ($meta['bytes_in'] ?? 0);
$bytesOut = (int) ($meta['bytes_out'] ?? 0);
$lastActivity = time();
$lastHeartbeatWrite = 0;
$maxIdleSeconds = 21600;
$closeReason = 'worker_stopped';
$targetProducedOutput = false;
$noOutputHintShown = false;
$workerStartedAt = microtime(true);
$scriptCaptureOffset = 0;

// Wake login prompt proactively on hosts where first prompt is delayed/suppressed.
$bootWake = @fwrite($stdin, "\r");
if (is_int($bootWake) && $bootWake > 0) {
    $bytesIn += $bootWake;
    $lastActivity = time();
}

while (true) {
    if ($terminate) {
        $closeReason = 'worker_terminated';
        break;
    }

    if (is_file(hostWorkerStopPath($sessionId))) {
        $stopReasonRaw = @file_get_contents(hostWorkerStopPath($sessionId));
        $stopReason = is_string($stopReasonRaw) ? trim($stopReasonRaw) : '';
        $closeReason = $stopReason !== '' ? $stopReason : 'client_closed';
        break;
    }

    $hadActivity = false;

    $readOut = @fread($stdout, 65536);
    if (is_string($readOut) && $readOut !== '') {
        // Keep stdout drained to avoid blocking, but render from script transcript only.
        $hadActivity = true;
    }

    $readErr = @fread($stderr, 65536);
    if (is_string($readErr) && $readErr !== '') {
        // Keep stderr drained to avoid blocking, but render from script transcript only.
        $hadActivity = true;
    }

    [$capturedChunk, $scriptCaptureOffset] = hostWorkerReadAppendChunk($scriptCapturePath, $scriptCaptureOffset);
    if ($capturedChunk !== '') {
        $sanitizedChunk = hostWorkerSanitizeScriptChunk($capturedChunk);
        $written = hostWorkerAppendOut($sessionId, $sanitizedChunk);
        if ($written > 0) {
            $bytesOut += $written;
            $hadActivity = true;
        }
        if (preg_match('/[^\r\n\t ]/', $sanitizedChunk) === 1) {
            $targetProducedOutput = true;
        }
    }

    $status = @proc_get_status($proc);
    if (!is_array($status) || empty($status['running'])) {
        $closeReason = 'target_closed';
        break;
    }

    // Some Debian hosts do not render login prompt immediately in browser stream.
    // Provide a visible hint so the page is not perceived as frozen.
    if (!$targetProducedOutput && !$noOutputHintShown && (microtime(true) - $workerStartedAt) >= 2.0) {
        $hint = "[system-console] Waiting for login prompt. Press Enter if screen is empty.\r\n";
        $written = hostWorkerAppendOut($sessionId, $hint);
        if ($written > 0) {
            $bytesOut += $written;
            $hadActivity = true;
        }
        $noOutputHintShown = true;
    }

    $inPath = hostWorkerInPath($sessionId);
    clearstatcache(true, $inPath);
    $inSize = @filesize($inPath);
    if (is_int($inSize) && $inSize > $inputOffset) {
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
                        $w = @fwrite($stdin, $part);
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
        $meta = hostWorkerReadMeta($sessionId) ?: [];
        $meta['status'] = 'running';
        $meta['worker_pid'] = $pid > 0 ? $pid : null;
        $meta['worker_last_seen_at'] = hostWorkerNowIso();
        $meta['updated_at'] = hostWorkerNowIso();
        $meta['bytes_in'] = $bytesIn;
        $meta['bytes_out'] = $bytesOut;
        hostWorkerWriteMeta($sessionId, $meta);
        $lastHeartbeatWrite = $now;
    }

    if (($now - $lastActivity) > $maxIdleSeconds) {
        $closeReason = 'idle_timeout';
        break;
    }

    usleep(50000);
}

@proc_terminate($proc);
@fclose($stdin);
@fclose($stdout);
@fclose($stderr);
@proc_close($proc);

[$tailChunk, $scriptCaptureOffset] = hostWorkerReadAppendChunk($scriptCapturePath, $scriptCaptureOffset);
if ($tailChunk !== '') {
    $tailChunk = hostWorkerSanitizeScriptChunk($tailChunk);
    $written = hostWorkerAppendOut($sessionId, $tailChunk);
    if ($written > 0) {
        $bytesOut += $written;
    }
}

$meta = hostWorkerReadMeta($sessionId) ?: [];
$meta['status'] = 'closed';
$meta['updated_at'] = hostWorkerNowIso();
$meta['closed_at'] = hostWorkerNowIso();
$meta['closed_reason'] = $closeReason;
$meta['worker_last_seen_at'] = hostWorkerNowIso();
$meta['bytes_in'] = $bytesIn;
$meta['bytes_out'] = $bytesOut;
hostWorkerWriteMeta($sessionId, $meta);

exit(0);
