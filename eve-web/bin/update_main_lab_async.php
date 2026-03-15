#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LabRuntimeService.php';
require_once __DIR__ . '/../src/MainService.php';
require_once __DIR__ . '/../src/UserService.php';

function mainLabUpdateReadArg(array $argv, string $name): string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos((string) $arg, $prefix) === 0) {
            return trim((string) substr((string) $arg, strlen($prefix)));
        }
    }
    return '';
}

function mainLabUpdateNormalizeSharedUsersArg($value): array
{
    $rows = is_array($value) ? $value : [];
    $out = [];
    foreach ($rows as $item) {
        $name = trim((string) $item);
        if ($name === '') {
            continue;
        }
        $out[$name] = true;
    }
    return array_keys($out);
}

try {
    $userId = mainLabUpdateReadArg($argv, 'user-id');
    $operationId = mainLabUpdateProgressNormalizeOperationId(mainLabUpdateReadArg($argv, 'operation-id'));

    if ($userId === '' || $operationId === '') {
        throw new RuntimeException('Invalid arguments');
    }

    $db = db();
    $user = getUserById($db, $userId);
    if (!is_array($user)) {
        throw new RuntimeException('User not found');
    }
    $viewer = [
        'id' => (string) ($user['id'] ?? ''),
        'username' => (string) ($user['username'] ?? ''),
        'role_name' => (string) ($user['role'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
    ];

    $state = mainLabUpdateProgressRead($operationId);
    if (!is_array($state)) {
        throw new RuntimeException('Lab update operation not found');
    }
    $internal = is_array($state['internal'] ?? null) ? $state['internal'] : [];

    $labId = trim((string) ($internal['lab_id'] ?? ''));
    $name = (string) ($internal['name'] ?? '');
    $description = null;
    if (array_key_exists('description', $internal)) {
        $rawDescription = $internal['description'];
        if ($rawDescription !== null) {
            $description = trim((string) $rawDescription);
            if ($description === '') {
                $description = null;
            }
        }
    }
    $isShared = !empty($internal['is_shared']);
    $collaborateAllowed = !empty($internal['collaborate_allowed']);
    $topologyLocked = !empty($internal['topology_locked']);
    $topologyAllowWipe = !empty($internal['topology_allow_wipe']);
    $sharedWith = mainLabUpdateNormalizeSharedUsersArg($internal['shared_with'] ?? []);

    if ($labId === '' || trim($name) === '') {
        throw new RuntimeException('Invalid lab update payload');
    }

    $progressState = is_array($state['progress'] ?? null) ? $state['progress'] : [];
    $cleanupState = is_array($state['cleanup'] ?? null) ? $state['cleanup'] : [];
    $progressTotal = max(3, (int) ($progressState['total'] ?? 3));
    $progressCurrent = max(0, min($progressTotal, (int) ($progressState['current'] ?? 0)));
    $cleanupLabsTotal = max(0, (int) ($cleanupState['labs_total'] ?? 0));
    $cleanupNodesTotal = max(0, (int) ($cleanupState['nodes_total'] ?? 0));

    $recalcProgressTotal = static function () use (&$progressTotal, &$progressCurrent, &$cleanupLabsTotal, &$cleanupNodesTotal): void {
        $computed = 2 + $cleanupNodesTotal + $cleanupLabsTotal + 1;
        if ($computed < 3) {
            $computed = 3;
        }
        $progressTotal = $computed;
        if ($progressCurrent > $progressTotal) {
            $progressCurrent = $progressTotal;
        }
    };

    $patch = static function (array $changes, ?int $forceCurrent = null) use (&$progressCurrent, &$progressTotal, &$cleanupLabsTotal, &$cleanupNodesTotal, $operationId, $recalcProgressTotal): void {
        $recalcProgressTotal();
        if ($forceCurrent !== null) {
            $progressCurrent = max(0, min($progressTotal, $forceCurrent));
        }

        $cleanupPatch = is_array($changes['cleanup'] ?? null) ? (array) $changes['cleanup'] : [];
        if (array_key_exists('labs_total', $cleanupPatch)) {
            $cleanupLabsTotal = max(0, (int) $cleanupPatch['labs_total']);
        }
        if (array_key_exists('nodes_total', $cleanupPatch)) {
            $cleanupNodesTotal = max(0, (int) $cleanupPatch['nodes_total']);
        }
        $recalcProgressTotal();

        $changes['progress'] = array_merge(
            ['current' => $progressCurrent, 'total' => $progressTotal],
            is_array($changes['progress'] ?? null) ? (array) $changes['progress'] : []
        );
        mainLabUpdateProgressPatch($operationId, $changes);
    };

    $progressCurrent = max($progressCurrent, 1);
    $patch([
        'status' => 'running',
        'stage' => 'preparing',
        'started_at' => mainDeleteProgressNow(),
        'message' => 'Preparing lab update',
    ]);

    $stageCallback = static function (string $stage, array $payload) use ($patch, &$cleanupLabsTotal, &$cleanupNodesTotal): void {
        $stage = strtolower(trim($stage));

        if ($stage === 'preparing') {
            $patch([
                'status' => 'running',
                'stage' => 'preparing',
                'message' => 'Preparing lab update',
            ], 1);
            return;
        }

        if ($stage === 'updating_metadata') {
            $patch([
                'status' => 'running',
                'stage' => 'updating_metadata',
                'message' => 'Updating lab metadata',
            ], 1);
            return;
        }

        if ($stage === 'metadata_updated') {
            $patch([
                'status' => 'running',
                'stage' => 'metadata_updated',
                'message' => 'Lab metadata updated',
            ], 2);
            return;
        }

        if ($stage === 'cleanup_stats') {
            $cleanupLabsTotal = max(0, (int) ($payload['labs_total'] ?? 0));
            $cleanupNodesTotal = max(0, (int) ($payload['nodes_total'] ?? 0));
            $patch([
                'status' => 'running',
                'stage' => ($cleanupLabsTotal > 0 || $cleanupNodesTotal > 0) ? 'cleanup_preparing' : 'cleanup_done',
                'message' => ($cleanupLabsTotal > 0 || $cleanupNodesTotal > 0)
                    ? 'Preparing local-copy cleanup'
                    : 'No local copies to clean',
                'cleanup' => [
                    'labs_total' => $cleanupLabsTotal,
                    'labs_deleted' => 0,
                    'nodes_total' => $cleanupNodesTotal,
                    'nodes_stopped' => 0,
                ],
            ], 2);
            return;
        }

        if ($stage === 'cleanup_stopping_start') {
            $patch([
                'status' => 'running',
                'stage' => 'cleanup_stopping',
                'message' => 'Stopping local-copy nodes',
                'cleanup' => [
                    'nodes_stopped' => 0,
                ],
            ], 2);
            return;
        }

        if ($stage === 'cleanup_stopping_progress') {
            $nodesStopped = max(0, min($cleanupNodesTotal, (int) ($payload['current'] ?? 0)));
            $patch([
                'status' => 'running',
                'stage' => 'cleanup_stopping',
                'message' => 'Stopping local-copy nodes',
                'cleanup' => [
                    'nodes_stopped' => $nodesStopped,
                ],
            ], 2 + $nodesStopped);
            return;
        }

        if ($stage === 'cleanup_deleting_start') {
            $patch([
                'status' => 'running',
                'stage' => 'cleanup_deleting',
                'message' => 'Deleting local-copy labs',
                'cleanup' => [
                    'labs_deleted' => 0,
                ],
            ], 2 + $cleanupNodesTotal);
            return;
        }

        if ($stage === 'cleanup_deleting_progress') {
            $labsDeleted = max(0, min($cleanupLabsTotal, (int) ($payload['current'] ?? 0)));
            $patch([
                'status' => 'running',
                'stage' => 'cleanup_deleting',
                'message' => 'Deleting local-copy labs',
                'cleanup' => [
                    'labs_deleted' => $labsDeleted,
                ],
            ], 2 + $cleanupNodesTotal + $labsDeleted);
            return;
        }

        if ($stage === 'cleanup_done') {
            $labsDeleted = max(0, min($cleanupLabsTotal, (int) ($payload['labs_deleted'] ?? $cleanupLabsTotal)));
            $nodesStopped = max(0, min($cleanupNodesTotal, (int) ($payload['nodes_stopped'] ?? $cleanupNodesTotal)));
            $patch([
                'status' => 'running',
                'stage' => 'cleanup_done',
                'message' => 'Local-copy cleanup completed',
                'cleanup' => [
                    'labs_deleted' => $labsDeleted,
                    'nodes_stopped' => $nodesStopped,
                ],
            ], 2 + $cleanupNodesTotal + $cleanupLabsTotal);
            return;
        }
    };

    $updated = updateMainLabForViewer(
        $db,
        $viewer,
        $labId,
        $name,
        $description,
        $isShared,
        $collaborateAllowed,
        $sharedWith,
        $topologyLocked,
        $topologyAllowWipe,
        $stageCallback
    );

    $recalcProgressTotal();
    $progressCurrent = $progressTotal;
    $patch([
        'status' => 'done',
        'stage' => 'done',
        'message' => 'Lab updated',
        'finished_at' => mainDeleteProgressNow(),
        'result' => is_array($updated) ? $updated : [],
    ]);
    exit(0);
} catch (Throwable $e) {
    $operationId = isset($operationId) ? (string) $operationId : '';
    if ($operationId !== '') {
        try {
            $state = mainLabUpdateProgressRead($operationId);
            $progress = is_array($state) && is_array($state['progress'] ?? null) ? $state['progress'] : [];
            $total = max(1, (int) ($progress['total'] ?? 1));
            $current = max(0, min($total, (int) ($progress['current'] ?? 0)));
            mainLabUpdateProgressPatch($operationId, [
                'status' => 'failed',
                'stage' => 'failed',
                'message' => (string) $e->getMessage(),
                'finished_at' => mainDeleteProgressNow(),
                'progress' => [
                    'current' => $current,
                    'total' => $total,
                ],
            ]);
        } catch (Throwable $inner) {
            // Ignore progress write failures in worker shutdown path.
        }
    }
    file_put_contents('php://stderr', '[main-lab-update-worker] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
