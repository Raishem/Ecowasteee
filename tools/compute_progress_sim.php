<?php
require_once __DIR__ . '/../config.php';
$project_id = isset($argv[1]) ? (int)$argv[1] : 5;
$workflow_stages = [
    ['name'=>'Planning'],
    ['name'=>'Preparation'],
    ['name'=>'Creation']
];
$total_stages = count($workflow_stages);

try {
    $conn = getDBConnection();
    $completed_stage_map = [];
    $stage_stmt = $conn->prepare("SELECT stage_number, MAX(completed_at) AS completed_at FROM project_stages WHERE project_id = ? GROUP BY stage_number");
    $stage_stmt->bind_param('i', $project_id);
    $stage_stmt->execute();
    $res = $stage_stmt->get_result();
    while ($s = $res->fetch_assoc()) {
        $raw_num = (int)$s['stage_number'];
        $idx = max(0, $raw_num - 1);
        if (!is_null($s['completed_at'])) $completed_stage_map[$idx] = $s['completed_at'];
    }
    $completed_stages = 0;
    for ($i=0;$i<$total_stages;$i++) if (array_key_exists($i,$completed_stage_map)) $completed_stages++;
    $progress_percent = $total_stages>0 ? (int) round(($completed_stages/$total_stages)*100) : 0;
    echo "computed: total_stages={$total_stages} completed_stages={$completed_stages} progress={$progress_percent}%\n";
    print_r($completed_stage_map);
} catch (Exception $e) { echo 'Error: '.$e->getMessage()."\n"; }
