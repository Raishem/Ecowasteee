<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
try {
    $conn = getDBConnection();
    if (!$conn) throw new Exception('DB connection failed');

    $tables = ['stage_templates','project_stages'];
    $res = [];
    foreach ($tables as $t) {
        $q = $conn->query("SELECT COUNT(*) as cnt FROM " . $conn->real_escape_string($t));
        $row = $q ? $q->fetch_assoc() : null;
        $res[$t] = $row ? intval($row['cnt']) : 0;
    }
    
    echo json_encode(['success'=>true,'tables'=>$res]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
