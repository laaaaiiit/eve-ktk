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

function resolveLabNodeRuntimeDir(string $labId, string $nodeId, string $ownerUserId = ''): string
{
    $ownerSegment = normalizeRuntimeOwnerSegment($ownerUserId);
    if ($ownerSegment === '') {
        ensureLabRuntimeDirs($labId, $nodeId, '');
        return v2RuntimeNodeDir($labId, $nodeId, '');
    }

    $targetNodeDir = v2RuntimeNodeDir($labId, $nodeId, $ownerSegment);
    if (is_dir($targetNodeDir)) {
        return $targetNodeDir;
    }

    $legacyNodeDir = v2RuntimeNodeDir($labId, $nodeId, '');
    if (is_dir($legacyNodeDir)) {
        ensureRuntimeDirectory(v2RuntimeRootDir());
        ensureRuntimeDirectory(v2RuntimeLabsRootDir($ownerSegment));
        ensureRuntimeDirectory(v2RuntimeLabDir($labId, $ownerSegment));
        ensureRuntimeDirectory(v2RuntimeLabDir($labId, $ownerSegment) . '/nodes');

        if (@rename($legacyNodeDir, $targetNodeDir)) {
            return $targetNodeDir;
        }
        return $legacyNodeDir;
    }

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

function runtimeLegacyStartFailureTail(string $legacyWrapperLogPath, string $nodeWrapperLogPath): string
{
    $legacyTail = trim(runtimeTailText($legacyWrapperLogPath, 100));
    $nodeTail = trim(runtimeTailText($nodeWrapperLogPath, 100));

    if ($legacyTail !== '' && $nodeTail !== '') {
        if (strpos($legacyTail, $nodeTail) !== false) {
            return $legacyTail;
        }
        return $legacyTail . "\n[node wrapper]\n" . $nodeTail;
    }

    return $legacyTail !== '' ? $legacyTail : $nodeTail;
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

function runtimeListIolWrapperPidsBySignature(string $labId, string $nodeId, array $ctx): array
{
    $name = trim((string) ($ctx['name'] ?? ''));
    $image = trim((string) ($ctx['image'] ?? ''));
    $iolNodeId = runtimeStableIntInRange('iol:' . $labId . ':' . $nodeId, 1, 1023);
    $expectedImagePath = $image !== '' ? ('/opt/unetlab/addons/iol/bin/' . $image) : '';

    $found = [];
    foreach (scandir('/proc') ?: [] as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $pid = (int) $entry;
        if ($pid <= 1 || !runtimePidAlive($pid)) {
            continue;
        }

        $cmdline = strtolower(runtimeReadProcCmdline($pid));
        if ($cmdline === '' || strpos($cmdline, 'iol_wrapper') === false) {
            continue;
        }

        $args = runtimeReadProcCmdlineArgs($pid);
        if (empty($args)) {
            continue;
        }

        if (!runtimeCmdlineHasArgPair($args, '-D', (string) $iolNodeId)) {
            continue;
        }
        if ($expectedImagePath !== '' && !runtimeCmdlineHasArgPair($args, '-F', $expectedImagePath)) {
            continue;
        }
        if ($name !== '' && !runtimeCmdlineHasArgPair($args, '-t', $name)) {
            continue;
        }

        $found[] = $pid;
    }

    if (empty($found)) {
        return [];
    }

    sort($found, SORT_NUMERIC);
    return array_values(array_unique($found));
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

function runtimeQmpWaitDeviceRemoval($stream, string $devId, int $attempts = 20, int $sleepUsec = 50000): void
{
    $attempts = max(1, $attempts);
    for ($i = 0; $i < $attempts; $i++) {
        if (runtimeQmpFindDevicePciSlot($stream, $devId) === null) {
            return;
        }
        usleep(max(1000, $sleepUsec));
    }
}

function runtimeQmpWaitDevicePresent($stream, string $devId, int $attempts = 20, int $sleepUsec = 50000): bool
{
    $attempts = max(1, $attempts);
    for ($i = 0; $i < $attempts; $i++) {
        if (runtimeQmpFindDevicePciSlot($stream, $devId) !== null) {
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
    if ($pid <= 1 || !runtimePidBelongsToNode($pid, $labId, $nodeId)) {
        return ['ok' => false, 'reason' => 'node_not_running'];
    }

    $stream = runtimeQmpOpen($nodeId);
    if (!is_resource($stream)) {
        return ['ok' => false, 'reason' => 'qmp_unavailable'];
    }

    $changesByPortId = [];
    foreach ($nodeChanges as $change) {
        if (!is_array($change)) {
            continue;
        }
        $portId = trim((string) ($change['port_id'] ?? ''));
        if ($portId === '') {
            continue;
        }
        $changesByPortId[$portId] = $change;
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
                $netdevCmd = 'netdev_add tap,id=' . $netId
                    . ',br=' . $cloudBridge
                    . ',helper=' . $bridgeHelperPath;
                $probeNetdevCmd = 'netdev_add tap,id=' . $probeId
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
            runtimeQmpWaitDeviceRemoval($stream, $devId);
            runtimeQmpHmp($stream, 'netdev_del ' . $netId);

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
            if (!runtimeQmpWaitDevicePresent($stream, $devId, 30, 50000)) {
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

function runtimeNativeIolBindingFromContext(array $ctx, string $labId, string $nodeId): ?array
{
    $pid = (int) ($ctx['runtime_pid'] ?? 0);
    if ($pid <= 1 || !runtimePidAlive($pid)) {
        return null;
    }

    if (!runtimePidBelongsToNode($pid, $labId, $nodeId)) {
        $cmdline = strtolower(runtimeReadProcCmdline($pid));
        if ($cmdline === '' || strpos($cmdline, 'iol_wrapper') === false) {
            return null;
        }
    }

    $iolNodeId = runtimeStableIntInRange('iol:' . $labId . ':' . $nodeId, 1, 1023);
    $tenant = 0;

    $args = runtimeReadProcCmdlineArgs($pid);
    if (!empty($args)) {
        $dValue = runtimeCmdlineGetArgValue($args, '-D');
        if (is_string($dValue) && ctype_digit($dValue)) {
            $parsed = (int) $dValue;
            if ($parsed > 0) {
                $iolNodeId = $parsed;
            }
        }

        $tValue = runtimeCmdlineGetArgValue($args, '-T');
        if (is_string($tValue) && ctype_digit($tValue)) {
            $parsed = (int) $tValue;
            if ($parsed >= 1 && $parsed <= 120) {
                $tenant = $parsed;
            }
        }
    }

    if ($tenant < 1) {
        $consolePort = isset($ctx['runtime_console_port']) ? (int) $ctx['runtime_console_port'] : 0;
        if ($consolePort > 0) {
            $delta = $consolePort - 32768 - $iolNodeId;
            if ($delta >= (1 * 256) && $delta <= (120 * 256) && $delta % 256 === 0) {
                $candidate = (int) ($delta / 256);
                if ($candidate >= 1 && $candidate <= 120) {
                    $tenant = $candidate;
                }
            }
        }
    }

    if ($tenant < 1 || $iolNodeId < 1) {
        return null;
    }

    return [
        'tenant' => $tenant,
        'node_numeric_id' => $iolNodeId,
        'runtime_pid' => $pid,
    ];
}

function runtimeLegacyFindFreeNodeNumericId(array $nodesMap, int $min = 1, int $max = 1023, array $exclude = []): int
{
    $used = [];
    foreach ($nodesMap as $id) {
        $value = (int) $id;
        if ($value >= $min && $value <= $max) {
            $used[$value] = true;
        }
    }
    foreach ($exclude as $value) {
        $value = (int) $value;
        if ($value >= $min && $value <= $max) {
            $used[$value] = true;
        }
    }

    for ($i = $min; $i <= $max; $i++) {
        if (!isset($used[$i])) {
            return $i;
        }
    }
    return 0;
}

function runtimeHotApplyNativeIolNodeLinks(PDO $db, array $ctx, string $labId, string $nodeId, array $nodeChanges): array
{
    $binding = runtimeNativeIolBindingFromContext($ctx, $labId, $nodeId);
    if (!is_array($binding)) {
        return ['ok' => false, 'reason' => 'native_iol_binding_unavailable'];
    }

    $ownerUserId = isset($ctx['author_user_id']) ? (string) $ctx['author_user_id'] : '';
    $authorUsername = trim((string) ($ctx['author_username'] ?? ''));
    if ($authorUsername === '') {
        $authorUsername = 'root';
    }
    $tenant = (int) ($binding['tenant'] ?? 0);
    $nodeNumericId = (int) ($binding['node_numeric_id'] ?? 0);
    if ($tenant < 1 || $nodeNumericId < 1) {
        return ['ok' => false, 'reason' => 'native_iol_binding_invalid'];
    }

    $topology = runtimeLegacyLoadLabTopology($db, $labId);
    $map = runtimeLegacyPrepareMap($topology, $labId, $ownerUserId);
    $map['tenant'] = $tenant;

    $nodesMap = is_array($map['nodes'] ?? null) ? (array) $map['nodes'] : [];
    foreach ($nodesMap as $uuid => $mappedId) {
        $uuid = strtolower(trim((string) $uuid));
        if ($uuid === '' || $uuid === $nodeId) {
            continue;
        }
        if ((int) $mappedId === $nodeNumericId) {
            $replacement = runtimeLegacyFindFreeNodeNumericId($nodesMap, 1, 1023, [$nodeNumericId]);
            if ($replacement > 0) {
                $nodesMap[$uuid] = $replacement;
            }
        }
    }
    $nodesMap[$nodeId] = $nodeNumericId;
    $map['nodes'] = $nodesMap;

    $labFile = runtimeLegacyWriteLabFile($topology, $map, $labId, $ownerUserId);
    $nodeDir = resolveLabNodeRuntimeDir($labId, $nodeId, $ownerUserId);
    $logPath = runtimeNodeLogPathForType($nodeDir, 'legacy_wrapper');

    $ports = is_array($ctx['ports'] ?? null) ? (array) $ctx['ports'] : [];
    $portIfMap = [];
    $fallbackIndex = 0;
    foreach ($ports as $port) {
        if (!is_array($port)) {
            continue;
        }
        $portId = trim((string) ($port['id'] ?? ''));
        if ($portId === '') {
            continue;
        }
        $index = runtimeLegacyPortIndexFromName((string) ($port['name'] ?? ''), $fallbackIndex);
        $fallbackIndex = max($fallbackIndex + 1, $index + 1);
        $portIfMap[$portId] = runtimeLegacyInterfaceIdForPort('iol', $index);
    }

    $oldNetworksMap = is_array($map['networks'] ?? null) ? (array) $map['networks'] : [];
    $applied = [];
    $skipped = [];

    foreach ($nodeChanges as $change) {
        if (!is_array($change)) {
            continue;
        }
        $portId = trim((string) ($change['port_id'] ?? ''));
        if ($portId === '' || !isset($portIfMap[$portId])) {
            $skipped[] = ['port_id' => $portId, 'reason' => 'port_not_found'];
            continue;
        }

        $oldNetworkUuid = strtolower(trim((string) ($change['old_network_id'] ?? '')));
        $oldLegacyId = isset($oldNetworksMap[$oldNetworkUuid]) ? (int) $oldNetworksMap[$oldNetworkUuid] : 0;
        if ($oldLegacyId <= 0) {
            $detectedLegacyId = runtimeLegacyTapCurrentBridgeLegacyId($tenant, $nodeNumericId, (int) $portIfMap[$portId]);
            if ($detectedLegacyId > 0) {
                $oldLegacyId = $detectedLegacyId;
            }
        }

        try {
            runtimeLegacyRunWrapperLinkAction(
                $tenant,
                $labFile,
                $nodeNumericId,
                (int) $portIfMap[$portId],
                $oldLegacyId,
                $authorUsername,
                $logPath
            );
            $applied[] = [
                'port_id' => $portId,
                'interface_id' => (int) $portIfMap[$portId],
                'old_network_legacy_id' => $oldLegacyId,
            ];
        } catch (Throwable $e) {
            $skipped[] = [
                'port_id' => $portId,
                'interface_id' => (int) $portIfMap[$portId],
                'reason' => 'link_action_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    return [
        'ok' => true,
        'mode' => 'native_iol_wrapper_link',
        'tenant' => $tenant,
        'node_numeric_id' => $nodeNumericId,
        'applied' => $applied,
        'skipped' => $skipped,
    ];
}

function runtimeHotApplyLegacyNodeLinks(PDO $db, string $labId, string $nodeId, array $nodeChanges): array
{
    refreshLabNodeRuntimeState($db, $labId, $nodeId);
    $ctx = runtimeLoadNodeContext($db, $labId, $nodeId);
    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
    if (!in_array($nodeType, ['qemu', 'iol', 'dynamips', 'vpcs'], true)) {
        return ['ok' => false, 'reason' => 'unsupported_node_type', 'node_type' => $nodeType];
    }

    $ownerUserId = isset($ctx['author_user_id']) ? (string) $ctx['author_user_id'] : '';
    $runtimePid = (int) ($ctx['runtime_pid'] ?? 0);
    $isRunning = false;
    $isLegacyRuntime = false;
    if ($runtimePid > 1) {
        if (runtimePidBelongsToNode($runtimePid, $labId, $nodeId)) {
            $isRunning = true;
            $isLegacyRuntime = false;
        } elseif ($nodeType === 'iol' && runtimePidAlive($runtimePid)) {
            // IOL wrappers can hide environment from the worker; treat alive PID as running.
            $isRunning = true;
            $isLegacyRuntime = false;
        } elseif (runtimeLegacyPidBelongsToNode(
            $db,
            $runtimePid,
            $labId,
            $nodeId,
            $ownerUserId,
            $nodeType,
            (string) ($ctx['image'] ?? '')
        )) {
            $isRunning = true;
            $isLegacyRuntime = true;
        }
    }
    if (!$isRunning) {
        return ['ok' => false, 'reason' => 'node_not_running'];
    }

    if ($nodeType === 'iol' && !$isLegacyRuntime) {
        return runtimeHotApplyNativeIolNodeLinks($db, $ctx, $labId, $nodeId, $nodeChanges);
    }

    $topology = runtimeLegacyLoadLabTopology($db, $labId);
    $lab = is_array($topology['lab'] ?? null) ? (array) $topology['lab'] : [];
    $nodes = is_array($topology['nodes'] ?? null) ? (array) $topology['nodes'] : [];
    $authorUsername = trim((string) ($lab['author_username'] ?? 'root'));
    if ($authorUsername === '') {
        $authorUsername = 'root';
    }

    $legacyNode = null;
    foreach ($nodes as $n) {
        if ((string) ($n['id'] ?? '') === $nodeId) {
            $legacyNode = is_array($n) ? $n : null;
            break;
        }
    }
    if (!is_array($legacyNode)) {
        return ['ok' => false, 'reason' => 'legacy_node_not_found'];
    }

    $oldMap = runtimeLegacyLoadMap(runtimeLegacyMapPath($labId, $ownerUserId));
    $map = runtimeLegacyPrepareMap($topology, $labId, $ownerUserId);
    $tenant = isset($map['tenant']) ? (int) $map['tenant'] : 0;
    if ($tenant < 1) {
        return ['ok' => false, 'reason' => 'tenant_unavailable'];
    }

    $legacyNodeId = runtimeLegacyNodeNumericId($map, $nodeId);
    $labFile = runtimeLegacyWriteLabFile($topology, $map, $labId, $ownerUserId);
    $nodeDir = resolveLabNodeRuntimeDir($labId, $nodeId, $ownerUserId);
    $logPath = runtimeNodeLogPathForType($nodeDir, 'legacy_wrapper');

    $ports = is_array($legacyNode['ports'] ?? null) ? (array) $legacyNode['ports'] : [];
    $portIfMap = [];
    $fallbackIndex = 0;
    foreach ($ports as $port) {
        if (strtolower(trim((string) ($port['port_type'] ?? ''))) !== 'ethernet') {
            continue;
        }
        $index = runtimeLegacyPortIndexFromName((string) ($port['name'] ?? ''), $fallbackIndex);
        $fallbackIndex = max($fallbackIndex + 1, $index + 1);
        if ($nodeType === 'dynamips' && !runtimeLegacyDynamipsPortAllowed((string) ($legacyNode['template'] ?? ''), $index)) {
            continue;
        }
        $portId = trim((string) ($port['id'] ?? ''));
        if ($portId === '') {
            continue;
        }
        $portIfMap[$portId] = runtimeLegacyInterfaceIdForPort($nodeType, $index);
    }

    $oldNetworksMap = is_array($oldMap['networks'] ?? null) ? (array) $oldMap['networks'] : [];
    $applied = [];
    $skipped = [];
    foreach ($nodeChanges as $change) {
        if (!is_array($change)) {
            continue;
        }
        $portId = trim((string) ($change['port_id'] ?? ''));
        if ($portId === '' || !isset($portIfMap[$portId])) {
            $skipped[] = ['port_id' => $portId, 'reason' => 'port_not_found'];
            continue;
        }
        $oldNetworkUuid = strtolower(trim((string) ($change['old_network_id'] ?? '')));
        $oldLegacyId = isset($oldNetworksMap[$oldNetworkUuid]) ? (int) $oldNetworksMap[$oldNetworkUuid] : 0;
        if ($oldLegacyId <= 0) {
            $detectedLegacyId = runtimeLegacyTapCurrentBridgeLegacyId($tenant, $legacyNodeId, (int) $portIfMap[$portId]);
            if ($detectedLegacyId > 0) {
                $oldLegacyId = $detectedLegacyId;
            }
        }

        runtimeLegacyRunWrapperLinkAction(
            $tenant,
            $labFile,
            $legacyNodeId,
            (int) $portIfMap[$portId],
            $oldLegacyId,
            $authorUsername,
            $logPath
        );
        $applied[] = [
            'port_id' => $portId,
            'interface_id' => (int) $portIfMap[$portId],
            'old_network_legacy_id' => $oldLegacyId,
        ];
    }

    return ['ok' => true, 'mode' => 'legacy_link_wrapper', 'applied' => $applied, 'skipped' => $skipped];
}

function runtimeLegacyTapCurrentBridgeName(int $tenant, int $legacyNodeId, int $interfaceId): string
{
    if ($tenant < 1 || $legacyNodeId < 1 || $interfaceId < 0) {
        return '';
    }
    $tap = 'vunl' . $tenant . '_' . $legacyNodeId . '_' . $interfaceId;
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

function runtimeLegacyTapCurrentBridgeLegacyId(int $tenant, int $legacyNodeId, int $interfaceId): int
{
    $bridge = runtimeLegacyTapCurrentBridgeName($tenant, $legacyNodeId, $interfaceId);
    if ($bridge === '') {
        return 0;
    }
    if (preg_match('/^vnet' . preg_quote((string) $tenant, '/') . '_([0-9]+)$/', $bridge, $m) !== 1) {
        return 0;
    }
    return max(0, (int) ($m[1] ?? 0));
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
            if ($nodeType === 'qemu') {
                // Keep hot-apply path aligned with the runtime mode used for this node.
                // When QEMU runs via legacy wrappers (current default), QMP socket
                // reconfiguration is incompatible with legacy link plumbing for IOL/Dynamips.
                $useLegacy = runtimeShouldUseLegacyRuntime($db, $ctx, $labId, $nodeId);
                if ($useLegacy) {
                    $result = runtimeHotApplyLegacyNodeLinks(
                        $db,
                        $labId,
                        $nodeId,
                        isset($changesByNode[$nodeId]) ? $changesByNode[$nodeId] : []
                    );
                } else {
                    $result = runtimeHotApplyQemuNodeLinks(
                        $db,
                        $labId,
                        $nodeId,
                        isset($changesByNode[$nodeId]) ? $changesByNode[$nodeId] : []
                    );
                }
            } else {
                $result = runtimeHotApplyLegacyNodeLinks(
                    $db,
                    $labId,
                    $nodeId,
                    isset($changesByNode[$nodeId]) ? $changesByNode[$nodeId] : []
                );
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

function runtimeAllocateIolWrapperBinding(string $labId, string $nodeId, int $iolNodeId, ?int $preferredPort = null): array
{
    $minTenant = 1;
    $maxTenant = 120;

    if ($preferredPort !== null && $preferredPort > 0) {
        $delta = $preferredPort - 32768 - $iolNodeId;
        if ($delta >= ($minTenant * 256) && $delta <= ($maxTenant * 256) && $delta % 256 === 0) {
            $tenant = (int) ($delta / 256);
            if ($tenant >= $minTenant && $tenant <= $maxTenant && runtimeCanBindTcpPort($preferredPort)) {
                return [
                    'tenant' => $tenant,
                    'console_port' => $preferredPort,
                ];
            }
        }
    }

    $span = $maxTenant - $minTenant + 1;
    $startTenant = runtimeStableIntInRange('iol-tenant:' . $labId . ':' . $nodeId, $minTenant, $maxTenant);
    for ($offset = 0; $offset < $span; $offset++) {
        $tenant = $minTenant + (($startTenant - $minTenant + $offset) % $span);
        $port = 32768 + (256 * $tenant) + $iolNodeId;
        if ($port < 1024 || $port > 65535) {
            continue;
        }
        if (runtimeCanBindTcpPort($port)) {
            return [
                'tenant' => $tenant,
                'console_port' => $port,
            ];
        }
    }

    throw new RuntimeException('No free IOL console port found');
}

function runtimeLegacyNodeTypeSupported(string $nodeType): bool
{
    $nodeType = strtolower(trim($nodeType));
    return in_array($nodeType, ['iol', 'dynamips', 'qemu', 'vpcs'], true);
}

function runtimeNodeHasLegacyPeer(PDO $db, string $labId, string $nodeId): bool
{
    $stmt = $db->prepare(
        "SELECT 1
         FROM lab_node_ports p
         INNER JOIN lab_node_ports p2
                 ON p2.network_id = p.network_id
                AND p2.id <> p.id
                AND p2.port_type = 'ethernet'
         INNER JOIN lab_nodes n2 ON n2.id = p2.node_id
         WHERE p.node_id = :node_id
           AND p.port_type = 'ethernet'
           AND p.network_id IS NOT NULL
           AND n2.lab_id = :lab_id
           AND lower(n2.node_type) IN ('iol', 'dynamips')
         LIMIT 1"
    );
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function runtimeShouldUseLegacyRuntime(PDO $db, array $ctx, string $labId, string $nodeId): bool
{
    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
    if (!runtimeLegacyNodeTypeSupported($nodeType)) {
        return false;
    }

    $pid = isset($ctx['runtime_pid']) ? (int) $ctx['runtime_pid'] : 0;
    if ($pid <= 1) {
        return false;
    }
    if (runtimePidBelongsToNode($pid, $labId, $nodeId)) {
        return false;
    }

    $ownerUserId = (string) ($ctx['author_user_id'] ?? '');
    $image = trim((string) ($ctx['image'] ?? ''));
    return runtimeLegacyPidBelongsToNode($db, $pid, $labId, $nodeId, $ownerUserId, $nodeType, $image);
}

function runtimeNodeHasCloudPorts(array $ctx): bool
{
    $ports = is_array($ctx['ports'] ?? null) ? (array) $ctx['ports'] : [];
    foreach ($ports as $port) {
        if (!is_array($port)) {
            continue;
        }
        $networkType = strtolower(trim((string) ($port['network_type'] ?? '')));
        if ($networkType !== '' && preg_match('/^pnet[0-9]+$/', $networkType) === 1) {
            return true;
        }
    }
    return false;
}

function runtimeLegacyMapPath(string $labId, string $ownerUserId = ''): string
{
    return rtrim(v2RuntimeLabDir($labId, $ownerUserId), '/') . '/legacy_ids.json';
}

function runtimeLegacyLabFilePath(string $labId, string $ownerUserId = ''): string
{
    return rtrim(v2RuntimeLabDir($labId, $ownerUserId), '/') . '/legacy-runtime.unl';
}

function runtimeLegacyNodeRuntimeDir(int $tenant, string $labId, int $nodeNumericId): string
{
    return '/opt/unetlab/tmp/' . max(1, $tenant) . '/' . $labId . '/' . max(1, $nodeNumericId);
}

function runtimeLegacyNodeWrapperLogPath(int $tenant, string $labId, int $nodeNumericId): string
{
    return rtrim(runtimeLegacyNodeRuntimeDir($tenant, $labId, $nodeNumericId), '/') . '/wrapper.txt';
}

function runtimeWrappersDir(): string
{
    $custom = trim((string) getenv('EVE_V2_WRAPPERS_DIR'));
    if ($custom !== '') {
        return rtrim($custom, '/');
    }

    $preferred = '/opt/unetlab/wrappers-v2';
    if (is_dir($preferred)) {
        return $preferred;
    }

    $fallback = '/opt/unetlab/wrappers';
    if (is_dir($fallback)) {
        return $fallback;
    }

    return $preferred;
}

function runtimeWrapperPath(string $name): string
{
    return runtimeWrappersDir() . '/' . ltrim(trim($name), '/');
}

function runtimeLegacyLoadMap(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function runtimeLegacySaveMap(string $path, array $map): void
{
    ensureRuntimeDirectory((string) dirname($path));
    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode legacy runtime map');
    }
    if (@file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Failed to write legacy runtime map');
    }
}

function runtimeLegacyTenantLooksBusy(int $tenant, string $labId): bool
{
    if ($tenant < 1) {
        return true;
    }

    $tmpTenantDir = '/opt/unetlab/tmp/' . $tenant;
    if (!is_dir($tmpTenantDir)) {
        return false;
    }

    foreach (scandir($tmpTenantDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if ($entry === $labId) {
            return false;
        }
        if (is_dir($tmpTenantDir . '/' . $entry)) {
            return true;
        }
    }
    return false;
}

function runtimeLegacyChooseTenant(array $existingMap, string $labId): int
{
    $existing = isset($existingMap['tenant']) ? (int) $existingMap['tenant'] : 0;
    if ($existing >= 1 && $existing <= 120) {
        if (!runtimeLegacyTenantLooksBusy($existing, $labId)) {
            return $existing;
        }
        $tmpTenantDir = '/opt/unetlab/tmp/' . $existing;
        if (is_dir($tmpTenantDir . '/' . $labId)) {
            return $existing;
        }
    }

    $start = runtimeStableIntInRange('legacy-tenant:' . $labId, 1, 120);
    for ($offset = 0; $offset < 120; $offset++) {
        $tenant = 1 + (($start - 1 + $offset) % 120);
        if (!runtimeLegacyTenantLooksBusy($tenant, $labId)) {
            return $tenant;
        }
    }

    return $start;
}

function runtimeLegacyAssignNumericIds(array $uuids, array $existing, int $min, int $max): array
{
    $min = max(1, $min);
    $max = max($min, $max);

    $normalized = [];
    foreach ($uuids as $uuid) {
        $uuid = strtolower(trim((string) $uuid));
        if ($uuid === '') {
            continue;
        }
        if (!isset($normalized[$uuid])) {
            $normalized[$uuid] = true;
        }
    }
    $uuidList = array_keys($normalized);

    $result = [];
    $used = [];

    foreach ($uuidList as $uuid) {
        $candidate = isset($existing[$uuid]) ? (int) $existing[$uuid] : 0;
        if ($candidate >= $min && $candidate <= $max && !isset($used[$candidate])) {
            $result[$uuid] = $candidate;
            $used[$candidate] = true;
        }
    }

    $next = $min;
    foreach ($uuidList as $uuid) {
        if (isset($result[$uuid])) {
            continue;
        }
        while ($next <= $max && isset($used[$next])) {
            $next++;
        }
        if ($next > $max) {
            throw new RuntimeException('Legacy runtime ID space is exhausted');
        }
        $result[$uuid] = $next;
        $used[$next] = true;
        $next++;
    }

    return $result;
}

function runtimeLegacyPortIndexFromName(string $name, int $fallback): int
{
    if (preg_match('/([0-9]+)$/', trim($name), $m) === 1) {
        return max(0, (int) $m[1]);
    }
    return max(0, $fallback);
}

function runtimeLegacyDynamipsPortAllowed(string $template, int $index): bool
{
    $template = strtolower(trim($template));
    if ($index < 0) {
        return false;
    }
    if ($template === 'c1710') {
        return $index <= 1;
    }
    if ($template === 'c3725') {
        return $index <= 1;
    }
    if ($template === 'c7200') {
        return $index <= 0;
    }
    return false;
}

function runtimeLegacyInterfaceIdForPort(string $nodeType, int $index): int
{
    $index = max(0, $index);
    $nodeType = strtolower(trim($nodeType));
    if ($nodeType === 'vpcs') {
        return 0;
    }
    if ($nodeType === 'iol') {
        $slot = (int) floor($index / 4);
        $sub = $index % 4;
        return $slot + ($sub * 16);
    }
    return $index;
}

function runtimeLegacyInterfaceNameForPort(string $nodeType, string $template, int $index, string $fallback = ''): string
{
    $index = max(0, $index);
    $nodeType = strtolower(trim($nodeType));
    $template = strtolower(trim($template));

    if ($nodeType === 'iol') {
        $slot = (int) floor($index / 4);
        $sub = $index % 4;
        return 'e' . $slot . '/' . $sub;
    }

    if ($nodeType === 'dynamips') {
        if ($template === 'c1710') {
            if ($index === 0) {
                return 'e0';
            }
            if ($index === 1) {
                return 'fa0';
            }
        } elseif ($template === 'c3725') {
            if ($index === 0) {
                return 'fa0/0';
            }
            if ($index === 1) {
                return 'fa0/1';
            }
        } elseif ($template === 'c7200') {
            if ($index === 0) {
                return 'fa0/0';
            }
        }
    }

    $fallback = trim($fallback);
    return $fallback !== '' ? $fallback : ('eth' . $index);
}

function runtimeLegacyXmlAttr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function runtimeLegacyLoadLabTopology(PDO $db, string $labId): array
{
    $labStmt = $db->prepare(
        "SELECT l.id,
                l.name,
                l.author_user_id::text AS author_user_id,
                COALESCE(u.username, 'root') AS author_username
         FROM labs l
         LEFT JOIN users u ON u.id = l.author_user_id
         WHERE l.id = :lab_id
         LIMIT 1"
    );
    $labStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $labStmt->execute();
    $lab = $labStmt->fetch(PDO::FETCH_ASSOC);
    if ($lab === false) {
        throw new RuntimeException('Lab not found');
    }

    $nodesStmt = $db->prepare(
        "SELECT id,
                name,
                node_type,
                template,
                image,
                icon,
                console,
                cpu,
                ram_mb,
                nvram_mb,
                first_mac::text AS first_mac,
                qemu_options,
                qemu_version,
                qemu_arch,
                qemu_nic,
                ethernet_count,
                serial_count,
                left_pos,
                top_pos,
                created_at
         FROM lab_nodes
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC, id ASC"
    );
    $nodesStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $nodesStmt->execute();
    $nodes = $nodesStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($nodes)) {
        $nodes = [];
    }

    $portsStmt = $db->prepare(
        "SELECT id,
                node_id,
                name,
                port_type,
                network_id,
                created_at
         FROM lab_node_ports
         WHERE node_id IN (
             SELECT id FROM lab_nodes WHERE lab_id = :lab_id
         )
         ORDER BY created_at ASC, id ASC"
    );
    $portsStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $portsStmt->execute();
    $ports = $portsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($ports)) {
        $ports = [];
    }

    $networksStmt = $db->prepare(
        "SELECT id,
                name,
                network_type,
                left_pos,
                top_pos,
                visibility,
                icon,
                created_at
         FROM lab_networks
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC, id ASC"
    );
    $networksStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $networksStmt->execute();
    $networks = $networksStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($networks)) {
        $networks = [];
    }

    $portsByNode = [];
    foreach ($ports as $port) {
        $nodeId = (string) ($port['node_id'] ?? '');
        if ($nodeId === '') {
            continue;
        }
        if (!isset($portsByNode[$nodeId])) {
            $portsByNode[$nodeId] = [];
        }
        $portsByNode[$nodeId][] = $port;
    }

    foreach ($nodes as &$node) {
        $nodeId = (string) ($node['id'] ?? '');
        $node['ports'] = isset($portsByNode[$nodeId]) ? $portsByNode[$nodeId] : [];
    }
    unset($node);

    return [
        'lab' => $lab,
        'nodes' => $nodes,
        'networks' => $networks,
    ];
}

function runtimeLegacyPrepareMap(array $topology, string $labId, string $ownerUserId = ''): array
{
    $existing = runtimeLegacyLoadMap(runtimeLegacyMapPath($labId, $ownerUserId));
    $nodes = is_array($topology['nodes'] ?? null) ? $topology['nodes'] : [];

    $legacyNodeUuids = [];
    $usedNetworkUuids = [];

    foreach ($nodes as $node) {
        $nodeType = strtolower(trim((string) ($node['node_type'] ?? '')));
        if (!runtimeLegacyNodeTypeSupported($nodeType)) {
            continue;
        }
        $nodeId = strtolower(trim((string) ($node['id'] ?? '')));
        if ($nodeId !== '') {
            $legacyNodeUuids[] = $nodeId;
        }
        $ports = is_array($node['ports'] ?? null) ? (array) $node['ports'] : [];
        foreach ($ports as $port) {
            if (strtolower(trim((string) ($port['port_type'] ?? ''))) !== 'ethernet') {
                continue;
            }
            $networkId = strtolower(trim((string) ($port['network_id'] ?? '')));
            if ($networkId !== '') {
                $usedNetworkUuids[] = $networkId;
            }
        }
    }

    $legacyNodeMap = runtimeLegacyAssignNumericIds(
        $legacyNodeUuids,
        is_array($existing['nodes'] ?? null) ? (array) $existing['nodes'] : [],
        1,
        1023
    );

    $legacyNetworkMap = runtimeLegacyAssignNumericIds(
        $usedNetworkUuids,
        is_array($existing['networks'] ?? null) ? (array) $existing['networks'] : [],
        1,
        60000
    );

    $tenant = runtimeLegacyChooseTenant($existing, $labId);

    $map = [
        'version' => 1,
        'lab_id' => $labId,
        'tenant' => $tenant,
        'nodes' => $legacyNodeMap,
        'networks' => $legacyNetworkMap,
        'updated_at' => gmdate('c'),
    ];
    runtimeLegacySaveMap(runtimeLegacyMapPath($labId, $ownerUserId), $map);
    return $map;
}

function runtimeLegacyInjectQemuCheckSerialOption(string $qemuOptions, int $checkPort): string
{
    $checkPort = (int) $checkPort;
    if ($checkPort < 1 || $checkPort > 65535) {
        return trim($qemuOptions);
    }

    $tokens = splitShellArgs($qemuOptions);
    foreach ($tokens as $token) {
        $lower = strtolower(trim((string) $token));
        if ($lower === '-serial' || $lower === '--serial' || strpos($lower, '-serial=') === 0 || strpos($lower, '--serial=') === 0) {
            return trim($qemuOptions);
        }
    }

    $serialOption = '-serial tcp:127.0.0.1:' . $checkPort . ',server,nowait,nodelay';
    $base = trim($qemuOptions);
    if ($base === '') {
        return $serialOption;
    }
    return $base . ' ' . $serialOption;
}

function runtimeLegacyBuildUnlXml(array $topology, array $map, string $labId, array $runtimeHints = []): string
{
    $lab = is_array($topology['lab'] ?? null) ? $topology['lab'] : [];
    $nodes = is_array($topology['nodes'] ?? null) ? $topology['nodes'] : [];
    $networks = is_array($topology['networks'] ?? null) ? $topology['networks'] : [];

    $tenant = isset($map['tenant']) ? (int) $map['tenant'] : 1;
    if ($tenant < 1) {
        $tenant = 1;
    }
    $mappedNodes = is_array($map['nodes'] ?? null) ? (array) $map['nodes'] : [];
    $mappedNetworks = is_array($map['networks'] ?? null) ? (array) $map['networks'] : [];
    $checkPortMapRaw = is_array($runtimeHints['qemu_check_ports'] ?? null) ? (array) $runtimeHints['qemu_check_ports'] : [];
    $checkPortMap = [];
    foreach ($checkPortMapRaw as $rawNodeId => $rawPort) {
        $nodeKey = strtolower(trim((string) $rawNodeId));
        $port = (int) $rawPort;
        if ($nodeKey === '' || $port < 1 || $port > 65535) {
            continue;
        }
        $checkPortMap[$nodeKey] = $port;
    }

    $labName = runtimeLegacyXmlAttr((string) ($lab['name'] ?? 'Lab'));
    $author = trim((string) ($lab['author_username'] ?? 'root'));
    if ($author === '') {
        $author = 'root';
    }

    $xmlNodes = [];
    $requiredNetworkIds = [];

    foreach ($nodes as $node) {
        $nodeUuid = strtolower(trim((string) ($node['id'] ?? '')));
        $nodeType = strtolower(trim((string) ($node['node_type'] ?? '')));
        if ($nodeUuid === '' || !runtimeLegacyNodeTypeSupported($nodeType)) {
            continue;
        }
        if (!isset($mappedNodes[$nodeUuid])) {
            continue;
        }

        $template = trim((string) ($node['template'] ?? ''));
        $interfacesXml = [];
        $ports = is_array($node['ports'] ?? null) ? (array) $node['ports'] : [];
        $fallbackIndex = 0;
        $seenInterfaceIds = [];

        foreach ($ports as $port) {
            if (strtolower(trim((string) ($port['port_type'] ?? ''))) !== 'ethernet') {
                continue;
            }
            $networkUuid = strtolower(trim((string) ($port['network_id'] ?? '')));
            if ($networkUuid === '' || !isset($mappedNetworks[$networkUuid])) {
                continue;
            }

            $index = runtimeLegacyPortIndexFromName((string) ($port['name'] ?? ''), $fallbackIndex);
            $fallbackIndex = max($fallbackIndex + 1, $index + 1);

            if ($nodeType === 'dynamips' && !runtimeLegacyDynamipsPortAllowed($template, $index)) {
                continue;
            }

            $ifId = runtimeLegacyInterfaceIdForPort($nodeType, $index);
            if (isset($seenInterfaceIds[$ifId])) {
                continue;
            }
            $seenInterfaceIds[$ifId] = true;

            $ifName = runtimeLegacyInterfaceNameForPort(
                $nodeType,
                $template,
                $index,
                (string) ($port['name'] ?? '')
            );

            $interfacesXml[] = '        <interface id="' . $ifId . '" name="' . runtimeLegacyXmlAttr($ifName)
                . '" type="ethernet" network_id="' . (int) $mappedNetworks[$networkUuid] . '"/>';
            $requiredNetworkIds[$networkUuid] = true;
        }

        $nodeName = runtimeLegacyXmlAttr((string) ($node['name'] ?? 'Node'));
        $nodeTemplate = runtimeLegacyXmlAttr($template);
        $nodeImage = runtimeLegacyXmlAttr((string) ($node['image'] ?? ''));
        $nodeIcon = runtimeLegacyXmlAttr((string) ($node['icon'] ?? ''));
        $nodeConsole = trim((string) ($node['console'] ?? 'telnet'));
        if ($nodeConsole === '') {
            $nodeConsole = 'telnet';
        }
        $nodeConsole = runtimeLegacyXmlAttr($nodeConsole);
        $ethernet = max(0, (int) ($node['ethernet_count'] ?? 0));
        $serial = max(0, (int) ($node['serial_count'] ?? 0));
        $ram = max(0, (int) ($node['ram_mb'] ?? 0));
        $nvram = max(0, (int) ($node['nvram_mb'] ?? 0));
        if ($nodeType === 'iol') {
            // Keep explicit custom values and apply safe defaults for empty fields.
            if ($ram <= 0) {
                $ram = 1024;
            }
            if ($nvram <= 0) {
                $nvram = 256;
            }
        }
        $delay = 0;
        $left = max(0, (int) ($node['left_pos'] ?? 0));
        $top = max(0, (int) ($node['top_pos'] ?? 0));
        $config = 0;
        $cpu = max(0, (int) ($node['cpu'] ?? 0));
        $qemuOptions = trim((string) ($node['qemu_options'] ?? ''));
        if ($nodeType === 'qemu') {
            $consoleTypeRaw = strtolower(trim((string) ($node['console'] ?? '')));
            if (in_array($consoleTypeRaw, ['vnc', 'rdp'], true) && isset($checkPortMap[$nodeUuid])) {
                $qemuOptions = runtimeLegacyInjectQemuCheckSerialOption($qemuOptions, (int) $checkPortMap[$nodeUuid]);
            }
        }
        $qemuVersion = trim((string) ($node['qemu_version'] ?? ''));
        $qemuArch = trim((string) ($node['qemu_arch'] ?? ''));
        $qemuNic = trim((string) ($node['qemu_nic'] ?? ''));
        $templateKey = strtolower(trim($template));
        $firstMac = trim((string) ($node['first_mac'] ?? ''));
        $firstMacHex = normalizeMacHex($firstMac);
        if ($firstMacHex !== null) {
            $firstMac = incrementMacHex($firstMacHex, 0);
        } elseif (in_array($templateKey, ['bigip', 'firepower6', 'firepower', 'linux'], true)) {
            $firstMac = runtimeMacAddress($nodeUuid, 0, null);
        } else {
            $firstMac = '';
        }

        $nodeAttrs = [
            'id' => (string) ((int) $mappedNodes[$nodeUuid]),
            'name' => $nodeName,
            'type' => runtimeLegacyXmlAttr($nodeType),
            'template' => $nodeTemplate,
            'image' => $nodeImage,
            'ethernet' => (string) $ethernet,
            'nvram' => (string) $nvram,
            'ram' => (string) $ram,
            'serial' => (string) $serial,
            'console' => $nodeConsole,
            'delay' => (string) $delay,
            'icon' => $nodeIcon,
            'config' => (string) $config,
            'left' => (string) $left,
            'top' => (string) $top,
        ];
        if ($cpu > 0) {
            $nodeAttrs['cpu'] = (string) $cpu;
        }
        if ($firstMac !== '') {
            $nodeAttrs['firstmac'] = runtimeLegacyXmlAttr($firstMac);
        }
        if ($qemuOptions !== '') {
            $nodeAttrs['qemu_options'] = runtimeLegacyXmlAttr($qemuOptions);
        }
        if ($qemuVersion !== '') {
            $nodeAttrs['qemu_version'] = runtimeLegacyXmlAttr($qemuVersion);
        }
        if ($qemuArch !== '') {
            $nodeAttrs['qemu_arch'] = runtimeLegacyXmlAttr($qemuArch);
        }
        if ($qemuNic !== '') {
            $nodeAttrs['qemu_nic'] = runtimeLegacyXmlAttr($qemuNic);
        }

        $line = '      <node';
        foreach ($nodeAttrs as $attrName => $attrValue) {
            $line .= ' ' . $attrName . '="' . $attrValue . '"';
        }
        $line .= '>';

        if (empty($interfacesXml)) {
            $xmlNodes[] = $line . '</node>';
            continue;
        }

        $xmlNodes[] = $line;
        foreach ($interfacesXml as $ifXml) {
            $xmlNodes[] = $ifXml;
        }
        $xmlNodes[] = '      </node>';
    }

    $xmlNetworks = [];
    foreach ($networks as $network) {
        $networkUuid = strtolower(trim((string) ($network['id'] ?? '')));
        if ($networkUuid === '' || !isset($requiredNetworkIds[$networkUuid])) {
            continue;
        }
        if (!isset($mappedNetworks[$networkUuid])) {
            continue;
        }

        $networkType = trim((string) ($network['network_type'] ?? 'bridge'));
        if ($networkType === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $networkType) !== 1) {
            $networkType = 'bridge';
        }

        $xmlNetworks[] = '      <network id="' . (int) $mappedNetworks[$networkUuid]
            . '" type="' . runtimeLegacyXmlAttr($networkType)
            . '" name="' . runtimeLegacyXmlAttr((string) ($network['name'] ?? 'Network'))
            . '" left="' . max(0, (int) ($network['left_pos'] ?? 0))
            . '" top="' . max(0, (int) ($network['top_pos'] ?? 0))
            . '" visibility="' . max(0, (int) ($network['visibility'] ?? 0))
            . '" icon="' . runtimeLegacyXmlAttr((string) ($network['icon'] ?? 'lan.png'))
            . '"/>';
    }

    $lines = [];
    $lines[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $lines[] = '<lab name="' . $labName . '" id="' . runtimeLegacyXmlAttr($labId) . '" tenant="' . $tenant
        . '" shared="false" sharedWith="" isMirror="false" collaborateAllowed="false" version="1" scripttimeout="300" lock="1" author="' . runtimeLegacyXmlAttr($author) . '">';
    $lines[] = '  <topology>';
    $lines[] = '    <nodes>';
    foreach ($xmlNodes as $line) {
        $lines[] = $line;
    }
    $lines[] = '    </nodes>';
    $lines[] = '    <networks>';
    foreach ($xmlNetworks as $line) {
        $lines[] = $line;
    }
    $lines[] = '    </networks>';
    $lines[] = '  </topology>';
    $lines[] = '  <objects>';
    $lines[] = '    <textobjects/>';
    $lines[] = '  </objects>';
    $lines[] = '</lab>';

    return implode("\n", $lines) . "\n";
}

function runtimeLegacyWriteLabFile(array $topology, array $map, string $labId, string $ownerUserId = '', array $runtimeHints = []): string
{
    $labFile = runtimeLegacyLabFilePath($labId, $ownerUserId);
    ensureRuntimeDirectory((string) dirname($labFile));
    $xml = runtimeLegacyBuildUnlXml($topology, $map, $labId, $runtimeHints);
    if (@file_put_contents($labFile, $xml) === false) {
        throw new RuntimeException('Failed to write legacy runtime lab file');
    }
    return $labFile;
}

function runtimeLegacyWrapperCommandPrefix(): string
{
    $wrappersDir = runtimeWrappersDir();
    $envAssign = 'EVE_WRAPPERS_DIR=' . escapeshellarg($wrappersDir);
    $wrapper = runtimeWrapperPath('unl_wrapper');
    $sudoAllowedWrapper = '/opt/unetlab/wrappers/unl_wrapper';
    if (!is_file($sudoAllowedWrapper) || !is_executable($sudoAllowedWrapper)) {
        $sudoAllowedWrapper = $wrapper;
    }

    $euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
    if ($euid !== 0 && is_executable('/usr/bin/sudo')) {
        // sudoers typically allows only unl_wrapper path and may forbid custom env vars.
        return '/usr/bin/sudo -n ' . escapeshellarg($sudoAllowedWrapper);
    }
    return '/usr/bin/env ' . $envAssign . ' ' . escapeshellarg($wrapper);
}

function runtimeLegacyRunWrapperAction(string $action, int $tenant, string $labFile, int $nodeId, string $username, string $logPath): void
{
    $action = strtolower(trim($action));
    if (!in_array($action, ['start', 'stop', 'wipe'], true)) {
        throw new RuntimeException('Unsupported legacy wrapper action');
    }

    $username = trim($username);
    if ($username === '') {
        $username = 'root';
    }

    $cmd = runtimeLegacyWrapperCommandPrefix()
        . ' -a ' . escapeshellarg($action)
        . ' -T ' . (int) $tenant
        . ' -U ' . escapeshellarg($username)
        . ' -F ' . escapeshellarg($labFile);

    if ($nodeId > 0) {
        $cmd .= ' -D ' . (int) $nodeId;
    }

    // Do not rely on shell redirection to node log path because worker can run as
    // www-data while old runtime directories/files may be root-owned.
    $snippet = $cmd . ' 2>&1';
    $out = [];
    exec('/bin/bash -lc ' . escapeshellarg($snippet), $out, $rc);
    if (!empty($out)) {
        $logDir = (string) dirname($logPath);
        if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
            @file_put_contents($logPath, implode("\n", $out) . "\n", FILE_APPEND);
        }
    }
    if ((int) $rc !== 0) {
        $tail = runtimeTailText($logPath, 80);
        if ($tail === '' && !empty($out)) {
            $tail = implode("\n", array_slice($out, -80));
        }
        throw new RuntimeException(
            'Legacy wrapper action failed'
            . ($tail !== '' ? (': ' . $tail) : '')
        );
    }
}

function runtimeLegacyRunWrapperLinkAction(
    int $tenant,
    string $labFile,
    int $nodeId,
    int $interfaceId,
    int $oldNetworkId,
    string $username,
    string $logPath
): void {
    $username = trim($username);
    if ($username === '') {
        $username = 'root';
    }

    $cmd = runtimeLegacyWrapperCommandPrefix()
        . ' -a ' . escapeshellarg('link')
        . ' -T ' . (int) $tenant
        . ' -U ' . escapeshellarg($username)
        . ' -F ' . escapeshellarg($labFile)
        . ' -D ' . (int) $nodeId
        . ' -i ' . (int) $interfaceId;
    if ($oldNetworkId > 0) {
        $cmd .= ' -b ' . (int) $oldNetworkId;
    }

    $snippet = $cmd . ' 2>&1';
    $out = [];
    exec('/bin/bash -lc ' . escapeshellarg($snippet), $out, $rc);
    if (!empty($out)) {
        $logDir = (string) dirname($logPath);
        if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
            @file_put_contents($logPath, implode("\n", $out) . "\n", FILE_APPEND);
        }
    }
    if ((int) $rc !== 0) {
        $tail = runtimeTailText($logPath, 80);
        if ($tail === '' && !empty($out)) {
            $tail = implode("\n", array_slice($out, -80));
        }
        throw new RuntimeException(
            'Legacy wrapper link action failed'
            . ($tail !== '' ? (': ' . $tail) : '')
        );
    }
}

function runtimeLegacyFindIolPid(int $tenant, int $nodeNumericId, string $image = ''): int
{
    if ($tenant < 1 || $nodeNumericId < 1) {
        return 0;
    }

    $expectedImagePath = trim($image) !== '' ? ('/opt/unetlab/addons/iol/bin/' . trim($image)) : '';
    $found = [];

    foreach (scandir('/proc') ?: [] as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $pid = (int) $entry;
        if ($pid <= 1 || !runtimePidAlive($pid)) {
            continue;
        }

        $args = runtimeReadProcCmdlineArgs($pid);
        if (empty($args)) {
            continue;
        }
        $cmdline = strtolower(implode(' ', $args));
        if (strpos($cmdline, 'iol_wrapper') === false) {
            continue;
        }
        if (!runtimeCmdlineHasArgPair($args, '-T', (string) $tenant)) {
            continue;
        }
        if (!runtimeCmdlineHasArgPair($args, '-D', (string) $nodeNumericId)) {
            continue;
        }
        if ($expectedImagePath !== '' && !runtimeCmdlineHasArgPair($args, '-F', $expectedImagePath)) {
            continue;
        }
        $found[] = $pid;
    }

    if (empty($found)) {
        return 0;
    }
    rsort($found, SORT_NUMERIC);
    return (int) $found[0];
}

function runtimeLegacyFindDynamipsPid(int $nodeNumericId): int
{
    if ($nodeNumericId < 1) {
        return 0;
    }
    $found = [];

    foreach (scandir('/proc') ?: [] as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $pid = (int) $entry;
        if ($pid <= 1 || !runtimePidAlive($pid)) {
            continue;
        }

        $args = runtimeReadProcCmdlineArgs($pid);
        if (empty($args)) {
            continue;
        }
        $cmdline = strtolower(implode(' ', $args));
        if (strpos($cmdline, 'dynamips') === false) {
            continue;
        }
        if (!runtimeCmdlineHasArgPair($args, '-i', (string) $nodeNumericId)) {
            continue;
        }
        $found[] = $pid;
    }

    if (empty($found)) {
        return 0;
    }
    rsort($found, SORT_NUMERIC);
    return (int) $found[0];
}

function runtimeLegacyFindQemuWrapperPid(int $tenant, int $nodeNumericId): int
{
    if ($tenant < 1 || $nodeNumericId < 1) {
        return 0;
    }

    $found = [];
    foreach (scandir('/proc') ?: [] as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $pid = (int) $entry;
        if ($pid <= 1 || !runtimePidAlive($pid)) {
            continue;
        }

        $args = runtimeReadProcCmdlineArgs($pid);
        if (empty($args)) {
            continue;
        }
        $cmdline = strtolower(implode(' ', $args));
        if (strpos($cmdline, 'qemu_wrapper') === false) {
            continue;
        }
        if (!runtimeCmdlineHasArgPair($args, '-T', (string) $tenant)) {
            continue;
        }
        if (!runtimeCmdlineHasArgPair($args, '-D', (string) $nodeNumericId)) {
            continue;
        }
        $found[] = $pid;
    }

    if (empty($found)) {
        return 0;
    }
    rsort($found, SORT_NUMERIC);
    return (int) $found[0];
}

function runtimeLegacyFindVpcsPid(int $tenant, int $nodeNumericId): int
{
    if ($tenant < 1 || $nodeNumericId < 1) {
        return 0;
    }

    $consolePort = 32768 + (256 * $tenant) + $nodeNumericId;
    $found = [];
    foreach (scandir('/proc') ?: [] as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $pid = (int) $entry;
        if ($pid <= 1 || !runtimePidAlive($pid)) {
            continue;
        }

        $args = runtimeReadProcCmdlineArgs($pid);
        if (empty($args)) {
            continue;
        }
        $cmdline = strtolower(implode(' ', $args));
        if (strpos($cmdline, '/vpcs') === false && strpos($cmdline, ' vpcs') === false) {
            continue;
        }
        if (!runtimeCmdlineHasArgPair($args, '-m', (string) $nodeNumericId)) {
            continue;
        }
        if (!runtimeCmdlineHasArgPair($args, '-p', (string) $consolePort)) {
            continue;
        }
        $found[] = $pid;
    }

    if (empty($found)) {
        return 0;
    }
    rsort($found, SORT_NUMERIC);
    return (int) $found[0];
}

function runtimeLegacyExtractDynamipsConsolePort(int $pid): ?int
{
    $args = runtimeReadProcCmdlineArgs($pid);
    $count = count($args);
    for ($i = 0; $i < $count; $i++) {
        $arg = (string) $args[$i];
        if ($arg !== '-T' || $i + 1 >= $count) {
            continue;
        }
        $next = trim((string) $args[$i + 1]);
        if (ctype_digit($next)) {
            $port = (int) $next;
            if ($port >= 1 && $port <= 65535) {
                return $port;
            }
        }
        break;
    }
    return null;
}

function runtimeLegacyConsolePort(string $nodeType, int $tenant, int $nodeNumericId, int $pid): ?int
{
    $nodeType = strtolower(trim($nodeType));
    if ($nodeType === 'iol' || $nodeType === 'qemu' || $nodeType === 'vpcs') {
        $port = 32768 + (256 * $tenant) + $nodeNumericId;
        if ($port >= 1 && $port <= 65535) {
            return $port;
        }
        return null;
    }
    if ($nodeType === 'dynamips') {
        return runtimeLegacyExtractDynamipsConsolePort($pid);
    }
    return null;
}

function runtimeLegacyNodeNumericId(array $map, string $nodeId): int
{
    $nodeId = strtolower(trim($nodeId));
    if ($nodeId === '') {
        return 0;
    }
    $nodesMap = is_array($map['nodes'] ?? null) ? (array) $map['nodes'] : [];
    return isset($nodesMap[$nodeId]) ? (int) $nodesMap[$nodeId] : 0;
}

function runtimeLegacyQemuResetTapMasters(int $tenant, int $nodeNumericId, int $ethCount, string $logPath): void
{
    if ($tenant < 1 || $nodeNumericId < 1 || $ethCount < 1) {
        return;
    }

    $maxIf = min(256, max(1, $ethCount));
    for ($i = 0; $i < $maxIf; $i++) {
        $tap = 'vunl' . $tenant . '_' . $nodeNumericId . '_' . $i;
        $snippet = 'if ip link show ' . escapeshellarg($tap) . ' >/dev/null 2>&1; then '
            . 'ip link set dev ' . escapeshellarg($tap) . ' nomaster >/dev/null 2>&1 || true; '
            . 'ip link set dev ' . escapeshellarg($tap) . ' up >/dev/null 2>&1 || true; '
            . 'fi';
        $out = [];
        exec('/bin/bash -lc ' . escapeshellarg($snippet), $out, $rc);
        if (!empty($out)) {
            @file_put_contents($logPath, implode("\n", $out) . "\n", FILE_APPEND);
        }
    }
}

function runtimeLegacyQemuResetTapMasterInterface(int $tenant, int $nodeNumericId, int $interfaceId, string $logPath): void
{
    if ($tenant < 1 || $nodeNumericId < 1 || $interfaceId < 0) {
        return;
    }

    $tap = 'vunl' . $tenant . '_' . $nodeNumericId . '_' . $interfaceId;
    $snippet = 'if ip link show ' . escapeshellarg($tap) . ' >/dev/null 2>&1; then '
        . 'ip link set dev ' . escapeshellarg($tap) . ' nomaster >/dev/null 2>&1 || true; '
        . 'ip link set dev ' . escapeshellarg($tap) . ' up >/dev/null 2>&1 || true; '
        . 'fi';
    $out = [];
    exec('/bin/bash -lc ' . escapeshellarg($snippet), $out, $rc);
    if (!empty($out)) {
        @file_put_contents($logPath, implode("\n", $out) . "\n", FILE_APPEND);
    }
}

function runtimeLegacyApplyWrapperLinksForNode(
    array $topology,
    array $map,
    string $nodeId,
    string $nodeType,
    int $tenant,
    string $labFile,
    string $username,
    string $logPath
): array {
    $nodeType = strtolower(trim($nodeType));
    if (!in_array($nodeType, ['qemu', 'iol', 'dynamips', 'vpcs'], true)) {
        return ['applied' => [], 'skipped' => [['reason' => 'unsupported_node_type']]];
    }

    $legacyNodeId = runtimeLegacyNodeNumericId($map, $nodeId);
    if ($legacyNodeId < 1 || $tenant < 1) {
        return ['applied' => [], 'skipped' => [['reason' => 'legacy_mapping_missing']]];
    }

    $nodes = is_array($topology['nodes'] ?? null) ? (array) $topology['nodes'] : [];
    $legacyNode = null;
    foreach ($nodes as $n) {
        if ((string) ($n['id'] ?? '') === $nodeId) {
            $legacyNode = is_array($n) ? $n : null;
            break;
        }
    }
    if (!is_array($legacyNode)) {
        return ['applied' => [], 'skipped' => [['reason' => 'node_not_found']]];
    }

    $mappedNetworks = is_array($map['networks'] ?? null) ? (array) $map['networks'] : [];
    $ports = is_array($legacyNode['ports'] ?? null) ? (array) $legacyNode['ports'] : [];
    $template = (string) ($legacyNode['template'] ?? '');
    $fallbackIndex = 0;
    $seenIfIds = [];
    $applied = [];
    $skipped = [];

    foreach ($ports as $port) {
        if (strtolower(trim((string) ($port['port_type'] ?? ''))) !== 'ethernet') {
            continue;
        }
        $networkUuid = strtolower(trim((string) ($port['network_id'] ?? '')));
        if ($networkUuid === '' || !isset($mappedNetworks[$networkUuid])) {
            continue;
        }

        $index = runtimeLegacyPortIndexFromName((string) ($port['name'] ?? ''), $fallbackIndex);
        $fallbackIndex = max($fallbackIndex + 1, $index + 1);
        if ($nodeType === 'dynamips' && !runtimeLegacyDynamipsPortAllowed($template, $index)) {
            $skipped[] = ['port' => (string) ($port['name'] ?? ''), 'reason' => 'dynamips_port_not_allowed'];
            continue;
        }

        $ifId = runtimeLegacyInterfaceIdForPort($nodeType, $index);
        if (isset($seenIfIds[$ifId])) {
            continue;
        }
        $seenIfIds[$ifId] = true;

        $recovered = false;
        try {
            runtimeLegacyRunWrapperLinkAction(
                $tenant,
                $labFile,
                $legacyNodeId,
                $ifId,
                0,
                $username,
                $logPath
            );
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            $needsRetry = $nodeType === 'qemu'
                && (strpos($msg, '80030') !== false || strpos($msg, 'already a member of a bridge') !== false);
            if (!$needsRetry) {
                $skipped[] = [
                    'port' => (string) ($port['name'] ?? ''),
                    'interface_id' => $ifId,
                    'reason' => 'link_action_failed',
                    'error' => $e->getMessage(),
                ];
                continue;
            }

            runtimeLegacyQemuResetTapMasterInterface($tenant, $legacyNodeId, $ifId, $logPath);
            try {
                runtimeLegacyRunWrapperLinkAction(
                    $tenant,
                    $labFile,
                    $legacyNodeId,
                    $ifId,
                    0,
                    $username,
                    $logPath
                );
                $recovered = true;
            } catch (Throwable $e2) {
                $skipped[] = [
                    'port' => (string) ($port['name'] ?? ''),
                    'interface_id' => $ifId,
                    'reason' => 'link_action_retry_failed',
                    'error' => $e2->getMessage(),
                ];
                continue;
            }
        }

        $applied[] = [
            'port' => (string) ($port['name'] ?? ''),
            'interface_id' => $ifId,
            'network_legacy_id' => (int) $mappedNetworks[$networkUuid],
            'recovered' => $recovered,
        ];
    }

    return ['applied' => $applied, 'skipped' => $skipped];
}

function runtimeLegacyStartNodeRuntime(PDO $db, array $ctx, string $labId, string $nodeId): array
{
    $ownerUserId = (string) ($ctx['author_user_id'] ?? '');
    $authorUsername = trim((string) ($ctx['author_username'] ?? ''));
    if ($authorUsername === '') {
        $authorUsername = 'root';
    }

    $nodeDir = resolveLabNodeRuntimeDir($labId, $nodeId, $ownerUserId);
    $logPath = runtimeNodeLogPathForType($nodeDir, 'legacy_wrapper');
    $topology = runtimeLegacyLoadLabTopology($db, $labId);
    $map = runtimeLegacyPrepareMap($topology, $labId, $ownerUserId);
    $nodeNumericId = runtimeLegacyNodeNumericId($map, $nodeId);
    if ($nodeNumericId < 1) {
        throw new RuntimeException('Legacy node ID mapping not found');
    }

    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
    $image = trim((string) ($ctx['image'] ?? ''));
    $consoleType = strtolower(trim((string) ($ctx['console'] ?? '')));
    $checkConsolePort = null;
    $runtimeHints = [];
    if ($nodeType === 'qemu' && in_array($consoleType, ['vnc', 'rdp'], true)) {
        $preferredCheckPort = isset($ctx['runtime_check_console_port']) ? (int) $ctx['runtime_check_console_port'] : null;
        if ($preferredCheckPort !== null && $preferredCheckPort <= 0) {
            $preferredCheckPort = null;
        }
        try {
            $checkConsolePort = runtimeAllocateConsolePort($labId, $nodeId, 'qemu-check', $preferredCheckPort);
            $runtimeHints['qemu_check_ports'] = [$nodeId => $checkConsolePort];
        } catch (Throwable $e) {
            $checkConsolePort = null;
        }
    }

    $labFile = runtimeLegacyWriteLabFile($topology, $map, $labId, $ownerUserId, $runtimeHints);
    $tenant = (int) ($map['tenant'] ?? 0);
    if ($tenant < 1) {
        throw new RuntimeException('Legacy tenant mapping is invalid');
    }

    $nodeWrapperLogPath = runtimeLegacyNodeWrapperLogPath($tenant, $labId, $nodeNumericId);

    $startError = null;
    try {
        runtimeLegacyRunWrapperAction('start', $tenant, $labFile, $nodeNumericId, $authorUsername, $logPath);
    } catch (Throwable $e) {
        // Some legacy wrappers may return non-zero even when the emulator process
        // still starts (for example, noisy TAP/OVS cleanup paths). Verify PID first.
        $startError = $e;
    }

    $pid = 0;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        if ($nodeType === 'iol') {
            $pid = runtimeLegacyFindIolPid($tenant, $nodeNumericId, $image);
        } elseif ($nodeType === 'dynamips') {
            $pid = runtimeLegacyFindDynamipsPid($nodeNumericId);
        } elseif ($nodeType === 'qemu') {
            $pid = runtimeLegacyFindQemuWrapperPid($tenant, $nodeNumericId);
        } elseif ($nodeType === 'vpcs') {
            $pid = runtimeLegacyFindVpcsPid($tenant, $nodeNumericId);
        }
        if ($pid > 1) {
            break;
        }
        usleep(150000);
    }
    if ($pid <= 1) {
        $tail = runtimeLegacyStartFailureTail($logPath, $nodeWrapperLogPath);
        if ($startError instanceof Throwable) {
            $message = trim($startError->getMessage());
            if ($message === '') {
                $message = 'Legacy runtime start failed';
            }
            if ($tail !== '' && strpos($message, $tail) === false) {
                $message = $message . ': ' . $tail;
            }
            throw new RuntimeException($message, 0, $startError);
        }
        throw new RuntimeException('Failed to detect legacy runtime process' . ($tail !== '' ? (': ' . $tail) : ''));
    }

    $consolePort = runtimeLegacyConsolePort($nodeType, $tenant, $nodeNumericId, $pid);
    if ($nodeType === 'qemu' && in_array($consoleType, ['vnc', 'rdp'], true) && $checkConsolePort === null) {
        // Fallback for legacy starts where hidden check socket couldn't be provisioned.
        $checkConsolePort = $consolePort;
    }
    setNodeRunningState($db, $labId, $nodeId, $pid, $consolePort, $checkConsolePort);

    $legacyLinkSync = ['applied' => [], 'skipped' => []];
    if ($nodeType === 'qemu') {
        $ethCount = max(0, (int) ($ctx['ethernet_count'] ?? 0));
        runtimeLegacyQemuResetTapMasters($tenant, $nodeNumericId, $ethCount, $logPath);
        $legacyLinkSync = runtimeLegacyApplyWrapperLinksForNode(
            $topology,
            $map,
            $nodeId,
            $nodeType,
            $tenant,
            $labFile,
            $authorUsername,
            $logPath
        );
    }

    return [
        'already_running' => false,
        'pid' => $pid,
        'console_port' => $consolePort,
        'legacy' => [
            'tenant' => $tenant,
            'node_numeric_id' => $nodeNumericId,
            'lab_file' => $labFile,
        ],
        'legacy_link_sync' => $legacyLinkSync,
    ];
}

function runtimeLegacyStopNodeRuntime(PDO $db, array $ctx, string $labId, string $nodeId): array
{
    $ownerUserId = (string) ($ctx['author_user_id'] ?? '');
    $authorUsername = trim((string) ($ctx['author_username'] ?? ''));
    if ($authorUsername === '') {
        $authorUsername = 'root';
    }

    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
    $existingPid = isset($ctx['runtime_pid']) ? (int) $ctx['runtime_pid'] : 0;
    $nodeDir = resolveLabNodeRuntimeDir($labId, $nodeId, $ownerUserId);
    $logPath = runtimeNodeLogPathForType($nodeDir, 'legacy_wrapper');
    $topology = runtimeLegacyLoadLabTopology($db, $labId);
    $map = runtimeLegacyPrepareMap($topology, $labId, $ownerUserId);
    $nodeNumericId = runtimeLegacyNodeNumericId($map, $nodeId);
    $tenant = (int) ($map['tenant'] ?? 0);

    $candidatePids = [];
    $candidateBefore = 0;
    if ($nodeType === 'iol') {
        $image = trim((string) ($ctx['image'] ?? ''));
        $candidateBefore = runtimeLegacyFindIolPid($tenant, $nodeNumericId, $image);
    } elseif ($nodeType === 'dynamips') {
        $candidateBefore = runtimeLegacyFindDynamipsPid($nodeNumericId);
    } elseif ($nodeType === 'qemu') {
        $candidateBefore = runtimeLegacyFindQemuWrapperPid($tenant, $nodeNumericId);
    } elseif ($nodeType === 'vpcs') {
        $candidateBefore = runtimeLegacyFindVpcsPid($tenant, $nodeNumericId);
    }

    if ($candidateBefore <= 1 && $existingPid <= 1) {
        setNodeStoppedState($db, $labId, $nodeId, null);
        return [
            'already_stopped' => true,
            'pid' => null,
            'signal' => null,
            'forced' => false,
            'graceful' => false,
            'legacy' => [
                'tenant' => $tenant,
                'node_numeric_id' => $nodeNumericId,
            ],
        ];
    }

    if ($nodeNumericId > 0 && $tenant > 0) {
        $labFile = runtimeLegacyWriteLabFile($topology, $map, $labId, $ownerUserId);
        try {
            runtimeLegacyRunWrapperAction('stop', $tenant, $labFile, $nodeNumericId, $authorUsername, $logPath);
        } catch (Throwable $e) {
            if ($candidateBefore > 1) {
                throw $e;
            }
        }
    }

    if ($nodeType === 'iol') {
        $image = trim((string) ($ctx['image'] ?? ''));
        $pid = runtimeLegacyFindIolPid($tenant, $nodeNumericId, $image);
        if ($pid > 1) {
            $candidatePids[] = $pid;
        }
    } elseif ($nodeType === 'dynamips') {
        $pid = runtimeLegacyFindDynamipsPid($nodeNumericId);
        if ($pid > 1) {
            $candidatePids[] = $pid;
        }
    } elseif ($nodeType === 'qemu') {
        $pid = runtimeLegacyFindQemuWrapperPid($tenant, $nodeNumericId);
        if ($pid > 1) {
            $candidatePids[] = $pid;
        }
    } elseif ($nodeType === 'vpcs') {
        $pid = runtimeLegacyFindVpcsPid($tenant, $nodeNumericId);
        if ($pid > 1) {
            $candidatePids[] = $pid;
        }
    }

    $termination = runtimeTerminateNodePids($candidatePids, 8.0);
    $forced = !empty($termination['forced']);
    setNodeStoppedState($db, $labId, $nodeId, null);

    return [
        'already_stopped' => false,
        'pid' => !empty($candidatePids) ? $candidatePids[0] : null,
        'signal' => $forced ? 'SIGKILL' : 'SIGTERM',
        'forced' => $forced,
        'graceful' => true,
        'legacy' => [
            'tenant' => $tenant,
            'node_numeric_id' => $nodeNumericId,
        ],
    ];
}

function runtimeLegacyPidBelongsToNode(PDO $db, int $pid, string $labId, string $nodeId, string $ownerUserId, string $nodeType, string $image = ''): bool
{
    if ($pid <= 1 || !runtimePidAlive($pid)) {
        return false;
    }
    if (!runtimeLegacyNodeTypeSupported($nodeType)) {
        return false;
    }

    $map = runtimeLegacyLoadMap(runtimeLegacyMapPath($labId, $ownerUserId));
    $tenant = isset($map['tenant']) ? (int) $map['tenant'] : 0;
    $nodeNumericId = runtimeLegacyNodeNumericId($map, $nodeId);
    if ($tenant < 1 || $nodeNumericId < 1) {
        return false;
    }

    if (strtolower(trim($nodeType)) === 'iol') {
        $iolPid = runtimeLegacyFindIolPid($tenant, $nodeNumericId, $image);
        return $iolPid > 1 && $iolPid === $pid;
    }
    if (strtolower(trim($nodeType)) === 'dynamips') {
        $dynPid = runtimeLegacyFindDynamipsPid($nodeNumericId);
        return $dynPid > 1 && $dynPid === $pid;
    }
    if (strtolower(trim($nodeType)) === 'qemu') {
        $qemuPid = runtimeLegacyFindQemuWrapperPid($tenant, $nodeNumericId);
        return $qemuPid > 1 && $qemuPid === $pid;
    }
    if (strtolower(trim($nodeType)) === 'vpcs') {
        $vpcsPid = runtimeLegacyFindVpcsPid($tenant, $nodeNumericId);
        return $vpcsPid > 1 && $vpcsPid === $pid;
    }
    return false;
}

function runtimeLegacyResolveNodeProcess(PDO $db, string $labId, string $nodeId, string $ownerUserId, string $nodeType, string $image = ''): array
{
    $result = ['pid' => 0, 'console_port' => null];
    if (!runtimeLegacyNodeTypeSupported($nodeType)) {
        return $result;
    }

    $map = runtimeLegacyLoadMap(runtimeLegacyMapPath($labId, $ownerUserId));
    $tenant = isset($map['tenant']) ? (int) $map['tenant'] : 0;
    $nodeNumericId = runtimeLegacyNodeNumericId($map, $nodeId);
    if ($tenant < 1 || $nodeNumericId < 1) {
        return $result;
    }

    $type = strtolower(trim($nodeType));
    $pid = 0;
    if ($type === 'iol') {
        $pid = runtimeLegacyFindIolPid($tenant, $nodeNumericId, $image);
    } elseif ($type === 'dynamips') {
        $pid = runtimeLegacyFindDynamipsPid($nodeNumericId);
    } elseif ($type === 'qemu') {
        $pid = runtimeLegacyFindQemuWrapperPid($tenant, $nodeNumericId);
    } elseif ($type === 'vpcs') {
        $pid = runtimeLegacyFindVpcsPid($tenant, $nodeNumericId);
    }

    if ($pid > 1) {
        $result['pid'] = $pid;
        $result['console_port'] = runtimeLegacyConsolePort($type, $tenant, $nodeNumericId, $pid);
    }
    return $result;
}

function runtimeLaunchBackgroundProcess(
    string $labId,
    string $nodeId,
    string $nodeDir,
    string $logPath,
    string $pidPath,
    string $binary,
    array $args,
    array $extraEnv = []
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
    $envPrefix = implode(' ', $envPairs);
    if ($envPrefix !== '') {
        $envPrefix .= ' ';
    }

    $cmd = buildShellCommandFromArgv($binary, $args);
    // `setsid -f` detaches immediately; we later resolve the real PID by env markers.
    $snippet = 'cd ' . escapeshellarg($nodeDir)
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

    $enableGuestAgent = runtimeQemuLooksLikeLinuxNode($ctx)
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

    $bridgeHelperPath = runtimeQemuBridgeHelperPath();

    for ($i = 0; $i < $ethCount; $i++) {
        $key = $netKeys[$i] ?? ('isolated:' . $labId . ':' . $nodeId . ':' . $i);
        $portMeta = $indexedPorts[$i] ?? null;
        $p2p = runtimePortUdpPointToPoint(is_array($portMeta) ? $portMeta : null);
        $endpoint = runtimeMcastEndpoint($key);
        $mac = runtimeMacAddress($nodeId, $i, $ctx['first_mac'] ?? null);
        $netId = 'net' . $i;
        $networkType = strtolower(trim((string) (is_array($portMeta) ? ($portMeta['network_type'] ?? '') : '')));
        $isCloudPort = $networkType !== '' && preg_match('/^pnet[0-9]+$/', $networkType) === 1;
        $cloudBridge = $isCloudPort ? $networkType : '';

        $args[] = '-device';
        $args[] = $nicDriver . ',id=nic' . $i . ',netdev=' . $netId . ',mac=' . $mac;
        $args[] = '-netdev';
        if ($isCloudPort) {
            if ($bridgeHelperPath === '') {
                throw new RuntimeException('qemu-bridge-helper not found for cloud bridge: ' . $cloudBridge);
            }
            if (!runtimeQemuBridgeAclAllows($cloudBridge)) {
                throw new RuntimeException('Bridge "' . $cloudBridge . '" is not allowed in /etc/qemu/bridge.conf');
            }
            $args[] = 'tap,id=' . $netId . ',br=' . $cloudBridge . ',helper=' . $bridgeHelperPath;
        } elseif ($p2p !== null) {
            $args[] = 'socket,id=' . $netId
                . ',localaddr=127.0.0.1:' . $p2p['local_port']
                . ',udp=127.0.0.1:' . $p2p['remote_port'];
        } else {
            $args[] = 'socket,id=' . $netId . ',mcast=' . $endpoint['addr'] . ':' . $endpoint['port'];
        }

        $networkInfo = [
            'index' => $i,
            'key' => $key,
            'mac' => $mac,
        ];
        if ($isCloudPort) {
            $networkInfo['mode'] = 'cloud_bridge';
            $networkInfo['bridge'] = $cloudBridge;
        } elseif ($p2p !== null) {
            $networkInfo['mode'] = 'udp_p2p';
            $networkInfo['local_udp_port'] = (int) $p2p['local_port'];
            $networkInfo['remote_udp_port'] = (int) $p2p['remote_port'];
        } else {
            $networkInfo['mode'] = 'mcast';
            $networkInfo['mcast'] = $endpoint['addr'] . ':' . $endpoint['port'];
        }
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

function runtimeBuildDynamipsLaunchSpec(array $ctx, string $labId, string $nodeId, string $nodeDir): array
{
    $binary = '/usr/bin/dynamips';
    if (!is_executable($binary)) {
        throw new RuntimeException('dynamips binary is not available');
    }

    $image = trim((string) ($ctx['image'] ?? ''));
    if ($image === '') {
        throw new RuntimeException('Dynamips image is required');
    }
    $imagePath = '/opt/unetlab/addons/dynamips/' . $image;
    if (!is_file($imagePath)) {
        throw new RuntimeException('Dynamips image not found: ' . $image);
    }

    $template = trim((string) ($ctx['template'] ?? ''));
    $tpl = runtimeLoadTemplateConfig($template);

    $name = trim((string) ($ctx['name'] ?? 'Dynamips'));
    if ($name === '') {
        $name = 'Dynamips';
    }
    $ram = max(16, (int) ($ctx['ram_mb'] ?? ($tpl['ram'] ?? 256)));
    $nvram = max(16, (int) ($ctx['nvram_mb'] ?? ($tpl['nvram'] ?? 128)));
    $idlepc = trim((string) ($tpl['idlepc'] ?? ''));
    $dynOpts = splitShellArgs((string) ($tpl['dynamips_options'] ?? ''));
    $consolePort = runtimeAllocateConsolePort(
        $labId,
        $nodeId,
        'dynamips',
        isset($ctx['runtime_console_port']) ? (int) $ctx['runtime_console_port'] : null
    );

    $args = [];
    foreach ($dynOpts as $token) {
        $args[] = (string) $token;
    }
    $args[] = '-l';
    $args[] = basename(runtimeNodeLogPathForType($nodeDir, 'dynamips'));
    if ($idlepc !== '') {
        $args[] = '--idle-pc';
        $args[] = $idlepc;
    }
    $args[] = '-N';
    $args[] = $name;
    $args[] = '-i';
    $args[] = (string) runtimeNodeNumericId($labId, $nodeId);
    $args[] = '-r';
    $args[] = (string) $ram;
    $args[] = '-n';
    $args[] = (string) $nvram;
    $args[] = '-T';
    $args[] = (string) $consolePort;
    if (strtolower($template) === 'c7200') {
        $args[] = '-p';
        $args[] = '0:C7200-IO-FE';
    }
    $ethCount = max(0, (int) ($ctx['ethernet_count'] ?? ($tpl['ethernet'] ?? 0)));
    $indexedPorts = runtimeBuildPortIndexMap((array) ($ctx['ports'] ?? []), $ethCount);
    $networks = [];
    for ($i = 0; $i < $ethCount; $i++) {
        // Conservative defaults per legacy templates to avoid invalid slot wiring.
        if (strtolower($template) === 'c1710' && $i > 0) {
            continue;
        }
        if (strtolower($template) === 'c7200' && $i > 0) {
            continue;
        }
        if (strtolower($template) === 'c3725' && $i > 1) {
            continue;
        }

        $portMeta = $indexedPorts[$i] ?? null;
        $p2p = runtimePortUdpPointToPoint(is_array($portMeta) ? $portMeta : null);
        if ($p2p === null) {
            continue;
        }

        $args[] = '-s';
        $args[] = '0:' . $i . ':udp:' . $p2p['local_port'] . ':127.0.0.1:' . $p2p['remote_port'];
        $networks[] = [
            'index' => $i,
            'mode' => 'udp_p2p',
            'local_udp_port' => (int) $p2p['local_port'],
            'remote_udp_port' => (int) $p2p['remote_port'],
        ];
    }
    $args[] = $imagePath;

    return [
        'launch_mode' => 'background',
        'binary' => $binary,
        'args' => $args,
        'console_port' => $consolePort,
        'networks' => $networks,
        'image' => $image,
        'process_hints' => ['dynamips', basename($imagePath)],
    ];
}

function runtimeBuildVpcsLaunchSpec(array $ctx, string $labId, string $nodeId): array
{
    $binary = '/opt/vpcsu/bin/vpcs';
    if (!is_executable($binary)) {
        throw new RuntimeException('vpcs binary is not available');
    }

    $name = trim((string) ($ctx['name'] ?? 'VPCS'));
    if ($name === '') {
        $name = 'VPCS';
    }
    $consolePort = runtimeAllocateConsolePort(
        $labId,
        $nodeId,
        'vpcs',
        isset($ctx['runtime_console_port']) ? (int) $ctx['runtime_console_port'] : null
    );
    $macSeed = runtimeStableIntInRange($labId . ':' . $nodeId, 1, 240);
    $indexedPorts = runtimeBuildPortIndexMap((array) ($ctx['ports'] ?? []), 1);
    $p2p = runtimePortUdpPointToPoint(is_array($indexedPorts[0] ?? null) ? $indexedPorts[0] : null);

    $args = [
        '-i', '1',
        '-p', (string) $consolePort,
        '-m', (string) $macSeed,
        '-N', $name,
    ];
    if ($p2p !== null) {
        $args[] = '-s';
        $args[] = (string) $p2p['local_port'];
        $args[] = '-c';
        $args[] = (string) $p2p['remote_port'];
    }

    $networks = [];
    if ($p2p !== null) {
        $networks[] = [
            'index' => 0,
            'mode' => 'udp_p2p',
            'local_udp_port' => (int) $p2p['local_port'],
            'remote_udp_port' => (int) $p2p['remote_port'],
        ];
    }

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

function runtimePrepareIolRuntimeFiles(string $nodeDir, int $iolNodeId): void
{
    $iourcPath = '/opt/unetlab/addons/iol/bin/iourc';
    if (!is_file($iourcPath)) {
        throw new RuntimeException('IOL license file iourc is not found');
    }

    $runtimeIourc = rtrim($nodeDir, '/') . '/iourc';
    if (is_link($runtimeIourc) || is_file($runtimeIourc)) {
        @unlink($runtimeIourc);
    }
    if (!@symlink($iourcPath, $runtimeIourc) && !is_link($runtimeIourc)) {
        if (!@copy($iourcPath, $runtimeIourc)) {
            throw new RuntimeException('Failed to prepare iourc file');
        }
    }

    $wrapperId = $iolNodeId + 512;
    $rows = [];
    for ($d = 0; $d < 64; $d++) {
        $rows[] = $iolNodeId . ':' . $d . ' ' . $wrapperId . ':' . $d;
    }
    $netmapPath = rtrim($nodeDir, '/') . '/NETMAP';
    // Recreate NETMAP to avoid permission issues when an old file was created by another user.
    if ((is_file($netmapPath) || is_link($netmapPath)) && !@unlink($netmapPath) && (is_file($netmapPath) || is_link($netmapPath))) {
        throw new RuntimeException('Failed to replace IOL NETMAP file');
    }
    $ok = @file_put_contents($netmapPath, implode("\n", $rows) . "\n");
    if (!is_int($ok) || $ok <= 0) {
        throw new RuntimeException('Failed to write IOL NETMAP file');
    }
    @chmod($netmapPath, 0664);
}

function runtimeBuildIolLaunchSpec(array $ctx, string $labId, string $nodeId, string $nodeDir): array
{
    $image = trim((string) ($ctx['image'] ?? ''));
    if ($image === '') {
        throw new RuntimeException('IOL image is required');
    }

    $binary = '/opt/unetlab/addons/iol/bin/' . $image;
    if (!is_file($binary) || !is_executable($binary)) {
        throw new RuntimeException('IOL image binary not found: ' . $image);
    }

    $wrapperBinary = runtimeWrapperPath('iol_wrapper');
    if (!is_file($wrapperBinary) || !is_executable($wrapperBinary)) {
        throw new RuntimeException('iol_wrapper binary is not available');
    }

    $template = trim((string) ($ctx['template'] ?? ''));
    $tpl = runtimeLoadTemplateConfig($template);
    $ram = max(256, (int) ($ctx['ram_mb'] ?? ($tpl['ram'] ?? 1024)));
    $nvram = max(128, (int) ($ctx['nvram_mb'] ?? ($tpl['nvram'] ?? 256)));
    $ethGroups = max(0, (int) ($ctx['ethernet_count'] ?? ($tpl['ethernet'] ?? 1)));
    $serialGroups = max(0, (int) ($ctx['serial_count'] ?? ($tpl['serial'] ?? 0)));
    if ($ethGroups + $serialGroups > 16) {
        $serialGroups = max(0, 16 - $ethGroups);
    }

    $name = trim((string) ($ctx['name'] ?? 'IOL'));
    if ($name === '') {
        $name = 'IOL';
    }
    $delaySec = 0;
    $iolNodeId = runtimeStableIntInRange('iol:' . $labId . ':' . $nodeId, 1, 1023);
    $binding = runtimeAllocateIolWrapperBinding(
        $labId,
        $nodeId,
        $iolNodeId,
        isset($ctx['runtime_console_port']) ? (int) $ctx['runtime_console_port'] : null
    );

    runtimePrepareIolRuntimeFiles($nodeDir, $iolNodeId);

    $args = [
        '-T', (string) $binding['tenant'],
        '-D', (string) $iolNodeId,
        '-t', $name,
        '-F', $binary,
        '-d', (string) $delaySec,
        '-e', (string) $ethGroups,
        '-s', (string) $serialGroups,
        '-r', (string) $ram,
        '-n', (string) $nvram,
    ];

    return [
        'launch_mode' => 'background',
        'binary' => $wrapperBinary,
        'args' => $args,
        'console_port' => (int) $binding['console_port'],
        'networks' => [],
        'image' => $image,
        'process_hints' => ['iol_wrapper', basename($binary)],
        'env' => [
            'LD_LIBRARY_PATH' => '/opt/unetlab/addons/iol/lib',
        ],
    ];
}

function runtimeBuildLaunchSpec(array $ctx, string $labId, string $nodeId, string $nodeDir, string $pidPath): array
{
    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
    switch ($nodeType) {
        case 'qemu':
            return runtimeBuildQemuLaunchSpec($ctx, $labId, $nodeId, $nodeDir, $pidPath);
        case 'dynamips':
            return runtimeBuildDynamipsLaunchSpec($ctx, $labId, $nodeId, $nodeDir);
        case 'vpcs':
            return runtimeBuildVpcsLaunchSpec($ctx, $labId, $nodeId);
        case 'iol':
            return runtimeBuildIolLaunchSpec($ctx, $labId, $nodeId, $nodeDir);
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
        "SELECT n.runtime_pid,
                n.is_running,
                n.power_state,
                n.runtime_console_port,
                n.runtime_check_console_port,
                n.console,
                n.node_type,
                n.image,
                l.author_user_id::text AS author_user_id
         FROM lab_nodes n
         INNER JOIN labs l ON l.id = n.lab_id
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
    $ownerUserId = (string) ($row['author_user_id'] ?? '');

    if ($pid <= 0) {
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

        // Keep queued transitional states ("starting"/"stopping") intact.
        // They are set by task enqueue and must be visible in UI until worker applies action.
        if ($running || $powerState === 'running' || (($powerState === 'starting' || $powerState === 'stopping') && !$hasActiveTask)) {
            setNodeStoppedState($db, $labId, $nodeId, null);
        }
        return;
    }

    $belongsToNode = runtimePidBelongsToNode($pid, $labId, $nodeId);
    if (!$belongsToNode && runtimeLegacyNodeTypeSupported($nodeType)) {
        $belongsToNode = runtimeLegacyPidBelongsToNode($db, $pid, $labId, $nodeId, $ownerUserId, $nodeType, $image);
    }

    if ($belongsToNode) {
        if (!$running || $powerState !== 'running') {
            if ($checkConsolePort === null && $nodeType === 'qemu' && in_array($consoleType, ['vnc', 'rdp'], true)) {
                $checkConsolePort = $consolePort;
            }
            setNodeRunningState($db, $labId, $nodeId, $pid, $consolePort, $checkConsolePort);
        }
        return;
    }

    if (runtimeLegacyNodeTypeSupported($nodeType)) {
        $resolved = runtimeLegacyResolveNodeProcess($db, $labId, $nodeId, $ownerUserId, $nodeType, $image);
        $resolvedPid = (int) ($resolved['pid'] ?? 0);
        if ($resolvedPid > 1) {
            $resolvedConsole = isset($resolved['console_port']) ? ((int) $resolved['console_port'] ?: null) : null;
            $resolvedCheckConsole = null;
            if ($nodeType === 'qemu' && in_array($consoleType, ['vnc', 'rdp'], true)) {
                $resolvedCheckConsole = $resolvedConsole;
            }
            setNodeRunningState($db, $labId, $nodeId, $resolvedPid, $resolvedConsole, $resolvedCheckConsole);
            return;
        }
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
    $isLegacyNodeType = runtimeLegacyNodeTypeSupported($nodeType);
    $isQemuLinuxNode = $nodeType === 'qemu' && runtimeQemuLooksLikeLinuxNode($ctx);
    $isQemuCloudNode = $nodeType === 'qemu' && runtimeNodeHasCloudPorts($ctx);
    $preferLegacyQemuStart = $isQemuCloudNode && !$isQemuLinuxNode;
    $disableLegacyFallback = $isQemuCloudNode && $isQemuLinuxNode;
    if ($existingPid > 1) {
        $alreadyRunning = runtimePidBelongsToNode($existingPid, $labId, $nodeId);
        if (!$alreadyRunning && $isLegacyNodeType) {
            $alreadyRunning = runtimeLegacyPidBelongsToNode(
                $db,
                $existingPid,
                $labId,
                $nodeId,
                $ownerUserId,
                $nodeType,
                (string) ($ctx['image'] ?? '')
            );
        }
        if ($alreadyRunning) {
            return [
                'already_running' => true,
                'pid' => $existingPid,
            ];
        }
    }

    if ($preferLegacyQemuStart && $isLegacyNodeType) {
        try {
            $legacyResult = runtimeLegacyStartNodeRuntime($db, $ctx, $labId, $nodeId);
            if (is_array($legacyResult)) {
                $legacyResult['runtime_fallback'] = 'legacy_cloud';
            }
            return $legacyResult;
        } catch (Throwable $e) {
            // Continue into native start path as last resort.
        }
    }

    try {
        if ($nodeType === 'iol') {
            $staleIolPids = runtimeListIolWrapperPidsBySignature($labId, $nodeId, $ctx);
            if (!empty($staleIolPids)) {
                runtimeTerminateNodePids($staleIolPids, 4.0);
            }
        }

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
            $pid = runtimeLaunchBackgroundProcess($labId, $nodeId, $nodeDir, $logPath, $pidPath, $binary, $args, $extraEnv);
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
        if (!runtimePidBelongsToNode($pid, $labId, $nodeId)) {
            $tail = runtimeTailText($logPath, 40);
            throw new RuntimeException('Node process exited immediately' . ($tail !== '' ? (': ' . $tail) : ''));
        }

        setNodeRunningState(
            $db,
            $labId,
            $nodeId,
            $pid,
            isset($spec['console_port']) ? (is_numeric($spec['console_port']) ? (int) $spec['console_port'] : null) : null,
            isset($spec['check_console_port']) ? (is_numeric($spec['check_console_port']) ? (int) $spec['check_console_port'] : null) : null
        );

        return [
            'already_running' => false,
            'pid' => $pid,
            'console_port' => $spec['console_port'] ?? null,
            'networks' => $spec['networks'] ?? [],
            'image' => $spec['image'] ?? null,
        ];
    } catch (Throwable $nativeError) {
        if (!$isLegacyNodeType || $disableLegacyFallback) {
            throw $nativeError;
        }
        try {
            $legacyResult = runtimeLegacyStartNodeRuntime($db, $ctx, $labId, $nodeId);
            if (is_array($legacyResult)) {
                $legacyResult['runtime_fallback'] = 'legacy';
            }
            return $legacyResult;
        } catch (Throwable $legacyError) {
            $nativeMsg = trim($nativeError->getMessage());
            if ($nativeMsg === '') {
                $nativeMsg = 'unknown native start error';
            }
            $legacyMsg = trim($legacyError->getMessage());
            if ($legacyMsg === '') {
                $legacyMsg = 'unknown legacy fallback error';
            }
            throw new RuntimeException(
                'Native runtime start failed: ' . $nativeMsg . '; legacy fallback failed: ' . $legacyMsg,
                0,
                $nativeError
            );
        }
    }
}

function stopLabNodeRuntime(PDO $db, string $labId, string $nodeId): array
{
    refreshLabNodeRuntimeState($db, $labId, $nodeId);

    $ctx = runtimeLoadNodeContext($db, $labId, $nodeId);
    $pid = (int) ($ctx['runtime_pid'] ?? 0);
    $nodeType = strtolower(trim((string) ($ctx['node_type'] ?? '')));
    $useLegacyRuntime = runtimeShouldUseLegacyRuntime($db, $ctx, $labId, $nodeId);
    if ($useLegacyRuntime && runtimeLegacyNodeTypeSupported($nodeType)) {
        return runtimeLegacyStopNodeRuntime($db, $ctx, $labId, $nodeId);
    }

    $targetPids = [];
    if ($pid > 1 && runtimePidBelongsToNode($pid, $labId, $nodeId)) {
        $targetPids[] = $pid;
    } elseif ($pid > 1 && $nodeType === 'iol') {
        // IOL wrapper processes can run under a different user; env matching may be unavailable.
        $targetPids[] = $pid;
    }
    $extraPids = runtimeListNodePidsByEnv($labId, $nodeId, [], false);
    foreach ($extraPids as $extraPid) {
        $targetPids[] = (int) $extraPid;
    }
    if ($nodeType === 'iol') {
        $fallbackIolPids = runtimeListIolWrapperPidsBySignature($labId, $nodeId, $ctx);
        foreach ($fallbackIolPids as $extraPid) {
            $targetPids[] = (int) $extraPid;
        }
    }
    $targetPids = array_values(array_unique(array_filter($targetPids, static function ($value): bool {
        return (int) $value > 1;
    })));

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
