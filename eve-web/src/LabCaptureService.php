<?php

declare(strict_types=1);

require_once __DIR__ . '/LabService.php';
require_once __DIR__ . '/LabRuntimeService.php';

function v2LabCaptureNormalizeIface(string $iface): string
{
    $iface = strtolower(trim($iface));
    if ($iface === '' || preg_match('/^[a-z0-9_.:-]{1,32}$/', $iface) !== 1) {
        return '';
    }
    return $iface;
}

function v2LabCaptureResolveAttachmentInterface(PDO $db, array $viewer, string $labId, string $attachmentId): array
{
    if (!viewerCanViewLab($db, $viewer, $labId)) {
        throw new RuntimeException('Forbidden');
    }

    $stmt = $db->prepare(
        "SELECT p.id AS attachment_id,
                p.name AS port_name,
                n.id AS node_id,
                n.name AS node_name,
                n.node_type,
                n.template,
                n.image,
                n.ethernet_count,
                n.runtime_pid,
                n.runtime_console_port
         FROM lab_node_ports p
         INNER JOIN lab_nodes n ON n.id = p.node_id
         WHERE p.id = :attachment_id
           AND n.lab_id = :lab_id
         LIMIT 1"
    );
    $stmt->bindValue(':attachment_id', $attachmentId, PDO::PARAM_STR);
    $stmt->bindValue(':lab_id', $labId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Attachment not found');
    }

    $nodeId = (string) ($row['node_id'] ?? '');
    if ($nodeId === '') {
        throw new RuntimeException('Attachment not found');
    }

    $portsStmt = $db->prepare(
        "SELECT p.id, p.name, p.network_id, nw.network_type
         FROM lab_node_ports p
         LEFT JOIN lab_networks nw ON nw.id = p.network_id
         WHERE p.node_id = :node_id
         ORDER BY p.id ASC"
    );
    $portsStmt->bindValue(':node_id', $nodeId, PDO::PARAM_STR);
    $portsStmt->execute();
    $ports = $portsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($ports)) {
        $ports = [];
    }

    $ethCount = max(0, (int) ($row['ethernet_count'] ?? 0));
    $indexedPorts = runtimeBuildPortIndexMap($ports, $ethCount);
    $targetAttachmentId = (string) ($row['attachment_id'] ?? '');
    $portIndex = -1;
    for ($i = 0; $i < count($indexedPorts); $i++) {
        $portMeta = $indexedPorts[$i] ?? null;
        if (!is_array($portMeta)) {
            continue;
        }
        $portId = trim((string) ($portMeta['id'] ?? ''));
        if ($portId !== '' && hash_equals($portId, $targetAttachmentId)) {
            $portIndex = $i;
            break;
        }
    }
    if ($portIndex < 0) {
        throw new RuntimeException('Attachment index is not resolved');
    }

    $nodeType = strtolower(trim((string) ($row['node_type'] ?? '')));
    $iface = '';
    if ($nodeType === 'qemu') {
        $iface = runtimeQemuTapName($labId, $nodeId, $portIndex);
    } elseif ($nodeType === 'vpcs') {
        $iface = runtimeVpcsTapName($labId, $nodeId);
    } else {
        throw new RuntimeException('Capture is unsupported for this node type');
    }

    $iface = v2LabCaptureNormalizeIface($iface);
    if ($iface === '') {
        throw new RuntimeException('Capture interface is invalid');
    }

    return [
        'lab_id' => $labId,
        'attachment_id' => $targetAttachmentId,
        'node_id' => $nodeId,
        'node_name' => (string) ($row['node_name'] ?? ''),
        'node_type' => $nodeType,
        'port_name' => (string) ($row['port_name'] ?? ''),
        'port_index' => $portIndex,
        'host_interface' => $iface,
        'interface_exists' => runtimeLinuxInterfaceExists($iface),
    ];
}
