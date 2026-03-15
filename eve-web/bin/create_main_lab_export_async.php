#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LabRuntimeService.php';
require_once __DIR__ . '/../src/MainService.php';
require_once __DIR__ . '/../src/UserService.php';

function mainExportReadArg(array $argv, string $name): string
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
    $userId = mainExportReadArg($argv, 'user-id');
    $labId = strtolower(mainExportReadArg($argv, 'lab-id'));
    $includeRuntime = mainExportReadArg($argv, 'include-runtime') === '1';
    $includeChecks = mainExportReadArg($argv, 'include-checks') !== '0';
    $operationId = mainExportProgressNormalizeOperationId(mainExportReadArg($argv, 'operation-id'));

    if ($userId === '' || $labId === '' || $operationId === '') {
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

    $state = mainExportProgressRead($operationId);
    $progressTotal = is_array($state) ? max(1.0, (float) (($state['progress']['total'] ?? 1))) : 1.0;
    $progressCurrent = is_array($state) ? max(0.0, min($progressTotal, (float) (($state['progress']['current'] ?? 0)))) : 0.0;
    $packingState = is_array($state['packing'] ?? null) ? $state['packing'] : [];
    $packingWeight = max(1, (int) ($packingState['weight'] ?? 40));
    $packingBaseCurrent = null;
    $packingTotalBytes = max(0, (int) ($packingState['total_bytes'] ?? 0));

    $advanceProgress = static function (float $delta = 1.0) use (&$progressCurrent, &$progressTotal): float {
        $next = $progressCurrent + max(0.0, $delta);
        if ($next > $progressTotal) {
            $next = $progressTotal;
        }
        $progressCurrent = $next;
        return $progressCurrent;
    };
    $patch = static function (array $changes) use ($operationId, &$progressCurrent, &$progressTotal): void {
        $changes['progress'] = array_merge(
            ['current' => $progressCurrent, 'total' => $progressTotal],
            is_array($changes['progress'] ?? null) ? (array) $changes['progress'] : []
        );
        mainExportProgressPatch($operationId, $changes);
    };

    $advanceProgress(1);
    $patch([
        'status' => 'running',
        'stage' => 'preparing',
        'started_at' => mainDeleteProgressNow(),
        'message' => 'Preparing export',
    ]);

    $stageCallback = static function (string $stage, array $payload) use (
        $patch,
        $advanceProgress,
        &$progressCurrent,
        &$progressTotal,
        &$packingBaseCurrent,
        &$packingTotalBytes,
        $packingWeight
    ): void {
        if ($stage === 'collecting_data') {
            $advanceProgress(1);
            $patch([
                'status' => 'running',
                'stage' => 'collecting_data',
                'message' => 'Collecting lab data',
            ]);
            return;
        }
        if ($stage === 'runtime_copy_start') {
            $patch([
                'status' => 'running',
                'stage' => 'runtime_copy',
                'message' => 'Copying runtime files',
                'runtime_copy' => [
                    'current' => (int) ($payload['current'] ?? 0),
                    'total' => (int) ($payload['total'] ?? 0),
                    'copied' => (int) ($payload['copied'] ?? 0),
                    'skipped' => (int) ($payload['skipped'] ?? 0),
                ],
            ]);
            return;
        }
        if ($stage === 'runtime_copy_progress') {
            $advanceProgress(1);
            $patch([
                'status' => 'running',
                'stage' => 'runtime_copy',
                'message' => 'Copying runtime files',
                'runtime_copy' => [
                    'current' => (int) ($payload['current'] ?? 0),
                    'total' => (int) ($payload['total'] ?? 0),
                    'copied' => (int) ($payload['copied'] ?? 0),
                    'skipped' => (int) ($payload['skipped'] ?? 0),
                ],
            ]);
            return;
        }
        if ($stage === 'writing_payload') {
            $advanceProgress(1);
            $patch([
                'status' => 'running',
                'stage' => 'writing_payload',
                'message' => 'Writing export payload',
            ]);
            return;
        }
        if ($stage === 'packing_archive_start' || $stage === 'packing_archive') {
            if ($packingBaseCurrent === null) {
                $advanceProgress(1);
                $packingBaseCurrent = $progressCurrent;
            }
            $totalBytes = max(0, (int) ($payload['total_bytes'] ?? 0));
            if ($totalBytes > 0) {
                $packingTotalBytes = $totalBytes;
            }
            $patch([
                'status' => 'running',
                'stage' => 'packing_archive',
                'message' => 'Packing archive',
                'packing' => [
                    'processed_bytes' => max(0, (int) ($payload['processed_bytes'] ?? 0)),
                    'current_bytes' => max(0, (int) ($payload['current_bytes'] ?? 0)),
                    'total_bytes' => $packingTotalBytes,
                    'eta_seconds' => ($payload['eta_seconds'] ?? null),
                    'weight' => $packingWeight,
                ],
            ]);
            return;
        }
        if ($stage === 'packing_archive_progress') {
            if ($packingBaseCurrent === null) {
                $advanceProgress(1);
                $packingBaseCurrent = $progressCurrent;
            }

            $totalBytes = max(0, (int) ($payload['total_bytes'] ?? $packingTotalBytes));
            if ($totalBytes > 0) {
                $packingTotalBytes = $totalBytes;
            }
            $processedBytes = max(0, (int) ($payload['processed_bytes'] ?? 0));
            $currentBytes = max(0, (int) ($payload['current_bytes'] ?? 0));
            $isFinalizing = !empty($payload['finalizing']);
            $basisBytes = $processedBytes > 0 ? $processedBytes : $currentBytes;

            $fraction = 0.0;
            if ($packingTotalBytes > 0) {
                $fraction = $basisBytes / $packingTotalBytes;
                if (!is_finite($fraction)) {
                    $fraction = 0.0;
                }
                if ($fraction < 0.0) {
                    $fraction = 0.0;
                } elseif ($fraction > 0.999) {
                    $fraction = 0.999;
                }
            }
            $targetCurrent = (float) $packingBaseCurrent + ($packingWeight * $fraction);
            $maxBeforeDone = max(0.0, $progressTotal - 1.0);
            if ($targetCurrent > $progressCurrent) {
                $progressCurrent = min($maxBeforeDone, $targetCurrent);
            }
            $eta = ($payload['eta_seconds'] ?? null);
            if ($eta !== null) {
                $eta = (int) $eta;
                if ($eta < 0) {
                    $eta = 0;
                }
            }
            if ($isFinalizing) {
                $eta = null;
            }
            $patch([
                'status' => 'running',
                'stage' => ($isFinalizing ? 'packing_archive_finalizing' : 'packing_archive'),
                'message' => ($isFinalizing ? 'Finalizing archive' : 'Packing archive'),
                'packing' => [
                    'processed_bytes' => $processedBytes,
                    'current_bytes' => $currentBytes,
                    'total_bytes' => $packingTotalBytes,
                    'eta_seconds' => $eta,
                    'finalizing' => $isFinalizing,
                    'weight' => $packingWeight,
                ],
            ]);
        }
    };

    $export = mainExportLabArchiveForViewer(
        $db,
        $viewer,
        $labId,
        $includeRuntime,
        $includeChecks,
        $stageCallback
    );

    $progressCurrent = $progressTotal;
    $patch([
        'status' => 'done',
        'stage' => 'done',
        'message' => 'Export is ready',
        'finished_at' => mainDeleteProgressNow(),
        'result' => [
            'archive_name' => (string) ($export['archive_name'] ?? 'lab-export.evev2lab.tgz'),
            'size_bytes' => (int) ($export['size_bytes'] ?? 0),
            'downloaded' => false,
        ],
        'internal' => [
            'archive_path' => (string) ($export['archive_path'] ?? ''),
            'work_dir' => (string) ($export['work_dir'] ?? ''),
            'content_type' => (string) ($export['content_type'] ?? 'application/gzip'),
        ],
    ]);
    exit(0);
} catch (Throwable $e) {
    if (isset($export) && is_array($export)) {
        try {
            cleanupMainLabArchiveExport($export);
        } catch (Throwable $inner) {
            // Ignore cleanup failures in worker shutdown path.
        }
    }
    if (!empty($operationId ?? '')) {
        try {
            mainExportProgressPatch((string) $operationId, [
                'status' => 'failed',
                'stage' => 'failed',
                'message' => (string) $e->getMessage(),
                'finished_at' => mainDeleteProgressNow(),
            ]);
        } catch (Throwable $inner) {
            // Ignore progress patch failures in worker shutdown path.
        }
    }
    file_put_contents('php://stderr', '[main-export-worker] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
