<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $baseDir = dirname(__DIR__);
    loadEnvFile($baseDir . '/.env');

    $host = cfg('DB_HOST', '127.0.0.1');
    $port = cfg('DB_PORT', '5432');
    $name = cfg('DB_NAME', 'eve-ng-db');
    $user = cfg('DB_USER', 'eve-ng-ktk');
    $pass = cfg('DB_PASSWORD', '');

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
