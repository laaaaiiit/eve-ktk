<?php

declare(strict_types=1);

function auditTrailAllowedSources(): array
{
	$root = '/opt/unetlab/data/Logs';
	return [
		'user_activity' => ['label' => 'User Activity', 'path' => $root . '/user_activity.log'],
		'security' => ['label' => 'Security', 'path' => $root . '/security.log'],
		'access_http' => ['label' => 'HTTP Access', 'path' => $root . '/access_http.log'],
	];
}

function normalizeAuditTrailSource(string $source): string
{
	$source = strtolower(trim($source));
	$aliases = [
		'' => 'all',
		'*' => 'all',
		'any' => 'all',
		'all' => 'all',
		'activity' => 'user_activity',
		'user' => 'user_activity',
		'security_log' => 'security',
		'access' => 'access_http',
		'http' => 'access_http',
	];
	if (isset($aliases[$source])) {
		$source = $aliases[$source];
	}
	if ($source === 'all') {
		return 'all';
	}
	return array_key_exists($source, auditTrailAllowedSources()) ? $source : 'all';
}

function normalizeAuditTrailLines(int $lines): int
{
	return max(20, min(5000, $lines));
}

function normalizeAuditFilterText($value, int $maxLen = 120): string
{
	$text = trim((string) $value);
	if ($text === '') {
		return '';
	}
	if (strlen($text) > $maxLen) {
		$text = substr($text, 0, $maxLen);
	}
	return $text;
}

function parseAuditFilterDate($value, bool $isEnd = false): ?float
{
	$text = trim((string) $value);
	if ($text === '') {
		return null;
	}

	try {
		$dt = new DateTimeImmutable($text);
	} catch (Throwable $e) {
		return null;
	}

	if ($isEnd && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $text)) {
		$dt = $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 59);
	}

	return (float) $dt->format('U.u');
}

function parseAuditLogTimestamp(string $raw): array
{
	$raw = trim($raw);
	if ($raw === '') {
		return ['raw' => '', 'iso' => null, 'epoch' => null];
	}

	$dt = DateTimeImmutable::createFromFormat('H:i:s d/m/y P', $raw);
	if (!$dt instanceof DateTimeImmutable) {
		try {
			$dt = new DateTimeImmutable($raw);
		} catch (Throwable $e) {
			return ['raw' => $raw, 'iso' => null, 'epoch' => null];
		}
	}

	return [
		'raw' => $raw,
		'iso' => $dt->format(DateTimeInterface::ATOM),
		'epoch' => (float) $dt->format('U.u'),
	];
}

function parseAuditContext(string $raw): array
{
	$raw = trim($raw);
	if ($raw === '' || $raw === '-') {
		return [];
	}

	$out = [];
	$pattern = '/([a-z0-9_]+)=("(?:(?:\\\\.)|[^"\\\\])*"|[^\s]+)/i';
	if (!preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER)) {
		return [];
	}

	foreach ($matches as $m) {
		$key = strtolower(trim((string) ($m[1] ?? '')));
		if ($key === '') {
			continue;
		}
		$token = (string) ($m[2] ?? '');
		$value = $token;
		if (strlen($token) >= 2 && $token[0] === '"' && substr($token, -1) === '"') {
			$value = stripcslashes(substr($token, 1, -1));
		}
		if ($value === '-') {
			$value = '';
		}
		$out[$key] = $value;
	}

	return $out;
}

function classifyAuditAction(string $event, string $method, string $path): string
{
	$event = strtolower(trim($event));
	$method = strtoupper(trim($method));
	$path = trim($path);

	if ($event === 'request') {
		return 'request';
	}
	if ($event === 'auth_login') {
		return 'auth_login';
	}
	if ($event === 'auth_logout') {
		return 'auth_logout';
	}
	if ($event === 'page_open') {
		return 'page_open';
	}
	if ($event === 'access_denied' || $event === 'page_open_denied') {
		return 'access_denied';
	}
	if ($event === 'lab_checks_saved') {
		return 'lab_checks_update';
	}
	if ($event === 'lab_check_tasks_saved') {
		return 'lab_tasks_update';
	}
	if ($event === 'lab_check_tasks_marks_saved') {
		return 'lab_tasks_mark';
	}
	if ($event === 'lab_checks_run') {
		return 'lab_checks_run';
	}
	if ($event === 'node_create') {
		return 'node_create';
	}
	if ($event === 'node_delete') {
		return 'node_delete';
	}
	if ($event === 'main_lab_delete') {
		return 'main_lab_delete';
	}
	if ($event === 'main_folder_delete') {
		return 'main_folder_delete';
	}

	if ($path !== '') {
		// Service/polling requests (hide from human-oriented audit UI).
		if (preg_match('#^/api/console/sessions/[a-f0-9-]{36}/(read|write)$#i', $path)) {
			return 'service_console_io';
		}
		if (
			preg_match('#^/api/main/(local-copy-progress|lab-update-progress|export-progress|import-progress|delete-progress)/#i', $path)
			|| preg_match('#^/api/tasks(?:$|/)#i', $path)
			|| preg_match('#^/api/system/host-console/(files|download|uploads)#i', $path)
		) {
			return 'service_progress';
		}

		if (preg_match('#^/api/labs/[a-f0-9-]{36}/nodes/[a-f0-9-]{36}/console/session$#i', $path)
			|| $path === '/api/system/host-console/session') {
			return 'console_open';
		}
		if ($method === 'DELETE' && preg_match('#^/api/console/sessions/[a-f0-9-]{36}$#i', $path)) {
			return 'console_close';
		}

		// Main explorer actions.
		if ($method === 'POST' && $path === '/api/main/folders') {
			return 'main_folder_create';
		}
		if ($method === 'POST' && $path === '/api/main/labs') {
			return 'main_lab_create';
		}
		if ($method === 'PUT' && preg_match('#^/api/main/labs/[a-f0-9-]{36}$#i', $path)) {
			return 'main_lab_update';
		}
		if ($method === 'POST' && preg_match('#^/api/main/labs/[a-f0-9-]{36}/move$#i', $path)) {
			return 'main_lab_move';
		}
		if ($method === 'POST' && preg_match('#^/api/main/labs/[a-f0-9-]{36}/stop$#i', $path)) {
			return 'main_lab_stop';
		}
		if ($method === 'POST' && preg_match('#^/api/main/labs/[a-f0-9-]{36}/export$#i', $path)) {
			return 'main_lab_export';
		}
		if ($method === 'POST' && $path === '/api/main/labs/import') {
			return 'main_lab_import';
		}
		if ($method === 'PUT' && preg_match('#^/api/main/entries/lab/[a-f0-9-]{36}$#i', $path)) {
			return 'main_lab_rename';
		}
		if ($method === 'PUT' && preg_match('#^/api/main/entries/folder/[a-f0-9-]{36}$#i', $path)) {
			return 'main_folder_rename';
		}
		if ($method === 'DELETE' && preg_match('#^/api/main/entries/lab/[a-f0-9-]{36}$#i', $path)) {
			return 'main_lab_delete';
		}
		if ($method === 'DELETE' && preg_match('#^/api/main/entries/folder/[a-f0-9-]{36}$#i', $path)) {
			return 'main_folder_delete';
		}
		if ($method === 'POST' && preg_match('#^/api/main/shared-labs/[a-f0-9-]{36}/local$#i', $path)) {
			return 'main_shared_local_open';
		}
		if ($method === 'POST' && preg_match('#^/api/main/shared-labs/[a-f0-9-]{36}/restart$#i', $path)) {
			return 'main_shared_local_restart';
		}
		if ($method === 'POST' && preg_match('#^/api/main/shared-labs/[a-f0-9-]{36}/collaborate$#i', $path)) {
			return 'main_shared_collaborate_open';
		}
		if ($method === 'POST' && preg_match('#^/api/main/shared-labs/[a-f0-9-]{36}/stop$#i', $path)) {
			return 'main_shared_local_stop';
		}

		// Profile actions.
		if ($method === 'PUT' && $path === '/api/preferences') {
			return 'profile_update';
		}
		if ($method === 'PUT' && $path === '/api/preferences/password') {
			return 'profile_password_update';
		}

		// Session actions (legacy endpoints can still appear in logs).
		if ($method === 'DELETE' && preg_match('#^/api/sessions/[a-z0-9]{32,128}$#i', $path)) {
			return 'auth_logout';
		}

		// Cloud management.
		if (preg_match('#^/api/clouds(?:$|/)#i', $path)) {
			return 'cloud_manage';
		}

		// Lab checks/tasks actions.
		if ($method === 'PUT' && preg_match('#^/api/labs/[a-f0-9-]{36}/checks$#i', $path)) {
			return 'lab_checks_update';
		}
		if ($method === 'POST' && preg_match('#^/api/labs/[a-f0-9-]{36}/checks/run$#i', $path)) {
			return 'lab_checks_run';
		}
		if ($method === 'POST' && preg_match('#^/api/labs/[a-f0-9-]{36}/checks/sync-copies$#i', $path)) {
			return 'lab_checks_sync';
		}
		if ($method === 'PUT' && preg_match('#^/api/labs/[a-f0-9-]{36}/checks/tasks$#i', $path)) {
			return 'lab_tasks_update';
		}
		if ($method === 'POST' && preg_match('#^/api/labs/[a-f0-9-]{36}/checks/tasks/sync-copies$#i', $path)) {
			return 'lab_tasks_sync';
		}
		if ($method === 'PUT' && preg_match('#^/api/labs/[a-f0-9-]{36}/checks/tasks/marks$#i', $path)) {
			return 'lab_tasks_mark';
		}

		if (preg_match('#/power/start$#i', $path)) {
			return 'power_start';
		}
		if (preg_match('#/power/stop$#i', $path)) {
			return 'power_stop';
		}
		if (preg_match('#/power/reload$#i', $path)) {
			return 'power_reload';
		}
		if (preg_match('#/power/wipe$#i', $path)) {
			return 'power_wipe';
		}
		if (preg_match('#^/api/labs/[a-f0-9-]{36}/checks#i', $path)) {
			return 'lab_check';
		}
		if (preg_match('#^/api/labs/[a-f0-9-]{36}/(nodes|links|attachments|link-layout|objects|clouds)#i', $path)) {
			return 'topology_change';
		}
		if (preg_match('#^/api/main/(labs|folders)#i', $path)) {
			return 'main_change';
		}
	}

	if ($event === 'api_action') {
		return 'api_action';
	}

	return $event !== '' ? $event : 'other';
}

function auditActionCatalog(): array
{
	return [
		['id' => 'request', 'label' => 'HTTP Request'],
		['id' => 'auth_login', 'label' => 'Login'],
		['id' => 'auth_logout', 'label' => 'Logout'],
		['id' => 'page_open', 'label' => 'Page Open'],
		['id' => 'access_denied', 'label' => 'Access Denied'],
		['id' => 'console_open', 'label' => 'Console Open'],
		['id' => 'console_close', 'label' => 'Console Close'],
		['id' => 'main_folder_create', 'label' => 'Folder Create'],
		['id' => 'main_folder_rename', 'label' => 'Folder Rename'],
		['id' => 'main_folder_delete', 'label' => 'Folder Delete'],
		['id' => 'main_lab_create', 'label' => 'Lab Create'],
		['id' => 'main_lab_update', 'label' => 'Lab Update'],
		['id' => 'main_lab_rename', 'label' => 'Lab Rename'],
		['id' => 'main_lab_move', 'label' => 'Lab Move'],
		['id' => 'main_lab_stop', 'label' => 'Lab Stop'],
		['id' => 'main_lab_export', 'label' => 'Lab Export'],
		['id' => 'main_lab_import', 'label' => 'Lab Import'],
		['id' => 'main_lab_delete', 'label' => 'Lab Delete'],
		['id' => 'main_shared_local_open', 'label' => 'Open Local Copy'],
		['id' => 'main_shared_local_restart', 'label' => 'Reset Local Copy'],
		['id' => 'main_shared_collaborate_open', 'label' => 'Open Collaboration'],
		['id' => 'main_shared_local_stop', 'label' => 'Stop Local Copy'],
		['id' => 'node_create', 'label' => 'Node Create'],
		['id' => 'node_delete', 'label' => 'Node Delete'],
		['id' => 'profile_update', 'label' => 'Profile Update'],
		['id' => 'profile_password_update', 'label' => 'Password Change'],
		['id' => 'cloud_manage', 'label' => 'Cloud Manage'],
		['id' => 'power_start', 'label' => 'Node Start'],
		['id' => 'power_stop', 'label' => 'Node Stop'],
		['id' => 'power_reload', 'label' => 'Node Reload'],
		['id' => 'power_wipe', 'label' => 'Node Wipe'],
		['id' => 'lab_check', 'label' => 'Lab Check'],
		['id' => 'lab_checks_update', 'label' => 'Lab Checks Update'],
		['id' => 'lab_checks_sync', 'label' => 'Lab Checks Sync Copies'],
		['id' => 'lab_checks_run', 'label' => 'Lab Checks Run'],
		['id' => 'lab_tasks_update', 'label' => 'Lab Tasks Update'],
		['id' => 'lab_tasks_sync', 'label' => 'Lab Tasks Sync Copies'],
		['id' => 'lab_tasks_mark', 'label' => 'Lab Tasks Mark'],
		['id' => 'topology_change', 'label' => 'Topology Change'],
		['id' => 'main_change', 'label' => 'Main Explorer Change'],
		['id' => 'api_action', 'label' => 'API Action'],
		['id' => 'service_progress', 'label' => 'Service Progress'],
		['id' => 'service_console_io', 'label' => 'Service Console I/O'],
		['id' => 'other', 'label' => 'Other'],
	];
}

function parseAuditLogLine(string $sourceId, string $line, int $seq): ?array
{
	$line = trimSystemLogLine($line);
	if ($line === '') {
		return null;
	}

	if (!preg_match('/^\[(?<ts>[^\]]+)\]\s+\[(?<ip>[^\]]*)\]\s+\[(?<status>[^\]]*)\]\s+-\s*(?<ctx>.*)$/', $line, $m)) {
		return null;
	}

	$timestamp = parseAuditLogTimestamp((string) ($m['ts'] ?? ''));
	$ip = trim((string) ($m['ip'] ?? ''));
	$status = strtoupper(trim((string) ($m['status'] ?? '')));
	if ($status !== 'ERROR') {
		$status = 'OK';
	}
	$context = parseAuditContext((string) ($m['ctx'] ?? ''));
	$event = strtolower(trim((string) ($context['event'] ?? '')));
	$method = strtoupper(trim((string) ($context['method'] ?? '')));
	$path = trim((string) ($context['path'] ?? ''));
	$user = trim((string) ($context['user'] ?? $context['username'] ?? ''));
	$role = trim((string) ($context['role'] ?? ''));
	$userId = trim((string) ($context['user_id'] ?? ''));
	$code = isset($context['code']) && is_numeric((string) $context['code']) ? (int) $context['code'] : null;
	$durationMs = isset($context['duration_ms']) && is_numeric((string) $context['duration_ms']) ? (int) $context['duration_ms'] : null;
	$action = classifyAuditAction($event, $method, $path);

	return [
		'source' => $sourceId,
		'timestamp_raw' => (string) ($timestamp['raw'] ?? ''),
		'timestamp_iso' => $timestamp['iso'] ?? null,
		'timestamp_epoch' => $timestamp['epoch'] ?? null,
		'ip' => $ip !== '' ? $ip : '127.0.0.1',
		'status' => $status,
		'event' => $event,
		'action' => $action,
		'method' => $method,
		'path' => $path,
		'user' => $user,
		'user_id' => $userId,
		'role' => $role,
		'code' => $code,
		'duration_ms' => $durationMs,
		'context' => $context,
		'raw_line' => $line,
		'_seq' => $seq,
	];
}

function auditRecordIsNoise(array $record): bool
{
	$action = strtolower(trim((string) ($record['action'] ?? '')));
	// Hide raw HTTP request records in audit (both OK and ERROR) to avoid duplicates.
	if ($action === 'request') {
		return true;
	}

	$status = strtoupper(trim((string) ($record['status'] ?? 'OK')));
	if ($status === 'ERROR') {
		return false;
	}

	$event = strtolower(trim((string) ($record['event'] ?? '')));
	$path = strtolower(trim((string) ($record['path'] ?? '')));
	$method = strtoupper(trim((string) ($record['method'] ?? '')));

	// Hide service-level events for regular audit view.
	if ($action === 'service_progress' || $action === 'service_console_io') {
		return true;
	}
	// Opening /console page always followed by console_open action, keep only action.
	if ($event === 'page_open' && ($path === '/console' || $path === '/capture')) {
		return true;
	}

	if ($path === '/api/auth/ping' || $path === '/api/v2/auth/ping') {
		return true;
	}

	if (
		($path === '/api/system/audit' || $path === '/api/v2/system/audit'
			|| $path === '/api/system/audit/export' || $path === '/api/v2/system/audit/export')
		&& $method === 'GET'
	) {
		return true;
	}

	// Hide technical duplicate rows for successful checks/tasks saves:
	// a human-readable domain event is already logged for these PUT calls.
	if (
		$event === 'api_action'
		&& $method === 'PUT'
		&& preg_match('#^/api/labs/[a-f0-9-]{36}/checks(?:/tasks(?:/marks)?)?$#i', $path)
	) {
		return true;
	}

	return false;
}

function auditRecordMatches(array $record, array $filters): bool
{
	$status = strtolower((string) ($filters['status'] ?? 'all'));
	if ($status !== '' && $status !== 'all') {
		if (strtolower((string) ($record['status'] ?? '')) !== $status) {
			return false;
		}
	}

	$event = strtolower((string) ($filters['event'] ?? ''));
	if ($event !== '' && $event !== 'all') {
		if (strtolower((string) ($record['event'] ?? '')) !== $event) {
			return false;
		}
	}

	$action = strtolower((string) ($filters['action'] ?? ''));
	if ($action !== '' && $action !== 'all') {
		if (strtolower((string) ($record['action'] ?? '')) !== $action) {
			return false;
		}
	}

	$method = strtoupper((string) ($filters['method'] ?? ''));
	if ($method !== '' && $method !== 'ALL') {
		if (strtoupper((string) ($record['method'] ?? '')) !== $method) {
			return false;
		}
	}

	$userNeedle = strtolower((string) ($filters['user'] ?? ''));
	if ($userNeedle !== '') {
		$userHaystack = strtolower((string) ($record['user'] ?? ''));
		if ($userHaystack === '' || strpos($userHaystack, $userNeedle) === false) {
			return false;
		}
	}

	$ipNeedle = strtolower((string) ($filters['ip'] ?? ''));
	if ($ipNeedle !== '') {
		$ipHaystack = strtolower((string) ($record['ip'] ?? ''));
		if ($ipHaystack === '' || strpos($ipHaystack, $ipNeedle) === false) {
			return false;
		}
	}

	$fromEpoch = $filters['date_from_epoch'] ?? null;
	if (is_numeric($fromEpoch)) {
		$recordTs = $record['timestamp_epoch'] ?? null;
		if (!is_numeric($recordTs) || (float) $recordTs < (float) $fromEpoch) {
			return false;
		}
	}

	$toEpoch = $filters['date_to_epoch'] ?? null;
	if (is_numeric($toEpoch)) {
		$recordTs = $record['timestamp_epoch'] ?? null;
		if (!is_numeric($recordTs) || (float) $recordTs > (float) $toEpoch) {
			return false;
		}
	}

	$searchNeedle = strtolower((string) ($filters['search'] ?? ''));
	if ($searchNeedle !== '') {
		$haystack = strtolower(
			implode(' ', [
				(string) ($record['raw_line'] ?? ''),
				(string) ($record['event'] ?? ''),
				(string) ($record['action'] ?? ''),
				(string) ($record['method'] ?? ''),
				(string) ($record['path'] ?? ''),
				(string) ($record['user'] ?? ''),
				(string) ($record['role'] ?? ''),
				(string) ($record['ip'] ?? ''),
				(string) ($record['object_type'] ?? ''),
				(string) ($record['object_id'] ?? ''),
				(string) ($record['object_name'] ?? ''),
				(string) ($record['lab_id'] ?? ''),
				(string) ($record['lab_name'] ?? ''),
				(string) ($record['node_id'] ?? ''),
				(string) ($record['node_name'] ?? ''),
				(string) ($record['cloud_id'] ?? ''),
				(string) ($record['cloud_name'] ?? ''),
				(string) ($record['folder_id'] ?? ''),
				(string) ($record['folder_name'] ?? ''),
				(string) ($record['session_id'] ?? ''),
				(string) ($record['task_id'] ?? ''),
			])
		);
		if (strpos($haystack, $searchNeedle) === false) {
			return false;
		}
	}

	return true;
}

function auditNormalizeUuid(string $value): string
{
	$value = strtolower(trim($value));
	if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value)) {
		return '';
	}
	return $value;
}

function auditFirstValidUuid(array $values): string
{
	foreach ($values as $value) {
		$uuid = auditNormalizeUuid((string) $value);
		if ($uuid !== '') {
			return $uuid;
		}
	}
	return '';
}

function auditPrepareTextInClause(string $prefix, array $values): array
{
	$placeholders = [];
	$params = [];
	$index = 0;
	foreach ($values as $value) {
		$id = trim((string) $value);
		if ($id === '') {
			continue;
		}
		$ph = ':' . $prefix . $index;
		$placeholders[] = $ph;
		$params[$ph] = $id;
		$index += 1;
	}
	return [$placeholders, $params];
}

function auditExtractTargetRefs(array $record): array
{
	$context = (isset($record['context']) && is_array($record['context'])) ? $record['context'] : [];
	$path = trim((string) ($record['path'] ?? ''));

	$refs = [
		'lab_id' => auditFirstValidUuid([
			$context['lab_id'] ?? '',
			$context['source_lab_id'] ?? '',
		]),
		'node_id' => auditFirstValidUuid([
			$context['node_id'] ?? '',
			$context['source_node_id'] ?? '',
			$context['target_node_id'] ?? '',
		]),
		'cloud_id' => auditFirstValidUuid([
			$context['cloud_id'] ?? '',
		]),
		'folder_id' => auditFirstValidUuid([
			$context['folder_id'] ?? '',
		]),
		'session_id' => auditFirstValidUuid([
			$context['session_id'] ?? '',
		]),
		'task_id' => auditFirstValidUuid([
			$context['task_id'] ?? '',
			$context['active_task_id'] ?? '',
		]),
	];

	if ($refs['lab_id'] === '' && preg_match('#^/api/labs/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['lab_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}
	if ($refs['node_id'] === '' && preg_match('#^/api/labs/[a-f0-9-]{36}/nodes/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['node_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}
	if ($refs['node_id'] === '' && preg_match('#^/api/labmgmt/labs/[a-f0-9-]{36}/nodes/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['node_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}
	if ($refs['lab_id'] === '' && preg_match('#^/api/main/labs/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['lab_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}
	if ($refs['lab_id'] === '' && preg_match('#^/api/main/shared-labs/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['lab_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}
	if ($refs['lab_id'] === '' && preg_match('#^/api/main/entries/lab/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['lab_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}
	if ($refs['folder_id'] === '' && preg_match('#^/api/main/entries/folder/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['folder_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}
	if ($refs['folder_id'] === '' && preg_match('#^/api/main/folders/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['folder_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}
	if ($refs['cloud_id'] === '' && preg_match('#^/api/clouds/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['cloud_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}
	if ($refs['session_id'] === '' && preg_match('#^/api/console/sessions/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['session_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}
	if ($refs['task_id'] === '' && preg_match('#^/api/tasks/([a-f0-9-]{36})(?:/|$)#i', $path, $m)) {
		$refs['task_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}

	// Page open on lab view: /lab/{lab_uuid}
	if ($refs['lab_id'] === '' && preg_match('#^/lab/([a-f0-9-]{36})$#i', $path, $m)) {
		$refs['lab_id'] = auditNormalizeUuid((string) ($m[1] ?? ''));
	}

	return $refs;
}

function auditReadConsoleSessionMeta(string $sessionId): ?array
{
	$sessionId = auditNormalizeUuid($sessionId);
	if ($sessionId === '') {
		return null;
	}

	if (function_exists('v2ConsoleReadMeta')) {
		try {
			$meta = v2ConsoleReadMeta($sessionId);
			if (is_array($meta)) {
				return $meta;
			}
		} catch (Throwable $e) {
			// Fall through to plain file lookup.
		}
	}

	$path = '/opt/unetlab/data/v2-console/sessions/' . $sessionId . '/meta.json';
	if (!is_file($path)) {
		return null;
	}
	$raw = @file_get_contents($path);
	if (!is_string($raw) || $raw === '') {
		return null;
	}
	$data = json_decode($raw, true);
	return is_array($data) ? $data : null;
}

function auditFetchLabsByIds(PDO $db, array $labIds): array
{
	$out = [];
	[$placeholders, $params] = auditPrepareTextInClause('lab', array_values(array_unique($labIds)));
	if (count($placeholders) < 1) {
		return $out;
	}

	$sql = "SELECT id::text AS id, name
	        FROM labs
	        WHERE id::text IN (" . implode(', ', $placeholders) . ")";
	$stmt = $db->prepare($sql);
	foreach ($params as $ph => $value) {
		$stmt->bindValue($ph, $value, PDO::PARAM_STR);
	}
	$stmt->execute();
	foreach ($stmt->fetchAll() ?: [] as $row) {
		$id = auditNormalizeUuid((string) ($row['id'] ?? ''));
		if ($id === '') {
			continue;
		}
		$out[$id] = trim((string) ($row['name'] ?? ''));
	}
	return $out;
}

function auditFetchNodesByIds(PDO $db, array $nodeIds): array
{
	$out = [];
	[$placeholders, $params] = auditPrepareTextInClause('node', array_values(array_unique($nodeIds)));
	if (count($placeholders) < 1) {
		return $out;
	}

	$sql = "SELECT id::text AS id, lab_id::text AS lab_id, name
	        FROM lab_nodes
	        WHERE id::text IN (" . implode(', ', $placeholders) . ")";
	$stmt = $db->prepare($sql);
	foreach ($params as $ph => $value) {
		$stmt->bindValue($ph, $value, PDO::PARAM_STR);
	}
	$stmt->execute();
	foreach ($stmt->fetchAll() ?: [] as $row) {
		$id = auditNormalizeUuid((string) ($row['id'] ?? ''));
		if ($id === '') {
			continue;
		}
		$out[$id] = [
			'name' => trim((string) ($row['name'] ?? '')),
			'lab_id' => auditNormalizeUuid((string) ($row['lab_id'] ?? '')),
		];
	}
	return $out;
}

function auditFetchCloudsByIds(PDO $db, array $cloudIds): array
{
	$out = [];
	[$placeholders, $params] = auditPrepareTextInClause('cloud', array_values(array_unique($cloudIds)));
	if (count($placeholders) < 1) {
		return $out;
	}

	$sql = "SELECT id::text AS id, name
	        FROM clouds
	        WHERE id::text IN (" . implode(', ', $placeholders) . ")";
	$stmt = $db->prepare($sql);
	foreach ($params as $ph => $value) {
		$stmt->bindValue($ph, $value, PDO::PARAM_STR);
	}
	$stmt->execute();
	foreach ($stmt->fetchAll() ?: [] as $row) {
		$id = auditNormalizeUuid((string) ($row['id'] ?? ''));
		if ($id === '') {
			continue;
		}
		$out[$id] = trim((string) ($row['name'] ?? ''));
	}
	return $out;
}

function auditFetchFoldersByIds(PDO $db, array $folderIds): array
{
	$out = [];
	[$placeholders, $params] = auditPrepareTextInClause('folder', array_values(array_unique($folderIds)));
	if (count($placeholders) < 1) {
		return $out;
	}

	$sql = "SELECT id::text AS id, name
	        FROM lab_folders
	        WHERE id::text IN (" . implode(', ', $placeholders) . ")";
	$stmt = $db->prepare($sql);
	foreach ($params as $ph => $value) {
		$stmt->bindValue($ph, $value, PDO::PARAM_STR);
	}
	$stmt->execute();
	foreach ($stmt->fetchAll() ?: [] as $row) {
		$id = auditNormalizeUuid((string) ($row['id'] ?? ''));
		if ($id === '') {
			continue;
		}
		$out[$id] = trim((string) ($row['name'] ?? ''));
	}
	return $out;
}

function auditFetchUsersByIds(PDO $db, array $userIds): array
{
	$out = [];
	[$placeholders, $params] = auditPrepareTextInClause('usr', array_values(array_unique($userIds)));
	if (count($placeholders) < 1) {
		return $out;
	}

	$sql = "SELECT u.id::text AS id,
	               u.username,
	               COALESCE(r.name, '') AS role_name
	        FROM users u
	        LEFT JOIN roles r ON r.id = u.role_id
	        WHERE u.id::text IN (" . implode(', ', $placeholders) . ")";
	$stmt = $db->prepare($sql);
	foreach ($params as $ph => $value) {
		$stmt->bindValue($ph, $value, PDO::PARAM_STR);
	}
	$stmt->execute();
	foreach ($stmt->fetchAll() ?: [] as $row) {
		$id = auditNormalizeUuid((string) ($row['id'] ?? ''));
		if ($id === '') {
			continue;
		}
		$out[$id] = [
			'username' => trim((string) ($row['username'] ?? '')),
			'role' => strtolower(trim((string) ($row['role_name'] ?? ''))),
		];
	}

	return $out;
}

function auditFetchTaskRefsByIds(PDO $db, array $taskIds): array
{
	$out = [];
	[$placeholders, $params] = auditPrepareTextInClause('task', array_values(array_unique($taskIds)));
	if (count($placeholders) < 1) {
		return $out;
	}

	$sql = "SELECT t.id::text AS task_id,
	               t.lab_id::text AS lab_id,
	               t.node_id::text AS node_id,
	               COALESCE(l.name, '') AS lab_name,
	               COALESCE(n.name, '') AS node_name,
	               COALESCE(t.action, '') AS task_action
	        FROM lab_tasks t
	        LEFT JOIN labs l ON l.id = t.lab_id
	        LEFT JOIN lab_nodes n ON n.id = t.node_id
	        WHERE t.id::text IN (" . implode(', ', $placeholders) . ")";
	$stmt = $db->prepare($sql);
	foreach ($params as $ph => $value) {
		$stmt->bindValue($ph, $value, PDO::PARAM_STR);
	}
	$stmt->execute();
	foreach ($stmt->fetchAll() ?: [] as $row) {
		$taskId = auditNormalizeUuid((string) ($row['task_id'] ?? ''));
		if ($taskId === '') {
			continue;
		}
		$out[$taskId] = [
			'lab_id' => auditNormalizeUuid((string) ($row['lab_id'] ?? '')),
			'node_id' => auditNormalizeUuid((string) ($row['node_id'] ?? '')),
			'lab_name' => trim((string) ($row['lab_name'] ?? '')),
			'node_name' => trim((string) ($row['node_name'] ?? '')),
			'task_action' => trim((string) ($row['task_action'] ?? '')),
		];
	}

	return $out;
}

function auditEnrichRecords(PDO $db, array $records): array
{
	$refsByIndex = [];
	$labIds = [];
	$nodeIds = [];
	$cloudIds = [];
	$folderIds = [];
	$sessionIds = [];
	$userIds = [];
	$taskIds = [];

	foreach ($records as $idx => $record) {
		if (!is_array($record)) {
			continue;
		}
		$refs = auditExtractTargetRefs($record);
		$refsByIndex[$idx] = $refs;

		if ($refs['lab_id'] !== '') {
			$labIds[] = $refs['lab_id'];
		}
		if ($refs['node_id'] !== '') {
			$nodeIds[] = $refs['node_id'];
		}
		if ($refs['cloud_id'] !== '') {
			$cloudIds[] = $refs['cloud_id'];
		}
		if ($refs['folder_id'] !== '') {
			$folderIds[] = $refs['folder_id'];
		}
		if ($refs['session_id'] !== '') {
			$sessionIds[] = $refs['session_id'];
		}
		if ($refs['task_id'] !== '') {
			$taskIds[] = $refs['task_id'];
		}
		$userId = auditNormalizeUuid((string) ($record['user_id'] ?? ''));
		if ($userId !== '') {
			$userIds[] = $userId;
		}
	}

	$sessionMetaMap = [];
	foreach (array_values(array_unique($sessionIds)) as $sessionId) {
		$meta = auditReadConsoleSessionMeta($sessionId);
		if (!is_array($meta)) {
			continue;
		}
		$sessionMetaMap[$sessionId] = [
			'lab_id' => auditNormalizeUuid((string) ($meta['lab_id'] ?? '')),
			'node_id' => auditNormalizeUuid((string) ($meta['node_id'] ?? '')),
			'node_name' => trim((string) ($meta['node_name'] ?? '')),
		];

		if ($sessionMetaMap[$sessionId]['lab_id'] !== '') {
			$labIds[] = $sessionMetaMap[$sessionId]['lab_id'];
		}
		if ($sessionMetaMap[$sessionId]['node_id'] !== '') {
			$nodeIds[] = $sessionMetaMap[$sessionId]['node_id'];
		}
	}

	$nodesMap = auditFetchNodesByIds($db, $nodeIds);
	$labsMap = auditFetchLabsByIds($db, $labIds);
	$cloudsMap = auditFetchCloudsByIds($db, $cloudIds);
	$foldersMap = auditFetchFoldersByIds($db, $folderIds);
	$usersMap = auditFetchUsersByIds($db, $userIds);
	$taskMap = auditFetchTaskRefsByIds($db, $taskIds);

	foreach ($records as $idx => &$record) {
		if (!is_array($record)) {
			continue;
		}
		$context = (isset($record['context']) && is_array($record['context'])) ? $record['context'] : [];

		$refs = $refsByIndex[$idx] ?? ['lab_id' => '', 'node_id' => '', 'cloud_id' => '', 'folder_id' => '', 'session_id' => '', 'task_id' => ''];
		$sessionId = (string) ($refs['session_id'] ?? '');
		$taskId = (string) ($refs['task_id'] ?? '');
		$sessionMeta = ($sessionId !== '' && isset($sessionMetaMap[$sessionId]) && is_array($sessionMetaMap[$sessionId]))
			? $sessionMetaMap[$sessionId]
			: null;
		$taskMeta = ($taskId !== '' && isset($taskMap[$taskId]) && is_array($taskMap[$taskId]))
			? $taskMap[$taskId]
			: null;

		$labId = (string) ($refs['lab_id'] ?? '');
		$nodeId = (string) ($refs['node_id'] ?? '');
		$cloudId = (string) ($refs['cloud_id'] ?? '');
		$folderId = (string) ($refs['folder_id'] ?? '');

		if ($labId === '' && is_array($sessionMeta)) {
			$labId = (string) ($sessionMeta['lab_id'] ?? '');
		}
		if ($labId === '' && is_array($taskMeta)) {
			$labId = (string) ($taskMeta['lab_id'] ?? '');
		}
		if ($nodeId === '' && is_array($sessionMeta)) {
			$nodeId = (string) ($sessionMeta['node_id'] ?? '');
		}
		if ($nodeId === '' && is_array($taskMeta)) {
			$nodeId = (string) ($taskMeta['node_id'] ?? '');
		}
		if ($labId === '' && $nodeId !== '' && isset($nodesMap[$nodeId]) && is_array($nodesMap[$nodeId])) {
			$labId = (string) ($nodesMap[$nodeId]['lab_id'] ?? '');
		}

		$nodeName = '';
		if ($nodeId !== '' && isset($nodesMap[$nodeId]) && is_array($nodesMap[$nodeId])) {
			$nodeName = trim((string) ($nodesMap[$nodeId]['name'] ?? ''));
		}
		if ($nodeName === '' && is_array($sessionMeta)) {
			$nodeName = trim((string) ($sessionMeta['node_name'] ?? ''));
		}
		if ($nodeName === '' && is_array($taskMeta)) {
			$nodeName = trim((string) ($taskMeta['node_name'] ?? ''));
		}
		if ($nodeName === '') {
			$nodeName = trim((string) ($context['node_name'] ?? ''));
		}

		$labName = $labId !== '' ? trim((string) ($labsMap[$labId] ?? '')) : '';
		if ($labName === '' && is_array($taskMeta)) {
			$labName = trim((string) ($taskMeta['lab_name'] ?? ''));
		}
		if ($labName === '') {
			$labName = trim((string) ($context['lab_name'] ?? $context['entry_name'] ?? ''));
		}
		$cloudName = $cloudId !== '' ? trim((string) ($cloudsMap[$cloudId] ?? '')) : '';
		if ($cloudName === '') {
			$cloudName = trim((string) ($context['cloud_name'] ?? $context['entry_name'] ?? ''));
		}
		$folderName = $folderId !== '' ? trim((string) ($foldersMap[$folderId] ?? '')) : '';
		if ($folderName === '') {
			$folderName = trim((string) ($context['folder_name'] ?? $context['entry_name'] ?? ''));
		}
		$userId = auditNormalizeUuid((string) ($record['user_id'] ?? ''));
		if ($userId !== '' && isset($usersMap[$userId]) && is_array($usersMap[$userId])) {
			$userNameResolved = trim((string) ($usersMap[$userId]['username'] ?? ''));
			$userRoleResolved = trim((string) ($usersMap[$userId]['role'] ?? ''));
			if (trim((string) ($record['user'] ?? '')) === '' && $userNameResolved !== '') {
				$record['user'] = $userNameResolved;
			}
			if (trim((string) ($record['role'] ?? '')) === '' && $userRoleResolved !== '') {
				$record['role'] = $userRoleResolved;
			}
		}
		if (trim((string) ($record['user'] ?? '')) === '' && $userId !== '') {
			$record['user'] = $userId;
		}

		$objectType = 'system';
		$objectId = '';
		$objectName = '';
		$path = trim((string) ($record['path'] ?? ''));
		$event = strtolower(trim((string) ($record['event'] ?? '')));
		if ($nodeId !== '') {
			$objectType = 'node';
			$objectId = $nodeId;
			$objectName = $nodeName;
		} elseif ($cloudId !== '') {
			$objectType = 'cloud';
			$objectId = $cloudId;
			$objectName = $cloudName;
		} elseif ($labId !== '') {
			$objectType = 'lab';
			$objectId = $labId;
			$objectName = $labName;
		} elseif ($folderId !== '') {
			$objectType = 'folder';
			$objectId = $folderId;
			$objectName = $folderName;
		} elseif ($sessionId !== '') {
			$objectType = 'console_session';
			$objectId = $sessionId;
			$objectName = $nodeName;
		} elseif ($taskId !== '') {
			$objectType = 'task';
			$objectId = $taskId;
			$objectName = '';
		} elseif ($event === 'page_open' || ($path !== '' && strpos($path, '/api/') !== 0)) {
			$objectType = 'page';
			$objectName = $path;
		}

		$record['session_id'] = $sessionId;
		$record['task_id'] = $taskId;
		$record['lab_id'] = $labId;
		$record['lab_name'] = $labName;
		$record['node_id'] = $nodeId;
		$record['node_name'] = $nodeName;
		$record['cloud_id'] = $cloudId;
		$record['cloud_name'] = $cloudName;
		$record['folder_id'] = $folderId;
		$record['folder_name'] = $folderName;
		$record['object_type'] = $objectType;
		$record['object_id'] = $objectId;
		$record['object_name'] = $objectName;
	}
	unset($record);

	return $records;
}

function getAuditTrailPayloadForViewer(PDO $db, array $query): array
{
	$source = normalizeAuditTrailSource((string) ($query['source'] ?? 'all'));
	$lines = normalizeAuditTrailLines(isset($query['lines']) ? (int) $query['lines'] : 100);
	$search = normalizeAuditFilterText($query['search'] ?? '', 255);
	$user = normalizeAuditFilterText($query['user'] ?? '', 80);
	$ip = normalizeAuditFilterText($query['ip'] ?? '', 80);
	$status = strtolower(normalizeAuditFilterText($query['status'] ?? 'all', 16));
	if (!in_array($status, ['all', 'ok', 'error'], true)) {
		$status = 'all';
	}
	$event = strtolower(normalizeAuditFilterText($query['event'] ?? '', 80));
	$action = strtolower(normalizeAuditFilterText($query['action'] ?? '', 80));
	$method = strtoupper(normalizeAuditFilterText($query['method'] ?? 'all', 12));
	if ($method === '' || $method === '*') {
		$method = 'ALL';
	}
	$dateFrom = normalizeAuditFilterText($query['date_from'] ?? '', 40);
	$dateTo = normalizeAuditFilterText($query['date_to'] ?? '', 40);
	$dateFromEpoch = parseAuditFilterDate($dateFrom, false);
	$dateToEpoch = parseAuditFilterDate($dateTo, true);

	$sources = auditTrailAllowedSources();
	$selectedSourceIds = [];
	if ($source === 'all') {
		$selectedSourceIds = array_keys($sources);
	} elseif (isset($sources[$source])) {
		$selectedSourceIds = [$source];
	}

	$readMultiplier = 20;
	$readLimitPerSource = max(200, min(40000, $lines * $readMultiplier));
	$records = [];
	foreach ($selectedSourceIds as $sourceId) {
		$path = (string) ($sources[$sourceId]['path'] ?? '');
		$rawLines = tailLinesFromFile($path, $readLimitPerSource);
		$seq = 0;
		foreach ($rawLines as $line) {
			$seq += 1;
			$record = parseAuditLogLine($sourceId, (string) $line, $seq);
			if ($record !== null && !auditRecordIsNoise($record)) {
				$records[] = $record;
			}
		}
	}

	usort($records, static function (array $a, array $b): int {
		$ta = isset($a['timestamp_epoch']) && is_numeric($a['timestamp_epoch']) ? (float) $a['timestamp_epoch'] : 0.0;
		$tb = isset($b['timestamp_epoch']) && is_numeric($b['timestamp_epoch']) ? (float) $b['timestamp_epoch'] : 0.0;
		if ($ta < $tb) {
			return 1;
		}
		if ($ta > $tb) {
			return -1;
		}
		return ((int) ($b['_seq'] ?? 0)) <=> ((int) ($a['_seq'] ?? 0));
	});

	$records = auditEnrichRecords($db, $records);

	$filters = [
		'search' => $search,
		'user' => $user,
		'ip' => $ip,
		'status' => $status,
		'event' => $event,
		'action' => $action,
		'method' => $method,
		'date_from' => $dateFrom,
		'date_to' => $dateTo,
		'date_from_epoch' => $dateFromEpoch,
		'date_to_epoch' => $dateToEpoch,
	];

	$filtered = array_values(array_filter($records, static function (array $record) use ($filters): bool {
		return auditRecordMatches($record, $filters);
	}));
	$limited = array_slice($filtered, 0, $lines);

	$events = [];
	$actions = [];
	$methods = [];
	foreach ($records as $record) {
		$e = strtolower(trim((string) ($record['event'] ?? '')));
		if ($e !== '') {
			$events[$e] = true;
		}
		$a = strtolower(trim((string) ($record['action'] ?? '')));
		if ($a !== '') {
			$actions[$a] = true;
		}
		$m = strtoupper(trim((string) ($record['method'] ?? '')));
		if ($m !== '') {
			$methods[$m] = true;
		}
	}

	$eventList = array_keys($events);
	sort($eventList, SORT_STRING);
	$actionCatalog = auditActionCatalog();
	$actionLabels = [];
	foreach ($actionCatalog as $row) {
		$actionLabels[(string) ($row['id'] ?? '')] = (string) ($row['label'] ?? '');
	}
	$actionList = array_keys($actions);
	sort($actionList, SORT_STRING);

	$methodList = array_keys($methods);
	sort($methodList, SORT_STRING);

	$sourcesMeta = [
		['id' => 'all', 'label' => 'All Sources'],
	];
	foreach ($sources as $id => $row) {
		$sourcesMeta[] = [
			'id' => (string) $id,
			'label' => (string) ($row['label'] ?? $id),
		];
	}

	$outRecords = array_map(static function (array $record): array {
		unset($record['_seq'], $record['timestamp_epoch']);
		return $record;
	}, $limited);

	return [
		'selected_source' => $source,
		'selected_lines' => $lines,
		'filters' => [
			'search' => $search,
			'user' => $user,
			'ip' => $ip,
			'status' => $status,
			'event' => $event,
			'action' => $action,
			'method' => ($method === 'ALL' ? 'all' : $method),
			'date_from' => $dateFrom,
			'date_to' => $dateTo,
		],
		'sources' => $sourcesMeta,
		'events' => $eventList,
		'actions' => array_map(static function (string $id) use ($actionLabels): array {
			return ['id' => $id, 'label' => $actionLabels[$id] ?? $id];
		}, $actionList),
		'methods' => $methodList,
		'content' => [
			'record_count' => count($outRecords),
			'records_total' => count($records),
			'records_filtered' => count($filtered),
			'records' => $outRecords,
		],
	];
}

function buildAuditTrailCsv(array $payload): string
{
	$csvSafe = static function ($value): string {
		$text = (string) $value;
		if ($text === '') {
			return '';
		}
		$first = $text[0];
		if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r") {
			return "'" . $text;
		}
		return $text;
	};

	$records = (array) (($payload['content'] ?? [])['records'] ?? []);
	$fp = fopen('php://temp', 'w+');
	if ($fp === false) {
		throw new RuntimeException('Failed to build export');
	}

	fwrite($fp, "\xEF\xBB\xBF");
	fputcsv($fp, [
		'time',
		'source',
		'status',
		'user',
		'role',
		'ip',
		'event',
		'action',
		'object_type',
		'object_name',
		'object_id',
		'lab_name',
		'node_name',
		'cloud_name',
		'folder_name',
		'method',
		'path',
		'http_code',
		'duration_ms',
		'raw_line',
	], ';');

	foreach ($records as $row) {
		$item = is_array($row) ? $row : [];
		fputcsv($fp, [
			$csvSafe((string) ($item['timestamp_raw'] ?? '')),
			$csvSafe((string) ($item['source'] ?? '')),
			$csvSafe((string) ($item['status'] ?? '')),
			$csvSafe((string) ($item['user'] ?? '')),
			$csvSafe((string) ($item['role'] ?? '')),
			$csvSafe((string) ($item['ip'] ?? '')),
			$csvSafe((string) ($item['event'] ?? '')),
			$csvSafe((string) ($item['action'] ?? '')),
			$csvSafe((string) ($item['object_type'] ?? '')),
			$csvSafe((string) ($item['object_name'] ?? '')),
			$csvSafe((string) ($item['object_id'] ?? '')),
			$csvSafe((string) ($item['lab_name'] ?? '')),
			$csvSafe((string) ($item['node_name'] ?? '')),
			$csvSafe((string) ($item['cloud_name'] ?? '')),
			$csvSafe((string) ($item['folder_name'] ?? '')),
			$csvSafe((string) ($item['method'] ?? '')),
			$csvSafe((string) ($item['path'] ?? '')),
			$csvSafe((isset($item['code']) && $item['code'] !== null) ? (string) $item['code'] : ''),
			$csvSafe((isset($item['duration_ms']) && $item['duration_ms'] !== null) ? (string) $item['duration_ms'] : ''),
			$csvSafe((string) ($item['raw_line'] ?? '')),
		], ';');
	}

	rewind($fp);
	$out = stream_get_contents($fp);
	fclose($fp);
	if (!is_string($out)) {
		throw new RuntimeException('Failed to finalize export');
	}

	return $out;
}
