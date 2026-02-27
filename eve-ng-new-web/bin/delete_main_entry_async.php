#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LabRuntimeService.php';
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
    $type = strtolower(readArg($argv, 'type'));
    $entryId = strtolower(readArg($argv, 'entry-id'));
    $operationId = mainDeleteProgressNormalizeOperationId(readArg($argv, 'operation-id'));

    if ($userId === '' || !in_array($type, ['folder', 'lab'], true) || $entryId === '') {
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

    $scope = buildMainEntryDeleteScopeForViewer($db, $viewer, $type, $entryId);
    if ($operationId !== '') {
        mainDeleteProgressPatch($operationId, [
            'status' => 'running',
            'stage' => 'preparing',
            'started_at' => mainDeleteProgressNow(),
            'message' => 'Preparing delete scope',
            'progress' => [
                'current' => 0,
                'total' => (int) (($scope['stats']['total_lab_count'] ?? 0)),
            ],
            'nodes' => [
                'current' => 0,
                'total' => (int) (($scope['stats']['total_node_count'] ?? 0)),
            ],
            'stats' => (array) ($scope['stats'] ?? []),
        ]);
    }

    $stageCallback = null;
    if ($operationId !== '') {
        $stageCallback = static function (string $stage, array $payload) use ($operationId): void {
            if ($stage === 'deleting_labs_start') {
                mainDeleteProgressPatch($operationId, [
                    'status' => 'running',
                    'stage' => 'deleting_labs',
                    'message' => 'Deleting labs',
                    'progress' => [
                        'current' => (int) ($payload['current'] ?? 0),
                        'total' => (int) ($payload['total'] ?? 0),
                    ],
                ]);
                return;
            }
            if ($stage === 'stopping_nodes_start') {
                mainDeleteProgressPatch($operationId, [
                    'status' => 'running',
                    'stage' => 'stopping_nodes',
                    'message' => 'Stopping lab nodes',
                    'nodes' => [
                        'current' => (int) ($payload['current'] ?? 0),
                        'total' => (int) ($payload['total'] ?? 0),
                    ],
                ]);
                return;
            }
            if ($stage === 'stopping_nodes_progress') {
                mainDeleteProgressPatch($operationId, [
                    'status' => 'running',
                    'stage' => 'stopping_nodes',
                    'message' => 'Stopping lab nodes',
                    'current_lab_id' => (string) ($payload['lab_id'] ?? ''),
                    'current_node_id' => (string) ($payload['node_id'] ?? ''),
                    'nodes' => [
                        'current' => (int) ($payload['current'] ?? 0),
                        'total' => (int) ($payload['total'] ?? 0),
                    ],
                ]);
                return;
            }
            if ($stage === 'deleting_labs_progress') {
                mainDeleteProgressPatch($operationId, [
                    'status' => 'running',
                    'stage' => 'deleting_labs',
                    'message' => 'Deleting labs',
                    'current_lab_id' => (string) ($payload['lab_id'] ?? ''),
                    'progress' => [
                        'current' => (int) ($payload['current'] ?? 0),
                        'total' => (int) ($payload['total'] ?? 0),
                    ],
                ]);
                return;
            }
            if ($stage === 'deleting_folder') {
                mainDeleteProgressPatch($operationId, [
                    'status' => 'running',
                    'stage' => 'deleting_folder',
                    'message' => 'Deleting folder',
                ]);
            }
        };
    }

    deleteMainEntryForViewerWithScope($db, $type, $entryId, $scope, $stageCallback);

    if ($operationId !== '') {
        $total = (int) (($scope['stats']['total_lab_count'] ?? 0));
        $nodesTotal = (int) (($scope['stats']['total_node_count'] ?? 0));
        mainDeleteProgressPatch($operationId, [
            'status' => 'done',
            'stage' => 'done',
            'message' => 'Delete completed',
            'progress' => [
                'current' => $total,
                'total' => $total,
            ],
            'nodes' => [
                'current' => $nodesTotal,
                'total' => $nodesTotal,
            ],
            'finished_at' => mainDeleteProgressNow(),
        ]);
    }
    exit(0);
} catch (Throwable $e) {
    $operationId = isset($operationId) ? (string) $operationId : '';
    if ($operationId !== '') {
        try {
            mainDeleteProgressPatch($operationId, [
                'status' => 'failed',
                'stage' => 'failed',
                'message' => (string) $e->getMessage(),
                'finished_at' => mainDeleteProgressNow(),
            ]);
        } catch (Throwable $inner) {
            // Ignore progress write failures in worker shutdown path.
        }
    }
    file_put_contents('php://stderr', '[main-delete-worker] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
