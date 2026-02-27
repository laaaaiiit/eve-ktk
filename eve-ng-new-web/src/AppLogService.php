<?php

declare(strict_types=1);

function v2AppLogFiles(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $root = '/opt/unetlab/data/Logs';
    $map = [
        'access_http' => $root . '/access_http.log',
        'user_activity' => $root . '/user_activity.log',
        'security' => $root . '/security.log',
        'task_worker' => $root . '/task_worker.log',
        'system_errors' => $root . '/system_errors.log',
    ];

    return $map;
}

function v2AppLogTimestamp(): string
{
    $dt = new DateTimeImmutable('now');
    return $dt->format('H:i:s d/m/y P');
}

function v2AppLogClientIp(): string
{
    if (function_exists('clientIp')) {
        $ip = trim((string) clientIp());
        if ($ip !== '') {
            return $ip;
        }
    }

    $realIp = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($realIp !== '') {
        return $realIp;
    }

    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remoteAddr !== '' ? $remoteAddr : '127.0.0.1';
}

function v2AppLogNormalizeStatus(string $status): string
{
    $status = strtoupper(trim($status));
    if (in_array($status, ['OK', 'SUCCESS', 'INFO'], true)) {
        return 'OK';
    }
    return 'ERROR';
}

function v2AppLogSafeKey(string $key): string
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
    $key = trim((string) $key, '_');
    return $key !== '' ? $key : 'field';
}

function v2AppLogSafeValue($value): string
{
    if ($value === null) {
        return '-';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $value = is_string($json) ? $json : '[unserializable]';
    }

    $text = trim((string) $value);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = str_replace(['[', ']'], ['(', ')'], (string) $text);
    if ($text === '') {
        return '""';
    }
    if (preg_match('/^[A-Za-z0-9._:@\/+=,-]+$/', $text)) {
        return $text;
    }
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    return '"' . $escaped . '"';
}

function v2AppLogContextToString(array $context): string
{
    if (empty($context)) {
        return '-';
    }

    $parts = [];
    foreach ($context as $key => $value) {
        $parts[] = v2AppLogSafeKey((string) $key) . '=' . v2AppLogSafeValue($value);
    }
    return implode(' ', $parts);
}

function v2AppLogAttachUser(array $context, ?array $user = null): array
{
    $user = $user ?? v2AppLogGetRequestUser();
    if (!is_array($user)) {
        return $context;
    }

    $userId = trim((string) ($user['id'] ?? ''));
    $username = trim((string) ($user['username'] ?? ''));
    $role = trim((string) ($user['role_name'] ?? $user['role'] ?? ''));
    if ($userId !== '') {
        $context['user_id'] = $userId;
    }
    if ($username !== '') {
        $context['user'] = $username;
    }
    if ($role !== '') {
        $context['role'] = strtolower($role);
    }

    return $context;
}

function v2AppLogWrite(string $channel, string $status, array $context = [], ?string $ip = null): void
{
    $files = v2AppLogFiles();
    if (!isset($files[$channel])) {
        return;
    }

    $status = v2AppLogNormalizeStatus($status);
    $ip = trim((string) ($ip ?? v2AppLogClientIp()));
    if ($ip === '') {
        $ip = '127.0.0.1';
    }

    $line = sprintf(
        '[%s] [%s] [%s] - %s',
        v2AppLogTimestamp(),
        $ip,
        $status,
        v2AppLogContextToString($context)
    );

    $path = (string) $files[$channel];
    $written = @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        @error_log($line);
    }
}

function v2AppLogRequestInit(string $method, string $path, float $startedAt): void
{
    $GLOBALS['__v2_app_log_request'] = [
        'method' => strtoupper(trim($method)),
        'path' => trim($path),
        'started_at' => $startedAt,
        'user' => null,
        'action_logged' => false,
    ];
}

function v2AppLogGetRequestContext(): array
{
    $ctx = $GLOBALS['__v2_app_log_request'] ?? null;
    return is_array($ctx) ? $ctx : [];
}

function v2AppLogSetRequestUser(?array $user): void
{
    if (!isset($GLOBALS['__v2_app_log_request']) || !is_array($GLOBALS['__v2_app_log_request'])) {
        return;
    }
    if (!is_array($user)) {
        return;
    }
    $GLOBALS['__v2_app_log_request']['user'] = $user;
}

function v2AppLogGetRequestUser(): ?array
{
    $ctx = v2AppLogGetRequestContext();
    return isset($ctx['user']) && is_array($ctx['user']) ? $ctx['user'] : null;
}

function v2AppLogMarkActionLogged(): void
{
    if (!isset($GLOBALS['__v2_app_log_request']) || !is_array($GLOBALS['__v2_app_log_request'])) {
        return;
    }
    $GLOBALS['__v2_app_log_request']['action_logged'] = true;
}

function v2AppLogActionAlreadyLogged(): bool
{
    $ctx = v2AppLogGetRequestContext();
    return !empty($ctx['action_logged']);
}
