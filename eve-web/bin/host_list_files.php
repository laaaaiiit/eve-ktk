#!/usr/bin/env php
<?php

declare(strict_types=1);

function fail(string $msg, int $code = 1): void
{
    fwrite(STDERR, $msg . "\n");
    exit($code);
}

function normalize_abs_path(string $path): string
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

function allowed_roots(): array
{
    $raw = trim((string) getenv('EVE_VM_CONSOLE_ALLOWED_ROOTS'));
    if ($raw === '') {
        $raw = '/opt/unetlab,/root,/home,/tmp,/var/tmp';
    }
    $parts = preg_split('/[\s,]+/', $raw);
    if (!is_array($parts)) {
        $parts = [];
    }
    $roots = [];
    foreach ($parts as $part) {
        $root = normalize_abs_path((string) $part);
        if ($root === '' || $root === '/') {
            continue;
        }
        $real = @realpath($root);
        if (is_string($real) && $real !== '') {
            $root = normalize_abs_path($real);
        }
        if ($root !== '' && $root !== '/') {
            $roots[$root] = true;
        }
    }
    $list = array_keys($roots);
    sort($list, SORT_STRING);
    return $list;
}

function path_allowed(string $path, bool $mustExist = false): bool
{
    $normalized = normalize_abs_path($path);
    if ($normalized === '') {
        return false;
    }
    $effective = $normalized;
    $real = @realpath($normalized);
    if (is_string($real) && $real !== '') {
        $effective = normalize_abs_path($real);
    } elseif ($mustExist) {
        return false;
    }
    if ($effective === '') {
        return false;
    }
    foreach (allowed_roots() as $root) {
        if ($effective === $root || strpos($effective, rtrim($root, '/') . '/') === 0) {
            return true;
        }
    }
    return false;
}

$options = getopt('', ['path:', 'limit:']);
$path = trim((string) ($options['path'] ?? ''));
$limit = isset($options['limit']) ? (int) $options['limit'] : 500;
$limit = max(10, min(5000, $limit));

if (normalize_abs_path($path) === '') {
    fail('invalid path', 2);
}
if (!path_allowed($path, true)) {
    fail('path is not allowed', 2);
}
if (!is_dir($path) || !is_readable($path)) {
    fail('directory not readable', 3);
}

$entries = @scandir($path);
if (!is_array($entries)) {
    fail('failed to read directory', 4);
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
    if (count($result) >= $limit) {
        break;
    }
}

usort($result, static function (array $a, array $b): int {
    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

echo json_encode([
    'directory' => $path,
    'files' => $result,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
