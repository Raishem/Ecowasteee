<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
try {
    $conn = getDBConnection();
    if (!$conn) throw new Exception('DB connection failed');

    $q = "SELECT p.project_id, p.project_name,
            (SELECT COUNT(*) FROM project_steps ps WHERE ps.project_id = p.project_id) AS step_count,
            (SELECT COUNT(*) FROM project_step_progress psp WHERE psp.project_id = p.project_id) AS progress_count
          FROM projects p
          ORDER BY p.project_id";
    $r = $conn->query($q);
    $rows = [];
    while ($row = $r->fetch_assoc()) {
        $rows[] = $row;
    }
    echo json_encode(['success'=>true,'projects'=>$rows]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
