<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
try {
    $conn = getDBConnection();
    if (!$conn) throw new Exception('DB connection failed');

    // Get all projects with their stages
    $q = "SELECT p.project_id, p.project_name, 
           COUNT(DISTINCT ps.stage_number) as stage_count,
           GROUP_CONCAT(DISTINCT ps.stage_number ORDER BY ps.stage_number) as stage_numbers
           FROM projects p
           LEFT JOIN project_stages ps ON p.project_id = ps.project_id
           GROUP BY p.project_id
           ORDER BY p.project_id";
    
    $result = $conn->query($q);
    $projects = [];
    while ($row = $result->fetch_assoc()) {
        $projects[] = $row;
    }
    
    echo json_encode(['success'=>true,'projects'=>$projects]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
