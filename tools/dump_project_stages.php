<?php
require_once __DIR__ . '/../config.php';
$project_id = isset($argv[1]) ? (int)$argv[1] : 5;
try {
    $conn = getDBConnection();
    $stmt = $conn->prepare('SELECT stage_number, completed_at FROM project_stages WHERE project_id = ? ORDER BY stage_number');
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    echo "Stages for project_id={$project_id}:\n";
    foreach ($rows as $r) {
        echo "stage_number={$r['stage_number']} completed_at=" . ($r['completed_at'] === null ? 'NULL' : $r['completed_at']) . "\n";
    }
} catch (Exception $e) {
    echo 'Error: '.$e->getMessage().'\n';
}
