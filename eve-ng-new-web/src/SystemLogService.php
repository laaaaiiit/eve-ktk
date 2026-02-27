<?php

declare(strict_types=1);

function systemLogsAllowedSources(): array
{
    $root = '/opt/unetlab/data/Logs';
    return [
        'access_http' => ['label' => 'HTTP Access', 'path' => $root . '/access_http.log'],
        'user_activity' => ['label' => 'User Activity', 'path' => $root . '/user_activity.log'],
        'security' => ['label' => 'Security', 'path' => $root . '/security.log'],
        'task_worker' => ['label' => 'Task Worker', 'path' => $root . '/task_worker.log'],
        'system_errors' => ['label' => 'System Errors', 'path' => $root . '/system_errors.log'],
    ];
}

function normalizeSystemLogSource(string $source): string
{
    $source = strtolower(trim($source));
    $aliases = [
        'access' => 'access_http',
        'api' => 'user_activity',
        'error' => 'system_errors',
        'php' => 'system_errors',
        'worker1' => 'task_worker',
        'worker2' => 'task_worker',
        'worker3' => 'task_worker',
        'worker4' => 'task_worker',
    ];
    if (isset($aliases[$source])) {
        $source = $aliases[$source];
    }
    return preg_match('/^[a-z0-9_]+$/', $source) ? $source : '';
}

function normalizeSystemLogLines(int $lines): int
{
    return max(20, min(2000, $lines));
}

function trimSystemLogLine(string $line): string
{
    $line = rtrim($line, "\r\n");
    if (strlen($line) > 8000) {
        return substr($line, 0, 8000) . ' …';
    }
    return $line;
}

function tailLinesFromFile(string $path, int $lines): array
{
    $lines = normalizeSystemLogLines($lines);
    if (!is_file($path)) {
        return [];
    }
    if (!is_readable($path)) {
        throw new RuntimeException('Log is not readable');
    }

    $size = @filesize($path);
    if (!is_int($size) && !is_float($size)) {
        $size = 0;
    }
    $sizeInt = max(0, (int) $size);
    if ($sizeInt === 0) {
        return [];
    }

    $estimatedBytes = max(65536, min(2097152, $lines * 420));
    $readBytes = min($sizeInt, $estimatedBytes);
    $offset = max(0, $sizeInt - $readBytes);

    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException('Failed to open log');
    }
    try {
        if ($offset > 0) {
            @fseek($fp, $offset);
        } else {
            @rewind($fp);
        }
        $data = @stream_get_contents($fp);
    } finally {
        @fclose($fp);
    }
    if (!is_string($data) || $data === '') {
        return [];
    }

    $parts = preg_split('/\r\n|\n|\r/', $data);
    if (!is_array($parts)) {
        return [];
    }
    if ($offset > 0 && count($parts) > 1) {
        // First line may be cut in the middle because of tail offset.
        array_shift($parts);
    }

    $parts = array_values(array_filter($parts, static function ($v): bool {
        return $v !== null && $v !== '';
    }));
    if (empty($parts)) {
        return [];
    }

    $slice = array_slice($parts, -$lines);
    return array_map(static function ($line): string {
        return trimSystemLogLine((string) $line);
    }, $slice);
}

function buildSystemLogSourcesMeta(): array
{
    $out = [];
    foreach (systemLogsAllowedSources() as $id => $row) {
        $path = (string) ($row['path'] ?? '');
        $exists = ($path !== '' && is_file($path));
        $readable = ($exists && is_readable($path));
        $size = $exists ? @filesize($path) : null;
        $mtime = $exists ? @filemtime($path) : null;
        $out[] = [
            'id' => (string) $id,
            'label' => (string) ($row['label'] ?? $id),
            'path' => $path,
            'exists' => (bool) $exists,
            'readable' => (bool) $readable,
            'size_bytes' => (is_int($size) || is_float($size)) ? (int) $size : null,
            'updated_at' => (is_int($mtime) && $mtime > 0) ? gmdate('c', $mtime) : null,
        ];
    }
    return $out;
}

function getSystemLogsPayloadForAdmin(string $sourceId, int $lines, string $search = ''): array
{
    $sourceId = normalizeSystemLogSource($sourceId);
    if ($sourceId === '') {
        throw new InvalidArgumentException('source_invalid');
    }
    $sources = systemLogsAllowedSources();
    if (!isset($sources[$sourceId])) {
        throw new InvalidArgumentException('source_invalid');
    }

    $lines = normalizeSystemLogLines($lines);
    $search = trim($search);
    if (strlen($search) > 255) {
        $search = substr($search, 0, 255);
    }

    $source = $sources[$sourceId];
    $path = (string) ($source['path'] ?? '');
    $all = tailLinesFromFile($path, $lines * 4);
    if ($search !== '') {
        $needle = strtolower($search);
        $all = array_values(array_filter($all, static function ($line) use ($needle): bool {
            return stripos((string) $line, $needle) !== false;
        }));
    }
    $linesOut = array_slice($all, -$lines);
    // UI expects newest records first.
    $linesOut = array_values(array_reverse($linesOut));

    return [
        'selected_source' => $sourceId,
        'selected_lines' => $lines,
        'selected_search' => $search,
        'sources' => buildSystemLogSourcesMeta(),
        'content' => [
            'source_id' => $sourceId,
            'label' => (string) ($source['label'] ?? $sourceId),
            'path' => $path,
            'line_count' => count($linesOut),
            'lines' => $linesOut,
        ],
    ];
}
