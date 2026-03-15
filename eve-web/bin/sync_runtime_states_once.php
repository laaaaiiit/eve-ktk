#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LabRuntimeService.php';
require_once __DIR__ . '/../src/AppLogService.php';

try {
    $db = db();
    $labIds = $db->query("SELECT id FROM labs")->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($labIds)) {
        $labIds = [];
    }

    $refreshedLabs = 0;
    foreach ($labIds as $labIdRaw) {
        $labId = trim((string) $labIdRaw);
        if ($labId === '') {
            continue;
        }
        refreshLabRuntimeStatesForLab($db, $labId);
        $refreshedLabs++;
    }

    v2AppLogWrite('task_worker', 'OK', [
        'event' => 'runtime_states_synced_on_start',
        'labs_refreshed' => $refreshedLabs,
    ], '127.0.0.1');
    exit(0);
} catch (Throwable $e) {
    v2AppLogWrite('task_worker', 'ERROR', [
        'event' => 'runtime_states_sync_failed_on_start',
        'error' => $e->getMessage(),
    ], '127.0.0.1');
    fwrite(STDERR, '[runtime-sync] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

