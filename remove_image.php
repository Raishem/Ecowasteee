<?php
session_start();
require_once 'config.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit();
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit();
}

// Get parameters
$type = isset($_POST['type']) ? $_POST['type'] : '';
$image_id = isset($_POST['image_id']) ? (int)$_POST['image_id'] : 0;
$step_id = isset($_POST['step_id']) ? (int)$_POST['step_id'] : 0;

if (empty($type) || $image_id < 1) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit();
}

try {
    $conn = getDBConnection();
    
    if ($type === 'project') {
        // Remove project image
        
        // First, get the image path and project ID
        $stmt = $conn->prepare("
            SELECT pi.image_path, pi.project_id 
            FROM project_images pi 
            JOIN projects p ON pi.project_id = p.project_id 
            WHERE pi.image_id = ? AND p.user_id = ?
        ");
        $stmt->bind_param("ii", $image_id, $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($image = $result->fetch_assoc()) {
            $image_path = $image['image_path'];
            $project_id = $image['project_id'];
            
            // Delete from database
            $delete_stmt = $conn->prepare("DELETE FROM project_images WHERE image_id = ?");
            $delete_stmt->bind_param("i", $image_id);
            $delete_stmt->execute();
            
            // Delete physical file
            if (file_exists($image_path)) {
                unlink($image_path);
                
                // Also try to delete from thumbnail directory if exists
                $thumbnail_path = str_replace('project_images/', 'project_images/thumbs/', $image_path);
                if (file_exists($thumbnail_path)) {
                    unlink($thumbnail_path);
                }
            }
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Project image removed successfully',
                'project_id' => $project_id
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Image not found or access denied']);
        }
        
    } elseif ($type === 'step') {
        // Remove step image
        if ($step_id < 1) {
            echo json_encode(['status' => 'error', 'message' => 'Step ID required']);
            exit();
        }
        
        // First, get the image path and verify ownership
        $stmt = $conn->prepare("
            SELECT psi.image_path, psi.step_id, ps.project_id 
            FROM project_step_images psi
            JOIN project_steps ps ON psi.step_id = ps.step_id
            JOIN projects p ON ps.project_id = p.project_id 
            WHERE psi.step_image_id = ? AND p.user_id = ?
        ");
        $stmt->bind_param("ii", $image_id, $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($image = $result->fetch_assoc()) {
            $image_path = $image['image_path'];
            $step_id = $image['step_id'];
            $project_id = $image['project_id'];
            
            // Delete from database
            $delete_stmt = $conn->prepare("DELETE FROM project_step_images WHERE step_image_id = ?");
            $delete_stmt->bind_param("i", $image_id);
            $delete_stmt->execute();
            
            // Delete physical file
            if (file_exists($image_path)) {
                unlink($image_path);
            }
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Step image removed successfully',
                'step_id' => $step_id,
                'project_id' => $project_id
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Image not found or access denied']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid image type']);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>