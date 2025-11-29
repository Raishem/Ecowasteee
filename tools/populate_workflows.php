<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
try {
    $conn = getDBConnection();
    if (!$conn) throw new Exception('DB connection failed');

    // Create stage_templates with 3 stages if empty
    $templates = [
        ['template_id'=>1, 'stage_number'=>1, 'stage_name'=>'Preparation', 'description'=>'Collect materials required for this project'],
        ['template_id'=>2, 'stage_number'=>2, 'stage_name'=>'Construction', 'description'=>'Build or transform materials into the finished item'],
        ['template_id'=>3, 'stage_number'=>3, 'stage_name'=>'Share', 'description'=>'Share project and results with the community']
    ];

    $msgs = [];
    
    // Check if stage_templates already has data
    $check = $conn->query("SELECT COUNT(*) as cnt FROM stage_templates");
    $cnt = $check->fetch_assoc();
    
    if (intval($cnt['cnt']) === 0) {
        // Insert templates (explicit template_id because table has no AUTO_INCREMENT)
        foreach ($templates as $t) {
            $stmt = $conn->prepare("INSERT INTO stage_templates (template_id, stage_number, stage_name, description) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iiss', $t['template_id'], $t['stage_number'], $t['stage_name'], $t['description']);
            if (!$stmt->execute()) throw new Exception("Failed to insert template: " . $stmt->error);
        }
        $msgs[] = "Inserted 3 stage templates";
    } else {
        $msgs[] = "Stage templates already exist";
    }

    // Get all projects
    $projects = $conn->query("SELECT project_id FROM projects");
    $proj_ids = [];
    while ($row = $projects->fetch_assoc()) {
        $proj_ids[] = $row['project_id'];
    }

    // For each project, create 3 project_stages rows if not already present
    foreach ($proj_ids as $pid) {
        $check = $conn->query("SELECT COUNT(*) as cnt FROM project_stages WHERE project_id = $pid");
        $cnt = $check->fetch_assoc();
        
        if (intval($cnt['cnt']) === 0) {
            // Insert 3 stages for this project. Project_stages table expects stage_id but lacks AUTO_INCREMENT,
            // so generate a deterministic unique stage_id: project_id * 10 + stage_number
            $names = ['Preparation', 'Construction', 'Share'];
            for ($i = 1; $i <= 3; $i++) {
                $stage_name = $names[$i - 1];
                $stage_id = ($pid * 10) + $i;
                $stmt = $conn->prepare("INSERT INTO project_stages (stage_id, project_id, stage_number, stage_name, is_completed) VALUES (?, ?, ?, ?, 0)");
                $stmt->bind_param('iiis', $stage_id, $pid, $i, $stage_name);
                if (!$stmt->execute()) throw new Exception("Failed to insert project stage: " . $stmt->error);
            }
        }
    }
    $msgs[] = "Created project_stages for " . count($proj_ids) . " projects";

    echo json_encode(['success'=>true,'messages'=>$msgs]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
