<?php

declare(strict_types=1);

const LAB_CHECK_MAX_ITEMS = 300;
const LAB_CHECK_MAX_GRADES = 32;
const LAB_CHECK_MAX_OUTPUT_BYTES = 180000;
const LAB_CHECK_TASK_MAX_ITEMS = 400;
const LAB_CHECK_TASK_INTRO_MAX_BYTES = 24000;
const LAB_CHECK_TASK_ITEM_MAX_BYTES = 4000;

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
    if (function_exists('v2AppLogAttachUser')) {
        $context = v2AppLogAttachUser($context);
    }
    v2AppLogWrite('user_activity', $status, $context);
    if (function_exists('v2AppLogMarkActionLogged')) {
        v2AppLogMarkActionLogged();
    }
}

function labCheckSecretKeyBinary(): string
{
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }

    $raw = trim((string) getenv('EVE_LABCHECK_SECRET_KEY'));
    if ($raw === '') {
        $raw = trim((string) getenv('EVE_APP_SECRET'));
    }
    if ($raw === '') {
        $machineId = trim((string) @file_get_contents('/etc/machine-id'));
        if ($machineId === '') {
            $machineId = php_uname('n') . '|labcheck-fallback';
        }
        $raw = $machineId;
    }

    $cached = hash('sha256', $raw, true);
    return $cached;
}

function labCheckEncryptSecret(string $plain): string
{
    $plain = (string) $plain;
    if ($plain === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        return $plain;
    }

    $key = labCheckSecretKeyBinary();
    $iv = random_bytes(12);
    $tag = '';
    $cipherRaw = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipherRaw) || !is_string($tag) || strlen($tag) !== 16) {
        return $plain;
    }

    return 'enc:v1:' . base64_encode($iv . $tag . $cipherRaw);
}

function labCheckDecryptSecret(string $stored): string
{
    $stored = (string) $stored;
    if ($stored === '') {
        return '';
    }
    if (strpos($stored, 'enc:v1:') !== 0) {
        return $stored;
    }
    if (!function_exists('openssl_decrypt')) {
        return '';
    }

    $blob = base64_decode(substr($stored, 7), true);
    if (!is_string($blob) || strlen($blob) < 29) {
        return '';
    }
    $iv = substr($blob, 0, 12);
    $tag = substr($blob, 12, 16);
    $cipherRaw = substr($blob, 28);
    $key = labCheckSecretKeyBinary();
    $plain = openssl_decrypt($cipherRaw, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($plain) ? $plain : '';
}

function labCheckResolveLabName(PDO $db, string $labId): string
{
    static $cache = [];
    $id = labCheckNormalizeUuid($labId);
    if ($id === '') {
        return '';
    }
    if (array_key_exists($id, $cache)) {
        return (string) $cache[$id];
    }
    try {
        $stmt = $db->prepare('SELECT name FROM labs WHERE id = :lab_id LIMIT 1');
        $stmt->bindValue(':lab_id', $id, PDO::PARAM_STR);
        $stmt->execute();
        $name = trim((string) ($stmt->fetchColumn() ?: ''));
        $cache[$id] = $name;
        return $name;
    } catch (Throwable $e) {
        $cache[$id] = '';
        return '';
    }
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

    $stmt = $db->prepare('SELECT author_user_id, source_lab_id, is_mirror FROM labs WHERE id = :lab_id LIMIT 1');
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return false;
    }
    if (!empty($row['source_lab_id']) || !empty($row['is_mirror'])) {
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
                   i.is_required,
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
    if (!is_array($rows)) {
        return [];
    }

    foreach ($rows as &$row) {
        $row['ssh_password'] = labCheckDecryptSecret((string) ($row['ssh_password'] ?? ''));
    }
    unset($row);

    return $rows;
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
        'is_required' => !empty($row['is_required']),
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
    $labMeta = labCheckLoadLabMetaForSync($db, $labId);
    $syncNotice = null;
    if (is_array($labMeta) && !empty($labMeta['source_lab_id'])) {
        $syncNotice = labCheckLoadSyncNoticeForLab($db, $labId);
    }
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
        'sync_notice' => $syncNotice,
    ];
}

function labCheckTaskNormalizeIntroText($value): string
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $trimmed = trim($text);
    if ($trimmed === '') {
        return '';
    }
    if (strlen($trimmed) > LAB_CHECK_TASK_INTRO_MAX_BYTES) {
        $trimmed = substr($trimmed, 0, LAB_CHECK_TASK_INTRO_MAX_BYTES);
    }
    return $trimmed;
}

function labCheckTaskNormalizeItemText($value): string
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $trimmed = trim($text);
    if ($trimmed === '') {
        return '';
    }
    if (strlen($trimmed) > LAB_CHECK_TASK_ITEM_MAX_BYTES) {
        $trimmed = substr($trimmed, 0, LAB_CHECK_TASK_ITEM_MAX_BYTES);
    }
    return $trimmed;
}

function labCheckTaskLoadSettings(PDO $db, string $labId): array
{
    $stmt = $db->prepare(
        "SELECT intro_text, updated_at
         FROM lab_check_task_settings
         WHERE lab_id = :lab_id
         LIMIT 1"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return [
            'intro_text' => '',
            'updated_at' => null,
        ];
    }

    return [
        'intro_text' => (string) ($row['intro_text'] ?? ''),
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
    ];
}

function labCheckTaskLoadItemsRaw(PDO $db, string $labId, bool $enabledOnly = false): array
{
    $sql = "SELECT id, lab_id, task_text, is_enabled, order_index, updated_at
            FROM lab_check_task_items
            WHERE lab_id = :lab_id";
    if ($enabledOnly) {
        $sql .= ' AND is_enabled = TRUE';
    }
    $sql .= ' ORDER BY order_index ASC, created_at ASC, id ASC';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function labCheckTaskSanitizeItemForViewer(array $row, bool $canManage): array
{
    $base = [
        'id' => (string) ($row['id'] ?? ''),
        'lab_id' => (string) ($row['lab_id'] ?? ''),
        'task_text' => (string) ($row['task_text'] ?? ''),
        'order_index' => isset($row['order_index']) ? (int) $row['order_index'] : 0,
    ];

    if ($canManage) {
        $base['is_enabled'] = !empty($row['is_enabled']);
        $base['updated_at'] = isset($row['updated_at']) ? (string) $row['updated_at'] : null;
    }

    return $base;
}

function labCheckTaskNormalizeItemsPayload($itemsRaw): array
{
    if (!is_array($itemsRaw)) {
        throw new InvalidArgumentException('Invalid tasks payload');
    }
    if (count($itemsRaw) > LAB_CHECK_TASK_MAX_ITEMS) {
        throw new InvalidArgumentException('Too many task items');
    }

    $normalized = [];
    foreach ($itemsRaw as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $text = labCheckTaskNormalizeItemText($row['task_text'] ?? $row['text'] ?? '');
        if ($text === '') {
            continue;
        }
        if (strlen($text) > LAB_CHECK_TASK_ITEM_MAX_BYTES) {
            throw new InvalidArgumentException('Task item is too long');
        }
        $isEnabled = array_key_exists('is_enabled', $row) ? labCheckBool($row['is_enabled']) : true;
        $normalized[] = [
            'task_text' => $text,
            'is_enabled' => $isEnabled,
            'order_index' => count($normalized),
        ];
        if (count($normalized) > LAB_CHECK_TASK_MAX_ITEMS) {
            throw new InvalidArgumentException('Too many task items');
        }
        unset($idx);
    }

    return $normalized;
}

function labCheckTaskListDoneIds(PDO $db, string $labId, string $viewerId, array $allowedTaskIds): array
{
    $viewerId = labCheckNormalizeUuid($viewerId);
    if ($viewerId === '' || empty($allowedTaskIds)) {
        return [];
    }

    $allowed = [];
    foreach ($allowedTaskIds as $taskId) {
        $id = labCheckNormalizeUuid($taskId);
        if ($id !== '') {
            $allowed[$id] = true;
        }
    }
    if (empty($allowed)) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT task_item_id
         FROM lab_check_task_marks
         WHERE lab_id = :lab_id
           AND user_id = :user_id
           AND is_done = TRUE"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->bindValue(':user_id', $viewerId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    $result = [];
    foreach ($rows as $row) {
        $taskId = labCheckNormalizeUuid($row['task_item_id'] ?? '');
        if ($taskId === '' || !isset($allowed[$taskId])) {
            continue;
        }
        $result[] = $taskId;
    }
    return $result;
}

function labCheckTaskListForViewer(PDO $db, array $viewer, string $labId): array
{
    labCheckEnsureViewerCanView($db, $viewer, $labId);

    $canManage = labCheckCanManage($db, $viewer, $labId);
    $settings = labCheckTaskLoadSettings($db, $labId);
    $itemsRaw = labCheckTaskLoadItemsRaw($db, $labId, !$canManage);

    $items = [];
    foreach ($itemsRaw as $row) {
        if (!$canManage && empty($row['is_enabled'])) {
            continue;
        }
        $items[] = labCheckTaskSanitizeItemForViewer($row, $canManage);
    }

    $taskIds = [];
    foreach ($items as $item) {
        $taskId = labCheckNormalizeUuid($item['id'] ?? '');
        if ($taskId !== '') {
            $taskIds[] = $taskId;
        }
    }

    $doneItemIds = labCheckTaskListDoneIds($db, $labId, labCheckViewerId($viewer), $taskIds);

    return [
        'lab_id' => $labId,
        'can_manage' => $canManage,
        'settings' => $settings,
        'items' => $items,
        'done_item_ids' => $doneItemIds,
    ];
}

function labCheckTaskSaveConfig(PDO $db, array $viewer, string $labId, array $payload): array
{
    labCheckEnsureViewerCanView($db, $viewer, $labId);
    if (!labCheckCanManage($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $viewerId = labCheckViewerId($viewer);
    $settingsRaw = is_array($payload['settings'] ?? null) ? (array) $payload['settings'] : [];
    $introText = labCheckTaskNormalizeIntroText($settingsRaw['intro_text'] ?? '');
    $items = labCheckTaskNormalizeItemsPayload($payload['items'] ?? []);

    $db->beginTransaction();
    try {
        $upsertSettings = $db->prepare(
            "INSERT INTO lab_check_task_settings (lab_id, intro_text, updated_by, updated_at)
             VALUES (:lab_id, :intro_text, :updated_by, NOW())
             ON CONFLICT (lab_id)
             DO UPDATE SET intro_text = EXCLUDED.intro_text,
                           updated_by = EXCLUDED.updated_by,
                           updated_at = NOW()"
        );
        $upsertSettings->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $upsertSettings->bindValue(':intro_text', $introText, PDO::PARAM_STR);
        if ($viewerId === '') {
            $upsertSettings->bindValue(':updated_by', null, PDO::PARAM_NULL);
        } else {
            $upsertSettings->bindValue(':updated_by', $viewerId, PDO::PARAM_STR);
        }
        $upsertSettings->execute();

        $delItems = $db->prepare('DELETE FROM lab_check_task_items WHERE lab_id = :lab_id');
        $delItems->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $delItems->execute();

        $insItem = $db->prepare(
            "INSERT INTO lab_check_task_items (
                lab_id, task_text, is_enabled, order_index, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :lab_id, :task_text, :is_enabled, :order_index, :created_by, :updated_by, NOW(), NOW()
            )"
        );

        foreach ($items as $item) {
            $insItem->bindValue(':lab_id', $labId, PDO::PARAM_STR);
            $insItem->bindValue(':task_text', (string) $item['task_text'], PDO::PARAM_STR);
            $insItem->bindValue(':is_enabled', !empty($item['is_enabled']), PDO::PARAM_BOOL);
            $insItem->bindValue(':order_index', (int) $item['order_index'], PDO::PARAM_INT);
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
        'event' => 'lab_check_tasks_saved',
        'lab_id' => $labId,
        'lab_name' => labCheckResolveLabName($db, $labId),
        'items_count' => count($items),
        'user_id' => $viewerId,
    ]);

    return labCheckTaskListForViewer($db, $viewer, $labId);
}

function labCheckTaskSaveViewerMarks(PDO $db, array $viewer, string $labId, array $payload): array
{
    labCheckEnsureViewerCanView($db, $viewer, $labId);

    $viewerId = labCheckNormalizeUuid(labCheckViewerId($viewer));
    if ($viewerId === '') {
        throw new RuntimeException('Forbidden');
    }

    $doneIdsRaw = $payload['done_item_ids'] ?? [];
    if (!is_array($doneIdsRaw)) {
        throw new InvalidArgumentException('Invalid done item ids payload');
    }

    $canManage = labCheckCanManage($db, $viewer, $labId);
    $visibleItemsRaw = labCheckTaskLoadItemsRaw($db, $labId, !$canManage);
    $visibleIds = [];
    foreach ($visibleItemsRaw as $row) {
        if (!$canManage && empty($row['is_enabled'])) {
            continue;
        }
        $taskId = labCheckNormalizeUuid($row['id'] ?? '');
        if ($taskId !== '') {
            $visibleIds[$taskId] = true;
        }
    }

    $doneIds = [];
    foreach ($doneIdsRaw as $taskIdRaw) {
        $taskId = labCheckNormalizeUuid($taskIdRaw);
        if ($taskId === '' || !isset($visibleIds[$taskId])) {
            continue;
        }
        $doneIds[$taskId] = true;
    }

    $db->beginTransaction();
    try {
        $delMarks = $db->prepare(
            "DELETE FROM lab_check_task_marks
             WHERE lab_id = :lab_id
               AND user_id = :user_id"
        );
        $delMarks->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $delMarks->bindValue(':user_id', $viewerId, PDO::PARAM_STR);
        $delMarks->execute();

        if (!empty($doneIds)) {
            $insMark = $db->prepare(
                "INSERT INTO lab_check_task_marks (lab_id, task_item_id, user_id, is_done, updated_at)
                 VALUES (:lab_id, :task_item_id, :user_id, TRUE, NOW())"
            );
            foreach (array_keys($doneIds) as $taskId) {
                $insMark->bindValue(':lab_id', $labId, PDO::PARAM_STR);
                $insMark->bindValue(':task_item_id', $taskId, PDO::PARAM_STR);
                $insMark->bindValue(':user_id', $viewerId, PDO::PARAM_STR);
                $insMark->execute();
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    labCheckLog('OK', [
        'event' => 'lab_check_tasks_marks_saved',
        'lab_id' => $labId,
        'lab_name' => labCheckResolveLabName($db, $labId),
        'done_count' => count($doneIds),
        'user_id' => $viewerId,
    ]);

    return labCheckTaskListForViewer($db, $viewer, $labId);
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
    if ($nodeType === 'vpcs') {
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
        'vios',
        'viosl2',
        'iosv',
        'iosvl2',
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

function labCheckIsPingCommandLine(string $line): bool
{
    return preg_match('/^\s*ping(?:\.exe)?\b/i', $line) === 1;
}

function labCheckNormalizePingLineForShell(string $line, string $shellType): string
{
    $trimmed = trim($line);
    if ($trimmed === '' || !labCheckIsPingCommandLine($trimmed)) {
        return $line;
    }

    $shell = labCheckNormalizeShellType($shellType);
    if ($shell === 'sh') {
        if (preg_match('/(?:^|\s)-(?:c|w|W)\b/i', $trimmed) === 1) {
            return $line;
        }
        return rtrim($line) . ' -c 4';
    }

    if (in_array($shell, ['cmd', 'powershell'], true)) {
        if (preg_match('/(?:^|\s)-n\b/i', $trimmed) === 1 || preg_match('/(?:^|\s)-t(?:\s|$)/i', $trimmed) === 1) {
            return $line;
        }
        return rtrim($line) . ' -n 4';
    }

    return $line;
}

function labCheckApplyFinitePingPolicy(string $commandText, string $shellType): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $commandText);
    $lines = explode("\n", $normalized);
    foreach ($lines as $idx => $line) {
        $lines[$idx] = labCheckNormalizePingLineForShell((string) $line, $shellType);
    }
    return implode("\n", $lines);
}

function labCheckIsPotentialSlowIosCommand(string $commandPayload, string $nodeShellProfile): bool
{
    if ($nodeShellProfile !== 'ios') {
        return false;
    }
    $commandLower = strtolower($commandPayload);
    return (
        strpos($commandLower, 'show run') !== false
        || strpos($commandLower, 'show startup-config') !== false
        || strpos($commandLower, 'show tech') !== false
        || strpos($commandLower, 'show interface') !== false
        || strpos($commandLower, 'show ip route') !== false
    );
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
            'is_required' => labCheckBool($row['is_required'] ?? false),
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
                is_required,
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
                :is_required,
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
            $insItem->bindValue(':is_required', !empty($item['is_required']), PDO::PARAM_BOOL);
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
                    $insItem->bindValue(':ssh_password', labCheckEncryptSecret($sshPassword), PDO::PARAM_STR);
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
        'lab_name' => labCheckResolveLabName($db, $labId),
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

function labCheckIsIosSyslogNoiseLine(string $line): bool
{
    $line = trim($line);
    if ($line === '') {
        return false;
    }
    if (preg_match('/^\*?[A-Z][a-z]{2}\s+\d+\s+\d{2}:\d{2}:\d{2}(?:\.\d+)?\s*:\s*%[A-Z0-9_-]+-\d+-[A-Z0-9_]+:/', $line) === 1) {
        return true;
    }
    if (preg_match('/^%[A-Z0-9_-]+-\d+-[A-Z0-9_]+:/', $line) === 1) {
        return true;
    }
    return false;
}

function labCheckIsMostlyIosSyslogNoise(string $normalizedOutput): bool
{
    $lines = explode("\n", labCheckNormalizeLineEndings($normalizedOutput));
    $hasContent = false;
    foreach ($lines as $line) {
        $trim = trim((string) $line);
        if ($trim === '') {
            continue;
        }
        $hasContent = true;
        if (!labCheckIsIosSyslogNoiseLine($trim)) {
            return false;
        }
    }
    return $hasContent;
}

function labCheckSafeSubstr(string $text, int $maxBytes = LAB_CHECK_MAX_OUTPUT_BYTES): string
{
    $text = labCheckEnsureUtf8($text);
    if ($maxBytes < 1) {
        $maxBytes = LAB_CHECK_MAX_OUTPUT_BYTES;
    }
    if (strlen($text) <= $maxBytes) {
        return $text;
    }
    $cut = substr($text, 0, $maxBytes);
    if (preg_match('//u', $cut) !== 1) {
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $cut);
        if (is_string($fixed) && $fixed !== '') {
            $cut = $fixed;
        } else {
            while ($cut !== '' && preg_match('//u', $cut) !== 1) {
                $cut = substr($cut, 0, -1);
            }
        }
    }
    return $cut;
}

function labCheckEnsureUtf8(string $text): string
{
    if ($text === '') {
        return '';
    }
    $text = str_replace("\0", '', $text);
    if (preg_match('//u', $text) === 1) {
        return $text;
    }

    $encodings = [
        'CP866',
        'Windows-1251',
        'CP1251',
        'KOI8-R',
        'Windows-1252',
        'ISO-8859-1',
    ];
    foreach ($encodings as $enc) {
        $converted = @iconv($enc, 'UTF-8//IGNORE', $text);
        if (is_string($converted) && $converted !== '' && preg_match('//u', $converted) === 1) {
            return $converted;
        }
    }

    $scrubbed = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
    if (is_string($scrubbed) && $scrubbed !== '' && preg_match('//u', $scrubbed) === 1) {
        return $scrubbed;
    }

    $ascii = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text);
    return is_string($ascii) ? $ascii : '';
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

function labCheckBuildWindowsAgentCommand(string $commandText, string $shellType): array
{
    $shell = labCheckNormalizeShellType($shellType);
    $normalized = str_replace(["\r\n", "\r"], "\n", $commandText);
    $parts = array_values(array_filter(array_map(static function ($line): string {
        return trim((string) $line);
    }, explode("\n", $normalized)), static function ($line): bool {
        return $line !== '';
    }));
    if (empty($parts)) {
        return [];
    }

    if ($shell === 'powershell') {
        $joined = implode('; ', $parts);
        if ($joined === '') {
            return [];
        }
        return [
            'path' => 'powershell.exe',
            'arg' => ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $joined],
        ];
    }

    // Default Windows check shell is cmd.
    $joined = implode(' & ', $parts);
    if ($joined === '') {
        return [];
    }
    return [
        'path' => 'cmd.exe',
        'arg' => ['/d', '/s', '/c', $joined],
    ];
}

function labCheckExecuteViaWindowsGuestAgent(array $item): array
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
            'error' => 'windows_agent_unavailable',
            'output' => '',
            'duration_ms' => 0,
        ];
    }

    $qgaSocket = labCheckQgaSocketPath($nodeId);
    if (!file_exists($qgaSocket)) {
        return [
            'supported' => false,
            'ok' => false,
            'error' => 'windows_agent_unavailable',
            'output' => '',
            'duration_ms' => 0,
        ];
    }

    $shellType = labCheckNormalizeShellType($item['shell_type'] ?? 'auto');
    if ($shellType === 'auto') {
        $shellType = 'cmd';
    }
    $command = labCheckBuildWindowsAgentCommand((string) ($item['command_text'] ?? ''), $shellType);
    if (empty($command)) {
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
            'error' => 'windows_agent_unavailable',
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
                'error' => 'windows_agent_unavailable',
                'output' => '',
                'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
            ];
        }

        $exec = labCheckQgaRequest(
            $socket,
            $buffer,
            'guest-exec',
            [
                'path' => (string) ($command['path'] ?? 'cmd.exe'),
                'arg' => is_array($command['arg'] ?? null) ? (array) $command['arg'] : [],
                'capture-output' => true,
            ],
            min(4.0, max(1.2, $timeout * 0.4))
        );
        if (empty($exec['ok']) || !is_array($exec['return'])) {
            return [
                'supported' => true,
                'ok' => false,
                'error' => 'windows_agent_exec_failed',
                'output' => '',
                'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
            ];
        }

        $pid = isset($exec['return']['pid']) ? (int) $exec['return']['pid'] : 0;
        if ($pid < 1) {
            return [
                'supported' => true,
                'ok' => false,
                'error' => 'windows_agent_exec_failed',
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
                    'error' => 'windows_agent_exec_failed',
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
            'error' => 'windows_agent_timeout',
            'output' => '',
            'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
        ];
    } catch (Throwable $e) {
        return [
            'supported' => true,
            'ok' => false,
            'error' => 'windows_agent_exec_failed',
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

function labCheckIsPidAlive(int $pid): bool
{
    if ($pid <= 1) {
        return false;
    }
    if (!function_exists('posix_kill')) {
        return false;
    }
    return @posix_kill($pid, 0);
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

        $ageSec = max(0, $now - $lastTs);
        $workerPid = (int) ($meta['worker_pid'] ?? 0);
        $workerAlive = labCheckIsPidAlive($workerPid);

        // "closing" can linger in metadata even after worker is gone.
        // Keep only a short grace window and prefer worker liveness.
        if ($status === 'closing') {
            if (($workerAlive && $ageSec <= 8) || $ageSec <= 2) {
                return true;
            }
            continue;
        }

        // "starting" is expected to be short-lived.
        if ($status === 'starting') {
            if ($workerAlive || $ageSec <= 60) {
                return true;
            }
            continue;
        }

        // For "running", require live worker pid when possible.
        // If pid is absent/unavailable, keep only a short grace window.
        if ($status === 'running') {
            if ($workerAlive || $ageSec <= 20) {
                return true;
            }
            continue;
        }

        if ($ageSec <= max(30, min($maxAgeSec, 300))) {
            return true;
        }
    }

    return false;
}

function labCheckTryCloseViewerConsoleSessions(array $viewer, string $labId, string $nodeId): bool
{
    if (!function_exists('v2ConsoleCloseConflictingUserNodeSessions')) {
        return false;
    }
    try {
        v2ConsoleCloseConflictingUserNodeSessions($viewer, $labId, $nodeId);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function labCheckWaitForConsoleSessionRelease(string $labId, string $nodeId, int $maxWaitMs = 1200): bool
{
    $maxWaitMs = max(0, min(5000, $maxWaitMs));
    $deadline = microtime(true) + ($maxWaitMs / 1000.0);
    while (microtime(true) < $deadline) {
        if (!labCheckNodeHasActiveUserConsoleSession($labId, $nodeId, 1800)) {
            return true;
        }
        usleep(120000);
    }
    return !labCheckNodeHasActiveUserConsoleSession($labId, $nodeId, 1800);
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
    $fingerprint = strtolower(trim(implode(' ', [
        (string) ($item['node_template'] ?? $item['template'] ?? ''),
        (string) ($item['node_image'] ?? $item['image'] ?? ''),
        (string) ($item['node_name'] ?? ''),
        strtolower(trim((string) ($item['node_type'] ?? ''))),
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

    // For checks we only need stable EXEC prompt (user ">" or privileged "#"),
    // but never config mode.
    while (!in_array($state, ['user', 'privileged'], true) && $steps < 5 && microtime(true) < $deadline) {
        if ($state === 'config') {
            // Ctrl+Z + end handles nested config modes more reliably.
            labCheckConsoleSendRaw($socket, "\x1a\r\nend\r\n", $replyBuffer, 1.0);
        } else {
            labCheckConsoleSendRaw($socket, "\x03\r\n\r\n", $replyBuffer, 0.9);
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

function labCheckBuildConsoleWarmupTargets(PDO $db, string $labId, array $items): array
{
    $targets = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $nodeId = labCheckNormalizeUuid($item['node_id'] ?? '');
        if ($nodeId === '' || isset($targets[$nodeId])) {
            continue;
        }

        $isRunning = !empty($item['is_running']) || strtolower((string) ($item['power_state'] ?? '')) === 'running';
        if (!$isRunning) {
            continue;
        }

        $transport = labCheckResolveTransport($item);
        if ($transport !== 'console') {
            continue;
        }

        $port = labCheckResolveRuntimeConsolePort($db, $labId, $item, true);
        if ($port < 1 || $port > 65535) {
            continue;
        }

        $mainPort = isset($item['runtime_console_port']) ? (int) $item['runtime_console_port'] : 0;
        $checkPort = isset($item['runtime_check_console_port']) ? (int) $item['runtime_check_console_port'] : 0;
        $hasDedicatedCheckPort = $checkPort > 0 && $checkPort === $port && $checkPort !== $mainPort;
        if (!$hasDedicatedCheckPort && labCheckNodeHasActiveUserConsoleSession($labId, $nodeId, 1800)) {
            // Avoid injecting wake-up newlines into user-interactive console sessions.
            continue;
        }

        $nodeType = strtolower(trim((string) ($item['node_type'] ?? '')));
        $nodeTemplate = strtolower(trim((string) ($item['node_template'] ?? $item['template'] ?? '')));
        $isVpcsNode = ($nodeType === 'vpcs' || $nodeTemplate === 'vpcs');
        $payload = $isVpcsNode ? "\r" : "\r\n";
        $targets[$nodeId] = [
            'node_id' => $nodeId,
            'port' => $port,
            'payload' => $payload,
        ];
    }
    return array_values($targets);
}

function labCheckWarmupConsoleNodesAsync(PDO $db, string $labId, array $items, float $budgetSec = 2.2): void
{
    $targets = labCheckBuildConsoleWarmupTargets($db, $labId, $items);
    if (empty($targets)) {
        return;
    }

    $budgetSec = max(0.25, min(8.0, $budgetSec));
    $globalDeadline = microtime(true) + $budgetSec;
    $active = [];

    foreach ($targets as $target) {
        $port = isset($target['port']) ? (int) $target['port'] : 0;
        if ($port < 1 || $port > 65535) {
            continue;
        }
        $payload = (string) ($target['payload'] ?? "\r\n");
        if ($payload === '') {
            $payload = "\r\n";
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('tcp://127.0.0.1:%d', $port),
            $errno,
            $errstr,
            0.25,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );
        if (!is_resource($socket)) {
            continue;
        }

        @stream_set_blocking($socket, false);
        @stream_set_write_buffer($socket, 0);
        $now = microtime(true);
        $id = (int) $socket;
        $active[$id] = [
            'socket' => $socket,
            'payload' => $payload,
            'sent_at' => null,
            'last_io_at' => $now,
            'node_deadline' => min($globalDeadline, $now + 0.95),
        ];
    }

    if (empty($active)) {
        return;
    }

    $closeEntry = static function (array &$pool, int $id): void {
        if (!isset($pool[$id])) {
            return;
        }
        $socket = $pool[$id]['socket'] ?? null;
        if (is_resource($socket)) {
            @fclose($socket);
        }
        unset($pool[$id]);
    };

    while (!empty($active) && microtime(true) < $globalDeadline) {
        $read = [];
        $write = [];
        $except = [];
        foreach ($active as $id => $state) {
            $socket = $state['socket'] ?? null;
            if (!is_resource($socket)) {
                unset($active[$id]);
                continue;
            }
            $read[] = $socket;
            if ((string) ($state['payload'] ?? '') !== '') {
                $write[] = $socket;
            }
            $except[] = $socket;
        }
        if (empty($read) && empty($write)) {
            break;
        }

        $sec = 0;
        $usec = 80000;
        $selected = @stream_select($read, $write, $except, $sec, $usec);
        $now = microtime(true);
        if ($selected === false) {
            break;
        }

        foreach ($except as $socket) {
            $closeEntry($active, (int) $socket);
        }

        foreach ($write as $socket) {
            $id = (int) $socket;
            if (!isset($active[$id])) {
                continue;
            }
            $payload = (string) ($active[$id]['payload'] ?? '');
            if ($payload === '') {
                continue;
            }
            $written = @fwrite($socket, $payload);
            if ($written === false) {
                $closeEntry($active, $id);
                continue;
            }
            if ($written > 0) {
                $active[$id]['payload'] = (string) substr($payload, $written);
                $active[$id]['last_io_at'] = $now;
                if ($active[$id]['payload'] === '' && $active[$id]['sent_at'] === null) {
                    $active[$id]['sent_at'] = $now;
                }
            }
        }

        foreach ($read as $socket) {
            $id = (int) $socket;
            if (!isset($active[$id])) {
                continue;
            }
            $chunk = @fread($socket, 32768);
            if (is_string($chunk) && $chunk !== '') {
                $active[$id]['last_io_at'] = $now;
            } elseif ($chunk === '' && @feof($socket)) {
                $closeEntry($active, $id);
            }
        }

        foreach (array_keys($active) as $id) {
            if (!isset($active[$id])) {
                continue;
            }
            $state = $active[$id];
            $sentAt = $state['sent_at'];
            $lastIoAt = (float) ($state['last_io_at'] ?? $now);
            $nodeDeadline = (float) ($state['node_deadline'] ?? $now);
            if ($now >= $nodeDeadline) {
                $closeEntry($active, $id);
                continue;
            }
            if ($sentAt !== null) {
                $sinceSent = $now - (float) $sentAt;
                $sinceIo = $now - $lastIoAt;
                if ($sinceSent >= 0.12 && $sinceIo >= 0.08) {
                    $closeEntry($active, $id);
                }
            }
        }
    }

    foreach (array_keys($active) as $id) {
        $closeEntry($active, $id);
    }
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
    $isPotentialSlowIosCommand = labCheckIsPotentialSlowIosCommand($commandPayload, $nodeShellProfile);
    $nodeType = strtolower(trim((string) ($item['node_type'] ?? '')));
    $nodeTemplate = strtolower(trim((string) ($item['node_template'] ?? $item['template'] ?? '')));
    $isVpcsNode = ($nodeType === 'vpcs' || $nodeTemplate === 'vpcs');
    // Always normalize IOS/vIOS prompt before command execution to avoid
    // inheriting leftover CLI state (config mode, submode, etc.).
    $forceUserPromptBootstrap = ($nodeShellProfile === 'ios');
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
            // Best effort: auto-release this viewer's stale/active console tabs for same node.
            // This prevents false "console_in_use" when user forgot an opened console tab.
            labCheckTryCloseViewerConsoleSessions($viewer, $labId, $nodeId);
            if (!labCheckWaitForConsoleSessionRelease($labId, $nodeId, 3500)) {
                return [
                    'ok' => false,
                    'error' => 'console_in_use',
                    'output' => '',
                    'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
                ];
            }
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
            labCheckTryCloseViewerConsoleSessions($viewer, $labId, $nodeId);
            if (!labCheckWaitForConsoleSessionRelease($labId, $nodeId, 3500)) {
                return [
                    'ok' => false,
                    'error' => 'console_in_use',
                    'output' => '',
                    'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
                ];
            }
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $output = '';
            $warmupOutput = '';
            $telnetState = labCheckTelnetStateInit();
            $replyBuffer = '';
            $commandBuffer = $isVpcsNode
                ? ($commandPayload . "\r")
                : ("\r\n" . $commandPayload . "\r\n");
            $commandSentAt = null;
            $iosPromptSeenAfterCommand = false;
            $iosMaxWaitWithoutPrompt = 0.0;
            if ($nodeShellProfile === 'ios' && !$isVpcsNode) {
                $iosMaxWaitWithoutPrompt = min(
                    max(1.6, ((float) $timeout) - 0.35),
                    $isPotentialSlowIosCommand ? 7.5 : 4.5
                );
            }

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
            $warmupDeadline = microtime(true) + (($nodeShellProfile === 'ios' || $isVpcsNode) ? 0.65 : 0.25);
            $warmupHasData = false;
            $warmupLastDataAt = microtime(true);
            $warmupQuietWindow = ($nodeShellProfile === 'ios' || $isVpcsNode) ? 0.18 : 0.10;
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
                        $warmupHasData = true;
                        $warmupLastDataAt = microtime(true);
                    }
                } elseif ($chunk === '' && @feof($socket)) {
                    break;
                }
                labCheckSocketWriteBuffer($socket, $replyBuffer);
                if ($warmupHasData && (microtime(true) - $warmupLastDataAt) >= $warmupQuietWindow) {
                    break;
                }
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
                        if ($nodeShellProfile === 'ios' && !$isVpcsNode) {
                            $decodedLower = strtolower(labCheckStripAnsi($decoded));
                            if ($decodedLower !== '' && strpos($decodedLower, '--more--') !== false) {
                                // Auto-advance Cisco pager to collect full output for checks.
                                $replyBuffer .= ' ';
                            }
                            if ($commandSentAt !== null) {
                                $tail = (string) substr($output . $decoded, -16384);
                                $promptState = labCheckDetectCliPromptState($tail);
                                if ($promptState === 'user' || $promptState === 'privileged') {
                                    $iosPromptSeenAfterCommand = true;
                                }
                            }
                        }
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
                if ($isVpcsNode) {
                    $idleBreakDelay = 0.5;
                    $idleQuietWindow = 1.6;
                    $minRunBeforeIdleBreak = 0.8;
                } elseif ($nodeShellProfile === 'ios') {
                    // IOS can delay show-command output under load; avoid early idle break.
                    $idleBreakDelay = $isPotentialSlowIosCommand ? 1.4 : 0.9;
                    $idleQuietWindow = $isPotentialSlowIosCommand ? 2.3 : 1.7;
                    $minRunBeforeIdleBreak = $isPotentialSlowIosCommand ? 2.6 : 1.8;
                } else {
                    $idleBreakDelay = 0.2;
                    $idleQuietWindow = 1.0;
                    $minRunBeforeIdleBreak = 0.35;
                }
                $elapsedAfterSent = ($commandSentAt !== null) ? ($now - $commandSentAt) : 0.0;
                $canIdleBreak = ($commandSentAt !== null) && ($elapsedAfterSent >= $idleBreakDelay);
                if (
                    $canIdleBreak
                    && $hasData
                    && $elapsedAfterSent >= $minRunBeforeIdleBreak
                    && ($now - $lastDataAt) >= $idleQuietWindow
                ) {
                    if (
                        $nodeShellProfile === 'ios'
                        && !$isVpcsNode
                        && !$iosPromptSeenAfterCommand
                        && $elapsedAfterSent < $iosMaxWaitWithoutPrompt
                    ) {
                        usleep(50000);
                        continue;
                    }
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
	$hadExplicitDelimiters = preg_match('/^([#~\/|]).*\1[imsxuADSUXJ]*$/', $pattern) === 1;
	if (!$hadExplicitDelimiters) {
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
	if ($matched !== 1) {
		// Common lab use-case: users anchor with ^/$ for line checks and forget multiline flag.
		$multilinePattern = '';
		if (preg_match('/^([#~\/|])(.*)\1([imsxuADSUXJ]*)$/s', $pattern, $parts) === 1) {
			$flags = (string) ($parts[3] ?? '');
			if (strpos($flags, 'm') === false) {
				$multilinePattern = (string) $parts[1] . (string) $parts[2] . (string) $parts[1] . $flags . 'm';
			}
		}
		if ($multilinePattern !== '') {
			$multilineMatched = @preg_match($multilinePattern, $output);
			if ($multilineMatched === 1) {
				$matched = 1;
			}
		}
	}
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
        } elseif (!in_array($nodeShellProfile, ['linux', 'windows'], true)) {
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
    if ($transport === 'console' && in_array($effectiveShellType, ['sh', 'cmd', 'powershell'], true)) {
        $execItem['command_text'] = labCheckApplyFinitePingPolicy(
            (string) ($execItem['command_text'] ?? ''),
            $effectiveShellType
        );
    }

    $execResult = null;
    $linuxAgentSupported = false;
    $windowsAgentSupported = false;
    if ($transport === 'console' && $nodeShellProfile === 'linux') {
        $agentResult = labCheckExecuteViaLinuxGuestAgent($execItem);
        if (!empty($agentResult['supported'])) {
            $linuxAgentSupported = true;
            $execResult = $agentResult;
        }
    } elseif (
        $transport === 'console'
        && ($nodeShellProfile === 'windows' || in_array($effectiveShellType, ['cmd', 'powershell'], true))
    ) {
        $agentResult = labCheckExecuteViaWindowsGuestAgent($execItem);
        if (!empty($agentResult['supported'])) {
            $windowsAgentSupported = true;
            $execResult = $agentResult;
        }
    }
    if (!is_array($execResult)) {
        $execResult = ($transport === 'ssh')
            ? labCheckExecuteViaSsh($execItem)
            : labCheckExecuteViaConsole($db, $viewer, $labId, $execItem);
        if (
            $transport === 'console'
            && is_array($execResult)
            && empty($execResult['ok'])
            && strtolower(trim((string) ($execResult['error'] ?? ''))) === 'console_in_use'
        ) {
            usleep(900000);
            $execResult = labCheckExecuteViaConsole($db, $viewer, $labId, $execItem);
        }
    } elseif (
        $transport === 'console'
        && ($linuxAgentSupported || $windowsAgentSupported)
        && empty($execResult['ok'])
    ) {
        $agentError = strtolower(trim((string) ($execResult['error'] ?? '')));
        if (in_array($agentError, [
            'linux_agent_timeout',
            'linux_agent_exec_failed',
            'windows_agent_timeout',
            'windows_agent_exec_failed',
        ], true)) {
            $fallbackResult = labCheckExecuteViaConsole($db, $viewer, $labId, $execItem);
            if (is_array($fallbackResult) && !empty($fallbackResult['ok'])) {
                $execResult = $fallbackResult;
            }
        }
    }
    if ($transport === 'console' && is_array($execResult) && empty($execResult['ok'])) {
        $execError = strtolower(trim((string) ($execResult['error'] ?? '')));
        if (in_array($execError, ['console_no_output', 'linux_console_no_output'], true)) {
            // Node can be "idle/sleepy" after long inactivity. Wake the console
            // and retry command once with a short timeout.
            try {
                labCheckWarmupConsoleNodesAsync($db, $labId, [$execItem], 0.8);
            } catch (Throwable $ignored) {
                // Ignore warm-up failures, original execution result still applies.
            }
            $retryItem = $execItem;
            $retryTimeout = isset($execItem['timeout_seconds']) ? (int) $execItem['timeout_seconds'] : 12;
            $retryTimeout = max(8, min(30, $retryTimeout + 6));
            $retryItem['timeout_seconds'] = $retryTimeout;
            $retryResult = labCheckExecuteViaConsole($db, $viewer, $labId, $retryItem);
            if (is_array($retryResult) && !empty($retryResult['ok'])) {
                $execResult = $retryResult;
            } elseif (is_array($retryResult) && empty($retryResult['ok'])) {
                $retryError = strtolower(trim((string) ($retryResult['error'] ?? '')));
                if (!in_array($retryError, ['console_in_use', 'console_lock_failed'], true)) {
                    $execResult = $retryResult;
                }
            }
        }
    }
    if ($transport === 'console' && $nodeShellProfile === 'linux' && !$linuxAgentSupported && empty($execResult['ok'])) {
        $execError = strtolower(trim((string) ($execResult['error'] ?? '')));
        if (in_array($execError, ['console_connect_failed', 'console_port_missing', 'console_not_text_mode', 'console_no_output', 'linux_console_no_output'], true)) {
            $execResult['error'] = 'linux_agent_unavailable';
        }
    } elseif (
        $transport === 'console'
        && ($nodeShellProfile === 'windows' || in_array($effectiveShellType, ['cmd', 'powershell'], true))
        && !$windowsAgentSupported
        && empty($execResult['ok'])
    ) {
        $execError = strtolower(trim((string) ($execResult['error'] ?? '')));
        if (in_array($execError, ['console_connect_failed', 'console_port_missing', 'console_not_text_mode', 'console_no_output'], true)) {
            $execResult['error'] = 'windows_agent_unavailable';
        }
    }

    $outputText = labCheckSafeSubstr((string) ($execResult['output'] ?? ''));
    $outputText = labCheckNormalizeExecutionOutput(
        $outputText,
        (string) ($execItem['command_text'] ?? '')
    );
    $durationMs = isset($execResult['duration_ms']) ? (int) $execResult['duration_ms'] : 0;

    if ($transport === 'console' && $nodeShellProfile === 'ios' && !empty($execResult['ok'])) {
        $iosOutputEmpty = trim($outputText) === '';
        $iosOutputNoiseOnly = labCheckIsMostlyIosSyslogNoise($outputText);
        if ($iosOutputEmpty || $iosOutputNoiseOnly) {
            try {
                labCheckWarmupConsoleNodesAsync($db, $labId, [$execItem], 0.9);
            } catch (Throwable $ignored) {
                // Best effort: continue with retry anyway.
            }
            $retryItem = $execItem;
            $retryTimeout = isset($execItem['timeout_seconds']) ? (int) $execItem['timeout_seconds'] : 12;
            $retryTimeout = max(15, min(40, $retryTimeout + 8));
            $retryItem['timeout_seconds'] = $retryTimeout;
            $retryResult = labCheckExecuteViaConsole($db, $viewer, $labId, $retryItem);
            if (is_array($retryResult) && !empty($retryResult['ok'])) {
                $retryOutput = labCheckSafeSubstr((string) ($retryResult['output'] ?? ''));
                $retryOutput = labCheckNormalizeExecutionOutput(
                    $retryOutput,
                    (string) ($retryItem['command_text'] ?? '')
                );
                $retryNoiseOnly = labCheckIsMostlyIosSyslogNoise($retryOutput);
                $shouldReplace = false;
                if (trim($retryOutput) !== '' && !$retryNoiseOnly) {
                    $shouldReplace = true;
                } elseif ($iosOutputEmpty || $iosOutputNoiseOnly) {
                    // If first output was empty/noise, second attempt is preferable even if small.
                    $shouldReplace = true;
                }
                if ($shouldReplace) {
                    $execResult = $retryResult;
                    $outputText = $retryOutput;
                    $durationMs = isset($retryResult['duration_ms']) ? (int) $retryResult['duration_ms'] : $durationMs;
                }
            }
        }
    }

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
        'is_required' => !empty($row['is_required']),
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

function labCheckRunForViewer(PDO $db, array $viewer, string $labId, ?callable $onProgress = null): array
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
    $requiredItemsTotal = 0;
    $requiredItemsPassed = 0;
    // If one console item marks node as busy/locked, avoid repeating long waits
    // for every subsequent item of the same node within this run.
    $consoleBlockedNodes = [];

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
    if (is_callable($onProgress)) {
        try {
            $onProgress([
                'run_id' => $runId,
                'status' => 'running',
                'total_items' => $configuredTotalItems,
                'passed_items' => 0,
                'failed_items' => 0,
                'error_items' => 0,
                'completed_items' => 0,
            ]);
        } catch (Throwable $ignored) {
            // Progress callback failures should not break the check run.
        }
    }
    try {
        // Pre-warm idle console nodes in parallel before first real checks.
        // This reduces random "console_no_output" on sleeping/idle guests.
        labCheckWarmupConsoleNodesAsync($db, $labId, $items, 2.6);
    } catch (Throwable $ignored) {
        // Warm-up is best-effort only.
    }

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
            $itemNodeId = labCheckNormalizeUuid($item['node_id'] ?? '');
            if ($itemNodeId !== '' && isset($consoleBlockedNodes[$itemNodeId])) {
                $blockedError = (string) ($consoleBlockedNodes[$itemNodeId] ?? 'console_in_use');
                $result = [
                    'status' => 'error',
                    'is_passed' => false,
                    'earned_points' => 0,
                    'transport' => (string) (labCheckResolveTransport($item) ?: (string) ($item['transport'] ?? 'auto')),
                    'shell_type' => (string) (labCheckResolveEffectiveShellType($item) ?: (string) ($item['shell_type'] ?? 'auto')),
                    'output_text' => '',
                    'error_text' => $blockedError,
                    'duration_ms' => (int) max(0, round((microtime(true) - $itemStartedAt) * 1000)),
                ];
            } else {
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
            }

            $status = (string) ($result['status'] ?? 'error');
            $resultTransport = strtolower(trim((string) ($result['transport'] ?? '')));
            $resultError = strtolower(trim((string) ($result['error_text'] ?? '')));
            if (
                $itemNodeId !== ''
                && $resultTransport === 'console'
                && in_array($resultError, ['console_in_use', 'console_lock_failed'], true)
            ) {
                $consoleBlockedNodes[$itemNodeId] = $resultError;
            }
            $isRequired = !empty($item['is_required']);
            if ($status === 'passed') {
                $passedItems++;
            } elseif ($status === 'failed') {
                $failedItems++;
            } else {
                $errorItems++;
            }
            if ($isRequired) {
                $requiredItemsTotal++;
                if (!empty($result['is_passed'])) {
                    $requiredItemsPassed++;
                }
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
            if ($requiredItemsPassed < $requiredItemsTotal) {
                $gradeLabel = null;
            }
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
            if (is_callable($onProgress)) {
                try {
                    $onProgress([
                        'run_id' => $runId,
                        'status' => 'running',
                        'total_items' => $configuredTotalItems,
                        'passed_items' => $passedItems,
                        'failed_items' => $failedItems,
                        'error_items' => $errorItems,
                        'completed_items' => $passedItems + $failedItems + $errorItems,
                    ]);
                } catch (Throwable $ignored) {
                    // Progress callback failures should not break the check run.
                }
            }
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
        if ($requiredItemsPassed < $requiredItemsTotal) {
            $gradeLabel = null;
        }
        $durationMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));

        $finalStatus = 'completed';
        if (
            ($errorItems > 0 && $passedItems === 0 && $failedItems === 0)
            || ($requiredItemsPassed < $requiredItemsTotal)
        ) {
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
        if (is_callable($onProgress)) {
            try {
                $onProgress([
                    'run_id' => $runId,
                    'status' => $finalStatus,
                    'total_items' => $configuredTotalItems,
                    'passed_items' => $passedItems,
                    'failed_items' => $failedItems,
                    'error_items' => $errorItems,
                    'completed_items' => $passedItems + $failedItems + $errorItems,
                ]);
            } catch (Throwable $ignored) {
                // Progress callback failures should not break the check run.
            }
        }
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
                if ($requiredItemsPassed < $requiredItemsTotal) {
                    $gradeLabel = null;
                }
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
                if (is_callable($onProgress)) {
                    try {
                        $onProgress([
                            'run_id' => $runId,
                            'status' => 'failed',
                            'total_items' => $configuredTotalItems,
                            'passed_items' => $passedItems,
                            'failed_items' => $failedItems,
                            'error_items' => $errorItems,
                            'completed_items' => $passedItems + $failedItems + $errorItems,
                        ]);
                    } catch (Throwable $ignored) {
                        // Progress callback failures should not break the check run.
                    }
                }
            } catch (Throwable $ignored) {
                // Ignore forced-finalization failures: original error is more relevant.
            }
        }
        throw $e;
    }

    labCheckLog('OK', [
        'event' => 'lab_checks_run',
        'lab_id' => $labId,
        'lab_name' => labCheckResolveLabName($db, $labId),
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
        "SELECT r.id,
                r.check_item_id,
                r.node_id,
                r.node_name,
                r.check_title,
                r.transport,
                r.shell_type,
                r.command_text,
                r.expected_text,
                r.match_mode,
                r.hint_text,
                r.show_expected_to_learner,
                r.show_output_to_learner,
                r.status,
                r.is_passed,
                r.points,
                r.earned_points,
                r.output_text,
                r.error_text,
                r.duration_ms,
                r.created_at,
                COALESCE(i.is_required, FALSE) AS is_required
         FROM lab_check_run_items r
         LEFT JOIN lab_check_items i ON i.id = r.check_item_id
         WHERE r.run_id = :run_id
         ORDER BY r.created_at ASC, r.id ASC"
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
            $csvSafe((string) ($run['id'] ?? '')),
            $csvSafe((string) ($run['started_at'] ?? '')),
            $csvSafe((string) ($run['finished_at'] ?? '')),
            $csvSafe((string) ($run['started_by_username'] ?? '')),
            $csvSafe((string) ($item['node_name'] ?? '')),
            $csvSafe((string) ($item['check_title'] ?? '')),
            $csvSafe((string) ($item['status'] ?? '')),
            $csvSafe((string) ((int) ($item['earned_points'] ?? 0))),
            $csvSafe((string) ((int) ($item['points'] ?? 0))),
            $csvSafe((string) ($item['hint_text'] ?? '')),
            $csvSafe(isset($item['output_text']) ? (string) $item['output_text'] : ''),
            $csvSafe(isset($item['expected_text']) ? (string) $item['expected_text'] : ''),
            $csvSafe((string) ($item['error_text'] ?? '')),
            $csvSafe((string) ((int) ($item['duration_ms'] ?? 0))),
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

function labCheckCloneTasksForLabCopy(PDO $db, string $sourceLabId, string $targetLabId, ?string $actorUserId = null): array
{
    $sourceLabId = labCheckNormalizeUuid($sourceLabId);
    $targetLabId = labCheckNormalizeUuid($targetLabId);
    if ($sourceLabId === '' || $targetLabId === '') {
        return [
            'task_items_synced' => 0,
        ];
    }

    $actorUserId = labCheckNormalizeUuid($actorUserId);

    $taskSettingsStmt = $db->prepare(
        "SELECT intro_text
         FROM lab_check_task_settings
         WHERE lab_id = :lab_id
         LIMIT 1"
    );
    $taskSettingsStmt->bindValue(':lab_id', $sourceLabId, PDO::PARAM_STR);
    $taskSettingsStmt->execute();
    $taskSettings = $taskSettingsStmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($taskSettings)) {
        $upsertTaskSettings = $db->prepare(
            "INSERT INTO lab_check_task_settings (lab_id, intro_text, updated_by, created_at, updated_at)
             VALUES (:lab_id, :intro_text, :updated_by, NOW(), NOW())
             ON CONFLICT (lab_id)
             DO UPDATE SET intro_text = EXCLUDED.intro_text,
                           updated_by = EXCLUDED.updated_by,
                           updated_at = NOW()"
        );
        $upsertTaskSettings->bindValue(':lab_id', $targetLabId, PDO::PARAM_STR);
        $upsertTaskSettings->bindValue(':intro_text', (string) ($taskSettings['intro_text'] ?? ''), PDO::PARAM_STR);
        if ($actorUserId === '') {
            $upsertTaskSettings->bindValue(':updated_by', null, PDO::PARAM_NULL);
        } else {
            $upsertTaskSettings->bindValue(':updated_by', $actorUserId, PDO::PARAM_STR);
        }
        $upsertTaskSettings->execute();
    } else {
        $deleteTaskSettings = $db->prepare('DELETE FROM lab_check_task_settings WHERE lab_id = :lab_id');
        $deleteTaskSettings->bindValue(':lab_id', $targetLabId, PDO::PARAM_STR);
        $deleteTaskSettings->execute();
    }

    $taskItems = labCheckTaskLoadItemsRaw($db, $sourceLabId, false);
    $delTaskItems = $db->prepare('DELETE FROM lab_check_task_items WHERE lab_id = :lab_id');
    $delTaskItems->bindValue(':lab_id', $targetLabId, PDO::PARAM_STR);
    $delTaskItems->execute();

    $taskOrderIndex = 0;
    if (!empty($taskItems)) {
        $insTaskItem = $db->prepare(
            "INSERT INTO lab_check_task_items (
                lab_id, task_text, is_enabled, order_index, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :lab_id, :task_text, :is_enabled, :order_index, :created_by, :updated_by, NOW(), NOW()
            )"
        );

        foreach ($taskItems as $taskItem) {
            $insTaskItem->bindValue(':lab_id', $targetLabId, PDO::PARAM_STR);
            $insTaskItem->bindValue(':task_text', (string) ($taskItem['task_text'] ?? ''), PDO::PARAM_STR);
            $insTaskItem->bindValue(':is_enabled', !empty($taskItem['is_enabled']), PDO::PARAM_BOOL);
            $insTaskItem->bindValue(':order_index', isset($taskItem['order_index']) ? (int) $taskItem['order_index'] : $taskOrderIndex, PDO::PARAM_INT);
            if ($actorUserId === '') {
                $insTaskItem->bindValue(':created_by', null, PDO::PARAM_NULL);
                $insTaskItem->bindValue(':updated_by', null, PDO::PARAM_NULL);
            } else {
                $insTaskItem->bindValue(':created_by', $actorUserId, PDO::PARAM_STR);
                $insTaskItem->bindValue(':updated_by', $actorUserId, PDO::PARAM_STR);
            }
            $insTaskItem->execute();
            $taskOrderIndex++;
        }
    }

    return [
        'task_items_synced' => $taskOrderIndex,
    ];
}

function labCheckCloneChecksForLabCopy(PDO $db, string $sourceLabId, string $targetLabId, array $nodeIdMap, ?string $actorUserId = null): array
{
    $sourceLabId = labCheckNormalizeUuid($sourceLabId);
    $targetLabId = labCheckNormalizeUuid($targetLabId);
    if ($sourceLabId === '' || $targetLabId === '') {
        return [
            'check_items_synced' => 0,
            'check_items_skipped' => 0,
        ];
    }

    $actorUserId = labCheckNormalizeUuid($actorUserId);
    labCheckEnsureDefaults($db, $sourceLabId, $actorUserId === '' ? null : $actorUserId);

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
    } else {
        $deleteSettings = $db->prepare('DELETE FROM lab_check_settings WHERE lab_id = :lab_id');
        $deleteSettings->bindValue(':lab_id', $targetLabId, PDO::PARAM_STR);
        $deleteSettings->execute();
    }

    $gradeRows = labCheckLoadGrades($db, $sourceLabId);
    if (empty($gradeRows)) {
        $gradeRows = labCheckNormalizeGradesPayload([]);
    }
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

    $items = labCheckLoadItemsRaw($db, $sourceLabId, false);
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
            is_required,
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
            :is_required,
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
    $skipped = 0;
    foreach ($items as $item) {
        $oldNodeId = (string) ($item['node_id'] ?? '');
        if ($oldNodeId === '' || !isset($nodeIdMap[$oldNodeId])) {
            $skipped++;
            continue;
        }

        $newNodeId = labCheckNormalizeUuid((string) $nodeIdMap[$oldNodeId]);
        if ($newNodeId === '') {
            $skipped++;
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
        $insItem->bindValue(':is_required', !empty($item['is_required']), PDO::PARAM_BOOL);
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
                $insItem->bindValue(':ssh_password', labCheckEncryptSecret($sshPassword), PDO::PARAM_STR);
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

    return [
        'check_items_synced' => $orderIndex,
        'check_items_skipped' => $skipped,
    ];
}

function labCheckCloneConfigForLabCopy(PDO $db, string $sourceLabId, string $targetLabId, array $nodeIdMap, ?string $actorUserId = null): void
{
    labCheckCloneChecksForLabCopy($db, $sourceLabId, $targetLabId, $nodeIdMap, $actorUserId);
    labCheckCloneTasksForLabCopy($db, $sourceLabId, $targetLabId, $actorUserId);
}

function labCheckSyncNoticeObjectType(): string
{
    return 'check_sync';
}

function labCheckSyncNoticeObjectName(): string
{
    return 'copy_notice';
}

function labCheckLoadLabMetaForSync(PDO $db, string $labId): ?array
{
    $labId = labCheckNormalizeUuid($labId);
    if ($labId === '') {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT id, name, author_user_id, source_lab_id, is_mirror
         FROM labs
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->bindValue(':id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'author_user_id' => (string) ($row['author_user_id'] ?? ''),
        'source_lab_id' => (string) ($row['source_lab_id'] ?? ''),
        'is_mirror' => !empty($row['is_mirror']),
    ];
}

function labCheckLoadSyncNoticeForLab(PDO $db, string $labId): ?array
{
    $labId = labCheckNormalizeUuid($labId);
    if ($labId === '') {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT data_base64
         FROM lab_objects
         WHERE lab_id = :lab_id
           AND object_type = :object_type
           AND name = :name
         ORDER BY updated_at DESC, created_at DESC, id DESC
         LIMIT 1"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->bindValue(':object_type', labCheckSyncNoticeObjectType(), PDO::PARAM_STR);
    $stmt->bindValue(':name', labCheckSyncNoticeObjectName(), PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    $base64 = (string) ($row['data_base64'] ?? '');
    if ($base64 === '') {
        return null;
    }
    $json = base64_decode($base64, true);
    if (!is_string($json) || $json === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }

    $code = trim((string) ($decoded['code'] ?? ''));
    if ($code === '') {
        return null;
    }
    $missingNodes = array_values(array_filter(array_map(static function ($v): string {
        return trim((string) $v);
    }, is_array($decoded['missing_nodes'] ?? null) ? (array) $decoded['missing_nodes'] : []), static function (string $v): bool {
        return $v !== '';
    }));
    $ambiguousNodes = array_values(array_filter(array_map(static function ($v): string {
        return trim((string) $v);
    }, is_array($decoded['ambiguous_nodes'] ?? null) ? (array) $decoded['ambiguous_nodes'] : []), static function (string $v): bool {
        return $v !== '';
    }));

    return [
        'code' => $code,
        'source_lab_id' => labCheckNormalizeUuid($decoded['source_lab_id'] ?? ''),
        'source_topology_hash' => trim((string) ($decoded['source_topology_hash'] ?? '')),
        'target_topology_hash' => trim((string) ($decoded['target_topology_hash'] ?? '')),
        'missing_nodes' => $missingNodes,
        'ambiguous_nodes' => $ambiguousNodes,
        'synced_at' => trim((string) ($decoded['synced_at'] ?? '')),
    ];
}

function labCheckSaveSyncNoticeForLab(PDO $db, string $labId, ?array $notice): void
{
    $labId = labCheckNormalizeUuid($labId);
    if ($labId === '') {
        return;
    }

    $deleteStmt = $db->prepare(
        "DELETE FROM lab_objects
         WHERE lab_id = :lab_id
           AND object_type = :object_type
           AND name = :name"
    );
    $deleteStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $deleteStmt->bindValue(':object_type', labCheckSyncNoticeObjectType(), PDO::PARAM_STR);
    $deleteStmt->bindValue(':name', labCheckSyncNoticeObjectName(), PDO::PARAM_STR);
    $deleteStmt->execute();

    if (!is_array($notice) || empty($notice)) {
        return;
    }

    $payloadJson = json_encode($notice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson) || $payloadJson === '') {
        return;
    }
    $payloadBase64 = base64_encode($payloadJson);
    if ($payloadBase64 === '') {
        return;
    }

    $insertStmt = $db->prepare(
        "INSERT INTO lab_objects (lab_id, object_type, name, data_base64, created_at, updated_at)
         VALUES (:lab_id, :object_type, :name, :data_base64, NOW(), NOW())"
    );
    $insertStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $insertStmt->bindValue(':object_type', labCheckSyncNoticeObjectType(), PDO::PARAM_STR);
    $insertStmt->bindValue(':name', labCheckSyncNoticeObjectName(), PDO::PARAM_STR);
    $insertStmt->bindValue(':data_base64', $payloadBase64, PDO::PARAM_STR);
    $insertStmt->execute();
}

function labCheckNormalizeNodeNameKey(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $collapsed = preg_replace('/\s+/u', ' ', $name);
    if (!is_string($collapsed)) {
        $collapsed = $name;
    }
    return strtolower(trim($collapsed));
}

function labCheckLoadLabNodesForSync(PDO $db, string $labId): array
{
    $stmt = $db->prepare(
        "SELECT id, name, node_type, template, image, ethernet_count, serial_count
         FROM lab_nodes
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function labCheckBuildTopologyFingerprint(PDO $db, string $labId): string
{
    $labId = labCheckNormalizeUuid($labId);
    if ($labId === '') {
        return '';
    }

    $nodes = labCheckLoadLabNodesForSync($db, $labId);
    $nodeLines = [];
    foreach ($nodes as $row) {
        $nodeLines[] = implode('|', [
            labCheckNormalizeNodeNameKey((string) ($row['name'] ?? '')),
            strtolower(trim((string) ($row['node_type'] ?? ''))),
            strtolower(trim((string) ($row['template'] ?? ''))),
            strtolower(trim((string) ($row['image'] ?? ''))),
            (string) ((int) ($row['ethernet_count'] ?? 0)),
            (string) ((int) ($row['serial_count'] ?? 0)),
        ]);
    }
    sort($nodeLines, SORT_STRING);

    $portStmt = $db->prepare(
        "SELECT n.name AS node_name,
                p.name AS port_name,
                p.port_type,
                COALESCE(nw.network_type, '') AS network_type,
                COALESCE(nw.name, '') AS network_name
         FROM lab_node_ports p
         INNER JOIN lab_nodes n ON n.id = p.node_id
         LEFT JOIN lab_networks nw ON nw.id = p.network_id
         WHERE n.lab_id = :lab_id
         ORDER BY n.name ASC, p.name ASC, p.id ASC"
    );
    $portStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $portStmt->execute();
    $portRows = $portStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($portRows)) {
        $portRows = [];
    }
    $portLines = [];
    foreach ($portRows as $row) {
        $portLines[] = implode('|', [
            labCheckNormalizeNodeNameKey((string) ($row['node_name'] ?? '')),
            strtolower(trim((string) ($row['port_name'] ?? ''))),
            strtolower(trim((string) ($row['port_type'] ?? ''))),
            strtolower(trim((string) ($row['network_type'] ?? ''))),
            strtolower(trim((string) ($row['network_name'] ?? ''))),
        ]);
    }
    sort($portLines, SORT_STRING);

    $networkStmt = $db->prepare(
        "SELECT COALESCE(name, '') AS name, COALESCE(network_type, '') AS network_type
         FROM lab_networks
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC, id ASC"
    );
    $networkStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $networkStmt->execute();
    $networkRows = $networkStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($networkRows)) {
        $networkRows = [];
    }
    $networkLines = [];
    foreach ($networkRows as $row) {
        $networkLines[] = implode('|', [
            strtolower(trim((string) ($row['network_type'] ?? ''))),
            strtolower(trim((string) ($row['name'] ?? ''))),
        ]);
    }
    sort($networkLines, SORT_STRING);

    $payload = [
        'nodes' => $nodeLines,
        'ports' => $portLines,
        'networks' => $networkLines,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        $json = serialize($payload);
    }
    return hash('sha256', $json);
}

function labCheckLoadCheckNodeIdsForSync(PDO $db, string $labId): array
{
    $stmt = $db->prepare(
        "SELECT DISTINCT node_id::text AS node_id
         FROM lab_check_items
         WHERE lab_id = :lab_id
           AND node_id IS NOT NULL"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    $ids = [];
    foreach ($rows as $row) {
        $nodeId = labCheckNormalizeUuid($row['node_id'] ?? '');
        if ($nodeId !== '') {
            $ids[$nodeId] = true;
        }
    }
    return array_keys($ids);
}

function labCheckBuildNodeIdMapByNodeNames(PDO $db, string $sourceLabId, string $targetLabId, array $sourceNodeIds): array
{
    $sourceNodeIdsSet = [];
    foreach ($sourceNodeIds as $id) {
        $nodeId = labCheckNormalizeUuid($id);
        if ($nodeId !== '') {
            $sourceNodeIdsSet[$nodeId] = true;
        }
    }

    if (empty($sourceNodeIdsSet)) {
        return [
            'map' => [],
            'missing_nodes' => [],
            'ambiguous_nodes' => [],
            'duplicate_source_nodes' => [],
        ];
    }

    $sourceNodes = labCheckLoadLabNodesForSync($db, $sourceLabId);
    $targetNodes = labCheckLoadLabNodesForSync($db, $targetLabId);

    $sourceNamesById = [];
    $sourceNameKeyCounter = [];
    foreach ($sourceNodes as $node) {
        $nodeId = labCheckNormalizeUuid($node['id'] ?? '');
        if ($nodeId === '' || !isset($sourceNodeIdsSet[$nodeId])) {
            continue;
        }
        $nodeName = trim((string) ($node['name'] ?? ''));
        $sourceNamesById[$nodeId] = $nodeName;
        $key = labCheckNormalizeNodeNameKey($nodeName);
        if ($key !== '') {
            $sourceNameKeyCounter[$key] = (int) ($sourceNameKeyCounter[$key] ?? 0) + 1;
        }
    }

    $targetByNameKey = [];
    foreach ($targetNodes as $node) {
        $nodeId = labCheckNormalizeUuid($node['id'] ?? '');
        if ($nodeId === '') {
            continue;
        }
        $key = labCheckNormalizeNodeNameKey((string) ($node['name'] ?? ''));
        if ($key === '') {
            continue;
        }
        if (!isset($targetByNameKey[$key])) {
            $targetByNameKey[$key] = [];
        }
        $targetByNameKey[$key][] = $nodeId;
    }

    $map = [];
    $missingNodes = [];
    $ambiguousNodes = [];
    $duplicateSourceNodes = [];
    foreach (array_keys($sourceNodeIdsSet) as $sourceNodeId) {
        if (!isset($sourceNamesById[$sourceNodeId])) {
            $missingNodes[] = $sourceNodeId;
            continue;
        }
        $sourceNodeName = trim((string) $sourceNamesById[$sourceNodeId]);
        $sourceKey = labCheckNormalizeNodeNameKey($sourceNodeName);
        if ($sourceKey === '') {
            $missingNodes[] = $sourceNodeName;
            continue;
        }
        if ((int) ($sourceNameKeyCounter[$sourceKey] ?? 0) > 1) {
            $duplicateSourceNodes[] = $sourceNodeName;
            continue;
        }

        $targetMatches = isset($targetByNameKey[$sourceKey]) ? (array) $targetByNameKey[$sourceKey] : [];
        if (count($targetMatches) === 1) {
            $map[$sourceNodeId] = $targetMatches[0];
            continue;
        }
        if (count($targetMatches) === 0) {
            $missingNodes[] = $sourceNodeName;
            continue;
        }
        $ambiguousNodes[] = $sourceNodeName;
    }

    $uniqueClean = static function (array $values): array {
        $clean = array_values(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $values), static function (string $value): bool {
            return $value !== '';
        }));
        return array_values(array_unique($clean));
    };

    return [
        'map' => $map,
        'missing_nodes' => $uniqueClean($missingNodes),
        'ambiguous_nodes' => $uniqueClean($ambiguousNodes),
        'duplicate_source_nodes' => $uniqueClean($duplicateSourceNodes),
    ];
}

function labCheckListMirrorCopiesForSync(PDO $db, string $sourceLabId): array
{
    $stmt = $db->prepare(
        "SELECT l.id, l.name, l.author_user_id, COALESCE(u.username, '') AS username
         FROM labs l
         LEFT JOIN users u ON u.id = l.author_user_id
         WHERE l.source_lab_id = :source_lab_id
         ORDER BY LOWER(COALESCE(u.username, '')) ASC, LOWER(COALESCE(l.name, '')) ASC, l.id ASC"
    );
    $stmt->bindValue(':source_lab_id', $sourceLabId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function labCheckEnsureSourceLabCanSync(PDO $db, array $viewer, string $labId): array
{
    labCheckEnsureViewerCanView($db, $viewer, $labId);
    if (!labCheckCanManage($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $meta = labCheckLoadLabMetaForSync($db, $labId);
    if (!is_array($meta)) {
        throw new RuntimeException('Lab not found');
    }
    if (!empty($meta['source_lab_id']) || !empty($meta['is_mirror'])) {
        throw new RuntimeException('mirror_lab_sync_not_allowed');
    }
    return $meta;
}

function labCheckSyncTasksToLocalCopies(PDO $db, array $viewer, string $sourceLabId): array
{
    $sourceLabId = labCheckNormalizeUuid($sourceLabId);
    $sourceMeta = labCheckEnsureSourceLabCanSync($db, $viewer, $sourceLabId);
    $actorUserId = labCheckViewerId($viewer);
    $copies = labCheckListMirrorCopiesForSync($db, $sourceLabId);

    $results = [];
    $updated = 0;
    $failed = 0;
    foreach ($copies as $copy) {
        $copyLabId = labCheckNormalizeUuid($copy['id'] ?? '');
        if ($copyLabId === '') {
            continue;
        }

        $startedTx = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $startedTx = true;
            }
            $stats = labCheckCloneTasksForLabCopy($db, $sourceLabId, $copyLabId, $actorUserId);
            if ($startedTx && $db->inTransaction()) {
                $db->commit();
            }
            $updated++;
            $results[] = [
                'lab_id' => $copyLabId,
                'lab_name' => (string) ($copy['name'] ?? ''),
                'username' => (string) ($copy['username'] ?? ''),
                'status' => 'updated',
                'task_items_synced' => (int) ($stats['task_items_synced'] ?? 0),
            ];
        } catch (Throwable $e) {
            if ($startedTx && $db->inTransaction()) {
                $db->rollBack();
            }
            $failed++;
            $results[] = [
                'lab_id' => $copyLabId,
                'lab_name' => (string) ($copy['name'] ?? ''),
                'username' => (string) ($copy['username'] ?? ''),
                'status' => 'failed',
                'error' => trim((string) $e->getMessage()) !== '' ? trim((string) $e->getMessage()) : 'sync_failed',
            ];
        }
    }

    return [
        'source_lab_id' => $sourceLabId,
        'source_lab_name' => (string) ($sourceMeta['name'] ?? ''),
        'copies_total' => count($copies),
        'updated' => $updated,
        'failed' => $failed,
        'synced_at' => gmdate('c'),
        'results' => $results,
    ];
}

function labCheckSyncChecksToLocalCopies(PDO $db, array $viewer, string $sourceLabId): array
{
    $sourceLabId = labCheckNormalizeUuid($sourceLabId);
    $sourceMeta = labCheckEnsureSourceLabCanSync($db, $viewer, $sourceLabId);
    $actorUserId = labCheckViewerId($viewer);
    $copies = labCheckListMirrorCopiesForSync($db, $sourceLabId);

    $sourceNodeIds = labCheckLoadCheckNodeIdsForSync($db, $sourceLabId);
    $requiresTopologyMatch = !empty($sourceNodeIds);
    $sourceTopologyHash = $requiresTopologyMatch ? labCheckBuildTopologyFingerprint($db, $sourceLabId) : '';

    $results = [];
    $updated = 0;
    $failed = 0;
    $resetRequired = 0;

    foreach ($copies as $copy) {
        $copyLabId = labCheckNormalizeUuid($copy['id'] ?? '');
        if ($copyLabId === '') {
            continue;
        }

        $startedTx = false;
        try {
            $targetTopologyHash = $requiresTopologyMatch ? labCheckBuildTopologyFingerprint($db, $copyLabId) : '';
            if ($requiresTopologyMatch && $sourceTopologyHash !== '' && $targetTopologyHash !== '' && !hash_equals($sourceTopologyHash, $targetTopologyHash)) {
                $notice = [
                    'code' => 'topology_mismatch',
                    'source_lab_id' => $sourceLabId,
                    'source_topology_hash' => $sourceTopologyHash,
                    'target_topology_hash' => $targetTopologyHash,
                    'missing_nodes' => [],
                    'ambiguous_nodes' => [],
                    'synced_at' => gmdate('c'),
                ];
                if (!$db->inTransaction()) {
                    $db->beginTransaction();
                    $startedTx = true;
                }
                labCheckSaveSyncNoticeForLab($db, $copyLabId, $notice);
                if ($startedTx && $db->inTransaction()) {
                    $db->commit();
                }
                $resetRequired++;
                $results[] = [
                    'lab_id' => $copyLabId,
                    'lab_name' => (string) ($copy['name'] ?? ''),
                    'username' => (string) ($copy['username'] ?? ''),
                    'status' => 'reset_required',
                    'reason' => 'topology_mismatch',
                ];
                continue;
            }

            $mapInfo = labCheckBuildNodeIdMapByNodeNames($db, $sourceLabId, $copyLabId, $sourceNodeIds);
            $missingNodes = is_array($mapInfo['missing_nodes'] ?? null) ? (array) $mapInfo['missing_nodes'] : [];
            $ambiguousNodes = is_array($mapInfo['ambiguous_nodes'] ?? null) ? (array) $mapInfo['ambiguous_nodes'] : [];
            $duplicateSourceNodes = is_array($mapInfo['duplicate_source_nodes'] ?? null) ? (array) $mapInfo['duplicate_source_nodes'] : [];
            if (!empty($duplicateSourceNodes)) {
                $ambiguousNodes = array_values(array_unique(array_merge($ambiguousNodes, $duplicateSourceNodes)));
            }

            if (!empty($missingNodes) || !empty($ambiguousNodes)) {
                $notice = [
                    'code' => 'node_mapping_mismatch',
                    'source_lab_id' => $sourceLabId,
                    'source_topology_hash' => $sourceTopologyHash,
                    'target_topology_hash' => $requiresTopologyMatch ? labCheckBuildTopologyFingerprint($db, $copyLabId) : '',
                    'missing_nodes' => $missingNodes,
                    'ambiguous_nodes' => $ambiguousNodes,
                    'synced_at' => gmdate('c'),
                ];
                if (!$db->inTransaction()) {
                    $db->beginTransaction();
                    $startedTx = true;
                }
                labCheckSaveSyncNoticeForLab($db, $copyLabId, $notice);
                if ($startedTx && $db->inTransaction()) {
                    $db->commit();
                }
                $resetRequired++;
                $results[] = [
                    'lab_id' => $copyLabId,
                    'lab_name' => (string) ($copy['name'] ?? ''),
                    'username' => (string) ($copy['username'] ?? ''),
                    'status' => 'reset_required',
                    'reason' => 'node_mapping_mismatch',
                    'missing_nodes' => $missingNodes,
                    'ambiguous_nodes' => $ambiguousNodes,
                ];
                continue;
            }

            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $startedTx = true;
            }
            $stats = labCheckCloneChecksForLabCopy(
                $db,
                $sourceLabId,
                $copyLabId,
                is_array($mapInfo['map'] ?? null) ? (array) $mapInfo['map'] : [],
                $actorUserId
            );
            labCheckSaveSyncNoticeForLab($db, $copyLabId, null);
            if ($startedTx && $db->inTransaction()) {
                $db->commit();
            }
            $updated++;
            $results[] = [
                'lab_id' => $copyLabId,
                'lab_name' => (string) ($copy['name'] ?? ''),
                'username' => (string) ($copy['username'] ?? ''),
                'status' => 'updated',
                'check_items_synced' => (int) ($stats['check_items_synced'] ?? 0),
                'check_items_skipped' => (int) ($stats['check_items_skipped'] ?? 0),
            ];
        } catch (Throwable $e) {
            if ($startedTx && $db->inTransaction()) {
                $db->rollBack();
            }
            $failed++;
            $results[] = [
                'lab_id' => $copyLabId,
                'lab_name' => (string) ($copy['name'] ?? ''),
                'username' => (string) ($copy['username'] ?? ''),
                'status' => 'failed',
                'error' => trim((string) $e->getMessage()) !== '' ? trim((string) $e->getMessage()) : 'sync_failed',
            ];
        }
    }

    return [
        'source_lab_id' => $sourceLabId,
        'source_lab_name' => (string) ($sourceMeta['name'] ?? ''),
        'copies_total' => count($copies),
        'updated' => $updated,
        'reset_required' => $resetRequired,
        'failed' => $failed,
        'synced_at' => gmdate('c'),
        'results' => $results,
    ];
}
