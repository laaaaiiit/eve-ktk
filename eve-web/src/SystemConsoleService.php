<?php

declare(strict_types=1);

function v2SystemConsoleUploadsRoot(): string
{
    return v2ConsoleDataRoot() . '/host-uploads';
}

function v2SystemConsoleNowIso(): string
{
    return gmdate('c');
}

function v2SystemConsoleNormalizeUploadId(string $uploadId): string
{
    $normalized = strtolower(trim($uploadId));
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $normalized)) {
        return '';
    }
    return $normalized;
}

function v2SystemConsoleEnsureRoots(): void
{
    v2ConsoleEnsureRoots();
    $uploadsRoot = v2SystemConsoleUploadsRoot();
    if (!is_dir($uploadsRoot) && !@mkdir($uploadsRoot, 0770, true) && !is_dir($uploadsRoot)) {
        throw new RuntimeException('Failed to create uploads storage directory');
    }
}

function v2SystemConsoleUploadMetaPath(string $uploadId): string
{
    return v2SystemConsoleUploadsRoot() . '/' . $uploadId . '.json';
}

function v2SystemConsoleReadUploadMeta(string $uploadId): ?array
{
    $path = v2SystemConsoleUploadMetaPath($uploadId);
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

function v2SystemConsoleWriteUploadMeta(string $uploadId, array $meta): void
{
    $path = v2SystemConsoleUploadMetaPath($uploadId);
    $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(3));
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode upload metadata');
    }
    if (@file_put_contents($tmpPath, $json, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write upload metadata');
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Failed to finalize upload metadata');
    }
}

function v2SystemConsoleNormalizeAbsolutePath(string $path): string
{
    $path = trim($path);
    if ($path === '' || strpos($path, "\0") !== false || $path[0] !== '/') {
        return '';
    }
    if (preg_match('#(^|/)\.\.(?:/|$)#', $path)) {
        return '';
    }
    $normalized = preg_replace('#/+#', '/', $path);
    return is_string($normalized) ? $normalized : '';
}

function v2SystemConsoleAllowedRoots(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $raw = trim((string) getenv('EVE_VM_CONSOLE_ALLOWED_ROOTS'));
    if ($raw === '') {
        $raw = '/opt/unetlab,/root,/home,/tmp,/var/tmp';
    }
    $roots = preg_split('/[\s,]+/', $raw);
    if (!is_array($roots)) {
        $roots = [];
    }

    $result = [];
    foreach ($roots as $root) {
        $normalized = v2SystemConsoleNormalizeAbsolutePath((string) $root);
        if ($normalized === '') {
            continue;
        }
        $resolved = @realpath($normalized);
        if (is_string($resolved) && $resolved !== '') {
            $normalized = v2SystemConsoleNormalizeAbsolutePath($resolved);
        }
        if ($normalized === '' || $normalized === '/') {
            continue;
        }
        $result[$normalized] = true;
    }

    $cached = array_keys($result);
    sort($cached, SORT_STRING);
    return $cached;
}

function v2SystemConsolePathAllowed(string $path, bool $mustExist = false): bool
{
    $normalized = v2SystemConsoleNormalizeAbsolutePath($path);
    if ($normalized === '') {
        return false;
    }

    $effective = $normalized;
    $real = @realpath($normalized);
    if (is_string($real) && $real !== '') {
        $effective = v2SystemConsoleNormalizeAbsolutePath($real);
    } elseif ($mustExist) {
        return false;
    }
    if ($effective === '') {
        return false;
    }

    foreach (v2SystemConsoleAllowedRoots() as $root) {
        if ($effective === $root || strpos($effective, rtrim($root, '/') . '/') === 0) {
            return true;
        }
    }
    return false;
}

function v2SystemConsolePrivilegedPhpBinary(): string
{
    $candidates = ['/usr/bin/php', '/usr/bin/php8.2', '/usr/local/bin/php'];
    foreach ($candidates as $candidate) {
        if (@is_executable($candidate)) {
            return $candidate;
        }
    }
    return v2ConsolePhpBinary();
}

function v2SystemConsoleSpawnWorker(string $sessionId): array
{
    $script = dirname(__DIR__) . '/bin/run_host_console_session.php';
    if (!is_file($script)) {
        return ['pid' => 0, 'error' => 'worker_script_missing'];
    }

    $php = v2SystemConsolePrivilegedPhpBinary();
    $errPath = rtrim(sys_get_temp_dir(), '/') . '/eve-v2-host-console.' . bin2hex(random_bytes(4)) . '.err';
    $cmd = sprintf(
        'sudo -n %s %s --session=%s > /dev/null 2>%s < /dev/null & echo $!',
        escapeshellarg($php),
        escapeshellarg($script),
        escapeshellarg($sessionId),
        escapeshellarg($errPath)
    );
    $out = [];
    $rc = 1;
    @exec($cmd, $out, $rc);
    $spawnError = '';
    if (@is_file($errPath)) {
        $rawError = @file_get_contents($errPath);
        if (is_string($rawError)) {
            $spawnError = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[[:cntrl:]]+/', ' ', $rawError)));
        }
        @unlink($errPath);
    }

    if ($rc !== 0 || !isset($out[0])) {
        if ($spawnError === '') {
            $spawnError = 'spawn_command_failed';
        }
        return ['pid' => 0, 'error' => $spawnError];
    }

    $pidRaw = trim((string) $out[0]);
    if ($pidRaw === '' || !preg_match('/^[0-9]+$/', $pidRaw)) {
        return ['pid' => 0, 'error' => $spawnError !== '' ? $spawnError : 'invalid_worker_pid'];
    }

    return ['pid' => (int) $pidRaw, 'error' => $spawnError];
}

function v2SystemConsoleCloseConflictingSessions(array $viewer): void
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
        if (!hash_equals('system_host_console', (string) ($meta['session_type'] ?? ''))) {
            continue;
        }

        $status = strtolower(trim((string) ($meta['status'] ?? '')));
        if ($status === 'closed' || $status === 'error') {
            continue;
        }

        @file_put_contents(v2ConsoleSessionStopPath($sid), 'replaced_by_new_session');
        $meta['status'] = 'closing';
        $meta['updated_at'] = v2SystemConsoleNowIso();
        $meta['closed_reason'] = 'replaced_by_new_session';

        try {
            v2ConsoleWriteMeta($sid, $meta);
        } catch (Throwable $e) {
            // Ignore close-on-replace failures.
        }
    }
}

function v2SystemConsoleOpenSession(array $viewer): array
{
    v2SystemConsoleEnsureRoots();
    v2ConsoleGarbageCollect();
    v2SystemConsoleCloseConflictingSessions($viewer);

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

    $nowIso = v2SystemConsoleNowIso();
    $meta = [
        'session_id' => $sessionId,
        'session_type' => 'system_host_console',
        'status' => 'starting',
        'created_at' => $nowIso,
        'updated_at' => $nowIso,
        'closed_at' => null,
        'closed_reason' => null,
        'owner_user_id' => (string) ($viewer['id'] ?? ''),
        'owner_username' => (string) ($viewer['username'] ?? ''),
        'owner_role' => (string) ($viewer['role_name'] ?? $viewer['role'] ?? ''),
        'lab_id' => null,
        'node_id' => 'system-host',
        'node_name' => 'EVE Host VM',
        'node_console' => 'telnet',
        'transport' => 'pty-login',
        'target_host' => '',
        'target_port' => 0,
        'worker_pid' => null,
        'worker_started_at' => null,
        'worker_last_seen_at' => null,
        'worker_error' => null,
        'last_client_activity_at' => $nowIso,
        'bytes_in' => 0,
        'bytes_out' => 0,
    ];

    v2ConsoleWriteMeta($sessionId, $meta);

    $spawn = v2SystemConsoleSpawnWorker($sessionId);
    $pid = (int) ($spawn['pid'] ?? 0);
    $spawnError = trim((string) ($spawn['error'] ?? ''));
    if ($pid <= 0) {
        $meta['status'] = 'error';
        $meta['updated_at'] = v2SystemConsoleNowIso();
        $meta['closed_at'] = v2SystemConsoleNowIso();
        $meta['closed_reason'] = 'worker_spawn_failed';
        $meta['worker_error'] = $spawnError !== '' ? $spawnError : 'worker_spawn_failed';
        v2ConsoleWriteMeta($sessionId, $meta);
        $errMsg = $spawnError !== '' ? ('Failed to start system console worker: ' . $spawnError) : 'Failed to start system console worker';
        throw new RuntimeException($errMsg);
    }
    $meta['worker_pid'] = $pid;
    $meta['worker_started_at'] = v2SystemConsoleNowIso();
    $meta['updated_at'] = v2SystemConsoleNowIso();
    v2ConsoleWriteMeta($sessionId, $meta);

    return [
        'session_id' => $sessionId,
        'session_type' => 'system_host_console',
        'status' => 'starting',
        'node_name' => 'EVE Host VM',
        'console' => 'telnet',
        'created_at' => $nowIso,
    ];
}

function v2SystemConsoleInitUpload(array $viewer, string $destinationPath, int $totalBytes = 0, bool $overwrite = false): array
{
    v2SystemConsoleEnsureRoots();

    $destinationPath = v2SystemConsoleNormalizeAbsolutePath($destinationPath);
    if ($destinationPath === '') {
        throw new InvalidArgumentException('Invalid destination path');
    }
    if (!v2SystemConsolePathAllowed($destinationPath, false)) {
        throw new InvalidArgumentException('Invalid destination path');
    }

    $destDir = dirname($destinationPath);
    if (!is_dir($destDir)) {
        throw new RuntimeException('Destination directory does not exist');
    }

    if (is_file($destinationPath) && !$overwrite) {
        throw new RuntimeException('Destination file already exists');
    }

    $uploadId = v2ConsoleGenerateSessionId();
    $tmpPath = rtrim(v2SystemConsoleUploadsRoot(), '/') . '/' . $uploadId . '.part';
    if (@file_put_contents($tmpPath, '') === false) {
        throw new RuntimeException('Failed to initialize upload temporary file');
    }

    $nowIso = v2SystemConsoleNowIso();
    $meta = [
        'upload_id' => $uploadId,
        'owner_user_id' => (string) ($viewer['id'] ?? ''),
        'owner_username' => (string) ($viewer['username'] ?? ''),
        'destination_path' => $destinationPath,
        'tmp_path' => $tmpPath,
        'overwrite' => $overwrite,
        'status' => 'uploading',
        'created_at' => $nowIso,
        'updated_at' => $nowIso,
        'completed_at' => null,
        'total_bytes' => max(0, $totalBytes),
        'received_bytes' => 0,
    ];

    v2SystemConsoleWriteUploadMeta($uploadId, $meta);

    return [
        'upload_id' => $uploadId,
        'status' => 'uploading',
        'destination_path' => $destinationPath,
        'total_bytes' => (int) $meta['total_bytes'],
        'received_bytes' => 0,
    ];
}

function v2SystemConsoleRequireUploadAccess(array $viewer, string $uploadId): array
{
    $uploadId = v2SystemConsoleNormalizeUploadId($uploadId);
    if ($uploadId === '') {
        throw new InvalidArgumentException('Invalid upload id');
    }

    $meta = v2SystemConsoleReadUploadMeta($uploadId);
    if (!is_array($meta)) {
        throw new RuntimeException('Upload not found');
    }

    if (!viewerIsAdmin($viewer) && !hash_equals((string) ($meta['owner_user_id'] ?? ''), (string) ($viewer['id'] ?? ''))) {
        throw new RuntimeException('Forbidden');
    }

    return $meta;
}

function v2SystemConsoleFinalizeUploadPrivileged(string $tmpPath, string $destinationPath, bool $overwrite): bool
{
    $script = dirname(__DIR__) . '/bin/host_upload_finalize.php';
    if (!is_file($script)) {
        return false;
    }
    $php = v2SystemConsolePrivilegedPhpBinary();
    $cmd = sprintf(
        'sudo -n %s %s --tmp=%s --dest=%s --overwrite=%s',
        escapeshellarg($php),
        escapeshellarg($script),
        escapeshellarg($tmpPath),
        escapeshellarg($destinationPath),
        $overwrite ? '1' : '0'
    );
    $out = [];
    $rc = 1;
    @exec($cmd, $out, $rc);
    return $rc === 0;
}

function v2SystemConsoleUploadChunk(array $viewer, string $uploadId, int $offset): array
{
    $uploadId = v2SystemConsoleNormalizeUploadId($uploadId);
    if ($uploadId === '') {
        throw new InvalidArgumentException('Invalid upload id');
    }

    $meta = v2SystemConsoleRequireUploadAccess($viewer, $uploadId);
    if ((string) ($meta['status'] ?? '') !== 'uploading') {
        throw new RuntimeException('Upload is not active');
    }

    $tmpPath = (string) ($meta['tmp_path'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        throw new RuntimeException('Upload temporary file is missing');
    }

    clearstatcache(true, $tmpPath);
    $currentSize = @filesize($tmpPath);
    $currentSize = is_int($currentSize) ? $currentSize : 0;
    if ($offset < 0 || $offset !== $currentSize) {
        throw new RuntimeException('Invalid chunk offset');
    }

    $input = @fopen('php://input', 'rb');
    if ($input === false) {
        throw new RuntimeException('Failed to read request body');
    }

    $target = @fopen($tmpPath, 'ab');
    if ($target === false) {
        @fclose($input);
        throw new RuntimeException('Failed to open upload temporary file');
    }

    $written = 0;
    try {
        if (!@flock($target, LOCK_EX)) {
            throw new RuntimeException('Failed to lock upload file');
        }
        $copied = @stream_copy_to_stream($input, $target);
        if (!is_int($copied)) {
            throw new RuntimeException('Failed to write upload chunk');
        }
        $written = $copied;
        @fflush($target);
        @flock($target, LOCK_UN);
    } finally {
        @fclose($target);
        @fclose($input);
    }

    $meta['received_bytes'] = (int) ($meta['received_bytes'] ?? 0) + $written;
    $meta['updated_at'] = v2SystemConsoleNowIso();
    v2SystemConsoleWriteUploadMeta($uploadId, $meta);

    return [
        'upload_id' => $uploadId,
        'status' => 'uploading',
        'written' => $written,
        'received_bytes' => (int) ($meta['received_bytes'] ?? 0),
        'total_bytes' => (int) ($meta['total_bytes'] ?? 0),
    ];
}

function v2SystemConsoleCompleteUpload(array $viewer, string $uploadId): array
{
    $uploadId = v2SystemConsoleNormalizeUploadId($uploadId);
    if ($uploadId === '') {
        throw new InvalidArgumentException('Invalid upload id');
    }

    $meta = v2SystemConsoleRequireUploadAccess($viewer, $uploadId);
    if ((string) ($meta['status'] ?? '') !== 'uploading') {
        throw new RuntimeException('Upload is not active');
    }

    $tmpPath = (string) ($meta['tmp_path'] ?? '');
    $destinationPath = (string) ($meta['destination_path'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath) || $destinationPath === '') {
        throw new RuntimeException('Upload metadata is invalid');
    }
    if (!v2SystemConsolePathAllowed($destinationPath, false)) {
        throw new RuntimeException('Upload metadata is invalid');
    }

    $expectedTotal = (int) ($meta['total_bytes'] ?? 0);
    clearstatcache(true, $tmpPath);
    $tmpSize = @filesize($tmpPath);
    $tmpSize = is_int($tmpSize) ? $tmpSize : 0;
    if ($expectedTotal > 0 && $tmpSize !== $expectedTotal) {
        throw new RuntimeException('Uploaded size does not match expected size');
    }

    if (!@rename($tmpPath, $destinationPath)) {
        $ok = v2SystemConsoleFinalizeUploadPrivileged($tmpPath, $destinationPath, !empty($meta['overwrite']));
        if (!$ok) {
            throw new RuntimeException('Failed to move uploaded file to destination');
        }
    }

    $meta['status'] = 'completed';
    $meta['received_bytes'] = $tmpSize;
    $meta['completed_at'] = v2SystemConsoleNowIso();
    $meta['updated_at'] = v2SystemConsoleNowIso();
    v2SystemConsoleWriteUploadMeta($uploadId, $meta);

    return [
        'upload_id' => $uploadId,
        'status' => 'completed',
        'destination_path' => $destinationPath,
        'received_bytes' => $tmpSize,
        'total_bytes' => (int) ($meta['total_bytes'] ?? 0),
    ];
}

function v2SystemConsoleCancelUpload(array $viewer, string $uploadId): array
{
    $uploadId = v2SystemConsoleNormalizeUploadId($uploadId);
    if ($uploadId === '') {
        throw new InvalidArgumentException('Invalid upload id');
    }

    $meta = v2SystemConsoleRequireUploadAccess($viewer, $uploadId);
    $tmpPath = (string) ($meta['tmp_path'] ?? '');
    if ($tmpPath !== '' && is_file($tmpPath)) {
        @unlink($tmpPath);
    }

    $meta['status'] = 'cancelled';
    $meta['updated_at'] = v2SystemConsoleNowIso();
    v2SystemConsoleWriteUploadMeta($uploadId, $meta);

    return [
        'upload_id' => $uploadId,
        'status' => 'cancelled',
    ];
}

function v2SystemConsoleStreamDownload(string $absolutePath): void
{
    $path = v2SystemConsoleNormalizeAbsolutePath($absolutePath);
    if ($path === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid path';
        return;
    }
    if (!v2SystemConsolePathAllowed($path, true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access denied';
        return;
    }

    $isDirectReadable = is_file($path) && is_readable($path);

    $size = 0;
    $filename = basename($path);
    $streamViaHelper = false;
    if ($isDirectReadable) {
        clearstatcache(true, $path);
        $size = @filesize($path);
        $size = is_int($size) ? $size : 0;
    } else {
        $script = dirname(__DIR__) . '/bin/host_stream_download.php';
        $php = v2SystemConsolePrivilegedPhpBinary();
        $cmd = sprintf(
            'sudo -n %s %s --path=%s --meta=1',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($path)
        );
        $out = [];
        $rc = 1;
        @exec($cmd, $out, $rc);
        if ($rc !== 0 || empty($out)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'File not found';
            return;
        }
        $meta = json_decode(implode("\n", $out), true);
        if (!is_array($meta)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Failed to read file';
            return;
        }
        $size = isset($meta['size']) ? (int) $meta['size'] : 0;
        if (!empty($meta['name'])) {
            $filename = (string) $meta['name'];
        }
        $streamViaHelper = true;
    }

    $rangeHeader = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    $start = 0;
    $end = max(0, $size - 1);
    $partial = false;

    if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $m)) {
        $rawStart = $m[1];
        $rawEnd = $m[2];

        if ($rawStart === '' && $rawEnd !== '') {
            $suffix = (int) $rawEnd;
            if ($suffix > 0) {
                $start = max(0, $size - $suffix);
                $partial = true;
            }
        } else {
            $start = max(0, (int) $rawStart);
            if ($rawEnd !== '') {
                $end = min($end, (int) $rawEnd);
            }
            if ($start <= $end) {
                $partial = true;
            }
        }
    }

    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        return;
    }

    $length = ($end - $start) + 1;
    if ($partial) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    } else {
        http_response_code(200);
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    header('Cache-Control: no-store');

    if ($streamViaHelper) {
        $script = dirname(__DIR__) . '/bin/host_stream_download.php';
        $php = v2SystemConsolePrivilegedPhpBinary();
        $cmd = sprintf(
            'sudo -n %s %s --path=%s --offset=%d --length=%d',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($path),
            $start,
            $length
        );
        @passthru($cmd);
        return;
    }

    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        return;
    }

    @fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunkSize = (int) min(1048576, $remaining);
        $buf = @fread($fp, $chunkSize);
        if (!is_string($buf) || $buf === '') {
            break;
        }
        echo $buf;
        $remaining -= strlen($buf);
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
        if (connection_aborted()) {
            break;
        }
    }

    @fclose($fp);
}

function v2SystemConsoleListFilesPrivileged(string $absoluteDirPath, int $limit = 500): ?array
{
    $script = dirname(__DIR__) . '/bin/host_list_files.php';
    if (!is_file($script)) {
        return null;
    }
    $php = v2SystemConsolePrivilegedPhpBinary();
    $cmd = sprintf(
        'sudo -n %s %s --path=%s --limit=%d',
        escapeshellarg($php),
        escapeshellarg($script),
        escapeshellarg($absoluteDirPath),
        max(10, min(5000, $limit))
    );
    $out = [];
    $rc = 1;
    @exec($cmd, $out, $rc);
    if ($rc !== 0) {
        return null;
    }
    $raw = implode("\n", $out);
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['files']) || !is_array($decoded['files'])) {
        return null;
    }
    return $decoded;
}

function v2SystemConsoleListFiles(string $absoluteDirPath, int $limit = 500): array
{
    $path = v2SystemConsoleNormalizeAbsolutePath($absoluteDirPath);
    if ($path === '') {
        throw new InvalidArgumentException('Invalid path');
    }
    if (!v2SystemConsolePathAllowed($path, true)) {
        throw new InvalidArgumentException('Invalid path');
    }
    if (!is_dir($path)) {
        throw new RuntimeException('Directory not found');
    }

    $entries = @scandir($path);
    if (!is_array($entries)) {
        $privileged = v2SystemConsoleListFilesPrivileged($path, $limit);
        if (is_array($privileged)) {
            return $privileged;
        }
        throw new RuntimeException('Failed to read directory');
    }

    $result = [];
    foreach ($entries as $entry) {
        $name = (string) $entry;
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = rtrim($path, '/') . '/' . $name;
        if (!is_file($full) || !is_readable($full)) {
            continue;
        }
        $size = @filesize($full);
        $mtime = @filemtime($full);
        $result[] = [
            'name' => $name,
            'path' => $full,
            'size' => is_int($size) ? $size : 0,
            'mtime' => is_int($mtime) ? gmdate('c', $mtime) : null,
        ];
        if (count($result) >= max(10, min(5000, $limit))) {
            break;
        }
    }

    usort($result, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return [
        'directory' => $path,
        'files' => $result,
    ];
}
