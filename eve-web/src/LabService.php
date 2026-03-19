<?php

declare(strict_types=1);

function viewerIsAdmin(array $viewer): bool
{
    $role = strtolower(trim((string) ($viewer['role_name'] ?? $viewer['role'] ?? '')));
    return $role === 'admin';
}

function viewerCanManageClouds(array $viewer): bool
{
    return true;
}

function viewerCanViewAllCloudPnets(PDO $db, array $viewer): bool
{
    if (viewerIsAdmin($viewer)) {
        return true;
    }
    return rbacUserHasPermission($db, $viewer, 'cloudmgmt.pnet.view_all');
}

function listViewerCloudProfiles(PDO $db, string $viewerUserId, bool $includeAll = false): array
{
    if ($includeAll) {
        $stmt = $db->prepare(
            "SELECT c.id,
                    c.name,
                    c.pnet,
                    COALESCE(string_agg(DISTINCT u.username, ', ' ORDER BY u.username), '') AS usernames
             FROM clouds c
             LEFT JOIN cloud_users cu ON cu.cloud_id = c.id
             LEFT JOIN users u ON u.id = cu.user_id
             GROUP BY c.id, c.name, c.pnet
             ORDER BY c.name ASC, c.pnet ASC"
        );
        $stmt->execute();
    } else {
        if ($viewerUserId === '') {
            return [];
        }
        $stmt = $db->prepare(
            "SELECT c.id,
                    c.name,
                    c.pnet,
                    COALESCE(u.username, '') AS usernames
             FROM cloud_users cu
             INNER JOIN clouds c ON c.id = cu.cloud_id
             INNER JOIN users u ON u.id = cu.user_id
             WHERE cu.user_id = :user_id
             ORDER BY c.name ASC, c.pnet ASC"
        );
        $stmt->bindValue(':user_id', $viewerUserId, PDO::PARAM_STR);
        $stmt->execute();
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $result = [];
    foreach ($rows as $row) {
        $pnet = normalizeLabPnetValue((string) ($row['pnet'] ?? ''));
        if ($pnet === '') {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = strtoupper($pnet);
        }
        $usernames = trim((string) ($row['usernames'] ?? ''));
        $meta = $pnet;
        if ($usernames !== '') {
            $meta .= '; ' . $usernames;
        }
        $result[] = [
            'cloud_id' => (string) ($row['id'] ?? ''),
            'name' => $name,
            'pnet' => $pnet,
            'usernames' => $usernames,
            'label' => $name . ' (' . $meta . ')',
        ];
    }
    return $result;
}

function listViewerCloudPnetSet(PDO $db, string $viewerUserId): array
{
    $profiles = listViewerCloudProfiles($db, $viewerUserId);
    $set = [];
    foreach ($profiles as $profile) {
        $pnet = strtolower(trim((string) ($profile['pnet'] ?? '')));
        if ($pnet !== '') {
            $set[$pnet] = true;
        }
    }
    return $set;
}

function viewerCanViewLab(PDO $db, array $viewer, string $labId): bool
{
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        return false;
    }
    $sql = "SELECT 1
            FROM labs l
            LEFT JOIN lab_shared_users su ON su.lab_id = l.id AND su.user_id = :viewer_id
            WHERE l.id = :lab_id";
    if (!viewerIsAdmin($viewer)) {
        $sql .= " AND (l.author_user_id = :viewer_id OR su.user_id IS NOT NULL)";
    }
    $sql .= " LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function viewerCanEditLab(PDO $db, array $viewer, string $labId): bool
{
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        return false;
    }
    if (viewerIsAdmin($viewer)) {
        return true;
    }

    $stmt = $db->prepare(
        "SELECT 1
         FROM labs l
         LEFT JOIN lab_shared_users su ON su.lab_id = l.id AND su.user_id = :viewer_id
         WHERE l.id = :lab_id
           AND (
             l.author_user_id = :viewer_id
             OR (l.is_shared = TRUE AND l.collaborate_allowed = TRUE AND su.user_id IS NOT NULL)
           )
         LIMIT 1"
    );
    $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

const LAB_LINK_LAYOUT_OBJECT_TYPE = 'link_layout';
const LAB_LINK_LAYOUT_OBJECT_NAME = '__link_layout_v1__';

function formatLabPortDisplayName(string $nodeType, string $portName, string $template = '', string $image = ''): string
{
    $nodeType = strtolower(trim($nodeType));
    $portName = trim($portName);
    $template = strtolower(trim($template));
    $image = strtolower(trim($image));
    if ($portName === '') {
        return $portName;
    }
    $viosProfile = $template . ' ' . $image;
    $isVios = ($nodeType === 'qemu')
        && (
            strpos($viosProfile, 'vios') !== false
            || strpos($viosProfile, 'iosv') !== false
            || strpos($viosProfile, 'iosvl2') !== false
        );
    $isViosSwitch = $isVios
        && preg_match('/\b(viosl2|iosvl2|iosv[-_ ]?l2)\b/i', $viosProfile) === 1;
    if ($isVios) {
        if ($isViosSwitch) {
            if (preg_match('/^(?:gigabitethernet|gi|ethernet|eth|e)\s*([0-9]+)\s*\/\s*([0-9]+)$/i', $portName, $m) === 1) {
                $major = (int) $m[1];
                $minor = (int) $m[2];
                $flat = ($major * 4) + $minor;
                $group = (int) floor($flat / 4);
                $offset = $flat % 4;
                return 'Gi' . $group . '/' . $offset;
            }
            if (preg_match('/^(?:gigabitethernet|gi|ethernet|eth|e)\s*([0-9]+)$/i', $portName, $m) === 1) {
                $idx = (int) $m[1];
                $major = (int) floor($idx / 4);
                $minor = $idx % 4;
                return 'Gi' . $major . '/' . $minor;
            }
        } else {
            if (preg_match('/^(?:gigabitethernet|gi)\s*([0-9]+)\s*\/\s*([0-9]+)$/i', $portName, $m) === 1) {
                $major = (int) $m[1];
                $minor = (int) $m[2];
                $flat = ($major * 4) + $minor;
                return 'Gi0/' . $flat;
            }
            if (preg_match('/^(?:ethernet|eth|e)\s*([0-9]+)\s*\/\s*([0-9]+)$/i', $portName, $m) === 1) {
                $major = (int) $m[1];
                $minor = (int) $m[2];
                $flat = ($major * 4) + $minor;
                return 'Gi0/' . $flat;
            }
            if (preg_match('/^(?:gigabitethernet|gi|ethernet|eth|e)\s*([0-9]+)$/i', $portName, $m) === 1) {
                $idx = (int) $m[1];
                return 'Gi0/' . $idx;
            }
        }
    }
    return $portName;
}

function v2TemplateDir(): string
{
    $platform = 'intel';
    $family = @file_get_contents('/opt/unetlab/platform');
    if (is_string($family) && trim($family) === 'svm') {
        $platform = 'amd';
    }
    return '/opt/unetlab/runtime/templates/' . $platform;
}

function listNodeIconsV2(): array
{
    $iconsDir = '/opt/unetlab/runtime/images/icons';
    $result = [];
    if (!is_dir($iconsDir)) {
        return $result;
    }
    foreach (scandir($iconsDir) ?: [] as $filename) {
        if ($filename === '.' || $filename === '..') {
            continue;
        }
        $path = $iconsDir . '/' . $filename;
        if (!is_file($path) || !preg_match('/\.(svg|png|jpg)$/i', $filename)) {
            continue;
        }
        $result[$filename] = preg_replace('/\.(svg|png|jpg)$/i', '', $filename);
    }
    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function listNodeConfigTemplatesV2(): array
{
    $configsDir = '/opt/unetlab/runtime/configs';
    $result = [];
    if (!is_dir($configsDir)) {
        return $result;
    }
    foreach (scandir($configsDir) ?: [] as $filename) {
        if ($filename === '.' || $filename === '..') {
            continue;
        }
        $path = $configsDir . '/' . $filename;
        if (!is_file($path) || !preg_match('/\.php$/i', $filename)) {
            continue;
        }
        $result[$filename] = preg_replace('/\.php$/i', '', $filename);
    }
    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function listNodeImagesV2(string $type, string $template, bool $allowFallback = true): array
{
	$result = [];
	$type = strtolower(trim($type));
	if ($type === 'qemu') {
        $dir = '/opt/unetlab/addons/qemu';
        if (is_dir($dir)) {
            $allDirs = [];
            foreach (scandir($dir) ?: [] as $folder) {
                if ($folder === '.' || $folder === '..') {
                    continue;
                }
                if (!is_dir($dir . '/' . $folder)) {
                    continue;
                }
                $allDirs[] = $folder;
                if (preg_match('/^' . preg_quote($template, '/') . '-.+$/i', $folder)) {
                    $result[$folder] = $folder;
                }
            }
            if ($allowFallback && empty($result) && !empty($allDirs)) {
                $token = preg_quote($template, '/');
                foreach ($allDirs as $folder) {
                    if (preg_match('/(^|[-_.])' . $token . '([-.]|$)/i', $folder)) {
                        $result[$folder] = $folder;
                    }
                }
            }
            if ($allowFallback && empty($result) && !empty($allDirs)) {
                foreach ($allDirs as $folder) {
                    $result[$folder] = $folder;
                }
            }
        }
	} elseif ($type === 'docker') {
        $cmd = '/usr/bin/docker -H=tcp://127.0.0.1:4243 images --format "{{.Repository}}:{{.Tag}}" 2>/dev/null';
        $lines = [];
        @exec($cmd, $lines, $rc);
        if (is_array($lines) && $rc === 0) {
            foreach ($lines as $line) {
                $image = trim((string) $line);
                if ($image !== '') {
                    $result[$image] = $image;
                }
            }
        }
    } elseif ($type === 'vpcs') {
        $result[''] = '';
    }

    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function listNodeTemplatesV2(): array
{
    $tplDir = v2TemplateDir();
    $result = [];
    if (!is_dir($tplDir)) {
        return $result;
    }

    foreach (scandir($tplDir) ?: [] as $file) {
        if (!preg_match('/^(.+)\.yml$/i', $file, $m)) {
            continue;
        }
        $template = $m[1];
        $data = @yaml_parse_file($tplDir . '/' . $file);
        if (!is_array($data)) {
            continue;
        }
		$type = strtolower(trim((string) ($data['type'] ?? 'qemu')));
		if (!in_array($type, ['qemu', 'docker', 'vpcs'], true)) {
			continue;
		}
		$description = (string) ($data['description'] ?? $template);
		$images = listNodeImagesV2($type, $template, false);
		$available = ($type === 'vpcs') || !empty($images);
        if (!$available) {
            continue;
        }
        $result[] = [
            'template' => $template,
            'type' => $type,
            'description' => $description,
            'available' => $available,
            'images_count' => count($images),
        ];
    }

    usort($result, static function (array $a, array $b): int {
        return strnatcasecmp((string) $a['description'], (string) $b['description']);
    });
    return $result;
}

function getNodeTemplateV2(string $template): ?array
{
    $template = trim($template);
    if ($template === '') {
        return null;
    }
    $path = v2TemplateDir() . '/' . $template . '.yml';
    if (!is_file($path)) {
        return null;
    }
    $data = @yaml_parse_file($path);
    if (!is_array($data)) {
        return null;
    }
    $data['template'] = $template;
    return $data;
}

function getNodeTemplateOptionsV2(string $template): array
{
    $tpl = getNodeTemplateV2($template);
    if (!is_array($tpl)) {
        throw new RuntimeException('Template not found');
    }

    $type = strtolower(trim((string) ($tpl['type'] ?? 'qemu')));
    $icons = listNodeIconsV2();
    $images = listNodeImagesV2($type, $template, true);
    $options = [
        'name' => ['type' => 'input', 'value' => (string) ($tpl['name'] ?? 'Node')],
        'icon' => ['type' => 'list', 'value' => (string) ($tpl['icon'] ?? 'Router-2D-Gen-White-S.svg'), 'list' => $icons],
    ];

    if ($type !== 'vpcs') {
        $options['image'] = [
            'type' => 'list',
            'value' => (string) ($tpl['image'] ?? (count($images) ? (string) array_key_last($images) : '')),
            'list' => $images,
        ];
    }
	if (in_array($type, ['qemu', 'docker'], true)) {
		$defaultRam = 1024;
		$options['ram'] = ['type' => 'input', 'value' => (int) ($tpl['ram'] ?? $defaultRam)];
	}
    if ($type === 'qemu') {
        $options['cpu'] = ['type' => 'input', 'value' => (int) ($tpl['cpu'] ?? 1)];
        $options['ethernet'] = ['type' => 'input', 'value' => (int) ($tpl['ethernet'] ?? 2)];
        $options['console'] = ['type' => 'list', 'value' => (string) ($tpl['console'] ?? 'telnet'), 'list' => ['telnet' => 'telnet', 'vnc' => 'vnc', 'rdp' => 'rdp']];
        $options['qemu_arch'] = ['type' => 'list', 'value' => (string) ($tpl['qemu_arch'] ?? 'x86_64'), 'list' => ['i386' => 'i386', 'x86_64' => 'x86_64']];
        $options['qemu_version'] = [
            'type' => 'list',
            'value' => (string) ($tpl['qemu_version'] ?? '2.4.0'),
            'list' => ['1.3.1' => '1.3.1', '2.0.2' => '2.0.2', '2.2.0' => '2.2.0', '2.4.0' => '2.4.0', '2.5.0' => '2.5.0', '2.6.2' => '2.6.2', '2.12.0' => '2.12.0', '3.1.0' => '3.1.0', '4.1.0' => '4.1.0', '5.2.0' => '5.2.0', '6.0.0' => '6.0.0'],
        ];
        $options['qemu_nic'] = ['type' => 'list', 'value' => (string) ($tpl['qemu_nic'] ?? 'e1000'), 'list' => ['virtio-net-pci' => 'virtio-net-pci', 'e1000' => 'e1000', 'i82559er' => 'i82559er', 'rtl8139' => 'rtl8139', 'e1000-82545em' => 'e1000-82545em', 'vmxnet3' => 'vmxnet3']];
        $options['qemu_options'] = ['type' => 'input', 'value' => (string) ($tpl['qemu_options'] ?? '')];
    }
	if ($type === 'docker') {
		$options['ethernet'] = ['type' => 'input', 'value' => (int) ($tpl['ethernet'] ?? 1)];
	}
    if (in_array($template, ['bigip', 'firepower6', 'firepower'], true)) {
        $options['firstmac'] = ['type' => 'input', 'value' => (string) ($tpl['firstmac'] ?? '')];
    }

    return [
        'template' => $template,
        'description' => (string) ($tpl['description'] ?? $template),
        'type' => $type,
        'options' => $options,
    ];
}

function nodeImageMatchesTemplateList(string $image, array $images): bool
{
    if ($image === '') {
        return true;
    }
    if (array_key_exists($image, $images)) {
        return true;
    }
    foreach ($images as $key => $_label) {
        if (strcasecmp((string) $key, $image) === 0) {
            return true;
        }
    }
    return false;
}

function validateNodeImageForTemplate(string $nodeType, string $template, string $image): void
{
    $nodeType = strtolower(trim($nodeType));
    $template = trim($template);
    $image = trim($image);

    if ($image === '' || $template === '' || $nodeType === 'vpcs') {
        return;
    }

    $images = listNodeImagesV2($nodeType, $template, true);
    if (empty($images)) {
        return;
    }

    if (!nodeImageMatchesTemplateList($image, $images)) {
        throw new InvalidArgumentException('image_invalid_for_template');
    }
}

function decodeLabObjectDataBase64(string $encoded)
{
    $encoded = trim($encoded);
    if ($encoded === '') {
        return [];
    }
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || $decoded === '') {
        return [];
    }
    $json = json_decode($decoded, true);
    if (is_array($json)) {
        return $json;
    }
    return [
        'raw' => $decoded,
    ];
}

function normalizeLabLinkLayoutState(array $payload): array
{
    $labelsRaw = is_array($payload['labels'] ?? null) ? (array) $payload['labels'] : [];
    $bendsRaw = is_array($payload['bends'] ?? null) ? (array) $payload['bends'] : [];

    $labels = [];
    $labelsCount = 0;
    foreach ($labelsRaw as $keyRaw => $rowRaw) {
        if ($labelsCount >= 2000) {
            break;
        }
        $key = trim((string) $keyRaw);
        if ($key === '' || strlen($key) > 190 || !is_array($rowRaw)) {
            continue;
        }
        $t = isset($rowRaw['t']) ? (float) $rowRaw['t'] : 0.5;
        if (!is_finite($t)) {
            $t = 0.5;
        }
        if ($t < 0.02) {
            $t = 0.02;
        } elseif ($t > 0.98) {
            $t = 0.98;
        }
        $labels[$key] = ['t' => $t];
        $labelsCount++;
    }

    $bends = [];
    $bendsCount = 0;
    foreach ($bendsRaw as $keyRaw => $rowRaw) {
        if ($bendsCount >= 2000) {
            break;
        }
        $key = trim((string) $keyRaw);
        if ($key === '' || strlen($key) > 190 || !is_array($rowRaw)) {
            continue;
        }
        $x = isset($rowRaw['x']) ? (float) $rowRaw['x'] : 0.0;
        $y = isset($rowRaw['y']) ? (float) $rowRaw['y'] : 0.0;
        if (!is_finite($x)) {
            $x = 0.0;
        }
        if (!is_finite($y)) {
            $y = 0.0;
        }
        if ($x < -200000.0) {
            $x = -200000.0;
        } elseif ($x > 200000.0) {
            $x = 200000.0;
        }
        if ($y < -200000.0) {
            $y = -200000.0;
        } elseif ($y > 200000.0) {
            $y = 200000.0;
        }

        $pointsRaw = is_array($rowRaw['points'] ?? null) ? (array) $rowRaw['points'] : [];
        $points = [];
        $pointsCount = 0;
        foreach ($pointsRaw as $pointRaw) {
            if ($pointsCount >= 128) {
                break;
            }
            if (!is_array($pointRaw)) {
                continue;
            }
            $px = isset($pointRaw['x']) ? (float) $pointRaw['x'] : null;
            $py = isset($pointRaw['y']) ? (float) $pointRaw['y'] : null;
            if (!is_finite((float) $px) || !is_finite((float) $py)) {
                continue;
            }
            $kind = strtolower(trim((string) ($pointRaw['kind'] ?? 'bend')));
            if ($kind !== 'stop') {
                $kind = 'bend';
            }
            $px = (float) $px;
            $py = (float) $py;
            if ($px < -200000.0) {
                $px = -200000.0;
            } elseif ($px > 200000.0) {
                $px = 200000.0;
            }
            if ($py < -200000.0) {
                $py = -200000.0;
            } elseif ($py > 200000.0) {
                $py = 200000.0;
            }
            $points[] = [
                'x' => $px,
                'y' => $py,
                'kind' => $kind,
            ];
            $pointsCount++;
        }

        $bends[$key] = [
            'x' => $x,
            'y' => $y,
            'points' => $points,
        ];
        $bendsCount++;
    }

    return [
        'labels' => $labels,
        'bends' => $bends,
    ];
}

function decodeLabLinkLayoutStateBase64(string $encoded): array
{
    $encoded = trim($encoded);
    if ($encoded === '') {
        return ['labels' => [], 'bends' => []];
    }
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || $decoded === '') {
        return ['labels' => [], 'bends' => []];
    }
    $json = json_decode($decoded, true);
    if (!is_array($json)) {
        return ['labels' => [], 'bends' => []];
    }
    return normalizeLabLinkLayoutState($json);
}

function loadLabLinkLayoutState(PDO $db, string $labId): array
{
    $stmt = $db->prepare(
        "SELECT data_base64
         FROM lab_objects
         WHERE lab_id = :lab_id
           AND object_type = :object_type
           AND name = :name
         ORDER BY updated_at DESC, created_at DESC
         LIMIT 1"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->bindValue(':object_type', LAB_LINK_LAYOUT_OBJECT_TYPE, PDO::PARAM_STR);
    $stmt->bindValue(':name', LAB_LINK_LAYOUT_OBJECT_NAME, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['labels' => [], 'bends' => []];
    }
    return decodeLabLinkLayoutStateBase64((string) ($row['data_base64'] ?? ''));
}

function saveLabLinkLayoutState(PDO $db, array $viewer, string $labId, array $payload): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $layoutPayload = is_array($payload['layout'] ?? null) ? (array) $payload['layout'] : $payload;
    $normalized = normalizeLabLinkLayoutState($layoutPayload);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Link layout encode failed');
    }
    $dataBase64 = base64_encode($json);

    $idsStmt = $db->prepare(
        "SELECT id
         FROM lab_objects
         WHERE lab_id = :lab_id
           AND object_type = :object_type
           AND name = :name
         ORDER BY created_at ASC, id ASC"
    );
    $idsStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $idsStmt->bindValue(':object_type', LAB_LINK_LAYOUT_OBJECT_TYPE, PDO::PARAM_STR);
    $idsStmt->bindValue(':name', LAB_LINK_LAYOUT_OBJECT_NAME, PDO::PARAM_STR);
    $idsStmt->execute();
    $existingRows = $idsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($existingRows)) {
        $existingRows = [];
    }

    if (count($existingRows) === 0) {
        $insertStmt = $db->prepare(
            "INSERT INTO lab_objects (lab_id, object_type, name, data_base64)
             VALUES (:lab_id, :object_type, :name, :data_base64)"
        );
        $insertStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $insertStmt->bindValue(':object_type', LAB_LINK_LAYOUT_OBJECT_TYPE, PDO::PARAM_STR);
        $insertStmt->bindValue(':name', LAB_LINK_LAYOUT_OBJECT_NAME, PDO::PARAM_STR);
        $insertStmt->bindValue(':data_base64', $dataBase64, PDO::PARAM_STR);
        $insertStmt->execute();
        return $normalized;
    }

    $primaryId = (string) ($existingRows[0]['id'] ?? '');
    if ($primaryId !== '') {
        $updateStmt = $db->prepare(
            "UPDATE lab_objects
             SET data_base64 = :data_base64
             WHERE id = :id"
        );
        $updateStmt->bindValue(':data_base64', $dataBase64, PDO::PARAM_STR);
        $updateStmt->bindValue(':id', $primaryId, PDO::PARAM_STR);
        $updateStmt->execute();
    }

    if (count($existingRows) > 1) {
        $deleteStmt = $db->prepare("DELETE FROM lab_objects WHERE id = :id");
        foreach (array_slice($existingRows, 1) as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $deleteStmt->bindValue(':id', $id, PDO::PARAM_STR);
            $deleteStmt->execute();
        }
    }

    return $normalized;
}

function getLabEditorData(PDO $db, array $viewer, string $labId, bool $previewMode = false): array
{
    $viewerId = (string) ($viewer['id'] ?? '');
    if ($viewerId === '') {
        throw new InvalidArgumentException('Invalid viewer');
    }

    $labSql = "SELECT l.id,
                      l.name,
                      l.description,
                      l.author_user_id,
                      a.username AS author_username,
                      l.is_shared,
                      l.source_lab_id,
                      l.collaborate_allowed,
                      l.topology_locked,
                      l.topology_allow_wipe,
                      l.created_at,
                      l.updated_at
               FROM labs l
               INNER JOIN users a ON a.id = l.author_user_id
               LEFT JOIN lab_shared_users su ON su.lab_id = l.id AND su.user_id = :viewer_id
               WHERE l.id = :lab_id";
    if (!viewerIsAdmin($viewer)) {
        $labSql .= " AND (l.author_user_id = :viewer_id OR su.user_id IS NOT NULL)";
    }
    $labSql .= " LIMIT 1";

    $labStmt = $db->prepare($labSql);
    $labStmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_STR);
    $labStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $labStmt->execute();
    $lab = $labStmt->fetch(PDO::FETCH_ASSOC);
    if ($lab === false) {
        throw new RuntimeException('Lab not found');
    }

    if (function_exists('refreshLabRuntimeStatesForLab')) {
        refreshLabRuntimeStatesForLab($db, $labId);
    }

    $nodesStmt = $db->prepare(
        "SELECT id, name, node_type, template, icon, image, console, left_pos, top_pos,
                ethernet_count, serial_count,
                is_running, power_state, last_error, power_updated_at, updated_at,
                runtime_pid, runtime_console_port
         FROM lab_nodes
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC"
    );
    $nodesStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $nodesStmt->execute();
    $nodes = $nodesStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($nodes)) {
        $nodes = [];
    }

    $networksStmt = $db->prepare(
        "SELECT id, name, network_type, left_pos, top_pos, icon, updated_at
         FROM lab_networks
         WHERE lab_id = :lab_id
         ORDER BY created_at ASC"
    );
    $networksStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $networksStmt->execute();
    $networks = $networksStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($networks)) {
        $networks = [];
    }

    $objectsStmt = $db->prepare(
        "SELECT id, object_type, name, data_base64, updated_at
         FROM lab_objects
         WHERE lab_id = :lab_id
           AND object_type IN ('text', 'shape')
         ORDER BY created_at ASC"
    );
    $objectsStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $objectsStmt->execute();
    $objects = $objectsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($objects)) {
        $objects = [];
    }

    $attachmentsStmt = $db->prepare(
        "SELECT p.id,
                p.node_id,
                p.network_id,
                p.name AS port_name,
                n.node_type,
                n.template,
                n.image,
                nw.name AS network_name,
                nw.network_type
         FROM lab_node_ports p
         INNER JOIN lab_nodes n ON n.id = p.node_id
         LEFT JOIN lab_networks nw ON nw.id = p.network_id
         WHERE n.lab_id = :lab_id
           AND p.network_id IS NOT NULL
         ORDER BY p.created_at ASC"
    );
    $attachmentsStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $attachmentsStmt->execute();
    $attachments = $attachmentsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($attachments)) {
        $attachments = [];
    }

    if (!viewerIsAdmin($viewer)) {
        $viewerCanSeeAllCloudPnets = viewerCanViewAllCloudPnets($db, $viewer);
        $viewerCloudPnets = $viewerCanSeeAllCloudPnets ? [] : listViewerCloudPnetSet($db, $viewerId);
        $isSharedPreviewContext = $previewMode
            && !empty($lab['is_shared'])
            && (string) ($lab['author_user_id'] ?? '') !== $viewerId;

        $normalizedNetworks = [];
        foreach ($networks as $row) {
            $networkType = strtolower(trim((string) ($row['network_type'] ?? '')));
            if (!isCloudNetworkType($networkType) || $networkType === 'cloud' || isset($viewerCloudPnets[$networkType])) {
                $normalizedNetworks[] = $row;
                continue;
            }
            if ($isSharedPreviewContext) {
                $row['network_type'] = 'cloud';
                $normalizedNetworks[] = $row;
            }
        }
        $networks = $normalizedNetworks;

        $normalizedAttachments = [];
        foreach ($attachments as $row) {
            $networkType = strtolower(trim((string) ($row['network_type'] ?? '')));
            if (!isCloudNetworkType($networkType) || $networkType === 'cloud' || isset($viewerCloudPnets[$networkType])) {
                $normalizedAttachments[] = $row;
                continue;
            }
            if ($isSharedPreviewContext) {
                $row['network_type'] = 'cloud';
                if (!isset($row['network_name']) || trim((string) $row['network_name']) === '') {
                    $row['network_name'] = 'Cloud';
                }
                $normalizedAttachments[] = $row;
            }
        }
        $attachments = $normalizedAttachments;
    }

    $linkLayout = loadLabLinkLayoutState($db, $labId);
    $canEdit = viewerCanEditLab($db, $viewer, $labId);

    return [
        'can_edit' => $canEdit,
        'lab' => [
            'id' => (string) $lab['id'],
            'name' => (string) $lab['name'],
            'description' => (string) ($lab['description'] ?? ''),
            'author_user_id' => (string) $lab['author_user_id'],
            'author_username' => (string) $lab['author_username'],
            'is_shared' => (bool) $lab['is_shared'],
            'source_lab_id' => isset($lab['source_lab_id']) && $lab['source_lab_id'] !== null ? (string) $lab['source_lab_id'] : null,
            'collaborate_allowed' => (bool) $lab['collaborate_allowed'],
            'topology_locked' => (bool) ($lab['topology_locked'] ?? false),
            'topology_allow_wipe' => (bool) ($lab['topology_allow_wipe'] ?? false),
            'created_at' => (string) $lab['created_at'],
            'updated_at' => (string) $lab['updated_at'],
        ],
        'nodes' => array_map(static function ($row) {
            return [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'node_type' => (string) $row['node_type'],
                'template' => (string) ($row['template'] ?? ''),
                'icon' => (string) ($row['icon'] ?? ''),
                'image' => (string) ($row['image'] ?? ''),
                'console' => (string) ($row['console'] ?? ''),
                'left' => isset($row['left_pos']) ? (int) $row['left_pos'] : 0,
                'top' => isset($row['top_pos']) ? (int) $row['top_pos'] : 0,
                'ethernet' => isset($row['ethernet_count']) ? (int) $row['ethernet_count'] : 0,
                'serial' => isset($row['serial_count']) ? (int) $row['serial_count'] : 0,
                'is_running' => !empty($row['is_running']),
                'power_state' => (string) ($row['power_state'] ?? (!empty($row['is_running']) ? 'running' : 'stopped')),
                'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : null,
                'power_updated_at' => isset($row['power_updated_at']) ? (string) $row['power_updated_at'] : null,
                'runtime_pid' => isset($row['runtime_pid']) ? ((int) $row['runtime_pid'] ?: null) : null,
                'runtime_console_port' => isset($row['runtime_console_port']) ? ((int) $row['runtime_console_port'] ?: null) : null,
                'updated_at' => (string) $row['updated_at'],
            ];
        }, $nodes),
        'networks' => array_map(static function ($row) {
            return [
                'id' => (string) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'network_type' => (string) ($row['network_type'] ?? ''),
                'icon' => (string) ($row['icon'] ?? ''),
                'left' => isset($row['left_pos']) ? (int) $row['left_pos'] : 0,
                'top' => isset($row['top_pos']) ? (int) $row['top_pos'] : 0,
                'updated_at' => (string) $row['updated_at'],
            ];
        }, $networks),
        'attachments' => array_map(static function ($row) {
            $portName = (string) ($row['port_name'] ?? '');
            $nodeType = (string) ($row['node_type'] ?? '');
            return [
                'id' => (string) $row['id'],
                'node_id' => (string) $row['node_id'],
                'network_id' => (string) $row['network_id'],
                'port_name' => $portName,
                'port_label' => formatLabPortDisplayName(
                    $nodeType,
                    $portName,
                    (string) ($row['template'] ?? ''),
                    (string) ($row['image'] ?? '')
                ),
                'network_name' => isset($row['network_name']) ? (string) $row['network_name'] : '',
                'network_type' => isset($row['network_type']) ? (string) $row['network_type'] : '',
            ];
        }, $attachments),
        'objects' => array_map(static function ($row) {
            $dataBase64 = (string) ($row['data_base64'] ?? '');
            return [
                'id' => (string) $row['id'],
                'object_type' => (string) ($row['object_type'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'data_base64' => $dataBase64,
                'data' => decodeLabObjectDataBase64($dataBase64),
                'updated_at' => (string) $row['updated_at'],
            ];
        }, $objects),
        'link_layout' => $linkLayout,
    ];
}

function labWipeAllowedByPolicy(PDO $db, array $viewer, string $labId): bool
{
    if (viewerIsAdmin($viewer)) {
        return true;
    }
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerId === '') {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT id, author_user_id, source_lab_id, topology_locked, topology_allow_wipe
         FROM labs
         WHERE id = :lab_id
         LIMIT 1"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $lab = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lab === false) {
        return false;
    }

    $isRecipientContext = !empty($lab['source_lab_id']) || (string) ($lab['author_user_id'] ?? '') !== $viewerId;
    $topologyLocked = !empty($lab['topology_locked']);
    $topologyAllowWipe = !empty($lab['topology_allow_wipe']);
    if ($isRecipientContext && $topologyLocked && !$topologyAllowWipe) {
        return false;
    }
    return true;
}

function createLabNode(PDO $db, array $viewer, string $labId, array $payload): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $template = trim((string) ($payload['template'] ?? ''));
    if ($template === '') {
        throw new InvalidArgumentException('template_required');
    }
    $tpl = getNodeTemplateV2($template);
    if (!is_array($tpl)) {
        throw new InvalidArgumentException('template_invalid');
    }

    $nodeType = strtolower(trim((string) ($tpl['type'] ?? ($payload['node_type'] ?? 'qemu'))));
	if (!in_array($nodeType, ['qemu', 'docker', 'vpcs'], true)) {
		throw new InvalidArgumentException('node_type_invalid');
	}

    $count = (int) ($payload['number_nodes'] ?? ($payload['count'] ?? 1));
    if ($count < 1) {
        $count = 1;
    }
    if ($count > 10) {
        throw new InvalidArgumentException('count_limit_per_request');
    }
    $maxNodesPerLab = 100;

    $name = trim((string) ($payload['name'] ?? ($tpl['name'] ?? 'Node')));
    $left = max(0, (int) ($payload['left'] ?? 0));
    $top = max(0, (int) ($payload['top'] ?? 0));
    $icon = trim((string) ($payload['icon'] ?? ($tpl['icon'] ?? 'Router-2D-Gen-White-S.svg')));
    $image = trim((string) ($payload['image'] ?? ($tpl['image'] ?? '')));
    $cpu = (int) ($payload['cpu'] ?? ($tpl['cpu'] ?? 1));
    $ramMb = (int) ($payload['ram'] ?? ($tpl['ram'] ?? 1024));
    $nvramMb = (int) ($payload['nvram'] ?? ($tpl['nvram'] ?? 0));
    $ethernetCount = (int) ($payload['ethernet'] ?? ($payload['ethernet_count'] ?? ($tpl['ethernet'] ?? 2)));
    $serialCount = (int) ($payload['serial'] ?? ($tpl['serial'] ?? 0));
    $console = trim((string) ($payload['console'] ?? ($tpl['console'] ?? 'telnet')));
    $firstMac = trim((string) ($payload['firstmac'] ?? ''));
    $qemuOptions = trim((string) ($payload['qemu_options'] ?? ($tpl['qemu_options'] ?? '')));
    $qemuVersion = trim((string) ($payload['qemu_version'] ?? ($tpl['qemu_version'] ?? '')));
    $qemuArch = trim((string) ($payload['qemu_arch'] ?? ($tpl['qemu_arch'] ?? '')));
    $qemuNic = trim((string) ($payload['qemu_nic'] ?? ($tpl['qemu_nic'] ?? '')));
    validateNodeImageForTemplate($nodeType, $template, $image);

    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }
    if (!preg_match('/^[\p{L}\p{N}_\-\.\(\)\s]{1,255}$/u', $name)) {
        throw new InvalidArgumentException('name_invalid');
    }
    if ($nodeType === 'vpcs') {
        $ethernetCount = 1;
        $serialCount = 0;
    } else {
        if ($ethernetCount < 0 || $ethernetCount > 128) {
            throw new InvalidArgumentException('ethernet_count_invalid');
        }
        if ($serialCount < 0 || $serialCount > 64) {
            throw new InvalidArgumentException('serial_count_invalid');
        }
    }
    $ethernetPortCount = $ethernetCount;

    $insertNode = $db->prepare(
        "INSERT INTO lab_nodes (
            lab_id,
            name,
            node_type,
            template,
            image,
            icon,
            console,
            cpu,
            ram_mb,
            nvram_mb,
            first_mac,
            qemu_options,
            qemu_version,
            qemu_arch,
            qemu_nic,
            left_pos,
            top_pos,
            ethernet_count,
            serial_count,
            is_running
         ) VALUES (
            :lab_id,
            :name,
            :node_type,
            :template,
            :image,
            :icon,
            :console,
            :cpu,
            :ram_mb,
            :nvram_mb,
            :first_mac,
            :qemu_options,
            :qemu_version,
            :qemu_arch,
            :qemu_nic,
            :left_pos,
            :top_pos,
            :ethernet_count,
            :serial_count,
            FALSE
         )
         RETURNING id, name, node_type, template, icon, left_pos, top_pos, is_running, power_state, last_error, power_updated_at, updated_at"
    );

    $insertPort = $db->prepare(
        "INSERT INTO lab_node_ports (node_id, name, port_type, network_id)
         VALUES (:node_id, :name, :port_type, NULL)"
    );
    $lockLabStmt = $db->prepare(
        "SELECT id
         FROM labs
         WHERE id = :lab_id
         FOR UPDATE"
    );
    $labNodesCountStmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM lab_nodes
         WHERE lab_id = :lab_id"
    );

    $created = [];
    $relink = ['queued' => [], 'skipped' => []];
    $relink = ['queued' => [], 'skipped' => []];
    $db->beginTransaction();
    try {
        $lockLabStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $lockLabStmt->execute();
        if ($lockLabStmt->fetch(PDO::FETCH_ASSOC) === false) {
            throw new RuntimeException('Lab not found');
        }

        $labNodesCountStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $labNodesCountStmt->execute();
        $existingNodesCount = (int) $labNodesCountStmt->fetchColumn();
        if (($existingNodesCount + $count) > $maxNodesPerLab) {
            throw new InvalidArgumentException('lab_nodes_limit_exceeded');
        }

        $nodesPerRow = 5;
        $horizontalStep = 150;
        $verticalStep = 80;
        for ($idx = 0; $idx < $count; $idx++) {
            $nodeLeft = $left + (($idx % $nodesPerRow) * $horizontalStep);
            $nodeTop = $top + ((int) floor($idx / $nodesPerRow) * $verticalStep);
            $nodeName = ($count > 1) ? ($name . ($idx + 1)) : $name;

            $insertNode->bindValue(':lab_id', $labId, PDO::PARAM_STR);
            $insertNode->bindValue(':name', $nodeName, PDO::PARAM_STR);
            $insertNode->bindValue(':node_type', $nodeType, PDO::PARAM_STR);
            $insertNode->bindValue(':template', $template, PDO::PARAM_STR);
            $insertNode->bindValue(':image', $image !== '' ? $image : null, $image !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertNode->bindValue(':icon', $icon, PDO::PARAM_STR);
            $insertNode->bindValue(':console', $console !== '' ? $console : null, $console !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertNode->bindValue(':cpu', $cpu > 0 ? $cpu : null, $cpu > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $insertNode->bindValue(':ram_mb', $ramMb > 0 ? $ramMb : null, $ramMb > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $insertNode->bindValue(':nvram_mb', $nvramMb > 0 ? $nvramMb : null, $nvramMb > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $insertNode->bindValue(':first_mac', $firstMac !== '' ? $firstMac : null, $firstMac !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertNode->bindValue(':qemu_options', $qemuOptions !== '' ? $qemuOptions : null, $qemuOptions !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertNode->bindValue(':qemu_version', $qemuVersion !== '' ? $qemuVersion : null, $qemuVersion !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertNode->bindValue(':qemu_arch', $qemuArch !== '' ? $qemuArch : null, $qemuArch !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertNode->bindValue(':qemu_nic', $qemuNic !== '' ? $qemuNic : null, $qemuNic !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertNode->bindValue(':left_pos', $nodeLeft, PDO::PARAM_INT);
            $insertNode->bindValue(':top_pos', $nodeTop, PDO::PARAM_INT);
            $insertNode->bindValue(':ethernet_count', $ethernetCount, PDO::PARAM_INT);
            $insertNode->bindValue(':serial_count', $serialCount, PDO::PARAM_INT);
            $insertNode->execute();

            $row = $insertNode->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('Insert failed');
            }

            if ($ethernetPortCount > 0) {
                for ($i = 0; $i < $ethernetPortCount; $i++) {
                    $insertPort->bindValue(':node_id', (string) $row['id'], PDO::PARAM_STR);
                    $insertPort->bindValue(':name', 'eth' . $i, PDO::PARAM_STR);
                    $insertPort->bindValue(':port_type', 'ethernet', PDO::PARAM_STR);
                    $insertPort->execute();
                }
            }
            if ($serialCount > 0) {
                for ($i = 0; $i < $serialCount; $i++) {
                    $insertPort->bindValue(':node_id', (string) $row['id'], PDO::PARAM_STR);
                    $insertPort->bindValue(':name', 'ser' . $i, PDO::PARAM_STR);
                    $insertPort->bindValue(':port_type', 'serial', PDO::PARAM_STR);
                    $insertPort->execute();
                }
            }

            $created[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'node_type' => (string) $row['node_type'],
                'template' => (string) ($row['template'] ?? ''),
                'icon' => (string) ($row['icon'] ?? ''),
                'left' => isset($row['left_pos']) ? (int) $row['left_pos'] : 0,
                'top' => isset($row['top_pos']) ? (int) $row['top_pos'] : 0,
                'ethernet' => $ethernetCount,
                'ethernet_ports' => $ethernetPortCount,
                'serial' => $serialCount,
                'is_running' => !empty($row['is_running']),
                'power_state' => (string) ($row['power_state'] ?? 'stopped'),
                'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : null,
                'power_updated_at' => isset($row['power_updated_at']) ? (string) $row['power_updated_at'] : null,
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'count' => count($created),
        'nodes' => $created,
    ];
}

function getLabNodeEditorData(PDO $db, array $viewer, string $labId, string $nodeId): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    if (function_exists('refreshLabNodeRuntimeState')) {
        refreshLabNodeRuntimeState($db, $labId, $nodeId);
    }

    $stmt = $db->prepare(
        "SELECT id,
                name,
                node_type,
                template,
                image,
                icon,
                console,
                cpu,
                ram_mb,
                nvram_mb,
                first_mac,
                qemu_options,
                qemu_version,
                qemu_arch,
                qemu_nic,
                left_pos,
                top_pos,
                ethernet_count,
                serial_count,
                runtime_pid,
                runtime_console_port,
                power_state,
                last_error,
                power_updated_at,
                is_running,
                updated_at
         FROM lab_nodes
         WHERE id = :node_id
           AND lab_id = :lab_id
         LIMIT 1"
    );
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Node not found');
    }

    return [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'node_type' => (string) ($row['node_type'] ?? ''),
        'template' => (string) ($row['template'] ?? ''),
        'image' => (string) ($row['image'] ?? ''),
        'icon' => (string) ($row['icon'] ?? ''),
        'console' => (string) ($row['console'] ?? ''),
        'cpu' => (int) ($row['cpu'] ?? 0),
        'ram' => (int) ($row['ram_mb'] ?? 0),
        'nvram' => (int) ($row['nvram_mb'] ?? 0),
        'firstmac' => (string) ($row['first_mac'] ?? ''),
        'qemu_options' => (string) ($row['qemu_options'] ?? ''),
        'qemu_version' => (string) ($row['qemu_version'] ?? ''),
        'qemu_arch' => (string) ($row['qemu_arch'] ?? ''),
        'qemu_nic' => (string) ($row['qemu_nic'] ?? ''),
        'left' => isset($row['left_pos']) ? (int) $row['left_pos'] : 0,
        'top' => isset($row['top_pos']) ? (int) $row['top_pos'] : 0,
        'ethernet' => (int) ($row['ethernet_count'] ?? 0),
        'serial' => (int) ($row['serial_count'] ?? 0),
        'runtime_pid' => isset($row['runtime_pid']) ? ((int) $row['runtime_pid'] ?: null) : null,
        'runtime_console_port' => isset($row['runtime_console_port']) ? ((int) $row['runtime_console_port'] ?: null) : null,
        'power_state' => (string) ($row['power_state'] ?? (!empty($row['is_running']) ? 'running' : 'stopped')),
        'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : null,
        'power_updated_at' => isset($row['power_updated_at']) ? (string) $row['power_updated_at'] : null,
        'is_running' => !empty($row['is_running']),
        'updated_at' => (string) $row['updated_at'],
    ];
}

function syncNodePortsCount(PDO $db, string $nodeId, string $portType, string $prefix, int $newCount): void
{
    $sel = $db->prepare(
        "SELECT id, name, network_id
         FROM lab_node_ports
         WHERE node_id = :node_id
           AND port_type = :port_type"
    );
    $sel->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $sel->bindValue(':port_type', $portType, PDO::PARAM_STR);
    $sel->execute();
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byIndex = [];
    foreach ($rows as $row) {
        $name = (string) ($row['name'] ?? '');
        if (preg_match('/^' . preg_quote($prefix, '/') . '([0-9]+)$/', $name, $m)) {
            $idx = (int) $m[1];
            $byIndex[$idx] = $row;
        }
    }

    $ins = $db->prepare(
        "INSERT INTO lab_node_ports (node_id, name, port_type, network_id)
         VALUES (:node_id, :name, :port_type, NULL)"
    );
    for ($i = 0; $i < $newCount; $i++) {
        if (isset($byIndex[$i])) {
            continue;
        }
        $ins->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
        $ins->bindValue(':name', $prefix . $i, PDO::PARAM_STR);
        $ins->bindValue(':port_type', $portType, PDO::PARAM_STR);
        $ins->execute();
    }

    if (!empty($byIndex)) {
        $del = $db->prepare("DELETE FROM lab_node_ports WHERE id = :id");
        foreach ($byIndex as $idx => $row) {
            if ($idx < $newCount) {
                continue;
            }
            if (!empty($row['network_id'])) {
                throw new RuntimeException('ports_in_use');
            }
            $del->bindValue(':id', (string) $row['id'], PDO::PARAM_STR);
            $del->execute();
        }
    }
}

function updateLabNode(PDO $db, array $viewer, string $labId, string $nodeId, array $payload): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $findStmt = $db->prepare(
        "SELECT id,
                name,
                icon,
                image,
                console,
                cpu,
                ram_mb,
                nvram_mb,
                first_mac,
                qemu_options,
                qemu_version,
                qemu_arch,
                qemu_nic,
                node_type,
                template,
                ethernet_count,
                serial_count,
                left_pos,
                top_pos
         FROM lab_nodes
         WHERE id = :node_id
           AND lab_id = :lab_id
         LIMIT 1"
    );
    $findStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $findStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $findStmt->execute();
    $current = $findStmt->fetch(PDO::FETCH_ASSOC);
    if ($current === false) {
        throw new RuntimeException('Node not found');
    }

    $name = (string) $current['name'];
    $icon = (string) $current['icon'];
    $leftPos = isset($current['left_pos']) ? max(0, (int) $current['left_pos']) : 0;
    $topPos = isset($current['top_pos']) ? max(0, (int) $current['top_pos']) : 0;
    $image = (string) ($current['image'] ?? '');
    $console = (string) ($current['console'] ?? '');
    $cpu = (int) ($current['cpu'] ?? 0);
    $ramMb = (int) ($current['ram_mb'] ?? 0);
    $nvramMb = (int) ($current['nvram_mb'] ?? 0);
    $nodeType = strtolower(trim((string) ($current['node_type'] ?? '')));
    $template = trim((string) ($current['template'] ?? ''));
    $firstMac = (string) ($current['first_mac'] ?? '');
    $qemuOptions = (string) ($current['qemu_options'] ?? '');
    $qemuVersion = (string) ($current['qemu_version'] ?? '');
    $qemuArch = (string) ($current['qemu_arch'] ?? '');
    $qemuNic = (string) ($current['qemu_nic'] ?? '');
    $ethernetCount = (int) ($current['ethernet_count'] ?? 0);
    $serialCount = (int) ($current['serial_count'] ?? 0);

    if (array_key_exists('name', $payload)) {
        $name = trim((string) $payload['name']);
        if ($name === '') {
            throw new InvalidArgumentException('name_required');
        }
        if (!preg_match('/^[\p{L}\p{N}_\-\.\(\)\s]{1,255}$/u', $name)) {
            throw new InvalidArgumentException('name_invalid');
        }
    }

    if (array_key_exists('icon', $payload)) {
        $icon = trim((string) $payload['icon']);
        if ($icon === '') {
            throw new InvalidArgumentException('icon_required');
        }
        $icons = listNodeIconsV2();
        if (!array_key_exists($icon, $icons)) {
            throw new InvalidArgumentException('icon_invalid');
        }
    }

    if (array_key_exists('left', $payload)) {
        $leftPos = max(0, (int) $payload['left']);
    }
    if (array_key_exists('top', $payload)) {
        $topPos = max(0, (int) $payload['top']);
    }
    if (array_key_exists('image', $payload)) {
        $image = trim((string) $payload['image']);
    }
    if (array_key_exists('console', $payload)) {
        $console = trim((string) $payload['console']);
    }
    if (array_key_exists('cpu', $payload)) {
        $cpu = (int) $payload['cpu'];
    }
    if (array_key_exists('ram', $payload)) {
        $ramMb = (int) $payload['ram'];
    }
    if (array_key_exists('nvram', $payload)) {
        $nvramMb = (int) $payload['nvram'];
    }
    if (array_key_exists('firstmac', $payload)) {
        $firstMac = trim((string) $payload['firstmac']);
    }
    if (array_key_exists('qemu_options', $payload)) {
        $qemuOptions = trim((string) $payload['qemu_options']);
    }
    if (array_key_exists('qemu_version', $payload)) {
        $qemuVersion = trim((string) $payload['qemu_version']);
    }
    if (array_key_exists('qemu_arch', $payload)) {
        $qemuArch = trim((string) $payload['qemu_arch']);
    }
    if (array_key_exists('qemu_nic', $payload)) {
        $qemuNic = trim((string) $payload['qemu_nic']);
    }
    if (array_key_exists('ethernet', $payload) || array_key_exists('ethernet_count', $payload)) {
        $ethernetCount = (int) ($payload['ethernet'] ?? $payload['ethernet_count']);
    }
    if (array_key_exists('serial', $payload)) {
        $serialCount = (int) $payload['serial'];
    }
    validateNodeImageForTemplate($nodeType, $template, $image);

    if ($nodeType === 'vpcs') {
        $ethernetCount = 1;
        $serialCount = 0;
    } else {
        if ($ethernetCount < 0 || $ethernetCount > 128) {
            throw new InvalidArgumentException('ethernet_count_invalid');
        }
        if ($serialCount < 0 || $serialCount > 64) {
            throw new InvalidArgumentException('serial_count_invalid');
        }
    }
    $ethernetPortCount = $ethernetCount;

    $stmt = $db->prepare(
        "UPDATE lab_nodes
         SET name = :name,
             icon = :icon,
             left_pos = :left_pos,
             top_pos = :top_pos,
             image = :image,
             console = :console,
             cpu = :cpu,
             ram_mb = :ram_mb,
             nvram_mb = :nvram_mb,
             first_mac = :first_mac,
             qemu_options = :qemu_options,
             qemu_version = :qemu_version,
             qemu_arch = :qemu_arch,
             qemu_nic = :qemu_nic,
             ethernet_count = :ethernet_count,
             serial_count = :serial_count,
             updated_at = NOW()
         WHERE id = :node_id
           AND lab_id = :lab_id
         RETURNING id, name, node_type, template, icon, left_pos, top_pos, is_running, power_state, last_error, power_updated_at, ethernet_count, serial_count, updated_at"
    );
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':icon', $icon, PDO::PARAM_STR);
    $stmt->bindValue(':left_pos', $leftPos, PDO::PARAM_INT);
    $stmt->bindValue(':top_pos', $topPos, PDO::PARAM_INT);
    $stmt->bindValue(':image', $image !== '' ? $image : null, $image !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':console', $console !== '' ? $console : null, $console !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':cpu', $cpu > 0 ? $cpu : null, $cpu > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':ram_mb', $ramMb > 0 ? $ramMb : null, $ramMb > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':nvram_mb', $nvramMb > 0 ? $nvramMb : null, $nvramMb > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':first_mac', $firstMac !== '' ? $firstMac : null, $firstMac !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':qemu_options', $qemuOptions !== '' ? $qemuOptions : null, $qemuOptions !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':qemu_version', $qemuVersion !== '' ? $qemuVersion : null, $qemuVersion !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':qemu_arch', $qemuArch !== '' ? $qemuArch : null, $qemuArch !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':qemu_nic', $qemuNic !== '' ? $qemuNic : null, $qemuNic !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':ethernet_count', $ethernetCount, PDO::PARAM_INT);
    $stmt->bindValue(':serial_count', $serialCount, PDO::PARAM_INT);
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $db->beginTransaction();
    try {
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Node not found');
        }
        syncNodePortsCount($db, $nodeId, 'ethernet', 'eth', $ethernetPortCount);
        syncNodePortsCount($db, $nodeId, 'serial', 'ser', $serialCount);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'node_type' => (string) $row['node_type'],
        'template' => (string) ($row['template'] ?? ''),
        'icon' => (string) ($row['icon'] ?? ''),
        'left' => isset($row['left_pos']) ? (int) $row['left_pos'] : 0,
        'top' => isset($row['top_pos']) ? (int) $row['top_pos'] : 0,
        'is_running' => !empty($row['is_running']),
        'power_state' => (string) ($row['power_state'] ?? (!empty($row['is_running']) ? 'running' : 'stopped')),
        'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : null,
        'power_updated_at' => isset($row['power_updated_at']) ? (string) $row['power_updated_at'] : null,
        'ethernet' => (int) ($row['ethernet_count'] ?? $ethernetCount),
        'ethernet_ports' => $ethernetPortCount,
        'serial' => (int) ($row['serial_count'] ?? $serialCount),
        'updated_at' => (string) $row['updated_at'],
    ];
}

function deleteLabNode(PDO $db, array $viewer, string $labId, string $nodeId): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $nodeStmt = $db->prepare(
        "SELECT n.id,
                n.name,
                n.is_running,
                n.power_state,
                l.author_user_id::text AS owner_user_id
         FROM lab_nodes n
         INNER JOIN labs l ON l.id = n.lab_id
         WHERE n.id = :node_id
           AND n.lab_id = :lab_id
         LIMIT 1"
    );
    $nodeStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $nodeStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $nodeStmt->execute();
    $node = $nodeStmt->fetch(PDO::FETCH_ASSOC);
    if ($node === false) {
        throw new RuntimeException('Node not found');
    }

    // Stop runtime process before deleting DB row to avoid orphan qemu.
    if (function_exists('stopLabNodeRuntime')) {
        try {
            stopLabNodeRuntime($db, $labId, $nodeId);
        } catch (Throwable $e) {
            // Continue deletion even if process is already gone or stop failed.
        }
    }

    $bridgeIds = [];
    $db->beginTransaction();
    try {
        $bridgeStmt = $db->prepare(
            "SELECT DISTINCT nw.id
             FROM lab_node_ports p
             INNER JOIN lab_networks nw ON nw.id = p.network_id
             WHERE p.node_id = :node_id
               AND nw.lab_id = :lab_id
               AND lower(nw.network_type) = 'bridge'"
        );
        $bridgeStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
        $bridgeStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $bridgeStmt->execute();
        $bridgeRows = $bridgeStmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($bridgeRows)) {
            foreach ($bridgeRows as $row) {
                $id = isset($row['id']) ? (string) $row['id'] : '';
                if ($id !== '') {
                    $bridgeIds[] = $id;
                }
            }
        }

        if (!empty($bridgeIds)) {
            $detachStmt = $db->prepare(
                "UPDATE lab_node_ports
                 SET network_id = NULL,
                     updated_at = NOW()
                 WHERE network_id = :network_id
                   AND node_id <> :node_id"
            );
            $deleteBridgeStmt = $db->prepare(
                "DELETE FROM lab_networks
                 WHERE id = :network_id
                   AND lab_id = :lab_id"
            );
            foreach ($bridgeIds as $bridgeId) {
                $detachStmt->bindValue(':network_id', $bridgeId, PDO::PARAM_STR);
                $detachStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
                $detachStmt->execute();

                $deleteBridgeStmt->bindValue(':network_id', $bridgeId, PDO::PARAM_STR);
                $deleteBridgeStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
                $deleteBridgeStmt->execute();
            }
        }

        $deleteNodeStmt = $db->prepare(
            "DELETE FROM lab_nodes
             WHERE id = :node_id
               AND lab_id = :lab_id
             RETURNING id, name"
        );
        $deleteNodeStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
        $deleteNodeStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $deleteNodeStmt->execute();
        $deletedNode = $deleteNodeStmt->fetch(PDO::FETCH_ASSOC);
        if ($deletedNode === false) {
            throw new RuntimeException('Node not found');
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $deletedRuntimePaths = [];
    if (function_exists('cleanupLabNodeRuntimeArtifacts')) {
        try {
            $deletedRuntimePaths = cleanupLabNodeRuntimeArtifacts(
                $labId,
                $nodeId,
                (string) ($node['owner_user_id'] ?? '')
            );
        } catch (Throwable $e) {
            $deletedRuntimePaths = [];
        }
    }

    return [
        'id' => (string) ($deletedNode['id'] ?? $nodeId),
        'name' => (string) ($deletedNode['name'] ?? $node['name'] ?? ''),
        'deleted_bridge_links' => count($bridgeIds),
        'deleted_runtime_paths' => $deletedRuntimePaths,
    ];
}

function wipeLabNode(PDO $db, array $viewer, string $labId, string $nodeId): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }
    if (!labWipeAllowedByPolicy($db, $viewer, $labId)) {
        throw new RuntimeException('Wipe is disabled by topology policy');
    }

    $nodeStmt = $db->prepare(
        "SELECT n.id,
                n.name,
                n.is_running,
                n.power_state,
                l.author_user_id::text AS owner_user_id
         FROM lab_nodes n
         INNER JOIN labs l ON l.id = n.lab_id
         WHERE n.id = :node_id
           AND n.lab_id = :lab_id
         LIMIT 1"
    );
    $nodeStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $nodeStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $nodeStmt->execute();
    $node = $nodeStmt->fetch(PDO::FETCH_ASSOC);
    if ($node === false) {
        throw new RuntimeException('Node not found');
    }
    $powerStateBefore = strtolower(trim((string) ($node['power_state'] ?? '')));
    $wasRunningBeforeWipe = !empty($node['is_running']) || in_array($powerStateBefore, ['running', 'starting'], true);

    $stopResult = null;
    $stopError = null;
    if (function_exists('stopLabNodeRuntime')) {
        try {
            // Wipe always starts by stopping the node runtime first.
            $stopResult = stopLabNodeRuntime($db, $labId, $nodeId);
        } catch (Throwable $e) {
            $stopError = trim((string) $e->getMessage());
        }
    }

    $deletedRuntimePaths = [];
    $cleanupError = null;
    if (function_exists('cleanupLabNodeRuntimeArtifacts')) {
        try {
            $deletedRuntimePaths = cleanupLabNodeRuntimeArtifacts(
                $labId,
                $nodeId,
                (string) ($node['owner_user_id'] ?? '')
            );
        } catch (Throwable $e) {
            $cleanupError = trim((string) $e->getMessage());
        }
    }

    if (function_exists('setNodeStoppedState')) {
        setNodeStoppedState($db, $labId, $nodeId, null);
    }

    $startResult = null;
    $startError = null;
    $startAttempts = 0;
    if ($wasRunningBeforeWipe && function_exists('startLabNodeRuntime')) {
        // Runtime cleanup can complete with a small delay after wipe.
        // Retry auto-start a few times before giving up.
        $retryDelaysUs = [0, 700000, 1200000, 1800000];
        foreach ($retryDelaysUs as $delayUs) {
            if ((int) $delayUs > 0) {
                usleep((int) $delayUs);
            }
            $startAttempts++;
            try {
                $startResult = startLabNodeRuntime($db, $labId, $nodeId);
                $startError = null;
                break;
            } catch (Throwable $e) {
                $startError = trim((string) $e->getMessage());
                if (function_exists('refreshLabNodeRuntimeState')) {
                    try {
                        refreshLabNodeRuntimeState($db, $labId, $nodeId);
                    } catch (Throwable $_) {
                        // Ignore refresh failure between retries.
                    }
                }
            }
        }
    }

    $stateStmt = $db->prepare(
        "SELECT id,
                name,
                is_running,
                power_state,
                last_error,
                power_updated_at,
                updated_at
         FROM lab_nodes
         WHERE id = :node_id
           AND lab_id = :lab_id
         LIMIT 1"
    );
    $stateStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $stateStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stateStmt->execute();
    $state = $stateStmt->fetch(PDO::FETCH_ASSOC);

    return [
        'id' => (string) ($node['id'] ?? $nodeId),
        'name' => (string) ($node['name'] ?? ''),
        'stop' => is_array($stopResult) ? $stopResult : null,
        'stop_error' => ($stopError !== null && $stopError !== '') ? $stopError : null,
        'was_running_before_wipe' => $wasRunningBeforeWipe,
        'start_attempts' => $startAttempts,
        'start' => is_array($startResult) ? $startResult : null,
        'start_error' => ($startError !== null && $startError !== '') ? $startError : null,
        'deleted_runtime_paths' => $deletedRuntimePaths,
        'cleanup_error' => ($cleanupError !== null && $cleanupError !== '') ? $cleanupError : null,
        'node' => is_array($state) ? [
            'id' => (string) ($state['id'] ?? $nodeId),
            'name' => (string) ($state['name'] ?? ''),
            'is_running' => !empty($state['is_running']),
            'power_state' => (string) ($state['power_state'] ?? 'stopped'),
            'last_error' => isset($state['last_error']) ? (string) $state['last_error'] : null,
            'power_updated_at' => isset($state['power_updated_at']) ? (string) $state['power_updated_at'] : null,
            'updated_at' => isset($state['updated_at']) ? (string) $state['updated_at'] : null,
        ] : null,
    ];
}

function listLabPortsForViewer(PDO $db, array $viewer, string $labId): array
{
    if (!viewerCanViewLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $stmt = $db->prepare(
        "SELECT p.id,
                p.node_id,
                n.name AS node_name,
                n.node_type,
                n.template,
                n.image,
                p.name,
                p.port_type,
                p.network_id,
                nw.name AS network_name,
                nw.network_type
         FROM lab_node_ports p
         INNER JOIN lab_nodes n ON n.id = p.node_id
         LEFT JOIN lab_networks nw ON nw.id = p.network_id
         WHERE n.lab_id = :lab_id
         ORDER BY n.created_at ASC, p.port_type ASC, p.name ASC"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }

    return array_map(static function (array $row): array {
        $name = (string) ($row['name'] ?? '');
        $nodeType = (string) ($row['node_type'] ?? '');
        return [
            'id' => (string) $row['id'],
            'node_id' => (string) $row['node_id'],
            'node_name' => (string) ($row['node_name'] ?? ''),
            'name' => $name,
            'display_name' => formatLabPortDisplayName(
                $nodeType,
                $name,
                (string) ($row['template'] ?? ''),
                (string) ($row['image'] ?? '')
            ),
            'port_type' => (string) ($row['port_type'] ?? ''),
            'network_id' => isset($row['network_id']) ? (string) $row['network_id'] : null,
            'network_name' => isset($row['network_name']) ? (string) $row['network_name'] : null,
            'network_type' => isset($row['network_type']) ? (string) $row['network_type'] : null,
            'is_connected' => !empty($row['network_id']),
        ];
    }, $rows);
}

function pickPortForLink(array $ports, string $expectedNodeId, string $explicitPortId = ''): ?array
{
    $supportsPort = static function (array $port) use ($expectedNodeId): bool {
        if ((string) ($port['node_id'] ?? '') !== $expectedNodeId) {
            return false;
        }
        $nodeType = strtolower(trim((string) ($port['node_type'] ?? '')));
        if ($nodeType !== 'vpcs') {
            return true;
        }
        $name = strtolower(trim((string) ($port['name'] ?? '')));
        if (preg_match('/^eth([0-9]+)$/', $name, $m) === 1) {
            return ((int) $m[1]) === 0;
        }
        return $name === 'eth0';
    };

    $explicitPortId = trim($explicitPortId);
    if ($explicitPortId !== '') {
        foreach ($ports as $port) {
            if ((string) ($port['id'] ?? '') !== $explicitPortId) {
                continue;
            }
            if (!$supportsPort($port)) {
                return null;
            }
            return $port;
        }
        return null;
    }

    foreach ($ports as $port) {
        if (!$supportsPort($port)) {
            continue;
        }
        if (!empty($port['network_id'])) {
            continue;
        }
        return $port;
    }
    return null;
}

function queueLinkRuntimeRestartTasks(PDO $db, array $viewer, string $labId, array $nodeIds, string $reason = 'link_change'): array
{
    // Default behavior: no forced restart on link changes.
    // Runtime relink should be applied live by hot-apply path.
    // (Cloud-specific fallback can be queued separately if needed.)
    $unique = [];
    foreach ($nodeIds as $nodeId) {
        $id = trim((string) $nodeId);
        if ($id !== '') {
            $unique[$id] = true;
        }
    }
    $skipped = [];
    foreach (array_keys($unique) as $nodeId) {
        $skipped[] = ['node_id' => $nodeId, 'reason' => 'restart_disabled'];
    }
    return ['queued' => [], 'skipped' => $skipped];
}

function queueHotApplyFallbackRestartTasks(
    PDO $db,
    array $viewer,
    string $labId,
    array $candidateNodeIds,
    array $hotApply,
    string $reason = 'hot_apply_fallback'
): array {
    $candidate = [];
    foreach ($candidateNodeIds as $nodeId) {
        $id = trim((string) $nodeId);
        if ($id !== '') {
            $candidate[$id] = true;
        }
    }
    if (empty($candidate)) {
        return ['queued' => [], 'skipped' => []];
    }

    $needsFallback = [];
    $skipped = [];
    $skippedRows = is_array($hotApply['skipped'] ?? null) ? (array) $hotApply['skipped'] : [];
    foreach ($skippedRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $nodeId = trim((string) ($row['node_id'] ?? ''));
        if ($nodeId === '' || !isset($candidate[$nodeId])) {
            continue;
        }
        $skipReason = strtolower(trim((string) ($row['reason'] ?? '')));
        if ($skipReason === '' || $skipReason === 'node_not_running' || $skipReason === 'already_connected') {
            continue;
        }
        $needsFallback[$nodeId] = $skipReason;
    }
    if (empty($needsFallback)) {
        return ['queued' => [], 'skipped' => []];
    }

    $nodeIdList = array_values(array_keys($needsFallback));
    $placeholders = [];
    foreach ($nodeIdList as $idx => $unusedNodeId) {
        $placeholders[] = ':node_id_' . $idx;
    }

    $nodeStmt = $db->prepare(
        "SELECT id, node_type, is_running, power_state
         FROM lab_nodes
         WHERE lab_id = :lab_id
           AND id IN (" . implode(', ', $placeholders) . ')'
    );
    $nodeStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    foreach ($nodeIdList as $idx => $nodeId) {
        $nodeStmt->bindValue(':node_id_' . $idx, (string) $nodeId, PDO::PARAM_STR);
    }
    $nodeStmt->execute();
    $nodeRows = $nodeStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($nodeRows)) {
        $nodeRows = [];
    }

    $nodeMeta = [];
    foreach ($nodeRows as $nodeRow) {
        if (!is_array($nodeRow)) {
            continue;
        }
        $id = trim((string) ($nodeRow['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $nodeMeta[$id] = $nodeRow;
    }

    $queued = [];
    $viewerId = trim((string) ($viewer['id'] ?? ''));
    $viewerName = trim((string) ($viewer['username'] ?? ''));
    $clientIp = function_exists('clientIp') ? (string) clientIp() : '';

    foreach ($nodeIdList as $nodeId) {
        $meta = is_array($nodeMeta[$nodeId] ?? null) ? (array) $nodeMeta[$nodeId] : [];
        if (empty($meta)) {
            $skipped[] = ['node_id' => $nodeId, 'reason' => 'node_not_found'];
            continue;
        }

        $nodeType = strtolower(trim((string) ($meta['node_type'] ?? '')));
        if (!in_array($nodeType, ['qemu', 'vpcs'], true)) {
            $skipped[] = ['node_id' => $nodeId, 'reason' => 'node_type_not_supported', 'node_type' => $nodeType];
            continue;
        }

        $powerState = strtolower(trim((string) ($meta['power_state'] ?? '')));
        $isRunning = !empty($meta['is_running']) || in_array($powerState, ['running', 'starting'], true);
        if (!$isRunning) {
            $skipped[] = ['node_id' => $nodeId, 'reason' => 'node_not_running'];
            continue;
        }

        if (function_exists('findActiveLabTaskForNode')) {
            $activeTask = findActiveLabTaskForNode($db, $nodeId);
            if (is_array($activeTask)) {
                $skipped[] = [
                    'node_id' => $nodeId,
                    'reason' => 'task_in_progress',
                    'task_id' => (string) ($activeTask['id'] ?? ''),
                ];
                continue;
            }
        }

        $stopPayload = json_encode([
            'requested_at' => gmdate('c'),
            'requested_from_ip' => $clientIp,
            'requested_by' => 'link-runtime',
            'reason' => $reason,
            'source' => 'link_hot_apply',
            'skip_reason' => (string) ($needsFallback[$nodeId] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($stopPayload) || $stopPayload === '') {
            $stopPayload = '{}';
        }

        $startPayload = json_encode([
            'requested_at' => gmdate('c'),
            'requested_from_ip' => $clientIp,
            'requested_by' => 'link-runtime',
            'reason' => $reason,
            'source' => 'link_hot_apply',
            'depends_on' => 'stop',
            'skip_reason' => (string) ($needsFallback[$nodeId] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($startPayload) || $startPayload === '') {
            $startPayload = '{}';
        }

        $insertTaskStmt = $db->prepare(
            "INSERT INTO lab_tasks (
                lab_id,
                node_id,
                action,
                status,
                payload,
                requested_by_user_id,
                requested_by
             ) VALUES (
                :lab_id,
                :node_id,
                :action,
                'pending',
                CAST(:payload AS jsonb),
                :requested_by_user_id,
                :requested_by
             )
             RETURNING id"
        );
        $insertTaskStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $insertTaskStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
        $insertTaskStmt->bindValue(':requested_by_user_id', $viewerId !== '' ? $viewerId : null, $viewerId !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insertTaskStmt->bindValue(':requested_by', $viewerName !== '' ? $viewerName : null, $viewerName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $insertTaskStmt->bindValue(':action', 'stop', PDO::PARAM_STR);
        $insertTaskStmt->bindValue(':payload', $stopPayload, PDO::PARAM_STR);
        $insertTaskStmt->execute();
        $stopTaskId = (string) ($insertTaskStmt->fetchColumn() ?: '');

        $insertTaskStmt->bindValue(':action', 'start', PDO::PARAM_STR);
        $insertTaskStmt->bindValue(':payload', $startPayload, PDO::PARAM_STR);
        $insertTaskStmt->execute();
        $startTaskId = (string) ($insertTaskStmt->fetchColumn() ?: '');

        $nodeStateStmt = $db->prepare(
            "UPDATE lab_nodes
             SET power_state = 'starting',
                 last_error = NULL,
                 power_updated_at = NOW(),
                 updated_at = NOW()
             WHERE id = :node_id
               AND lab_id = :lab_id"
        );
        $nodeStateStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
        $nodeStateStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $nodeStateStmt->execute();

        $queued[] = [
            'node_id' => $nodeId,
            'reason' => (string) ($needsFallback[$nodeId] ?? ''),
            'tasks' => [
                'stop' => $stopTaskId,
                'start' => $startTaskId,
            ],
        ];
    }

    return ['queued' => $queued, 'skipped' => $skipped];
}

function createLabLink(PDO $db, array $viewer, string $labId, array $payload): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $sourceNodeId = trim((string) ($payload['source_node_id'] ?? ''));
    $targetNodeId = trim((string) ($payload['target_node_id'] ?? ''));
    $sourcePortId = trim((string) ($payload['source_port_id'] ?? ''));
    $targetPortId = trim((string) ($payload['target_port_id'] ?? ''));

    if ($sourceNodeId === '' || $targetNodeId === '') {
        throw new InvalidArgumentException('nodes_required');
    }
    if ($sourceNodeId === $targetNodeId) {
        throw new InvalidArgumentException('same_node');
    }
    if ($sourcePortId !== '' && $targetPortId !== '' && $sourcePortId === $targetPortId) {
        throw new InvalidArgumentException('same_port');
    }

    $portsStmt = $db->prepare(
        "SELECT p.id,
                p.node_id,
                p.name,
                p.port_type,
                p.network_id,
                n.node_type,
                n.template,
                n.image,
                n.name AS node_name,
                n.left_pos,
                n.top_pos
         FROM lab_node_ports p
         INNER JOIN lab_nodes n ON n.id = p.node_id
         WHERE n.lab_id = :lab_id
           AND p.port_type = 'ethernet'
           AND p.node_id IN (:source_node_id, :target_node_id)
         ORDER BY p.created_at ASC, p.name ASC
         FOR UPDATE"
    );
    $portsStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $portsStmt->bindValue(':source_node_id', $sourceNodeId, PDO::PARAM_STR);
    $portsStmt->bindValue(':target_node_id', $targetNodeId, PDO::PARAM_STR);

    $db->beginTransaction();
    try {
        $portsStmt->execute();
        $ports = $portsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($ports) || empty($ports)) {
            throw new RuntimeException('ports_not_found');
        }

        $sourcePort = pickPortForLink($ports, $sourceNodeId, $sourcePortId);
        $targetPort = pickPortForLink($ports, $targetNodeId, $targetPortId);
        if ($sourcePort === null || $targetPort === null) {
            throw new RuntimeException('no_free_ports');
        }
        if ((string) ($sourcePort['id'] ?? '') === (string) ($targetPort['id'] ?? '')) {
            throw new InvalidArgumentException('same_port');
        }
        if (!empty($sourcePort['network_id']) || !empty($targetPort['network_id'])) {
            throw new RuntimeException('port_in_use');
        }

        $sourceLeft = isset($sourcePort['left_pos']) ? (int) $sourcePort['left_pos'] : 0;
        $sourceTop = isset($sourcePort['top_pos']) ? (int) $sourcePort['top_pos'] : 0;
        $targetLeft = isset($targetPort['left_pos']) ? (int) $targetPort['left_pos'] : 0;
        $targetTop = isset($targetPort['top_pos']) ? (int) $targetPort['top_pos'] : 0;
        $netLeft = max(0, (int) round(($sourceLeft + $targetLeft) / 2));
        $netTop = max(0, (int) round(($sourceTop + $targetTop) / 2));
        $networkName = 'Link ' . substr((string) ($sourcePort['name'] ?? 'eth'), 0, 16) . ' - ' . substr((string) ($targetPort['name'] ?? 'eth'), 0, 16);

        $insertNetwork = $db->prepare(
            "INSERT INTO lab_networks (
                lab_id,
                name,
                network_type,
                left_pos,
                top_pos,
                visibility,
                icon
             ) VALUES (
                :lab_id,
                :name,
                'bridge',
                :left_pos,
                :top_pos,
                0,
                'lan.png'
             )
             RETURNING id, name, network_type"
        );
        $insertNetwork->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $insertNetwork->bindValue(':name', $networkName, PDO::PARAM_STR);
        $insertNetwork->bindValue(':left_pos', $netLeft, PDO::PARAM_INT);
        $insertNetwork->bindValue(':top_pos', $netTop, PDO::PARAM_INT);
        $insertNetwork->execute();
        $network = $insertNetwork->fetch(PDO::FETCH_ASSOC);
        if ($network === false) {
            throw new RuntimeException('network_create_failed');
        }

        $networkId = (string) $network['id'];
        $attachPort = $db->prepare(
            "UPDATE lab_node_ports
             SET network_id = :network_id,
                 updated_at = NOW()
             WHERE id = :port_id"
        );

        $attachPort->bindValue(':network_id', $networkId, PDO::PARAM_STR);
        $attachPort->bindValue(':port_id', (string) $sourcePort['id'], PDO::PARAM_STR);
        $attachPort->execute();
        $attachPort->bindValue(':port_id', (string) $targetPort['id'], PDO::PARAM_STR);
        $attachPort->execute();

        $relink = queueLinkRuntimeRestartTasks(
            $db,
            $viewer,
            $labId,
            [$sourceNodeId, $targetNodeId],
            'link_create'
        );

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    if (!empty($relink['queued']) && function_exists('kickLabTaskWorkerAsync')) {
        kickLabTaskWorkerAsync();
    }
    $hotApply = ['applied' => [], 'skipped' => []];
    if (function_exists('runtimeHotApplyNodeLinks')) {
        $hotApply = runtimeHotApplyNodeLinks(
            $db,
            $labId,
            [$sourceNodeId, $targetNodeId],
            [
                [
                    'node_id' => (string) ($sourcePort['node_id'] ?? ''),
                    'port_id' => (string) ($sourcePort['id'] ?? ''),
                    'old_network_id' => (string) ($sourcePort['network_id'] ?? ''),
                ],
                [
                    'node_id' => (string) ($targetPort['node_id'] ?? ''),
                    'port_id' => (string) ($targetPort['id'] ?? ''),
                    'old_network_id' => (string) ($targetPort['network_id'] ?? ''),
                ],
            ]
        );
    }
    $fallbackRelink = queueHotApplyFallbackRestartTasks(
        $db,
        $viewer,
        $labId,
        [$sourceNodeId, $targetNodeId],
        $hotApply,
        'link_create_hot_apply_failed'
    );
    if (!empty($fallbackRelink['queued']) && function_exists('kickLabTaskWorkerAsync')) {
        kickLabTaskWorkerAsync();
    }

    return [
        'network_id' => $networkId,
        'network_name' => (string) ($network['name'] ?? ''),
        'network_type' => (string) ($network['network_type'] ?? 'bridge'),
        'source' => [
            'node_id' => (string) ($sourcePort['node_id'] ?? ''),
            'node_name' => (string) ($sourcePort['node_name'] ?? ''),
            'port_id' => (string) ($sourcePort['id'] ?? ''),
            'port_name' => (string) ($sourcePort['name'] ?? ''),
            'port_label' => formatLabPortDisplayName(
                (string) ($sourcePort['node_type'] ?? ''),
                (string) ($sourcePort['name'] ?? ''),
                (string) ($sourcePort['template'] ?? ''),
                (string) ($sourcePort['image'] ?? '')
            ),
        ],
        'target' => [
            'node_id' => (string) ($targetPort['node_id'] ?? ''),
            'node_name' => (string) ($targetPort['node_name'] ?? ''),
            'port_id' => (string) ($targetPort['id'] ?? ''),
            'port_name' => (string) ($targetPort['name'] ?? ''),
            'port_label' => formatLabPortDisplayName(
                (string) ($targetPort['node_type'] ?? ''),
                (string) ($targetPort['name'] ?? ''),
                (string) ($targetPort['template'] ?? ''),
                (string) ($targetPort['image'] ?? '')
            ),
        ],
        'runtime_relink' => $relink,
        'runtime_hot_apply' => $hotApply,
        'runtime_fallback_relink' => $fallbackRelink,
    ];
}

function deleteLabLink(PDO $db, array $viewer, string $labId, string $networkId): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $db->beginTransaction();
    try {
        $netStmt = $db->prepare(
            "SELECT id, network_type
             FROM lab_networks
             WHERE id = :network_id
               AND lab_id = :lab_id
             FOR UPDATE"
        );
        $netStmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
        $netStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $netStmt->execute();
        $network = $netStmt->fetch(PDO::FETCH_ASSOC);
        if ($network === false) {
            throw new RuntimeException('Link not found');
        }

        $networkType = strtolower(trim((string) ($network['network_type'] ?? '')));
        if ($networkType !== 'bridge') {
            throw new RuntimeException('link_delete_forbidden');
        }

        $affectedNodesStmt = $db->prepare(
            "SELECT p.node_id,
                    p.id AS port_id,
                    p.network_id AS old_network_id
             FROM lab_node_ports p
             INNER JOIN lab_nodes n ON n.id = p.node_id
             WHERE n.lab_id = :lab_id
               AND p.network_id = :network_id
             FOR UPDATE"
        );
        $affectedNodesStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $affectedNodesStmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
        $affectedNodesStmt->execute();
        $affectedRows = $affectedNodesStmt->fetchAll(PDO::FETCH_ASSOC);
        $affectedNodeIds = [];
        $affectedPortChanges = [];
        if (is_array($affectedRows)) {
            foreach ($affectedRows as $row) {
                $id = trim((string) ($row['node_id'] ?? ''));
                if ($id !== '') {
                    $affectedNodeIds[] = $id;
                    $affectedPortChanges[] = [
                        'node_id' => $id,
                        'port_id' => (string) ($row['port_id'] ?? ''),
                        'old_network_id' => (string) ($row['old_network_id'] ?? ''),
                    ];
                }
            }
        }

        $detachStmt = $db->prepare(
            "UPDATE lab_node_ports p
             SET network_id = NULL,
                 updated_at = NOW()
             FROM lab_nodes n
             WHERE p.node_id = n.id
               AND n.lab_id = :lab_id
               AND p.network_id = :network_id"
        );
        $detachStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $detachStmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
        $detachStmt->execute();
        $detachedPorts = $detachStmt->rowCount();

        $deleteNet = $db->prepare("DELETE FROM lab_networks WHERE id = :network_id");
        $deleteNet->bindValue(':network_id', $networkId, PDO::PARAM_STR);
        $deleteNet->execute();

        $relink = queueLinkRuntimeRestartTasks(
            $db,
            $viewer,
            $labId,
            $affectedNodeIds,
            'link_delete'
        );

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    if (!empty($relink['queued']) && function_exists('kickLabTaskWorkerAsync')) {
        kickLabTaskWorkerAsync();
    }
    $hotApply = ['applied' => [], 'skipped' => []];
    if (function_exists('runtimeHotApplyNodeLinks')) {
        $hotApply = runtimeHotApplyNodeLinks($db, $labId, $affectedNodeIds, $affectedPortChanges);
    }

    $fallbackRelink = queueHotApplyFallbackRestartTasks(
        $db,
        $viewer,
        $labId,
        $affectedNodeIds,
        $hotApply,
        'link_delete_hot_apply_failed'
    );
    if (!empty($fallbackRelink['queued']) && function_exists('kickLabTaskWorkerAsync')) {
        kickLabTaskWorkerAsync();
    }

    return [
        'network_id' => $networkId,
        'detached_ports' => (int) $detachedPorts,
        'runtime_relink' => $relink,
        'runtime_hot_apply' => $hotApply,
        'runtime_fallback_relink' => $fallbackRelink,
    ];
}

function detachLabAttachment(PDO $db, array $viewer, string $labId, string $attachmentId): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $db->beginTransaction();
    try {
        $findStmt = $db->prepare(
            "SELECT p.id,
                    p.node_id,
                    p.network_id,
                    p.name AS port_name,
                    n.node_type,
                    n.template,
                    n.image,
                    n.name AS node_name
             FROM lab_node_ports p
             INNER JOIN lab_nodes n ON n.id = p.node_id
             WHERE p.id = :attachment_id
               AND n.lab_id = :lab_id
             LIMIT 1
             FOR UPDATE"
        );
        $findStmt->bindValue(':attachment_id', $attachmentId, PDO::PARAM_STR);
        $findStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $findStmt->execute();
        $row = $findStmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Attachment not found');
        }

        $networkId = isset($row['network_id']) ? trim((string) $row['network_id']) : '';
        $networkName = '';
        $networkType = '';
        if ($networkId !== '') {
            $networkStmt = $db->prepare(
                "SELECT name, network_type
                 FROM lab_networks
                 WHERE id = :network_id
                   AND lab_id = :lab_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $networkStmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
            $networkStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
            $networkStmt->execute();
            $network = $networkStmt->fetch(PDO::FETCH_ASSOC);
            if ($network !== false) {
                $networkName = (string) ($network['name'] ?? '');
                $networkType = strtolower(trim((string) ($network['network_type'] ?? '')));
            }
        }

        if ($networkId !== '' && !isCloudNetworkType($networkType)) {
            throw new RuntimeException('attachment_detach_forbidden');
        }

		$detached = false;
		$relink = ['queued' => [], 'skipped' => []];
		if ($networkId !== '') {
			$detachStmt = $db->prepare(
				"UPDATE lab_node_ports
                 SET network_id = NULL,
                     updated_at = NOW()
                 WHERE id = :attachment_id"
			);
			$detachStmt->bindValue(':attachment_id', $attachmentId, PDO::PARAM_STR);
			$detachStmt->execute();
			$detached = $detachStmt->rowCount() > 0;
			if ($detached) {
				$relink = queueLinkRuntimeRestartTasks(
					$db,
					$viewer,
					$labId,
					[(string) ($row['node_id'] ?? '')],
					'attachment_detach'
				);
			}
		}

		$db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
		}
		throw $e;
	}
	if (!empty($relink['queued']) && function_exists('kickLabTaskWorkerAsync')) {
		kickLabTaskWorkerAsync();
	}
    $hotApply = ['applied' => [], 'skipped' => []];
    if ($detached && function_exists('runtimeHotApplyNodeLinks')) {
        $hotApply = runtimeHotApplyNodeLinks(
            $db,
            $labId,
            [(string) ($row['node_id'] ?? '')],
            [
                [
                    'node_id' => (string) ($row['node_id'] ?? ''),
                    'port_id' => (string) ($row['id'] ?? ''),
                    'old_network_id' => (string) ($row['network_id'] ?? ''),
                ],
            ]
        );
    }
    $hotApplyFailure = null;
    if ($detached && !empty($hotApply['skipped']) && is_array($hotApply['skipped'])) {
        foreach ($hotApply['skipped'] as $skip) {
            if (!is_array($skip)) {
                continue;
            }
            $skippedNodeId = trim((string) ($skip['node_id'] ?? ''));
            $rowNodeId = trim((string) ($row['node_id'] ?? ''));
            if ($skippedNodeId !== '' && $rowNodeId !== '' && $skippedNodeId !== $rowNodeId) {
                continue;
            }
            $reason = strtolower(trim((string) ($skip['reason'] ?? '')));
            if ($reason === '' || $reason === 'node_not_running' || $reason === 'cloud_hot_apply_not_supported') {
                continue;
            }
            $detail = is_array($skip['detail'] ?? null) ? (array) $skip['detail'] : [];
            $error = trim((string) ($detail['error'] ?? $detail['details'] ?? $skip['error'] ?? ''));
            if ($error === '') {
                $error = $reason;
            }
            $hotApplyFailure = [
                'reason' => $reason,
                'error' => $error,
            ];
            break;
        }
    }
    $runtimeWarning = null;
    if (is_array($hotApplyFailure)) {
        $runtimeWarning = [
            'code' => 'cloud_detach_hot_apply_failed',
            'reason' => (string) ($hotApplyFailure['reason'] ?? ''),
            'error' => (string) ($hotApplyFailure['error'] ?? 'unknown'),
            'message' => 'Link was detached in DB; runtime update will be applied via queued restart tasks',
        ];
    }

    $fallbackRelink = queueHotApplyFallbackRestartTasks(
        $db,
        $viewer,
        $labId,
        [(string) ($row['node_id'] ?? '')],
        $hotApply,
        'cloud_detach_hot_apply_failed'
    );
    if (!empty($fallbackRelink['queued']) && function_exists('kickLabTaskWorkerAsync')) {
        kickLabTaskWorkerAsync();
    }

		return [
	        'attachment_id' => (string) ($row['id'] ?? $attachmentId),
	        'node_id' => (string) ($row['node_id'] ?? ''),
	        'node_name' => (string) ($row['node_name'] ?? ''),
	        'port_name' => (string) ($row['port_name'] ?? ''),
            'port_label' => formatLabPortDisplayName(
                (string) ($row['node_type'] ?? ''),
                (string) ($row['port_name'] ?? ''),
                (string) ($row['template'] ?? ''),
                (string) ($row['image'] ?? '')
            ),
			'network_id' => (string) ($row['network_id'] ?? ''),
			'network_name' => (string) $networkName,
		'network_type' => (string) $networkType,
		'detached' => (bool) $detached,
		'runtime_relink' => $relink,
        'runtime_hot_apply' => $hotApply,
        'runtime_fallback_relink' => $fallbackRelink,
        'runtime_warning' => $runtimeWarning,
	];
}

function isCloudNetworkType(string $networkType): bool
{
    $networkType = strtolower(trim($networkType));
    if ($networkType === 'cloud') {
        return true;
    }
    return (bool) preg_match('/^pnet[0-9]+$/', $networkType);
}

function normalizeLabPnetValue(string $pnet): string
{
    $pnet = strtolower(trim($pnet));
    if (!preg_match('/^pnet[0-9]+$/', $pnet)) {
        return '';
    }
    if ($pnet === 'pnet0') {
        return '';
    }
    return $pnet;
}

function resolveLabCloudInput(PDO $db, array $viewer, array $payload): array
{
    $viewerUserId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerUserId === '') {
        throw new RuntimeException('Forbidden');
    }
    $allowAllClouds = viewerCanViewAllCloudPnets($db, $viewer);
    $cloudId = trim((string) ($payload['cloud_id'] ?? ''));
    if ($cloudId !== '') {
        if ($allowAllClouds) {
            $stmt = $db->prepare(
                "SELECT c.id, c.name, c.pnet
                 FROM clouds c
                 WHERE c.id = :cloud_id
                 LIMIT 1"
            );
        } else {
            $stmt = $db->prepare(
                "SELECT c.id, c.name, c.pnet
                 FROM clouds c
                 INNER JOIN cloud_users cu ON cu.cloud_id = c.id
                 WHERE c.id = :cloud_id
                   AND cu.user_id = :viewer_user_id
                 LIMIT 1"
            );
            $stmt->bindValue(':viewer_user_id', $viewerUserId, PDO::PARAM_STR);
        }
        $stmt->bindValue(':cloud_id', $cloudId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException('cloud_id_invalid');
        }
        $pnet = normalizeLabPnetValue((string) ($row['pnet'] ?? ''));
        if ($pnet === '') {
            throw new InvalidArgumentException('cloud_id_invalid');
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = strtoupper($pnet);
        }
        return [
            'cloud_id' => (string) $row['id'],
            'name' => $name,
            'pnet' => $pnet,
        ];
    }

    throw new InvalidArgumentException('cloud_id_invalid');
}

function listLabCloudOptions(PDO $db, array $viewer, string $labId): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }
    $viewerUserId = trim((string) ($viewer['id'] ?? ''));
    if ($viewerUserId === '') {
        throw new RuntimeException('Forbidden');
    }

    return listViewerCloudProfiles($db, $viewerUserId, viewerCanViewAllCloudPnets($db, $viewer));
}

function createLabCloudNetwork(PDO $db, array $viewer, string $labId, array $payload): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $resolved = resolveLabCloudInput($db, $viewer, $payload);
    $left = max(0, (int) ($payload['left'] ?? 0));
    $top = max(0, (int) ($payload['top'] ?? 0));
    $icon = trim((string) ($payload['icon'] ?? 'Cloud-2D-White-small-S.svg'));
    if ($icon === '') {
        $icon = 'Cloud-2D-White-small-S.svg';
    }

    $stmt = $db->prepare(
        "INSERT INTO lab_networks (
            lab_id,
            name,
            network_type,
            left_pos,
            top_pos,
            visibility,
            icon
         ) VALUES (
            :lab_id,
            :name,
            :network_type,
            :left_pos,
            :top_pos,
            1,
            :icon
         )
         RETURNING id, name, network_type, left_pos, top_pos, visibility, icon, updated_at"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->bindValue(':name', (string) $resolved['name'], PDO::PARAM_STR);
    $stmt->bindValue(':network_type', (string) $resolved['pnet'], PDO::PARAM_STR);
    $stmt->bindValue(':left_pos', $left, PDO::PARAM_INT);
    $stmt->bindValue(':top_pos', $top, PDO::PARAM_INT);
    $stmt->bindValue(':icon', $icon, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Cloud create failed');
    }

    return [
        'id' => (string) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'network_type' => (string) ($row['network_type'] ?? ''),
        'left' => isset($row['left_pos']) ? (int) $row['left_pos'] : 0,
        'top' => isset($row['top_pos']) ? (int) $row['top_pos'] : 0,
        'visibility' => isset($row['visibility']) ? (int) $row['visibility'] : 1,
        'icon' => (string) ($row['icon'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function updateLabCloudNetwork(PDO $db, array $viewer, string $labId, string $networkId, array $payload): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $findStmt = $db->prepare(
        "SELECT id, name, network_type, left_pos, top_pos, icon
         FROM lab_networks
         WHERE id = :network_id
           AND lab_id = :lab_id
         LIMIT 1"
    );
    $findStmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
    $findStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $findStmt->execute();
    $current = $findStmt->fetch(PDO::FETCH_ASSOC);
    if ($current === false || !isCloudNetworkType((string) ($current['network_type'] ?? ''))) {
        throw new RuntimeException('Cloud not found');
    }

    $name = (string) ($current['name'] ?? '');
    $networkType = (string) ($current['network_type'] ?? '');
    $left = isset($current['left_pos']) ? (int) $current['left_pos'] : 0;
    $top = isset($current['top_pos']) ? (int) $current['top_pos'] : 0;
    $icon = (string) ($current['icon'] ?? 'Cloud-2D-White-small-S.svg');

    if (array_key_exists('left', $payload)) {
        $left = max(0, (int) $payload['left']);
    }
    if (array_key_exists('top', $payload)) {
        $top = max(0, (int) $payload['top']);
    }
    if (array_key_exists('icon', $payload)) {
        $nextIcon = trim((string) $payload['icon']);
        if ($nextIcon !== '') {
            $icon = $nextIcon;
        }
    }

    if (
        array_key_exists('cloud_id', $payload)
        || array_key_exists('pnet', $payload)
        || array_key_exists('name', $payload)
    ) {
        $resolved = resolveLabCloudInput($db, $viewer, $payload);
        $networkType = (string) $resolved['pnet'];
        $name = (string) $resolved['name'];
    }

    $stmt = $db->prepare(
        "UPDATE lab_networks
         SET name = :name,
             network_type = :network_type,
             left_pos = :left_pos,
             top_pos = :top_pos,
             visibility = 1,
             icon = :icon,
             updated_at = NOW()
         WHERE id = :network_id
           AND lab_id = :lab_id
         RETURNING id, name, network_type, left_pos, top_pos, visibility, icon, updated_at"
    );
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':network_type', $networkType, PDO::PARAM_STR);
    $stmt->bindValue(':left_pos', $left, PDO::PARAM_INT);
    $stmt->bindValue(':top_pos', $top, PDO::PARAM_INT);
    $stmt->bindValue(':icon', $icon, PDO::PARAM_STR);
    $stmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Cloud not found');
    }

    return [
        'id' => (string) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'network_type' => (string) ($row['network_type'] ?? ''),
        'left' => isset($row['left_pos']) ? (int) $row['left_pos'] : 0,
        'top' => isset($row['top_pos']) ? (int) $row['top_pos'] : 0,
        'visibility' => isset($row['visibility']) ? (int) $row['visibility'] : 1,
        'icon' => (string) ($row['icon'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function deleteLabCloudNetwork(PDO $db, array $viewer, string $labId, string $networkId): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $db->beginTransaction();
    try {
        $findStmt = $db->prepare(
            "SELECT id, name, network_type
             FROM lab_networks
             WHERE id = :network_id
               AND lab_id = :lab_id
             FOR UPDATE"
        );
        $findStmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
        $findStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $findStmt->execute();
        $network = $findStmt->fetch(PDO::FETCH_ASSOC);
        if ($network === false || !isCloudNetworkType((string) ($network['network_type'] ?? ''))) {
            throw new RuntimeException('Cloud not found');
        }

        $detachStmt = $db->prepare(
            "UPDATE lab_node_ports
             SET network_id = NULL,
                 updated_at = NOW()
             WHERE network_id = :network_id"
        );
        $detachStmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
        $detachStmt->execute();
        $detachedPorts = $detachStmt->rowCount();

        $deleteStmt = $db->prepare("DELETE FROM lab_networks WHERE id = :network_id");
        $deleteStmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
        $deleteStmt->execute();

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'network_id' => $networkId,
        'name' => (string) ($network['name'] ?? ''),
        'detached_ports' => (int) $detachedPorts,
    ];
}

function labObjectClampInt($value, int $min, int $max, int $default): int
{
    $val = is_numeric($value) ? (int) $value : $default;
    if ($val < $min) {
        $val = $min;
    }
    if ($val > $max) {
        $val = $max;
    }
    return $val;
}

function labObjectSanitizeColor($value, string $fallback): string
{
    $color = trim((string) $value);
    if ($color === '') {
        return $fallback;
    }
    if (preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})$/i', $color)) {
        return $color;
    }
    if (preg_match('/^rgba?\([0-9\.,\s]+\)$/i', $color)) {
        return $color;
    }
    if (strtolower($color) === 'transparent') {
        return 'transparent';
    }
    return $fallback;
}

function labObjectSanitizeBendPoints($value, int $width, int $height): array
{
    $maxWidth = max(1, $width);
    $maxHeight = max(1, $height);
    $xLimit = max(4000, $maxWidth * 6);
    $yLimit = max(4000, $maxHeight * 6);
    if (!is_array($value)) {
        return [];
    }
    $points = [];
    $count = 0;
    foreach ($value as $row) {
        if ($count >= 128) {
            break;
        }
        if (!is_array($row)) {
            continue;
        }
        $xRaw = $row['x'] ?? null;
        $yRaw = $row['y'] ?? null;
        if (!is_numeric($xRaw) || !is_numeric($yRaw)) {
            continue;
        }
        $x = (int) round((float) $xRaw);
        $y = (int) round((float) $yRaw);
        if ($x < -$xLimit) {
            $x = -$xLimit;
        } elseif ($x > $xLimit) {
            $x = $xLimit;
        }
        if ($y < -$yLimit) {
            $y = -$yLimit;
        } elseif ($y > $yLimit) {
            $y = $yLimit;
        }
        $points[] = ['x' => $x, 'y' => $y];
        $count++;
    }
    return $points;
}

function labStrLen(string $value): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value);
    }
    return strlen($value);
}

function labStrSubstr(string $value, int $offset, int $length): string
{
    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, $offset, $length);
    }
    return (string) substr($value, $offset, $length);
}

function normalizeLabObjectType(string $objectType): string
{
    $objectType = strtolower(trim($objectType));
    if (in_array($objectType, ['text', 'shape'], true)) {
        return $objectType;
    }
    return '';
}

function normalizeLabObjectData(string $objectType, array $currentData, array $payload): array
{
    $left = array_key_exists('left', $payload)
        ? max(0, (int) $payload['left'])
        : max(0, (int) ($currentData['left'] ?? 0));
    $top = array_key_exists('top', $payload)
        ? max(0, (int) $payload['top'])
        : max(0, (int) ($currentData['top'] ?? 0));

    if ($objectType === 'text') {
        $textRaw = array_key_exists('text', $payload)
            ? (string) $payload['text']
            : (string) ($currentData['text'] ?? 'Text');
        $text = trim($textRaw);
        if ($text === '') {
            $text = 'Text';
        }
        if (labStrLen($text) > 2000) {
            $text = labStrSubstr($text, 0, 2000);
        }
        $align = strtolower(trim((string) ($payload['align'] ?? ($currentData['align'] ?? 'left'))));
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'left';
        }
        return [
            'left' => $left,
            'top' => $top,
            'text' => $text,
            'width' => labObjectClampInt($payload['width'] ?? ($currentData['width'] ?? 320), 20, 1600, 320),
            'height' => labObjectClampInt($payload['height'] ?? ($currentData['height'] ?? 88), 20, 1000, 88),
            'font_size' => labObjectClampInt($payload['font_size'] ?? ($currentData['font_size'] ?? 16), 10, 72, 16),
            'align' => $align,
            'color' => labObjectSanitizeColor($payload['color'] ?? ($currentData['color'] ?? '#e2e8f0'), '#e2e8f0'),
            'background_color' => labObjectSanitizeColor($payload['background_color'] ?? ($currentData['background_color'] ?? 'rgba(15,23,42,0.55)'), 'rgba(15,23,42,0.55)'),
            'z_index' => labObjectClampInt($payload['z_index'] ?? ($currentData['z_index'] ?? 30), 1, 2000000000, 30),
        ];
    }

    $shape = strtolower(trim((string) ($payload['shape'] ?? ($currentData['shape'] ?? 'rect'))));
    if (!in_array($shape, ['rect', 'circle', 'ellipse'], true)) {
        $shape = 'rect';
    }

    $shapeWidth = labObjectClampInt($payload['width'] ?? ($currentData['width'] ?? 220), 30, 2000, 220);
    $shapeHeight = labObjectClampInt($payload['height'] ?? ($currentData['height'] ?? 140), 30, 2000, 140);
    $bendPointsRaw = array_key_exists('bend_points', $payload)
        ? $payload['bend_points']
        : ($currentData['bend_points'] ?? []);

    return [
        'left' => $left,
        'top' => $top,
        'shape' => $shape,
        'width' => $shapeWidth,
        'height' => $shapeHeight,
        'rotation' => labObjectClampInt($payload['rotation'] ?? ($currentData['rotation'] ?? 0), -360, 360, 0),
        'stroke_width' => labObjectClampInt($payload['stroke_width'] ?? ($currentData['stroke_width'] ?? 2), 0, 18, 2),
        'dashed' => !empty($payload['dashed']) || (!array_key_exists('dashed', $payload) && !empty($currentData['dashed'])),
        'fill_color' => labObjectSanitizeColor($payload['fill_color'] ?? ($currentData['fill_color'] ?? 'rgba(56,189,248,0.20)'), 'rgba(56,189,248,0.20)'),
        'stroke_color' => labObjectSanitizeColor($payload['stroke_color'] ?? ($currentData['stroke_color'] ?? '#38bdf8'), '#38bdf8'),
        'bend_points' => labObjectSanitizeBendPoints($bendPointsRaw, $shapeWidth, $shapeHeight),
        'z_index' => labObjectClampInt($payload['z_index'] ?? ($currentData['z_index'] ?? 20), 1, 2000000000, 20),
    ];
}

function createLabObject(PDO $db, array $viewer, string $labId, array $payload): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $objectType = normalizeLabObjectType((string) ($payload['object_type'] ?? ''));
    if ($objectType === '') {
        throw new InvalidArgumentException('object_type_invalid');
    }
    $incomingData = is_array($payload['data'] ?? null) ? (array) $payload['data'] : [];
    if (array_key_exists('left', $payload)) {
        $incomingData['left'] = $payload['left'];
    }
    if (array_key_exists('top', $payload)) {
        $incomingData['top'] = $payload['top'];
    }
    $normalizedData = normalizeLabObjectData($objectType, [], $incomingData);
    $json = json_encode($normalizedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Object data encode failed');
    }
    $dataBase64 = base64_encode($json);

    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        $name = $objectType === 'text' ? 'Text' : 'Shape';
    }
    if (labStrLen($name) > 255) {
        $name = labStrSubstr($name, 0, 255);
    }

    $stmt = $db->prepare(
        "INSERT INTO lab_objects (lab_id, object_type, name, data_base64)
         VALUES (:lab_id, :object_type, :name, :data_base64)
         RETURNING id, object_type, name, data_base64, updated_at"
    );
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->bindValue(':object_type', $objectType, PDO::PARAM_STR);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':data_base64', $dataBase64, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Object create failed');
    }

    return [
        'id' => (string) $row['id'],
        'object_type' => (string) ($row['object_type'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'data_base64' => (string) ($row['data_base64'] ?? ''),
        'data' => decodeLabObjectDataBase64((string) ($row['data_base64'] ?? '')),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function updateLabObject(PDO $db, array $viewer, string $labId, string $objectId, array $payload): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $findStmt = $db->prepare(
        "SELECT id, object_type, name, data_base64
         FROM lab_objects
         WHERE id = :object_id
           AND lab_id = :lab_id
         LIMIT 1"
    );
    $findStmt->bindValue(':object_id', $objectId, PDO::PARAM_STR);
    $findStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $findStmt->execute();
    $current = $findStmt->fetch(PDO::FETCH_ASSOC);
    if ($current === false) {
        throw new RuntimeException('Object not found');
    }

    $objectType = (string) ($current['object_type'] ?? '');
    if (array_key_exists('object_type', $payload)) {
        $normalizedType = normalizeLabObjectType((string) $payload['object_type']);
        if ($normalizedType === '') {
            throw new InvalidArgumentException('object_type_invalid');
        }
        $objectType = $normalizedType;
    }

    $currentData = decodeLabObjectDataBase64((string) ($current['data_base64'] ?? ''));
    if (!is_array($currentData)) {
        $currentData = [];
    }

    $incomingData = is_array($payload['data'] ?? null) ? (array) $payload['data'] : [];
    if (array_key_exists('left', $payload)) {
        $incomingData['left'] = $payload['left'];
    }
    if (array_key_exists('top', $payload)) {
        $incomingData['top'] = $payload['top'];
    }

    $normalizedData = normalizeLabObjectData($objectType, $currentData, $incomingData);
    $json = json_encode($normalizedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Object data encode failed');
    }
    $dataBase64 = base64_encode($json);

    $name = (string) ($current['name'] ?? '');
    if (array_key_exists('name', $payload)) {
        $name = trim((string) $payload['name']);
        if ($name === '') {
            $name = $objectType === 'text' ? 'Text' : 'Shape';
        }
        if (labStrLen($name) > 255) {
            $name = labStrSubstr($name, 0, 255);
        }
    }

    $stmt = $db->prepare(
        "UPDATE lab_objects
         SET object_type = :object_type,
             name = :name,
             data_base64 = :data_base64,
             updated_at = NOW()
         WHERE id = :object_id
           AND lab_id = :lab_id
         RETURNING id, object_type, name, data_base64, updated_at"
    );
    $stmt->bindValue(':object_type', $objectType, PDO::PARAM_STR);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':data_base64', $dataBase64, PDO::PARAM_STR);
    $stmt->bindValue(':object_id', $objectId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Object not found');
    }

    return [
        'id' => (string) $row['id'],
        'object_type' => (string) ($row['object_type'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'data_base64' => (string) ($row['data_base64'] ?? ''),
        'data' => decodeLabObjectDataBase64((string) ($row['data_base64'] ?? '')),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function deleteLabObject(PDO $db, array $viewer, string $labId, string $objectId): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $stmt = $db->prepare(
        "DELETE FROM lab_objects
         WHERE id = :object_id
           AND lab_id = :lab_id
         RETURNING id, object_type, name"
    );
    $stmt->bindValue(':object_id', $objectId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Object not found');
    }

    return [
        'id' => (string) $row['id'],
        'object_type' => (string) ($row['object_type'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
    ];
}

function createLabNodeNetworkLink(PDO $db, array $viewer, string $labId, array $payload): array
{
    if (!viewerCanEditLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $sourceNodeId = trim((string) ($payload['source_node_id'] ?? ''));
    $networkId = trim((string) ($payload['network_id'] ?? ''));
    $sourcePortId = trim((string) ($payload['source_port_id'] ?? ''));
    if ($sourceNodeId === '' || $networkId === '') {
        throw new InvalidArgumentException('node_or_network_required');
    }

    $network = [];
    $sourcePort = [];
    $relink = ['queued' => [], 'skipped' => []];
    $alreadyConnected = false;
    $db->beginTransaction();
    try {
        $networkStmt = $db->prepare(
            "SELECT id, name, network_type
             FROM lab_networks
             WHERE id = :network_id
               AND lab_id = :lab_id
             FOR UPDATE"
        );
        $networkStmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
        $networkStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $networkStmt->execute();
        $network = $networkStmt->fetch(PDO::FETCH_ASSOC);
        if ($network === false) {
            throw new RuntimeException('Network not found');
        }
        if (!isCloudNetworkType((string) ($network['network_type'] ?? ''))) {
            throw new RuntimeException('network_type_invalid');
        }

        $portsStmt = $db->prepare(
	            "SELECT p.id,
	                    p.node_id,
	                    p.name,
	                    p.port_type,
	                    p.network_id,
	                    n.node_type,
                        n.template,
                        n.image,
	                    n.name AS node_name
             FROM lab_node_ports p
             INNER JOIN lab_nodes n ON n.id = p.node_id
             WHERE n.lab_id = :lab_id
               AND p.port_type = 'ethernet'
               AND p.node_id = :source_node_id
             ORDER BY p.created_at ASC, p.name ASC
             FOR UPDATE"
        );
        $portsStmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
        $portsStmt->bindValue(':source_node_id', $sourceNodeId, PDO::PARAM_STR);
        $portsStmt->execute();
        $ports = $portsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($ports) || empty($ports)) {
            throw new RuntimeException('ports_not_found');
        }

        $findAlreadyConnectedPort = static function (array $rows, string $nodeId, string $wantedNetworkId, string $wantedPortId = ''): ?array {
            foreach ($rows as $row) {
                if ((string) ($row['node_id'] ?? '') !== $nodeId) {
                    continue;
                }
                if ($wantedPortId !== '' && (string) ($row['id'] ?? '') !== $wantedPortId) {
                    continue;
                }
                if ((string) ($row['network_id'] ?? '') !== $wantedNetworkId) {
                    continue;
                }
                return $row;
            }
            return null;
        };

        $sourcePort = pickPortForLink($ports, $sourceNodeId, $sourcePortId);
        if ($sourcePort === null) {
            $existingPort = $findAlreadyConnectedPort($ports, $sourceNodeId, $networkId, $sourcePortId);
            if ($existingPort !== null) {
                $sourcePort = $existingPort;
                $alreadyConnected = true;
            } else {
                throw new RuntimeException('no_free_ports');
            }
        }
        if (!$alreadyConnected && !empty($sourcePort['network_id'])) {
            if ((string) ($sourcePort['network_id'] ?? '') === $networkId) {
                $alreadyConnected = true;
            } else {
                throw new RuntimeException('port_in_use');
            }
        }

        if (!$alreadyConnected) {
            $attachStmt = $db->prepare(
                "UPDATE lab_node_ports
                 SET network_id = :network_id,
                     updated_at = NOW()
                 WHERE id = :port_id"
            );
			$attachStmt->bindValue(':network_id', $networkId, PDO::PARAM_STR);
			$attachStmt->bindValue(':port_id', (string) ($sourcePort['id'] ?? ''), PDO::PARAM_STR);
			$attachStmt->execute();

			$relink = queueLinkRuntimeRestartTasks(
				$db,
				$viewer,
				$labId,
				[$sourceNodeId],
				'link_attach_cloud'
			);
        } else {
            $relink = [
                'queued' => [],
                'skipped' => [
                    ['node_id' => $sourceNodeId, 'reason' => 'already_connected'],
                ],
            ];
        }

		$db->commit();
	} catch (Throwable $e) {
		if ($db->inTransaction()) {
			$db->rollBack();
		}
		throw $e;
	}
	if (!empty($relink['queued']) && function_exists('kickLabTaskWorkerAsync')) {
		kickLabTaskWorkerAsync();
	}
    $hotApply = ['applied' => [], 'skipped' => []];
    if ($alreadyConnected) {
        $hotApply['skipped'][] = ['node_id' => $sourceNodeId, 'reason' => 'already_connected'];
    } elseif (function_exists('runtimeHotApplyNodeLinks')) {
        $hotApply = runtimeHotApplyNodeLinks(
            $db,
            $labId,
            [$sourceNodeId],
            [
                [
                    'node_id' => (string) ($sourcePort['node_id'] ?? ''),
                    'port_id' => (string) ($sourcePort['id'] ?? ''),
                    'old_network_id' => (string) ($sourcePort['network_id'] ?? ''),
                ],
            ]
        );
    }
    $hotApplyFailure = null;
    if (!empty($hotApply['skipped']) && is_array($hotApply['skipped'])) {
        foreach ($hotApply['skipped'] as $skip) {
            if (!is_array($skip)) {
                continue;
            }
            $skippedNodeId = trim((string) ($skip['node_id'] ?? ''));
            if ($skippedNodeId !== '' && $skippedNodeId !== $sourceNodeId) {
                continue;
            }
            $reason = strtolower(trim((string) ($skip['reason'] ?? '')));
            if (
                $reason === ''
                || $reason === 'node_not_running'
                || $reason === 'cloud_hot_apply_not_supported'
                || $reason === 'already_connected'
            ) {
                continue;
            }
            $detail = is_array($skip['detail'] ?? null) ? (array) $skip['detail'] : [];
            $error = trim((string) ($detail['error'] ?? $detail['details'] ?? $skip['error'] ?? ''));
            if ($error === '') {
                $error = $reason;
            }
            $hotApplyFailure = [
                'reason' => $reason,
                'error' => $error,
            ];
            break;
        }
    }

    $runtimeWarning = null;
    if (is_array($hotApplyFailure)) {
        $runtimeWarning = [
            'code' => 'cloud_attach_hot_apply_failed',
            'reason' => (string) ($hotApplyFailure['reason'] ?? ''),
            'error' => (string) ($hotApplyFailure['error'] ?? 'unknown'),
            'message' => 'Link was attached in DB; runtime update may require node relink/restart fallback',
        ];
    }

    $fallbackRelink = queueHotApplyFallbackRestartTasks(
        $db,
        $viewer,
        $labId,
        [$sourceNodeId],
        $hotApply,
        'cloud_attach_hot_apply_failed'
    );
    if (!empty($fallbackRelink['queued']) && function_exists('kickLabTaskWorkerAsync')) {
        kickLabTaskWorkerAsync();
    }

    return [
        'network_id' => (string) ($network['id'] ?? $networkId),
        'network_name' => (string) ($network['name'] ?? ''),
        'network_type' => (string) ($network['network_type'] ?? ''),
        'already_connected' => $alreadyConnected,
        'source' => [
            'node_id' => (string) ($sourcePort['node_id'] ?? ''),
            'node_name' => (string) ($sourcePort['node_name'] ?? ''),
            'port_id' => (string) ($sourcePort['id'] ?? ''),
            'port_name' => (string) ($sourcePort['name'] ?? ''),
            'port_label' => formatLabPortDisplayName(
                (string) ($sourcePort['node_type'] ?? ''),
                (string) ($sourcePort['name'] ?? ''),
                (string) ($sourcePort['template'] ?? ''),
                (string) ($sourcePort['image'] ?? '')
            ),
        ],
        'runtime_relink' => $relink,
        'runtime_hot_apply' => $hotApply,
        'runtime_fallback_relink' => $fallbackRelink,
        'runtime_warning' => $runtimeWarning,
    ];
}
