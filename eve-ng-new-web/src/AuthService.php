<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

function clientIp(): string
{
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return (string) $_SERVER['HTTP_X_REAL_IP'];
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
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

function createSession(PDO $db, string $userId, string $ip, string $userAgent, int $ttlSeconds = 28800): string
{
    $token = bin2hex(random_bytes(32));
    $sql = "INSERT INTO auth_sessions (token, user_id, expires_at, ip, user_agent, last_activity)
            VALUES (:token, :user_id, NOW() + (:ttl || ' seconds')::interval, :ip, :user_agent, NOW())";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
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
            WHERE s.token = :token
              AND s.ended_at IS NULL
              AND s.expires_at >= NOW()
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row === false || (bool) $row['is_blocked']) {
        return null;
    }
    return $row;
}

function touchSessionActivity(PDO $db, string $token, string $ip): bool
{
    $stmt = $db->prepare(
        'UPDATE auth_sessions
         SET last_activity = NOW(), ip = :ip
         WHERE token = :token
           AND ended_at IS NULL
           AND expires_at >= NOW()'
    );
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
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
    $stmt = $db->prepare(
        "SELECT r.name
         FROM auth_sessions s
         INNER JOIN users u ON u.id = s.user_id
         INNER JOIN roles r ON r.id = u.role_id
         WHERE s.token = :token
         LIMIT 1"
    );
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
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
    $sql = "SELECT s.token,
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
    $stmt = $db->prepare(
        "UPDATE auth_sessions
         SET ended_at = NOW(),
             ended_reason = 'admin_terminated'
         WHERE token = :token
           AND ended_at IS NULL"
    );
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        return true;
    }

    $exists = $db->prepare('SELECT 1 FROM auth_sessions WHERE token = :token LIMIT 1');
    $exists->bindValue(':token', $token, PDO::PARAM_STR);
    $exists->execute();
    return $exists->fetchColumn() !== false;
}

function deleteSession(PDO $db, string $token): void
{
    $stmt = $db->prepare(
        "UPDATE auth_sessions
         SET ended_at = NOW(),
             ended_reason = 'user_logout'
         WHERE token = :token
           AND ended_at IS NULL"
    );
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $stmt->execute();
}
