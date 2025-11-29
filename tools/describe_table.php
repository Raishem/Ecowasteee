<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
try {
    $conn = getDBConnection();
    // support CLI usage: php describe_table.php project_stages
    $tableArg = null;
    if (php_sapi_name() === 'cli' && isset($argv[1])) {
        $tableArg = $argv[1];
    }
    $t = $conn->real_escape_string($tableArg ?? ($_GET['table'] ?? 'stage_templates'));
    $r = $conn->query("SHOW CREATE TABLE $t");
    $row = $r ? $r->fetch_assoc() : null;
    echo json_encode($row);
} catch (Exception $e) {
    echo json_encode(['error'=>$e->getMessage()]);
}
