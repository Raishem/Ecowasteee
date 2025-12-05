<?php
// remove_final_image.php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['status' => 'error', 'message' => 'You must be logged in']);
    exit();
}

// Check if project ID is provided
if (!isset($_POST['project_id']) || empty($_POST['project_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Project ID is required']);
    exit();
}

$project_id = (int)$_POST['project_id'];
$user_id = $_SESSION['user_id'];

try {
    $conn = getDBConnection();
    
    // Verify the project belongs to the user
    $project_check = $conn->prepare("SELECT project_id FROM projects WHERE project_id = ? AND user_id = ?");
    $project_check->bind_param("ii", $project_id, $user_id);
    $project_check->execute();
    $project_result = $project_check->get_result();
    
    if ($project_result->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Project not found or access denied']);
        exit();
    }
    
    // Find and delete the final image
    $find_image_stmt = $conn->prepare("SELECT image_path, image_id FROM project_images WHERE project_id = ? AND is_final_image = 1");
    $find_image_stmt->bind_param("i", $project_id);
    $find_image_stmt->execute();
    $image_result = $find_image_stmt->get_result()->fetch_assoc();
    
    if ($image_result) {
        // Delete the file from server
        if (file_exists($image_result['image_path'])) {
            unlink($image_result['image_path']);
        }
        
        // Delete from database
        $delete_stmt = $conn->prepare("DELETE FROM project_images WHERE image_id = ? AND project_id = ?");
        $delete_stmt->bind_param("ii", $image_result['image_id'], $project_id);
        $delete_stmt->execute();
        
        echo json_encode(['status' => 'success', 'message' => 'Final image removed successfully']);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'No final image found']);
    }
    
} catch (Exception $e) {
    error_log('Remove image error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
    exit();
}
?>