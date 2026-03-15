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

$options = getopt('', ['path:', 'meta::', 'offset::', 'length::']);
$path = trim((string) ($options['path'] ?? ''));
if (normalize_abs_path($path) === '') {
    fail('invalid path', 2);
}
if (!path_allowed($path, true)) {
    fail('path is not allowed', 2);
}
if (!is_file($path) || !is_readable($path)) {
    fail('file not readable', 3);
}

if (array_key_exists('meta', $options)) {
    $size = @filesize($path);
    $size = is_int($size) ? $size : 0;
    echo json_encode([
        'size' => $size,
        'name' => basename($path),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

$size = @filesize($path);
$size = is_int($size) ? $size : 0;
$offset = isset($options['offset']) ? max(0, (int) $options['offset']) : 0;
$length = isset($options['length']) ? max(0, (int) $options['length']) : max(0, $size - $offset);
if ($offset > $size) {
    exit(0);
}

$fp = @fopen($path, 'rb');
if ($fp === false) {
    fail('open failed', 4);
}
@fseek($fp, $offset);

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
exit(0);
