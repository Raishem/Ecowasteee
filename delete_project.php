<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_POST['project_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $project_id = (int)$_POST['project_id'];
    $user_id = (int)$_SESSION['user_id'];

    // Verify ownership
    $stmt = $conn->prepare('SELECT project_id FROM projects WHERE project_id = ? AND user_id = ?');
    $stmt->bind_param('ii', $project_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result->fetch_assoc()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Delete related records in order of dependencies
        // Use IF EXISTS to handle tables that might not exist in this installation
        
        // 1. Delete material photos
        @$conn->query("DELETE FROM material_photos WHERE material_id IN (SELECT material_id FROM project_materials WHERE project_id = $project_id)");

        // 2. Delete stage photos (if table exists)
        @$conn->query("DELETE FROM stage_photos WHERE project_id = $project_id");

        // 3. Delete material allocations
        @$conn->query("DELETE FROM material_allocations WHERE project_id = $project_id");

        // 4. Delete project materials
        $conn->query("DELETE FROM project_materials WHERE project_id = $project_id");

        // 5. Delete project stages
        @$conn->query("DELETE FROM project_stages WHERE project_id = $project_id");

        // 6. Delete project steps
        @$conn->query("DELETE FROM project_steps WHERE project_id = $project_id");

        // 7. Delete step photos (if table exists)
        @$conn->query("DELETE FROM step_photos WHERE step_id IN (SELECT step_id FROM project_steps WHERE project_id = $project_id)");

        // 8. Delete project step progress (if table exists)
        @$conn->query("DELETE FROM project_step_progress WHERE project_id = $project_id");

        // 9. Delete the project itself
        $del = $conn->prepare('DELETE FROM projects WHERE project_id = ? AND user_id = ?');
        $del->bind_param('ii', $project_id, $user_id);
        $del->execute();

        $conn->commit();

        echo json_encode(['success' => true, 'message' => 'Project deleted successfully']);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
