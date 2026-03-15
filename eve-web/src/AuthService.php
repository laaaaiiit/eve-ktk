<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

function normalizeClientIpCandidate(string $value): string
{
    $candidate = trim($value);
    if ($candidate === '') {
        return '';
    }

    // RFC7239/XFF may carry quoted values.
    $candidate = trim($candidate, "\"'");
    if ($candidate === '' || strtolower($candidate) === 'unknown') {
        return '';
    }

    // Bracketed IPv6 form: [2001:db8::1]:443
    if (strlen($candidate) > 2 && $candidate[0] === '[') {
        $end = strpos($candidate, ']');
        if ($end !== false) {
            $candidate = substr($candidate, 1, $end - 1);
        }
    } elseif (preg_match('/^(.+):([0-9]{1,5})$/', $candidate, $m)) {
        // IPv4:port
        $host = $m[1];
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $candidate = $host;
        }
    }

    // IPv6 zone index: fe80::1%eth0
    $zonePos = strpos($candidate, '%');
    if ($zonePos !== false) {
        $candidate = substr($candidate, 0, $zonePos);
    }

    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : '';
}

function clientIpInCidrRange(string $ip, string $cidr): bool
{
    $ip = normalizeClientIpCandidate($ip);
    $cidr = trim($cidr);
    if ($ip === '' || $cidr === '') {
        return false;
    }

    if (strpos($cidr, '/') === false) {
        return normalizeClientIpCandidate($cidr) === $ip;
    }

    [$baseRaw, $prefixRaw] = explode('/', $cidr, 2);
    $base = normalizeClientIpCandidate($baseRaw);
    if ($base === '' || !preg_match('/^[0-9]{1,3}$/', trim($prefixRaw))) {
        return false;
    }

    $ipBin = @inet_pton($ip);
    $baseBin = @inet_pton($base);
    if ($ipBin === false || $baseBin === false || strlen($ipBin) !== strlen($baseBin)) {
        return false;
    }

    $prefix = (int) trim($prefixRaw);
    $totalBits = strlen($ipBin) * 8;
    if ($prefix < 0 || $prefix > $totalBits) {
        return false;
    }

    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;

    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($baseBin, 0, $fullBytes)) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }

    $mask = ((0xFF << (8 - $remainingBits)) & 0xFF);
    $ipByte = ord($ipBin[$fullBytes]);
    $baseByte = ord($baseBin[$fullBytes]);
    return (($ipByte & $mask) === ($baseByte & $mask));
}

function shouldTrustProxyHeadersForClientIp(string $remoteAddr): bool
{
    $remote = normalizeClientIpCandidate($remoteAddr);
    if ($remote === '') {
        return false;
    }

    $forceTrustRaw = strtolower(trim((string) getenv('EVE_TRUST_PROXY_HEADERS')));
    if (in_array($forceTrustRaw, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    // Optional explicit allow-list for proxies (comma/space separated IPs/CIDRs).
    $trusted = trim((string) getenv('EVE_TRUSTED_PROXY_IPS'));
    if ($trusted !== '') {
        foreach (preg_split('/[\s,]+/', $trusted) as $entry) {
            if (clientIpInCidrRange($remote, (string) $entry)) {
                return true;
            }
        }
        return false;
    }

    // Secure-by-default: trust only local reverse proxy unless explicitly configured.
    return $remote === '127.0.0.1' || $remote === '::1';
}

function clientIpFromForwardedHeader(string $forwarded): string
{
    if (trim($forwarded) === '') {
        return '';
    }
    $segments = explode(',', $forwarded);
    foreach ($segments as $segment) {
        $parts = explode(';', $segment);
        foreach ($parts as $part) {
            $token = trim($part);
            if (stripos($token, 'for=') !== 0) {
                continue;
            }
            $candidate = normalizeClientIpCandidate(substr($token, 4));
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }
    return '';
}

function clientIpFromXForwardedFor(string $xff): string
{
    if (trim($xff) === '') {
        return '';
    }
    $parts = explode(',', $xff);
    foreach ($parts as $part) {
        $candidate = normalizeClientIpCandidate($part);
        if ($candidate !== '') {
            return $candidate;
        }
    }
    return '';
}

function forwardedChainContainsIp(string $headerValue, string $ip): bool
{
    $ip = normalizeClientIpCandidate($ip);
    if ($ip === '' || trim($headerValue) === '') {
        return false;
    }
    foreach (explode(',', $headerValue) as $part) {
        $candidate = normalizeClientIpCandidate($part);
        if ($candidate !== '' && $candidate === $ip) {
            return true;
        }
    }
    return false;
}

function forwardedHeaderContainsIp(string $headerValue, string $ip): bool
{
    $ip = normalizeClientIpCandidate($ip);
    if ($ip === '' || trim($headerValue) === '') {
        return false;
    }
    foreach (explode(',', $headerValue) as $segment) {
        foreach (explode(';', $segment) as $part) {
            $token = trim($part);
            if (stripos($token, 'for=') !== 0) {
                continue;
            }
            $candidate = normalizeClientIpCandidate(substr($token, 4));
            if ($candidate !== '' && $candidate === $ip) {
                return true;
            }
        }
    }
    return false;
}

function clientIpFromSingleHeaders(array $headerKeys): string
{
    foreach ($headerKeys as $key) {
        $candidate = normalizeClientIpCandidate((string) ($_SERVER[$key] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }
    return '';
}

function clientIp(): string
{
    $remoteAddr = normalizeClientIpCandidate((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $forwardedRaw = (string) ($_SERVER['HTTP_FORWARDED'] ?? ($_SERVER['HTTP_X_FORWARDED'] ?? ''));
    $xffRaw = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['HTTP_FORWARDED_FOR'] ?? ''));

    $trustProxyHeaders = shouldTrustProxyHeadersForClientIp($remoteAddr);
    if (!$trustProxyHeaders && $remoteAddr !== '') {
        // Many proxies append their own REMOTE_ADDR to forwarded chains.
        $trustProxyHeaders =
            forwardedChainContainsIp($xffRaw, $remoteAddr)
            || forwardedHeaderContainsIp($forwardedRaw, $remoteAddr);
    }

    if ($trustProxyHeaders) {
        $forwarded = clientIpFromForwardedHeader($forwardedRaw);
        if ($forwarded !== '') {
            return $forwarded;
        }

        $xff = clientIpFromXForwardedFor($xffRaw);
        if ($xff !== '') {
            return $xff;
        }

        $proxySingle = clientIpFromSingleHeaders([
            'HTTP_X_REAL_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_CLIENT_IP',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_CLIENT_IP',
        ]);
        if ($proxySingle !== '') {
            return $proxySingle;
        }
    }

    if ($remoteAddr !== '') {
        return $remoteAddr;
    }

    // Last-resort fallback for unusual setups with missing REMOTE_ADDR.
    $proxySingle = clientIpFromSingleHeaders([
        'HTTP_X_REAL_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_CLIENT_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_CLIENT_IP',
    ]);
    if ($proxySingle !== '') {
        return $proxySingle;
    }

    $xff = clientIpFromXForwardedFor($xffRaw);
    if ($xff !== '') {
        return $xff;
    }

    $forwarded = clientIpFromForwardedHeader($forwardedRaw);
    if ($forwarded !== '') {
        return $forwarded;
    }
    return '127.0.0.1';
}

function findUserForLogin(PDO $db, string $username): ?array
{
    $sql = "SELECT u.id, u.username, u.password_hash, u.is_blocked, u.lang, u.theme, r.id AS role_id, r.name AS role_name
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.username = :username
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function authSessionTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function authSessionSelector(string $selector): string
{
    $normalized = strtolower(trim($selector));
    if (!preg_match('/^[a-f0-9]{64}$/', $normalized)) {
        return '';
    }
    return $normalized;
}

function createSession(PDO $db, string $userId, string $ip, string $userAgent, int $ttlSeconds = 28800): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = authSessionTokenHash($token);
    $sql = "INSERT INTO auth_sessions (token, user_id, expires_at, ip, user_agent, last_activity)
            VALUES (:token, :user_id, NOW() + (:ttl || ' seconds')::interval, :ip, :user_agent, NOW())";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':token', $tokenHash, PDO::PARAM_STR);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
    $stmt->bindValue(':ttl', (string) $ttlSeconds, PDO::PARAM_STR);
    $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
    $stmt->bindValue(':user_agent', $userAgent, PDO::PARAM_STR);
    $stmt->execute();

    return $token;
}

function setSessionCookie(string $token, int $ttlSeconds = 28800): void
{
    setcookie('eve_ng_v2_session', $token, [
        'expires' => time() + $ttlSeconds,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearSessionCookie(): void
{
    setcookie('eve_ng_v2_session', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Cleanup cookie values created by older path scopes.
    setcookie('eve_ng_v2_session', '', [
        'expires' => time() - 3600,
        'path' => '/v2/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    setcookie('eve_ng_v2_session', '', [
        'expires' => time() - 3600,
        'path' => '/v2/api/v2/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    setcookie('eve_ng_v2_session', '', [
        'expires' => time() - 3600,
        'path' => '/api/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function touchLastSeen(PDO $db, string $userId, string $ip): void
{
    $stmt = $db->prepare('UPDATE users SET last_seen = NOW(), last_ip = :ip WHERE id = :id');
    $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
    $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
    $stmt->execute();
}

function getUserFromSession(PDO $db, string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $tokenHash = authSessionTokenHash($token);

    $cleanup = $db->prepare(
        "UPDATE auth_sessions
         SET ended_at = NOW(),
             ended_reason = COALESCE(ended_reason, 'expired')
         WHERE ended_at IS NULL
           AND expires_at < NOW()"
    );
    $cleanup->execute();

    $sql = "SELECT u.id, u.username, u.last_seen, u.last_ip, u.is_blocked, u.lang, u.theme, r.id AS role_id, r.name AS role_name
            FROM auth_sessions s
            INNER JOIN users u ON u.id = s.user_id
            INNER JOIN roles r ON r.id = u.role_id
            WHERE s.token = :token_hash
              AND s.ended_at IS NULL
              AND s.expires_at >= NOW()
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row === false || (bool) $row['is_blocked']) {
        return null;
    }
    return $row;
}

function touchSessionActivity(PDO $db, string $token, string $ip): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }
    $tokenHash = authSessionTokenHash($token);

    $stmt = $db->prepare(
        'UPDATE auth_sessions
         SET last_activity = NOW(), ip = :ip
         WHERE token = :token_hash
           AND ended_at IS NULL
           AND expires_at >= NOW()'
    );
    $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_STR);
    $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->rowCount() > 0;
}

function listOnlineUsers(PDO $db, int $onlineWindowSeconds = 90, bool $includeAdmin = true): array
{
    $sql = "SELECT u.id AS user_id,
                   u.username,
                   r.name AS role_name,
                   COUNT(*) FILTER (WHERE s.last_activity >= NOW() - (:window || ' seconds')::interval) AS online_sessions,
                   MAX(s.last_activity) AS last_activity
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            LEFT JOIN auth_sessions s ON s.user_id = u.id
                                     AND s.ended_at IS NULL
                                     AND s.expires_at >= NOW()
            " . ($includeAdmin ? '' : "WHERE LOWER(r.name) <> 'admin'") . "
            GROUP BY u.id, u.username, r.name
            ORDER BY u.id ASC";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':window', (string) $onlineWindowSeconds, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }

    foreach ($rows as &$row) {
        $row['online_sessions'] = (int) ($row['online_sessions'] ?? 0);
        $row['is_online'] = $row['online_sessions'] > 0;
    }
    unset($row);
    return $rows;
}

function sessionTokenBelongsToAdmin(PDO $db, string $token): bool
{
    $selector = authSessionSelector($token);
    if ($selector === '') {
        return false;
    }
    $stmt = $db->prepare(
        "SELECT r.name
         FROM auth_sessions s
         INNER JOIN users u ON u.id = s.user_id
         INNER JOIN roles r ON r.id = u.role_id
         WHERE s.token = :token
         LIMIT 1"
    );
    $stmt->bindValue(':token', $selector, PDO::PARAM_STR);
    $stmt->execute();
    $roleName = strtolower(trim((string) $stmt->fetchColumn()));
    return $roleName === 'admin';
}

function deleteUserSessions(PDO $db, string $userId): int
{
    $stmt = $db->prepare(
        "UPDATE auth_sessions
         SET ended_at = NOW(),
             ended_reason = 'admin_terminated'
         WHERE user_id = :user_id
           AND ended_at IS NULL"
    );
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->rowCount();
}

function listUserSessions(PDO $db, string $userId): array
{
    $sql = "SELECT s.token AS token,
                   s.token AS session_selector,
                   s.created_at,
                   s.last_activity,
                   s.expires_at,
                   s.ended_at,
                   s.ended_reason,
                   s.ip,
                   s.user_agent,
                   (s.ended_at IS NULL AND s.expires_at >= NOW()) AS is_alive,
                   (s.ended_at IS NULL AND s.expires_at >= NOW() AND s.last_activity >= NOW() - INTERVAL '90 seconds') AS is_active_now,
                   u.username
            FROM auth_sessions s
            INNER JOIN users u ON u.id = s.user_id
            WHERE s.user_id = :user_id
            ORDER BY s.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function deleteSessionByToken(PDO $db, string $token): bool
{
    $selector = authSessionSelector($token);
    if ($selector === '') {
        return false;
    }
    $stmt = $db->prepare(
        "UPDATE auth_sessions
         SET ended_at = NOW(),
             ended_reason = 'admin_terminated'
         WHERE token = :token
           AND ended_at IS NULL"
    );
    $stmt->bindValue(':token', $selector, PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        return true;
    }

    $exists = $db->prepare('SELECT 1 FROM auth_sessions WHERE token = :token LIMIT 1');
    $exists->bindValue(':token', $selector, PDO::PARAM_STR);
    $exists->execute();
    return $exists->fetchColumn() !== false;
}

function deleteSession(PDO $db, string $token): void
{
    $token = trim($token);
    if ($token === '') {
        return;
    }
    $tokenHash = authSessionTokenHash($token);

    $stmt = $db->prepare(
        "UPDATE auth_sessions
         SET ended_at = NOW(),
             ended_reason = 'user_logout'
         WHERE token = :token_hash
           AND ended_at IS NULL"
    );
    $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_STR);
    $stmt->execute();
}

function authLoginThrottleWindowSeconds(): int
{
    $value = (int) getenv('EVE_LOGIN_THROTTLE_WINDOW_SECONDS');
    if ($value < 30) {
        $value = 600;
    }
    return $value;
}

function authLoginThrottleMaxAttempts(): int
{
    $value = (int) getenv('EVE_LOGIN_THROTTLE_MAX_ATTEMPTS');
    if ($value < 1) {
        $value = 5;
    }
    return $value;
}

function authLoginThrottleBlockSeconds(): int
{
    $value = (int) getenv('EVE_LOGIN_THROTTLE_BLOCK_SECONDS');
    if ($value < 30) {
        $value = 900;
    }
    return $value;
}

function authLoginThrottleKey(string $username, string $ip): string
{
    $identity = strtolower(trim($username)) . '|' . trim($ip);
    return hash('sha256', $identity);
}

function authLoginThrottleCleanup(PDO $db): void
{
    static $lastCleanup = 0.0;
    $now = microtime(true);
    if (($now - $lastCleanup) < 60.0) {
        return;
    }
    $lastCleanup = $now;

    try {
        $stmt = $db->prepare(
            "DELETE FROM auth_login_throttle
             WHERE blocked_until < NOW() - INTERVAL '1 day'
                OR (blocked_until IS NULL AND last_failed_at < NOW() - INTERVAL '1 day')"
        );
        $stmt->execute();
    } catch (Throwable $e) {
        // Migration may not be applied yet.
    }
}

function authLoginThrottleCheck(PDO $db, string $username, string $ip): array
{
    authLoginThrottleCleanup($db);
    $key = authLoginThrottleKey($username, $ip);
    try {
        $stmt = $db->prepare(
            "SELECT blocked_until
             FROM auth_login_throttle
             WHERE throttle_key = :throttle_key
             LIMIT 1"
        );
        $stmt->bindValue(':throttle_key', $key, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['blocked' => false, 'retry_after' => 0];
    }
    if (!is_array($row) || empty($row['blocked_until'])) {
        return ['blocked' => false, 'retry_after' => 0];
    }

    $blockedUntil = (string) $row['blocked_until'];
    $retryAfter = max(0, strtotime($blockedUntil) - time());
    return [
        'blocked' => ($retryAfter > 0),
        'retry_after' => $retryAfter,
    ];
}

function authLoginThrottleRegisterFailure(PDO $db, string $username, string $ip): array
{
    authLoginThrottleCleanup($db);
    $key = authLoginThrottleKey($username, $ip);
    $windowSeconds = authLoginThrottleWindowSeconds();
    $maxAttempts = authLoginThrottleMaxAttempts();
    $blockSeconds = authLoginThrottleBlockSeconds();

    try {
        $stmt = $db->prepare(
            "INSERT INTO auth_login_throttle (throttle_key, username, ip, failed_count, first_failed_at, last_failed_at, blocked_until, updated_at)
             VALUES (:throttle_key, :username, :ip, 1, NOW(), NOW(), NULL, NOW())
             ON CONFLICT (throttle_key) DO UPDATE
             SET
                 failed_count = CASE
                     WHEN auth_login_throttle.last_failed_at < NOW() - (:window_seconds || ' seconds')::interval THEN 1
                     ELSE auth_login_throttle.failed_count + 1
                 END,
                 first_failed_at = CASE
                     WHEN auth_login_throttle.last_failed_at < NOW() - (:window_seconds || ' seconds')::interval THEN NOW()
                     ELSE auth_login_throttle.first_failed_at
                 END,
                 last_failed_at = NOW(),
                 blocked_until = CASE
                     WHEN auth_login_throttle.last_failed_at < NOW() - (:window_seconds || ' seconds')::interval THEN NULL
                     WHEN auth_login_throttle.failed_count + 1 >= :max_attempts THEN NOW() + (:block_seconds || ' seconds')::interval
                     ELSE auth_login_throttle.blocked_until
                 END,
                 updated_at = NOW()
             RETURNING failed_count, blocked_until"
        );
        $stmt->bindValue(':throttle_key', $key, PDO::PARAM_STR);
        $stmt->bindValue(':username', strtolower(trim($username)), PDO::PARAM_STR);
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->bindValue(':window_seconds', (string) $windowSeconds, PDO::PARAM_STR);
        $stmt->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
        $stmt->bindValue(':block_seconds', (string) $blockSeconds, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [
            'blocked' => false,
            'retry_after' => 0,
            'failed_count' => 0,
        ];
    }

    $retryAfter = 0;
    if (is_array($row) && !empty($row['blocked_until'])) {
        $retryAfter = max(0, strtotime((string) $row['blocked_until']) - time());
    }

    return [
        'blocked' => ($retryAfter > 0),
        'retry_after' => $retryAfter,
        'failed_count' => (int) (($row['failed_count'] ?? 0)),
    ];
}

function authLoginThrottleReset(PDO $db, string $username, string $ip): void
{
    $key = authLoginThrottleKey($username, $ip);
    try {
        $stmt = $db->prepare('DELETE FROM auth_login_throttle WHERE throttle_key = :throttle_key');
        $stmt->bindValue(':throttle_key', $key, PDO::PARAM_STR);
        $stmt->execute();
    } catch (Throwable $e) {
        // Ignore if throttle table is unavailable.
    }
}
