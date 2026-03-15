#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LabService.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/AppLogService.php';
require_once __DIR__ . '/../src/LabTaskService.php';

try {
    $db = db();
    $options = getopt('', ['mode:']);
    $mode = isset($options['mode']) ? strtolower(trim((string) $options['mode'])) : 'any';
    if (!in_array($mode, ['any', 'start', 'stop', 'check'], true)) {
        $mode = 'any';
    }
    $processed = runLabTaskWorker($db, 0, 2000, $mode);
    v2AppLogWrite('task_worker', 'OK', [
        'event' => 'worker_cycle_completed',
        'processed' => $processed,
        'mode' => $mode,
    ], '127.0.0.1');
    exit(0);
} catch (Throwable $e) {
    v2AppLogWrite('task_worker', 'ERROR', [
        'event' => 'worker_cycle_failed',
        'message' => $e->getMessage(),
    ], '127.0.0.1');
    v2AppLogWrite('system_errors', 'ERROR', [
        'event' => 'worker_cycle_failed',
        'message' => $e->getMessage(),
    ], '127.0.0.1');
    file_put_contents('php://stderr', '[lab-task-worker] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
