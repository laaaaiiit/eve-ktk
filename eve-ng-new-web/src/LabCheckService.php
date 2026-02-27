<?php

declare(strict_types=1);

const LAB_CHECK_MAX_ITEMS = 300;
const LAB_CHECK_MAX_GRADES = 32;
const LAB_CHECK_MAX_OUTPUT_BYTES = 180000;

function labCheckNormalizeUuid($value): string
{
    $uuid = strtolower(trim((string) $value));
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid)) {
        return '';
    }
    return $uuid;
}

function labCheckBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    $text = strtolower(trim((string) $value));
    return in_array($text, ['1', 'true', 'yes', 'on'], true);
}

function labCheckViewerId(array $viewer): string
{
    return trim((string) ($viewer['id'] ?? ''));
}

function labCheckViewerIsAdmin(array $viewer): bool
{
    $role = strtolower(trim((string) ($viewer['role_name'] ?? $viewer['role'] ?? '')));
    return $role === 'admin';
}

function labCheckLog(string $status, array $context): void
{
    if (!function_exists('v2AppLogWrite')) {
        return;
    }
    v2AppLogWrite('user_activity', $status, $context);
}

function labCheckEnsureViewerCanView(PDO $db, array $viewer, string $labId): void
{
    if (function_exists('viewerCanViewLab')) {
        if (!viewerCanViewLab($db, $viewer, $labId)) {
            throw new RuntimeException('Forbidden');
        }
        return;
    }

    $viewerId = labCheckViewerId($viewer);
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $sql = "SELECT 1
            FROM labs l
            LEFT JOIN lab_shared_users su ON su.lab_id = l.id AND su.user_id = :viewer_id
            WHERE l.id = :lab_id";
    if (!labCheckViewerIsAdmin($viewer)) {
        $sql .= " AND (l.author_user_id = :viewer_id OR su.user_id IS NOT NULL)";
    }
    $sql .= ' LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->fetchColumn() === false) {
        throw new RuntimeException('Forbidden');
    }
}

function labCheckCanManage(PDO $db, array $viewer, string $labId): bool
{
    if (labCheckViewerIsAdmin($viewer)) {
        return true;
    }

    $viewerId = labCheckViewerId($viewer);
    if ($viewerId === '') {
        return false;
    }

    $stmt = $db->prepare('SELECT author_user_id FROM labs WHERE id = :lab_id LIMIT 1');
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return false;
    }
    return hash_equals((string) ($row['author_user_id'] ?? ''), $viewerId);
}

function labCheckEnsureDefaults(PDO $db, string $labId, ?string $actorUserId = null): void
{
    $actorUserId = labCheckNormalizeUuid($actorUserId);

    $insertSettings = $db->prepare(
        "INSERT INTO lab_check_settings (lab_id, grading_enabled, pass_percent, updated_by)
         VALUES (:lab_id, TRUE, 60.00, :updated_by)
         ON CONFLICT (lab_id) DO NOTHING"
    );
    $insertSettings->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    if ($actorUserId === '') {
        $insertSettings->bindValue(':updated_by', null, PDO::PARAM_NULL);
    } else {
        $insertSettings->bindValue(':updated_by', $actorUserId, PDO::PARAM_STR);
    }
    $insertSettings->execute();

    $countStmt = $db->prepare('SELECT COUNT(*) FROM lab_check_grade_scales WHERE lab_id = :lab_id');
    $countStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $countStmt->execute();
    $count = (int) $countStmt->fetchColumn();
    if ($count > 0) {
        return;
    }

    $defaults = [
        ['min_percent' => 90.0, 'grade_label' => '5', 'order_index' => 0],
        ['min_percent' => 75.0, 'grade_label' => '4', 'order_index' => 1],
        ['min_percent' => 60.0, 'grade_label' => '3', 'order_index' => 2],
        ['min_percent' => 0.0, 'grade_label' => '2', 'order_index' => 3],
    ];

    $ins = $db->prepare(
        "INSERT INTO lab_check_grade_scales (lab_id, min_percent, grade_label, order_index)
         VALUES (:lab_id, :min_percent, :grade_label, :order_index)"
    );
    foreach ($defaults as $row) {
        $ins->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $ins->bindValue(':min_percent', (float) $row['min_percent']);
        $ins->bindValue(':grade_label', (string) $row['grade_label'], PDO::PARAM_STR);
        $ins->bindValue(':order_index', (int) $row['order_index'], PDO::PARAM_INT);
        $ins->execute();
    }
}

function labCheckLoadSettings(PDO $db, string $labId): array
{
    $stmt = $db->prepare(
        "SELECT grading_enabled, pass_percent, updated_at
         FROM lab_check_settings
         WHERE lab_id = :lab_id
         LIMIT 1"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return [
            'grading_enabled' => true,
            'pass_percent' => 60.0,
            'updated_at' => null,
        ];
    }

    return [
        'grading_enabled' => !empty($row['grading_enabled']),
        'pass_percent' => isset($row['pass_percent']) ? (float) $row['pass_percent'] : 60.0,
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
    ];
}

function labCheckLoadGrades(PDO $db, string $labId): array
{
    $stmt = $db->prepare(
        "SELECT id, min_percent, grade_label, order_index
         FROM lab_check_grade_scales
         WHERE lab_id = :lab_id
         ORDER BY min_percent DESC, order_index ASC, created_at ASC"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    return array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'min_percent' => isset($row['min_percent']) ? (float) $row['min_percent'] : 0.0,
            'grade_label' => trim((string) ($row['grade_label'] ?? '')),
            'order_index' => isset($row['order_index']) ? (int) $row['order_index'] : 0,
        ];
    }, $rows);
}

function labCheckLoadItemsRaw(PDO $db, string $labId, bool $enabledOnly = false): array
{
    $sql = "SELECT i.id,
                   i.lab_id,
                   i.node_id,
                   i.title,
                   i.transport,
                   i.shell_type,
                   i.command_text,
                   i.match_mode,
                   i.expected_text,
                   i.hint_text,
                   i.show_expected_to_learner,
                   i.show_output_to_learner,
                   i.points,
                   i.timeout_seconds,
                   i.is_enabled,
                   i.order_index,
                   i.ssh_host,
                   i.ssh_port,
                   i.ssh_username,
                   i.ssh_password,
                   i.updated_at,
                   n.name AS node_name,
                   n.node_type,
                   n.template AS node_template,
                   n.image AS node_image,
                   n.console,
                   n.power_state,
                   n.is_running,
                   n.runtime_console_port,
                   n.runtime_check_console_port
            FROM lab_check_items i
            LEFT JOIN lab_nodes n ON n.id = i.node_id AND n.lab_id = i.lab_id
            WHERE i.lab_id = :lab_id";
    if ($enabledOnly) {
        $sql .= ' AND i.is_enabled = TRUE';
    }
    $sql .= ' ORDER BY i.order_index ASC, i.created_at ASC, i.id ASC';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function labCheckSanitizeItemForViewer(array $row, bool $canManage): array
{
    $base = [
        'id' => (string) ($row['id'] ?? ''),
        'lab_id' => (string) ($row['lab_id'] ?? ''),
        'node_id' => (string) ($row['node_id'] ?? ''),
        'node_name' => (string) ($row['node_name'] ?? ''),
        'node_type' => (string) ($row['node_type'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'hint_text' => (string) ($row['hint_text'] ?? ''),
        'show_expected_to_learner' => !empty($row['show_expected_to_learner']),
        'show_output_to_learner' => !empty($row['show_output_to_learner']),
        'points' => isset($row['points']) ? (int) $row['points'] : 0,
        'is_enabled' => !empty($row['is_enabled']),
        'order_index' => isset($row['order_index']) ? (int) $row['order_index'] : 0,
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
    ];

    if ($canManage) {
        $base['transport'] = (string) ($row['transport'] ?? 'auto');
        $base['shell_type'] = (string) ($row['shell_type'] ?? 'auto');
        $base['command_text'] = (string) ($row['command_text'] ?? '');
        $base['match_mode'] = (string) ($row['match_mode'] ?? 'contains');
        $base['expected_text'] = (string) ($row['expected_text'] ?? '');
        $base['timeout_seconds'] = isset($row['timeout_seconds']) ? (int) $row['timeout_seconds'] : 12;
        $base['ssh_host'] = (string) ($row['ssh_host'] ?? '');
        $base['ssh_port'] = isset($row['ssh_port']) ? (int) $row['ssh_port'] : 22;
        $base['ssh_username'] = (string) ($row['ssh_username'] ?? '');
        $base['ssh_password'] = (string) ($row['ssh_password'] ?? '');
    } else {
        $base['expected_text'] = !empty($row['show_expected_to_learner']) ? (string) ($row['expected_text'] ?? '') : null;
    }

    return $base;
}

function labCheckListForViewer(PDO $db, array $viewer, string $labId): array
{
    labCheckEnsureViewerCanView($db, $viewer, $labId);

    $canManage = labCheckCanManage($db, $viewer, $labId);
    $settings = labCheckLoadSettings($db, $labId);
    $grades = labCheckLoadGrades($db, $labId);
    if (empty($grades)) {
        $grades = labCheckNormalizeGradesPayload([]);
    }
    $itemsRaw = labCheckLoadItemsRaw($db, $labId, !$canManage);

    $items = [];
    foreach ($itemsRaw as $row) {
        if (!$canManage && empty($row['is_enabled'])) {
            continue;
        }
        $items[] = labCheckSanitizeItemForViewer($row, $canManage);
    }

    return [
        'lab_id' => $labId,
        'can_manage' => $canManage,
        'settings' => $settings,
        'grades' => $grades,
        'items' => $items,
    ];
}

function labCheckNormalizeTransport($value): string
{
    $transport = strtolower(trim((string) $value));
    if (!in_array($transport, ['auto', 'console', 'ssh'], true)) {
        return 'auto';
    }
    return $transport;
}

function labCheckNormalizeShellType($value): string
{
    $shell = strtolower(trim((string) $value));
    if (!in_array($shell, ['auto', 'ios', 'sh', 'cmd', 'powershell'], true)) {
        return 'auto';
    }
    return $shell;
}

function labCheckNormalizeMatchMode($value): string
{
    $mode = strtolower(trim((string) $value));
    if (!in_array($mode, ['contains', 'equals', 'regex', 'not_contains'], true)) {
        return 'contains';
    }
    return $mode;
}

function labCheckTextContainsAny(string $haystack, array $needles): bool
{
    $text = strtolower(trim($haystack));
    if ($text === '') {
        return false;
    }
    foreach ($needles as $needle) {
        $token = strtolower(trim((string) $needle));
        if ($token === '') {
            continue;
        }
        if (strpos($text, $token) !== false) {
            return true;
        }
    }
    return false;
}

function labCheckResolveNodeShellProfile(array $item): string
{
    $nodeType = strtolower(trim((string) ($item['node_type'] ?? '')));
    if (in_array($nodeType, ['iol', 'dynamips', 'vpcs'], true)) {
        return 'ios';
    }
    if ($nodeType === 'docker') {
        return 'linux';
    }

    $fingerprint = implode(' ', [
        strtolower(trim((string) ($item['node_template'] ?? $item['template'] ?? ''))),
        strtolower(trim((string) ($item['node_image'] ?? $item['image'] ?? ''))),
        strtolower(trim((string) ($item['node_name'] ?? ''))),
        $nodeType,
    ]);

    if (labCheckTextContainsAny($fingerprint, [
        'windows',
        'win10',
        'win11',
        'win7',
        'win8',
        'w2k',
        'winserver',
        'server2012',
        'server2016',
        'server2019',
        'server2022',
        'microsoft',
    ])) {
        return 'windows';
    }

    if (labCheckTextContainsAny($fingerprint, [
        'ubuntu',
        'debian',
        'centos',
        'fedora',
        'alpine',
        'linux',
        'rhel',
        'rocky',
        'opensuse',
        'suse',
        'kali',
        'mint',
    ])) {
        return 'linux';
    }

    if (labCheckTextContainsAny($fingerprint, [
        'ios',
        'csr',
        'nxos',
        'asav',
        'asa',
        'junos',
        'vyos',
        'router',
        'switch',
    ])) {
        return 'ios';
    }

    return 'unknown';
}

function labCheckResolveEffectiveShellType(array $item): string
{
    $shellType = labCheckNormalizeShellType($item['shell_type'] ?? 'auto');
    if ($shellType !== 'auto') {
        return $shellType;
    }

    $profile = labCheckResolveNodeShellProfile($item);
    if ($profile === 'windows') {
        return 'cmd';
    }
    if ($profile === 'linux') {
        return 'sh';
    }
    if ($profile === 'ios') {
        return 'ios';
    }
    return 'auto';
}

function labCheckShellTypeAllowedForProfile(string $shellType, string $profile): bool
{
    $shell = labCheckNormalizeShellType($shellType);
    $platform = strtolower(trim($profile));

    if ($platform === 'windows') {
        return in_array($shell, ['auto', 'cmd', 'powershell'], true);
    }
    if ($platform === 'linux') {
        return in_array($shell, ['auto', 'sh'], true);
    }
    if ($platform === 'ios') {
        return in_array($shell, ['auto', 'ios'], true);
    }
    return true;
}

function labCheckNormalizeCommandByShell(string $command, string $shellType, string $transport): string
{
    $raw = rtrim($command, "\r\n");
    if ($raw === '') {
        return '';
    }

    $shell = labCheckNormalizeShellType($shellType);
    $mode = labCheckNormalizeTransport($transport);
    if ($shell === 'auto' || $shell === 'ios') {
        return $raw;
    }

    if ($shell === 'powershell') {
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $parts = array_values(array_filter(array_map(static function ($line): string {
            return trim((string) $line);
        }, explode("\n", $normalized)), static function ($line): bool {
            return $line !== '';
        }));
        $joined = !empty($parts) ? implode('; ', $parts) : trim($normalized);
        if ($joined === '') {
            return '';
        }
        $escaped = str_replace("'", "''", $joined);
        return "powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '" . $escaped . "'";
    }

    if ($shell === 'cmd' && $mode === 'ssh') {
        $singleLine = str_replace(["\r\n", "\r", "\n"], ' & ', $raw);
        $singleLine = trim($singleLine);
        if ($singleLine === '') {
            return '';
        }
        $escaped = str_replace('"', '\"', $singleLine);
        return 'cmd /c "' . $escaped . '"';
    }

    if ($shell === 'sh' && $mode === 'ssh') {
        $escaped = str_replace("'", "'\"'\"'", $raw);
        return "sh -lc '" . $escaped . "'";
    }

    return $raw;
}

function labCheckLoadLabNodeSet(PDO $db, string $labId): array
{
    $stmt = $db->prepare(
        "SELECT id, name
         FROM lab_nodes
         WHERE lab_id = :lab_id"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    $set = [];
    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $set[$id] = (string) ($row['name'] ?? '');
    }
    return $set;
}

function labCheckNormalizeGradesPayload($gradesRaw): array
{
    $rows = is_array($gradesRaw) ? $gradesRaw : [];
    $normalized = [];
    $idx = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($idx >= LAB_CHECK_MAX_GRADES) {
            break;
        }
        $minPercent = isset($row['min_percent']) ? (float) $row['min_percent'] : 0.0;
        if (!is_finite($minPercent)) {
            $minPercent = 0.0;
        }
        if ($minPercent < 0.0) {
            $minPercent = 0.0;
        } elseif ($minPercent > 100.0) {
            $minPercent = 100.0;
        }

        $label = trim((string) ($row['grade_label'] ?? ''));
        if ($label === '') {
            continue;
        }
        if (strlen($label) > 64) {
            $label = substr($label, 0, 64);
        }

        $normalized[] = [
            'min_percent' => round($minPercent, 2),
            'grade_label' => $label,
            'order_index' => $idx,
        ];
        $idx++;
    }

    if (empty($normalized)) {
        $normalized = [
            ['min_percent' => 90.0, 'grade_label' => '5', 'order_index' => 0],
            ['min_percent' => 75.0, 'grade_label' => '4', 'order_index' => 1],
            ['min_percent' => 60.0, 'grade_label' => '3', 'order_index' => 2],
            ['min_percent' => 0.0, 'grade_label' => '2', 'order_index' => 3],
        ];
    }

    usort($normalized, static function (array $a, array $b): int {
        $cmp = ((float) $b['min_percent']) <=> ((float) $a['min_percent']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return ((int) $a['order_index']) <=> ((int) $b['order_index']);
    });

    $i = 0;
    foreach ($normalized as &$row) {
        $row['order_index'] = $i;
        $i++;
    }
    unset($row);

    return $normalized;
}

function labCheckNormalizeItemsPayload(PDO $db, string $labId, $itemsRaw): array
{
    $rows = is_array($itemsRaw) ? $itemsRaw : [];
    $nodeSet = labCheckLoadLabNodeSet($db, $labId);
    $normalized = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        if (count($normalized) >= LAB_CHECK_MAX_ITEMS) {
            break;
        }

        $nodeId = labCheckNormalizeUuid($row['node_id'] ?? '');
        if ($nodeId === '' || !isset($nodeSet[$nodeId])) {
            throw new InvalidArgumentException('Invalid node_id at item #' . ($index + 1));
        }

        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Title is required at item #' . ($index + 1));
        }
        if (strlen($title) > 255) {
            $title = substr($title, 0, 255);
        }

        $commandText = trim((string) ($row['command_text'] ?? ''));
        if ($commandText === '') {
            throw new InvalidArgumentException('Command is required at item #' . ($index + 1));
        }

        $expectedText = (string) ($row['expected_text'] ?? '');
        if (strlen($expectedText) > 12000) {
            $expectedText = substr($expectedText, 0, 12000);
        }

        $hintText = (string) ($row['hint_text'] ?? '');
        if (strlen($hintText) > 4000) {
            $hintText = substr($hintText, 0, 4000);
        }

        $points = isset($row['points']) ? (int) $row['points'] : 1;
        if ($points < 0) {
            $points = 0;
        }
        if ($points > 100000) {
            $points = 100000;
        }

        $timeout = isset($row['timeout_seconds']) ? (int) $row['timeout_seconds'] : 12;
        if ($timeout < 1) {
            $timeout = 1;
        }
        if ($timeout > 240) {
            $timeout = 240;
        }

        $transport = labCheckNormalizeTransport($row['transport'] ?? 'auto');
        $shellType = labCheckNormalizeShellType($row['shell_type'] ?? 'auto');
        $matchMode = labCheckNormalizeMatchMode($row['match_mode'] ?? 'contains');

        if (trim($expectedText) === '') {
            throw new InvalidArgumentException('Expected value is required at item #' . ($index + 1));
        }

        $sshHost = trim((string) ($row['ssh_host'] ?? ''));
        if (strlen($sshHost) > 255) {
            $sshHost = substr($sshHost, 0, 255);
        }
        $sshUsername = trim((string) ($row['ssh_username'] ?? ''));
        if (strlen($sshUsername) > 255) {
            $sshUsername = substr($sshUsername, 0, 255);
        }
        $sshPassword = (string) ($row['ssh_password'] ?? '');
        if (strlen($sshPassword) > 2048) {
            $sshPassword = substr($sshPassword, 0, 2048);
        }
        $sshPort = isset($row['ssh_port']) ? (int) $row['ssh_port'] : 22;
        if ($sshPort < 1 || $sshPort > 65535) {
            $sshPort = 22;
        }

        $normalized[] = [
            'node_id' => $nodeId,
            'title' => $title,
            'transport' => $transport,
            'shell_type' => $shellType,
            'command_text' => $commandText,
            'match_mode' => $matchMode,
            'expected_text' => $expectedText,
            'hint_text' => $hintText,
            'show_expected_to_learner' => labCheckBool($row['show_expected_to_learner'] ?? false),
            'show_output_to_learner' => labCheckBool($row['show_output_to_learner'] ?? false),
            'points' => $points,
            'timeout_seconds' => $timeout,
            'is_enabled' => labCheckBool($row['is_enabled'] ?? true),
            'order_index' => count($normalized),
            'ssh_host' => $sshHost,
            'ssh_port' => $sshPort,
            'ssh_username' => $sshUsername,
            'ssh_password' => $sshPassword,
        ];
    }

    return $normalized;
}

function labCheckSaveConfig(PDO $db, array $viewer, string $labId, array $payload): array
{
    labCheckEnsureViewerCanView($db, $viewer, $labId);
    if (!labCheckCanManage($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $viewerId = labCheckViewerId($viewer);

    $settingsRaw = is_array($payload['settings'] ?? null) ? (array) $payload['settings'] : [];
    $gradingEnabled = array_key_exists('grading_enabled', $settingsRaw)
        ? labCheckBool($settingsRaw['grading_enabled'])
        : true;
    $passPercent = isset($settingsRaw['pass_percent']) ? (float) $settingsRaw['pass_percent'] : 60.0;
    if (!is_finite($passPercent)) {
        $passPercent = 60.0;
    }
    if ($passPercent < 0.0) {
        $passPercent = 0.0;
    } elseif ($passPercent > 100.0) {
        $passPercent = 100.0;
    }

    $grades = labCheckNormalizeGradesPayload($payload['grades'] ?? []);
    $items = labCheckNormalizeItemsPayload($db, $labId, $payload['items'] ?? []);

    $db->beginTransaction();
    try {
        $upsertSettings = $db->prepare(
            "INSERT INTO lab_check_settings (lab_id, grading_enabled, pass_percent, updated_by, updated_at)
             VALUES (:lab_id, :grading_enabled, :pass_percent, :updated_by, NOW())
             ON CONFLICT (lab_id)
             DO UPDATE SET grading_enabled = EXCLUDED.grading_enabled,
                           pass_percent = EXCLUDED.pass_percent,
                           updated_by = EXCLUDED.updated_by,
                           updated_at = NOW()"
        );
        $upsertSettings->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $upsertSettings->bindValue(':grading_enabled', $gradingEnabled, PDO::PARAM_BOOL);
        $upsertSettings->bindValue(':pass_percent', round($passPercent, 2));
        if ($viewerId === '') {
            $upsertSettings->bindValue(':updated_by', null, PDO::PARAM_NULL);
        } else {
            $upsertSettings->bindValue(':updated_by', $viewerId, PDO::PARAM_STR);
        }
        $upsertSettings->execute();

        $delGrades = $db->prepare('DELETE FROM lab_check_grade_scales WHERE lab_id = :lab_id');
        $delGrades->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $delGrades->execute();

        $insGrade = $db->prepare(
            "INSERT INTO lab_check_grade_scales (lab_id, min_percent, grade_label, order_index)
             VALUES (:lab_id, :min_percent, :grade_label, :order_index)"
        );
        foreach ($grades as $grade) {
            $insGrade->bindValue(':lab_id', $labId, PDO::PARAM_STR);
            $insGrade->bindValue(':min_percent', round((float) ($grade['min_percent'] ?? 0.0), 2));
            $insGrade->bindValue(':grade_label', (string) ($grade['grade_label'] ?? ''), PDO::PARAM_STR);
            $insGrade->bindValue(':order_index', (int) ($grade['order_index'] ?? 0), PDO::PARAM_INT);
            $insGrade->execute();
        }

        $delItems = $db->prepare('DELETE FROM lab_check_items WHERE lab_id = :lab_id');
        $delItems->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $delItems->execute();

        $insItem = $db->prepare(
            "INSERT INTO lab_check_items (
                lab_id,
                node_id,
                title,
                transport,
                shell_type,
                command_text,
                match_mode,
                expected_text,
                hint_text,
                show_expected_to_learner,
                show_output_to_learner,
                points,
                timeout_seconds,
                is_enabled,
                order_index,
                ssh_host,
                ssh_port,
                ssh_username,
                ssh_password,
                created_by,
                updated_by,
                created_at,
                updated_at
            ) VALUES (
                :lab_id,
                :node_id,
                :title,
                :transport,
                :shell_type,
                :command_text,
                :match_mode,
                :expected_text,
                :hint_text,
                :show_expected_to_learner,
                :show_output_to_learner,
                :points,
                :timeout_seconds,
                :is_enabled,
                :order_index,
                :ssh_host,
                :ssh_port,
                :ssh_username,
                :ssh_password,
                :created_by,
                :updated_by,
                NOW(),
                NOW()
            )"
        );

        foreach ($items as $item) {
            $insItem->bindValue(':lab_id', $labId, PDO::PARAM_STR);
            $insItem->bindValue(':node_id', (string) $item['node_id'], PDO::PARAM_STR);
            $insItem->bindValue(':title', (string) $item['title'], PDO::PARAM_STR);
            $insItem->bindValue(':transport', (string) $item['transport'], PDO::PARAM_STR);
            $insItem->bindValue(':shell_type', (string) $item['shell_type'], PDO::PARAM_STR);
            $insItem->bindValue(':command_text', (string) $item['command_text'], PDO::PARAM_STR);
            $insItem->bindValue(':match_mode', (string) $item['match_mode'], PDO::PARAM_STR);
            $insItem->bindValue(':expected_text', (string) $item['expected_text'], PDO::PARAM_STR);
            $insItem->bindValue(':hint_text', (string) $item['hint_text'], PDO::PARAM_STR);
            $insItem->bindValue(':show_expected_to_learner', !empty($item['show_expected_to_learner']), PDO::PARAM_BOOL);
            $insItem->bindValue(':show_output_to_learner', !empty($item['show_output_to_learner']), PDO::PARAM_BOOL);
            $insItem->bindValue(':points', (int) $item['points'], PDO::PARAM_INT);
            $insItem->bindValue(':timeout_seconds', (int) $item['timeout_seconds'], PDO::PARAM_INT);
            $insItem->bindValue(':is_enabled', !empty($item['is_enabled']), PDO::PARAM_BOOL);
            $insItem->bindValue(':order_index', (int) $item['order_index'], PDO::PARAM_INT);

            $sshHost = trim((string) ($item['ssh_host'] ?? ''));
            $sshUsername = trim((string) ($item['ssh_username'] ?? ''));
            $sshPassword = (string) ($item['ssh_password'] ?? '');

            if ($sshHost === '') {
                $insItem->bindValue(':ssh_host', null, PDO::PARAM_NULL);
            } else {
                $insItem->bindValue(':ssh_host', $sshHost, PDO::PARAM_STR);
            }
            if ($sshHost === '' || $sshUsername === '') {
                $insItem->bindValue(':ssh_port', null, PDO::PARAM_NULL);
                $insItem->bindValue(':ssh_username', null, PDO::PARAM_NULL);
                $insItem->bindValue(':ssh_password', null, PDO::PARAM_NULL);
            } else {
                $insItem->bindValue(':ssh_port', (int) ($item['ssh_port'] ?? 22), PDO::PARAM_INT);
                $insItem->bindValue(':ssh_username', $sshUsername, PDO::PARAM_STR);
                if ($sshPassword === '') {
                    $insItem->bindValue(':ssh_password', null, PDO::PARAM_NULL);
                } else {
                    $insItem->bindValue(':ssh_password', $sshPassword, PDO::PARAM_STR);
                }
            }

            if ($viewerId === '') {
                $insItem->bindValue(':created_by', null, PDO::PARAM_NULL);
                $insItem->bindValue(':updated_by', null, PDO::PARAM_NULL);
            } else {
                $insItem->bindValue(':created_by', $viewerId, PDO::PARAM_STR);
                $insItem->bindValue(':updated_by', $viewerId, PDO::PARAM_STR);
            }

            $insItem->execute();
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    labCheckLog('OK', [
        'event' => 'lab_checks_saved',
        'lab_id' => $labId,
        'items_count' => count($items),
        'grades_count' => count($grades),
        'user_id' => $viewerId,
    ]);

    return labCheckListForViewer($db, $viewer, $labId);
}

function labCheckNormalizeText(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return trim($text);
}

function labCheckStripAnsi(string $text): string
{
    // Remove OSC sequences (for example: ESC ]0;R BEL) and CSI ANSI escapes.
    $clean = preg_replace('/\e\][^\a\x1b]*(?:\a|\e\\\\)/', '', $text);
    if (!is_string($clean)) {
        $clean = $text;
    }
    $clean = preg_replace('/\e\[[\d;?]*[ -\/]*[@-~]/', '', $clean);
    if (!is_string($clean)) {
        $clean = $text;
    }
    // Strip remaining control chars except LF/TAB/CR.
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);
    if (!is_string($clean)) {
        return $text;
    }
    return $clean;
}

function labCheckNormalizeLineEndings(string $text): string
{
    return str_replace(["\r\n", "\r"], "\n", $text);
}

function labCheckIsCommandEchoLine(string $line, string $commandLine): bool
{
    if ($line === '' || $commandLine === '') {
        return false;
    }
    if (hash_equals($line, $commandLine)) {
        return true;
    }
    if (strlen($line) < strlen($commandLine)) {
        return false;
    }
    if (substr($line, -strlen($commandLine)) !== $commandLine) {
        return false;
    }
    $prefix = rtrim((string) substr($line, 0, strlen($line) - strlen($commandLine)));
    if ($prefix === '') {
        return true;
    }
    return preg_match('/[>#$]\s*$/', $prefix) === 1;
}

function labCheckIsPromptOnlyLine(string $line): bool
{
    if ($line === '') {
        return false;
    }
    return preg_match('/^[[:alnum:]_.():@\/-]+(?:\([^)]+\))?[>#]\s*$/u', $line) === 1;
}

function labCheckNormalizeExecutionOutput(string $outputText, string $commandText): string
{
    $outputLines = explode("\n", labCheckNormalizeLineEndings($outputText));
    $commandLines = array_values(array_filter(array_map(static function ($line): string {
        return trim((string) $line);
    }, explode("\n", labCheckNormalizeLineEndings($commandText))), static function ($line): bool {
        return $line !== '';
    }));

    $commandCounts = [];
    foreach ($commandLines as $cmd) {
        $commandCounts[$cmd] = (int) ($commandCounts[$cmd] ?? 0) + 1;
    }

    $cleaned = [];
    foreach ($outputLines as $rawLine) {
        $noRight = preg_replace('/\s+$/u', '', (string) $rawLine);
        $noRight = is_string($noRight) ? $noRight : (string) $rawLine;
        $line = trim($noRight);
        if ($line === '') {
            continue;
        }

        $matched = '';
        foreach ($commandLines as $cmd) {
            if (labCheckIsCommandEchoLine($line, $cmd)) {
                $matched = $cmd;
                break;
            }
        }
        if ($matched !== '' && !empty($commandCounts[$matched])) {
            $commandCounts[$matched]--;
            continue;
        }

        if (labCheckIsPromptOnlyLine($line)) {
            continue;
        }

        $cleaned[] = $noRight;
    }

    return implode("\n", $cleaned);
}

function labCheckSafeSubstr(string $text, int $maxBytes = LAB_CHECK_MAX_OUTPUT_BYTES): string
{
    if ($maxBytes < 1) {
        $maxBytes = LAB_CHECK_MAX_OUTPUT_BYTES;
    }
    if (strlen($text) <= $maxBytes) {
        return $text;
    }
    return substr($text, 0, $maxBytes);
}

function labCheckDecodeStreamChunk(array $chunk): string
{
    $base64 = (string) ($chunk['chunk_base64'] ?? '');
    if ($base64 === '') {
        return '';
    }
    $decoded = base64_decode($base64, true);
    return is_string($decoded) ? $decoded : '';
}

function labCheckConsoleSessionsRootPath(): string
{
    if (function_exists('v2ConsoleSessionsRoot')) {
        return (string) v2ConsoleSessionsRoot();
    }
    return '/opt/unetlab/data/v2-console/sessions';
}

function labCheckQgaSocketPath(string $nodeId): string
{
    $clean = strtolower(trim($nodeId));
    $clean = preg_replace('/[^a-z0-9-]/', '', $clean);
    if (!is_string($clean) || $clean === '') {
        $clean = substr(hash('sha256', $nodeId), 0, 24);
    }
    return '/tmp/eve-v2-qga-' . $clean . '.sock';
}

function labCheckQgaSendMessage($socket, array $payload): bool
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

function labCheckQgaReadMessage($socket, string &$buffer, float $timeoutSec = 1.0): ?array
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
                    // Some guest-agent builds can prepend junk bytes (for example,
                    // replacement char after 0xFF delimiter). Keep JSON tail only.
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

function labCheckQgaRequest($socket, string &$buffer, string $execute, array $arguments = [], float $timeoutSec = 2.0): array
{
    $id = 'lc-' . bin2hex(random_bytes(4));
    $payload = [
        'execute' => $execute,
        'id' => $id,
    ];
    if (!empty($arguments)) {
        $payload['arguments'] = $arguments;
    }
    if (!labCheckQgaSendMessage($socket, $payload)) {
        return [
            'ok' => false,
            'error' => 'linux_agent_io_failed',
            'return' => null,
        ];
    }

    $deadline = microtime(true) + max(0.3, $timeoutSec);
    while (microtime(true) < $deadline) {
        $msg = labCheckQgaReadMessage($socket, $buffer, min(0.4, max(0.1, $deadline - microtime(true))));
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

function labCheckDecodeQgaOutput($value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    $decoded = base64_decode($raw, true);
    return is_string($decoded) ? $decoded : $raw;
}

function labCheckBuildLinuxAgentCommand(string $commandText): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $commandText);
    $parts = array_values(array_filter(array_map(static function ($line): string {
        return trim((string) $line);
    }, explode("\n", $normalized)), static function ($line): bool {
        return $line !== '';
    }));
    if (empty($parts)) {
        return '';
    }
    return implode('; ', $parts);
}

function labCheckExecuteViaLinuxGuestAgent(array $item): array
{
    $nodeId = labCheckNormalizeUuid($item['node_id'] ?? '');
    $timeout = isset($item['timeout_seconds']) ? (int) $item['timeout_seconds'] : 12;
    if ($timeout < 2) {
        $timeout = 2;
    }
    if ($timeout > 240) {
        $timeout = 240;
    }

    if ($nodeId === '') {
        return [
            'supported' => false,
            'ok' => false,
            'error' => 'linux_agent_unavailable',
            'output' => '',
            'duration_ms' => 0,
        ];
    }

    $qgaSocket = labCheckQgaSocketPath($nodeId);
    if (!file_exists($qgaSocket)) {
        return [
            'supported' => false,
            'ok' => false,
            'error' => 'linux_agent_unavailable',
            'output' => '',
            'duration_ms' => 0,
        ];
    }

    $command = labCheckBuildLinuxAgentCommand((string) ($item['command_text'] ?? ''));
    if ($command === '') {
        return [
            'supported' => true,
            'ok' => false,
            'error' => 'empty_command',
            'output' => '',
            'duration_ms' => 0,
        ];
    }

    $startedAt = microtime(true);
    $socket = @stream_socket_client(
        'unix://' . $qgaSocket,
        $errno,
        $errstr,
        min(3.0, max(1.0, $timeout * 0.25)),
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($socket)) {
        return [
            'supported' => false,
            'ok' => false,
            'error' => 'linux_agent_unavailable',
            'output' => '',
            'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
        ];
    }

    @stream_set_blocking($socket, false);
    @stream_set_write_buffer($socket, 0);
    $buffer = '';

    try {
        $syncId = random_int(1, PHP_INT_MAX);
        $sync = labCheckQgaRequest(
            $socket,
            $buffer,
            'guest-sync',
            ['id' => $syncId],
            min(3.0, max(1.0, $timeout * 0.35))
        );
        if (empty($sync['ok'])) {
            return [
                'supported' => false,
                'ok' => false,
                'error' => 'linux_agent_unavailable',
                'output' => '',
                'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
            ];
        }

        $exec = labCheckQgaRequest(
            $socket,
            $buffer,
            'guest-exec',
            [
                'path' => '/bin/sh',
                'arg' => ['-lc', $command],
                'capture-output' => true,
            ],
            min(4.0, max(1.2, $timeout * 0.4))
        );
        if (empty($exec['ok']) || !is_array($exec['return'])) {
            return [
                'supported' => true,
                'ok' => false,
                'error' => 'linux_agent_exec_failed',
                'output' => '',
                'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
            ];
        }

        $pid = isset($exec['return']['pid']) ? (int) $exec['return']['pid'] : 0;
        if ($pid < 1) {
            return [
                'supported' => true,
                'ok' => false,
                'error' => 'linux_agent_exec_failed',
                'output' => '',
                'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
            ];
        }

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $status = labCheckQgaRequest(
                $socket,
                $buffer,
                'guest-exec-status',
                ['pid' => $pid],
                min(2.0, max(0.3, $deadline - microtime(true)))
            );
            if (empty($status['ok']) || !is_array($status['return'])) {
                if (($status['error'] ?? '') === 'linux_agent_timeout') {
                    usleep(120000);
                    continue;
                }
                return [
                    'supported' => true,
                    'ok' => false,
                    'error' => 'linux_agent_exec_failed',
                    'output' => '',
                    'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
                ];
            }

            $ret = (array) $status['return'];
            if (!empty($ret['exited'])) {
                $stdout = labCheckDecodeQgaOutput($ret['out-data'] ?? '');
                $stderr = labCheckDecodeQgaOutput($ret['err-data'] ?? '');
                $output = $stdout;
                if ($stderr !== '') {
                    $output .= ($output !== '' ? "\n" : '') . $stderr;
                }
                return [
                    'supported' => true,
                    'ok' => true,
                    'error' => null,
                    'output' => labCheckSafeSubstr(labCheckStripAnsi($output)),
                    'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
                    'exit_code' => isset($ret['exitcode']) ? (int) $ret['exitcode'] : null,
                ];
            }
            usleep(140000);
        }

        return [
            'supported' => true,
            'ok' => false,
            'error' => 'linux_agent_timeout',
            'output' => '',
            'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
        ];
    } catch (Throwable $e) {
        return [
            'supported' => true,
            'ok' => false,
            'error' => 'linux_agent_exec_failed',
            'output' => '',
            'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
        ];
    } finally {
        if (is_resource($socket)) {
            @stream_socket_shutdown($socket, STREAM_SHUT_RDWR);
            @fclose($socket);
        }
    }
}

function labCheckConsoleLocksRootPath(): string
{
    if (function_exists('v2ConsoleDataRoot')) {
        return rtrim((string) v2ConsoleDataRoot(), '/') . '/check-locks';
    }
    return '/opt/unetlab/data/v2-console/check-locks';
}

function labCheckConsoleLockPath(string $labId, string $nodeId): string
{
    return rtrim(labCheckConsoleLocksRootPath(), '/') . '/' . strtolower($labId . '--' . $nodeId) . '.lock';
}

function labCheckParseTs($value): int
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return 0;
    }
    $ts = strtotime($raw);
    if (!is_int($ts) || $ts <= 0) {
        return 0;
    }
    return $ts;
}

function labCheckNodeHasActiveUserConsoleSession(string $labId, string $nodeId, int $maxAgeSec = 1800): bool
{
    $root = labCheckConsoleSessionsRootPath();
    if (!is_dir($root)) {
        return false;
    }

    $entries = @scandir($root);
    if (!is_array($entries) || empty($entries)) {
        return false;
    }

    $now = time();
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $metaPath = $root . '/' . $entry . '/meta.json';
        if (!is_file($metaPath)) {
            continue;
        }

        $raw = @file_get_contents($metaPath);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            continue;
        }

        if (!hash_equals(strtolower((string) ($meta['lab_id'] ?? '')), strtolower($labId))) {
            continue;
        }
        if (!hash_equals(strtolower((string) ($meta['node_id'] ?? '')), strtolower($nodeId))) {
            continue;
        }

        $status = strtolower(trim((string) ($meta['status'] ?? '')));
        if (in_array($status, ['closed', 'error'], true)) {
            continue;
        }

        $lastTs = max(
            labCheckParseTs($meta['last_client_activity_at'] ?? ''),
            labCheckParseTs($meta['updated_at'] ?? ''),
            labCheckParseTs($meta['created_at'] ?? '')
        );
        if ($lastTs <= 0) {
            $lastTs = (int) (@filemtime($metaPath) ?: 0);
        }
        if ($lastTs <= 0) {
            continue;
        }

        if (($now - $lastTs) <= max(30, $maxAgeSec)) {
            return true;
        }
    }

    return false;
}

function labCheckAcquireConsoleLock(string $labId, string $nodeId): bool
{
    $root = labCheckConsoleLocksRootPath();
    if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)) {
        return false;
    }
    $path = labCheckConsoleLockPath($labId, $nodeId);
    $payload = json_encode([
        'lab_id' => $labId,
        'node_id' => $nodeId,
        'created_at' => gmdate('c'),
        'pid' => function_exists('getmypid') ? (int) getmypid() : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || $payload === '') {
        $payload = '{"lab_id":"' . addslashes($labId) . '","node_id":"' . addslashes($nodeId) . '"}';
    }

    return @file_put_contents($path, $payload . "\n", LOCK_EX) !== false;
}

function labCheckReleaseConsoleLock(string $labId, string $nodeId): void
{
    $path = labCheckConsoleLockPath($labId, $nodeId);
    if (is_file($path)) {
        @unlink($path);
    }
}

function labCheckTelnetStateInit(): array
{
    return [
        'mode' => 'data',
        'neg_verb' => null,
    ];
}

function labCheckTelnetConsumeChunk(string $chunk, array &$state): array
{
    $out = '';
    $reply = '';
    $len = strlen($chunk);

    for ($i = 0; $i < $len; $i++) {
        $byte = ord($chunk[$i]);
        $mode = (string) ($state['mode'] ?? 'data');

        if ($mode === 'data') {
            if ($byte === 255) { // IAC
                $state['mode'] = 'iac';
                continue;
            }
            $out .= $chunk[$i];
            continue;
        }

        if ($mode === 'iac') {
            if ($byte === 255) {
                $out .= chr(255);
                $state['mode'] = 'data';
                continue;
            }
            if ($byte === 251 || $byte === 252 || $byte === 253 || $byte === 254) { // WILL/WONT/DO/DONT
                $state['neg_verb'] = $byte;
                $state['mode'] = 'neg_opt';
                continue;
            }
            if ($byte === 250) { // SB
                $state['mode'] = 'sb';
                continue;
            }
            $state['mode'] = 'data';
            $state['neg_verb'] = null;
            continue;
        }

        if ($mode === 'neg_opt') {
            $verb = (int) ($state['neg_verb'] ?? 0);
            $opt = $byte;
            if ($verb === 253 || $verb === 254) { // DO/DONT -> WONT
                $reply .= chr(255) . chr(252) . chr($opt);
            } elseif ($verb === 251 || $verb === 252) { // WILL/WONT -> DONT
                $reply .= chr(255) . chr(254) . chr($opt);
            }
            $state['mode'] = 'data';
            $state['neg_verb'] = null;
            continue;
        }

        if ($mode === 'sb') {
            if ($byte === 255) {
                $state['mode'] = 'sb_iac';
            }
            continue;
        }

        if ($mode === 'sb_iac') {
            if ($byte === 240) { // SE
                $state['mode'] = 'data';
            } elseif ($byte === 255) {
                $state['mode'] = 'sb';
            } else {
                $state['mode'] = 'sb';
            }
            continue;
        }

        $state['mode'] = 'data';
    }

    return [$out, $reply];
}

function labCheckSocketWriteBuffer($socket, string &$buffer): int
{
    if (!is_resource($socket) || $buffer === '') {
        return 0;
    }
    $written = @fwrite($socket, $buffer);
    if (!is_int($written) || $written <= 0) {
        return 0;
    }
    if ($written >= strlen($buffer)) {
        $buffer = '';
    } else {
        $buffer = (string) substr($buffer, $written);
    }
    return $written;
}

function labCheckCloseSocket(&$socket): void
{
    if (!is_resource($socket)) {
        $socket = null;
        return;
    }
    @stream_socket_shutdown($socket, STREAM_SHUT_RDWR);
    @fclose($socket);
    $socket = null;
}

function labCheckConsoleReadIdleOutput($socket, bool $telnetFilterEnabled, array &$telnetState, string &$replyBuffer, float $maxDurationSec = 0.45): string
{
    if (!is_resource($socket)) {
        return '';
    }

    $out = '';
    $deadline = microtime(true) + max(0.05, $maxDurationSec);
    $lastDataAt = microtime(true);

    while (microtime(true) < $deadline) {
        labCheckSocketWriteBuffer($socket, $replyBuffer);
        $chunk = @fread($socket, 65536);
        if (is_string($chunk) && $chunk !== '') {
            $decoded = $chunk;
            if ($telnetFilterEnabled) {
                [$decoded, $negReply] = labCheckTelnetConsumeChunk($chunk, $telnetState);
                if ($negReply !== '') {
                    $replyBuffer .= $negReply;
                }
            }
            if ($decoded !== '') {
                $out .= $decoded;
            }
            $lastDataAt = microtime(true);
            continue;
        }
        if ($chunk === '' && @feof($socket)) {
            break;
        }
        if ((microtime(true) - $lastDataAt) >= 0.12) {
            break;
        }
        usleep(25000);
    }

    return $out;
}

function labCheckConsoleSendRaw($socket, string $payload, string &$replyBuffer, float $timeoutSec = 1.0): bool
{
    if (!is_resource($socket) || $payload === '') {
        return true;
    }

    $buffer = $payload;
    $deadline = microtime(true) + max(0.2, $timeoutSec);
    while ($buffer !== '' && microtime(true) < $deadline) {
        labCheckSocketWriteBuffer($socket, $replyBuffer);
        labCheckSocketWriteBuffer($socket, $buffer);
        if ($buffer !== '') {
            usleep(20000);
        }
    }

    return $buffer === '';
}

function labCheckDetectCliPromptState(string $output): string
{
    $clean = labCheckNormalizeLineEndings(labCheckStripAnsi($output));
    $lines = explode("\n", $clean);

    for ($idx = count($lines) - 1; $idx >= 0; $idx--) {
        $line = trim((string) ($lines[$idx] ?? ''));
        if ($line === '') {
            continue;
        }
        if (preg_match('/\([^)]*config[^)]*\)#\s*$/i', $line) === 1) {
            return 'config';
        }
        if (preg_match('/[>#]\s*$/', $line) === 1) {
            $line = rtrim($line);
            return (substr($line, -1) === '>') ? 'user' : 'privileged';
        }
    }

    return 'unknown';
}

function labCheckNeedsUserPromptBootstrap(array $item): bool
{
    $nodeType = strtolower(trim((string) ($item['node_type'] ?? '')));
    if ($nodeType === 'iol') {
        return true;
    }

    $fingerprint = strtolower(trim(implode(' ', [
        (string) ($item['node_template'] ?? $item['template'] ?? ''),
        (string) ($item['node_image'] ?? $item['image'] ?? ''),
        (string) ($item['node_name'] ?? ''),
        $nodeType,
    ])));
    if ($fingerprint === '') {
        return false;
    }

    return preg_match('/\basa(v)?\b/i', $fingerprint) === 1;
}

function labCheckEnsureUserPromptForConsole($socket, bool $telnetFilterEnabled, array &$telnetState, string &$replyBuffer, int $timeoutSec, string $initialOutput = ''): void
{
    if (!is_resource($socket)) {
        return;
    }

    $state = labCheckDetectCliPromptState($initialOutput);
    $deadline = microtime(true) + min(3.5, max(1.2, $timeoutSec * 0.45));
    $steps = 0;

    if ($state === 'unknown') {
        labCheckConsoleSendRaw($socket, "\x03\r\n", $replyBuffer, 0.9);
        $state = labCheckDetectCliPromptState(
            labCheckConsoleReadIdleOutput($socket, $telnetFilterEnabled, $telnetState, $replyBuffer, 0.55)
        );
    }

    while ($state !== 'user' && $steps < 5 && microtime(true) < $deadline) {
        if ($state === 'config') {
            labCheckConsoleSendRaw($socket, "end\r\n", $replyBuffer, 0.9);
        } elseif ($state === 'privileged') {
            labCheckConsoleSendRaw($socket, "disable\r\n", $replyBuffer, 0.9);
        } else {
            labCheckConsoleSendRaw($socket, "\r\n", $replyBuffer, 0.6);
        }

        $state = labCheckDetectCliPromptState(
            labCheckConsoleReadIdleOutput($socket, $telnetFilterEnabled, $telnetState, $replyBuffer, 0.55)
        );
        $steps++;

        if ($state === 'unknown' && $steps === 2) {
            labCheckConsoleSendRaw($socket, "\x03\r\n", $replyBuffer, 0.9);
            $state = labCheckDetectCliPromptState(
                labCheckConsoleReadIdleOutput($socket, $telnetFilterEnabled, $telnetState, $replyBuffer, 0.55)
            );
        }
    }

    // Final prompt settle; output is intentionally ignored.
    labCheckConsoleSendRaw($socket, "\r\n", $replyBuffer, 0.6);
    labCheckConsoleReadIdleOutput($socket, $telnetFilterEnabled, $telnetState, $replyBuffer, 0.18);
}

function labCheckResolveRuntimeConsolePort(PDO $db, string $labId, array $item, bool $allowRuntimeFallback = true): int
{
    $checkPort = isset($item['runtime_check_console_port']) ? (int) $item['runtime_check_console_port'] : 0;
    if ($checkPort >= 1 && $checkPort <= 65535) {
        return $checkPort;
    }

    if ($allowRuntimeFallback) {
        $port = isset($item['runtime_console_port']) ? (int) $item['runtime_console_port'] : 0;
        if ($port >= 1 && $port <= 65535) {
            return $port;
        }
    }

    $nodeId = labCheckNormalizeUuid($item['node_id'] ?? '');
    if ($nodeId === '') {
        return 0;
    }

    $stmt = $db->prepare(
        "SELECT runtime_check_console_port,
                runtime_console_port
         FROM lab_nodes
         WHERE lab_id = :lab_id
           AND id = :node_id
         LIMIT 1"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return 0;
    }
    $checkPort = isset($row['runtime_check_console_port']) ? (int) $row['runtime_check_console_port'] : 0;
    if ($checkPort >= 1 && $checkPort <= 65535) {
        return $checkPort;
    }
    if ($allowRuntimeFallback) {
        $port = isset($row['runtime_console_port']) ? (int) $row['runtime_console_port'] : 0;
        return ($port >= 1 && $port <= 65535) ? $port : 0;
    }
    return 0;
}

function labCheckExecuteViaConsole(PDO $db, array $viewer, string $labId, array $item): array
{
    $nodeId = labCheckNormalizeUuid($item['node_id'] ?? '');
    if ($nodeId === '') {
        return [
            'ok' => false,
            'error' => 'node_not_found',
            'output' => '',
            'duration_ms' => 0,
        ];
    }

    $runtimePort = labCheckResolveRuntimeConsolePort($db, $labId, $item);
    if ($runtimePort < 1 || $runtimePort > 65535) {
        return [
            'ok' => false,
            'error' => 'console_port_missing',
            'output' => '',
            'duration_ms' => 0,
        ];
    }

    $consoleType = strtolower(trim((string) ($item['console'] ?? 'telnet')));
    if ($consoleType === '') {
        $consoleType = 'telnet';
    }

    $timeout = isset($item['timeout_seconds']) ? (int) $item['timeout_seconds'] : 12;
    if ($timeout < 1) {
        $timeout = 1;
    }
    if ($timeout > 240) {
        $timeout = 240;
    }

    $command = rtrim((string) ($item['command_text'] ?? ''), "\r\n");
    $commandPayload = trim(str_replace(["\r\n", "\r", "\n"], "\r\n", $command), "\r\n");
    if ($commandPayload === '') {
        return [
            'ok' => false,
            'error' => 'empty_command',
            'output' => '',
            'duration_ms' => 0,
        ];
    }

    $nodeShellProfile = labCheckResolveNodeShellProfile($item);
    $forceUserPromptBootstrap = ($nodeShellProfile === 'ios') && labCheckNeedsUserPromptBootstrap($item);
    $maxAttempts = ($nodeShellProfile === 'ios') ? 2 : 1;
    $retryDelayUsec = 150000;
    $output = '';
    $startedAt = microtime(true);
    $socket = null;
    $lockAcquired = false;
    $telnetFilterEnabled = ($consoleType === 'telnet' || $consoleType === '');
    $telnetState = labCheckTelnetStateInit();
    $replyBuffer = '';
    $commandBuffer = '';

    try {
        if (labCheckNodeHasActiveUserConsoleSession($labId, $nodeId, 1800)) {
            return [
                'ok' => false,
                'error' => 'console_in_use',
                'output' => '',
                'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
            ];
        }

        $lockAcquired = labCheckAcquireConsoleLock($labId, $nodeId);
        if (!$lockAcquired) {
            return [
                'ok' => false,
                'error' => 'console_lock_failed',
                'output' => '',
                'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
            ];
        }

        if (labCheckNodeHasActiveUserConsoleSession($labId, $nodeId, 1800)) {
            return [
                'ok' => false,
                'error' => 'console_in_use',
                'output' => '',
                'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
            ];
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $output = '';
            $warmupOutput = '';
            $telnetState = labCheckTelnetStateInit();
            $replyBuffer = '';
            $commandBuffer = "\r\n" . $commandPayload . "\r\n";
            $commandSentAt = null;

            $errno = 0;
            $errstr = '';
            $connectTimeout = min(5.0, max(1.0, (float) $timeout));
            $socket = @stream_socket_client(
                sprintf('tcp://127.0.0.1:%d', $runtimePort),
                $errno,
                $errstr,
                $connectTimeout,
                STREAM_CLIENT_CONNECT
            );
            if (!is_resource($socket)) {
                if ($attempt < $maxAttempts) {
                    usleep($retryDelayUsec);
                    continue;
                }
                return [
                    'ok' => false,
                    'error' => 'console_connect_failed',
                    'output' => '',
                    'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
                ];
            }

            @stream_set_blocking($socket, false);
            @stream_set_write_buffer($socket, 0);

            // Warm-up read to consume banners/prompts before sending command.
            $warmupDeadline = microtime(true) + ($nodeShellProfile === 'ios' ? 0.65 : 0.25);
            while (microtime(true) < $warmupDeadline) {
                $chunk = @fread($socket, 65536);
                if (is_string($chunk) && $chunk !== '') {
                    $decoded = $chunk;
                    if ($telnetFilterEnabled) {
                        [$decoded, $negReply] = labCheckTelnetConsumeChunk($chunk, $telnetState);
                        if ($negReply !== '') {
                            $replyBuffer .= $negReply;
                        }
                    }
                    if ($decoded !== '') {
                        $warmupOutput .= $decoded;
                    }
                } elseif ($chunk === '' && @feof($socket)) {
                    break;
                }
                labCheckSocketWriteBuffer($socket, $replyBuffer);
                usleep(25000);
            }

            if ($forceUserPromptBootstrap) {
                labCheckEnsureUserPromptForConsole(
                    $socket,
                    $telnetFilterEnabled,
                    $telnetState,
                    $replyBuffer,
                    $timeout,
                    $warmupOutput
                );
            }

            $deadline = microtime(true) + $timeout;
            $lastDataAt = microtime(true);
            $hasData = false;

            while (microtime(true) < $deadline) {
                labCheckSocketWriteBuffer($socket, $replyBuffer);
                labCheckSocketWriteBuffer($socket, $commandBuffer);
                if ($commandSentAt === null && $commandBuffer === '') {
                    $commandSentAt = microtime(true);
                }

                $chunk = @fread($socket, 65536);
                if (is_string($chunk) && $chunk !== '') {
                    $decoded = $chunk;
                    if ($telnetFilterEnabled) {
                        [$decoded, $negReply] = labCheckTelnetConsumeChunk($chunk, $telnetState);
                        if ($negReply !== '') {
                            $replyBuffer .= $negReply;
                        }
                    }

                    if ($decoded !== '') {
                        $output .= $decoded;
                    }
                    $hasData = true;
                    $lastDataAt = microtime(true);
                    if (strlen($output) > LAB_CHECK_MAX_OUTPUT_BYTES) {
                        break;
                    }
                    continue;
                }

                if ($chunk === '' && @feof($socket)) {
                    break;
                }

                $now = microtime(true);
                $canIdleBreak = ($commandSentAt !== null) && (($now - $commandSentAt) >= 0.2);
                if ($canIdleBreak && $hasData && ($now - $lastDataAt) >= 1.0) {
                    break;
                }
                usleep(50000);
            }

            labCheckCloseSocket($socket);

            if (trim($output) !== '') {
                $durationMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));
                return [
                    'ok' => true,
                    'error' => null,
                    'output' => labCheckSafeSubstr(labCheckStripAnsi($output)),
                    'duration_ms' => $durationMs,
                ];
            }

            if ($attempt < $maxAttempts) {
                usleep($retryDelayUsec);
            }
        }

        $durationMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));
        if (trim($output) === '') {
            $errorCode = 'console_no_output';
            if ($nodeShellProfile === 'linux' && in_array($consoleType, ['vnc', 'rdp'], true)) {
                $errorCode = 'linux_console_no_output';
            }
            return [
                'ok' => false,
                'error' => $errorCode,
                'output' => '',
                'duration_ms' => $durationMs,
            ];
        }
        return [
            'ok' => true,
            'error' => null,
            'output' => labCheckSafeSubstr(labCheckStripAnsi($output)),
            'duration_ms' => $durationMs,
        ];
    } catch (Throwable $e) {
        $durationMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));
        return [
            'ok' => false,
            'error' => trim((string) $e->getMessage()) !== '' ? trim((string) $e->getMessage()) : 'console_execution_failed',
            'output' => labCheckSafeSubstr(labCheckStripAnsi($output)),
            'duration_ms' => $durationMs,
        ];
    } finally {
        labCheckCloseSocket($socket);
        if ($lockAcquired) {
            labCheckReleaseConsoleLock($labId, $nodeId);
        }
    }
}

function labCheckRunPythonJson(array $payload, int $hardTimeoutSec = 30): array
{
    $pythonCode = <<<'PY'
import json
import sys

try:
    import pexpect
except Exception as e:
    print(json.dumps({"ok": False, "error": "pexpect_unavailable", "details": str(e)}))
    sys.exit(0)

raw = sys.stdin.read()
try:
    cfg = json.loads(raw if raw else "{}")
except Exception:
    print(json.dumps({"ok": False, "error": "invalid_payload"}))
    sys.exit(0)

host = str(cfg.get("host", "")).strip()
user = str(cfg.get("username", "")).strip()
password = str(cfg.get("password", ""))
command = str(cfg.get("command", ""))
key_path = str(cfg.get("key_path", "")).strip()

try:
    port = int(cfg.get("port", 22))
except Exception:
    port = 22
if port < 1 or port > 65535:
    port = 22

try:
    timeout = float(cfg.get("timeout", 12))
except Exception:
    timeout = 12.0
if timeout < 2:
    timeout = 2.0
if timeout > 240:
    timeout = 240.0

if not host or not user or not command:
    print(json.dumps({"ok": False, "error": "missing_ssh_fields"}))
    sys.exit(0)

args = [
    "-o", "StrictHostKeyChecking=no",
    "-o", "UserKnownHostsFile=/dev/null",
    "-o", "LogLevel=ERROR",
    "-o", "ConnectTimeout=" + str(int(min(timeout, 20))),
    "-p", str(port),
]
if key_path:
    args.extend(["-i", key_path])
args.append(user + "@" + host)
args.append(command)

child = None
try:
    child = pexpect.spawn("ssh", args, timeout=timeout, encoding="utf-8", codec_errors="ignore")
    sent_password = False
    out_parts = []

    while True:
        idx = child.expect([
            r"(?i)are you sure you want to continue connecting",
            r"(?i)password:\\s*$",
            pexpect.EOF,
            pexpect.TIMEOUT,
        ])
        before = child.before or ""
        if before:
            out_parts.append(before)

        if idx == 0:
            child.sendline("yes")
            continue
        if idx == 1:
            if sent_password:
                print(json.dumps({"ok": False, "error": "ssh_auth_failed", "output": "".join(out_parts)}))
                sys.exit(0)
            if not password:
                print(json.dumps({"ok": False, "error": "ssh_password_required", "output": "".join(out_parts)}))
                sys.exit(0)
            child.sendline(password)
            sent_password = True
            continue
        if idx == 2:
            print(json.dumps({
                "ok": True,
                "output": "".join(out_parts),
                "exit_code": child.exitstatus,
                "signal": child.signalstatus,
            }))
            sys.exit(0)
        if idx == 3:
            if child is not None:
                try:
                    child.close(force=True)
                except Exception:
                    pass
            print(json.dumps({"ok": False, "error": "ssh_timeout", "output": "".join(out_parts)}))
            sys.exit(0)
except Exception as e:
    partial = ""
    if child is not None:
        try:
            partial = child.before or ""
        except Exception:
            partial = ""
    print(json.dumps({"ok": False, "error": "ssh_execution_failed", "details": str(e), "output": partial}))
    sys.exit(0)
PY;

    $pythonBin = @is_executable('/usr/bin/python3') ? '/usr/bin/python3' : 'python3';
    $cmd = escapeshellarg($pythonBin) . ' -c ' . escapeshellarg($pythonCode);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
    if (!is_resource($proc)) {
        return [
            'ok' => false,
            'error' => 'python_spawn_failed',
            'output' => '',
        ];
    }

    $inputJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($inputJson)) {
        $inputJson = '{}';
    }
    fwrite($pipes[0], $inputJson);
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);

    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }

        if ((microtime(true) - $start) > $hardTimeoutSec) {
            @proc_terminate($proc, 9);
            break;
        }

        usleep(50000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    @proc_close($proc);

    $decoded = json_decode(trim($stdout), true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'error' => trim($stderr) !== '' ? trim($stderr) : 'python_output_invalid',
            'output' => $stdout,
        ];
    }

    return $decoded;
}

function labCheckExecuteViaSsh(array $item): array
{
    $host = trim((string) ($item['ssh_host'] ?? ''));
    $username = trim((string) ($item['ssh_username'] ?? ''));
    $command = trim((string) ($item['command_text'] ?? ''));
    $password = (string) ($item['ssh_password'] ?? '');
    $port = isset($item['ssh_port']) ? (int) $item['ssh_port'] : 22;
    $timeout = isset($item['timeout_seconds']) ? (int) $item['timeout_seconds'] : 12;

    if ($host === '' || $username === '') {
        return [
            'ok' => false,
            'error' => 'ssh_host_or_username_missing',
            'output' => '',
            'duration_ms' => 0,
        ];
    }
    if ($command === '') {
        return [
            'ok' => false,
            'error' => 'empty_command',
            'output' => '',
            'duration_ms' => 0,
        ];
    }

    if ($port < 1 || $port > 65535) {
        $port = 22;
    }
    if ($timeout < 1) {
        $timeout = 1;
    }
    if ($timeout > 240) {
        $timeout = 240;
    }

    $startedAt = microtime(true);
    $result = labCheckRunPythonJson([
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'password' => $password,
        'command' => $command,
        'timeout' => $timeout,
    ], $timeout + 8);

    $durationMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));
    $output = labCheckSafeSubstr(labCheckStripAnsi((string) ($result['output'] ?? '')));

    if (!empty($result['ok'])) {
        return [
            'ok' => true,
            'error' => null,
            'output' => $output,
            'duration_ms' => $durationMs,
            'exit_code' => isset($result['exit_code']) ? (int) $result['exit_code'] : null,
        ];
    }

    $error = trim((string) ($result['error'] ?? 'ssh_execution_failed'));
    if ($error === '') {
        $error = 'ssh_execution_failed';
    }

    return [
        'ok' => false,
        'error' => $error,
        'output' => $output,
        'duration_ms' => $durationMs,
    ];
}

function labCheckEvaluateOutput(string $mode, string $outputRaw, string $expectedRaw): array
{
    $mode = labCheckNormalizeMatchMode($mode);
    $output = labCheckNormalizeText($outputRaw);
    $expected = labCheckNormalizeText($expectedRaw);

    if ($expected === '') {
        return [
            'ok' => false,
            'passed' => false,
            'error' => 'expected_empty',
        ];
    }

    if ($mode === 'equals') {
        return [
            'ok' => true,
            'passed' => hash_equals($expected, $output),
            'error' => null,
        ];
    }

    if ($mode === 'contains') {
        return [
            'ok' => true,
            'passed' => stripos($output, $expected) !== false,
            'error' => null,
        ];
    }

    if ($mode === 'not_contains') {
        return [
            'ok' => true,
            'passed' => stripos($output, $expected) === false,
            'error' => null,
        ];
    }

    $pattern = $expected;
    if (!preg_match('/^([#~\/|]).*\1[imsxuADSUXJ]*$/', $pattern)) {
        $pattern = '~' . $pattern . '~u';
    }

    $test = @preg_match($pattern, '');
    if ($test === false) {
        return [
            'ok' => false,
            'passed' => false,
            'error' => 'invalid_regex',
        ];
    }

    $matched = @preg_match($pattern, $output);
    return [
        'ok' => true,
        'passed' => $matched === 1,
        'error' => null,
    ];
}

function labCheckResolveTransport(array $item): string
{
    $transport = labCheckNormalizeTransport($item['transport'] ?? 'auto');
    $host = trim((string) ($item['ssh_host'] ?? ''));
    $user = trim((string) ($item['ssh_username'] ?? ''));
    if ($transport === 'ssh') {
        if ($host !== '' && $user !== '') {
            return 'ssh';
        }
        return 'console';
    }
    if ($transport === 'auto' && $host !== '' && $user !== '') {
        return 'ssh';
    }
    return 'console';
}

function labCheckRunItem(PDO $db, array $viewer, string $labId, array $item): array
{
    $transport = labCheckResolveTransport($item);
    $effectiveShellType = labCheckResolveEffectiveShellType($item);
    $nodeShellProfile = labCheckResolveNodeShellProfile($item);
    if ($transport === 'ssh' && ($nodeShellProfile === 'ios' || $effectiveShellType === 'ios')) {
        $transport = 'console';
    }
    $isRunning = !empty($item['is_running']) || strtolower((string) ($item['power_state'] ?? '')) === 'running';
    $consoleType = strtolower(trim((string) ($item['console'] ?? 'telnet')));

    if (!$isRunning) {
        return [
            'status' => 'error',
            'is_passed' => false,
            'output_text' => '',
            'error_text' => 'node_not_running',
            'duration_ms' => 0,
            'transport' => $transport,
            'shell_type' => $effectiveShellType,
            'earned_points' => 0,
        ];
    }

    if ($transport === 'console' && in_array($consoleType, ['vnc', 'rdp'], true)) {
        $hiddenConsolePort = labCheckResolveRuntimeConsolePort($db, $labId, $item, false);
        if ($hiddenConsolePort >= 1 && $hiddenConsolePort <= 65535) {
            $item['runtime_check_console_port'] = $hiddenConsolePort;
        } elseif ($nodeShellProfile !== 'linux') {
            return [
                'status' => 'error',
                'is_passed' => false,
                'output_text' => '',
                'error_text' => 'console_not_text_mode',
                'duration_ms' => 0,
                'transport' => $transport,
                'shell_type' => $effectiveShellType,
                'earned_points' => 0,
            ];
        }
    }

    if (!labCheckShellTypeAllowedForProfile($effectiveShellType, $nodeShellProfile)) {
        return [
            'status' => 'error',
            'is_passed' => false,
            'output_text' => '',
            'error_text' => 'shell_type_not_supported_for_node',
            'duration_ms' => 0,
            'transport' => $transport,
            'shell_type' => $effectiveShellType,
            'earned_points' => 0,
        ];
    }

    $execItem = $item;
    $execItem['command_text'] = labCheckNormalizeCommandByShell(
        (string) ($item['command_text'] ?? ''),
        $effectiveShellType,
        $transport
    );

    $execResult = null;
    $linuxAgentSupported = false;
    if ($transport === 'console' && $nodeShellProfile === 'linux') {
        $agentResult = labCheckExecuteViaLinuxGuestAgent($execItem);
        if (!empty($agentResult['supported'])) {
            $linuxAgentSupported = true;
            $execResult = $agentResult;
        }
    }
    if (!is_array($execResult)) {
        $execResult = ($transport === 'ssh')
            ? labCheckExecuteViaSsh($execItem)
            : labCheckExecuteViaConsole($db, $viewer, $labId, $execItem);
    }
    if ($transport === 'console' && $nodeShellProfile === 'linux' && !$linuxAgentSupported && empty($execResult['ok'])) {
        $execError = strtolower(trim((string) ($execResult['error'] ?? '')));
        if (in_array($execError, ['console_connect_failed', 'console_port_missing', 'console_not_text_mode', 'console_no_output', 'linux_console_no_output'], true)) {
            $execResult['error'] = 'linux_agent_unavailable';
        }
    }

    $outputText = labCheckSafeSubstr((string) ($execResult['output'] ?? ''));
    $outputText = labCheckNormalizeExecutionOutput(
        $outputText,
        (string) ($execItem['command_text'] ?? '')
    );
    $durationMs = isset($execResult['duration_ms']) ? (int) $execResult['duration_ms'] : 0;

    if (empty($execResult['ok'])) {
        return [
            'status' => 'error',
            'is_passed' => false,
            'output_text' => $outputText,
            'error_text' => (string) ($execResult['error'] ?? 'execution_failed'),
            'duration_ms' => $durationMs,
            'transport' => $transport,
            'shell_type' => $effectiveShellType,
            'earned_points' => 0,
        ];
    }

    $eval = labCheckEvaluateOutput(
        (string) ($item['match_mode'] ?? 'contains'),
        $outputText,
        (string) ($item['expected_text'] ?? '')
    );

    if (empty($eval['ok'])) {
        return [
            'status' => 'error',
            'is_passed' => false,
            'output_text' => $outputText,
            'error_text' => (string) ($eval['error'] ?? 'match_failed'),
            'duration_ms' => $durationMs,
            'transport' => $transport,
            'shell_type' => $effectiveShellType,
            'earned_points' => 0,
        ];
    }

    $passed = !empty($eval['passed']);
    $points = isset($item['points']) ? (int) $item['points'] : 0;
    if ($points < 0) {
        $points = 0;
    }

    return [
        'status' => $passed ? 'passed' : 'failed',
        'is_passed' => $passed,
        'output_text' => $outputText,
        'error_text' => null,
        'duration_ms' => $durationMs,
        'transport' => $transport,
        'shell_type' => $effectiveShellType,
        'earned_points' => $passed ? $points : 0,
    ];
}

function labCheckPickGradeLabel(array $grades, float $scorePercent): ?string
{
    if (empty($grades)) {
        return null;
    }
    foreach ($grades as $grade) {
        $minPercent = isset($grade['min_percent']) ? (float) $grade['min_percent'] : 0.0;
        if ($scorePercent >= $minPercent) {
            $label = trim((string) ($grade['grade_label'] ?? ''));
            return $label === '' ? null : $label;
        }
    }
    return null;
}

function labCheckGetRunRow(PDO $db, string $labId, string $runId): ?array
{
    $stmt = $db->prepare(
        "SELECT id,
                lab_id,
                started_by,
                started_by_username,
                status,
                total_items,
                passed_items,
                failed_items,
                error_items,
                total_points,
                earned_points,
                score_percent,
                grade_label,
                started_at,
                finished_at,
                duration_ms
         FROM lab_check_runs
         WHERE id = :run_id
           AND lab_id = :lab_id
         LIMIT 1"
    );
    $stmt->bindValue(':run_id', $runId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function labCheckSanitizeRunItemForViewer(array $row, bool $canManage): array
{
    $showExpected = $canManage || !empty($row['show_expected_to_learner']);
    $showOutput = $canManage || !empty($row['show_output_to_learner']);

    return [
        'id' => (string) ($row['id'] ?? ''),
        'check_item_id' => isset($row['check_item_id']) ? (string) $row['check_item_id'] : null,
        'node_id' => isset($row['node_id']) ? (string) $row['node_id'] : null,
        'node_name' => (string) ($row['node_name'] ?? ''),
        'check_title' => (string) ($row['check_title'] ?? ''),
        'status' => (string) ($row['status'] ?? 'failed'),
        'is_passed' => !empty($row['is_passed']),
        'points' => isset($row['points']) ? (int) $row['points'] : 0,
        'earned_points' => isset($row['earned_points']) ? (int) $row['earned_points'] : 0,
        'hint_text' => (string) ($row['hint_text'] ?? ''),
        'transport' => (string) ($row['transport'] ?? 'auto'),
        'output_text' => $showOutput ? (string) ($row['output_text'] ?? '') : null,
        'expected_text' => $showExpected ? (string) ($row['expected_text'] ?? '') : null,
        'show_output_to_learner' => !empty($row['show_output_to_learner']),
        'show_expected_to_learner' => !empty($row['show_expected_to_learner']),
        'error_text' => isset($row['error_text']) ? (string) $row['error_text'] : null,
        'duration_ms' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : 0,
        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        'command_text' => $canManage ? (string) ($row['command_text'] ?? '') : null,
        'match_mode' => $canManage ? (string) ($row['match_mode'] ?? '') : null,
        'shell_type' => $canManage ? (string) ($row['shell_type'] ?? '') : null,
    ];
}

function labCheckBuildRunSummary(array $row): array
{
    return [
        'id' => (string) ($row['id'] ?? ''),
        'lab_id' => (string) ($row['lab_id'] ?? ''),
        'started_by' => isset($row['started_by']) ? (string) $row['started_by'] : null,
        'started_by_username' => (string) ($row['started_by_username'] ?? ''),
        'status' => (string) ($row['status'] ?? 'running'),
        'total_items' => isset($row['total_items']) ? (int) $row['total_items'] : 0,
        'passed_items' => isset($row['passed_items']) ? (int) $row['passed_items'] : 0,
        'failed_items' => isset($row['failed_items']) ? (int) $row['failed_items'] : 0,
        'error_items' => isset($row['error_items']) ? (int) $row['error_items'] : 0,
        'total_points' => isset($row['total_points']) ? (int) $row['total_points'] : 0,
        'earned_points' => isset($row['earned_points']) ? (int) $row['earned_points'] : 0,
        'score_percent' => isset($row['score_percent']) ? (float) $row['score_percent'] : 0.0,
        'grade_label' => isset($row['grade_label']) ? (string) $row['grade_label'] : null,
        'started_at' => isset($row['started_at']) ? (string) $row['started_at'] : null,
        'finished_at' => isset($row['finished_at']) ? (string) $row['finished_at'] : null,
        'duration_ms' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : 0,
    ];
}

function labCheckRunForViewer(PDO $db, array $viewer, string $labId): array
{
    labCheckEnsureViewerCanView($db, $viewer, $labId);

    $viewerId = labCheckViewerId($viewer);
    $viewerUsername = trim((string) ($viewer['username'] ?? ''));

    if (function_exists('refreshLabRuntimeStatesForLab')) {
        try {
            refreshLabRuntimeStatesForLab($db, $labId);
        } catch (Throwable $e) {
            // Fallback to stored runtime state if refresh is not available.
        }
    }

    $items = labCheckLoadItemsRaw($db, $labId, true);
    if (empty($items)) {
        throw new RuntimeException('No checks configured');
    }

    $grades = labCheckLoadGrades($db, $labId);
    if (empty($grades)) {
        $grades = labCheckNormalizeGradesPayload([]);
    }

    $configuredTotalItems = count($items);
    $configuredTotalPoints = 0;
    foreach ($items as $it) {
        $pts = isset($it['points']) ? (int) $it['points'] : 0;
        if ($pts < 0) {
            $pts = 0;
        }
        $configuredTotalPoints += $pts;
    }

    $runId = '';
    $startedAt = microtime(true);
    $passedItems = 0;
    $failedItems = 0;
    $errorItems = 0;
    $earnedPoints = 0;

    $insertRun = $db->prepare(
        "INSERT INTO lab_check_runs (
            lab_id,
            started_by,
            started_by_username,
            status,
            started_at,
            total_items,
            passed_items,
            failed_items,
            error_items,
            total_points,
            earned_points,
            score_percent,
            grade_label,
            duration_ms
        ) VALUES (
            :lab_id,
            :started_by,
            :started_by_username,
            'running',
            NOW(),
            :total_items,
            0,
            0,
            0,
            :total_points,
            0,
            0,
            NULL,
            0
        )
        RETURNING id"
    );
    $insertRun->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    if ($viewerId === '') {
        $insertRun->bindValue(':started_by', null, PDO::PARAM_NULL);
    } else {
        $insertRun->bindValue(':started_by', $viewerId, PDO::PARAM_STR);
    }
    $insertRun->bindValue(':started_by_username', $viewerUsername, PDO::PARAM_STR);
    $insertRun->bindValue(':total_items', $configuredTotalItems, PDO::PARAM_INT);
    $insertRun->bindValue(':total_points', $configuredTotalPoints, PDO::PARAM_INT);
    $insertRun->execute();

    $runRow = $insertRun->fetch(PDO::FETCH_ASSOC);
    if ($runRow === false || empty($runRow['id'])) {
        throw new RuntimeException('Failed to create run');
    }
    $runId = (string) $runRow['id'];

    $insertRunItem = $db->prepare(
        "INSERT INTO lab_check_run_items (
            run_id,
            lab_id,
            check_item_id,
            node_id,
            node_name,
            check_title,
            transport,
            shell_type,
            command_text,
            expected_text,
            match_mode,
            hint_text,
            show_expected_to_learner,
            show_output_to_learner,
            status,
            is_passed,
            points,
            earned_points,
            output_text,
            error_text,
            duration_ms,
            created_at
        ) VALUES (
            :run_id,
            :lab_id,
            :check_item_id,
            :node_id,
            :node_name,
            :check_title,
            :transport,
            :shell_type,
            :command_text,
            :expected_text,
            :match_mode,
            :hint_text,
            :show_expected_to_learner,
            :show_output_to_learner,
            :status,
            :is_passed,
            :points,
            :earned_points,
            :output_text,
            :error_text,
            :duration_ms,
            NOW()
        )"
    );

    $updateRunProgress = $db->prepare(
        "UPDATE lab_check_runs
         SET status = 'running',
             total_items = :total_items,
             passed_items = :passed_items,
             failed_items = :failed_items,
             error_items = :error_items,
             total_points = :total_points,
             earned_points = :earned_points,
             score_percent = :score_percent,
             grade_label = :grade_label,
             duration_ms = :duration_ms
         WHERE id = :run_id"
    );

    $updateRunFinal = $db->prepare(
        "UPDATE lab_check_runs
         SET status = :status,
             total_items = :total_items,
             passed_items = :passed_items,
             failed_items = :failed_items,
             error_items = :error_items,
             total_points = :total_points,
             earned_points = :earned_points,
             score_percent = :score_percent,
             grade_label = :grade_label,
             finished_at = NOW(),
             duration_ms = :duration_ms
         WHERE id = :run_id"
    );

    try {
        foreach ($items as $item) {
            $points = isset($item['points']) ? (int) $item['points'] : 0;
            if ($points < 0) {
                $points = 0;
            }

            $itemStartedAt = microtime(true);
            try {
                $result = labCheckRunItem($db, $viewer, $labId, $item);
            } catch (Throwable $itemError) {
                $msg = trim((string) $itemError->getMessage());
                $result = [
                    'status' => 'error',
                    'is_passed' => false,
                    'earned_points' => 0,
                    'transport' => (string) ($item['transport'] ?? 'auto'),
                    'shell_type' => (string) ($item['shell_type'] ?? 'auto'),
                    'output_text' => '',
                    'error_text' => $msg !== '' ? $msg : 'execution_failed',
                    'duration_ms' => (int) max(0, round((microtime(true) - $itemStartedAt) * 1000)),
                ];
            }

            $status = (string) ($result['status'] ?? 'error');
            if ($status === 'passed') {
                $passedItems++;
            } elseif ($status === 'failed') {
                $failedItems++;
            } else {
                $errorItems++;
            }

            $earned = isset($result['earned_points']) ? (int) $result['earned_points'] : 0;
            if ($earned < 0) {
                $earned = 0;
            }
            if ($earned > $points) {
                $earned = $points;
            }
            $earnedPoints += $earned;

            $insertRunItem->bindValue(':run_id', $runId, PDO::PARAM_STR);
            $insertRunItem->bindValue(':lab_id', $labId, PDO::PARAM_STR);
            $insertRunItem->bindValue(':check_item_id', (string) ($item['id'] ?? ''), PDO::PARAM_STR);
            $nodeId = labCheckNormalizeUuid($item['node_id'] ?? '');
            if ($nodeId === '') {
                $insertRunItem->bindValue(':node_id', null, PDO::PARAM_NULL);
            } else {
                $insertRunItem->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
            }
            $insertRunItem->bindValue(':node_name', (string) ($item['node_name'] ?? ''), PDO::PARAM_STR);
            $insertRunItem->bindValue(':check_title', (string) ($item['title'] ?? ''), PDO::PARAM_STR);
            $insertRunItem->bindValue(':transport', (string) ($result['transport'] ?? $item['transport'] ?? 'auto'), PDO::PARAM_STR);
            $insertRunItem->bindValue(':shell_type', (string) ($result['shell_type'] ?? $item['shell_type'] ?? 'auto'), PDO::PARAM_STR);
            $insertRunItem->bindValue(':command_text', (string) ($item['command_text'] ?? ''), PDO::PARAM_STR);
            $insertRunItem->bindValue(':expected_text', (string) ($item['expected_text'] ?? ''), PDO::PARAM_STR);
            $insertRunItem->bindValue(':match_mode', (string) ($item['match_mode'] ?? 'contains'), PDO::PARAM_STR);
            $insertRunItem->bindValue(':hint_text', (string) ($item['hint_text'] ?? ''), PDO::PARAM_STR);
            $insertRunItem->bindValue(':show_expected_to_learner', !empty($item['show_expected_to_learner']), PDO::PARAM_BOOL);
            $insertRunItem->bindValue(':show_output_to_learner', !empty($item['show_output_to_learner']), PDO::PARAM_BOOL);
            $insertRunItem->bindValue(':status', $status, PDO::PARAM_STR);
            $insertRunItem->bindValue(':is_passed', !empty($result['is_passed']), PDO::PARAM_BOOL);
            $insertRunItem->bindValue(':points', $points, PDO::PARAM_INT);
            $insertRunItem->bindValue(':earned_points', $earned, PDO::PARAM_INT);

            $outputText = (string) ($result['output_text'] ?? '');
            if ($outputText === '') {
                $insertRunItem->bindValue(':output_text', null, PDO::PARAM_NULL);
            } else {
                $insertRunItem->bindValue(':output_text', labCheckSafeSubstr($outputText), PDO::PARAM_STR);
            }

            $errorText = isset($result['error_text']) ? trim((string) $result['error_text']) : '';
            if ($errorText === '') {
                $insertRunItem->bindValue(':error_text', null, PDO::PARAM_NULL);
            } else {
                $insertRunItem->bindValue(':error_text', labCheckSafeSubstr($errorText), PDO::PARAM_STR);
            }

            $insertRunItem->bindValue(':duration_ms', isset($result['duration_ms']) ? (int) $result['duration_ms'] : 0, PDO::PARAM_INT);
            $insertRunItem->execute();

            $scorePercent = $configuredTotalPoints > 0
                ? round(($earnedPoints / $configuredTotalPoints) * 100.0, 2)
                : 0.0;
            if ($scorePercent < 0.0) {
                $scorePercent = 0.0;
            }
            if ($scorePercent > 100.0) {
                $scorePercent = 100.0;
            }
            $gradeLabel = labCheckPickGradeLabel($grades, $scorePercent);
            $durationMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));

            $updateRunProgress->bindValue(':total_items', $configuredTotalItems, PDO::PARAM_INT);
            $updateRunProgress->bindValue(':passed_items', $passedItems, PDO::PARAM_INT);
            $updateRunProgress->bindValue(':failed_items', $failedItems, PDO::PARAM_INT);
            $updateRunProgress->bindValue(':error_items', $errorItems, PDO::PARAM_INT);
            $updateRunProgress->bindValue(':total_points', $configuredTotalPoints, PDO::PARAM_INT);
            $updateRunProgress->bindValue(':earned_points', $earnedPoints, PDO::PARAM_INT);
            $updateRunProgress->bindValue(':score_percent', $scorePercent);
            if ($gradeLabel === null || trim($gradeLabel) === '') {
                $updateRunProgress->bindValue(':grade_label', null, PDO::PARAM_NULL);
            } else {
                $updateRunProgress->bindValue(':grade_label', $gradeLabel, PDO::PARAM_STR);
            }
            $updateRunProgress->bindValue(':duration_ms', $durationMs, PDO::PARAM_INT);
            $updateRunProgress->bindValue(':run_id', $runId, PDO::PARAM_STR);
            $updateRunProgress->execute();
        }

        $scorePercent = $configuredTotalPoints > 0
            ? round(($earnedPoints / $configuredTotalPoints) * 100.0, 2)
            : 0.0;
        if ($scorePercent < 0.0) {
            $scorePercent = 0.0;
        }
        if ($scorePercent > 100.0) {
            $scorePercent = 100.0;
        }
        $gradeLabel = labCheckPickGradeLabel($grades, $scorePercent);
        $durationMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));

        $finalStatus = 'completed';
        if ($errorItems > 0 && $passedItems === 0 && $failedItems === 0) {
            $finalStatus = 'failed';
        }

        $updateRunFinal->bindValue(':status', $finalStatus, PDO::PARAM_STR);
        $updateRunFinal->bindValue(':total_items', $configuredTotalItems, PDO::PARAM_INT);
        $updateRunFinal->bindValue(':passed_items', $passedItems, PDO::PARAM_INT);
        $updateRunFinal->bindValue(':failed_items', $failedItems, PDO::PARAM_INT);
        $updateRunFinal->bindValue(':error_items', $errorItems, PDO::PARAM_INT);
        $updateRunFinal->bindValue(':total_points', $configuredTotalPoints, PDO::PARAM_INT);
        $updateRunFinal->bindValue(':earned_points', $earnedPoints, PDO::PARAM_INT);
        $updateRunFinal->bindValue(':score_percent', $scorePercent);
        if ($gradeLabel === null || trim($gradeLabel) === '') {
            $updateRunFinal->bindValue(':grade_label', null, PDO::PARAM_NULL);
        } else {
            $updateRunFinal->bindValue(':grade_label', $gradeLabel, PDO::PARAM_STR);
        }
        $updateRunFinal->bindValue(':duration_ms', $durationMs, PDO::PARAM_INT);
        $updateRunFinal->bindValue(':run_id', $runId, PDO::PARAM_STR);
        $updateRunFinal->execute();
    } catch (Throwable $e) {
        if ($runId !== '') {
            try {
                $scorePercent = $configuredTotalPoints > 0
                    ? round(($earnedPoints / $configuredTotalPoints) * 100.0, 2)
                    : 0.0;
                if ($scorePercent < 0.0) {
                    $scorePercent = 0.0;
                }
                if ($scorePercent > 100.0) {
                    $scorePercent = 100.0;
                }
                $gradeLabel = labCheckPickGradeLabel($grades, $scorePercent);
                $durationMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));

                $updateRunFinal->bindValue(':status', 'failed', PDO::PARAM_STR);
                $updateRunFinal->bindValue(':total_items', $configuredTotalItems, PDO::PARAM_INT);
                $updateRunFinal->bindValue(':passed_items', $passedItems, PDO::PARAM_INT);
                $updateRunFinal->bindValue(':failed_items', $failedItems, PDO::PARAM_INT);
                $updateRunFinal->bindValue(':error_items', $errorItems, PDO::PARAM_INT);
                $updateRunFinal->bindValue(':total_points', $configuredTotalPoints, PDO::PARAM_INT);
                $updateRunFinal->bindValue(':earned_points', $earnedPoints, PDO::PARAM_INT);
                $updateRunFinal->bindValue(':score_percent', $scorePercent);
                if ($gradeLabel === null || trim($gradeLabel) === '') {
                    $updateRunFinal->bindValue(':grade_label', null, PDO::PARAM_NULL);
                } else {
                    $updateRunFinal->bindValue(':grade_label', $gradeLabel, PDO::PARAM_STR);
                }
                $updateRunFinal->bindValue(':duration_ms', $durationMs, PDO::PARAM_INT);
                $updateRunFinal->bindValue(':run_id', $runId, PDO::PARAM_STR);
                $updateRunFinal->execute();
            } catch (Throwable $ignored) {
                // Ignore forced-finalization failures: original error is more relevant.
            }
        }
        throw $e;
    }

    labCheckLog('OK', [
        'event' => 'lab_checks_run',
        'lab_id' => $labId,
        'run_id' => $runId,
        'user_id' => $viewerId,
        'user' => $viewerUsername,
    ]);

    return labCheckGetRunForViewer($db, $viewer, $labId, $runId);
}

function labCheckListRunsForViewer(PDO $db, array $viewer, string $labId, int $limit = 30): array
{
    labCheckEnsureViewerCanView($db, $viewer, $labId);
    $canManage = labCheckCanManage($db, $viewer, $labId);
    $viewerId = labCheckViewerId($viewer);

    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 300) {
        $limit = 300;
    }

    if ($canManage) {
        $stmt = $db->prepare(
            "SELECT id,
                    lab_id,
                    started_by,
                    started_by_username,
                    status,
                    total_items,
                    passed_items,
                    failed_items,
                    error_items,
                    total_points,
                    earned_points,
                    score_percent,
                    grade_label,
                    started_at,
                    finished_at,
                    duration_ms
             FROM lab_check_runs
             WHERE lab_id = :lab_id
             ORDER BY started_at DESC, id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    } else {
        $stmt = $db->prepare(
            "SELECT id,
                    lab_id,
                    started_by,
                    started_by_username,
                    status,
                    total_items,
                    passed_items,
                    failed_items,
                    error_items,
                    total_points,
                    earned_points,
                    score_percent,
                    grade_label,
                    started_at,
                    finished_at,
                    duration_ms
             FROM lab_check_runs
             WHERE lab_id = :lab_id
               AND started_by = :viewer_id
             ORDER BY started_at DESC, id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    return [
        'lab_id' => $labId,
        'can_manage' => $canManage,
        'runs' => array_map('labCheckBuildRunSummary', $rows),
    ];
}

function labCheckGetRunForViewer(PDO $db, array $viewer, string $labId, string $runId): array
{
    labCheckEnsureViewerCanView($db, $viewer, $labId);
    $canManage = labCheckCanManage($db, $viewer, $labId);
    $viewerId = labCheckViewerId($viewer);

    $runRow = labCheckGetRunRow($db, $labId, $runId);
    if ($runRow === null) {
        throw new RuntimeException('Run not found');
    }

    $runStartedBy = isset($runRow['started_by']) ? (string) $runRow['started_by'] : '';
    if (!$canManage && ($viewerId === '' || !hash_equals($runStartedBy, $viewerId))) {
        throw new RuntimeException('Forbidden');
    }

    $itemsStmt = $db->prepare(
        "SELECT id,
                check_item_id,
                node_id,
                node_name,
                check_title,
                transport,
                shell_type,
                command_text,
                expected_text,
                match_mode,
                hint_text,
                show_expected_to_learner,
                show_output_to_learner,
                status,
                is_passed,
                points,
                earned_points,
                output_text,
                error_text,
                duration_ms,
                created_at
         FROM lab_check_run_items
         WHERE run_id = :run_id
         ORDER BY created_at ASC, id ASC"
    );
    $itemsStmt->bindValue(':run_id', $runId, PDO::PARAM_STR);
    $itemsStmt->execute();
    $itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($itemRows)) {
        $itemRows = [];
    }

    $items = array_map(static function (array $row) use ($canManage): array {
        return labCheckSanitizeRunItemForViewer($row, $canManage);
    }, $itemRows);

    return [
        'lab_id' => $labId,
        'can_manage' => $canManage,
        'run' => labCheckBuildRunSummary($runRow),
        'items' => $items,
    ];
}

function labCheckExportRunForViewer(PDO $db, array $viewer, string $labId, string $runId): array
{
    $detail = labCheckGetRunForViewer($db, $viewer, $labId, $runId);
    $run = (array) ($detail['run'] ?? []);
    $items = is_array($detail['items'] ?? null) ? $detail['items'] : [];

    $fp = fopen('php://temp', 'r+');
    if (!is_resource($fp)) {
        throw new RuntimeException('Failed to build export');
    }

    fputcsv($fp, [
        'run_id',
        'started_at',
        'finished_at',
        'started_by',
        'node',
        'check',
        'status',
        'points_earned',
        'points_total',
        'hint',
        'output',
        'expected',
        'error',
        'duration_ms',
    ], ';');

    foreach ($items as $item) {
        fputcsv($fp, [
            (string) ($run['id'] ?? ''),
            (string) ($run['started_at'] ?? ''),
            (string) ($run['finished_at'] ?? ''),
            (string) ($run['started_by_username'] ?? ''),
            (string) ($item['node_name'] ?? ''),
            (string) ($item['check_title'] ?? ''),
            (string) ($item['status'] ?? ''),
            (string) ((int) ($item['earned_points'] ?? 0)),
            (string) ((int) ($item['points'] ?? 0)),
            (string) ($item['hint_text'] ?? ''),
            isset($item['output_text']) ? (string) $item['output_text'] : '',
            isset($item['expected_text']) ? (string) $item['expected_text'] : '',
            (string) ($item['error_text'] ?? ''),
            (string) ((int) ($item['duration_ms'] ?? 0)),
        ], ';');
    }

    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);

    if (!is_string($csv)) {
        $csv = '';
    }

    $started = (string) ($run['started_at'] ?? 'run');
    $started = preg_replace('/[^0-9a-zA-Z_-]+/', '-', $started);
    $filename = 'lab-check-run-' . ($started !== '' ? $started : 'run') . '.csv';

    return [
        'filename' => $filename,
        'content_type' => 'text/csv; charset=utf-8',
        'csv_base64' => base64_encode($csv),
        'size_bytes' => strlen($csv),
    ];
}

function labCheckCloneConfigForLabCopy(PDO $db, string $sourceLabId, string $targetLabId, array $nodeIdMap, ?string $actorUserId = null): void
{
    $sourceLabId = labCheckNormalizeUuid($sourceLabId);
    $targetLabId = labCheckNormalizeUuid($targetLabId);
    if ($sourceLabId === '' || $targetLabId === '') {
        return;
    }

    $actorUserId = labCheckNormalizeUuid($actorUserId);

    $settingsStmt = $db->prepare(
        "SELECT grading_enabled, pass_percent
         FROM lab_check_settings
         WHERE lab_id = :lab_id
         LIMIT 1"
    );
    $settingsStmt->bindValue(':lab_id', $sourceLabId, PDO::PARAM_STR);
    $settingsStmt->execute();
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($settings)) {
        $upsert = $db->prepare(
            "INSERT INTO lab_check_settings (lab_id, grading_enabled, pass_percent, updated_by, created_at, updated_at)
             VALUES (:lab_id, :grading_enabled, :pass_percent, :updated_by, NOW(), NOW())
             ON CONFLICT (lab_id)
             DO UPDATE SET grading_enabled = EXCLUDED.grading_enabled,
                           pass_percent = EXCLUDED.pass_percent,
                           updated_by = EXCLUDED.updated_by,
                           updated_at = NOW()"
        );
        $upsert->bindValue(':lab_id', $targetLabId, PDO::PARAM_STR);
        $upsert->bindValue(':grading_enabled', !empty($settings['grading_enabled']), PDO::PARAM_BOOL);
        $upsert->bindValue(':pass_percent', isset($settings['pass_percent']) ? (float) $settings['pass_percent'] : 60.0);
        if ($actorUserId === '') {
            $upsert->bindValue(':updated_by', null, PDO::PARAM_NULL);
        } else {
            $upsert->bindValue(':updated_by', $actorUserId, PDO::PARAM_STR);
        }
        $upsert->execute();
    }

    $gradeRows = labCheckLoadGrades($db, $sourceLabId);
    if (!empty($gradeRows)) {
        $delTargetGrades = $db->prepare('DELETE FROM lab_check_grade_scales WHERE lab_id = :lab_id');
        $delTargetGrades->bindValue(':lab_id', $targetLabId, PDO::PARAM_STR);
        $delTargetGrades->execute();

        $insGrade = $db->prepare(
            "INSERT INTO lab_check_grade_scales (lab_id, min_percent, grade_label, order_index, created_at, updated_at)
             VALUES (:lab_id, :min_percent, :grade_label, :order_index, NOW(), NOW())"
        );
        foreach ($gradeRows as $grade) {
            $insGrade->bindValue(':lab_id', $targetLabId, PDO::PARAM_STR);
            $insGrade->bindValue(':min_percent', (float) ($grade['min_percent'] ?? 0.0));
            $insGrade->bindValue(':grade_label', (string) ($grade['grade_label'] ?? ''), PDO::PARAM_STR);
            $insGrade->bindValue(':order_index', (int) ($grade['order_index'] ?? 0), PDO::PARAM_INT);
            $insGrade->execute();
        }
    }

    $items = labCheckLoadItemsRaw($db, $sourceLabId, false);
    if (empty($items)) {
        return;
    }

    $delItems = $db->prepare('DELETE FROM lab_check_items WHERE lab_id = :lab_id');
    $delItems->bindValue(':lab_id', $targetLabId, PDO::PARAM_STR);
    $delItems->execute();

    $insItem = $db->prepare(
        "INSERT INTO lab_check_items (
            lab_id,
            node_id,
            title,
            transport,
            shell_type,
            command_text,
            match_mode,
            expected_text,
            hint_text,
            show_expected_to_learner,
            show_output_to_learner,
            points,
            timeout_seconds,
            is_enabled,
            order_index,
            ssh_host,
            ssh_port,
            ssh_username,
            ssh_password,
            created_by,
            updated_by,
            created_at,
            updated_at
        ) VALUES (
            :lab_id,
            :node_id,
            :title,
            :transport,
            :shell_type,
            :command_text,
            :match_mode,
            :expected_text,
            :hint_text,
            :show_expected_to_learner,
            :show_output_to_learner,
            :points,
            :timeout_seconds,
            :is_enabled,
            :order_index,
            :ssh_host,
            :ssh_port,
            :ssh_username,
            :ssh_password,
            :created_by,
            :updated_by,
            NOW(),
            NOW()
        )"
    );

    $orderIndex = 0;
    foreach ($items as $item) {
        $oldNodeId = (string) ($item['node_id'] ?? '');
        if ($oldNodeId === '' || !isset($nodeIdMap[$oldNodeId])) {
            continue;
        }

        $newNodeId = labCheckNormalizeUuid((string) $nodeIdMap[$oldNodeId]);
        if ($newNodeId === '') {
            continue;
        }

        $insItem->bindValue(':lab_id', $targetLabId, PDO::PARAM_STR);
        $insItem->bindValue(':node_id', $newNodeId, PDO::PARAM_STR);
        $insItem->bindValue(':title', (string) ($item['title'] ?? ''), PDO::PARAM_STR);
        $insItem->bindValue(':transport', (string) ($item['transport'] ?? 'auto'), PDO::PARAM_STR);
        $insItem->bindValue(':shell_type', (string) ($item['shell_type'] ?? 'auto'), PDO::PARAM_STR);
        $insItem->bindValue(':command_text', (string) ($item['command_text'] ?? ''), PDO::PARAM_STR);
        $insItem->bindValue(':match_mode', (string) ($item['match_mode'] ?? 'contains'), PDO::PARAM_STR);
        $insItem->bindValue(':expected_text', (string) ($item['expected_text'] ?? ''), PDO::PARAM_STR);
        $insItem->bindValue(':hint_text', (string) ($item['hint_text'] ?? ''), PDO::PARAM_STR);
        $insItem->bindValue(':show_expected_to_learner', !empty($item['show_expected_to_learner']), PDO::PARAM_BOOL);
        $insItem->bindValue(':show_output_to_learner', !empty($item['show_output_to_learner']), PDO::PARAM_BOOL);
        $insItem->bindValue(':points', isset($item['points']) ? (int) $item['points'] : 0, PDO::PARAM_INT);
        $insItem->bindValue(':timeout_seconds', isset($item['timeout_seconds']) ? (int) $item['timeout_seconds'] : 12, PDO::PARAM_INT);
        $insItem->bindValue(':is_enabled', !empty($item['is_enabled']), PDO::PARAM_BOOL);
        $insItem->bindValue(':order_index', $orderIndex, PDO::PARAM_INT);

        $sshHost = trim((string) ($item['ssh_host'] ?? ''));
        if ($sshHost === '') {
            $insItem->bindValue(':ssh_host', null, PDO::PARAM_NULL);
            $insItem->bindValue(':ssh_port', null, PDO::PARAM_NULL);
            $insItem->bindValue(':ssh_username', null, PDO::PARAM_NULL);
            $insItem->bindValue(':ssh_password', null, PDO::PARAM_NULL);
        } else {
            $insItem->bindValue(':ssh_host', $sshHost, PDO::PARAM_STR);
            $insItem->bindValue(':ssh_port', isset($item['ssh_port']) ? (int) $item['ssh_port'] : 22, PDO::PARAM_INT);

            $sshUsername = trim((string) ($item['ssh_username'] ?? ''));
            if ($sshUsername === '') {
                $insItem->bindValue(':ssh_username', null, PDO::PARAM_NULL);
            } else {
                $insItem->bindValue(':ssh_username', $sshUsername, PDO::PARAM_STR);
            }

            $sshPassword = (string) ($item['ssh_password'] ?? '');
            if ($sshPassword === '') {
                $insItem->bindValue(':ssh_password', null, PDO::PARAM_NULL);
            } else {
                $insItem->bindValue(':ssh_password', $sshPassword, PDO::PARAM_STR);
            }
        }

        if ($actorUserId === '') {
            $insItem->bindValue(':created_by', null, PDO::PARAM_NULL);
            $insItem->bindValue(':updated_by', null, PDO::PARAM_NULL);
        } else {
            $insItem->bindValue(':created_by', $actorUserId, PDO::PARAM_STR);
            $insItem->bindValue(':updated_by', $actorUserId, PDO::PARAM_STR);
        }

        $insItem->execute();
        $orderIndex++;
    }
}
