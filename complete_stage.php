<?php
header('Content-Type: application/json');
session_start();
require_once 'config.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$stage_number = isset($_POST['stage_number']) ? (int)$_POST['stage_number'] : 0;

if (!$project_id || !$stage_number) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    $conn = getDBConnection();
    
    // Verify project belongs to user
    $check_stmt = $conn->prepare("SELECT project_id FROM projects WHERE project_id = ? AND user_id = ?");
    $check_stmt->bind_param("ii", $project_id, $_SESSION['user_id']);
    $check_stmt->execute();
    if (!$check_stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit;
    }
    
    // Check if previous stages are completed
    $prev_check = $conn->prepare("
        SELECT COUNT(*) as incomplete
        FROM (
            SELECT stage_number 
            FROM stage_templates 
            WHERE stage_number < ?
        ) needed_stages
        LEFT JOIN project_stages ps ON 
            ps.project_id = ? AND 
            ps.stage_number = needed_stages.stage_number AND 
            ps.is_completed = 1
        WHERE ps.project_id IS NULL
    ");
    $prev_check->bind_param("ii", $stage_number, $project_id);
    $prev_check->execute();
    $result = $prev_check->get_result();
    $incomplete = $result->fetch_assoc()['incomplete'];
    
    if ($incomplete > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot complete this stage until all previous stages are completed']);
        exit;
    }

    // Insert or update stage completion
    $stmt = $conn->prepare("
        INSERT INTO project_stages (project_id, stage_number, stage_name, is_completed, completed_at)
        SELECT ?, stage_number, stage_name, 1, NOW()
        FROM stage_templates
        WHERE stage_number = ?
        ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = NOW()
    ");
    $stmt->bind_param("ii", $project_id, $stage_number);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>