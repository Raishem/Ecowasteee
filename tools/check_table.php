<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
try {
    $conn = getDBConnection();
    if (!$conn) throw new Exception('DB connection failed');

    $tables = ['project_step_progress','stage_photos','step_photos'];
    $res = [];
    foreach ($tables as $t) {
        $q = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
        $res[$t] = ($q && $q->num_rows) ? true : false;
    }
    echo json_encode(['success'=>true,'tables'=>$res]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
