<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
try {
    $conn = getDBConnection();
    if (!$conn) throw new Exception('DB connection failed');

    $names = ['Preparation', 'Construction', 'Share'];
    $instructions = [
        'Collect materials required for this project',
        'Perform construction/assembly steps and take before/after photos',
        'Finalize details and share your project with the community'
    ];

    $projects = $conn->query("SELECT project_id FROM projects");
    $count = 0;
    while ($p = $projects->fetch_assoc()) {
        $pid = (int)$p['project_id'];
        // check if project_steps exist
        $r = $conn->query("SELECT COUNT(*) as cnt FROM project_steps WHERE project_id = $pid");
        $row = $r->fetch_assoc();
        if (intval($row['cnt']) === 0) {
            for ($i=1;$i<=3;$i++) {
                $step_id = ($pid * 100) + $i; // deterministic unique id
                $title = $names[$i-1];
                $instr = $instructions[$i-1];
                $stmt = $conn->prepare("INSERT INTO project_steps (step_id, project_id, step_number, title, instructions, is_done) VALUES (?, ?, ?, ?, ?, 0)");
                $stmt->bind_param('iiiss', $step_id, $pid, $i, $title, $instr);
                if (!$stmt->execute()) throw new Exception('Failed inserting step: ' . $stmt->error);
                // create initial progress row
                $ps = $conn->prepare("INSERT INTO project_step_progress (project_id, step_id, is_done) VALUES (?, ?, 0)");
                $ps->bind_param('ii', $pid, $step_id);
                if (!$ps->execute()) throw new Exception('Failed inserting progress: ' . $ps->error);
            }
            $count++;
        }
    }
    echo json_encode(['success'=>true,'message'=>"Created default steps for $count projects"]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
