<?php
require __DIR__ . '/html/includes/functions.php';
$db = checkDatabase();
if ($db === False) {
    echo "dbfail\n";
    exit(1);
}
$value = getSystemSetting($db, 'max_parallel_nodes', 'notset');
echo "limit=$value\n";
