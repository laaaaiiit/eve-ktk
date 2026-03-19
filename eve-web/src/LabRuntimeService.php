<?php

declare(strict_types=1);

function v2RuntimeRootDir(): string
{
    return '/opt/unetlab/data/v2-runtime';
}

function normalizeRuntimeOwnerSegment(string $ownerUserId): string
{
    $ownerUserId = trim($ownerUserId);
    if ($ownerUserId === '') {
        return '';
    }
    $ownerUserId = preg_replace('/[^a-zA-Z0-9._-]/', '_', $ownerUserId);
    if (!is_string($ownerUserId)) {
        return '';
    }
    $ownerUserId = trim($ownerUserId, '._-');
    return $ownerUserId;
}

function v2RuntimeLabsRootDir(string $ownerUserId = ''): string
{
    $ownerSegment = normalizeRuntimeOwnerSegment($ownerUserId);
    if ($ownerSegment === '') {
        return v2RuntimeRootDir() . '/labs';
    }
    return v2RuntimeRootDir() . '/' . $ownerSegment . '/labs';
}

function v2RuntimeLabDir(string $labId, string $ownerUserId = ''): string
{
    return rtrim(v2RuntimeLabsRootDir($ownerUserId), '/') . '/' . $labId;
}

function v2RuntimeNodeDir(string $labId, string $nodeId, string $ownerUserId = ''): string
{
    return v2RuntimeLabDir($labId, $ownerUserId) . '/nodes/' . $nodeId;
}

function v2RuntimeNodeLogPath(string $labId, string $nodeId, string $ownerUserId = ''): string
{
    return v2RuntimeNodeDir($labId, $nodeId, $ownerUserId) . '/qemu.log';
}

function v2RuntimeNodePidPath(string $labId, string $nodeId, string $ownerUserId = ''): string
{
    return v2RuntimeNodeDir($labId, $nodeId, $ownerUserId) . '/qemu.pid';
}

function ensureRuntimeDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create runtime directory: ' . $path);
    }
}

function ensureLabRuntimeDirs(string $labId, string $nodeId, string $ownerUserId = ''): void
{
    ensureRuntimeDirectory(v2RuntimeRootDir());
    ensureRuntimeDirectory(v2RuntimeLabsRootDir($ownerUserId));
    ensureRuntimeDirectory(v2RuntimeLabDir($labId, $ownerUserId));
    ensureRuntimeDirectory(v2RuntimeLabDir($labId, $ownerUserId) . '/nodes');
    ensureRuntimeDirectory(v2RuntimeNodeDir($labId, $nodeId, $ownerUserId));
}

function runtimeNodeLogPathFromDir(string $nodeDir): string
{
    return runtimeNodeLogPathForType($nodeDir, 'qemu');
}

function runtimeNodePidPathFromDir(string $nodeDir): string
{
    return runtimeNodePidPathForType($nodeDir, 'qemu');
}

function runtimeNodeTypeTag(string $nodeType): string
{
    $nodeType = strtolower(trim($nodeType));
    if ($nodeType === '' || preg_match('/^[a-z0-9_-]+$/', $nodeType) !== 1) {
        return 'node';
    }
    return $nodeType;
}

function runtimeNodeLogPathForType(string $nodeDir, string $nodeType): string
{
    return rtrim($nodeDir, '/') . '/' . runtimeNodeTypeTag($nodeType) . '.log';
}

function runtimeNodePidPathForType(string $nodeDir, string $nodeType): string
{
    return rtrim($nodeDir, '/') . '/' . runtimeNodeTypeTag($nodeType) . '.pid';
}

function runtimeQmpSocketPath(string $nodeId): string
{
    $clean = strtolower(trim($nodeId));
    $clean = preg_replace('/[^a-z0-9-]/', '', $clean);
    if (!is_string($clean) || $clean === '') {
        $clean = substr(hash('sha256', $nodeId), 0, 24);
    }
    return '/tmp/eve-v2-qmp-' . $clean . '.sock';
}

function runtimeQgaSocketPath(string $nodeId): string
{
    $clean = strtolower(trim($nodeId));
    $clean = preg_replace('/[^a-z0-9-]/', '', $clean);
    if (!is_string($clean) || $clean === '') {
        $clean = substr(hash('sha256', $nodeId), 0, 24);
    }
    return '/tmp/eve-v2-qga-' . $clean . '.sock';
}

function runtimeCleanupQmpSocket(string $nodeId): void
{
    $path = runtimeQmpSocketPath($nodeId);
    if ((is_file($path) || is_link($path)) && !@unlink($path)) {
        // Ignore unlink errors for stale sockets.
    }
}

function runtimeCleanupQgaSocket(string $nodeId): void
{
    $path = runtimeQgaSocketPath($nodeId);
    if ((is_file($path) || is_link($path)) && !@unlink($path)) {
        // Ignore unlink errors for stale sockets.
    }
}

function runtimeEnsureUnixSocketAccessible(string $path, int $waitMs = 3000): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    $deadline = microtime(true) + (max(200, $waitMs) / 1000.0);
    while (microtime(true) < $deadline) {
        clearstatcache(true, $path);
        if (@file_exists($path)) {
            @chmod($path, 0666);
            clearstatcache(true, $path);
            $perms = @fileperms($path);
            if (is_int($perms) && (($perms & 0006) === 0006)) {
                return true;
            }
            if (@is_writable($path)) {
                return true;
            }
        }
        usleep(100000);
    }
    return false;
}

function runtimeQemuLooksLikeLinuxNode(array $ctx): bool
{
    $fingerprint = strtolower(trim(implode(' ', [
        (string) ($ctx['template'] ?? ''),
        (string) ($ctx['image'] ?? ''),
        (string) ($ctx['name'] ?? ''),
        (string) ($ctx['node_type'] ?? ''),
    ])));
    if ($fingerprint === '') {
        return false;
    }

    return preg_match('/\b(ubuntu|debian|centos|fedora|alpine|linux|rhel|rocky|opensuse|suse|kali|mint)\b/i', $fingerprint) === 1;
}

function runtimeQemuLooksLikeWindowsNode(array $ctx): bool
{
    $fingerprint = strtolower(trim(implode(' ', [
        (string) ($ctx['template'] ?? ''),
        (string) ($ctx['image'] ?? ''),
        (string) ($ctx['name'] ?? ''),
        (string) ($ctx['node_type'] ?? ''),
    ])));
    if ($fingerprint === '') {
        return false;
    }

    return preg_match('/\b(windows|win|win10|win11|win7|win8|w2k|winserver|server2012|server2016|server2019|server2022|microsoft)\b/i', $fingerprint) === 1;
}

function runtimeIsCloudNetworkType(string $networkType): bool
{
    $networkType = strtolower(trim($networkType));
    if ($networkType === 'cloud') {
        return true;
    }
    return preg_match('/^pnet[0-9]+$/', $networkType) === 1;
}

function runtimeQemuOptionsHasGuestAgent(string $qemuOptions): bool
{
    $text = strtolower(trim($qemuOptions));
    if ($text === '') {
        return false;
    }
    return strpos($text, 'org.qemu.guest_agent.0') !== false
        || strpos($text, 'virtserialport') !== false
        || strpos($text, 'qga') !== false;
}

function runtimeFastStopSettings(PDO $db): array
{
    try {
        $stmt = $db->prepare(
            "SELECT fast_stop_vios
             FROM task_queue_settings
             WHERE id = 1
             LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['vios' => true];
        }
        return [
            'vios' => !array_key_exists('fast_stop_vios', $row) || (bool) $row['fast_stop_vios'],
        ];
    } catch (Throwable $e) {
        return ['vios' => true];
    }
}

function resolveLabNodeRuntimeDir(string $labId, string $nodeId, string $ownerUserId = ''): string
{
    $ownerSegment = normalizeRuntimeOwnerSegment($ownerUserId);
    if ($ownerSegment === '') {
        ensureLabRuntimeDirs($labId, $nodeId, '');
        return v2RuntimeNodeDir($labId, $nodeId, '');
    }

    $targetNodeDir = v2RuntimeNodeDir($labId, $nodeId, $ownerSegment);
    ensureLabRuntimeDirs($labId, $nodeId, $ownerSegment);
    return $targetNodeDir;
}

function runtimePathWithinRoot(string $path): bool
{
    $root = rtrim(v2RuntimeRootDir(), '/');
    $path = rtrim($path, '/');
    if ($root === '' || $path === '') {
        return false;
    }
    if ($path === $root) {
        return false;
    }
    return strpos($path, $root . '/') === 0;
}

function runtimeDeleteDirectoryTree(string $path): bool
{
    $path = rtrim($path, '/');
    if ($path === '' || !is_dir($path) || !runtimePathWithinRoot($path)) {
        return false;
    }

    try {
        $it = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $item) {
            /** @var SplFileInfo $item */
            $target = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($target);
            } else {
                @unlink($target);
            }
        }
        return @rmdir($path);
    } catch (Throwable $e) {
        return false;
    }
}

function labDeletionGuardKeyPart(string $labId, int $offset): int
{
    $hash = md5(strtolower(trim($labId)));
    if (!is_string($hash) || strlen($hash) < ($offset + 8)) {
        return 0;
    }
    $hex = substr($hash, $offset, 8);
    $value = hexdec($hex);
    if (!is_int($value) && !is_float($value)) {
        return 0;
    }
    $intValue = (int) $value;
    if ($intValue > 2147483647) {
        $intValue -= 4294967296;
    }
    return $intValue;
}

function labDeletionGuardKeys(string $labId): array
{
    $labId = trim($labId);
    if ($labId === '') {
        return [0, 0];
    }
    return [
        labDeletionGuardKeyPart($labId, 0),
        labDeletionGuardKeyPart($labId, 8),
    ];
}

function acquireLabDeletionGuard(PDO $db, string $labId): bool
{
    [$keyA, $keyB] = labDeletionGuardKeys($labId);
    $stmt = $db->prepare('SELECT pg_try_advisory_lock(:key_a, :key_b)');
    $stmt->bindValue(':key_a', $keyA, PDO::PARAM_INT);
    $stmt->bindValue(':key_b', $keyB, PDO::PARAM_INT);
    $stmt->execute();
    return !empty($stmt->fetchColumn());
}

function releaseLabDeletionGuard(PDO $db, string $labId): void
{
    [$keyA, $keyB] = labDeletionGuardKeys($labId);
    $stmt = $db->prepare('SELECT pg_advisory_unlock(:key_a, :key_b)');
    $stmt->bindValue(':key_a', $keyA, PDO::PARAM_INT);
    $stmt->bindValue(':key_b', $keyB, PDO::PARAM_INT);
    $stmt->execute();
}

function isLabDeletionGuardActive(PDO $db, string $labId): bool
{
    [$keyA, $keyB] = labDeletionGuardKeys($labId);
    $stmt = $db->prepare('SELECT pg_try_advisory_lock(:key_a, :key_b)');
    $stmt->bindValue(':key_a', $keyA, PDO::PARAM_INT);
    $stmt->bindValue(':key_b', $keyB, PDO::PARAM_INT);
    $stmt->execute();
    $acquired = !empty($stmt->fetchColumn());
    if ($acquired) {
        $unlock = $db->prepare('SELECT pg_advisory_unlock(:key_a, :key_b)');
        $unlock->bindValue(':key_a', $keyA, PDO::PARAM_INT);
        $unlock->bindValue(':key_b', $keyB, PDO::PARAM_INT);
        $unlock->execute();
        return false;
    }
    return true;
}

function cleanupLabNodeRuntimeArtifacts(string $labId, string $nodeId, string $ownerUserId = ''): array
{
    $deleted = [];

    $paths = [];
    $ownerSegment = normalizeRuntimeOwnerSegment($ownerUserId);
    if ($ownerSegment !== '') {
        $paths[] = v2RuntimeNodeDir($labId, $nodeId, $ownerSegment);
    }
    $paths[] = v2RuntimeNodeDir($labId, $nodeId, '');
    $paths = array_values(array_unique(array_filter($paths, static function ($v) {
        return is_string($v) && $v !== '';
    })));

    foreach ($paths as $path) {
        if (runtimeDeleteDirectoryTree($path)) {
            $deleted[] = $path;
        }
    }

    return $deleted;
}

function normalizeQemuArch(string $arch): string
{
    $arch = strtolower(trim($arch));
    if (!preg_match('/^[a-z0-9_+-]{2,32}$/', $arch)) {
        return 'x86_64';
    }
    return $arch;
}

function resolveQemuBinary(string $arch, string $version = ''): string
{
    $arch = normalizeQemuArch($arch);
    $candidates = [];

    $version = trim($version);
    if ($version !== '') {
        $candidates[] = '/opt/qemu-' . $version . '/bin/qemu-system-' . $arch;
    }

    $candidates[] = '/opt/qemu/bin/qemu-system-' . $arch;
    $candidates[] = '/usr/bin/qemu-system-' . $arch;

    foreach ($candidates as $candidate) {
        if (@is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('QEMU binary not found for arch: ' . $arch);
}

function splitShellArgs(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $len = strlen($raw);
    $tokens = [];
    $buf = '';
    $quote = '';

    for ($i = 0; $i < $len; $i++) {
        $ch = $raw[$i];

        if ($quote === '') {
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                if ($buf !== '') {
                    $tokens[] = $buf;
                    $buf = '';
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }

            if ($ch === '\\' && $i + 1 < $len) {
                $i++;
                $buf .= $raw[$i];
                continue;
            }

            $buf .= $ch;
            continue;
        }

        if ($ch === $quote) {
            $quote = '';
            continue;
        }

        if ($ch === '\\' && $quote === '"' && $i + 1 < $len) {
            $i++;
            $buf .= $raw[$i];
            continue;
        }

        $buf .= $ch;
    }

    if ($buf !== '') {
        $tokens[] = $buf;
    }

    return $tokens;
}

function normalizeQemuOptionTokens(array $tokens): array
{
    $normalized = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = (string) $tokens[$i];
        $next = ($i + 1 < $count) ? trim((string) $tokens[$i + 1]) : '';
        $nextLower = strtolower($next);

        if ($token === '-usbdevice') {
            if ($nextLower === 'tablet') {
                // Keep legacy syntax for best compatibility with old template sets.
                $normalized[] = '-usbdevice';
                $normalized[] = 'tablet';
                $i++;
                continue;
            }
        }

        // Runtime always starts QEMU with -daemonize, so stdio-backed serial/monitor
        // channels from legacy templates must be dropped.
        if ($token === '-serial') {
            if ($nextLower === 'stdio' || $nextLower === 'mon:stdio' || $nextLower === 'stdio,server,nowait') {
                $i++;
                continue;
            }
        }

        if ($token === '-monitor') {
            if ($nextLower === 'stdio') {
                $i++;
                continue;
            }
        }

        $normalized[] = $token;
    }

    return $normalized;
}

function runtimePidAlive(int $pid): bool
{
    if ($pid <= 1) {
        return false;
    }

    if (function_exists('posix_kill')) {
        if (@posix_kill($pid, 0)) {
            return true;
        }
    }

    return is_dir('/proc/' . $pid);
}

function runtimeReadProcEnviron(int $pid): string
{
    if ($pid <= 1) {
        return '';
    }

    $path = '/proc/' . $pid . '/environ';
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $raw = @file_get_contents($path);
    return is_string($raw) ? $raw : '';
}

function runtimePidBelongsToNode(int $pid, string $labId, string $nodeId): bool
{
    if (!runtimePidAlive($pid)) {
        return false;
    }

    $env = runtimeReadProcEnviron($pid);
    if ($env === '') {
        return false;
    }

    return strpos($env, 'EVE_V2_LAB_UUID=' . $labId) !== false
        && strpos($env, 'EVE_V2_NODE_UUID=' . $nodeId) !== false;
}

function runtimeTailText(string $filePath, int $lines = 20): string
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return '';
    }

    $content = @file_get_contents($filePath);
    if (!is_string($content) || $content === '') {
        return '';
    }

    $rows = preg_split('/\R/u', $content);
    if (!is_array($rows) || empty($rows)) {
        return '';
    }

    $slice = array_slice($rows, -max(1, $lines));
    return trim(implode("\n", $slice));
}

function runtimeReadPidfile(string $pidPath): int
{
    if (!is_file($pidPath) || !is_readable($pidPath)) {
        return 0;
    }
    $raw = trim((string) @file_get_contents($pidPath));
    return ctype_digit($raw) ? (int) $raw : 0;
}

function runtimeReadProcCmdline(int $pid): string
{
    if ($pid <= 1) {
        return '';
    }

    $path = '/proc/' . $pid . '/cmdline';
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    return trim(str_replace("\0", ' ', $raw));
}

function runtimeReadProcCmdlineArgs(int $pid): array
{
    if ($pid <= 1) {
        return [];
    }

    $path = '/proc/' . $pid . '/cmdline';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $parts = explode("\0", $raw);
    $args = [];
    foreach ($parts as $part) {
        if ($part !== '') {
            $args[] = (string) $part;
        }
    }
    return $args;
}

function runtimeCmdlineHasArgPair(array $args, string $flag, string $value): bool
{
    $flag = trim($flag);
    if ($flag === '' || $value === '') {
        return false;
    }

    $count = count($args);
    for ($i = 0; $i < $count; $i++) {
        $arg = (string) $args[$i];
        if ($arg !== $flag) {
            continue;
        }
        if ($i + 1 >= $count) {
            return false;
        }
        return (string) $args[$i + 1] === $value;
    }
    return false;
}

function runtimeCmdlineGetArgValue(array $args, string $flag): ?string
{
    $flag = trim($flag);
    if ($flag === '') {
        return null;
    }

    $count = count($args);
    for ($i = 0; $i < $count; $i++) {
        if ((string) $args[$i] !== $flag) {
            continue;
        }
        if ($i + 1 >= $count) {
            return null;
        }
        $value = trim((string) $args[$i + 1]);
        return $value === '' ? null : $value;
    }
    return null;
}

function runtimePidOwnerUsername(int $pid): string
{
    if ($pid <= 1) {
        return '';
    }
    $statusPath = '/proc/' . $pid . '/status';
    if (!is_file($statusPath) || !is_readable($statusPath)) {
        return '';
    }
    $status = @file_get_contents($statusPath);
    if (!is_string($status) || $status === '') {
        return '';
    }
    if (preg_match('/^Uid:\s+([0-9]+)/m', $status, $m) !== 1) {
        return '';
    }
    $uid = (int) ($m[1] ?? -1);
    if ($uid < 0) {
        return '';
    }
    if (function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid($uid);
        if (is_array($pw)) {
            $name = trim((string) ($pw['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }
    return '';
}

function runtimeReadProcExeBasename(int $pid): string
{
    if ($pid <= 1) {
        return '';
    }

    $path = '/proc/' . $pid . '/exe';
    if (!is_link($path)) {
        return '';
    }

    $target = @readlink($path);
    if (!is_string($target) || $target === '') {
        return '';
    }

    return strtolower((string) basename($target));
}

function runtimeIsShellProcess(int $pid): bool
{
    return in_array(runtimeReadProcExeBasename($pid), ['bash', 'sh', 'dash'], true);
}

function runtimeListNodePidsByEnv(string $labId, string $nodeId, array $cmdlineHints = [], bool $skipShellProcesses = true): array
{
    $hints = [];
    foreach ($cmdlineHints as $hint) {
        $hint = strtolower(trim((string) $hint));
        if ($hint !== '') {
            $hints[] = $hint;
        }
    }

    $found = [];
    foreach (scandir('/proc') ?: [] as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $pid = (int) $entry;
        if ($pid <= 1) {
            continue;
        }
        if (!runtimePidBelongsToNode($pid, $labId, $nodeId)) {
            continue;
        }

        if ($skipShellProcesses && runtimeIsShellProcess($pid)) {
            continue;
        }

        if (!empty($hints)) {
            $cmdline = strtolower(runtimeReadProcCmdline($pid));
            if ($cmdline === '') {
                continue;
            }
            $matched = false;
            foreach ($hints as $hint) {
                if (strpos($cmdline, $hint) !== false) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
        }

        $found[] = $pid;
    }

    if (empty($found)) {
        return [];
    }

    sort($found, SORT_NUMERIC);
    return array_values(array_unique($found));
}

function runtimeFindNodePidByEnv(string $labId, string $nodeId, array $cmdlineHints = [], bool $skipShellProcesses = true): int
{
    $pids = runtimeListNodePidsByEnv($labId, $nodeId, $cmdlineHints, $skipShellProcesses);
    if (empty($pids)) {
        return 0;
    }
    return (int) $pids[0];
}

function runtimePidLooksLikeQemu(int $pid): bool
{
    if ($pid <= 1 || !runtimePidAlive($pid)) {
        return false;
    }
    $cmdline = strtolower(runtimeReadProcCmdline($pid));
    if ($cmdline === '') {
        return false;
    }
    return strpos($cmdline, 'qemu-system-') !== false;
}

function runtimePidMatchesQemuNodeUuid(int $pid, string $nodeId): bool
{
    $nodeId = strtolower(trim($nodeId));
    if ($nodeId === '' || !runtimePidLooksLikeQemu($pid)) {
        return false;
    }
    $args = runtimeReadProcCmdlineArgs($pid);
    if (empty($args)) {
        return false;
    }
    return runtimeCmdlineHasArgPair($args, '-uuid', $nodeId);
}

function runtimeListQemuPidsByNodeUuid(string $nodeId): array
{
    $nodeId = strtolower(trim($nodeId));
    if ($nodeId === '') {
        return [];
    }

    $found = [];
    foreach (scandir('/proc') ?: [] as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $pid = (int) $entry;
        if ($pid <= 1) {
            continue;
        }
        if (runtimePidMatchesQemuNodeUuid($pid, $nodeId)) {
            $found[] = $pid;
        }
    }

    if (empty($found)) {
        return [];
    }

    sort($found, SORT_NUMERIC);
    return array_values(array_unique($found));
}

function runtimeResolveQemuPidByNodeUuid(string $nodeId, int $preferredPid = 0, int $attempts = 30): int
{
    $attempts = max(1, $attempts);
    for ($i = 0; $i < $attempts; $i++) {
        $pids = runtimeListQemuPidsByNodeUuid($nodeId);
        if (!empty($pids)) {
            if ($preferredPid > 1 && in_array($preferredPid, $pids, true)) {
                return $preferredPid;
            }
            return (int) $pids[count($pids) - 1];
        }
        usleep(100000);
    }

    return 0;
}

function runtimePruneExtraQemuPids(string $nodeId, int $keepPid): void
{
    $keepPid = (int) $keepPid;
    if ($keepPid <= 1) {
        return;
    }

    $pids = runtimeListQemuPidsByNodeUuid($nodeId);
    if (count($pids) <= 1) {
        return;
    }

    $kill = [];
    foreach ($pids as $pid) {
        $pid = (int) $pid;
        if ($pid > 1 && $pid !== $keepPid) {
            $kill[] = $pid;
        }
    }
    if (!empty($kill)) {
        runtimeTerminateNodePids($kill, 3.0);
    }
}

function runtimePidLooksLikeVpcs(int $pid): bool
{
    if ($pid <= 1 || !runtimePidAlive($pid)) {
        return false;
    }
    $cmdline = strtolower(runtimeReadProcCmdline($pid));
    if ($cmdline === '') {
        return false;
    }
    return strpos($cmdline, '/opt/vpcsu/bin/vpcs') !== false
        || strpos($cmdline, ' vpcs') !== false;
}

function runtimePidMatchesVpcsConsolePort(int $pid, ?int $consolePort): bool
{
    if (!runtimePidLooksLikeVpcs($pid) || $consolePort === null || $consolePort <= 0) {
        return false;
    }
    $args = runtimeReadProcCmdlineArgs($pid);
    if (empty($args)) {
        return false;
    }
    return runtimeCmdlineHasArgPair($args, '-p', (string) $consolePort);
}

function runtimeFindVpcsPidByConsolePort(?int $consolePort): int
{
    if ($consolePort === null || $consolePort <= 0) {
        return 0;
    }

    foreach (scandir('/proc') ?: [] as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $pid = (int) $entry;
        if ($pid <= 1) {
            continue;
        }
        if (runtimePidMatchesVpcsConsolePort($pid, $consolePort)) {
            return $pid;
        }
    }

    return 0;
}

function runtimeTerminateNodePids(array $pids, float $termTimeoutSec = 8.0): array
{
    $targets = [];
    foreach ($pids as $pid) {
        $pid = (int) $pid;
        if ($pid > 1) {
            $targets[$pid] = true;
        }
    }
    $targets = array_keys($targets);

    if (empty($targets) || !function_exists('posix_kill')) {
        return [
            'forced' => false,
            'alive_after' => [],
        ];
    }

    $sigTerm = defined('SIGTERM') ? (int) constant('SIGTERM') : 15;
    $sigKill = defined('SIGKILL') ? (int) constant('SIGKILL') : 9;

    foreach ($targets as $pid) {
        if (runtimePidAlive($pid)) {
            @posix_kill($pid, $sigTerm);
        }
    }

    $deadline = microtime(true) + max(0.5, $termTimeoutSec);
    while (microtime(true) < $deadline) {
        $alive = [];
        foreach ($targets as $pid) {
            if (runtimePidAlive($pid)) {
                $alive[] = $pid;
            }
        }
        if (empty($alive)) {
            return [
                'forced' => false,
                'alive_after' => [],
            ];
        }
        usleep(200000);
    }

    $forced = false;
    $aliveAfterKill = [];
    foreach ($targets as $pid) {
        if (!runtimePidAlive($pid)) {
            continue;
        }
        $forced = true;
        @posix_kill($pid, $sigKill);
    }

    usleep(200000);
    foreach ($targets as $pid) {
        if (runtimePidAlive($pid)) {
            $aliveAfterKill[] = $pid;
        }
    }

    return [
        'forced' => $forced,
        'alive_after' => $aliveAfterKill,
    ];
}

function runtimeWaitPidsExit(array $pids, float $timeoutSec = 10.0): array
{
    $targets = [];
    foreach ($pids as $pid) {
        $pid = (int) $pid;
        if ($pid > 1) {
            $targets[$pid] = true;
        }
    }
    $targets = array_keys($targets);
    if (empty($targets)) {
        return ['alive' => []];
    }

    $deadline = microtime(true) + max(0.2, $timeoutSec);
    while (microtime(true) < $deadline) {
        $alive = [];
        foreach ($targets as $pid) {
            if (runtimePidAlive($pid)) {
                $alive[] = $pid;
            }
        }
        if (empty($alive)) {
            return ['alive' => []];
        }
        usleep(200000);
    }

    $alive = [];
    foreach ($targets as $pid) {
        if (runtimePidAlive($pid)) {
            $alive[] = $pid;
        }
    }
    return ['alive' => $alive];
}

function runtimeQmpReadJsonMessage($stream, float $timeoutSec = 0.8): ?array
{
    $deadline = microtime(true) + max(0.1, $timeoutSec);
    while (microtime(true) < $deadline) {
        $line = @fgets($stream);
        if ($line === false) {
            $meta = @stream_get_meta_data($stream);
            if (is_array($meta) && !empty($meta['timed_out'])) {
                return null;
            }
            usleep(20000);
            continue;
        }
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

function runtimeQgaSendMessage($socket, array $payload): bool
{
    if (!is_resource($socket)) {
        return false;
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return false;
    }

    $buffer = $json . "\n";
    while ($buffer !== '') {
        $written = @fwrite($socket, $buffer);
        if (!is_int($written) || $written <= 0) {
            return false;
        }
        $buffer = (string) substr($buffer, $written);
    }
    return true;
}

function runtimeQgaReadMessage($socket, string &$buffer, float $timeoutSec = 1.0): ?array
{
    if (!is_resource($socket)) {
        return null;
    }

    $deadline = microtime(true) + max(0.15, $timeoutSec);
    while (microtime(true) < $deadline) {
        $chunk = @fread($socket, 65536);
        if (is_string($chunk) && $chunk !== '') {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = (string) substr($buffer, 0, $pos);
                $buffer = (string) substr($buffer, $pos + 1);
                $line = trim((string) ltrim($line, "\x00..\x1F\x7F\xFF"));
                if ($line !== '') {
                    // Strip leading non-JSON bytes that can appear after delimiter sync.
                    $line = (string) preg_replace('/^[^\{\[]+/', '', $line);
                }
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            continue;
        }
        if ($chunk === '' && @feof($socket)) {
            break;
        }
        usleep(20000);
    }

    return null;
}

function runtimeQgaRequest($socket, string &$buffer, string $execute, array $arguments = [], float $timeoutSec = 2.0): array
{
    $id = '';
    try {
        $id = 'rt-' . bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $id = 'rt-' . dechex((int) (microtime(true) * 1000000));
    }

    $payload = [
        'execute' => $execute,
        'id' => $id,
    ];
    if (!empty($arguments)) {
        $payload['arguments'] = $arguments;
    }
    if (!runtimeQgaSendMessage($socket, $payload)) {
        return [
            'ok' => false,
            'error' => 'linux_agent_io_failed',
            'return' => null,
        ];
    }

    $deadline = microtime(true) + max(0.3, $timeoutSec);
    while (microtime(true) < $deadline) {
        $msg = runtimeQgaReadMessage($socket, $buffer, min(0.4, max(0.1, $deadline - microtime(true))));
        if (!is_array($msg)) {
            continue;
        }

        $msgId = isset($msg['id']) ? (string) $msg['id'] : '';
        if ($id !== '') {
            // Ignore untagged async/error frames and wait for response of this request id.
            if ($msgId === '' || !hash_equals($msgId, $id)) {
                continue;
            }
        }

        if (array_key_exists('return', $msg)) {
            return [
                'ok' => true,
                'error' => null,
                'return' => $msg['return'],
            ];
        }
        if (array_key_exists('error', $msg)) {
            $err = is_array($msg['error']) ? (array) $msg['error'] : ['desc' => (string) $msg['error']];
            return [
                'ok' => false,
                'error' => trim((string) ($err['desc'] ?? $err['class'] ?? 'linux_agent_exec_failed')),
                'return' => null,
            ];
        }
    }

    return [
        'ok' => false,
        'error' => 'linux_agent_timeout',
        'return' => null,
    ];
}

function runtimeRequestQemuGuestShutdown(string $nodeId): bool
{
    $socketPath = runtimeQgaSocketPath($nodeId);
    if (!is_string($socketPath) || $socketPath === '' || !file_exists($socketPath)) {
        return false;
    }

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        'unix://' . $socketPath,
        $errno,
        $errstr,
        1.0,
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($socket)) {
        return false;
    }

    @stream_set_blocking($socket, false);
    @stream_set_write_buffer($socket, 0);
    $buffer = '';

    try {
        $syncId = 0;
        try {
            $syncId = random_int(1, PHP_INT_MAX);
        } catch (Throwable $e) {
            $syncId = max(1, (int) (microtime(true) * 1000000));
        }

        $sync = runtimeQgaRequest($socket, $buffer, 'guest-sync', ['id' => $syncId], 2.0);
        if (empty($sync['ok'])) {
            return false;
        }

        $shutdown = runtimeQgaRequest($socket, $buffer, 'guest-shutdown', ['mode' => 'powerdown'], 2.0);
        if (!empty($shutdown['ok'])) {
            return true;
        }

        // Some guests close QGA channel immediately after accepting shutdown.
        $error = strtolower(trim((string) ($shutdown['error'] ?? '')));
        if ($error === 'linux_agent_timeout' || $error === 'linux_agent_io_failed') {
            return true;
        }

        return false;
    } catch (Throwable $e) {
        return false;
    } finally {
        if (is_resource($socket)) {
            @stream_socket_shutdown($socket, STREAM_SHUT_RDWR);
            @fclose($socket);
        }
    }
}

function runtimeRequestQemuSystemPowerdown(string $nodeId, ?string &$mode = null): bool
{
    $mode = null;
    if (runtimeRequestQemuGuestShutdown($nodeId)) {
        $mode = 'guest_agent';
        return true;
    }

    $socketPath = runtimeQmpSocketPath($nodeId);
    if (!is_string($socketPath) || $socketPath === '' || !file_exists($socketPath)) {
        return false;
    }

    $errno = 0;
    $errstr = '';
    $stream = @stream_socket_client('unix://' . $socketPath, $errno, $errstr, 0.8);
    if (!is_resource($stream)) {
        return false;
    }

    @stream_set_timeout($stream, 0, 250000);
    runtimeQmpReadJsonMessage($stream, 0.8); // Greeting.

    $capsCmd = json_encode(['execute' => 'qmp_capabilities']);
    if (!is_string($capsCmd) || @fwrite($stream, $capsCmd . "\n") === false) {
        @fclose($stream);
        return false;
    }
    runtimeQmpReadJsonMessage($stream, 0.8);

    $powerCmd = json_encode(['execute' => 'system_powerdown']);
    if (!is_string($powerCmd) || @fwrite($stream, $powerCmd . "\n") === false) {
        @fclose($stream);
        return false;
    }
    @fflush($stream);
    runtimeQmpReadJsonMessage($stream, 0.8);
    @fclose($stream);
    $mode = 'acpi';
    return true;
}

function runtimeResolveQemuNicDriver(array $ctx): string
{
    $nicDriver = trim((string) ($ctx['qemu_nic'] ?? ''));
    if ($nicDriver === '' || !preg_match('/^[0-9a-zA-Z_.-]+$/', $nicDriver)) {
        $nicDriver = 'e1000';
    }
    return $nicDriver;
}

function runtimeQmpOpen(string $nodeId)
{
    $socketPath = runtimeQmpSocketPath($nodeId);
    if (!is_string($socketPath) || $socketPath === '' || !file_exists($socketPath)) {
        return null;
    }
    $errno = 0;
    $errstr = '';
    $stream = @stream_socket_client('unix://' . $socketPath, $errno, $errstr, 0.8);
    if (!is_resource($stream)) {
        return null;
    }
    @stream_set_timeout($stream, 0, 300000);
    runtimeQmpReadJsonMessage($stream, 0.8); // Greeting.
    $capsCmd = json_encode(['execute' => 'qmp_capabilities']);
    if (!is_string($capsCmd) || @fwrite($stream, $capsCmd . "\n") === false) {
        @fclose($stream);
        return null;
    }
    runtimeQmpReadJsonMessage($stream, 0.8);
    return $stream;
}

function runtimeQmpExecute($stream, string $execute, array $arguments = []): array
{
    if (!is_resource($stream)) {
        return ['ok' => false, 'error' => 'qmp_stream_invalid'];
    }
    $payload = ['execute' => $execute];
    if (!empty($arguments)) {
        $payload['arguments'] = $arguments;
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || @fwrite($stream, $encoded . "\n") === false) {
        return ['ok' => false, 'error' => 'qmp_write_failed'];
    }
    @fflush($stream);
    $reply = runtimeQmpReadJsonMessage($stream, 0.9);
    if (!is_array($reply)) {
        return ['ok' => false, 'error' => 'qmp_timeout'];
    }
    if (isset($reply['error'])) {
        $desc = '';
        if (is_array($reply['error'])) {
            $desc = (string) ($reply['error']['desc'] ?? $reply['error']['class'] ?? 'qmp_error');
        } else {
            $desc = (string) $reply['error'];
        }
        return ['ok' => false, 'error' => $desc];
    }
    return ['ok' => true, 'result' => $reply['return'] ?? null];
}

function runtimeQmpHmp($stream, string $commandLine): array
{
    return runtimeQmpExecute(
        $stream,
        'human-monitor-command',
        ['command-line' => $commandLine]
    );
}

function runtimeQmpHmpResultText(array $reply): string
{
    if (!isset($reply['result'])) {
        return '';
    }
    $result = $reply['result'];
    if (is_string($result)) {
        return trim($result);
    }
    if (is_scalar($result)) {
        return trim((string) $result);
    }
    return '';
}

function runtimeQmpHmpLooksLikeError(string $text): bool
{
    $normalized = strtolower(trim($text));
    if ($normalized === '') {
        return false;
    }

    $needles = [
        'error',
        'failed',
        'cannot ',
        "can't",
        'could not',
        'operation not permitted',
        'permission denied',
        'not found',
        'invalid',
        'unknown',
        'no such',
        'already exists',
        'already in use',
        'property ',
    ];
    foreach ($needles as $needle) {
        if (strpos($normalized, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function runtimeQmpFindDevicePciSlotInBus(array $bus, string $devId): ?int
{
    $devices = isset($bus['devices']) && is_array($bus['devices']) ? $bus['devices'] : [];
    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        $qdevId = trim((string) ($device['qdev_id'] ?? ''));
        if ($qdevId === $devId) {
            $slot = isset($device['slot']) ? (int) $device['slot'] : -1;
            if ($slot >= 0 && $slot <= 0x1f) {
                return $slot;
            }
            return null;
        }

        if (isset($device['pci_bridge']) && is_array($device['pci_bridge'])) {
            $childBus = isset($device['pci_bridge']['bus']) && is_array($device['pci_bridge']['bus'])
                ? $device['pci_bridge']['bus']
                : null;
            if (is_array($childBus)) {
                $found = runtimeQmpFindDevicePciSlotInBus($childBus, $devId);
                if ($found !== null) {
                    return $found;
                }
            }
        }
    }

    return null;
}

function runtimeQmpFindDevicePciSlot($stream, string $devId): ?int
{
    $devId = trim($devId);
    if ($devId === '') {
        return null;
    }

    $reply = runtimeQmpExecute($stream, 'query-pci');
    if (empty($reply['ok']) || !isset($reply['result']) || !is_array($reply['result'])) {
        return null;
    }

    foreach ($reply['result'] as $bus) {
        if (!is_array($bus)) {
            continue;
        }
        $found = runtimeQmpFindDevicePciSlotInBus($bus, $devId);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

function runtimeQmpInfoNetworkSnapshot($stream): string
{
    return runtimeQmpHmpResultText(runtimeQmpHmp($stream, 'info network'));
}

function runtimeQmpInfoNetworkHasEntry(string $snapshot, string $entryId): bool
{
    $entryId = trim($entryId);
    if ($entryId === '') {
        return false;
    }
    $pattern = '/(^|\\n)\\s*' . preg_quote($entryId, '/') . ':\\s*index=/mi';
    return preg_match($pattern, $snapshot) === 1;
}

function runtimeQmpWaitDeviceRemoval($stream, string $devId, int $attempts = 20, int $sleepUsec = 50000): bool
{
    $attempts = max(1, $attempts);
    for ($i = 0; $i < $attempts; $i++) {
        $missingInPci = runtimeQmpFindDevicePciSlot($stream, $devId) === null;
        $snapshot = runtimeQmpInfoNetworkSnapshot($stream);
        $missingInNet = !runtimeQmpInfoNetworkHasEntry($snapshot, $devId);
        if ($missingInPci && $missingInNet) {
            return true;
        }
        usleep(max(1000, $sleepUsec));
    }
    return false;
}

function runtimeQmpWaitDevicePresent($stream, string $devId, int $attempts = 20, int $sleepUsec = 50000): bool
{
    $attempts = max(1, $attempts);
    for ($i = 0; $i < $attempts; $i++) {
        if (runtimeQmpFindDevicePciSlot($stream, $devId) !== null) {
            return true;
        }
        $snapshot = runtimeQmpInfoNetworkSnapshot($stream);
        if (runtimeQmpInfoNetworkHasEntry($snapshot, $devId)) {
            return true;
        }
        usleep(max(1000, $sleepUsec));
    }
    return false;
}

function runtimeQmpWaitNetdevRemoval($stream, string $netId, int $attempts = 20, int $sleepUsec = 50000): bool
{
    $attempts = max(1, $attempts);
    for ($i = 0; $i < $attempts; $i++) {
        $snapshot = runtimeQmpInfoNetworkSnapshot($stream);
        if (!runtimeQmpInfoNetworkHasEntry($snapshot, $netId)) {
            return true;
        }
        usleep(max(1000, $sleepUsec));
    }
    return false;
}

function runtimeQemuBridgeHelperPath(): string
{
    $candidates = [];
    $custom = trim((string) getenv('EVE_QEMU_BRIDGE_HELPER'));
    if ($custom !== '') {
        $candidates[] = $custom;
    }
    $candidates[] = '/usr/lib/qemu/qemu-bridge-helper';
    $candidates[] = '/usr/libexec/qemu-bridge-helper';
    $candidates[] = '/usr/lib64/qemu/qemu-bridge-helper';

    foreach ($candidates as $path) {
        $path = trim((string) $path);
        if ($path === '') {
            continue;
        }
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }

    return '';
}

function runtimeQemuBridgeAclAllows(string $bridge): bool
{
    $bridge = strtolower(trim($bridge));
    if ($bridge === '') {
        return false;
    }

    $configPath = '/etc/qemu/bridge.conf';
    if (!is_file($configPath) || !is_readable($configPath)) {
        return false;
    }

    $lines = @file($configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return false;
    }

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, ';') === 0) {
            continue;
        }
        $line = strtolower(preg_replace('/\s+/', ' ', $line) ?? '');
        if ($line === 'allow all' || $line === ('allow ' . $bridge)) {
            return true;
        }
    }

    return false;
}

function runtimeHotApplyQemuNodeLinks(PDO $db, string $labId, string $nodeId, array $nodeChanges = []): array
{
    refreshLabNodeRuntimeState($db, $labId, $nodeId);
    $ctx = runtimeLoadNodeContext($db, $labId, $nodeId);
    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
    if ($nodeType !== 'qemu') {
        return ['ok' => false, 'reason' => 'unsupported_node_type', 'node_type' => $nodeType];
    }

    $pid = (int) ($ctx['runtime_pid'] ?? 0);
    if ($pid <= 1 || (!runtimePidBelongsToNode($pid, $labId, $nodeId) && !runtimePidMatchesQemuNodeUuid($pid, $nodeId))) {
        return ['ok' => false, 'reason' => 'node_not_running'];
    }

    $stream = runtimeQmpOpen($nodeId);
    if (!is_resource($stream)) {
        return ['ok' => false, 'reason' => 'qmp_unavailable'];
    }

    $changesByPortId = [];
    $oldNetworkIds = [];
    foreach ($nodeChanges as $change) {
        if (!is_array($change)) {
            continue;
        }
        $portId = trim((string) ($change['port_id'] ?? ''));
        if ($portId === '') {
            continue;
        }
        $changesByPortId[$portId] = $change;
        $oldNetworkId = trim((string) ($change['old_network_id'] ?? ''));
        if ($oldNetworkId !== '') {
            $oldNetworkIds[$oldNetworkId] = true;
        }
    }

    $oldNetworkTypeById = [];
    if (!empty($oldNetworkIds)) {
        $oldNetworkIdList = array_values(array_keys($oldNetworkIds));
        $placeholders = [];
        foreach ($oldNetworkIdList as $idx => $unusedNetworkId) {
            $placeholders[] = ':network_id_' . $idx;
        }
        $oldNetworkStmt = $db->prepare(
            "SELECT id, network_type
             FROM lab_networks
             WHERE id IN (" . implode(', ', $placeholders) . ')'
        );
        foreach ($oldNetworkIdList as $idx => $networkId) {
            $oldNetworkStmt->bindValue(':network_id_' . $idx, (string) $networkId, PDO::PARAM_STR);
        }
        $oldNetworkStmt->execute();
        $oldNetworkRows = $oldNetworkStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($oldNetworkRows)) {
            $oldNetworkRows = [];
        }
        foreach ($oldNetworkRows as $oldNetworkRow) {
            if (!is_array($oldNetworkRow)) {
                continue;
            }
            $rowNetworkId = trim((string) ($oldNetworkRow['id'] ?? ''));
            if ($rowNetworkId === '') {
                continue;
            }
            $oldNetworkTypeById[$rowNetworkId] = strtolower(trim((string) ($oldNetworkRow['network_type'] ?? '')));
        }
    }

    $nicDriver = runtimeResolveQemuNicDriver($ctx);
    $ethCount = max(0, (int) ($ctx['ethernet_count'] ?? 0));
    $indexedPorts = runtimeBuildPortIndexMap((array) ($ctx['ports'] ?? []), $ethCount);
    $netKeys = runtimeBuildNetworkKeys((array) ($ctx['ports'] ?? []), $ethCount, $labId, $nodeId);
    $results = [];
    $bridgeHelperPath = runtimeQemuBridgeHelperPath();

    $targetIndices = [];
    if (!empty($changesByPortId)) {
        foreach ($indexedPorts as $idx => $portMeta) {
            if (!is_array($portMeta)) {
                continue;
            }
            $portId = trim((string) ($portMeta['id'] ?? ''));
            if ($portId === '' || !isset($changesByPortId[$portId])) {
                continue;
            }
            $targetIndices[(int) $idx] = true;
        }
    }
    if (empty($targetIndices)) {
        for ($i = 0; $i < $ethCount; $i++) {
            $targetIndices[$i] = true;
        }
    }
    $targetIndexes = array_keys($targetIndices);
    sort($targetIndexes, SORT_NUMERIC);

    try {
        foreach ($targetIndexes as $i) {
            $i = (int) $i;
            $key = $netKeys[$i] ?? ('isolated:' . $labId . ':' . $nodeId . ':' . $i);
            $portMeta = $indexedPorts[$i] ?? null;
            $p2p = runtimePortUdpPointToPoint(is_array($portMeta) ? $portMeta : null);
            $endpoint = runtimeMcastEndpoint($key);
            $mac = runtimeMacAddress($nodeId, $i, $ctx['first_mac'] ?? null);
            $netId = 'net' . $i;
            $devId = 'nic' . $i;
            $networkType = strtolower(trim((string) (is_array($portMeta) ? ($portMeta['network_type'] ?? '') : '')));
            $isCloudPort = $networkType !== '' && preg_match('/^pnet[0-9]+$/', $networkType) === 1;
            $cloudBridge = $isCloudPort ? $networkType : '';
            $changeMeta = (is_array($portMeta) && isset($changesByPortId[(string) ($portMeta['id'] ?? '')]) && is_array($changesByPortId[(string) ($portMeta['id'] ?? '')]))
                ? $changesByPortId[(string) ($portMeta['id'] ?? '')]
                : [];
            $oldNetworkId = trim((string) ($changeMeta['old_network_id'] ?? ''));
            $oldNetworkType = $oldNetworkId !== '' ? strtolower(trim((string) ($oldNetworkTypeById[$oldNetworkId] ?? ''))) : '';
            $mode = 'mcast';

            $probeSuffix = '';
            try {
                $probeSuffix = dechex(random_int(0, 0xffff));
            } catch (Throwable $e) {
                $probeSuffix = dechex((int) (microtime(true) * 1000000) & 0xffff);
            }
            $probeId = 'v2probe' . $i . '_' . $probeSuffix;

            if ($isCloudPort) {
                if ($bridgeHelperPath === '') {
                    return [
                        'ok' => false,
                        'reason' => 'cloud_bridge_helper_missing',
                        'interface' => $i,
                        'error' => 'qemu-bridge-helper not found',
                    ];
                }
                if (!runtimeQemuBridgeAclAllows($cloudBridge)) {
                    return [
                        'ok' => false,
                        'reason' => 'cloud_bridge_acl_missing',
                        'interface' => $i,
                        'error' => 'Bridge "' . $cloudBridge . '" is not allowed in /etc/qemu/bridge.conf',
                    ];
                }
                $netdevCmd = 'netdev_add bridge,id=' . $netId
                    . ',br=' . $cloudBridge
                    . ',helper=' . $bridgeHelperPath;
                $probeNetdevCmd = 'netdev_add bridge,id=' . $probeId
                    . ',br=' . $cloudBridge
                    . ',helper=' . $bridgeHelperPath;
                $mode = 'cloud_bridge';
            } elseif ($p2p !== null) {
                $netdevCmd = 'netdev_add socket,id=' . $netId
                    . ',localaddr=127.0.0.1:' . (int) $p2p['local_port']
                    . ',udp=127.0.0.1:' . (int) $p2p['remote_port'];
                $probeNetdevCmd = 'netdev_add socket,id=' . $probeId
                    . ',localaddr=127.0.0.1:' . (int) $p2p['local_port']
                    . ',udp=127.0.0.1:' . (int) $p2p['remote_port'];
                $mode = 'udp_p2p';
            } else {
                $netdevCmd = 'netdev_add socket,id=' . $netId
                    . ',mcast=' . $endpoint['addr'] . ':' . $endpoint['port'];
                $probeNetdevCmd = 'netdev_add socket,id=' . $probeId
                    . ',mcast=' . $endpoint['addr'] . ':' . $endpoint['port'];
                $mode = 'mcast';
            }

            // Pre-check target backend before removing current NIC. This prevents
            // losing interface when cloud TAP attach is not permitted for runtime user.
            $probeAdd = runtimeQmpHmp($stream, $probeNetdevCmd);
            $probeText = runtimeQmpHmpResultText($probeAdd);
            runtimeQmpHmp($stream, 'netdev_del ' . $probeId);
            if (empty($probeAdd['ok']) || runtimeQmpHmpLooksLikeError($probeText)) {
                if ($isCloudPort) {
                    return [
                        'ok' => false,
                        'reason' => 'cloud_hot_apply_failed',
                        'interface' => $i,
                        'error' => $probeText !== '' ? $probeText : ((string) ($probeAdd['error'] ?? 'unknown')),
                    ];
                }
                return [
                    'ok' => false,
                    'reason' => 'netdev_precheck_failed',
                    'interface' => $i,
                    'error' => $probeText !== '' ? $probeText : ((string) ($probeAdd['error'] ?? 'unknown')),
                ];
            }

            // Existing nodes started before NIC IDs were introduced may fail hot apply.
            runtimeQmpHmp($stream, 'device_del ' . $devId);
            if (!runtimeQmpWaitDeviceRemoval($stream, $devId, 80, 50000)) {
                $snapshot = preg_replace('/\s+/', ' ', trim(runtimeQmpInfoNetworkSnapshot($stream)));
                if (!is_string($snapshot)) {
                    $snapshot = '';
                }
                if (strlen($snapshot) > 240) {
                    $snapshot = substr($snapshot, 0, 240);
                }
                return [
                    'ok' => false,
                    'reason' => 'device_remove_timeout',
                    'interface' => $i,
                    'error' => 'Timed out waiting for NIC removal' . ($snapshot !== '' ? ('; info network: ' . $snapshot) : ''),
                ];
            }

            $netdevRemoved = false;
            for ($dropAttempt = 0; $dropAttempt < 4; $dropAttempt++) {
                runtimeQmpHmp($stream, 'netdev_del ' . $netId);
                if (runtimeQmpWaitNetdevRemoval($stream, $netId, 40, 50000)) {
                    $netdevRemoved = true;
                    break;
                }
            }
            if (!$netdevRemoved) {
                $snapshot = preg_replace('/\s+/', ' ', trim(runtimeQmpInfoNetworkSnapshot($stream)));
                if (!is_string($snapshot)) {
                    $snapshot = '';
                }
                if (strlen($snapshot) > 240) {
                    $snapshot = substr($snapshot, 0, 240);
                }
                return [
                    'ok' => false,
                    'reason' => 'netdev_remove_timeout',
                    'interface' => $i,
                    'error' => 'Timed out waiting for netdev cleanup' . ($snapshot !== '' ? ('; info network: ' . $snapshot) : ''),
                ];
            }

            $addNetdev = runtimeQmpHmp($stream, $netdevCmd);
            $addNetdevText = runtimeQmpHmpResultText($addNetdev);
            if (empty($addNetdev['ok']) || runtimeQmpHmpLooksLikeError($addNetdevText)) {
                return [
                    'ok' => false,
                    'reason' => 'netdev_reconfigure_failed',
                    'interface' => $i,
                    'error' => $addNetdevText !== '' ? $addNetdevText : ((string) ($addNetdev['error'] ?? 'unknown')),
                ];
            }

            $addDeviceCmd = 'device_add ' . $nicDriver . ',id=' . $devId . ',netdev=' . $netId . ',mac=' . $mac;
            $addDevice = runtimeQmpHmp($stream, $addDeviceCmd);
            $addDeviceText = runtimeQmpHmpResultText($addDevice);
            if (empty($addDevice['ok']) || runtimeQmpHmpLooksLikeError($addDeviceText)) {
                return [
                    'ok' => false,
                    'reason' => 'device_reconfigure_failed',
                    'interface' => $i,
                    'error' => $addDeviceText !== '' ? $addDeviceText : ((string) ($addDevice['error'] ?? 'unknown')),
                ];
            }
            if (!runtimeQmpWaitDevicePresent($stream, $devId, 120, 50000)) {
                $networkSnapshot = runtimeQmpHmpResultText(runtimeQmpHmp($stream, 'info network'));
                $networkSnapshot = preg_replace('/\s+/', ' ', trim($networkSnapshot));
                if (!is_string($networkSnapshot)) {
                    $networkSnapshot = '';
                }
                if (strlen($networkSnapshot) > 240) {
                    $networkSnapshot = substr($networkSnapshot, 0, 240);
                }
                $extra = $networkSnapshot !== '' ? ('; info network: ' . $networkSnapshot) : '';
                return [
                    'ok' => false,
                    'reason' => 'device_reconfigure_missing_after_add',
                    'interface' => $i,
                    'error' => 'QEMU accepted device_add but NIC is not present after reconfigure' . $extra,
                ];
            }

            $results[] = [
                'index' => $i,
                'net_id' => $netId,
                'device_id' => $devId,
                'mode' => $mode,
                'bridge' => $cloudBridge !== '' ? $cloudBridge : null,
                'tap' => null,
            ];
        }
    } finally {
        @fclose($stream);
    }

    return ['ok' => true, 'interfaces' => $results];
}





function runtimeLinuxInterfaceExists(string $interface): bool
{
    $interface = trim($interface);
    if ($interface === '') {
        return false;
    }
    return is_dir('/sys/class/net/' . $interface);
}

function runtimeTapCurrentBridgeName(string $tap): string
{
    $tap = trim($tap);
    if ($tap === '') {
        return '';
    }
    $masterPath = '/sys/class/net/' . $tap . '/master';
    if (!is_link($masterPath)) {
        return '';
    }
    $target = @readlink($masterPath);
    if (!is_string($target) || $target === '') {
        return '';
    }
    $bridge = basename($target);
    if (!is_string($bridge) || $bridge === '') {
        return '';
    }
    return $bridge;
}

function runtimeBridgePortsMapFromInterfaces(string $rootFile = '/etc/network/interfaces'): array
{
    static $cache = [];

    $cacheKey = (string) (realpath($rootFile) ?: $rootFile);
    if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $visited = [];
    $map = [];
    runtimeParseBridgePortsFromInterfacesFile($rootFile, $visited, $map);

    foreach ($map as $bridge => $ports) {
        if (!is_array($ports)) {
            unset($map[$bridge]);
            continue;
        }
        $normalized = [];
        foreach ($ports as $port) {
            $port = strtolower(trim((string) $port));
            if ($port === '' || $port === 'none') {
                continue;
            }
            $normalized[$port] = true;
        }
        $deduped = array_keys($normalized);
        sort($deduped, SORT_NATURAL);
        $map[$bridge] = $deduped;
    }

    $cache[$cacheKey] = $map;
    return $map;
}

function runtimeParseBridgePortsFromInterfacesFile(string $filePath, array &$visited, array &$map): void
{
    $realPath = realpath($filePath) ?: $filePath;
    if (isset($visited[$realPath])) {
        return;
    }
    $visited[$realPath] = true;

    if (!is_readable($filePath)) {
        return;
    }

    $lines = @file($filePath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return;
    }

    $baseDir = dirname($filePath);
    $currentIface = '';

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (preg_match('/^(?:source|source-directory)\s+(.+)$/i', $trimmed, $m) === 1) {
            $pattern = trim((string) ($m[1] ?? ''));
            if ($pattern === '') {
                continue;
            }
            if ($pattern[0] !== '/') {
                $pattern = $baseDir . '/' . $pattern;
            }
            $matches = glob($pattern, GLOB_NOSORT) ?: [];
            foreach ($matches as $matchFile) {
                if (is_dir($matchFile)) {
                    $children = glob(rtrim($matchFile, '/') . '/*', GLOB_NOSORT) ?: [];
                    foreach ($children as $child) {
                        runtimeParseBridgePortsFromInterfacesFile($child, $visited, $map);
                    }
                    continue;
                }
                runtimeParseBridgePortsFromInterfacesFile($matchFile, $visited, $map);
            }
            continue;
        }

        if (preg_match('/^iface\s+([a-zA-Z0-9_.:-]+)\b/i', $trimmed, $m) === 1) {
            $currentIface = strtolower((string) ($m[1] ?? ''));
            continue;
        }

        if ($currentIface === '') {
            continue;
        }

        if (preg_match('/^bridge[_-]ports\s+(.+)$/i', $trimmed, $m) !== 1) {
            continue;
        }

        $portsText = trim((string) ($m[1] ?? ''));
        if ($portsText === '') {
            continue;
        }
        $parts = preg_split('/\s+/', $portsText) ?: [];
        if (!isset($map[$currentIface]) || !is_array($map[$currentIface])) {
            $map[$currentIface] = [];
        }
        foreach ($parts as $part) {
            $part = strtolower(trim((string) $part));
            if ($part === '' || $part === 'none') {
                continue;
            }
            $map[$currentIface][] = $part;
        }
    }
}

function runtimeBridgePortsFromInterfaces(string $bridge): array
{
    $bridge = strtolower(trim($bridge));
    if ($bridge === '') {
        return [];
    }

    $map = runtimeBridgePortsMapFromInterfaces('/etc/network/interfaces');
    $ports = $map[$bridge] ?? [];
    return is_array($ports) ? $ports : [];
}

function runtimeEnsureCloudBridgePortsAttached(string $bridge, string $logPath): void
{
    $bridge = strtolower(trim($bridge));
    if ($bridge === '' || preg_match('/^pnet[0-9]+$/', $bridge) !== 1) {
        return;
    }

    $ports = runtimeBridgePortsFromInterfaces($bridge);
    if (empty($ports)) {
        return;
    }

    foreach ($ports as $port) {
        $port = strtolower(trim((string) $port));
        if ($port === '' || !runtimeLinuxInterfaceExists($port)) {
            continue;
        }

        runtimeRunLoggedIpCommand(['link', 'set', 'dev', $port, 'up'], $logPath);

        $master = strtolower(trim(runtimeTapCurrentBridgeName($port)));
        if ($master !== '' && $master !== $bridge) {
            runtimeRunLoggedIpCommand(['link', 'set', 'dev', $port, 'nomaster'], $logPath);
        }
        if ($master !== $bridge) {
            runtimeRunLoggedIpCommand(['link', 'set', 'dev', $port, 'master', $bridge], $logPath);
            runtimeRunLoggedIpCommand(['link', 'set', 'dev', $port, 'up'], $logPath);
        }
    }
}

function runtimeRunLoggedShellCommand(string $snippet, string $logPath): array
{
    $snippet = trim($snippet);
    if ($snippet === '') {
        return ['rc' => 0, 'output' => ''];
    }

    $out = [];
    exec('/bin/bash -lc ' . escapeshellarg($snippet), $out, $rc);
    $text = trim(implode("\n", $out));
    if ($text !== '') {
        $logDir = (string) dirname($logPath);
        if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
            @file_put_contents($logPath, $text . "\n", FILE_APPEND);
        }
    }
    return ['rc' => (int) $rc, 'output' => $text];
}

function runtimeIpBinary(): string
{
    foreach (['/usr/sbin/ip', '/sbin/ip', '/usr/bin/ip', '/bin/ip'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'ip';
}

function runtimeRunLoggedArgvCommand(array $argv, string $logPath, bool $allowSudo = true): array
{
    $parts = [];
    foreach ($argv as $arg) {
        $arg = trim((string) $arg);
        if ($arg === '') {
            continue;
        }
        $parts[] = escapeshellarg($arg);
    }
    if (empty($parts)) {
        return ['rc' => 0, 'output' => '', 'cmd' => ''];
    }

    $command = implode(' ', $parts);
    $euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
    if ($allowSudo && $euid !== 0 && is_executable('/usr/bin/sudo')) {
        $command = '/usr/bin/sudo -n ' . $command;
    }

    $out = [];
    exec('/bin/bash -lc ' . escapeshellarg($command . ' 2>&1'), $out, $rc);
    $text = trim(implode("\n", $out));
    if ($text !== '') {
        $logDir = (string) dirname($logPath);
        if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
            @file_put_contents($logPath, $text . "\n", FILE_APPEND);
        }
    }

    return ['rc' => (int) $rc, 'output' => $text, 'cmd' => $command];
}

function runtimeRunLoggedIpCommand(array $args, string $logPath): array
{
    return runtimeRunLoggedArgvCommand(array_merge([runtimeIpBinary()], $args), $logPath, true);
}

function runtimeInternalBridgeName(string $labId, string $networkId): string
{
    $seed = strtolower(trim($labId)) . ':' . strtolower(trim($networkId));
    $hash = substr(hash('sha1', $seed), 0, 12);
    return 'v2n' . $hash;
}


function runtimeTapBridgeForPort(string $labId, ?array $port): string
{
    if (!is_array($port)) {
        return '';
    }

    $networkType = strtolower(trim((string) ($port['network_type'] ?? '')));
    if ($networkType !== '' && runtimeIsCloudNetworkType($networkType) && preg_match('/^pnet[0-9]+$/', $networkType) === 1) {
        return $networkType;
    }

    $networkId = strtolower(trim((string) ($port['network_id'] ?? '')));
    if ($networkId === '') {
        return '';
    }

    return runtimeInternalBridgeName($labId, $networkId);
}

function runtimeQemuTapName(string $labId, string $nodeId, int $index): string
{
    $seed = strtolower(trim($labId)) . ':' . strtolower(trim($nodeId)) . ':' . max(0, $index);
    return 'v2q' . substr(hash('sha1', $seed), 0, 11);
}

function runtimeVpcsTapName(string $labId, string $nodeId): string
{
    $seed = strtolower(trim($labId)) . ':' . strtolower(trim($nodeId));
    return 'v2v' . substr(hash('sha1', $seed), 0, 11);
}

function runtimeEnsureTapInterface(string $tap, string $logPath): bool
{
    $tap = strtolower(trim($tap));
    if ($tap === '' || preg_match('/^[a-z0-9_.-]{1,15}$/', $tap) !== 1) {
        return false;
    }

    if (!runtimeLinuxInterfaceExists($tap)) {
        runtimeRunLoggedIpCommand(['tuntap', 'add', 'dev', $tap, 'mode', 'tap'], $logPath);
    }

    return runtimeLinuxInterfaceExists($tap);
}

function runtimeSetTapLinkState(string $tap, bool $up, string $logPath): void
{
    $tap = strtolower(trim($tap));
    if ($tap === '') {
        return;
    }
    runtimeRunLoggedIpCommand(['link', 'set', 'dev', $tap, $up ? 'up' : 'down'], $logPath);
}




function runtimeEnsureLinuxBridgeUp(string $bridge, string $logPath): bool
{
    $bridge = strtolower(trim($bridge));
    if ($bridge === '' || preg_match('/^[a-z0-9_.-]{1,15}$/', $bridge) !== 1) {
        return false;
    }

    if (!runtimeLinuxInterfaceExists($bridge)) {
        runtimeRunLoggedIpCommand(['link', 'add', 'name', $bridge, 'type', 'bridge'], $logPath);
    }

    runtimeRunLoggedIpCommand(['link', 'set', 'dev', $bridge, 'up'], $logPath);
    runtimeEnsureCloudBridgePortsAttached($bridge, $logPath);

    return runtimeLinuxInterfaceExists($bridge);
}

function runtimeMoveTapToBridge(string $tap, string $bridge, string $logPath): array
{
    $tap = strtolower(trim($tap));
    $bridge = strtolower(trim($bridge));

    if ($tap === '') {
        return ['ok' => false, 'reason' => 'tap_invalid', 'error' => 'Tap name is empty'];
    }
    if (!runtimeLinuxInterfaceExists($tap)) {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(100000);
            if (runtimeLinuxInterfaceExists($tap)) {
                break;
            }
        }
    }
    if (!runtimeLinuxInterfaceExists($tap)) {
        return ['ok' => false, 'reason' => 'tap_not_found', 'error' => 'Tap interface is not available'];
    }

    runtimeRunLoggedIpCommand(['link', 'set', 'dev', $tap, 'nomaster'], $logPath);

    if ($bridge !== '') {
        $attach = runtimeRunLoggedIpCommand(['link', 'set', 'dev', $tap, 'master', $bridge], $logPath);
        if ((int) ($attach['rc'] ?? 1) !== 0) {
            $error = trim((string) ($attach['output'] ?? ''));
            if ($error === '') {
                $error = 'ip_link_command_failed';
            }
            return ['ok' => false, 'reason' => 'tap_move_failed', 'error' => $error];
        }
    }

    runtimeRunLoggedIpCommand(['link', 'set', 'dev', $tap, 'up'], $logPath);

    return ['ok' => true];
}

function runtimeCurrentUsername(): string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid((int) posix_geteuid());
        if (is_array($pw)) {
            $name = trim((string) ($pw['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }
    return 'www-data';
}

function runtimeSystemUserExists(string $username): bool
{
    $username = trim($username);
    if ($username === '' || preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $username) !== 1) {
        return false;
    }
    if (!function_exists('posix_getpwnam')) {
        return false;
    }
    $entry = @posix_getpwnam($username);
    return is_array($entry) && !empty($entry);
}






function runtimeHotApplyNativeTapNodeLinks(PDO $db, array $ctx, string $labId, string $nodeId, array $nodeChanges = []): array
{
    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
    if (!in_array($nodeType, ['qemu', 'vpcs'], true)) {
        return ['ok' => false, 'reason' => 'native_tap_node_type_unsupported', 'node_type' => $nodeType];
    }

    $pid = (int) ($ctx['runtime_pid'] ?? 0);
    if ($pid <= 1 || !runtimePidAlive($pid)) {
        return ['ok' => false, 'reason' => 'node_not_running'];
    }

    $ownerUserId = isset($ctx['author_user_id']) ? (string) $ctx['author_user_id'] : '';
    $nodeDir = resolveLabNodeRuntimeDir($labId, $nodeId, $ownerUserId);
    $logPath = runtimeNodeLogPathForType($nodeDir, $nodeType === 'qemu' ? 'qemu' : 'vpcs');

    $ports = is_array($ctx['ports'] ?? null) ? (array) $ctx['ports'] : [];
    $portMetaById = [];
    foreach ($ports as $port) {
        if (!is_array($port)) {
            continue;
        }
        $portId = trim((string) ($port['id'] ?? ''));
        if ($portId === '') {
            continue;
        }
        $index = runtimeEthIndexFromName((string) ($port['name'] ?? ''));
        if ($index === null || $index < 0) {
            continue;
        }
        if ($nodeType === 'vpcs' && $index !== 0) {
            continue;
        }
        $portMetaById[$portId] = [
            'port' => $port,
            'index' => (int) $index,
        ];
    }

    $changes = [];
    if (!empty($nodeChanges)) {
        foreach ($nodeChanges as $change) {
            if (!is_array($change)) {
                continue;
            }
            $portId = trim((string) ($change['port_id'] ?? ''));
            if ($portId === '' || !isset($portMetaById[$portId])) {
                continue;
            }
            $changes[] = $change;
        }
    } else {
        foreach ($portMetaById as $portId => $unusedMeta) {
            $changes[] = ['port_id' => (string) $portId, 'old_network_id' => ''];
        }
    }

    $applied = [];
    $skipped = [];

    foreach ($changes as $change) {
        $portId = trim((string) ($change['port_id'] ?? ''));
        if ($portId === '' || !isset($portMetaById[$portId])) {
            $skipped[] = ['port_id' => $portId, 'reason' => 'port_not_found'];
            continue;
        }

        $meta = (array) $portMetaById[$portId];
        $port = is_array($meta['port'] ?? null) ? (array) $meta['port'] : [];
        $index = (int) ($meta['index'] ?? 0);
        $tap = $nodeType === 'qemu'
            ? runtimeQemuTapName($labId, $nodeId, $index)
            : runtimeVpcsTapName($labId, $nodeId);
        $targetBridge = runtimeTapBridgeForPort($labId, $port);
        $oldBridge = runtimeTapCurrentBridgeName($tap);

        if (!runtimeEnsureTapInterface($tap, $logPath)) {
            $skipped[] = [
                'port_id' => $portId,
                'interface_id' => $index,
                'reason' => 'tap_prepare_failed',
                'error' => 'Failed to prepare TAP interface "' . $tap . '"',
            ];
            continue;
        }

        if ($targetBridge !== '' && !runtimeEnsureLinuxBridgeUp($targetBridge, $logPath)) {
            $skipped[] = [
                'port_id' => $portId,
                'interface_id' => $index,
                'reason' => 'bridge_prepare_failed',
                'error' => 'Failed to prepare bridge "' . $targetBridge . '"',
            ];
            continue;
        }

        $move = runtimeMoveTapToBridge($tap, $targetBridge, $logPath);
        if (empty($move['ok'])) {
            $skipped[] = [
                'port_id' => $portId,
                'interface_id' => $index,
                'reason' => (string) ($move['reason'] ?? 'tap_move_failed'),
                'error' => (string) ($move['error'] ?? ''),
            ];
            continue;
        }
        runtimeSetTapLinkState($tap, $targetBridge !== '', $logPath);

        $networkType = strtolower(trim((string) ($port['network_type'] ?? '')));
        $networkId = strtolower(trim((string) ($port['network_id'] ?? '')));
        $applied[] = [
            'port_id' => $portId,
            'interface_id' => $index,
            'tap' => $tap,
            'old_bridge' => $oldBridge !== '' ? $oldBridge : null,
            'bridge' => $targetBridge !== '' ? $targetBridge : null,
            'network_id' => $networkId !== '' ? $networkId : null,
            'network_type' => $networkType !== '' ? $networkType : null,
        ];
    }

    return [
        'ok' => true,
        'mode' => 'native_tap_bridge',
        'node_type' => $nodeType,
        'applied' => $applied,
        'skipped' => $skipped,
    ];
}

function runtimeHotApplyNodeLinks(PDO $db, string $labId, array $nodeIds, array $linkChanges = []): array
{
    $unique = [];
    foreach ($nodeIds as $nodeId) {
        $id = trim((string) $nodeId);
        if ($id !== '') {
            $unique[$id] = true;
        }
    }

    $changesByNode = [];
    foreach ($linkChanges as $change) {
        if (!is_array($change)) {
            continue;
        }
        $nodeId = trim((string) ($change['node_id'] ?? ''));
        if ($nodeId === '') {
            continue;
        }
        if (!isset($changesByNode[$nodeId])) {
            $changesByNode[$nodeId] = [];
        }
        $changesByNode[$nodeId][] = $change;
    }

    $applied = [];
    $skipped = [];
    foreach (array_keys($unique) as $nodeId) {
        try {
            refreshLabNodeRuntimeState($db, $labId, $nodeId);
            $ctx = runtimeLoadNodeContext($db, $labId, $nodeId);
            $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
            if ($nodeType === 'qemu' || $nodeType === 'vpcs') {
                $result = runtimeHotApplyNativeTapNodeLinks(
                    $db,
                    $ctx,
                    $labId,
                    $nodeId,
                    isset($changesByNode[$nodeId]) ? $changesByNode[$nodeId] : []
                );
            } else {
                $result = [
                    'ok' => false,
                    'reason' => 'hot_apply_not_supported',
                    'node_type' => $nodeType,
                ];
            }
        } catch (Throwable $e) {
            $skipped[] = [
                'node_id' => $nodeId,
                'reason' => 'exception',
                'error' => $e->getMessage(),
            ];
            continue;
        }

        if (!empty($result['ok'])) {
            $applied[] = ['node_id' => $nodeId, 'result' => $result];
        } else {
            $skipped[] = [
                'node_id' => $nodeId,
                'reason' => (string) ($result['reason'] ?? 'not_applied'),
                'detail' => $result,
            ];
        }
    }

    return ['applied' => $applied, 'skipped' => $skipped];
}

function runtimeResolveNodePid(int $candidatePid, string $labId, string $nodeId, array $cmdlineHints = [], int $attempts = 30): int
{
    $attempts = max(1, $attempts);
    for ($i = 0; $i < $attempts; $i++) {
        if ($candidatePid > 1 && runtimePidBelongsToNode($candidatePid, $labId, $nodeId)) {
            return $candidatePid;
        }

        $foundPid = runtimeFindNodePidByEnv($labId, $nodeId, $cmdlineHints);
        if ($foundPid > 1) {
            return $foundPid;
        }

        usleep(100000);
    }

    return 0;
}

function runtimeHash32(string $value): int
{
    $hex = hash('sha256', $value);
    $chunk = substr($hex, 0, 8);
    if ($chunk === false || $chunk === '') {
        return 0;
    }
    return (int) hexdec($chunk);
}

function runtimeStableIntInRange(string $value, int $min, int $max): int
{
    $min = max(0, $min);
    $max = max($min, $max);
    $span = $max - $min + 1;
    if ($span <= 1) {
        return $min;
    }
    $hash = runtimeHash32($value);
    return $min + ($hash % $span);
}

function runtimeNodeNumericId(string $labId, string $nodeId): int
{
    return runtimeStableIntInRange($labId . ':' . $nodeId, 1, 60000);
}

function runtimeCanBindTcpPort(int $port): bool
{
    if ($port < 1024 || $port > 65535) {
        return false;
    }

    $server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
    if (!is_resource($server)) {
        return false;
    }
    @fclose($server);
    return true;
}

function runtimeAllocateConsolePort(string $labId, string $nodeId, string $nodeType, ?int $preferredPort = null): int
{
    if ($preferredPort !== null && $preferredPort > 0 && runtimeCanBindTcpPort($preferredPort)) {
        return $preferredPort;
    }

    $start = runtimeStableIntInRange($nodeType . ':' . $labId . ':' . $nodeId, 33000, 60000);
    for ($offset = 0; $offset < 512; $offset++) {
        $port = 33000 + (($start - 33000 + $offset) % (60000 - 33000 + 1));
        if (runtimeCanBindTcpPort($port)) {
            return $port;
        }
    }

    throw new RuntimeException('No free TCP console port found');
}



function runtimeLaunchBackgroundProcess(
    string $labId,
    string $nodeId,
    string $workDir,
    string $logPath,
    string $pidPath,
    string $binary,
    array $args,
    array $extraEnv = [],
    ?string $runAsUser = null
): int {
    $envPairs = [
        'EVE_V2_LAB_UUID=' . escapeshellarg($labId),
        'EVE_V2_NODE_UUID=' . escapeshellarg($nodeId),
    ];
    foreach ($extraEnv as $key => $value) {
        $key = trim((string) $key);
        if ($key === '' || preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
            continue;
        }
        $envPairs[] = $key . '=' . escapeshellarg((string) $value);
    }

    $cmd = buildShellCommandFromArgv($binary, $args);
    $runAsUser = trim((string) $runAsUser);
    $envPrefix = implode(' ', $envPairs);
    if ($envPrefix !== '') {
        $envPrefix .= ' ';
    }
    if ($runAsUser !== '' && preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $runAsUser) === 1) {
        $currentUser = runtimeCurrentUsername();
        if (strcasecmp($currentUser, $runAsUser) !== 0 && is_executable('/usr/bin/sudo')) {
            // Keep node identity markers on the real runtime process across sudo
            // without introducing a separate `env` command in sudoers matching.
            $sudoPrefix = implode(' ', $envPairs);
            if ($sudoPrefix !== '') {
                $sudoPrefix .= ' ';
            }
            $cmd = '/usr/bin/sudo -n -u ' . escapeshellarg($runAsUser) . ' -- '
                . $sudoPrefix
                . $cmd;
            $envPrefix = '';
        }
    }
    // `setsid -f` detaches immediately; we later resolve the real PID by env markers.
    $snippet = 'cd ' . escapeshellarg($workDir)
        . ' && ' . $envPrefix . 'setsid -f ' . $cmd
        . ' >> ' . escapeshellarg($logPath)
        . ' 2>&1 < /dev/null';

    $out = [];
    exec('/bin/bash -lc ' . escapeshellarg($snippet), $out, $rc);
    if ((int) $rc !== 0) {
        return 0;
    }

    return 0;
}

function normalizeMacHex(?string $mac): ?string
{
    if ($mac === null) {
        return null;
    }

    $hex = strtolower(preg_replace('/[^a-f0-9]/i', '', $mac));
    if (!is_string($hex) || strlen($hex) !== 12 || !preg_match('/^[a-f0-9]{12}$/', $hex)) {
        return null;
    }

    return $hex;
}

function incrementMacHex(string $baseHex, int $offset): string
{
    $bytes = [];
    for ($i = 0; $i < 12; $i += 2) {
        $bytes[] = hexdec(substr($baseHex, $i, 2));
    }

    $carry = max(0, $offset);
    for ($i = 5; $i >= 0; $i--) {
        $sum = $bytes[$i] + ($carry & 0xff);
        $bytes[$i] = $sum & 0xff;
        $carry = ($carry >> 8) + ($sum >> 8);
    }

    return sprintf(
        '%02x:%02x:%02x:%02x:%02x:%02x',
        $bytes[0],
        $bytes[1],
        $bytes[2],
        $bytes[3],
        $bytes[4],
        $bytes[5]
    );
}

function runtimeMacAddress(string $nodeId, int $index, ?string $firstMac): string
{
    $baseHex = normalizeMacHex($firstMac);
    if ($baseHex !== null) {
        return incrementMacHex($baseHex, $index);
    }

    $hash = hash('sha256', strtolower($nodeId) . ':' . $index);
    return sprintf(
        '52:54:%02x:%02x:%02x:%02x',
        hexdec(substr($hash, 0, 2)),
        hexdec(substr($hash, 2, 2)),
        hexdec(substr($hash, 4, 2)),
        hexdec(substr($hash, 6, 2))
    );
}

function runtimeMcastEndpoint(string $key): array
{
    $hash = hash('sha256', $key);

    $oct2 = 64 + (hexdec(substr($hash, 0, 2)) % 64);
    $oct3 = 1 + (hexdec(substr($hash, 2, 2)) % 254);
    $oct4 = 1 + (hexdec(substr($hash, 4, 2)) % 254);
    $port = 20000 + (hexdec(substr($hash, 6, 4)) % 30000);

    return [
        'addr' => '239.' . $oct2 . '.' . $oct3 . '.' . $oct4,
        'port' => $port,
    ];
}

function runtimeEthIndexFromName(string $name): ?int
{
    $name = strtolower(trim($name));
    if (preg_match('/^eth([0-9]+)$/', $name, $m)) {
        return (int) $m[1];
    }
    return null;
}

function runtimeBuildPortIndexMap(array $ports, int $ethCount): array
{
    $ethCount = max(0, $ethCount);
    $indexed = array_fill(0, $ethCount, null);
    if ($ethCount === 0) {
        return $indexed;
    }

    $used = [];
    foreach ($ports as $port) {
        if (!is_array($port)) {
            continue;
        }

        $idx = runtimeEthIndexFromName((string) ($port['name'] ?? ''));
        if ($idx === null || $idx < 0 || $idx >= $ethCount || isset($used[$idx])) {
            $idx = null;
            for ($fallback = 0; $fallback < $ethCount; $fallback++) {
                if (!isset($used[$fallback])) {
                    $idx = $fallback;
                    break;
                }
            }
        }

        if ($idx === null || $idx < 0 || $idx >= $ethCount) {
            continue;
        }

        $indexed[$idx] = $port;
        $used[$idx] = true;
    }

    return $indexed;
}

function runtimeNetworkUdpPairPorts(string $networkId): array
{
    $seed = runtimeHash32('udp-link:' . strtolower(trim($networkId)));
    // Use an even base within 12000..59998 to reserve the adjacent port for peer.
    $base = 12000 + (($seed % 24000) * 2);
    return [
        'a' => $base,
        'b' => $base + 1,
    ];
}

function runtimePortUdpPointToPoint(?array $port): ?array
{
    if (!is_array($port)) {
        return null;
    }

    $networkId = trim((string) ($port['network_id'] ?? ''));
    if ($networkId === '') {
        return null;
    }

    $networkType = strtolower(trim((string) ($port['network_type'] ?? '')));
    if ($networkType !== '' && preg_match('/^pnet[0-9]+$/', $networkType)) {
        return null;
    }

    $endpointCount = (int) ($port['network_endpoint_count'] ?? 0);
    $endpointRank = (int) ($port['network_endpoint_rank'] ?? 0);
    if ($endpointCount !== 2 || $endpointRank < 1 || $endpointRank > 2) {
        return null;
    }

    $pair = runtimeNetworkUdpPairPorts($networkId);
    $local = ($endpointRank === 1) ? $pair['a'] : $pair['b'];
    $remote = ($endpointRank === 1) ? $pair['b'] : $pair['a'];

    return [
        'mode' => 'udp_p2p',
        'network_id' => $networkId,
        'local_port' => (int) $local,
        'remote_port' => (int) $remote,
    ];
}

function runtimeBuildNetworkKeys(array $ports, int $ethCount, string $labId, string $nodeId): array
{
    $keys = array_fill(0, max(0, $ethCount), '');
    $indexedPorts = runtimeBuildPortIndexMap($ports, $ethCount);

    for ($i = 0; $i < $ethCount; $i++) {
        $port = $indexedPorts[$i] ?? null;
        if (!is_array($port)) {
            $keys[$i] = 'isolated:' . $labId . ':' . $nodeId . ':' . $i;
            continue;
        }

        $networkId = $port['network_id'] ?? null;
        $networkType = strtolower(trim((string) ($port['network_type'] ?? '')));

        if ($networkId !== null && $networkId !== '') {
            if ($networkType !== '' && preg_match('/^pnet[0-9]+$/', $networkType)) {
                $keys[$i] = 'pnet:' . $networkType;
            } else {
                $keys[$i] = 'network:' . (string) $networkId;
            }
        } else {
            $keys[$i] = 'isolated:' . $labId . ':' . $nodeId . ':' . $i;
        }
    }

    return $keys;
}

function runtimeListImageFiles(string $imageDir): array
{
    if (!is_dir($imageDir)) {
        return [];
    }

    $files = [];
    foreach (scandir($imageDir) ?: [] as $filename) {
        if ($filename === '.' || $filename === '..') {
            continue;
        }
        $path = $imageDir . '/' . $filename;
        if (is_file($path)) {
            $files[] = $filename;
        }
    }

    usort($files, static function (string $a, string $b): int {
        return strnatcasecmp($a, $b);
    });

    return $files;
}

function runtimeDiskLikeExtensions(): array
{
    return ['qcow2', 'img', 'raw', 'vmdk', 'vdi', 'vhd', 'vhdx'];
}

function runtimeDiskLikePattern(): string
{
    return '(?:' . implode('|', runtimeDiskLikeExtensions()) . ')';
}

function runtimeLooksLikeOverlayDiskFile(string $filename): bool
{
    $lower = strtolower($filename);
    if ($lower === 'cdrom.iso' || $lower === 'kernel.img') {
        return false;
    }
    return preg_match('/\.' . runtimeDiskLikePattern() . '$/i', $filename) === 1;
}

function runtimeQemuImgBinary(): string
{
    return @is_executable('/usr/bin/qemu-img') ? '/usr/bin/qemu-img' : 'qemu-img';
}

function runtimeDetectDiskFormat(string $qemuImg, string $path): string
{
    if (!is_file($path)) {
        return '';
    }

    $cmd = escapeshellarg($qemuImg)
        . ' info --output=json '
        . escapeshellarg($path)
        . ' 2>/dev/null';
    $out = [];
    exec($cmd, $out, $rc);
    if ((int) $rc !== 0) {
        return '';
    }

    $json = json_decode(implode("\n", $out), true);
    if (!is_array($json)) {
        return '';
    }

    $format = strtolower(trim((string) ($json['format'] ?? '')));
    if ($format === '' || preg_match('/^[a-z0-9._+-]+$/', $format) !== 1) {
        return '';
    }
    return $format;
}

function runtimeEnsureNodeOverlays(string $imageDir, string $nodeDir): array
{
    $files = runtimeListImageFiles($imageDir);
    if (empty($files)) {
        throw new RuntimeException('Node image folder is empty');
    }

    $diskFiles = [];
    foreach ($files as $filename) {
        if (runtimeLooksLikeOverlayDiskFile($filename)) {
            $diskFiles[] = $filename;
        }
    }

    if (empty($diskFiles)) {
        throw new RuntimeException('No disk files found in node image folder');
    }

    $qemuImg = runtimeQemuImgBinary();

    foreach ($files as $filename) {
        $base = $imageDir . '/' . $filename;
        $overlay = $nodeDir . '/' . $filename;

        if (!runtimeLooksLikeOverlayDiskFile($filename)) {
            if (file_exists($overlay)) {
                continue;
            }
            if (@symlink($base, $overlay) || is_link($overlay)) {
                continue;
            }
            if (!@copy($base, $overlay)) {
                throw new RuntimeException('Failed to prepare runtime file for ' . $filename);
            }
            @chmod($overlay, 0664);
            continue;
        }

        if (file_exists($overlay)) {
            $overlayFormat = runtimeDetectDiskFormat($qemuImg, $overlay);
            if ($overlayFormat === 'qcow2') {
                continue;
            }
            @unlink($overlay);
        }

        $backingFormat = runtimeDetectDiskFormat($qemuImg, $base);
        $cmd = escapeshellarg($qemuImg)
            . ' create -f qcow2'
            . ($backingFormat !== '' ? (' -F ' . escapeshellarg($backingFormat)) : '')
            . ' -b ' . escapeshellarg($base)
            . ' ' . escapeshellarg($overlay)
            . ' 2>&1';

        $out = [];
        exec($cmd, $out, $rc);
        if ((int) $rc !== 0) {
            $err = trim(implode("\n", $out));
            throw new RuntimeException('Failed to create disk overlay for ' . $filename . ($err !== '' ? (': ' . $err) : ''));
        }

        @chmod($overlay, 0664);
    }

    return $files;
}

function runtimeAppendDiskArgs(array &$args, array $files, string $imageDir, string $nodeDir, string $template): void
{
    $hasMegasas = false;
    $hasLsi = false;
    $diskPattern = runtimeDiskLikePattern();

    foreach ($files as $filename) {
        if (preg_match('/^megasas[a-z]+\.' . $diskPattern . '$/i', $filename)) {
            $hasMegasas = true;
        }
        if (preg_match('/^lsi[a-z]+\.' . $diskPattern . '$/i', $filename)) {
            $hasLsi = true;
        }
    }

    if ($hasMegasas) {
        $args[] = '-device';
        $args[] = 'megasas,id=scsi0,bus=pci.0,addr=0x5';
    } elseif ($hasLsi) {
        $args[] = '-device';
        $args[] = 'lsi,id=scsi0,bus=pci.0,addr=0x5';
    }

    $fallbackDisk = 0;
    foreach ($files as $filename) {
        $basePath = $imageDir . '/' . $filename;
        $overlayPath = $nodeDir . '/' . $filename;

        if ($filename === 'cdrom.iso') {
            $args[] = '-cdrom';
            $args[] = $basePath;
            continue;
        }

        if ($filename === 'kernel.img') {
            $args[] = '-kernel';
            $args[] = $basePath;
            continue;
        }

        if (!runtimeLooksLikeOverlayDiskFile($filename)) {
            continue;
        }

        if (preg_match('/^megasas([a-z]+)\.' . $diskPattern . '$/i', $filename, $m)) {
            $letter = strtolower($m[1][0]);
            $lun = max(0, ord($letter) - 97);
            $args[] = '-device';
            $args[] = 'scsi-disk,bus=scsi0.0,scsi-id=' . $lun . ',drive=drive-scsi0-0-' . $lun . ',id=scsi0-0-' . $lun . ',bootindex=' . $lun;
            $args[] = '-drive';
            $args[] = 'file=' . $overlayPath . ',if=none,id=drive-scsi0-0-' . $lun . ',cache=none';
            continue;
        }

        if (preg_match('/^lsi([a-z]+)\.' . $diskPattern . '$/i', $filename, $m)) {
            $letter = strtolower($m[1][0]);
            $lun = max(0, ord($letter) - 97);
            $args[] = '-device';
            $args[] = 'scsi-disk,bus=scsi0.0,scsi-id=' . $lun . ',drive=drive-scsi0-0-' . $lun . ',id=scsi0-0-' . $lun . ',bootindex=' . $lun;
            $args[] = '-drive';
            $args[] = 'file=' . $overlayPath . ',if=none,id=drive-scsi0-0-' . $lun . ',cache=none';
            continue;
        }

        if (preg_match('/^hd([a-z])\.' . $diskPattern . '$/i', $filename, $m)) {
            $args[] = '-hd' . strtolower($m[1]);
            $args[] = $overlayPath;
            if (strtolower($template) === 'nxosv9k') {
                $args[] = '-bios';
                $args[] = '/opt/qemu/share/qemu/OVMF.fd';
                $args[] = '-drive';
                $args[] = 'file=' . $overlayPath . ',if=ide,index=2';
            }
            continue;
        }

        if (preg_match('/^virtide([a-z])\.' . $diskPattern . '$/i', $filename, $m)) {
            $diskNum = max(0, ord(strtolower($m[1])) - 97);
            $args[] = '-device';
            $args[] = 'virtio-blk-pci,scsi=off,drive=idedisk' . $diskNum . ',id=hd' . strtolower($m[1]) . ',bootindex=1';
            $args[] = '-drive';
            $args[] = 'file=' . $overlayPath . ',if=none,id=idedisk' . $diskNum . ',format=qcow2,cache=none';
            continue;
        }

        if (preg_match('/^virtio([a-z])\.' . $diskPattern . '$/i', $filename, $m)) {
            $lun = max(0, ord(strtolower($m[1])) - 97);
            $args[] = '-drive';
            $args[] = 'file=' . $overlayPath . ',if=virtio,bus=0,unit=' . $lun . ',cache=none';
            continue;
        }

        if (preg_match('/^scsi([a-z])\.' . $diskPattern . '$/i', $filename, $m)) {
            $lun = max(0, ord(strtolower($m[1])) - 97);
            $args[] = '-drive';
            $args[] = 'file=' . $overlayPath . ',if=scsi,bus=0,unit=' . $lun . ',cache=none';
            continue;
        }

        if (preg_match('/^sata([a-z])\.' . $diskPattern . '$/i', $filename, $m)) {
            $diskNum = max(0, ord(strtolower($m[1])) - 97);
            $args[] = '-device';
            $args[] = 'ahci,id=ahci' . $diskNum . ',bus=pci.0';
            $args[] = '-drive';
            $args[] = 'file=' . $overlayPath . ',if=none,id=drive-sata-disk' . $diskNum . ',format=qcow2';
            $args[] = '-device';
            $args[] = 'ide-drive,bus=ahci' . $diskNum . '.0,drive=drive-sata-disk' . $diskNum . ',id=drive-sata-disk' . $diskNum . ',bootindex=' . ($diskNum + 1);
            if (strtolower($template) === 'nxosv9k') {
                $args[] = '-bios';
                $args[] = '/opt/qemu/share/qemu/OVMF-sata.fd';
            }
            continue;
        }

        $args[] = '-drive';
        $args[] = 'file=' . $overlayPath . ',if=virtio,bus=0,unit=' . $fallbackDisk . ',cache=none';
        $fallbackDisk++;
    }
}

function runtimeLoadNodeContext(PDO $db, string $labId, string $nodeId): array
{
    $stmt = $db->prepare(
        "SELECT n.id,
                n.lab_id,
                l.name AS lab_name,
                l.author_user_id::text AS author_user_id,
                owner.username AS author_username,
                n.name,
                n.node_type,
                n.template,
                n.image,
                n.console,
                n.cpu,
                n.ram_mb,
                n.nvram_mb,
                n.first_mac::text AS first_mac,
                n.qemu_arch,
                n.qemu_version,
                n.qemu_nic,
                n.qemu_options,
                n.ethernet_count,
                n.serial_count,
                n.runtime_pid,
                n.runtime_console_port,
                n.runtime_check_console_port
         FROM lab_nodes n
         INNER JOIN labs l ON l.id = n.lab_id
         LEFT JOIN users owner ON owner.id = l.author_user_id
         WHERE n.id = :node_id
           AND n.lab_id = :lab_id
         LIMIT 1"
    );
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Node not found');
    }

    $portsStmt = $db->prepare(
        "SELECT p.id,
                p.name,
                p.network_id,
                nw.network_type,
                CASE
                    WHEN p.network_id IS NULL THEN 0
                    ELSE (
                        SELECT COUNT(*)
                        FROM lab_node_ports p2
                        WHERE p2.network_id = p.network_id
                          AND p2.port_type = 'ethernet'
                    )
                END AS network_endpoint_count,
                CASE
                    WHEN p.network_id IS NULL THEN 0
                    ELSE (
                        SELECT COUNT(*)
                        FROM lab_node_ports p2
                        WHERE p2.network_id = p.network_id
                          AND p2.port_type = 'ethernet'
                          AND p2.id <= p.id
                    )
                END AS network_endpoint_rank
         FROM lab_node_ports p
         LEFT JOIN lab_networks nw ON nw.id = p.network_id
         WHERE p.node_id = :node_id
           AND p.port_type = 'ethernet'
         ORDER BY p.created_at ASC, p.name ASC"
    );
    $portsStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $portsStmt->execute();
    $ports = $portsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($ports)) {
        $ports = [];
    }

    $row['ports'] = $ports;
    return $row;
}

function runtimeLoadTemplateConfig(string $template): array
{
    $template = trim($template);
    if ($template === '') {
        return [];
    }

    if (function_exists('getNodeTemplateV2')) {
        $tpl = getNodeTemplateV2($template);
        if (is_array($tpl)) {
            return $tpl;
        }
    }

    if (!function_exists('v2TemplateDir')) {
        return [];
    }

    $path = rtrim(v2TemplateDir(), '/') . '/' . $template . '.yml';
    if (!is_file($path)) {
        return [];
    }
    $data = @yaml_parse_file($path);
    return is_array($data) ? $data : [];
}

function runtimeBuildQemuLaunchSpec(array $ctx, string $labId, string $nodeId, string $nodeDir, string $pidPath): array
{
    $image = trim((string) ($ctx['image'] ?? ''));
    if ($image === '') {
        throw new RuntimeException('Node image is required');
    }

    $imageDir = '/opt/unetlab/addons/qemu/' . $image;
    if (!is_dir($imageDir)) {
        throw new RuntimeException('Image folder not found: ' . $image);
    }

    $files = runtimeEnsureNodeOverlays($imageDir, $nodeDir);

    $qemuArch = normalizeQemuArch((string) ($ctx['qemu_arch'] ?? 'x86_64'));
    $qemuVersion = trim((string) ($ctx['qemu_version'] ?? ''));
    $binary = resolveQemuBinary($qemuArch, $qemuVersion);

    $cpu = max(1, (int) ($ctx['cpu'] ?? 1));
    $ram = max(256, (int) ($ctx['ram_mb'] ?? 1024));
    $name = trim((string) ($ctx['name'] ?? 'Node'));
    if ($name === '') {
        $name = 'Node';
    }

    $nicDriver = runtimeResolveQemuNicDriver($ctx);

    $console = strtolower(trim((string) ($ctx['console'] ?? 'telnet')));

    $args = [];
    $args[] = '-smp';
    $args[] = (string) $cpu;
    $args[] = '-m';
    $args[] = (string) $ram;
    $args[] = '-name';
    $args[] = $name;
    $args[] = '-uuid';
    $args[] = $nodeId;

    $enableGuestAgent = (runtimeQemuLooksLikeLinuxNode($ctx) || runtimeQemuLooksLikeWindowsNode($ctx))
        && !runtimeQemuOptionsHasGuestAgent((string) ($ctx['qemu_options'] ?? ''));
    if ($enableGuestAgent) {
        runtimeCleanupQgaSocket($nodeId);
        $args[] = '-chardev';
        $args[] = 'socket,id=v2qga0,path=' . runtimeQgaSocketPath($nodeId) . ',server,nowait';
        $args[] = '-device';
        $args[] = 'virtio-serial-pci,id=v2qga-serial0';
        $args[] = '-device';
        $args[] = 'virtserialport,chardev=v2qga0,name=org.qemu.guest_agent.0';
    }

    $consolePort = null;
    $checkConsolePort = null;
    if ($console === 'vnc') {
        $consolePort = runtimeAllocateConsolePort(
            $labId,
            $nodeId,
            'qemu-vnc',
            isset($ctx['runtime_console_port']) ? (int) $ctx['runtime_console_port'] : null
        );
        $display = $consolePort - 5900;
        if ($display < 1) {
            $display = 1;
            $consolePort = 5901;
        }
        $args[] = '-vnc';
        $args[] = '127.0.0.1:' . $display;
        $checkConsolePort = runtimeAllocateConsolePort(
            $labId,
            $nodeId,
            'qemu-check',
            isset($ctx['runtime_check_console_port']) ? (int) $ctx['runtime_check_console_port'] : null
        );
        $args[] = '-chardev';
        $args[] = 'socket,id=v2checkserial0,host=127.0.0.1,port=' . $checkConsolePort . ',server,nowait,telnet=off';
        $args[] = '-serial';
        $args[] = 'chardev:v2checkserial0';
        $args[] = '-monitor';
        $args[] = 'none';
    } elseif ($console === 'telnet') {
        $consolePort = runtimeAllocateConsolePort(
            $labId,
            $nodeId,
            'qemu',
            isset($ctx['runtime_console_port']) ? (int) $ctx['runtime_console_port'] : null
        );
        $args[] = '-nographic';
        $args[] = '-chardev';
        $args[] = 'socket,id=v2serial0,host=127.0.0.1,port=' . $consolePort . ',server,nowait,telnet=off';
        $args[] = '-serial';
        $args[] = 'chardev:v2serial0';
        $args[] = '-monitor';
        $args[] = 'none';
    } else {
        $args[] = '-nographic';
    }

    runtimeCleanupQmpSocket($nodeId);
    $args[] = '-qmp';
    $args[] = 'unix:' . runtimeQmpSocketPath($nodeId) . ',server,nowait';

    $ethCount = max(0, (int) ($ctx['ethernet_count'] ?? 0));
    $indexedPorts = runtimeBuildPortIndexMap((array) ($ctx['ports'] ?? []), $ethCount);
    $netKeys = runtimeBuildNetworkKeys((array) ($ctx['ports'] ?? []), $ethCount, $labId, $nodeId);
    $networks = [];
    $logPath = runtimeNodeLogPathForType($nodeDir, 'qemu');

    for ($i = 0; $i < $ethCount; $i++) {
        $key = $netKeys[$i] ?? ('isolated:' . $labId . ':' . $nodeId . ':' . $i);
        $portMeta = $indexedPorts[$i] ?? null;
        $mac = runtimeMacAddress($nodeId, $i, $ctx['first_mac'] ?? null);
        $netId = 'net' . $i;
        $bridge = runtimeTapBridgeForPort($labId, is_array($portMeta) ? $portMeta : null);
        $tap = runtimeQemuTapName($labId, $nodeId, $i);
        $isCloudPort = $bridge !== '' && preg_match('/^pnet[0-9]+$/', $bridge) === 1;

        if (!runtimeEnsureTapInterface($tap, $logPath)) {
            throw new RuntimeException('Failed to prepare QEMU TAP interface: ' . $tap);
        }
        if ($bridge !== '' && !runtimeEnsureLinuxBridgeUp($bridge, $logPath)) {
            throw new RuntimeException('Failed to prepare bridge "' . $bridge . '" for QEMU TAP: ' . $tap);
        }
        $move = runtimeMoveTapToBridge($tap, $bridge, $logPath);
        if (empty($move['ok'])) {
            throw new RuntimeException(
                'Failed to move QEMU TAP "' . $tap . '" to bridge "' . $bridge . '": ' . (string) ($move['error'] ?? 'unknown')
            );
        }
        runtimeSetTapLinkState($tap, $bridge !== '', $logPath);

        $args[] = '-device';
        $args[] = $nicDriver . ',id=nic' . $i . ',netdev=' . $netId . ',mac=' . $mac;
        $args[] = '-netdev';
        $args[] = 'tap,id=' . $netId . ',ifname=' . $tap . ',script=no,downscript=no';

        $networkInfo = [
            'index' => $i,
            'key' => $key,
            'mac' => $mac,
            'tap' => $tap,
        ];
        if ($bridge === '') {
            $networkInfo['mode'] = 'tap_isolated';
        } elseif ($isCloudPort) {
            $networkInfo['mode'] = 'cloud_bridge';
        } else {
            $networkInfo['mode'] = 'tap_bridge';
        }
        $networkInfo['bridge'] = $bridge !== '' ? $bridge : null;
        $networks[] = $networkInfo;
    }

    runtimeAppendDiskArgs($args, $files, $imageDir, $nodeDir, (string) ($ctx['template'] ?? ''));

    $extraOptions = normalizeQemuOptionTokens(splitShellArgs((string) ($ctx['qemu_options'] ?? '')));
    $extraOptions = runtimeFilterExtraQemuOptionsByFiles($extraOptions, $nodeDir, $imageDir);
    $extraOptions = runtimeFilterExtraQemuOptionsByConsole($extraOptions, $console);
    if ($console === 'vnc' && !runtimeQemuOptionsProvideDisplay($extraOptions)) {
        $args[] = '-vga';
        $args[] = 'std';
    }
    foreach ($extraOptions as $token) {
        $args[] = $token;
    }

    $args[] = '-daemonize';
    $args[] = '-pidfile';
    $args[] = $pidPath;

    return [
        'launch_mode' => 'qemu_daemonize',
        'binary' => $binary,
        'args' => $args,
        'console_port' => $consolePort,
        'check_console_port' => $checkConsolePort,
        'qga_socket_path' => $enableGuestAgent ? runtimeQgaSocketPath($nodeId) : null,
        'networks' => $networks,
        'image' => $image,
        'image_files' => $files,
        'process_hints' => ['qemu-system'],
    ];
}

function runtimeFilterExtraQemuOptionsByConsole(array $tokens, string $console): array
{
    $console = strtolower(trim($console));
    if ($console !== 'telnet' && $console !== 'vnc') {
        return $tokens;
    }

    $result = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = (string) $tokens[$i];
        $tokenLower = strtolower($token);
        $hasValue = ($i + 1 < $count);

        if ($console === 'telnet') {
            if (($tokenLower === '-serial' || $tokenLower === '--serial') && $hasValue) {
                $i++;
                continue;
            }
            if (strpos($tokenLower, '-serial=') === 0 || strpos($tokenLower, '--serial=') === 0) {
                continue;
            }

            if (($tokenLower === '-monitor' || $tokenLower === '--monitor') && $hasValue) {
                $i++;
                continue;
            }
            if (strpos($tokenLower, '-monitor=') === 0 || strpos($tokenLower, '--monitor=') === 0) {
                continue;
            }
        }
        if ($console === 'vnc') {
            if ($tokenLower === '-nographic' || $tokenLower === '--nographic') {
                continue;
            }
            if (($tokenLower === '-display' || $tokenLower === '--display') && $hasValue) {
                $i++;
                continue;
            }
            if (strpos($tokenLower, '-display=') === 0 || strpos($tokenLower, '--display=') === 0) {
                continue;
            }
        }

        $result[] = $token;
    }

    return $result;
}

function runtimeQemuOptionsProvideDisplay(array $tokens): bool
{
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = (string) $tokens[$i];
        $tokenLower = strtolower($token);
        $next = ($i + 1 < $count) ? strtolower((string) $tokens[$i + 1]) : '';

        if ($tokenLower === '-vga' || $tokenLower === '--vga' || strpos($tokenLower, '-vga=') === 0 || strpos($tokenLower, '--vga=') === 0) {
            return true;
        }

        if ($tokenLower === '-device' && $next !== '') {
            if (strpos($next, 'vga') !== false || strpos($next, 'virtio-gpu') !== false || strpos($next, 'qxl') !== false) {
                return true;
            }
            $i++;
            continue;
        }
        if (strpos($tokenLower, '-device=') === 0) {
            $value = substr($tokenLower, strlen('-device='));
            if (strpos($value, 'vga') !== false || strpos($value, 'virtio-gpu') !== false || strpos($value, 'qxl') !== false) {
                return true;
            }
        }
    }
    return false;
}

function runtimeBuildVpcsLaunchSpec(array $ctx, string $labId, string $nodeId, string $nodeDir): array
{
    $binary = '';
    $candidates = [
        '/opt/vpcsu/bin/vpcs',
        '/usr/bin/vpcs',
        '/usr/local/bin/vpcs',
    ];
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            $binary = $candidate;
            break;
        }
    }
    if ($binary === '') {
        throw new RuntimeException('vpcs binary is not available');
    }
    runtimeEnsureVpcsBinaryCompatible($binary);

    $consolePort = runtimeAllocateConsolePort(
        $labId,
        $nodeId,
        'vpcs',
        isset($ctx['runtime_console_port']) ? (int) $ctx['runtime_console_port'] : null
    );
    $macSeed = runtimeStableIntInRange($labId . ':' . $nodeId, 1, 240);
    $indexedPorts = runtimeBuildPortIndexMap((array) ($ctx['ports'] ?? []), 1);
    $portMeta = is_array($indexedPorts[0] ?? null) ? $indexedPorts[0] : null;
    $bridge = runtimeTapBridgeForPort($labId, $portMeta);
    $tap = runtimeVpcsTapName($labId, $nodeId);
    $logPath = runtimeNodeLogPathForType($nodeDir, 'vpcs');
    if (!runtimeEnsureTapInterface($tap, $logPath)) {
        throw new RuntimeException('Failed to prepare VPCS TAP interface: ' . $tap);
    }
    if ($bridge !== '' && !runtimeEnsureLinuxBridgeUp($bridge, $logPath)) {
        throw new RuntimeException('Failed to prepare bridge "' . $bridge . '" for VPCS TAP: ' . $tap);
    }
    $move = runtimeMoveTapToBridge($tap, $bridge, $logPath);
    if (empty($move['ok'])) {
        throw new RuntimeException(
            'Failed to move VPCS TAP "' . $tap . '" to bridge "' . $bridge . '": ' . (string) ($move['error'] ?? 'unknown')
        );
    }
    runtimeSetTapLinkState($tap, $bridge !== '', $logPath);

    $args = [
        '-i', '1',
        '-p', (string) $consolePort,
        '-m', (string) $macSeed,
        '-e',
        '-d', $tap,
    ];
    $networkMode = $bridge === ''
        ? 'tap_isolated'
        : (preg_match('/^pnet[0-9]+$/', $bridge) === 1 ? 'cloud_bridge' : 'tap_bridge');
    $networks = [[
        'index' => 0,
        'mode' => $networkMode,
        'tap' => $tap,
        'bridge' => $bridge !== '' ? $bridge : null,
    ]];

    return [
        'launch_mode' => 'background',
        'binary' => $binary,
        'args' => $args,
        'console_port' => $consolePort,
        'networks' => $networks,
        'image' => null,
        'process_hints' => ['vpcs'],
    ];
}

function runtimeVpcsBinaryVersion(string $binary): string
{
    static $cache = [];

    $binary = trim($binary);
    if ($binary === '') {
        return '';
    }
    if (array_key_exists($binary, $cache)) {
        return (string) $cache[$binary];
    }

    $cmd = escapeshellarg($binary) . ' -v 2>&1';
    $raw = shell_exec($cmd);
    if (!is_string($raw) || trim($raw) === '') {
        $cache[$binary] = '';
        return '';
    }

    $version = '';
    if (preg_match('/version\s+([^\r\n]+)/i', $raw, $m) === 1) {
        $version = trim((string) $m[1]);
    }

    $cache[$binary] = $version;
    return $version;
}

function runtimeVpcsVersionLooksBroken(string $version): bool
{
    $value = strtolower(trim($version));
    if ($value === '') {
        return false;
    }
    return strpos($value, '0.5b2') !== false;
}

function runtimeEnsureVpcsBinaryCompatible(string $binary): void
{
    $version = runtimeVpcsBinaryVersion($binary);
    if (!runtimeVpcsVersionLooksBroken($version)) {
        return;
    }

    throw new RuntimeException(
        'Unsupported VPCS binary "' . $version . '" at ' . $binary
        . '. Install VPCS 1.3 (0.8.1) or newer and relink /opt/vpcsu/bin/vpcs.'
    );
}



function runtimeBuildLaunchSpec(array $ctx, string $labId, string $nodeId, string $nodeDir, string $pidPath): array
{
    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
    switch ($nodeType) {
        case 'qemu':
            return runtimeBuildQemuLaunchSpec($ctx, $labId, $nodeId, $nodeDir, $pidPath);
        case 'vpcs':
            return runtimeBuildVpcsLaunchSpec($ctx, $labId, $nodeId, $nodeDir);
        default:
            throw new RuntimeException('Node type is not supported yet in v2 runtime: ' . $nodeType);
    }
}

function buildShellCommandFromArgv(string $binary, array $args): string
{
    $parts = [escapeshellarg($binary)];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }
    return implode(' ', $parts);
}

function runtimeLooksLikeKvmError(string $text): bool
{
    $text = strtolower($text);
    if ($text === '') {
        return false;
    }
    return strpos($text, 'failed to initialize kvm') !== false
        || strpos($text, 'could not access kvm kernel module') !== false
        || strpos($text, 'kernel doesn\'t allow setting hyperv') !== false
        || strpos($text, "kernel doesn't allow setting hyperv") !== false
        || (strpos($text, 'cpu model') !== false && strpos($text, 'requires kvm') !== false)
        || strpos($text, 'permission denied') !== false
        || strpos($text, 'requires kvm') !== false;
}

function runtimeResolveQemuOptionFile(string $path, string $nodeDir, string $imageDir): ?string
{
    $path = trim($path);
    if ($path === '') {
        return null;
    }
    $path = trim($path, "\"'");
    if ($path === '') {
        return null;
    }

    if ($path[0] === '/') {
        return is_file($path) ? $path : null;
    }

    $localPath = rtrim($nodeDir, '/') . '/' . $path;
    if (is_file($localPath)) {
        return $path;
    }

    $imagePath = rtrim($imageDir, '/') . '/' . $path;
    if (is_file($imagePath)) {
        return $imagePath;
    }

    $sharedQemuPath = '/opt/qemu/share/qemu/' . $path;
    if (is_file($sharedQemuPath)) {
        return $sharedQemuPath;
    }

    return null;
}

function runtimeNormalizeDriveOptionValue(string $value, string $nodeDir, string $imageDir): ?string
{
    if (!preg_match('/(^|,)file=([^,]+)/i', $value, $m)) {
        return $value;
    }

    $rawPath = trim((string) ($m[2] ?? ''));
    $resolved = runtimeResolveQemuOptionFile($rawPath, $nodeDir, $imageDir);
    if ($resolved === null) {
        return null;
    }

    return preg_replace('/(^|,)file=([^,]+)/i', '$1file=' . $resolved, $value, 1);
}

function runtimeFilterExtraQemuOptionsByFiles(array $tokens, string $nodeDir, string $imageDir): array
{
    $result = [];
    $singleFileArgs = [
        '-cdrom' => true,
        '-kernel' => true,
        '-initrd' => true,
        '-bios' => true,
        '-pflash' => true,
        '-hda' => true,
        '-hdb' => true,
        '-hdc' => true,
        '-hdd' => true,
        '-fda' => true,
        '-fdb' => true,
    ];

    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = (string) $tokens[$i];
        $tokenLower = strtolower($token);
        $next = ($i + 1 < $count) ? (string) $tokens[$i + 1] : '';

        if (isset($singleFileArgs[$tokenLower]) && $next !== '') {
            $resolved = runtimeResolveQemuOptionFile($next, $nodeDir, $imageDir);
            if ($resolved !== null) {
                $result[] = $token;
                $result[] = $resolved;
            }
            $i++;
            continue;
        }

        if ($tokenLower === '-drive' && $next !== '') {
            $normalized = runtimeNormalizeDriveOptionValue($next, $nodeDir, $imageDir);
            if ($normalized !== null && trim($normalized) !== '') {
                $result[] = $token;
                $result[] = $normalized;
            }
            $i++;
            continue;
        }

        $result[] = $token;
    }

    return $result;
}

function runtimeBuildSoftwareFallbackArgs(array $args): array
{
    $fallback = [];
    $count = count($args);

    for ($i = 0; $i < $count; $i++) {
        $token = (string) $args[$i];

        if ($token === '-enable-kvm' || $token === '--enable-kvm') {
            continue;
        }

        if (($token === '-machine' || $token === '--machine') && $i + 1 < $count) {
            $value = (string) $args[$i + 1];
            if (stripos($value, 'accel=') !== false) {
                $value = preg_replace('/accel=([a-z0-9:_-]+)/i', 'accel=tcg', $value);
            } else {
                $value .= ',accel=tcg';
            }
            $fallback[] = $token;
            $fallback[] = $value;
            $i++;
            continue;
        }

        if (($token === '-accel' || $token === '--accel') && $i + 1 < $count) {
            $fallback[] = $token;
            $fallback[] = 'tcg';
            $i++;
            continue;
        }

        if ($token === '-cpu' && $i + 1 < $count) {
            $value = (string) $args[$i + 1];
            $valueLower = strtolower(trim($value));
            if ($valueLower === 'host' || strpos($valueLower, 'host,') === 0) {
                $value = 'qemu64';
            } else {
                $value = preg_replace('/(^|,)(hv_[a-z0-9_]+(?:=[^,]+)?)/i', '$1', $value);
                $value = preg_replace('/(^|,)(kvm_[a-z0-9_]+(?:=[^,]+)?)/i', '$1', $value);
                $value = preg_replace('/,{2,}/', ',', $value);
                $value = trim((string) $value, ',');
                if ($value === '') {
                    $value = 'qemu64';
                }
            }
            $fallback[] = '-cpu';
            $fallback[] = $value;
            $i++;
            continue;
        }

        $fallback[] = $token;
    }

    return $fallback;
}

function runtimeLaunchDaemonizedQemu(string $labId, string $nodeId, string $nodeDir, string $logPath, string $pidPath, string $binary, array $args): int
{
    if (is_file($pidPath)) {
        @unlink($pidPath);
    }

    $cmd = buildShellCommandFromArgv($binary, $args);
    $snippet = 'cd ' . escapeshellarg($nodeDir)
        . ' && EVE_V2_LAB_UUID=' . escapeshellarg($labId)
        . ' EVE_V2_NODE_UUID=' . escapeshellarg($nodeId)
        . ' ' . $cmd
        . ' >> ' . escapeshellarg($logPath)
        . ' 2>&1 < /dev/null';

    $out = [];
    exec('/bin/bash -lc ' . escapeshellarg($snippet), $out, $rc);
    if ((int) $rc !== 0) {
        return 0;
    }

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $pid = runtimeReadPidfile($pidPath);
        if ($pid > 1) {
            return $pid;
        }
        usleep(100000);
    }

    return 0;
}

function setNodeRunningState(PDO $db, string $labId, string $nodeId, int $pid, ?int $consolePort, ?int $checkConsolePort = null): void
{
    $stmt = $db->prepare(
        "UPDATE lab_nodes
         SET is_running = TRUE,
             power_state = 'running',
             last_error = NULL,
             power_updated_at = NOW(),
             runtime_pid = :runtime_pid,
             runtime_console_port = :runtime_console_port,
             runtime_check_console_port = :runtime_check_console_port,
             runtime_started_at = NOW(),
             runtime_stopped_at = NULL,
             updated_at = NOW()
         WHERE id = :node_id
           AND lab_id = :lab_id"
    );
    $stmt->bindValue(':runtime_pid', $pid, PDO::PARAM_INT);
    if ($consolePort !== null) {
        $stmt->bindValue(':runtime_console_port', $consolePort, PDO::PARAM_INT);
    } else {
        $stmt->bindValue(':runtime_console_port', null, PDO::PARAM_NULL);
    }
    if ($checkConsolePort !== null) {
        $stmt->bindValue(':runtime_check_console_port', $checkConsolePort, PDO::PARAM_INT);
    } else {
        $stmt->bindValue(':runtime_check_console_port', null, PDO::PARAM_NULL);
    }
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
}

function setNodeStoppedState(PDO $db, string $labId, string $nodeId, ?string $errorText = null): void
{
    $trimmed = trim((string) $errorText);
    if ($trimmed === '') {
        $trimmed = null;
    }
    if ($trimmed !== null && strlen($trimmed) > 2000) {
        $trimmed = substr($trimmed, 0, 2000);
    }

    $stmt = $db->prepare(
        "UPDATE lab_nodes
         SET is_running = FALSE,
             power_state = 'stopped',
             last_error = :last_error,
             power_updated_at = NOW(),
             runtime_pid = NULL,
             runtime_console_port = NULL,
             runtime_check_console_port = NULL,
             runtime_stopped_at = NOW(),
             updated_at = NOW()
         WHERE id = :node_id
           AND lab_id = :lab_id"
    );
    if ($trimmed !== null) {
        $stmt->bindValue(':last_error', $trimmed, PDO::PARAM_STR);
    } else {
        $stmt->bindValue(':last_error', null, PDO::PARAM_NULL);
    }
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
}

function refreshLabNodeRuntimeState(PDO $db, string $labId, string $nodeId): void
{
    $stmt = $db->prepare(
        "SELECT n.name,
                n.runtime_pid,
                n.is_running,
                n.power_state,
                n.runtime_console_port,
                n.runtime_check_console_port,
                n.console,
                n.node_type,
                n.image
         FROM lab_nodes n
         WHERE n.id = :node_id
           AND n.lab_id = :lab_id
         LIMIT 1"
    );
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return;
    }

    $pid = (int) ($row['runtime_pid'] ?? 0);
    $running = !empty($row['is_running']);
    $powerState = strtolower((string) ($row['power_state'] ?? ''));
    $consolePort = isset($row['runtime_console_port']) ? ((int) $row['runtime_console_port'] ?: null) : null;
    $checkConsolePort = isset($row['runtime_check_console_port']) ? ((int) $row['runtime_check_console_port'] ?: null) : null;
    $consoleType = strtolower(trim((string) ($row['console'] ?? '')));
    $nodeType = strtolower(trim((string) ($row['node_type'] ?? '')));
    $image = trim((string) ($row['image'] ?? ''));
    $nodeName = trim((string) ($row['name'] ?? ''));
    $hasActiveTask = false;
    if ($powerState === 'starting' || $powerState === 'stopping') {
        $taskStmt = $db->prepare(
            "SELECT 1
             FROM lab_tasks
             WHERE node_id = :node_id
               AND status IN ('pending', 'running')
             LIMIT 1"
        );
        $taskStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
        $taskStmt->execute();
        $hasActiveTask = $taskStmt->fetchColumn() !== false;
    }

    if ($pid <= 0) {
        // Keep queued transitional states ("starting"/"stopping") intact.
        // They are set by task enqueue and must be visible in UI until worker applies action.
        if ($running || $powerState === 'running' || (($powerState === 'starting' || $powerState === 'stopping') && !$hasActiveTask)) {
            setNodeStoppedState($db, $labId, $nodeId, null);
        }
        return;
    }

    $resolvedPid = 0;
    if ($pid > 1 && runtimePidBelongsToNode($pid, $labId, $nodeId)) {
        $resolvedPid = $pid;
    }

    if ($resolvedPid <= 1 && $nodeType === 'qemu') {
        if ($pid > 1 && runtimePidMatchesQemuNodeUuid($pid, $nodeId)) {
            $resolvedPid = $pid;
        } else {
            $foundQemuPid = runtimeResolveQemuPidByNodeUuid($nodeId, 0, 2);
            if ($foundQemuPid > 1) {
                $resolvedPid = $foundQemuPid;
            }
        }
    }

    if ($resolvedPid <= 1 && $nodeType === 'vpcs') {
        if ($pid > 1 && runtimePidMatchesVpcsConsolePort($pid, $consolePort)) {
            $resolvedPid = $pid;
        } else {
            $foundVpcsPid = runtimeFindVpcsPidByConsolePort($consolePort);
            if ($foundVpcsPid > 1) {
                $resolvedPid = $foundVpcsPid;
            }
        }
    }

    if ($resolvedPid <= 1 && in_array($nodeType, ['qemu', 'vpcs'], true)) {
        $hints = [];
        if ($nodeType === 'qemu') {
            $hints = ['qemu-system'];
        } elseif ($nodeType === 'vpcs') {
            $hints = ['vpcs'];
        }
        $foundPid = runtimeFindNodePidByEnv($labId, $nodeId, $hints, false);
        if ($foundPid > 1) {
            $resolvedPid = $foundPid;
        }
    }

    if ($resolvedPid > 1) {
        // Do not override queued transitional state on page reload while a task is still active.
        // Keep "starting"/"stopping" visible, but refresh runtime PID/ports if they changed.
        if (($powerState === 'starting' || $powerState === 'stopping') && $hasActiveTask) {
            $nextCheckConsolePort = $checkConsolePort;
            if ($nextCheckConsolePort === null && $nodeType === 'qemu' && in_array($consoleType, ['vnc', 'rdp'], true)) {
                $nextCheckConsolePort = $consolePort;
            }
            if ($resolvedPid !== $pid || $nextCheckConsolePort !== $checkConsolePort) {
                $syncStmt = $db->prepare(
                    "UPDATE lab_nodes
                     SET runtime_pid = :runtime_pid,
                         runtime_console_port = :runtime_console_port,
                         runtime_check_console_port = :runtime_check_console_port,
                         updated_at = NOW()
                     WHERE id = :node_id
                       AND lab_id = :lab_id"
                );
                $syncStmt->bindValue(':runtime_pid', $resolvedPid, PDO::PARAM_INT);
                if ($consolePort !== null) {
                    $syncStmt->bindValue(':runtime_console_port', $consolePort, PDO::PARAM_INT);
                } else {
                    $syncStmt->bindValue(':runtime_console_port', null, PDO::PARAM_NULL);
                }
                if ($nextCheckConsolePort !== null) {
                    $syncStmt->bindValue(':runtime_check_console_port', $nextCheckConsolePort, PDO::PARAM_INT);
                } else {
                    $syncStmt->bindValue(':runtime_check_console_port', null, PDO::PARAM_NULL);
                }
                $syncStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
                $syncStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
                $syncStmt->execute();
            }
            return;
        }

        if (!$running || $powerState !== 'running' || $resolvedPid !== $pid) {
            if ($checkConsolePort === null && $nodeType === 'qemu' && in_array($consoleType, ['vnc', 'rdp'], true)) {
                $checkConsolePort = $consolePort;
            }
            setNodeRunningState($db, $labId, $nodeId, $resolvedPid, $consolePort, $checkConsolePort);
        }
        return;
    }

    setNodeStoppedState($db, $labId, $nodeId, null);
}

function refreshLabRuntimeStatesForLab(PDO $db, string $labId): void
{
    $stmt = $db->prepare(
        "SELECT id
         FROM lab_nodes
         WHERE lab_id = :lab_id
           AND (
             runtime_pid IS NOT NULL
             OR is_running = TRUE
             OR power_state IN ('running', 'starting', 'stopping')
           )"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return;
    }

    foreach ($rows as $row) {
        $nodeId = (string) ($row['id'] ?? '');
        if ($nodeId !== '') {
            refreshLabNodeRuntimeState($db, $labId, $nodeId);
        }
    }
}

function startLabNodeRuntime(PDO $db, string $labId, string $nodeId): array
{
    refreshLabNodeRuntimeState($db, $labId, $nodeId);

    $ctx = runtimeLoadNodeContext($db, $labId, $nodeId);
    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
    $ownerUserId = isset($ctx['author_user_id']) ? (string) $ctx['author_user_id'] : '';
    $existingPid = (int) ($ctx['runtime_pid'] ?? 0);
    if ($existingPid > 1) {
        $alreadyRunning = false;
        if ($nodeType === 'qemu') {
            $alreadyRunning = runtimePidMatchesQemuNodeUuid($existingPid, $nodeId);
        } else {
            $alreadyRunning = runtimePidBelongsToNode($existingPid, $labId, $nodeId);
        }
        if ($alreadyRunning) {
            return [
                'already_running' => true,
                'pid' => $existingPid,
            ];
        }
    }
    if ($nodeType === 'qemu' && $existingPid <= 1) {
        $existingQemuPid = runtimeResolveQemuPidByNodeUuid($nodeId, 0, 2);
        if ($existingQemuPid > 1) {
            runtimePruneExtraQemuPids($nodeId, $existingQemuPid);
            setNodeRunningState(
                $db,
                $labId,
                $nodeId,
                $existingQemuPid,
                isset($ctx['runtime_console_port']) ? (is_numeric($ctx['runtime_console_port']) ? (int) $ctx['runtime_console_port'] : null) : null,
                isset($ctx['runtime_check_console_port']) ? (is_numeric($ctx['runtime_check_console_port']) ? (int) $ctx['runtime_check_console_port'] : null) : null
            );
            return [
                'already_running' => true,
                'pid' => $existingQemuPid,
            ];
        }
    }
    try {
        $nodeDir = resolveLabNodeRuntimeDir($labId, $nodeId, $ownerUserId);
        $logPath = runtimeNodeLogPathForType($nodeDir, $nodeType);
        $pidPath = runtimeNodePidPathForType($nodeDir, $nodeType);

        $spec = runtimeBuildLaunchSpec($ctx, $labId, $nodeId, $nodeDir, $pidPath);
        $binary = (string) $spec['binary'];
        $args = (array) $spec['args'];
        $launchMode = strtolower(trim((string) ($spec['launch_mode'] ?? '')));
        if ($launchMode === '') {
            $launchMode = 'background';
        }
        $processHints = is_array($spec['process_hints'] ?? null) ? (array) $spec['process_hints'] : [];

        $pid = 0;
        if ($launchMode === 'qemu_daemonize') {
            $pid = runtimeLaunchDaemonizedQemu($labId, $nodeId, $nodeDir, $logPath, $pidPath, $binary, $args);
            if ($pid <= 1) {
                $tail = runtimeTailText($logPath, 40);
                if (runtimeLooksLikeKvmError($tail)) {
                    $fallbackArgs = runtimeBuildSoftwareFallbackArgs($args);
                    if ($fallbackArgs !== $args) {
                        $pid = runtimeLaunchDaemonizedQemu($labId, $nodeId, $nodeDir, $logPath, $pidPath, $binary, $fallbackArgs);
                        if ($pid > 1) {
                            $args = $fallbackArgs;
                            $spec['fallback_mode'] = 'tcg';
                        }
                    }
                }
            }
        } else {
            $extraEnv = is_array($spec['env'] ?? null) ? (array) $spec['env'] : [];
            $runAsUser = isset($spec['run_as_user']) ? trim((string) $spec['run_as_user']) : '';
            $workDir = isset($spec['workdir']) ? trim((string) $spec['workdir']) : '';
            if ($workDir === '' || !is_dir($workDir)) {
                $workDir = $nodeDir;
            }
            $pid = runtimeLaunchBackgroundProcess(
                $labId,
                $nodeId,
                $workDir,
                $logPath,
                $pidPath,
                $binary,
                $args,
                $extraEnv,
                $runAsUser !== '' ? $runAsUser : null
            );
        }

        $pid = runtimeResolveNodePid($pid, $labId, $nodeId, $processHints, 50);
        if ($pid <= 1) {
            $tail = runtimeTailText($logPath, 40);
            throw new RuntimeException('Failed to read runtime pid' . ($tail !== '' ? (': ' . $tail) : ''));
        }

        usleep(300000);
        if (!runtimePidBelongsToNode($pid, $labId, $nodeId)) {
            $pid = runtimeResolveNodePid($pid, $labId, $nodeId, $processHints, 10);
        }
        $pidValid = runtimePidBelongsToNode($pid, $labId, $nodeId);
        if (!$pidValid) {
            $tail = runtimeTailText($logPath, 40);
            throw new RuntimeException('Node process exited immediately' . ($tail !== '' ? (': ' . $tail) : ''));
        }
        if ($nodeType === 'qemu') {
            runtimePruneExtraQemuPids($nodeId, $pid);
        }

        if ($nodeType === 'qemu') {
            $qgaSocketPath = trim((string) ($spec['qga_socket_path'] ?? ''));
            if ($qgaSocketPath !== '') {
                runtimeEnsureUnixSocketAccessible($qgaSocketPath, 3500);
            }
        }

        setNodeRunningState(
            $db,
            $labId,
            $nodeId,
            $pid,
            isset($spec['console_port']) ? (is_numeric($spec['console_port']) ? (int) $spec['console_port'] : null) : null,
            isset($spec['check_console_port']) ? (is_numeric($spec['check_console_port']) ? (int) $spec['check_console_port'] : null) : null
        );

        $linkSync = null;

        return [
            'already_running' => false,
            'pid' => $pid,
            'console_port' => $spec['console_port'] ?? null,
            'networks' => $spec['networks'] ?? [],
            'image' => $spec['image'] ?? null,
            'link_sync' => $linkSync,
        ];
    } catch (Throwable $nativeError) {
        throw $nativeError;
    }
}

function stopLabNodeRuntime(PDO $db, string $labId, string $nodeId, bool $preferFast = false): array
{
    refreshLabNodeRuntimeState($db, $labId, $nodeId);

    $ctx = runtimeLoadNodeContext($db, $labId, $nodeId);
    $pid = (int) ($ctx['runtime_pid'] ?? 0);
    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));

    $targetPids = [];
    if ($pid > 1 && runtimePidBelongsToNode($pid, $labId, $nodeId)) {
        $targetPids[] = $pid;
    } elseif ($pid > 1 && $nodeType === 'qemu' && runtimePidAlive($pid)) {
        // Safety: if DB PID has no v2 env markers, still allow stop by direct PID
        // when it clearly belongs to a qemu-system process.
        $cmdline = strtolower(trim(runtimeReadProcCmdline($pid)));
        if ($cmdline !== '' && strpos($cmdline, 'qemu-system') !== false) {
            $targetPids[] = $pid;
        }
    }
    $extraPids = runtimeListNodePidsByEnv($labId, $nodeId, [], false);
    foreach ($extraPids as $extraPid) {
        $targetPids[] = (int) $extraPid;
    }
    $targetPids = array_values(array_unique(array_filter($targetPids, static function ($value): bool {
        return (int) $value > 1;
    })));

    $fastStopFlags = runtimeFastStopSettings($db);
    $isFastStopProfile = static function (array $nodeCtx, array $flags): bool {
        $type = strtolower(trim((string) ($nodeCtx['node_type'] ?? '')));
        if ($type !== 'qemu') {
            return false;
        }

        $template = strtolower(trim((string) ($nodeCtx['template'] ?? '')));
        $image = strtolower(trim((string) ($nodeCtx['image'] ?? '')));
        $name = strtolower(trim((string) ($nodeCtx['name'] ?? '')));
        $fingerprint = trim($template . ' ' . $image . ' ' . $name);
        if ($fingerprint === '') {
            return false;
        }

        // Cisco vIOS/IOSv(L2) have ephemeral runtime; fast process kill is acceptable.
        return !empty($flags['vios']) && preg_match('/\b(vios|iosv|iosvl2|iosv[-_ ]?l2)\b/i', $fingerprint) === 1;
    };
    $fastStop = $preferFast || $isFastStopProfile($ctx, $fastStopFlags);

    if (empty($targetPids)) {
        setNodeStoppedState($db, $labId, $nodeId, null);
        if ($nodeType === 'qemu') {
            runtimeCleanupQmpSocket($nodeId);
            runtimeCleanupQgaSocket($nodeId);
        }
        return [
            'already_stopped' => true,
            'pid' => null,
            'signal' => null,
            'forced' => false,
            'graceful' => false,
        ];
    }

    if ($fastStop) {
        $termination = runtimeTerminateNodePids($targetPids, 1.2);
        $forced = !empty($termination['forced']);
        setNodeStoppedState($db, $labId, $nodeId, null);
        if ($nodeType === 'qemu') {
            runtimeCleanupQmpSocket($nodeId);
            runtimeCleanupQgaSocket($nodeId);
        }
        return [
            'already_stopped' => false,
            'pid' => $targetPids[0],
            'signal' => $forced ? 'SIGKILL' : 'SIGTERM',
            'forced' => $forced,
            'graceful' => false,
        ];
    }

    $graceful = false;
    if ($nodeType === 'qemu') {
        $powerdownMode = null;
        $graceful = runtimeRequestQemuSystemPowerdown($nodeId, $powerdownMode);
        if ($graceful) {
            // ACPI from QMP can trigger GUI power dialogs and hang for a long time.
            // Keep long grace for guest-agent shutdown, but shorten pure ACPI wait.
            $graceTimeout = ($powerdownMode === 'guest_agent') ? 35.0 : 10.0;
            $wait = runtimeWaitPidsExit($targetPids, $graceTimeout);
            $aliveAfterGrace = is_array($wait) ? (array) ($wait['alive'] ?? []) : [];
            if (empty($aliveAfterGrace)) {
                setNodeStoppedState($db, $labId, $nodeId, null);
                runtimeCleanupQmpSocket($nodeId);
                runtimeCleanupQgaSocket($nodeId);
                return [
                    'already_stopped' => false,
                    'pid' => $targetPids[0],
                    'signal' => 'ACPI',
                    'forced' => false,
                    'graceful' => true,
                ];
            }
            $targetPids = array_values(array_unique(array_filter($aliveAfterGrace, static function ($value): bool {
                return (int) $value > 1;
            })));
        }
    }

    $termination = runtimeTerminateNodePids($targetPids, $nodeType === 'qemu' ? 12.0 : 8.0);
    $forced = !empty($termination['forced']);

    setNodeStoppedState($db, $labId, $nodeId, null);
    if ($nodeType === 'qemu') {
        runtimeCleanupQmpSocket($nodeId);
        runtimeCleanupQgaSocket($nodeId);
    }

    return [
        'already_stopped' => false,
        'pid' => $targetPids[0],
        'signal' => $forced ? 'SIGKILL' : 'SIGTERM',
        'forced' => $forced,
        'graceful' => $graceful,
    ];
}
