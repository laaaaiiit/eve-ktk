#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LabRuntimeService.php';
require_once __DIR__ . '/../src/MainService.php';
require_once __DIR__ . '/../src/UserService.php';

function mainImportReadArg(array $argv, string $name): string
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
    $userId = mainImportReadArg($argv, 'user-id');
    $targetPath = mainImportReadArg($argv, 'target-path');
    $requestedLabName = mainImportReadArg($argv, 'lab-name');
    $operationId = mainImportProgressNormalizeOperationId(mainImportReadArg($argv, 'operation-id'));

    if ($userId === '' || $targetPath === '' || $operationId === '') {
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

    $state = mainImportProgressRead($operationId);
    if (!is_array($state)) {
        throw new RuntimeException('Import operation not found');
    }
    if ($requestedLabName === '') {
        $requestedLabName = trim((string) ($state['requested_lab_name'] ?? ''));
    }

    $internal = is_array($state['internal'] ?? null) ? $state['internal'] : [];
    $archivePath = trim((string) ($internal['archive_path'] ?? ''));
    if ($archivePath === '' || !is_file($archivePath)) {
        throw new RuntimeException('Import archive not found');
    }

    $statsState = is_array($state['stats'] ?? null) ? $state['stats'] : [];
    $dbTotal = max(0, (int) ($statsState['db_total'] ?? 0));
    $runtimeTotal = max(0, (int) ($statsState['runtime_total'] ?? 0));
    $progressState = is_array($state['progress'] ?? null) ? $state['progress'] : [];
    $progressCurrent = max(0, (int) ($progressState['current'] ?? 0));
    $progressTotal = max(1, (int) ($progressState['total'] ?? 1));

    $recalcProgressTotal = static function () use (&$dbTotal, &$runtimeTotal, &$progressTotal, &$progressCurrent): void {
        $computed = 3 + max(0, $dbTotal) + max(0, $runtimeTotal);
        if ($computed < 1) {
            $computed = 1;
        }
        $progressTotal = $computed;
        if ($progressCurrent > $progressTotal) {
            $progressCurrent = $progressTotal;
        }
    };

    $patch = static function (array $changes, ?int $forceCurrent = null, ?int $forceTotal = null) use (&$progressCurrent, &$progressTotal, $operationId): void {
        if ($forceTotal !== null) {
            $progressTotal = max(1, $forceTotal);
        }
        if ($forceCurrent !== null) {
            $progressCurrent = max(0, min($progressTotal, $forceCurrent));
        } else {
            $progressCurrent = max(0, min($progressTotal, $progressCurrent));
        }
        $incomingProgress = is_array($changes['progress'] ?? null) ? $changes['progress'] : [];
        $changes['progress'] = array_merge(
            ['current' => $progressCurrent, 'total' => $progressTotal],
            $incomingProgress
        );
        mainImportProgressPatch($operationId, $changes);
    };

    $recalcProgressTotal();
    $patch([
        'status' => 'running',
        'stage' => 'validating_archive',
        'started_at' => mainDeleteProgressNow(),
        'message' => 'Validating archive',
    ], max($progressCurrent, 1));

    $stageCallback = static function (string $stage, array $payload) use (
        &$dbTotal,
        &$runtimeTotal,
        &$progressCurrent,
        &$progressTotal,
        $recalcProgressTotal,
        $patch
    ): void {
        $stage = strtolower(trim($stage));

        if ($stage === 'validating_archive') {
            $recalcProgressTotal();
            $patch([
                'status' => 'running',
                'stage' => 'validating_archive',
                'message' => 'Validating archive',
            ], max($progressCurrent, 1));
            return;
        }

        if ($stage === 'extracting_archive') {
            $recalcProgressTotal();
            $patch([
                'status' => 'running',
                'stage' => 'extracting_archive',
                'message' => 'Extracting archive',
            ], max($progressCurrent, 2));
            return;
        }

        if ($stage === 'payload_loaded') {
            $dbTotal = max(0, (int) ($payload['db_total'] ?? $dbTotal));
            $runtimeTotal = max(0, (int) ($payload['runtime_total'] ?? $runtimeTotal));
            $recalcProgressTotal();
            $patch([
                'status' => 'running',
                'stage' => 'payload_loaded',
                'message' => 'Import plan prepared',
                'stats' => [
                    'db_total' => $dbTotal,
                    'runtime_total' => $runtimeTotal,
                ],
            ], max($progressCurrent, 2));
            return;
        }

        if ($stage === 'db_import_start') {
            $dbTotal = max(0, (int) ($payload['total'] ?? $dbTotal));
            $recalcProgressTotal();
            $patch([
                'status' => 'running',
                'stage' => 'db_import',
                'message' => 'Importing lab data',
                'stats' => [
                    'db_total' => $dbTotal,
                ],
            ], max($progressCurrent, 2));
            return;
        }

        if ($stage === 'db_import_progress') {
            $dbCurrent = max(0, (int) ($payload['current'] ?? 0));
            $dbStageTotal = max(0, (int) ($payload['total'] ?? $dbTotal));
            if ($dbStageTotal > 0) {
                $dbTotal = $dbStageTotal;
            }
            $recalcProgressTotal();
            $dbCurrentClamped = min($dbCurrent, $dbTotal);
            $absoluteCurrent = 2 + $dbCurrentClamped;
            $patch([
                'status' => 'running',
                'stage' => 'db_import',
                'message' => 'Importing lab data',
                'stats' => [
                    'db_total' => $dbTotal,
                    'db_current' => $dbCurrentClamped,
                ],
            ], $absoluteCurrent);
            return;
        }

        if ($stage === 'db_import_done') {
            $dbDone = max(0, (int) ($payload['current'] ?? $dbTotal));
            $dbTotal = max($dbTotal, $dbDone);
            $recalcProgressTotal();
            $patch([
                'status' => 'running',
                'stage' => 'db_import',
                'message' => 'Lab data imported',
                'stats' => [
                    'db_total' => $dbTotal,
                    'db_current' => $dbTotal,
                ],
            ], 2 + $dbTotal);
            return;
        }

        if ($stage === 'runtime_copy_start') {
            $runtimeTotal = max(0, (int) ($payload['total'] ?? $runtimeTotal));
            $recalcProgressTotal();
            $patch([
                'status' => 'running',
                'stage' => 'runtime_copy',
                'message' => 'Copying runtime files',
                'runtime_copy' => [
                    'current' => 0,
                    'total' => $runtimeTotal,
                    'copied' => max(0, (int) ($payload['copied'] ?? 0)),
                    'skipped' => max(0, (int) ($payload['skipped'] ?? 0)),
                ],
                'stats' => [
                    'runtime_total' => $runtimeTotal,
                ],
            ], 2 + $dbTotal);
            return;
        }

        if ($stage === 'runtime_copy_progress') {
            $runtimeCurrent = max(0, (int) ($payload['current'] ?? 0));
            $runtimeStageTotal = max(0, (int) ($payload['total'] ?? $runtimeTotal));
            if ($runtimeStageTotal > 0) {
                $runtimeTotal = $runtimeStageTotal;
            }
            $recalcProgressTotal();
            $runtimeCurrentClamped = min($runtimeCurrent, $runtimeTotal);
            $absoluteCurrent = 2 + $dbTotal + $runtimeCurrentClamped;
            $runtimeCopied = max(0, (int) ($payload['copied'] ?? 0));
            $runtimeSkipped = max(0, (int) ($payload['skipped'] ?? 0));
            $patch([
                'status' => 'running',
                'stage' => 'runtime_copy',
                'message' => 'Copying runtime files',
                'runtime_copy' => [
                    'current' => $runtimeCurrentClamped,
                    'total' => $runtimeTotal,
                    'copied' => $runtimeCopied,
                    'skipped' => $runtimeSkipped,
                ],
                'stats' => [
                    'runtime_total' => $runtimeTotal,
                    'runtime_copied' => $runtimeCopied,
                    'runtime_skipped' => $runtimeSkipped,
                ],
            ], $absoluteCurrent);
            return;
        }

        if ($stage === 'done') {
            $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
            $runtimeCopy = is_array($payload['runtime_copy'] ?? null) ? $payload['runtime_copy'] : [];
            $runtimeCopied = max(0, (int) ($runtimeCopy['copied'] ?? 0));
            $runtimeSkipped = max(0, (int) ($runtimeCopy['skipped'] ?? 0));
            $runtimeDone = max(0, (int) ($runtimeCopy['total'] ?? $runtimeTotal));
            $runtimeTotal = max($runtimeTotal, $runtimeDone);
            $recalcProgressTotal();
            $patch([
                'status' => 'running',
                'stage' => 'finalizing',
                'message' => 'Finalizing import',
                'result' => $result,
                'stats' => [
                    'runtime_total' => $runtimeTotal,
                    'runtime_copied' => $runtimeCopied,
                    'runtime_skipped' => $runtimeSkipped,
                ],
            ], min($progressTotal - 1, 2 + $dbTotal + $runtimeTotal));
        }
    };

    $result = importMainLabArchiveForViewer(
        $db,
        $viewer,
        $targetPath,
        $archivePath,
        $requestedLabName,
        $stageCallback
    );

    $donePayload = mainImportProgressRead($operationId);
    if (is_array($donePayload)) {
        mainImportProgressCleanupPayloadArtifacts($donePayload);
    }

    $recalcProgressTotal();
    $progressCurrent = $progressTotal;
    $patch([
        'status' => 'done',
        'stage' => 'done',
        'message' => 'Import completed',
        'finished_at' => mainDeleteProgressNow(),
        'result' => $result,
        'internal' => [
            'archive_path' => '',
            'upload_work_dir' => '',
        ],
    ], $progressTotal, $progressTotal);
    exit(0);
} catch (Throwable $e) {
    if (!empty($operationId ?? '')) {
        try {
            $failedPayload = mainImportProgressRead((string) $operationId);
            if (is_array($failedPayload)) {
                mainImportProgressCleanupPayloadArtifacts($failedPayload);
            }
            mainImportProgressPatch((string) $operationId, [
                'status' => 'failed',
                'stage' => 'failed',
                'message' => (string) $e->getMessage(),
                'finished_at' => mainDeleteProgressNow(),
                'internal' => [
                    'archive_path' => '',
                    'upload_work_dir' => '',
                ],
            ]);
        } catch (Throwable $inner) {
            // Ignore progress patch failures in worker shutdown path.
        }
    }
    file_put_contents('php://stderr', '[main-import-worker] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
