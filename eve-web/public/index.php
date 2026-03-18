<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/AppLogService.php';
require_once __DIR__ . '/../src/RbacService.php';
require_once __DIR__ . '/../src/UserService.php';
require_once __DIR__ . '/../src/CloudService.php';
require_once __DIR__ . '/../src/MainService.php';
require_once __DIR__ . '/../src/LabService.php';
require_once __DIR__ . '/../src/LabCheckService.php';
require_once __DIR__ . '/../src/CollabService.php';
require_once __DIR__ . '/../src/LabManagementService.php';
require_once __DIR__ . '/../src/LabTaskService.php';
require_once __DIR__ . '/../src/ConsoleService.php';
require_once __DIR__ . '/../src/SystemConsoleService.php';
require_once __DIR__ . '/../src/SystemService.php';
require_once __DIR__ . '/../src/SystemLogService.php';
require_once __DIR__ . '/../src/SystemAuditService.php';
require_once __DIR__ . '/../src/LabCaptureService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

function v2ApplySecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' ws: wss:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

function v2RequestOriginBase(): string
{
    $forwardedProtoRaw = trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $forwardedProto = '';
    if ($forwardedProtoRaw !== '') {
        $forwardedProto = strtolower(trim((string) explode(',', $forwardedProtoRaw, 2)[0]));
    }
    $scheme = in_array($forwardedProto, ['http', 'https'], true)
        ? $forwardedProto
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $hasForwardedProto = ($forwardedProto !== '');

    $forwardedHostRaw = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    $hostHeader = '';
    if ($forwardedHostRaw !== '') {
        $hostHeader = trim((string) explode(',', $forwardedHostRaw, 2)[0]);
    }
    if ($hostHeader === '') {
        $hostHeader = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    }
    $hasForwardedHost = ($forwardedHostRaw !== '');

    $host = '';
    $port = null;
    if ($hostHeader !== '') {
        $parts = @parse_url($scheme . '://' . $hostHeader);
        if (is_array($parts)) {
            $host = strtolower((string) ($parts['host'] ?? ''));
            $port = isset($parts['port']) ? (int) $parts['port'] : null;
        }
        if ($host === '') {
            $host = strtolower($hostHeader);
        }
    }

    if ($host === '') {
        $host = strtolower((string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }
    if ($port === null) {
        $forwardedPort = trim((string) ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? ''));
        if ($forwardedPort !== '' && ctype_digit($forwardedPort)) {
            $port = (int) $forwardedPort;
        }
    }
    // Internal SERVER_PORT can differ when TLS is terminated by reverse-proxy.
    // Only trust it for non-forwarded/direct requests.
    if ($port === null && !$hasForwardedProto && !$hasForwardedHost) {
        $serverPort = trim((string) ($_SERVER['SERVER_PORT'] ?? ''));
        if ($serverPort !== '' && ctype_digit($serverPort)) {
            $port = (int) $serverPort;
        }
    }

    $origin = $scheme . '://' . $host;
    $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    if ($port !== null && !$isDefaultPort) {
        $origin .= ':' . $port;
    }
    return $origin;
}

function v2IsSameOriginUrl(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($scheme === '' || $host === '') {
        return false;
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    $target = $scheme . '://' . $host;
    if ($port !== null) {
        $isDefault = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        if (!$isDefault) {
            $target .= ':' . $port;
        }
    }
    return hash_equals(v2RequestOriginBase(), $target);
}

function v2RequestHasBody(): bool
{
    $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($length > 0) {
        return true;
    }
    $transferEncoding = strtolower(trim((string) ($_SERVER['HTTP_TRANSFER_ENCODING'] ?? '')));
    return $transferEncoding !== '';
}

function v2RequestContentType(): string
{
    $raw = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
    if ($raw === '') {
        return '';
    }
    $parts = explode(';', $raw, 2);
    return trim((string) ($parts[0] ?? ''));
}

function v2PathAllowsRawBody(string $method, string $path): bool
{
    if ($method === 'PATCH' && preg_match('#^/api/system/host-console/uploads/[a-f0-9-]{36}$#i', $path)) {
        return true;
    }
    if ($method === 'POST' && $path === '/api/main/labs/import') {
        // Archive import uses multipart/form-data upload.
        return true;
    }
    return false;
}

function v2EnsureMutationRequestGuards(string $method, string $path): void
{
    $method = strtoupper(trim($method));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }
    if (strpos($path, '/api/') !== 0) {
        return;
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $xrw = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    $sameOrigin = false;
    if ($origin !== '') {
        $sameOrigin = v2IsSameOriginUrl($origin);
    } elseif ($referer !== '') {
        $sameOrigin = v2IsSameOriginUrl($referer);
    } elseif ($xrw === 'xmlhttprequest') {
        $sameOrigin = true;
    } elseif ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1') {
        // Local automation.
        $sameOrigin = true;
    }

    if (!$sameOrigin) {
        v2AppLogWrite('security', 'ERROR', [
            'event' => 'csrf_guard_rejected',
            'method' => $method,
            'path' => $path,
            'origin' => $origin,
            'referer' => $referer,
            'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
            'remote_addr' => $remoteAddr,
            'x_forwarded_host' => (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''),
            'x_forwarded_proto' => (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''),
            'x_forwarded_port' => (string) ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? ''),
        ]);
        jsonResponse(403, 'fail', 'Cross-site request rejected');
        exit;
    }

    if (v2RequestHasBody() && !v2PathAllowsRawBody($method, $path)) {
        $contentType = v2RequestContentType();
        if ($contentType !== 'application/json' && $contentType !== 'text/json') {
            jsonResponse(415, 'fail', 'Unsupported Content-Type');
            exit;
        }
    }
}

function v2AppLogIsImportantUserActionPath(string $method, string $path): bool
{
    $method = strtoupper(trim($method));
    $path = trim($path);
    if ($path === '') {
        return false;
    }

    // Power actions.
    if (preg_match('#^/api/labs/[a-f0-9-]{36}/nodes/[a-f0-9-]{36}/power/(start|stop|reload|wipe)$#i', $path)) {
        return true;
    }
    if (preg_match('#^/api/labs/[a-f0-9-]{36}/power/(start|stop|reload|wipe)$#i', $path)) {
        return true;
    }

    // Console open/close.
    if ($method === 'POST' && preg_match('#^/api/labs/[a-f0-9-]{36}/nodes/[a-f0-9-]{36}/console/session$#i', $path)) {
        return true;
    }
    if ($method === 'DELETE' && preg_match('#^/api/console/sessions/[a-f0-9-]{36}$#i', $path)) {
        return true;
    }

    // Lab checks/tasks configuration and run.
    if (preg_match('#^/api/labs/[a-f0-9-]{36}/checks(?:$|/)#i', $path)) {
        return true;
    }

    // Main explorer user actions (create/update/move/share/export/import/delete).
    if (preg_match('#^/api/main/(labs|folders|entries|shared-labs)(?:$|/)#i', $path)) {
        return true;
    }
    // Task queue user actions.
    if (preg_match('#^/api/tasks(?:$|/)#i', $path)) {
        return true;
    }

    return false;
}

function v2AppLogNormalizeMaybeUuid($value): string
{
    $id = strtolower(trim((string) $value));
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $id)) {
        return '';
    }
    return $id;
}

function v2AppLogAttachEntityNamesFromPath(PDO $db, array $context): array
{
    $path = trim((string) ($context['path'] ?? ''));
    $labId = v2AppLogNormalizeMaybeUuid($context['lab_id'] ?? '');
    $nodeId = v2AppLogNormalizeMaybeUuid($context['node_id'] ?? '');
    $taskId = v2AppLogNormalizeMaybeUuid($context['task_id'] ?? $context['active_task_id'] ?? '');

    if ($path !== '') {
        if ($labId === '' && preg_match('#^/api/labs/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
            $labId = v2AppLogNormalizeMaybeUuid((string) ($m[1] ?? ''));
        }
        if ($nodeId === '' && preg_match('#^/api/labs/[a-f0-9-]{36}/nodes/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
            $nodeId = v2AppLogNormalizeMaybeUuid((string) ($m[1] ?? ''));
        }
        if ($labId === '' && preg_match('#^/api/main/entries/lab/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
            $labId = v2AppLogNormalizeMaybeUuid((string) ($m[1] ?? ''));
        }
        if ($taskId === '' && preg_match('#^/api/tasks/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
            $taskId = v2AppLogNormalizeMaybeUuid((string) ($m[1] ?? ''));
        }
    }

    static $labCache = [];
    static $nodeCache = [];
    static $taskCache = [];

    if ($taskId !== '') {
        if (!array_key_exists($taskId, $taskCache)) {
            try {
                $stmt = $db->prepare(
                    "SELECT t.lab_id::text AS lab_id,
                            t.node_id::text AS node_id,
                            COALESCE(l.name, '') AS lab_name,
                            COALESCE(n.name, '') AS node_name
                     FROM lab_tasks t
                     LEFT JOIN labs l ON l.id = t.lab_id
                     LEFT JOIN lab_nodes n ON n.id = t.node_id
                     WHERE t.id = :task_id
                     LIMIT 1"
                );
                $stmt->bindValue(':task_id', $taskId, PDO::PARAM_STR);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $taskCache[$taskId] = is_array($row) ? [
                    'lab_id' => v2AppLogNormalizeMaybeUuid($row['lab_id'] ?? ''),
                    'node_id' => v2AppLogNormalizeMaybeUuid($row['node_id'] ?? ''),
                    'lab_name' => trim((string) ($row['lab_name'] ?? '')),
                    'node_name' => trim((string) ($row['node_name'] ?? '')),
                ] : null;
            } catch (Throwable $e) {
                $taskCache[$taskId] = null;
            }
        }
        $taskMeta = $taskCache[$taskId];
        if (is_array($taskMeta)) {
            if ($labId === '' && !empty($taskMeta['lab_id'])) {
                $labId = (string) $taskMeta['lab_id'];
            }
            if ($nodeId === '' && !empty($taskMeta['node_id'])) {
                $nodeId = (string) $taskMeta['node_id'];
            }
            if (trim((string) ($context['lab_name'] ?? '')) === '' && !empty($taskMeta['lab_name'])) {
                $context['lab_name'] = (string) $taskMeta['lab_name'];
            }
            if (trim((string) ($context['node_name'] ?? '')) === '' && !empty($taskMeta['node_name'])) {
                $context['node_name'] = (string) $taskMeta['node_name'];
            }
        }
        $context['task_id'] = $taskId;
    }

    if ($nodeId !== '') {
        if (!array_key_exists($nodeId, $nodeCache)) {
            try {
                $stmt = $db->prepare(
                    "SELECT n.lab_id::text AS lab_id,
                            COALESCE(n.name, '') AS node_name,
                            COALESCE(l.name, '') AS lab_name
                     FROM lab_nodes n
                     LEFT JOIN labs l ON l.id = n.lab_id
                     WHERE n.id = :node_id
                     LIMIT 1"
                );
                $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $nodeCache[$nodeId] = is_array($row) ? [
                    'lab_id' => v2AppLogNormalizeMaybeUuid($row['lab_id'] ?? ''),
                    'node_name' => trim((string) ($row['node_name'] ?? '')),
                    'lab_name' => trim((string) ($row['lab_name'] ?? '')),
                ] : null;
            } catch (Throwable $e) {
                $nodeCache[$nodeId] = null;
            }
        }
        $nodeMeta = $nodeCache[$nodeId];
        if (is_array($nodeMeta)) {
            if ($labId === '' && !empty($nodeMeta['lab_id'])) {
                $labId = (string) $nodeMeta['lab_id'];
            }
            if (trim((string) ($context['node_name'] ?? '')) === '' && !empty($nodeMeta['node_name'])) {
                $context['node_name'] = (string) $nodeMeta['node_name'];
            }
            if (trim((string) ($context['lab_name'] ?? '')) === '' && !empty($nodeMeta['lab_name'])) {
                $context['lab_name'] = (string) $nodeMeta['lab_name'];
            }
        }
        $context['node_id'] = $nodeId;
    }

    if ($labId !== '') {
        if (!array_key_exists($labId, $labCache)) {
            try {
                $stmt = $db->prepare("SELECT COALESCE(name, '') AS name FROM labs WHERE id = :lab_id LIMIT 1");
                $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
                $stmt->execute();
                $labCache[$labId] = trim((string) ($stmt->fetchColumn() ?: ''));
            } catch (Throwable $e) {
                $labCache[$labId] = '';
            }
        }
        if (trim((string) ($context['lab_name'] ?? '')) === '' && trim((string) ($labCache[$labId] ?? '')) !== '') {
            $context['lab_name'] = trim((string) $labCache[$labId]);
        }
        $context['lab_id'] = $labId;
    }

    return $context;
}

if ($uriPath === '/v2') {
    $uriPath = '/';
} elseif (strpos($uriPath, '/v2/') === 0) {
    $uriPath = substr($uriPath, 3);
}

if ($uriPath === '/api/v2') {
    $uriPath = '/api';
} elseif (strpos($uriPath, '/api/v2/') === 0) {
    $uriPath = '/api/' . ltrim(substr($uriPath, 8), '/');
}

v2ApplySecurityHeaders();
v2EnsureMutationRequestGuards($method, $uriPath);

$v2RequestStartedAt = microtime(true);
v2AppLogRequestInit($method, $uriPath, $v2RequestStartedAt);
register_shutdown_function(static function (): void {
    $ctx = v2AppLogGetRequestContext();
    if (empty($ctx)) {
        return;
    }

    $method = (string) ($ctx['method'] ?? '');
    $path = (string) ($ctx['path'] ?? '');
    $startedAt = (float) ($ctx['started_at'] ?? microtime(true));
    $statusCode = (int) http_response_code();
    if ($statusCode < 100) {
        $statusCode = 200;
    }
    $durationMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));
    // Keep HTTP access log compact: store only errors.
    if ($statusCode >= 400) {
        $accessContext = v2AppLogAttachUser([
            'event' => 'request',
            'method' => $method,
            'path' => $path,
            'code' => $statusCode,
            'duration_ms' => $durationMs,
        ]);
        v2AppLogWrite('access_http', $statusCode >= 400 ? 'ERROR' : 'OK', $accessContext);
    }

    if ($statusCode === 401 || $statusCode === 403) {
        $securityContext = v2AppLogAttachUser([
            'event' => 'access_denied',
            'method' => $method,
            'path' => $path,
            'code' => $statusCode,
            'duration_ms' => $durationMs,
        ]);
        v2AppLogWrite('security', 'ERROR', $securityContext);
    }

    $isApiMutation = (strpos($path, '/api/') === 0 && $method !== 'GET' && $path !== '/api/auth/ping');
    $isImportantUserAction = v2AppLogIsImportantUserActionPath($method, $path);
    if ($isApiMutation && !v2AppLogActionAlreadyLogged() && ($statusCode >= 400 || $isImportantUserAction)) {
        $activityContext = v2AppLogAttachUser([
            'event' => 'api_action',
            'method' => $method,
            'path' => $path,
            'code' => $statusCode,
            'duration_ms' => $durationMs,
        ]);
        global $db;
        if ($db instanceof PDO) {
            $activityContext = v2AppLogAttachEntityNamesFromPath($db, $activityContext);
        }
        v2AppLogWrite('user_activity', $statusCode >= 400 ? 'ERROR' : 'OK', $activityContext);
    }

    $fatal = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (is_array($fatal) && in_array((int) ($fatal['type'] ?? 0), $fatalTypes, true)) {
        $errorContext = v2AppLogAttachUser([
            'event' => 'fatal',
            'method' => $method,
            'path' => $path,
            'code' => $statusCode,
            'file' => (string) ($fatal['file'] ?? ''),
            'line' => (int) ($fatal['line'] ?? 0),
            'message' => (string) ($fatal['message'] ?? ''),
        ]);
        v2AppLogWrite('system_errors', 'ERROR', $errorContext);
        return;
    }

    if ($statusCode >= 500) {
        $errorContext = v2AppLogAttachUser([
            'event' => 'request_failed',
            'method' => $method,
            'path' => $path,
            'code' => $statusCode,
            'duration_ms' => $durationMs,
        ]);
        v2AppLogWrite('system_errors', 'ERROR', $errorContext);
    }
});

try {
    $db = db();
} catch (Throwable $e) {
    v2AppLogWrite('system_errors', 'ERROR', [
        'event' => 'database_connection_failed',
        'method' => $method,
        'path' => $uriPath,
        'message' => $e->getMessage(),
    ]);
    jsonResponse(500, 'error', 'Database connection failed');
    exit;
}

function attachUserPermissions(PDO $db, array $user): array
{
    $user['permissions'] = rbacUserPermissionCodes($db, $user);
    return $user;
}

function currentUserOrNull(PDO $db): ?array
{
    $token = (string) ($_COOKIE['eve_ng_v2_session'] ?? '');
    if ($token === '') {
        return null;
    }
    $user = getUserFromSession($db, $token);
    if ($user === null) {
        return null;
    }
    $attached = attachUserPermissions($db, $user);
    v2AppLogSetRequestUser($attached);
    return $attached;
}

function requireAuth(PDO $db): array
{
    $user = currentUserOrNull($db);
    if ($user === null) {
        clearSessionCookie();
        jsonResponse(401, 'fail', 'Unauthorized');
        exit;
    }
    return $user;
}

function requirePermission(PDO $db, string $permissionCode): array
{
    $user = requireAuth($db);
    if (!rbacUserHasPermission($db, $user, $permissionCode)) {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    return $user;
}

function requireAnyPermission(PDO $db, array $permissionCodes): array
{
    $user = requireAuth($db);
    if (!rbacUserHasAnyPermission($db, $user, $permissionCodes)) {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    return $user;
}

function requireAllPermissions(PDO $db, array $permissionCodes): array
{
    $user = requireAuth($db);
    if (!rbacUserHasAllPermissions($db, $user, $permissionCodes)) {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    return $user;
}

function userMgmtCanManageAdminUsers(PDO $db, array $actor): bool
{
    return rbacUserHasPermission($db, $actor, 'users.manage');
}

function userMgmtCanManageNonAdminUsers(PDO $db, array $actor): bool
{
    return rbacUserHasPermission($db, $actor, 'users.manage.non_admin');
}

function userRoleNameFromRow(array $row): string
{
    return strtolower(trim((string) ($row['role'] ?? $row['role_name'] ?? '')));
}

function renderHtmlPage(string $filename, ?array $user = null): void
{
    $context = v2AppLogAttachUser([
        'event' => 'page_open',
        'path' => (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
        'page' => $filename,
    ], $user);
    v2AppLogWrite('user_activity', 'OK', $context);

    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/' . $filename);
    exit;
}

function renderProtectedPage(PDO $db, string $filename, string $permissionCode): void
{
    $user = currentUserOrNull($db);
    if ($user === null) {
        v2AppLogWrite('security', 'ERROR', [
            'event' => 'page_open_denied',
            'path' => (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
            'required_permission' => $permissionCode,
            'reason' => 'unauthorized',
        ]);
        clearSessionCookie();
        header('Location: /login', true, 302);
        exit;
    }
    if (!rbacUserHasPermission($db, $user, $permissionCode)) {
        $context = v2AppLogAttachUser([
            'event' => 'page_open_denied',
            'path' => (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
            'required_permission' => $permissionCode,
            'reason' => 'missing_permission',
        ], $user);
        v2AppLogWrite('security', 'ERROR', $context);
        header('Location: /main', true, 302);
        exit;
    }
    renderHtmlPage($filename, $user);
}

function normalizeLang($value): string
{
    $lang = strtolower(trim((string) $value));
    return in_array($lang, ['en', 'ru'], true) ? $lang : 'en';
}

function normalizeTheme($value): string
{
    $theme = strtolower(trim((string) $value));
    return in_array($theme, ['dark', 'light'], true) ? $theme : 'dark';
}

function normalizePnet($value): string
{
    $pnet = strtolower(trim((string) $value));
    return preg_match('/^pnet[0-9]+$/', $pnet) ? $pnet : '';
}

function normalizeUuid($value): string
{
    $uuid = strtolower(trim((string) $value));
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid) ? $uuid : '';
}

function isUniqueViolation(PDOException $e): bool
{
    return trim((string) $e->getCode()) === '23505';
}

if ($method === 'GET' && ($uriPath === '/' || $uriPath === '')) {
    header('Location: /login', true, 302);
    exit;
}

if ($method === 'GET' && ($uriPath === '/login' || $uriPath === '/login/')) {
    renderHtmlPage('login.html');
}

if ($method === 'GET' && ($uriPath === '/usermgmt' || $uriPath === '/usermgmt/')) {
    renderProtectedPage($db, 'usermgmt.html', 'page.management.usermgmt.view');
}

if ($method === 'GET' && ($uriPath === '/cloudmgmt' || $uriPath === '/cloudmgmt/')) {
    renderProtectedPage($db, 'cloudmgmt.html', 'page.management.cloudmgmt.view');
}

if ($method === 'GET' && ($uriPath === '/main' || $uriPath === '/main/')) {
    $user = currentUserOrNull($db);
    if ($user === null) {
        clearSessionCookie();
        header('Location: /login', true, 302);
        exit;
    }
    renderHtmlPage('main.html', $user);
}

if ($method === 'GET' && ($uriPath === '/labmgmt' || $uriPath === '/labmgmt/')) {
    renderProtectedPage($db, 'labmgmt.html', 'page.management.labmgmt.view');
}

if ($method === 'GET' && ($uriPath === '/roles' || $uriPath === '/roles/')) {
    renderProtectedPage($db, 'roles.html', 'page.management.roles.view');
}

if ($method === 'GET' && ($uriPath === '/system-status' || $uriPath === '/system-status/')) {
    renderProtectedPage($db, 'system-status.html', 'page.system.status.view');
}

if ($method === 'GET' && ($uriPath === '/system-logs' || $uriPath === '/system-logs/')) {
    renderProtectedPage($db, 'system-logs.html', 'page.system.logs.view');
}

if ($method === 'GET' && ($uriPath === '/system-audit' || $uriPath === '/system-audit/')) {
    renderProtectedPage($db, 'system-audit.html', 'page.system.audit.view');
}

if ($method === 'GET' && ($uriPath === '/taskqueue' || $uriPath === '/taskqueue/')) {
    renderProtectedPage($db, 'taskqueue.html', 'page.system.taskqueue.view');
}

if ($method === 'GET' && ($uriPath === '/system-console' || $uriPath === '/system-console/')) {
    renderProtectedPage($db, 'system-console.html', 'page.system.vm_console.view');
}

if ($method === 'GET' && ($uriPath === '/capture' || $uriPath === '/capture/')) {
    $user = currentUserOrNull($db);
    if ($user === null) {
        clearSessionCookie();
        header('Location: /login', true, 302);
        exit;
    }
    renderHtmlPage('capture.html', $user);
}

if ($method === 'GET' && ($uriPath === '/console' || $uriPath === '/console/')) {
    $user = currentUserOrNull($db);
    if ($user === null) {
        clearSessionCookie();
        header('Location: /login', true, 302);
        exit;
    }
    renderHtmlPage('console.html', $user);
}

if ($method === 'GET' && preg_match('#^/lab/([a-f0-9-]{36})$#i', $uriPath)) {
    $user = currentUserOrNull($db);
    if ($user === null) {
        clearSessionCookie();
        header('Location: /login', true, 302);
        exit;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: text/html; charset=utf-8');
    $context = v2AppLogAttachUser([
        'event' => 'page_open',
        'path' => (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
        'page' => 'lab.html',
    ], $user);
    v2AppLogWrite('user_activity', 'OK', $context);
    readfile(__DIR__ . '/lab.html');
    exit;
}

if ($method === 'POST' && $uriPath === '/api/auth/login') {
    $body = readJsonBody();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $ip = clientIp();

    $throttleState = authLoginThrottleCheck($db, $username, $ip);
    if (!empty($throttleState['blocked'])) {
        $retryAfter = (int) ($throttleState['retry_after'] ?? 0);
        v2AppLogWrite('security', 'ERROR', [
            'event' => 'auth_login_throttled',
            'username' => $username,
            'ip' => $ip,
            'retry_after' => $retryAfter,
        ]);
        v2AppLogMarkActionLogged();
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
        }
        jsonResponse(429, 'fail', 'Too many login attempts', ['retry_after_seconds' => $retryAfter]);
        exit;
    }

    if ($username === '' || $password === '') {
        authLoginThrottleRegisterFailure($db, $username, $ip);
        v2AppLogWrite('user_activity', 'ERROR', [
            'event' => 'auth_login',
            'username' => $username,
            'reason' => 'missing_credentials',
        ]);
        v2AppLogMarkActionLogged();
        jsonResponse(400, 'fail', 'username and password are required');
        exit;
    }

    $user = findUserForLogin($db, $username);
    if ($user === null) {
        authLoginThrottleRegisterFailure($db, $username, $ip);
        v2AppLogWrite('user_activity', 'ERROR', [
            'event' => 'auth_login',
            'username' => $username,
            'reason' => 'invalid_credentials',
        ]);
        v2AppLogMarkActionLogged();
        jsonResponse(401, 'fail', 'Invalid credentials');
        exit;
    }

    if ((bool) $user['is_blocked']) {
        authLoginThrottleRegisterFailure($db, $username, $ip);
        v2AppLogWrite('user_activity', 'ERROR', [
            'event' => 'auth_login',
            'username' => $username,
            'reason' => 'account_blocked',
        ]);
        v2AppLogMarkActionLogged();
        jsonResponse(403, 'fail', 'Account is blocked');
        exit;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        authLoginThrottleRegisterFailure($db, $username, $ip);
        v2AppLogWrite('user_activity', 'ERROR', [
            'event' => 'auth_login',
            'username' => $username,
            'reason' => 'invalid_credentials',
        ]);
        v2AppLogMarkActionLogged();
        jsonResponse(401, 'fail', 'Invalid credentials');
        exit;
    }

    authLoginThrottleReset($db, $username, $ip);

    try {
        maybeUpgradeUserPasswordHash($db, (string) ($user['id'] ?? ''), $password, (string) ($user['password_hash'] ?? ''));
    } catch (Throwable $e) {
        v2AppLogWrite('system_errors', 'ERROR', [
            'event' => 'auth_password_rehash_failed',
            'username' => (string) ($user['username'] ?? $username),
            'message' => $e->getMessage(),
        ]);
    }

    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $token = createSession($db, (string) $user['id'], $ip, $userAgent);
    setSessionCookie($token);
    touchLastSeen($db, (string) $user['id'], $ip);
    v2AppLogSetRequestUser(attachUserPermissions($db, $user));
    $permissions = rbacUserPermissionCodes($db, $user);
    $activityContext = v2AppLogAttachUser([
        'event' => 'auth_login',
        'username' => (string) $user['username'],
        'result' => 'success',
    ], $user);
    v2AppLogWrite('user_activity', 'OK', $activityContext);
    v2AppLogMarkActionLogged();

    jsonResponse(200, 'success', 'Logged in', [
        'id' => (string) $user['id'],
        'username' => (string) $user['username'],
        'role_id' => (string) $user['role_id'],
        'role' => (string) $user['role_name'],
        'permissions' => $permissions,
        'lang' => normalizeLang($user['lang'] ?? 'en'),
        'theme' => normalizeTheme($user['theme'] ?? 'dark'),
        'last_ip' => $ip,
        'is_blocked' => false,
    ]);
    exit;
}

if ($method === 'GET' && $uriPath === '/api/auth') {
    $user = requireAuth($db);
    jsonResponse(200, 'success', 'Authorized', [
        'id' => (string) $user['id'],
        'username' => (string) $user['username'],
        'role_id' => (string) $user['role_id'],
        'role' => (string) $user['role_name'],
        'permissions' => array_values((array) ($user['permissions'] ?? [])),
        'lang' => normalizeLang($user['lang'] ?? 'en'),
        'theme' => normalizeTheme($user['theme'] ?? 'dark'),
        'last_seen' => $user['last_seen'],
        'last_ip' => $user['last_ip'],
        'is_blocked' => (bool) $user['is_blocked'],
    ]);
    exit;
}

if ($method === 'POST' && $uriPath === '/api/auth/ping') {
    $user = requireAuth($db);
    $token = (string) ($_COOKIE['eve_ng_v2_session'] ?? '');
    if ($token === '') {
        clearSessionCookie();
        jsonResponse(401, 'fail', 'Unauthorized');
        exit;
    }
    $ip = clientIp();
    if (!touchSessionActivity($db, $token, $ip)) {
        clearSessionCookie();
        jsonResponse(401, 'fail', 'Session expired');
        exit;
    }
    touchLastSeen($db, (string) $user['id'], $ip);
    jsonResponse(200, 'success', 'Session activity updated');
    exit;
}

if (($method === 'POST' || $method === 'DELETE') && $uriPath === '/api/auth/logout') {
    $token = (string) ($_COOKIE['eve_ng_v2_session'] ?? '');
    $actor = currentUserOrNull($db);
    if ($token !== '') {
        deleteSession($db, $token);
    }
    $activityContext = v2AppLogAttachUser([
        'event' => 'auth_logout',
        'result' => 'success',
    ], $actor);
    v2AppLogWrite('user_activity', 'OK', $activityContext);
    v2AppLogMarkActionLogged();
    clearSessionCookie();
    jsonResponse(200, 'success', 'Logged out');
    exit;
}

if ($method === 'GET' && $uriPath === '/api/auth/logout') {
    jsonResponse(405, 'fail', 'Use POST for logout');
    exit;
}

if ($method === 'GET' && $uriPath === '/api/preferences') {
    $user = requireAuth($db);
    jsonResponse(200, 'success', 'Preferences loaded', [
        'lang' => normalizeLang($user['lang'] ?? 'en'),
        'theme' => normalizeTheme($user['theme'] ?? 'dark')
    ]);
    exit;
}

if ($method === 'GET' && $uriPath === '/api/main/stats') {
    $user = requireAuth($db);
    jsonResponse(200, 'success', 'Main stats loaded', getMainNodeStats($db, (string) $user['id']));
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/editor$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $previewMode = !empty($_GET['preview']) && $_GET['preview'] !== '0';
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $data = getLabEditorData($db, $user, $labId, $previewMode);
        jsonResponse(200, 'success', 'Lab editor loaded', $data);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid request');
    } catch (RuntimeException $e) {
        jsonResponse(404, 'fail', 'Lab not found');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/collab/session$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $session = v2CollabOpenSession($db, $user, $labId);
        jsonResponse(200, 'success', 'Collaboration session ready', $session);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(500, 'error', 'Failed to prepare collaboration session');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to prepare collaboration session');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/link-layout$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    if (!viewerCanViewLab($db, $user, $labId)) {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    try {
        $layout = loadLabLinkLayoutState($db, $labId);
        jsonResponse(200, 'success', 'Link layout loaded', $layout);
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load link layout');
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/labs/([a-f0-9-]{36})/link-layout$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $body = readJsonBody();
    try {
        $layout = saveLabLinkLayoutState($db, $user, $labId, $body);
        jsonResponse(200, 'success', 'Link layout saved', $layout);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(500, 'error', 'Failed to save link layout');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to save link layout');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/ports$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $ports = listLabPortsForViewer($db, $user, $labId);
        jsonResponse(200, 'success', 'Lab ports loaded', $ports);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load lab ports');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/cloud-options$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $options = listLabCloudOptions($db, $user, $labId);
        jsonResponse(200, 'success', 'Cloud options loaded', $options);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load cloud options');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/clouds$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $body = readJsonBody();
    try {
        $cloud = createLabCloudNetwork($db, $user, $labId, $body);
        jsonResponse(201, 'success', 'Cloud created', $cloud);
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
        if ($msg === 'cloud_id_invalid') {
            jsonResponse(400, 'fail', 'cloud_id is invalid');
        } elseif ($msg === 'pnet_invalid') {
            jsonResponse(400, 'fail', 'pnet is invalid');
        } else {
            jsonResponse(400, 'fail', 'Invalid cloud payload');
        }
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to create cloud');
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/labs/([a-f0-9-]{36})/clouds/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $networkId = normalizeUuid($m[2]);
    if ($labId === '' || $networkId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or cloud id');
        exit;
    }
    $body = readJsonBody();
    try {
        $cloud = updateLabCloudNetwork($db, $user, $labId, $networkId, $body);
        jsonResponse(200, 'success', 'Cloud updated', $cloud);
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
        if ($msg === 'cloud_id_invalid') {
            jsonResponse(400, 'fail', 'cloud_id is invalid');
        } elseif ($msg === 'pnet_invalid') {
            jsonResponse(400, 'fail', 'pnet is invalid');
        } else {
            jsonResponse(400, 'fail', 'Invalid cloud payload');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Cloud not found') {
            jsonResponse(404, 'fail', 'Cloud not found');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to update cloud');
    }
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/labs/([a-f0-9-]{36})/clouds/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $networkId = normalizeUuid($m[2]);
    if ($labId === '' || $networkId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or cloud id');
        exit;
    }
    try {
        $deleted = deleteLabCloudNetwork($db, $user, $labId, $networkId);
        jsonResponse(200, 'success', 'Cloud deleted', $deleted);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Cloud not found') {
            jsonResponse(404, 'fail', 'Cloud not found');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to delete cloud');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/node-templates') {
    requireAuth($db);
    jsonResponse(200, 'success', 'Node templates loaded', listNodeTemplatesV2());
    exit;
}

if ($method === 'GET' && preg_match('#^/api/node-templates/([a-z0-9._-]+)$#i', $uriPath, $m)) {
    requireAuth($db);
    $template = trim((string) $m[1]);
    try {
        $data = getNodeTemplateOptionsV2($template);
        jsonResponse(200, 'success', 'Node template loaded', $data);
    } catch (RuntimeException $e) {
        jsonResponse(404, 'fail', 'Template not found');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/node-icons') {
    requireAuth($db);
    jsonResponse(200, 'success', 'Node icons loaded', listNodeIconsV2());
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/nodes$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $body = readJsonBody();
    try {
        $node = createLabNode($db, $user, $labId, $body);
        $createdNodes = (isset($node['nodes']) && is_array($node['nodes'])) ? array_values($node['nodes']) : [];
        $firstCreated = (isset($createdNodes[0]) && is_array($createdNodes[0])) ? $createdNodes[0] : [];
        $createdCount = isset($node['count']) ? (int) $node['count'] : count($createdNodes);
        $activityContext = v2AppLogAttachUser([
            'event' => 'node_create',
            'method' => $method,
            'path' => $uriPath,
            'code' => 201,
            'lab_id' => $labId,
            'node_id' => (string) ($firstCreated['id'] ?? ($node['id'] ?? '')),
            'node_name' => (string) ($firstCreated['name'] ?? ($node['name'] ?? '')),
            'node_template' => (string) ($firstCreated['template'] ?? ($node['template'] ?? '')),
            'node_count' => $createdCount > 0 ? $createdCount : 1,
        ], $user);
        if ($db instanceof PDO) {
            $activityContext = v2AppLogAttachEntityNamesFromPath($db, $activityContext);
        }
        v2AppLogWrite('user_activity', 'OK', $activityContext);
        v2AppLogMarkActionLogged();
        jsonResponse(201, 'success', 'Node created', $node);
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
        if ($msg === 'template_required') {
            jsonResponse(400, 'fail', 'Node template is required');
        } elseif ($msg === 'template_invalid') {
            jsonResponse(400, 'fail', 'Node template is invalid');
        } elseif ($msg === 'name_required') {
            jsonResponse(400, 'fail', 'Node name is required');
        } elseif ($msg === 'name_invalid') {
            jsonResponse(400, 'fail', 'Node name is invalid');
        } elseif ($msg === 'node_type_invalid') {
            jsonResponse(400, 'fail', 'Node type is invalid');
        } elseif ($msg === 'image_invalid_for_template') {
            jsonResponse(400, 'fail', 'Node image does not match selected template');
        } elseif ($msg === 'ethernet_count_invalid') {
            jsonResponse(400, 'fail', 'Ethernet count is invalid');
        } elseif ($msg === 'serial_count_invalid') {
            jsonResponse(400, 'fail', 'Serial count is invalid');
        } elseif ($msg === 'count_limit_per_request') {
            jsonResponse(400, 'fail', 'Cannot create more than 10 nodes at once');
        } elseif ($msg === 'lab_nodes_limit_exceeded') {
            jsonResponse(409, 'fail', 'Lab node limit is 100');
        } else {
            jsonResponse(400, 'fail', 'Invalid request');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(500, 'error', 'Failed to create node');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to create node');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/nodes/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $nodeId = normalizeUuid($m[2]);
    if ($labId === '' || $nodeId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or node id');
        exit;
    }
    try {
        $node = getLabNodeEditorData($db, $user, $labId, $nodeId);
        jsonResponse(200, 'success', 'Node loaded', $node);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Wipe is disabled by topology policy') {
            jsonResponse(403, 'fail', 'Wipe is disabled by topology policy');
        } elseif ($msg === 'Node not found') {
            jsonResponse(404, 'fail', 'Node not found');
        } else {
            jsonResponse(500, 'error', 'Failed to load node');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load node');
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/labs/([a-f0-9-]{36})/nodes/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $nodeId = normalizeUuid($m[2]);
    if ($labId === '' || $nodeId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or node id');
        exit;
    }
    $body = readJsonBody();
    try {
        $node = updateLabNode($db, $user, $labId, $nodeId, $body);
        jsonResponse(200, 'success', 'Node updated', $node);
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
        if ($msg === 'name_required') {
            jsonResponse(400, 'fail', 'Node name is required');
        } elseif ($msg === 'name_invalid') {
            jsonResponse(400, 'fail', 'Node name is invalid');
        } elseif ($msg === 'icon_required') {
            jsonResponse(400, 'fail', 'Node icon is required');
        } elseif ($msg === 'icon_invalid') {
            jsonResponse(400, 'fail', 'Node icon is invalid');
        } elseif ($msg === 'image_invalid_for_template') {
            jsonResponse(400, 'fail', 'Node image does not match selected template');
        } elseif ($msg === 'ethernet_count_invalid') {
            jsonResponse(400, 'fail', 'Ethernet count is invalid');
        } elseif ($msg === 'serial_count_invalid') {
            jsonResponse(400, 'fail', 'Serial count is invalid');
        } else {
            jsonResponse(400, 'fail', 'Invalid request');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Node not found') {
            jsonResponse(404, 'fail', 'Node not found');
        } elseif ($msg === 'ports_in_use') {
            jsonResponse(409, 'fail', 'Cannot reduce ports because some are connected');
        } else {
            jsonResponse(500, 'error', 'Failed to update node');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to update node');
    }
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/labs/([a-f0-9-]{36})/nodes/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $nodeId = normalizeUuid($m[2]);
    if ($labId === '' || $nodeId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or node id');
        exit;
    }
    try {
        $deleted = deleteLabNode($db, $user, $labId, $nodeId);
        $activityContext = v2AppLogAttachUser([
            'event' => 'node_delete',
            'method' => $method,
            'path' => $uriPath,
            'code' => 200,
            'lab_id' => $labId,
            'node_id' => (string) ($deleted['id'] ?? $nodeId),
            'node_name' => (string) ($deleted['name'] ?? ''),
            'deleted_bridge_links' => (int) ($deleted['deleted_bridge_links'] ?? 0),
        ], $user);
        v2AppLogWrite('user_activity', 'OK', $activityContext);
        v2AppLogMarkActionLogged();
        jsonResponse(200, 'success', 'Node deleted', $deleted);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Node not found') {
            jsonResponse(404, 'fail', 'Node not found');
        } else {
            jsonResponse(500, 'error', 'Failed to delete node');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to delete node');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/nodes/([a-f0-9-]{36})/wipe$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $nodeId = normalizeUuid($m[2]);
    if ($labId === '' || $nodeId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or node id');
        exit;
    }
    try {
        $result = wipeLabNode($db, $user, $labId, $nodeId);
        jsonResponse(200, 'success', 'Node wiped', $result);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Node not found') {
            jsonResponse(404, 'fail', 'Node not found');
        } else {
            jsonResponse(500, 'error', 'Failed to wipe node');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to wipe node');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/links$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $body = readJsonBody();
    $sourceNodeId = normalizeUuid($body['source_node_id'] ?? '');
    $targetNodeId = normalizeUuid($body['target_node_id'] ?? '');
    $sourcePortId = array_key_exists('source_port_id', $body) ? normalizeUuid($body['source_port_id']) : '';
    $targetPortId = array_key_exists('target_port_id', $body) ? normalizeUuid($body['target_port_id']) : '';
    try {
        $link = createLabLink($db, $user, $labId, [
            'source_node_id' => $sourceNodeId,
            'target_node_id' => $targetNodeId,
            'source_port_id' => $sourcePortId,
            'target_port_id' => $targetPortId,
        ]);
        jsonResponse(201, 'success', 'Link created', $link);
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
        if ($msg === 'same_node') {
            jsonResponse(400, 'fail', 'Cannot connect node to itself');
        } elseif ($msg === 'same_port') {
            jsonResponse(400, 'fail', 'Cannot connect same port');
        } else {
            jsonResponse(400, 'fail', 'Invalid link request');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'no_free_ports') {
            jsonResponse(409, 'fail', 'No free ethernet ports');
        } elseif ($msg === 'port_in_use') {
            jsonResponse(409, 'fail', 'Selected port is already connected');
        } else {
            jsonResponse(404, 'fail', 'Node or lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to create link');
    }
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/labs/([a-f0-9-]{36})/links/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $networkId = normalizeUuid($m[2]);
    if ($labId === '' || $networkId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or link id');
        exit;
    }
    try {
        $deleted = deleteLabLink($db, $user, $labId, $networkId);
        jsonResponse(200, 'success', 'Link deleted', $deleted);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'link_delete_forbidden') {
            jsonResponse(409, 'fail', 'Only internal bridge links can be deleted');
        } else {
            jsonResponse(404, 'fail', 'Link not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to delete link');
    }
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/labs/([a-f0-9-]{36})/links/attachments/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $attachmentId = normalizeUuid($m[2]);
    if ($labId === '' || $attachmentId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or attachment id');
        exit;
    }
    try {
        $detached = detachLabAttachment($db, $user, $labId, $attachmentId);
        jsonResponse(200, 'success', 'Attachment detached', $detached);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'attachment_detach_forbidden') {
            jsonResponse(409, 'fail', 'Only cloud attachments can be detached individually');
        } elseif ($msg === 'Attachment not found') {
            jsonResponse(404, 'fail', 'Attachment not found');
        } elseif (strpos($msg, 'cloud_detach_hot_apply_failed:') === 0) {
            $detail = trim(substr($msg, strlen('cloud_detach_hot_apply_failed:')));
            if ($detail === '') {
                $detail = 'unknown error';
            }
            jsonResponse(409, 'fail', 'Не удалось отключить cloud на запущенной ноде: ' . $detail);
        } elseif (strpos($msg, 'cloud_detach_rollback_failed:') === 0) {
            jsonResponse(500, 'error', 'Failed to rollback cloud detach state');
        } else {
            jsonResponse(500, 'error', 'Failed to detach attachment');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to detach attachment');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/links/node-network$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $body = readJsonBody();
    $sourceNodeId = normalizeUuid($body['source_node_id'] ?? '');
    $networkId = normalizeUuid($body['network_id'] ?? '');
    $sourcePortId = array_key_exists('source_port_id', $body) ? normalizeUuid($body['source_port_id']) : '';
    try {
        $link = createLabNodeNetworkLink($db, $user, $labId, [
            'source_node_id' => $sourceNodeId,
            'network_id' => $networkId,
            'source_port_id' => $sourcePortId,
        ]);
        jsonResponse(201, 'success', 'Node connected to cloud', $link);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid link request');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'no_free_ports') {
            jsonResponse(409, 'fail', 'No free ethernet ports');
        } elseif ($msg === 'port_in_use') {
            jsonResponse(409, 'fail', 'Selected port is already connected');
        } elseif ($msg === 'network_type_invalid') {
            jsonResponse(409, 'fail', 'Only cloud networks can be attached here');
        } elseif (strpos($msg, 'cloud_attach_hot_apply_failed:') === 0) {
            $detail = trim(substr($msg, strlen('cloud_attach_hot_apply_failed:')));
            if ($detail === '') {
                $detail = 'unknown error';
            }
            jsonResponse(409, 'fail', 'Не удалось применить cloud на запущенной ноде: ' . $detail);
        } elseif (strpos($msg, 'cloud_attach_rollback_failed:') === 0) {
            jsonResponse(500, 'error', 'Failed to rollback cloud attach state');
        } else {
            jsonResponse(404, 'fail', 'Node or cloud not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to connect node to cloud');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/attachments/([a-f0-9-]{36})/capture/meta$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $attachmentId = normalizeUuid($m[2]);
    if ($labId === '' || $attachmentId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or attachment id');
        exit;
    }
    try {
        $meta = v2LabCaptureResolveAttachmentInterface($db, $user, $labId, $attachmentId);
        jsonResponse(200, 'success', 'Capture metadata loaded', $meta);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Attachment not found') {
            jsonResponse(404, 'fail', 'Attachment not found');
        } else {
            jsonResponse(409, 'fail', $msg);
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to resolve capture metadata');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/attachments/([a-f0-9-]{36})/capture/stream$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $attachmentId = normalizeUuid($m[2]);
    if ($labId === '' || $attachmentId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or attachment id');
        exit;
    }
    try {
        $meta = v2LabCaptureResolveAttachmentInterface($db, $user, $labId, $attachmentId);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Attachment not found') {
            jsonResponse(404, 'fail', 'Attachment not found');
        } else {
            jsonResponse(409, 'fail', $msg);
        }
        exit;
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to resolve capture metadata');
        exit;
    }

    $iface = v2LabCaptureNormalizeIface((string) ($meta['host_interface'] ?? ''));
    if ($iface === '') {
        jsonResponse(409, 'fail', 'Capture interface is invalid');
        exit;
    }
    if (!runtimeLinuxInterfaceExists($iface)) {
        jsonResponse(409, 'fail', 'Capture interface is unavailable');
        exit;
    }

    $script = dirname(__DIR__) . '/bin/host_stream_capture.php';
    if (!is_file($script)) {
        jsonResponse(500, 'error', 'Capture helper is unavailable');
        exit;
    }

    $hostFilter = trim((string) ($_GET['host'] ?? ''));
    if ($hostFilter !== '' && filter_var($hostFilter, FILTER_VALIDATE_IP) === false) {
        jsonResponse(400, 'fail', 'Invalid host filter');
        exit;
    }
    $protoFilter = strtolower(trim((string) ($_GET['proto'] ?? '')));
    $allowedProto = ['all', 'arp', 'ip', 'ip6', 'icmp', 'icmp6', 'tcp', 'udp', 'stp', 'lldp', 'cdp'];
    if ($protoFilter !== '' && !in_array($protoFilter, $allowedProto, true)) {
        jsonResponse(400, 'fail', 'Invalid protocol filter');
        exit;
    }
    $quietMode = !isset($_GET['quiet']) || (string) $_GET['quiet'] !== '0';

    @set_time_limit(0);
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Accel-Buffering: no');

    $phpBin = '/usr/bin/php';
    if (!is_executable($phpBin)) {
        $phpBin = PHP_BINARY;
    }
    $cmd = 'sudo -n '
        . escapeshellarg($phpBin)
        . ' '
        . escapeshellarg($script)
        . ' --if='
        . escapeshellarg($iface);
    if ($hostFilter !== '') {
        $cmd .= ' --host=' . escapeshellarg($hostFilter);
    }
    if ($protoFilter !== '') {
        $cmd .= ' --proto=' . escapeshellarg($protoFilter);
    }
    if ($quietMode) {
        $cmd .= ' --quiet=1';
    } else {
        $cmd .= ' --quiet=0';
    }
    @passthru($cmd);
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/attachments/([a-f0-9-]{36})/capture/packets$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $attachmentId = normalizeUuid($m[2]);
    if ($labId === '' || $attachmentId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or attachment id');
        exit;
    }
    try {
        $meta = v2LabCaptureResolveAttachmentInterface($db, $user, $labId, $attachmentId);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Attachment not found') {
            jsonResponse(404, 'fail', 'Attachment not found');
        } else {
            jsonResponse(409, 'fail', $msg);
        }
        exit;
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to resolve capture metadata');
        exit;
    }

    $iface = v2LabCaptureNormalizeIface((string) ($meta['host_interface'] ?? ''));
    if ($iface === '') {
        jsonResponse(409, 'fail', 'Capture interface is invalid');
        exit;
    }
    if (!runtimeLinuxInterfaceExists($iface)) {
        jsonResponse(409, 'fail', 'Capture interface is unavailable');
        exit;
    }

    $script = dirname(__DIR__) . '/bin/host_stream_capture_packets.php';
    if (!is_file($script)) {
        jsonResponse(500, 'error', 'Capture packets helper is unavailable');
        exit;
    }

    $hostFilter = trim((string) ($_GET['host'] ?? ''));
    if ($hostFilter !== '' && filter_var($hostFilter, FILTER_VALIDATE_IP) === false) {
        jsonResponse(400, 'fail', 'Invalid host filter');
        exit;
    }
    $protoFilter = strtolower(trim((string) ($_GET['proto'] ?? '')));
    $allowedProto = ['all', 'arp', 'ip', 'ip6', 'icmp', 'icmp6', 'tcp', 'udp', 'stp', 'lldp', 'cdp'];
    if ($protoFilter !== '' && !in_array($protoFilter, $allowedProto, true)) {
        jsonResponse(400, 'fail', 'Invalid protocol filter');
        exit;
    }
    $limit = (int) ($_GET['limit'] ?? 0);
    if ($limit < 0) {
        $limit = 0;
    }
    if ($limit > 500000) {
        $limit = 500000;
    }

    @set_time_limit(0);
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    if (function_exists('ob_implicit_flush')) {
        @ob_implicit_flush(true);
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    // Kick first bytes immediately so fetch() does not hang at "connecting".
    echo str_repeat(' ', 4096) . PHP_EOL;
    @ob_flush();
    @flush();

    $phpBin = '/usr/bin/php';
    if (!is_executable($phpBin)) {
        $phpBin = PHP_BINARY;
    }
    $cmd = 'sudo -n '
        . escapeshellarg($phpBin)
        . ' '
        . escapeshellarg($script)
        . ' --if='
        . escapeshellarg($iface);
    if ($hostFilter !== '') {
        $cmd .= ' --host=' . escapeshellarg($hostFilter);
    }
    if ($protoFilter !== '') {
        $cmd .= ' --proto=' . escapeshellarg($protoFilter);
    }
    if ($limit > 0) {
        $cmd .= ' --limit=' . (string) $limit;
    }
    @passthru($cmd);
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/objects$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $body = readJsonBody();
    try {
        $object = createLabObject($db, $user, $labId, $body);
        jsonResponse(201, 'success', 'Object created', $object);
    } catch (InvalidArgumentException $e) {
        if ($e->getMessage() === 'object_type_invalid') {
            jsonResponse(400, 'fail', 'object_type is invalid');
        } else {
            jsonResponse(400, 'fail', 'Invalid object payload');
        }
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to create object');
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/labs/([a-f0-9-]{36})/objects/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $objectId = normalizeUuid($m[2]);
    if ($labId === '' || $objectId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or object id');
        exit;
    }
    $body = readJsonBody();
    try {
        $object = updateLabObject($db, $user, $labId, $objectId, $body);
        jsonResponse(200, 'success', 'Object updated', $object);
    } catch (InvalidArgumentException $e) {
        if ($e->getMessage() === 'object_type_invalid') {
            jsonResponse(400, 'fail', 'object_type is invalid');
        } else {
            jsonResponse(400, 'fail', 'Invalid object payload');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Object not found') {
            jsonResponse(404, 'fail', 'Object not found');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to update object');
    }
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/labs/([a-f0-9-]{36})/objects/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $objectId = normalizeUuid($m[2]);
    if ($labId === '' || $objectId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or object id');
        exit;
    }
    try {
        $deleted = deleteLabObject($db, $user, $labId, $objectId);
        jsonResponse(200, 'success', 'Object deleted', $deleted);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Object not found') {
            jsonResponse(404, 'fail', 'Object not found');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to delete object');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/nodes/([a-f0-9-]{36})/power/(start|stop)$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $nodeId = normalizeUuid($m[2]);
    $action = strtolower((string) $m[3]);
    $body = readJsonBody();
    $meta = [];
    if (is_array($body)) {
        $meta = [
            'bulk_operation_id' => isset($body['bulk_operation_id']) ? (string) $body['bulk_operation_id'] : '',
            'bulk_operation_label' => isset($body['bulk_operation_label']) ? (string) $body['bulk_operation_label'] : '',
            'bulk_operation_total' => isset($body['bulk_operation_total']) ? (int) $body['bulk_operation_total'] : 0,
            'bulk_operation_index' => isset($body['bulk_operation_index']) ? (int) $body['bulk_operation_index'] : 0,
        ];
    }
    if ($labId === '' || $nodeId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or node id');
        exit;
    }
    try {
        $result = enqueueLabNodePowerTask($db, $user, $labId, $nodeId, $action, $meta);
        $responseCode = 200;
        $responseMessage = 'Task already queued';
        if (!empty($result['queued'])) {
            $responseCode = 202;
            $responseMessage = 'Power task queued';
        } elseif (($result['reason'] ?? '') === 'already_in_target_state') {
            $responseCode = 200;
            $responseMessage = 'Node already in target state';
        }
        $nodeMeta = (isset($result['node']) && is_array($result['node'])) ? $result['node'] : [];
        $taskMeta = (isset($result['task']) && is_array($result['task'])) ? $result['task'] : [];
        $activityContext = v2AppLogAttachUser([
            'event' => 'api_action',
            'method' => $method,
            'path' => $uriPath,
            'code' => $responseCode,
            'lab_id' => $labId,
            'node_id' => $nodeId,
            'node_name' => (string) ($nodeMeta['name'] ?? ''),
            'task_id' => (string) ($taskMeta['id'] ?? ''),
            'queued' => !empty($result['queued']),
            'reason' => (string) ($result['reason'] ?? ''),
        ], $user);
        if ($db instanceof PDO) {
            $activityContext = v2AppLogAttachEntityNamesFromPath($db, $activityContext);
        }
        v2AppLogWrite('user_activity', 'OK', $activityContext);
        v2AppLogMarkActionLogged();
        jsonResponse($responseCode, 'success', $responseMessage, $result);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid power action');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Node not found') {
            jsonResponse(404, 'fail', 'Node not found');
        } else {
            jsonResponse(500, 'error', 'Failed to queue task');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to queue task');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/tasks$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 150;
    try {
        $tasks = listLabTasksForViewer($db, $user, $labId, $limit);
        jsonResponse(200, 'success', 'Lab tasks loaded', $tasks);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load lab tasks');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/task-groups$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    try {
        $groups = listLabTaskGroupsForLab($db, $user, $labId, $limit);
        jsonResponse(200, 'success', 'Lab task groups loaded', $groups);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(400, 'fail', 'Invalid request');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load lab task groups');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/power/(start|stop)$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $action = strtolower((string) $m[2]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $result = enqueueLabBulkPowerTask($db, $user, $labId, $action);
        if (!empty($result['queued'])) {
            jsonResponse(202, 'success', 'Bulk power task queued', $result);
        } else {
            jsonResponse(200, 'success', 'No eligible nodes for bulk power task', $result);
        }
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid power action');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(400, 'fail', 'Invalid bulk request');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to queue bulk power task');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $data = labCheckListForViewer($db, $user, $labId);
        jsonResponse(200, 'success', 'Lab checks loaded', $data);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load lab checks');
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $body = readJsonBody();
    try {
        $saved = labCheckSaveConfig($db, $user, $labId, $body);
        jsonResponse(200, 'success', 'Lab checks saved', $saved);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', trim((string) $e->getMessage()) !== '' ? (string) $e->getMessage() : 'Invalid checks payload');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(500, 'error', 'Failed to save lab checks');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to save lab checks');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks/sync-copies$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $result = labCheckSyncChecksToLocalCopies($db, $user, $labId);
        jsonResponse(200, 'success', 'Lab checks synced to local copies', $result);
    } catch (RuntimeException $e) {
        $msg = trim((string) $e->getMessage());
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'mirror_lab_sync_not_allowed') {
            jsonResponse(409, 'fail', 'Sync is available only for source labs');
        } else {
            jsonResponse(500, 'error', 'Failed to sync lab checks');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to sync lab checks');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks/run$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $result = enqueueLabCheckTask($db, $user, $labId);
        $responseCode = !empty($result['queued']) ? 202 : 200;
        $responseMessage = !empty($result['queued']) ? 'Lab checks queued' : 'Lab checks already queued';
        $taskMeta = (isset($result['task']) && is_array($result['task'])) ? $result['task'] : [];
        $activityContext = v2AppLogAttachUser([
            'event' => 'api_action',
            'method' => $method,
            'path' => $uriPath,
            'code' => $responseCode,
            'lab_id' => $labId,
            'task_id' => (string) ($taskMeta['id'] ?? ''),
            'node_id' => (string) ($taskMeta['node_id'] ?? ''),
            'node_name' => (string) ($taskMeta['node_name'] ?? ''),
            'queued' => !empty($result['queued']),
        ], $user);
        if ($db instanceof PDO) {
            $activityContext = v2AppLogAttachEntityNamesFromPath($db, $activityContext);
        }
        v2AppLogWrite('user_activity', 'OK', $activityContext);
        v2AppLogMarkActionLogged();
        jsonResponse($responseCode, 'success', $responseMessage, $result);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'No checks configured') {
            jsonResponse(409, 'fail', 'No checks configured');
        } else {
            jsonResponse(500, 'error', 'Failed to queue lab checks');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to queue lab checks');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks/tasks$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $data = labCheckTaskListForViewer($db, $user, $labId);
        jsonResponse(200, 'success', 'Lab tasks loaded', $data);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load lab tasks');
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks/tasks$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $body = readJsonBody();
    try {
        $saved = labCheckTaskSaveConfig($db, $user, $labId, $body);
        jsonResponse(200, 'success', 'Lab tasks saved', $saved);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', trim((string) $e->getMessage()) !== '' ? (string) $e->getMessage() : 'Invalid tasks payload');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(500, 'error', 'Failed to save lab tasks');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to save lab tasks');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks/tasks/sync-copies$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $result = labCheckSyncTasksToLocalCopies($db, $user, $labId);
        jsonResponse(200, 'success', 'Lab tasks synced to local copies', $result);
    } catch (RuntimeException $e) {
        $msg = trim((string) $e->getMessage());
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'mirror_lab_sync_not_allowed') {
            jsonResponse(409, 'fail', 'Sync is available only for source labs');
        } else {
            jsonResponse(500, 'error', 'Failed to sync lab tasks');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to sync lab tasks');
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks/tasks/marks$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $body = readJsonBody();
    try {
        $saved = labCheckTaskSaveViewerMarks($db, $user, $labId, $body);
        jsonResponse(200, 'success', 'Lab task marks saved', $saved);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', trim((string) $e->getMessage()) !== '' ? (string) $e->getMessage() : 'Invalid task marks payload');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(500, 'error', 'Failed to save task marks');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to save task marks');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks/runs$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 30;
    try {
        $data = labCheckListRunsForViewer($db, $user, $labId, $limit);
        jsonResponse(200, 'success', 'Lab check runs loaded', $data);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Lab not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load runs');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks/runs/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $runId = normalizeUuid($m[2]);
    if ($labId === '' || $runId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or run id');
        exit;
    }
    try {
        $data = labCheckGetRunForViewer($db, $user, $labId, $runId);
        jsonResponse(200, 'success', 'Lab check run loaded', $data);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Run not found') {
            jsonResponse(404, 'fail', 'Run not found');
        } else {
            jsonResponse(500, 'error', 'Failed to load run');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load run');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labs/([a-f0-9-]{36})/checks/runs/([a-f0-9-]{36})/export$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $runId = normalizeUuid($m[2]);
    if ($labId === '' || $runId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or run id');
        exit;
    }
    try {
        $data = labCheckExportRunForViewer($db, $user, $labId, $runId);
        jsonResponse(200, 'success', 'Lab check run export prepared', $data);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Run not found') {
            jsonResponse(404, 'fail', 'Run not found');
        } else {
            jsonResponse(500, 'error', 'Failed to export run');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to export run');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/labmgmt/overview') {
    requirePermission($db, 'page.management.labmgmt.view');
    try {
        $data = listLabManagementOverview($db);
        jsonResponse(200, 'success', 'Lab management overview loaded', $data);
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load lab management overview');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/labmgmt/labs/([a-f0-9-]{36})/nodes$#i', $uriPath, $m)) {
    requirePermission($db, 'page.management.labmgmt.view');
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $data = listLabManagementNodesForLab($db, $labId);
        jsonResponse(200, 'success', 'Lab nodes loaded', $data);
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load lab nodes');
    }
    exit;
}

if ($method === 'POST' && $uriPath === '/api/labmgmt/actions') {
    $admin = requireAllPermissions($db, ['page.management.labmgmt.view', 'labmgmt.actions']);
    $body = readJsonBody();
    $scopeType = strtolower(trim((string) ($body['scope_type'] ?? '')));
    $scopeIdRaw = isset($body['scope_id']) ? trim((string) $body['scope_id']) : '';
    $action = strtolower(trim((string) ($body['action'] ?? '')));

    if (!in_array($scopeType, ['all', 'user', 'lab', 'node'], true)) {
        jsonResponse(400, 'fail', 'scope_type is invalid');
        exit;
    }
    if (!in_array($action, ['start', 'stop', 'wipe'], true)) {
        jsonResponse(400, 'fail', 'action is invalid');
        exit;
    }

    $scopeId = null;
    if ($scopeType !== 'all') {
        if ($scopeType === 'user') {
            $scopeId = normalizeUuid($scopeIdRaw);
        } else {
            $scopeId = normalizeUuid($scopeIdRaw);
        }
        if ($scopeId === '') {
            jsonResponse(400, 'fail', 'scope_id is invalid');
            exit;
        }
    }

    try {
        $result = runLabManagementAction($db, $admin, $scopeType, $scopeId, $action);
        jsonResponse(200, 'success', 'Action completed', $result);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid action payload');
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to process action');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/tasks') {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 150;
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $status = isset($_GET['status']) ? (string) $_GET['status'] : '';
    try {
        $tasks = listRecentLabTasksForViewer($db, $user, $limit, $status, $page);
        jsonResponse(200, 'success', 'Tasks loaded', $tasks);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(400, 'fail', 'Invalid request');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load tasks');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/tasks/groups') {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 150;
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $status = isset($_GET['status']) ? (string) $_GET['status'] : '';
    try {
        $groups = listLabTaskGroupsForViewer($db, $user, $limit, $status, $page);
        jsonResponse(200, 'success', 'Task groups loaded', $groups);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(400, 'fail', 'Invalid request');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load task groups');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/tasks/workers/status') {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    try {
        $data = getLabTaskWorkersStatus($db);
        $data['can_manage'] = (($user['role_name'] ?? '') === 'admin');
        jsonResponse(200, 'success', 'Task workers status loaded', $data);
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load workers status');
    }
    exit;
}

if ($method === 'POST' && $uriPath === '/api/tasks/workers/restart') {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    if (($user['role_name'] ?? '') !== 'admin') {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    try {
        $data = restartLabTaskWorkers($db);
        jsonResponse(200, 'success', 'Task workers restarted', $data);
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to restart workers');
    }
    exit;
}

if ($method === 'DELETE' && $uriPath === '/api/tasks/history') {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    if (($user['role_name'] ?? '') !== 'admin') {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    try {
        $result = clearLabTaskHistory($db, $user);
        jsonResponse(200, 'success', 'Task history cleared', $result);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(400, 'fail', 'Invalid request');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to clear task history');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/tasks/settings') {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    try {
        $settings = getTaskQueueSettings($db);
        $settings['can_manage'] = (($user['role_name'] ?? '') === 'admin');
        jsonResponse(200, 'success', 'Task queue settings loaded', $settings);
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load task queue settings');
    }
    exit;
}

if ($method === 'PUT' && $uriPath === '/api/tasks/settings') {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    if (($user['role_name'] ?? '') !== 'admin') {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    $body = readJsonBody();
    $limit = isset($body['power_parallel_limit']) ? (int) $body['power_parallel_limit'] : 0;
    $workerSlots = isset($body['worker_slots']) ? (int) $body['worker_slots'] : null;
    $startWorkerSlots = isset($body['start_worker_slots']) ? (int) $body['start_worker_slots'] : null;
    $stopWorkerSlots = isset($body['stop_worker_slots']) ? (int) $body['stop_worker_slots'] : null;
    $checkWorkerSlots = isset($body['check_worker_slots']) ? (int) $body['check_worker_slots'] : null;
    $fastStopVios = isset($body['fast_stop_vios']) ? (bool) $body['fast_stop_vios'] : null;
    try {
        $settings = updateTaskQueueSettings($db, $limit, $workerSlots, $startWorkerSlots, $stopWorkerSlots, $checkWorkerSlots, $fastStopVios);
        $settings['can_manage'] = true;
        jsonResponse(200, 'success', 'Task queue settings updated', $settings);
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to update task queue settings');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/tasks/groups/([a-zA-Z0-9._:-]{1,80})/(cancel|stop)$#', $uriPath, $m)) {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    $groupId = trim((string) $m[1]);
    if ($groupId === '') {
        jsonResponse(400, 'fail', 'Invalid group id');
        exit;
    }
    try {
        $result = stopLabTaskGroup($db, $user, $groupId);
        jsonResponse(200, 'success', 'Task group stopped', $result);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid group request');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(400, 'fail', 'Invalid group request');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to stop task group');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/tasks/([a-f0-9-]{36})/(cancel|stop)$#i', $uriPath, $m)) {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    $taskId = normalizeUuid($m[1]);
    if ($taskId === '') {
        jsonResponse(400, 'fail', 'Invalid task id');
        exit;
    }
    try {
        $task = stopLabTask($db, $user, $taskId);
        jsonResponse(200, 'success', 'Task stopped', $task);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid task request');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Task not found') {
            jsonResponse(404, 'fail', 'Task not found');
        } elseif ($msg === 'Task already finished') {
            jsonResponse(409, 'fail', 'Task already finished');
        } else {
            jsonResponse(400, 'fail', 'Invalid task request');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to stop task');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/tasks/([a-f0-9-]{36})/retry$#i', $uriPath, $m)) {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    $taskId = normalizeUuid($m[1]);
    if ($taskId === '') {
        jsonResponse(400, 'fail', 'Invalid task id');
        exit;
    }
    try {
        $result = retryLabTask($db, $user, $taskId);
        $enqueue = (array) ($result['enqueue'] ?? []);
        if (!empty($enqueue['queued'])) {
            jsonResponse(202, 'success', 'Task queued', $result);
        } elseif (($enqueue['reason'] ?? '') === 'already_in_target_state') {
            jsonResponse(200, 'success', 'Node already in target state', $result);
        } else {
            jsonResponse(200, 'success', 'Task already queued', $result);
        }
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid task request');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Task not found') {
            jsonResponse(404, 'fail', 'Task not found');
        } elseif ($msg === 'Task already in progress') {
            jsonResponse(409, 'fail', 'Task already in progress');
        } elseif ($msg === 'Node not found') {
            jsonResponse(404, 'fail', 'Node not found');
        } else {
            jsonResponse(400, 'fail', 'Invalid task request');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to retry task');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/tasks/([a-f0-9-]{36})/force-stop-node$#i', $uriPath, $m)) {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    $taskId = normalizeUuid($m[1]);
    if ($taskId === '') {
        jsonResponse(400, 'fail', 'Invalid task id');
        exit;
    }
    try {
        $result = forceStopNodeByTask($db, $user, $taskId);
        jsonResponse(200, 'success', 'Node force-stopped', $result);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid task request');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Task not found') {
            jsonResponse(404, 'fail', 'Task not found');
        } elseif ($msg === 'Node not found') {
            jsonResponse(404, 'fail', 'Node not found');
        } else {
            jsonResponse(400, 'fail', 'Invalid task request');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to force-stop node');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/tasks/([a-f0-9-]{36})/force-stop-lab$#i', $uriPath, $m)) {
    $user = requirePermission($db, 'page.system.taskqueue.view');
    $taskId = normalizeUuid($m[1]);
    if ($taskId === '') {
        jsonResponse(400, 'fail', 'Invalid task id');
        exit;
    }
    try {
        $result = forceStopLabByTask($db, $user, $taskId);
        jsonResponse(200, 'success', 'Lab force-stopped', $result);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid task request');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Task not found') {
            jsonResponse(404, 'fail', 'Task not found');
        } elseif ($msg === 'Lab not found') {
            jsonResponse(404, 'fail', 'Lab not found');
        } else {
            jsonResponse(400, 'fail', 'Invalid task request');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to force-stop lab');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/system/status') {
    $user = requirePermission($db, 'page.system.status.view');
    try {
        $status = getSystemStatusForViewer($db, $user);
        jsonResponse(200, 'success', 'System status loaded', $status);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(500, 'error', 'Failed to load system status');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load system status');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/system/logs') {
    $user = requireAllPermissions($db, ['page.system.logs.view', 'system.logs.read']);
    $source = (string) ($_GET['source'] ?? 'access_http');
    $lines = isset($_GET['lines']) ? (int) $_GET['lines'] : 200;
    $search = (string) ($_GET['search'] ?? '');
    try {
        $data = getSystemLogsPayloadForAdmin($source, $lines, $search);
        jsonResponse(200, 'success', 'System logs loaded', $data);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid log request');
    } catch (RuntimeException $e) {
        jsonResponse(500, 'error', 'Failed to read system logs');
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to read system logs');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/system/audit') {
    $user = requirePermission($db, 'page.system.audit.view');
    try {
        $data = getAuditTrailPayloadForViewer($db, $_GET);
        jsonResponse(200, 'success', 'System audit loaded', $data);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid audit request');
    } catch (RuntimeException $e) {
        jsonResponse(500, 'error', 'Failed to read audit trail');
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to read audit trail');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/system/audit/export') {
    $user = requirePermission($db, 'page.system.audit.view');
    $query = $_GET;
    if (!isset($query['lines'])) {
        $query['lines'] = '5000';
    }

    try {
        $payload = getAuditTrailPayloadForViewer($db, $query);
        $csv = buildAuditTrailCsv($payload);
        $filename = 'system-audit-' . gmdate('Ymd-His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo $csv;
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid audit request');
    } catch (RuntimeException $e) {
        jsonResponse(500, 'error', 'Failed to export audit trail');
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to export audit trail');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/labs/([a-f0-9-]{36})/nodes/([a-f0-9-]{36})/console/session$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $nodeId = normalizeUuid($m[2]);
    if ($labId === '' || $nodeId === '') {
        jsonResponse(400, 'fail', 'Invalid lab or node id');
        exit;
    }
    try {
        $session = v2ConsoleOpenSession($db, $user, $labId, $nodeId);
        $activityContext = v2AppLogAttachUser([
            'event' => 'api_action',
            'method' => $method,
            'path' => $uriPath,
            'code' => 201,
            'lab_id' => $labId,
            'node_id' => $nodeId,
            'node_name' => (string) ($session['node_name'] ?? ''),
            'session_id' => (string) ($session['session_id'] ?? ''),
        ], $user);
        if ($db instanceof PDO) {
            $activityContext = v2AppLogAttachEntityNamesFromPath($db, $activityContext);
        }
        v2AppLogWrite('user_activity', 'OK', $activityContext);
        v2AppLogMarkActionLogged();
        jsonResponse(201, 'success', 'Console session created', $session);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Node not found') {
            jsonResponse(404, 'fail', 'Node not found');
        } elseif ($msg === 'Node is not running') {
            jsonResponse(409, 'fail', 'Node is not running');
        } else {
            jsonResponse(500, 'error', 'Failed to open console session');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to open console session');
    }
    exit;
}

if ($method === 'POST' && $uriPath === '/api/system/host-console/session') {
    $user = requirePermission($db, 'page.system.vm_console.view');
    try {
        $session = v2SystemConsoleOpenSession($user);
        jsonResponse(201, 'success', 'System console session created', $session);
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to open system console session');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/console/sessions/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $sessionId = v2ConsoleNormalizeSessionId((string) $m[1]);
    if ($sessionId === '') {
        jsonResponse(400, 'fail', 'Invalid session id');
        exit;
    }
    try {
        $meta = v2ConsoleGetSessionForViewer($user, $sessionId);
        $payload = [
            'session_id' => (string) ($meta['session_id'] ?? $sessionId),
            'status' => (string) ($meta['status'] ?? 'starting'),
            'node_id' => (string) ($meta['node_id'] ?? ''),
            'node_name' => (string) ($meta['node_name'] ?? ''),
            'console' => (string) ($meta['node_console'] ?? 'telnet'),
            'created_at' => isset($meta['created_at']) ? (string) $meta['created_at'] : null,
            'closed_at' => isset($meta['closed_at']) ? (string) $meta['closed_at'] : null,
            'closed_reason' => isset($meta['closed_reason']) ? (string) $meta['closed_reason'] : null,
        ];
        if (isset($meta['vnc_token'])) {
            $token = (string) $meta['vnc_token'];
            $proxyPath = (string) ($meta['vnc_proxy_path'] ?? v2ConsoleVncProxyPath());
            $payload['vnc_token'] = $token;
            $payload['vnc_proxy_path'] = $proxyPath;
            $payload['vnc_path'] = rtrim($proxyPath, '/') . '/?token=' . rawurlencode($token);
        }
        jsonResponse(200, 'success', 'Console session loaded', $payload);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid session id');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Session not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load console session');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/console/sessions/([a-f0-9-]{36})/read$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $sessionId = v2ConsoleNormalizeSessionId((string) $m[1]);
    if ($sessionId === '') {
        jsonResponse(400, 'fail', 'Invalid session id');
        exit;
    }
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $waitMs = isset($_GET['wait_ms']) ? (int) $_GET['wait_ms'] : 700;
    $maxBytes = isset($_GET['max_bytes']) ? (int) $_GET['max_bytes'] : 65536;
    try {
        $chunk = v2ConsoleReadSession($user, $sessionId, $offset, $waitMs, $maxBytes);
        jsonResponse(200, 'success', 'Console stream chunk loaded', $chunk);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid session id');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Session not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to read console stream');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/console/sessions/([a-f0-9-]{36})/write$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $sessionId = v2ConsoleNormalizeSessionId((string) $m[1]);
    if ($sessionId === '') {
        jsonResponse(400, 'fail', 'Invalid session id');
        exit;
    }
    $body = readJsonBody();
    $dataBase64 = (string) ($body['data_base64'] ?? '');
    if ($dataBase64 === '') {
        jsonResponse(400, 'fail', 'data_base64 is required');
        exit;
    }
    $decoded = base64_decode($dataBase64, true);
    if (!is_string($decoded)) {
        jsonResponse(400, 'fail', 'data_base64 is invalid');
        exit;
    }
    try {
        $result = v2ConsoleWriteSession($user, $sessionId, $decoded);
        jsonResponse(200, 'success', 'Console input queued', $result);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid session id');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Session is closed') {
            jsonResponse(409, 'fail', 'Session is closed');
        } else {
            jsonResponse(404, 'fail', 'Session not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to write console input');
    }
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/console/sessions/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $sessionId = v2ConsoleNormalizeSessionId((string) $m[1]);
    if ($sessionId === '') {
        jsonResponse(400, 'fail', 'Invalid session id');
        exit;
    }
    try {
        $result = v2ConsoleCloseSession($user, $sessionId, 'client_closed');
        jsonResponse(200, 'success', 'Console session closing', $result);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid session id');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Session not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to close console session');
    }
    exit;
}

if ($method === 'POST' && $uriPath === '/api/system/host-console/uploads/init') {
    $user = requireAllPermissions($db, ['page.system.vm_console.view', 'system.vm_console.files.manage']);
    $body = readJsonBody();
    $destinationPath = (string) ($body['destination_path'] ?? '');
    $totalBytes = isset($body['total_bytes']) ? (int) $body['total_bytes'] : 0;
    $overwrite = !empty($body['overwrite']);
    try {
        $payload = v2SystemConsoleInitUpload($user, $destinationPath, $totalBytes, $overwrite);
        jsonResponse(201, 'success', 'Upload initialized', $payload);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid upload request');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Destination file already exists') {
            jsonResponse(409, 'fail', 'Destination file already exists');
        } elseif ($msg === 'Destination directory does not exist' || $msg === 'Destination directory is not writable') {
            jsonResponse(400, 'fail', $msg);
        } else {
            jsonResponse(500, 'error', 'Failed to initialize upload');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to initialize upload');
    }
    exit;
}

if ($method === 'PATCH' && preg_match('#^/api/system/host-console/uploads/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAllPermissions($db, ['page.system.vm_console.view', 'system.vm_console.files.manage']);
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : -1;
    if ($offset < 0) {
        jsonResponse(400, 'fail', 'offset query param is required');
        exit;
    }
    try {
        $payload = v2SystemConsoleUploadChunk($user, (string) $m[1], $offset);
        jsonResponse(200, 'success', 'Upload chunk accepted', $payload);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid upload id');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Upload not found') {
            jsonResponse(404, 'fail', 'Upload not found');
        } elseif ($msg === 'Invalid chunk offset') {
            jsonResponse(409, 'fail', 'Invalid chunk offset');
        } elseif ($msg === 'Upload is not active') {
            jsonResponse(409, 'fail', 'Upload is not active');
        } else {
            jsonResponse(500, 'error', 'Failed to upload chunk');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to upload chunk');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/system/host-console/uploads/([a-f0-9-]{36})/complete$#i', $uriPath, $m)) {
    $user = requireAllPermissions($db, ['page.system.vm_console.view', 'system.vm_console.files.manage']);
    try {
        $payload = v2SystemConsoleCompleteUpload($user, (string) $m[1]);
        jsonResponse(200, 'success', 'Upload completed', $payload);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid upload id');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Upload not found') {
            jsonResponse(404, 'fail', 'Upload not found');
        } elseif ($msg === 'Uploaded size does not match expected size') {
            jsonResponse(409, 'fail', 'Uploaded size does not match expected size');
        } else {
            jsonResponse(500, 'error', 'Failed to finalize upload');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to finalize upload');
    }
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/system/host-console/uploads/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAllPermissions($db, ['page.system.vm_console.view', 'system.vm_console.files.manage']);
    try {
        $payload = v2SystemConsoleCancelUpload($user, (string) $m[1]);
        jsonResponse(200, 'success', 'Upload cancelled', $payload);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid upload id');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Upload not found') {
            jsonResponse(404, 'fail', 'Upload not found');
        } else {
            jsonResponse(500, 'error', 'Failed to cancel upload');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to cancel upload');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/system/host-console/download') {
    requireAllPermissions($db, ['page.system.vm_console.view', 'system.vm_console.files.manage']);
    $path = (string) ($_GET['path'] ?? '');
    v2SystemConsoleStreamDownload($path);
    exit;
}

if ($method === 'GET' && $uriPath === '/api/system/host-console/files') {
    requireAllPermissions($db, ['page.system.vm_console.view', 'system.vm_console.files.manage']);
    $path = (string) ($_GET['path'] ?? '');
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 500;
    try {
        $payload = v2SystemConsoleListFiles($path, $limit);
        jsonResponse(200, 'success', 'Directory files loaded', $payload);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid directory path');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Directory not found') {
            jsonResponse(404, 'fail', 'Directory not found');
        } else {
            jsonResponse(500, 'error', 'Failed to read directory');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to read directory');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/main/share-users') {
    $user = requireAuth($db);
    jsonResponse(200, 'success', 'Share users loaded', listShareTargetUsers($db, (string) $user['id']));
    exit;
}

if ($method === 'GET' && $uriPath === '/api/main/list') {
    $user = requireAuth($db);
    $path = (string) ($_GET['path'] ?? '/');
    try {
        $data = listMainEntriesForViewer($db, $user, $path);
        jsonResponse(200, 'success', 'Main list loaded', $data);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid path');
    } catch (RuntimeException $e) {
        jsonResponse(404, 'fail', 'Path not found');
    }
    exit;
}

if ($method === 'POST' && $uriPath === '/api/main/folders') {
    $user = requirePermission($db, 'main.folder.create');
    $body = readJsonBody();
    $path = (string) ($body['path'] ?? '/');
    $name = (string) ($body['name'] ?? '');
    try {
        $folder = createMainFolderForViewer($db, $user, $path, $name);
        jsonResponse(201, 'success', 'Folder created', $folder);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid folder name or path');
    } catch (PDOException $e) {
        jsonResponse(409, 'fail', 'Folder already exists');
    } catch (RuntimeException $e) {
        jsonResponse(404, 'fail', 'Path not found');
    }
    exit;
}

if ($method === 'POST' && $uriPath === '/api/main/labs') {
    $user = requirePermission($db, 'main.lab.create');
    $body = readJsonBody();
    $path = (string) ($body['path'] ?? '/');
    $name = (string) ($body['name'] ?? '');
    $description = array_key_exists('description', $body) ? (string) $body['description'] : null;
    $isShared = !empty($body['is_shared']);
    $collaborateAllowed = !empty($body['collaborate_allowed']);
    $topologyLocked = !empty($body['topology_locked']);
    $topologyAllowWipe = !empty($body['topology_allow_wipe']);
    $sharedWith = $body['shared_with'] ?? [];
    if (!is_array($sharedWith)) {
        $sharedWith = [];
    }
    if (($isShared || $collaborateAllowed) && !rbacUserHasPermission($db, $user, 'main.lab.publish')) {
        jsonResponse(403, 'fail', 'Missing permission: main.lab.publish');
        exit;
    }
    if (($topologyLocked || $topologyAllowWipe) && !rbacUserHasPermission($db, $user, 'main.lab.topology_lock.manage')) {
        jsonResponse(403, 'fail', 'Missing permission: main.lab.topology_lock.manage');
        exit;
    }
    if (!empty($sharedWith) && !rbacUserHasPermission($db, $user, 'main.lab.share')) {
        jsonResponse(403, 'fail', 'Missing permission: main.lab.share');
        exit;
    }
    try {
        $lab = createMainLabForViewer($db, $user, $path, $name, $description, $isShared, $collaborateAllowed, $sharedWith, $topologyLocked, $topologyAllowWipe);
        $activityContext = v2AppLogAttachUser([
            'event' => 'main_lab_create',
            'method' => $method,
            'path' => $uriPath,
            'code' => 201,
            'lab_id' => (string) ($lab['id'] ?? ''),
            'lab_name' => (string) ($lab['name'] ?? $name),
            'folder_path' => (string) ($lab['path'] ?? $path),
        ], $user);
        v2AppLogWrite('user_activity', 'OK', $activityContext);
        v2AppLogMarkActionLogged();
        jsonResponse(201, 'success', 'Lab created', $lab);
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Shared lab is required for collaboration') {
            jsonResponse(400, 'fail', 'Enable shared lab before collaboration');
        } elseif ($msg === 'Shared lab is required for user sharing') {
            jsonResponse(400, 'fail', 'Enable shared lab before user sharing');
        } elseif (strpos($msg, 'Shared user not found:') === 0) {
            jsonResponse(400, 'fail', $msg);
        } else {
            jsonResponse(400, 'fail', 'Invalid lab name or path');
        }
    } catch (PDOException $e) {
        if (isUniqueViolation($e)) {
            jsonResponse(409, 'fail', 'Lab already exists');
        } else {
            jsonResponse(500, 'error', 'Failed to create lab');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'main_publish_forbidden') {
            jsonResponse(403, 'fail', 'Missing permission: main.lab.publish');
        } elseif ($msg === 'main_share_forbidden') {
            jsonResponse(403, 'fail', 'Missing permission: main.lab.share');
        } elseif ($msg === 'main_topology_lock_forbidden') {
            jsonResponse(403, 'fail', 'Missing permission: main.lab.topology_lock.manage');
        } else {
            jsonResponse(404, 'fail', 'Path not found');
        }
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/main/labs/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $lab = getMainLabDetailsForViewer($db, $user, $labId);
        jsonResponse(200, 'success', 'Lab details loaded', $lab);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Entry not found');
        }
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/main/labs/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $body = readJsonBody();
    $async = !empty($body['async']);
    $name = (string) ($body['name'] ?? '');
    $description = array_key_exists('description', $body) ? (string) $body['description'] : null;
    $isShared = !empty($body['is_shared']);
    $collaborateAllowed = !empty($body['collaborate_allowed']);
    $topologyLocked = !empty($body['topology_locked']);
    $topologyAllowWipe = !empty($body['topology_allow_wipe']);
    $sharedWith = $body['shared_with'] ?? [];
    if (!is_array($sharedWith)) {
        $sharedWith = [];
    }
    if ($labId === '' || $name === '') {
        jsonResponse(400, 'fail', 'name is required');
        exit;
    }
    if (($isShared || $collaborateAllowed) && !rbacUserHasPermission($db, $user, 'main.lab.publish')) {
        jsonResponse(403, 'fail', 'Missing permission: main.lab.publish');
        exit;
    }
    if (($topologyLocked || $topologyAllowWipe) && !rbacUserHasPermission($db, $user, 'main.lab.topology_lock.manage')) {
        jsonResponse(403, 'fail', 'Missing permission: main.lab.topology_lock.manage');
        exit;
    }
    if (!empty($sharedWith) && !rbacUserHasPermission($db, $user, 'main.lab.share')) {
        jsonResponse(403, 'fail', 'Missing permission: main.lab.share');
        exit;
    }
    try {
        if ($async) {
            $queued = queueMainLabUpdateForViewer(
                $db,
                $user,
                $labId,
                $name,
                $description,
                $isShared,
                $collaborateAllowed,
                $sharedWith,
                $topologyLocked,
                $topologyAllowWipe
            );
            jsonResponse(202, 'success', 'Lab update queued', $queued);
            exit;
        }
        $updated = updateMainLabForViewer(
            $db,
            $user,
            $labId,
            $name,
            $description,
            $isShared,
            $collaborateAllowed,
            $sharedWith,
            $topologyLocked,
            $topologyAllowWipe
        );
        jsonResponse(200, 'success', 'Lab updated', $updated);
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Shared lab is required for collaboration') {
            jsonResponse(400, 'fail', 'Enable shared lab before collaboration');
        } elseif ($msg === 'Shared lab is required for user sharing') {
            jsonResponse(400, 'fail', 'Enable shared lab before user sharing');
        } elseif (strpos($msg, 'Shared user not found:') === 0) {
            jsonResponse(400, 'fail', $msg);
        } else {
            jsonResponse(400, 'fail', 'Invalid lab payload');
        }
    } catch (PDOException $e) {
        if (isUniqueViolation($e)) {
            jsonResponse(409, 'fail', 'Lab already exists');
        } else {
            jsonResponse(500, 'error', 'Failed to update lab');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'main_publish_forbidden') {
            jsonResponse(403, 'fail', 'Missing permission: main.lab.publish');
        } elseif ($msg === 'main_share_forbidden') {
            jsonResponse(403, 'fail', 'Missing permission: main.lab.share');
        } elseif ($msg === 'main_topology_lock_forbidden') {
            jsonResponse(403, 'fail', 'Missing permission: main.lab.topology_lock.manage');
        } elseif ($msg === 'Lab has running nodes') {
            jsonResponse(409, 'fail', 'Stop all nodes before editing this lab');
        } elseif (
            $msg === 'Failed to queue lab update task'
            || $msg === 'Lab update worker script is missing'
            || $msg === 'Failed to initialize lab update progress'
        ) {
            jsonResponse(500, 'error', 'Failed to queue lab update task');
        } else {
            jsonResponse(404, 'fail', 'Entry not found');
        }
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/main/labs/([a-f0-9-]{36})/move$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    $body = readJsonBody();
    $destinationPath = (string) ($body['destination_path'] ?? '/');
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $moved = moveMainLabForViewer($db, $user, $labId, $destinationPath);
        jsonResponse(200, 'success', 'Lab moved', $moved);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid destination path');
    } catch (PDOException $e) {
        if (isUniqueViolation($e)) {
            jsonResponse(409, 'fail', 'Entry with this name already exists');
        } else {
            jsonResponse(500, 'error', 'Failed to move lab');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Entry already exists in destination') {
            jsonResponse(409, 'fail', 'Entry with this name already exists');
        } elseif ($msg === 'Path not found') {
            jsonResponse(404, 'fail', 'Path not found');
        } else {
            jsonResponse(404, 'fail', 'Entry not found');
        }
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/main/labs/([a-f0-9-]{36})/stop$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $result = stopMainLabNodesForViewer($db, $user, $labId);
        jsonResponse(200, 'success', 'Stop action prepared', $result);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Entry not found') {
            jsonResponse(404, 'fail', 'Entry not found');
        } else {
            jsonResponse(500, 'error', 'Failed to prepare stop action');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to prepare stop action');
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/main/shared-labs/([a-f0-9-]{36})/(local|collaborate|restart|stop)$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $sourceLabId = normalizeUuid($m[1]);
    $action = strtolower((string) $m[2]);
    if ($sourceLabId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        if ($action === 'local') {
            $result = queueSharedLabLocalCopyForViewer($db, $user, $sourceLabId, false);
            jsonResponse(202, 'success', 'Local work lab preparation queued', $result);
            exit;
        }
        if ($action === 'restart') {
            $result = queueSharedLabLocalCopyForViewer($db, $user, $sourceLabId, true);
            jsonResponse(202, 'success', 'Local work lab reset queued', $result);
            exit;
        }
        if ($action === 'stop') {
            $result = stopSharedLabLocalCopyNodesForViewer($db, $user, $sourceLabId);
            jsonResponse(200, 'success', 'Stop action prepared', $result);
            exit;
        }
        $result = openSharedLabCollaborativeForViewer($db, $user, $sourceLabId);
        jsonResponse(200, 'success', 'Collaborative lab is ready', $result);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Collaboration is disabled') {
            jsonResponse(409, 'fail', 'Collaboration is disabled');
        } elseif ($msg === 'Local copy not found') {
            jsonResponse(409, 'fail', 'Local copy is required');
        } elseif ($msg === 'Shared lab not found') {
            jsonResponse(404, 'fail', 'Shared lab not found');
        } else {
            jsonResponse(500, 'error', 'Failed to prepare shared lab action');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to prepare shared lab action');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/main/local-copy-progress/([a-z0-9._-]{8,128})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $operationId = trim((string) $m[1]);
    if ($operationId === '') {
        jsonResponse(400, 'fail', 'Invalid operation id');
        exit;
    }
    try {
        $progress = getMainLocalCopyProgressForViewer($db, $user, $operationId);
        jsonResponse(200, 'success', 'Local copy progress loaded', $progress);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid operation id');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Local copy operation not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load local copy progress');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/main/lab-update-progress/([a-z0-9._-]{8,128})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $operationId = trim((string) $m[1]);
    if ($operationId === '') {
        jsonResponse(400, 'fail', 'Invalid operation id');
        exit;
    }
    try {
        $progress = getMainLabUpdateProgressForViewer($db, $user, $operationId);
        jsonResponse(200, 'success', 'Lab update progress loaded', $progress);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid operation id');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Lab update operation not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load lab update progress');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/main/labs/([a-f0-9-]{36})/works$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $sourceLabId = normalizeUuid($m[1]);
    if ($sourceLabId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    try {
        $works = listLocalWorksForSourceLab($db, $user, $sourceLabId);
        jsonResponse(200, 'success', 'Local works loaded', $works);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Entry not found');
        }
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/main/labs/([a-f0-9-]{36})/export$#i', $uriPath, $m)) {
    $user = requirePermission($db, 'main.lab.export');
    $labId = normalizeUuid($m[1]);
    if ($labId === '') {
        jsonResponse(400, 'fail', 'Invalid lab id');
        exit;
    }
    $body = readJsonBody();
    $includeRuntime = !empty($body['include_runtime']);
    $includeChecks = !array_key_exists('include_checks', $body) || !empty($body['include_checks']);

    try {
        $queued = queueMainLabExportForViewer($db, $user, $labId, $includeRuntime, $includeChecks);
        jsonResponse(202, 'success', 'Lab export queued', $queued);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid export request');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Entry not found') {
            jsonResponse(404, 'fail', 'Entry not found');
        } elseif (stripos($msg, 'Insufficient disk space') !== false) {
            jsonResponse(409, 'fail', $msg);
        } else {
            v2AppLogWrite('system_errors', 'ERROR', v2AppLogAttachUser([
                'event' => 'main_lab_export_failed',
                'lab_id' => $labId,
                'include_runtime' => $includeRuntime,
                'include_checks' => $includeChecks,
                'message' => $msg,
            ], $user));
            jsonResponse(500, 'error', ($msg !== '' ? $msg : 'Failed to export lab'));
        }
    } catch (Throwable $e) {
        v2AppLogWrite('system_errors', 'ERROR', v2AppLogAttachUser([
            'event' => 'main_lab_export_failed',
            'lab_id' => $labId,
            'include_runtime' => $includeRuntime,
            'include_checks' => $includeChecks,
            'message' => $e->getMessage(),
        ], $user));
        jsonResponse(500, 'error', 'Failed to export lab');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/main/export-progress/([a-z0-9._-]{8,128})$#i', $uriPath, $m)) {
    $user = requirePermission($db, 'main.lab.export');
    $operationId = trim((string) $m[1]);
    if ($operationId === '') {
        jsonResponse(400, 'fail', 'Invalid operation id');
        exit;
    }
    try {
        $progress = getMainExportProgressForViewer($db, $user, $operationId);
        jsonResponse(200, 'success', 'Export progress loaded', $progress);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid operation id');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Export operation not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load export progress');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/main/import-progress/([a-z0-9._-]{8,128})$#i', $uriPath, $m)) {
    $user = requirePermission($db, 'main.lab.import');
    $operationId = trim((string) $m[1]);
    if ($operationId === '') {
        jsonResponse(400, 'fail', 'Invalid operation id');
        exit;
    }
    try {
        $progress = getMainImportProgressForViewer($db, $user, $operationId);
        jsonResponse(200, 'success', 'Import progress loaded', $progress);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid operation id');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Import operation not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load import progress');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/main/export-download/([a-z0-9._-]{8,128})$#i', $uriPath, $m)) {
    $user = requirePermission($db, 'main.lab.export');
    $operationId = trim((string) $m[1]);
    if ($operationId === '') {
        jsonResponse(400, 'fail', 'Invalid operation id');
        exit;
    }
    $download = null;
    try {
        $download = resolveMainLabExportDownloadForViewer($db, $user, $operationId);
        $archivePath = trim((string) ($download['archive_path'] ?? ''));
        $workDir = trim((string) ($download['work_dir'] ?? ''));
        $fileName = trim((string) ($download['archive_name'] ?? 'lab-export.evev2lab.tgz'));
        $contentType = trim((string) ($download['content_type'] ?? 'application/gzip'));
        if ($fileName === '') {
            $fileName = 'lab-export.evev2lab.tgz';
        }
        if ($contentType === '') {
            $contentType = 'application/gzip';
        }

        $asciiFileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName);
        if (!is_string($asciiFileName) || trim($asciiFileName) === '') {
            $asciiFileName = 'lab-export.evev2lab.tgz';
        }

        $fileSize = @filesize($archivePath);
        if (!is_int($fileSize) || $fileSize < 0) {
            $fileSize = 0;
        }

        $fh = @fopen($archivePath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Export archive is not readable');
        }

        header_remove('Content-Type');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $asciiFileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $ignoreUserAbortPrev = ignore_user_abort(true);
        $clientAborted = false;
        try {
            while (!feof($fh)) {
                $chunk = fread($fh, 8192);
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                flush();
                if (connection_aborted()) {
                    $clientAborted = true;
                    break;
                }
            }
        } finally {
            fclose($fh);
            ignore_user_abort((bool) $ignoreUserAbortPrev);
        }

        cleanupMainLabArchiveExport([
            'work_dir' => $workDir,
            'archive_path' => $archivePath,
        ]);
        mainExportProgressPatch($operationId, [
            'message' => $clientAborted ? 'Archive stream aborted by client' : 'Archive downloaded',
            'result' => [
                'downloaded' => !$clientAborted,
                'downloaded_at' => mainDeleteProgressNow(),
                'aborted' => $clientAborted,
            ],
            'internal' => [
                'archive_path' => '',
                'work_dir' => '',
                'content_type' => '',
            ],
        ]);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid operation id');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Export is not ready') {
            jsonResponse(409, 'fail', 'Export is not ready');
        } elseif ($msg === 'Export archive not found') {
            jsonResponse(410, 'fail', 'Export archive expired');
        } elseif ($msg === 'Export archive is not readable') {
            if (is_array($download)) {
                cleanupMainLabArchiveExport([
                    'work_dir' => trim((string) ($download['work_dir'] ?? '')),
                    'archive_path' => trim((string) ($download['archive_path'] ?? '')),
                ]);
            }
            mainExportProgressPatch($operationId, [
                'status' => 'failed',
                'stage' => 'failed',
                'message' => 'Export archive is not readable',
                'finished_at' => mainDeleteProgressNow(),
                'internal' => [
                    'archive_path' => '',
                    'work_dir' => '',
                    'content_type' => '',
                ],
            ]);
            jsonResponse(500, 'error', 'Failed to download export archive');
        } else {
            v2AppLogWrite('system_errors', 'ERROR', v2AppLogAttachUser([
                'event' => 'main_lab_export_download_failed',
                'operation_id' => $operationId,
                'message' => $msg,
            ], $user));
            jsonResponse(500, 'error', 'Failed to download export archive');
        }
    } catch (Throwable $e) {
        v2AppLogWrite('system_errors', 'ERROR', v2AppLogAttachUser([
            'event' => 'main_lab_export_download_failed',
            'operation_id' => $operationId,
            'message' => $e->getMessage(),
        ], $user));
        jsonResponse(500, 'error', 'Failed to download export archive');
    }
    exit;
}

if ($method === 'POST' && $uriPath === '/api/main/labs/import') {
    $user = requirePermission($db, 'main.lab.import');
    $targetPath = (string) ($_POST['path'] ?? '/');
    $labNameOverride = (string) ($_POST['lab_name'] ?? '');

    $file = $_FILES['archive'] ?? null;
    if (!is_array($file)) {
        jsonResponse(400, 'fail', 'Archive file is required');
        exit;
    }

    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = 'Archive upload failed';
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            $message = 'Archive file is required';
        } elseif ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            $uploadMax = trim((string) ini_get('upload_max_filesize'));
            $postMax = trim((string) ini_get('post_max_size'));
            $message = 'Archive is too large';
            if ($uploadMax !== '' || $postMax !== '') {
                $message .= ' (upload_max_filesize=' . ($uploadMax !== '' ? $uploadMax : 'n/a')
                    . ', post_max_size=' . ($postMax !== '' ? $postMax : 'n/a') . ')';
            }
        }
        jsonResponse(400, 'fail', $message);
        exit;
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        jsonResponse(400, 'fail', 'Archive file is invalid');
        exit;
    }

    try {
        $queued = queueMainLabImportForViewer(
            $db,
            $user,
            $targetPath,
            $tmpPath,
            (string) ($file['name'] ?? ''),
            (int) ($file['size'] ?? 0),
            $labNameOverride
        );
        jsonResponse(202, 'success', 'Lab import queued', $queued);
    } catch (InvalidArgumentException $e) {
        $msg = trim((string) $e->getMessage());
        if ($msg === 'Invalid lab name') {
            jsonResponse(400, 'fail', 'Invalid lab name');
        } else {
            jsonResponse(400, 'fail', 'Invalid import request');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Path not found') {
            jsonResponse(404, 'fail', 'Path not found');
        } elseif (stripos($msg, 'Insufficient disk space') !== false) {
            jsonResponse(409, 'fail', $msg);
        } else {
            jsonResponse(500, 'error', ($msg !== '' ? $msg : 'Failed to queue import lab'));
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to queue import lab');
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/main/entries/(folder|lab)/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $type = strtolower((string) $m[1]);
    $entryId = normalizeUuid($m[2]);
    $body = readJsonBody();
    $name = (string) ($body['name'] ?? '');
    if ($entryId === '' || $name === '') {
        jsonResponse(400, 'fail', 'name is required');
        exit;
    }
    try {
        $updated = renameMainEntryForViewer($db, $user, $type, $entryId, $name);
        jsonResponse(200, 'success', 'Entry renamed', $updated);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid entry type or name');
    } catch (PDOException $e) {
        jsonResponse(409, 'fail', 'Entry with this name already exists');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Entry not found');
        }
    }
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/main/entries/(folder|lab)/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $type = strtolower((string) $m[1]);
    $entryId = normalizeUuid($m[2]);
    if ($entryId === '') {
        jsonResponse(400, 'fail', 'Invalid entry id');
        exit;
    }
    try {
        $queued = queueMainEntryDeleteForViewer($db, $user, $type, $entryId);
        $activityContext = v2AppLogAttachUser([
            'event' => ($type === 'lab') ? 'main_lab_delete' : 'main_folder_delete',
            'method' => $method,
            'path' => $uriPath,
            'code' => 202,
            'entry_type' => $type,
            'entry_id' => $entryId,
            'entry_name' => (string) ($queued['name'] ?? ''),
            'operation_id' => (string) ($queued['operation_id'] ?? ''),
            'lab_id' => ($type === 'lab') ? $entryId : '',
            'lab_name' => ($type === 'lab') ? (string) ($queued['name'] ?? '') : '',
            'folder_id' => ($type === 'folder') ? $entryId : '',
            'folder_name' => ($type === 'folder') ? (string) ($queued['name'] ?? '') : '',
        ], $user);
        v2AppLogWrite('user_activity', 'OK', $activityContext);
        v2AppLogMarkActionLogged();
        jsonResponse(202, 'success', 'Entry deletion queued', $queued);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid entry type');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } elseif ($msg === 'Entry not found') {
            jsonResponse(404, 'fail', 'Entry not found');
        } else {
            jsonResponse(500, 'error', 'Failed to queue delete task');
        }
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/main/delete-progress/([a-z0-9._-]{8,128})$#i', $uriPath, $m)) {
    $user = requireAuth($db);
    $operationId = trim((string) $m[1]);
    if ($operationId === '') {
        jsonResponse(400, 'fail', 'Invalid operation id');
        exit;
    }
    try {
        $progress = getMainDeleteProgressForViewer($db, $user, $operationId);
        jsonResponse(200, 'success', 'Delete progress loaded', $progress);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid operation id');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            jsonResponse(403, 'fail', 'Forbidden');
        } else {
            jsonResponse(404, 'fail', 'Delete operation not found');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load delete progress');
    }
    exit;
}

if ($method === 'PUT' && $uriPath === '/api/preferences') {
    $user = requireAuth($db);
    $body = readJsonBody();
    $lang = normalizeLang($body['lang'] ?? 'en');
    $theme = normalizeTheme($body['theme'] ?? 'dark');
    updateOwnPreferences($db, (string) $user['id'], $lang, $theme);
    jsonResponse(200, 'success', 'Preferences updated', ['lang' => $lang, 'theme' => $theme]);
    exit;
}

if ($method === 'PUT' && $uriPath === '/api/preferences/password') {
    $user = requireAuth($db);
    $body = readJsonBody();
    $currentPassword = (string) ($body['current_password'] ?? '');
    $newPassword = (string) ($body['new_password'] ?? '');
    if ($currentPassword === '' || $newPassword === '') {
        jsonResponse(400, 'fail', 'current_password and new_password are required');
        exit;
    }
    $minPasswordLength = userPasswordMinLength();
    if (strlen($newPassword) < $minPasswordLength) {
        jsonResponse(400, 'fail', 'new_password_too_short');
        exit;
    }
    $userId = (string) ($user['id'] ?? '');
    $hash = getUserPasswordHash($db, $userId);
    if (!is_string($hash) || $hash === '') {
        jsonResponse(404, 'fail', 'User not found');
        exit;
    }
    if (!password_verify($currentPassword, $hash)) {
        jsonResponse(400, 'fail', 'current_password_invalid');
        exit;
    }
    if (password_verify($newPassword, $hash)) {
        jsonResponse(400, 'fail', 'new_password_same_as_old');
        exit;
    }
    try {
        updateOwnPassword($db, $userId, $newPassword);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'new_password_too_short');
        exit;
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to update password');
        exit;
    }
    jsonResponse(200, 'success', 'Password updated');
    exit;
}

if ($method === 'GET' && $uriPath === '/api/permissions') {
    requirePermission($db, 'roles.manage');
    try {
        jsonResponse(200, 'success', 'Permissions loaded', listPermissionCatalog($db));
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to load permissions');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/roles') {
    $actor = requireAnyPermission($db, ['users.manage', 'users.manage.non_admin', 'roles.manage']);
    $includeAdminRole = userMgmtCanManageAdminUsers($db, $actor) || rbacUserHasPermission($db, $actor, 'roles.manage');
    jsonResponse(200, 'success', 'Roles loaded', listRoles($db, $includeAdminRole));
    exit;
}

if ($method === 'POST' && $uriPath === '/api/roles') {
    requirePermission($db, 'roles.manage');
    $body = readJsonBody();
    $name = (string) ($body['name'] ?? '');
    try {
        $created = createRole($db, $name);
        jsonResponse(201, 'success', 'Role created', $created);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid role name');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'role_name_exists') {
            jsonResponse(409, 'fail', 'Role name already exists');
        } else {
            jsonResponse(500, 'error', 'Failed to create role');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to create role');
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/roles/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    requirePermission($db, 'roles.manage');
    $roleId = normalizeUuid($m[1]);
    if ($roleId === '') {
        jsonResponse(400, 'fail', 'Invalid role id');
        exit;
    }
    $body = readJsonBody();
    $name = (string) ($body['name'] ?? '');
    try {
        $updated = updateRoleName($db, $roleId, $name);
        jsonResponse(200, 'success', 'Role updated', $updated);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid role name');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'role_not_found') {
            jsonResponse(404, 'fail', 'Role not found');
        } elseif ($msg === 'role_name_exists') {
            jsonResponse(409, 'fail', 'Role name already exists');
        } else {
            jsonResponse(500, 'error', 'Failed to update role');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to update role');
    }
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/roles/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    requirePermission($db, 'roles.manage');
    $roleId = normalizeUuid($m[1]);
    if ($roleId === '') {
        jsonResponse(400, 'fail', 'Invalid role id');
        exit;
    }
    try {
        if (!deleteRoleById($db, $roleId)) {
            jsonResponse(404, 'fail', 'Role not found');
        } else {
            jsonResponse(200, 'success', 'Role deleted');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'role_not_found') {
            jsonResponse(404, 'fail', 'Role not found');
        } elseif ($msg === 'role_system_protected') {
            jsonResponse(409, 'fail', 'System role cannot be deleted');
        } elseif ($msg === 'role_in_use') {
            jsonResponse(409, 'fail', 'Role is assigned to users');
        } else {
            jsonResponse(500, 'error', 'Failed to delete role');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to delete role');
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/roles/([a-f0-9-]{36})/permissions$#i', $uriPath, $m)) {
    requirePermission($db, 'roles.manage');
    $roleId = normalizeUuid($m[1]);
    if ($roleId === '') {
        jsonResponse(400, 'fail', 'Invalid role id');
        exit;
    }
    if (getRoleById($db, $roleId) === null) {
        jsonResponse(404, 'fail', 'Role not found');
        exit;
    }
    jsonResponse(200, 'success', 'Role permissions loaded', [
        'role_id' => $roleId,
        'permission_ids' => listRolePermissionIds($db, $roleId),
        'permissions' => listRolePermissions($db, $roleId),
    ]);
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/roles/([a-f0-9-]{36})/permissions$#i', $uriPath, $m)) {
    requirePermission($db, 'roles.manage');
    $roleId = normalizeUuid($m[1]);
    if ($roleId === '') {
        jsonResponse(400, 'fail', 'Invalid role id');
        exit;
    }
    $body = readJsonBody();
    $permissionIds = $body['permission_ids'] ?? [];
    if (!is_array($permissionIds)) {
        jsonResponse(400, 'fail', 'permission_ids must be an array');
        exit;
    }
    try {
        $updated = setRolePermissionsByIds($db, $roleId, $permissionIds);
        jsonResponse(200, 'success', 'Role permissions updated', [
            'role_id' => $roleId,
            'permission_ids' => listRolePermissionIds($db, $roleId),
            'permissions' => $updated,
        ]);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'Invalid permission id');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'role_not_found') {
            jsonResponse(404, 'fail', 'Role not found');
        } elseif ($msg === 'roles_manage_lockout') {
            jsonResponse(409, 'fail', 'At least one active user must keep role management and roles page access');
        } else {
            jsonResponse(500, 'error', 'Failed to update role permissions');
        }
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to update role permissions');
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/users') {
    $actor = requireAnyPermission($db, ['users.manage', 'users.manage.non_admin', 'cloudmgmt.mapping.manage']);
    if (userMgmtCanManageAdminUsers($db, $actor)) {
        jsonResponse(200, 'success', 'Users loaded', listUsers($db, true));
    } elseif (userMgmtCanManageNonAdminUsers($db, $actor)) {
        jsonResponse(200, 'success', 'Users loaded', listUsers($db, false));
    } else {
        jsonResponse(200, 'success', 'Users loaded', listUsers($db, true));
    }
    exit;
}

if ($method === 'POST' && $uriPath === '/api/users') {
    $actor = requireAnyPermission($db, ['users.manage', 'users.manage.non_admin']);
    $body = readJsonBody();

    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $roleId = normalizeUuid($body['role_id'] ?? '');
    $isBlocked = !empty($body['is_blocked']);
    $lang = normalizeLang($body['lang'] ?? 'en');
    $theme = normalizeTheme($body['theme'] ?? 'dark');

    if ($username === '' || $password === '' || $roleId === '') {
        jsonResponse(400, 'fail', 'username, password and role_id are required');
        exit;
    }
    if (strlen($password) < userPasswordMinLength()) {
        jsonResponse(400, 'fail', 'password_too_short');
        exit;
    }

    if (usernameExists($db, $username)) {
        jsonResponse(409, 'fail', 'Username already exists');
        exit;
    }
    if (!roleExists($db, $roleId)) {
        jsonResponse(400, 'fail', 'role_id is invalid');
        exit;
    }
    if (!userMgmtCanManageAdminUsers($db, $actor) && roleIdIsAdmin($db, $roleId)) {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }

    try {
        $created = createUser($db, $username, $password, $roleId, $isBlocked, $lang, $theme);
        jsonResponse(201, 'success', 'User created', $created);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'password_too_short');
    } catch (Throwable $e) {
        jsonResponse(500, 'error', 'Failed to create user');
    }
    exit;
}

if ($method === 'PUT' && preg_match('#^/api/users/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $actor = requireAnyPermission($db, ['users.manage', 'users.manage.non_admin']);
    $userId = normalizeUuid($m[1]);
    $body = readJsonBody();

    $roleId = normalizeUuid($body['role_id'] ?? '');
    $isBlocked = !empty($body['is_blocked']);
    $password = array_key_exists('password', $body) ? (string) $body['password'] : null;
    $lang = normalizeLang($body['lang'] ?? 'en');
    $theme = normalizeTheme($body['theme'] ?? 'dark');

    if ($userId === '' || $roleId === '') {
        jsonResponse(400, 'fail', 'role_id is required');
        exit;
    }
    if ($password !== null && $password !== '' && strlen($password) < userPasswordMinLength()) {
        jsonResponse(400, 'fail', 'password_too_short');
        exit;
    }
    if (!roleExists($db, $roleId)) {
        jsonResponse(400, 'fail', 'role_id is invalid');
        exit;
    }
    $targetUser = getUserById($db, $userId);
    if ($targetUser === null) {
        jsonResponse(404, 'fail', 'User not found');
        exit;
    }
    if (!userMgmtCanManageAdminUsers($db, $actor)) {
        if (roleNameIsAdmin(userRoleNameFromRow($targetUser)) || roleIdIsAdmin($db, $roleId)) {
            jsonResponse(403, 'fail', 'Forbidden');
            exit;
        }
    }

    try {
        updateUser($db, $userId, $roleId, $isBlocked, $password, $lang, $theme);
        $updated = getUserById($db, $userId);
        jsonResponse(200, 'success', 'User updated', $updated);
    } catch (InvalidArgumentException $e) {
        jsonResponse(400, 'fail', 'password_too_short');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'roles_manage_lockout') {
            jsonResponse(409, 'fail', 'At least one active user must keep role management and roles page access');
        } else {
            jsonResponse(500, 'error', 'Failed to update user');
        }
    }
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/users/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    $actor = requireAnyPermission($db, ['users.manage', 'users.manage.non_admin']);
    $userId = normalizeUuid($m[1]);
    if ($userId === '') {
        jsonResponse(400, 'fail', 'Invalid user id');
        exit;
    }

    $target = getUserById($db, $userId);
    if ($target === null) {
        jsonResponse(404, 'fail', 'User not found');
        exit;
    }
    if (!userMgmtCanManageAdminUsers($db, $actor) && roleNameIsAdmin(userRoleNameFromRow($target))) {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    if ((string) $actor['id'] === $userId) {
        jsonResponse(400, 'fail', 'You cannot delete your own account');
        exit;
    }

    try {
        deleteUser($db, $userId);
        jsonResponse(200, 'success', 'User deleted');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'roles_manage_lockout') {
            jsonResponse(409, 'fail', 'At least one active user must keep role management and roles page access');
        } else {
            jsonResponse(500, 'error', 'Failed to delete user');
        }
    }
    exit;
}

if ($method === 'GET' && $uriPath === '/api/online-users') {
    $actor = requireAnyPermission($db, ['users.manage', 'users.manage.non_admin']);
    $includeAdminUsers = userMgmtCanManageAdminUsers($db, $actor);
    jsonResponse(200, 'success', 'Online users loaded', listOnlineUsers($db, 90, $includeAdminUsers));
    exit;
}

if ($method === 'GET' && preg_match('#^/api/users/([a-f0-9-]{36})/sessions$#i', $uriPath, $m)) {
    $actor = requireAnyPermission($db, ['users.manage', 'users.manage.non_admin']);
    $userId = normalizeUuid($m[1]);
    if ($userId === '') {
        jsonResponse(400, 'fail', 'Invalid user id');
        exit;
    }
    $target = getUserById($db, $userId);
    if ($target === null) {
        jsonResponse(404, 'fail', 'User not found');
        exit;
    }
    if (!userMgmtCanManageAdminUsers($db, $actor) && roleNameIsAdmin(userRoleNameFromRow($target))) {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    jsonResponse(200, 'success', 'User sessions loaded', listUserSessions($db, $userId));
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/users/([a-f0-9-]{36})/sessions$#i', $uriPath, $m)) {
    $actor = requireAnyPermission($db, ['users.manage', 'users.manage.non_admin']);
    $userId = normalizeUuid($m[1]);
    if ($userId === '') {
        jsonResponse(400, 'fail', 'Invalid user id');
        exit;
    }

    $target = getUserById($db, $userId);
    if ($target === null) {
        jsonResponse(404, 'fail', 'User not found');
        exit;
    }
    if (!userMgmtCanManageAdminUsers($db, $actor) && roleNameIsAdmin(userRoleNameFromRow($target))) {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    if ((string) $actor['id'] === $userId) {
        jsonResponse(400, 'fail', 'You cannot terminate your own sessions');
        exit;
    }

    $deleted = deleteUserSessions($db, $userId);
    jsonResponse(200, 'success', 'User sessions terminated', ['deleted_sessions' => $deleted]);
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/sessions/([a-f0-9]{64})$#', $uriPath, $m)) {
    $actor = requireAnyPermission($db, ['users.manage', 'users.manage.non_admin']);
    $token = (string) $m[1];
    if (!userMgmtCanManageAdminUsers($db, $actor) && sessionTokenBelongsToAdmin($db, $token)) {
        jsonResponse(403, 'fail', 'Forbidden');
        exit;
    }
    if (!deleteSessionByToken($db, $token)) {
        jsonResponse(404, 'fail', 'Session not found');
        exit;
    }
    jsonResponse(200, 'success', 'Session terminated');
    exit;
}

if ($method === 'GET' && $uriPath === '/api/pnets') {
    requirePermission($db, 'page.management.cloudmgmt.view');
    jsonResponse(200, 'success', 'PNET list loaded', listPnetsFromInterfaces());
    exit;
}

if ($method === 'GET' && $uriPath === '/api/clouds') {
    requireAllPermissions($db, ['page.management.cloudmgmt.view', 'cloudmgmt.mapping.manage']);
    jsonResponse(200, 'success', 'Cloud mappings loaded', listCloudMappings($db));
    exit;
}

if ($method === 'POST' && $uriPath === '/api/clouds') {
    requireAllPermissions($db, ['page.management.cloudmgmt.view', 'cloudmgmt.mapping.manage']);
    $body = readJsonBody();

    $cloudName = trim((string) ($body['cloud_name'] ?? ''));
    $userId = normalizeUuid($body['user_id'] ?? '');
    $pnet = normalizePnet($body['pnet'] ?? '');

    if ($cloudName === '' || $userId === '' || $pnet === '') {
        jsonResponse(400, 'fail', 'cloud_name, user_id and pnet are required');
        exit;
    }
    if (getUserById($db, $userId) === null) {
        jsonResponse(400, 'fail', 'user_id is invalid');
        exit;
    }
    $availablePnets = listPnetsFromInterfaces();
    if (!in_array($pnet, $availablePnets, true)) {
        jsonResponse(400, 'fail', 'pnet is not defined in /etc/network/interfaces');
        exit;
    }

    try {
        $db->beginTransaction();
        $cloudId = resolveOrCreateCloud($db, $cloudName, $pnet);
        if (mappingExists($db, $cloudId, $userId)) {
            $db->rollBack();
            jsonResponse(409, 'fail', 'Cloud mapping already exists for this user');
            exit;
        }
        $mappingId = createCloudMapping($db, $cloudId, $userId);
        $created = getCloudMappingById($db, $mappingId);
        $db->commit();
        jsonResponse(201, 'success', 'Cloud mapping created', $created);
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonResponse(500, 'error', 'Failed to create cloud mapping');
        exit;
    }
}

if ($method === 'PUT' && preg_match('#^/api/clouds/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    requireAllPermissions($db, ['page.management.cloudmgmt.view', 'cloudmgmt.mapping.manage']);
    $mappingId = normalizeUuid($m[1]);
    $body = readJsonBody();

    $cloudName = trim((string) ($body['cloud_name'] ?? ''));
    $userId = normalizeUuid($body['user_id'] ?? '');
    $pnet = normalizePnet($body['pnet'] ?? '');

    if ($mappingId === '' || $cloudName === '' || $userId === '' || $pnet === '') {
        jsonResponse(400, 'fail', 'cloud_name, user_id and pnet are required');
        exit;
    }
    if (getCloudMappingById($db, $mappingId) === null) {
        jsonResponse(404, 'fail', 'Cloud mapping not found');
        exit;
    }
    if (getUserById($db, $userId) === null) {
        jsonResponse(400, 'fail', 'user_id is invalid');
        exit;
    }
    $availablePnets = listPnetsFromInterfaces();
    if (!in_array($pnet, $availablePnets, true)) {
        jsonResponse(400, 'fail', 'pnet is not defined in /etc/network/interfaces');
        exit;
    }

    try {
        $db->beginTransaction();
        $cloudId = resolveOrCreateCloud($db, $cloudName, $pnet);
        if (mappingExists($db, $cloudId, $userId, $mappingId)) {
            $db->rollBack();
            jsonResponse(409, 'fail', 'Cloud mapping already exists for this user');
            exit;
        }
        updateCloudMapping($db, $mappingId, $cloudId, $userId);
        cleanupOrphanClouds($db);
        $updated = getCloudMappingById($db, $mappingId);
        $db->commit();
        jsonResponse(200, 'success', 'Cloud mapping updated', $updated);
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonResponse(500, 'error', 'Failed to update cloud mapping');
        exit;
    }
}

if ($method === 'DELETE' && preg_match('#^/api/clouds/([a-f0-9-]{36})$#i', $uriPath, $m)) {
    requireAllPermissions($db, ['page.management.cloudmgmt.view', 'cloudmgmt.mapping.manage']);
    $mappingId = normalizeUuid($m[1]);
    if ($mappingId === '') {
        jsonResponse(400, 'fail', 'Invalid cloud mapping id');
        exit;
    }

    if (getCloudMappingById($db, $mappingId) === null) {
        jsonResponse(404, 'fail', 'Cloud mapping not found');
        exit;
    }

    try {
        $db->beginTransaction();
        deleteCloudMapping($db, $mappingId);
        cleanupOrphanClouds($db);
        $db->commit();
        jsonResponse(200, 'success', 'Cloud mapping deleted');
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonResponse(500, 'error', 'Failed to delete cloud mapping');
        exit;
    }
}

jsonResponse(404, 'fail', 'Route not found');
