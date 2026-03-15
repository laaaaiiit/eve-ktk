#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LabRuntimeService.php';
require_once __DIR__ . '/../src/LabCheckService.php';
require_once __DIR__ . '/../src/MainService.php';
require_once __DIR__ . '/../src/UserService.php';

function readArg(array $argv, string $name): string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos((string) $arg, $prefix) === 0) {
            return trim((string) substr((string) $arg, strlen($prefix)));
        }
    }
    return '';
}

try {
    $userId = readArg($argv, 'user-id');
    $sourceLabId = strtolower(readArg($argv, 'source-lab-id'));
    $forceReset = readArg($argv, 'force-reset') === '1';
    $operationId = mainLocalCopyProgressNormalizeOperationId(readArg($argv, 'operation-id'));

    if ($userId === '' || $sourceLabId === '') {
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

    $progressState = $operationId !== '' ? mainLocalCopyProgressRead($operationId) : null;
    $progressTotal = 1;
    if (is_array($progressState)) {
        $progressTotal = max(1, (int) (($progressState['progress']['total'] ?? 1)));
    }
    $progressCurrent = 0;
    $advanceProgress = static function (int $delta = 1) use (&$progressCurrent, $progressTotal): int {
        $next = $progressCurrent + max(0, $delta);
        if ($next > $progressTotal) {
            $next = $progressTotal;
        }
        $progressCurrent = $next;
        return $progressCurrent;
    };
    $patch = static function (array $changes) use (&$progressCurrent, $progressTotal, $operationId): void {
        if ($operationId === '') {
            return;
        }
        $changes['progress'] = array_merge(
            ['current' => $progressCurrent, 'total' => $progressTotal],
            is_array($changes['progress'] ?? null) ? (array) $changes['progress'] : []
        );
        mainLocalCopyProgressPatch($operationId, $changes);
    };

    $advanceProgress(1);
    $patch([
        'status' => 'running',
        'stage' => 'preparing',
        'started_at' => mainDeleteProgressNow(),
        'message' => 'Preparing local copy',
    ]);

    $stageCallback = static function (string $stage, array $payload) use ($patch, $advanceProgress): void {
        if ($stage === 'stopping_old_nodes_start') {
            $patch([
                'status' => 'running',
                'stage' => 'stopping_old_nodes',
                'message' => 'Stopping old local-copy nodes',
                'stopping_old_nodes' => [
                    'current' => (int) ($payload['current'] ?? 0),
                    'total' => (int) ($payload['total'] ?? 0),
                ],
            ]);
            return;
        }
        if ($stage === 'stopping_old_nodes_progress') {
            $advanceProgress(1);
            $patch([
                'status' => 'running',
                'stage' => 'stopping_old_nodes',
                'message' => 'Stopping old local-copy nodes',
                'stopping_old_nodes' => [
                    'current' => (int) ($payload['current'] ?? 0),
                    'total' => (int) ($payload['total'] ?? 0),
                ],
            ]);
            return;
        }
        if ($stage === 'deleting_old_copy_start') {
            $patch([
                'status' => 'running',
                'stage' => 'deleting_old_copy',
                'message' => 'Deleting old local copies',
                'deleting_old_copy' => [
                    'current' => (int) ($payload['current'] ?? 0),
                    'total' => (int) ($payload['total'] ?? 0),
                ],
            ]);
            return;
        }
        if ($stage === 'deleting_old_copy_progress') {
            $advanceProgress(1);
            $patch([
                'status' => 'running',
                'stage' => 'deleting_old_copy',
                'message' => 'Deleting old local copies',
                'deleting_old_copy' => [
                    'current' => (int) ($payload['current'] ?? 0),
                    'total' => (int) ($payload['total'] ?? 0),
                ],
            ]);
            return;
        }
        if ($stage === 'cloning_lab_data_start') {
            $advanceProgress(1);
            $patch([
                'status' => 'running',
                'stage' => 'cloning_lab_data',
                'message' => 'Cloning lab data',
            ]);
            return;
        }
        if ($stage === 'cloning_lab_data_done') {
            $advanceProgress(1);
            $patch([
                'status' => 'running',
                'stage' => 'cloning_lab_data',
                'message' => 'Lab data cloned',
                'runtime_copy' => [
                    'current' => 0,
                    'total' => (int) ($payload['runtime_total'] ?? 0),
                ],
            ]);
            return;
        }
        if ($stage === 'copying_runtime_start') {
            $patch([
                'status' => 'running',
                'stage' => 'copying_runtime',
                'message' => 'Copying runtime files',
                'runtime_copy' => [
                    'current' => (int) ($payload['current'] ?? 0),
                    'total' => (int) ($payload['total'] ?? 0),
                ],
            ]);
            return;
        }
        if ($stage === 'copying_runtime_progress') {
            $advanceProgress(1);
            $patch([
                'status' => 'running',
                'stage' => 'copying_runtime',
                'message' => 'Copying runtime files',
                'runtime_copy' => [
                    'current' => (int) ($payload['current'] ?? 0),
                    'total' => (int) ($payload['total'] ?? 0),
                ],
            ]);
            return;
        }
    };

    $result = createSharedLabLocalCopyForViewer(
        $db,
        $viewer,
        $sourceLabId,
        $forceReset,
        $stageCallback
    );

    $progressCurrent = $progressTotal;
    $patch([
        'status' => 'done',
        'stage' => 'done',
        'message' => 'Local copy is ready',
        'finished_at' => mainDeleteProgressNow(),
        'result' => $result,
    ]);
    exit(0);
} catch (Throwable $e) {
    $operationId = isset($operationId) ? (string) $operationId : '';
    if ($operationId !== '') {
        try {
            $state = mainLocalCopyProgressRead($operationId);
            $total = is_array($state) ? max(1, (int) (($state['progress']['total'] ?? 1))) : 1;
            $current = is_array($state) ? max(0, min($total, (int) (($state['progress']['current'] ?? 0)))) : 0;
            mainLocalCopyProgressPatch($operationId, [
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
    file_put_contents('php://stderr', '[main-local-copy-worker] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
