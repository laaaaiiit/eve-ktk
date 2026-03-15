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

$options = getopt('', ['tmp:', 'dest:', 'overwrite:']);
$tmp = trim((string) ($options['tmp'] ?? ''));
$dest = trim((string) ($options['dest'] ?? ''));
$overwrite = ((string) ($options['overwrite'] ?? '0')) === '1';

if ($tmp === '' || $dest === '') {
    fail('missing args', 2);
}
if (strpos($tmp, "\0") !== false || strpos($dest, "\0") !== false) {
    fail('invalid path', 2);
}
if (normalize_abs_path($dest) === '') {
    fail('invalid destination', 2);
}
if (!path_allowed($dest, false)) {
    fail('destination is not allowed', 2);
}

$tmpRoot = '/opt/unetlab/data/v2-console/host-uploads/';
if (strpos($tmp, $tmpRoot) !== 0) {
    fail('invalid temporary file root', 2);
}
if (!is_file($tmp)) {
    fail('temporary file missing', 2);
}

$destDir = dirname($dest);
if (!is_dir($destDir)) {
    fail('destination dir missing', 2);
}

if (is_file($dest)) {
    if (!$overwrite) {
        fail('destination exists', 3);
    }
    if (!@unlink($dest)) {
        fail('failed unlink destination', 4);
    }
}

if (!@rename($tmp, $dest)) {
    fail('rename failed', 5);
}

@chmod($dest, 0644);
exit(0);
