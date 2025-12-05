<?php
// Turn off all error reporting for production
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any stray output
ob_start();

session_start();

// Set JSON header FIRST
header('Content-Type: application/json; charset=utf-8');

// Clean any previous output
if (ob_get_length() > 0) {
    ob_clean();
}

// Default error response
$response = ['status' => 'error', 'message' => 'Unknown error'];

try {
    // Check if user is logged in
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        throw new Exception('Please login first');
    }

    // Check if it's a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Get project ID
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

    if ($project_id < 1) {
        throw new Exception('Invalid project ID');
    }

    // Check if config.php exists
    if (!file_exists('config.php')) {
        throw new Exception('Configuration file not found');
    }

    require_once 'config.php';

    // Verify project belongs to user
    $conn = getDBConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    $check_stmt = $conn->prepare("SELECT project_id FROM projects WHERE project_id = ? AND user_id = ?");
    if (!$check_stmt) {
        throw new Exception('Database prepare failed: ' . $conn->error);
    }
    
    $check_stmt->bind_param("ii", $project_id, $_SESSION['user_id']);
    if (!$check_stmt->execute()) {
        throw new Exception('Database query failed: ' . $check_stmt->error);
    }
    
    $project = $check_stmt->get_result()->fetch_assoc();
    
    if (!$project) {
        throw new Exception('Project not found or access denied');
    }
    
    // Check if files were uploaded
    if (!isset($_FILES['project_images']) || $_FILES['project_images']['error'][0] == UPLOAD_ERR_NO_FILE) {
        throw new Exception('No files selected');
    }
    
    // Rest of your existing code continues here...
    // Create upload directory if it doesn't exist
    $upload_dir = 'uploads/project_images/' . $project_id . '/';
    
    // ... [KEEP YOUR EXISTING UPLOAD LOGIC HERE] ...

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    
    // Log the error for debugging
    error_log('Upload error: ' . $e->getMessage());
}

// Clean any output buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Output only JSON
echo json_encode($response);
exit();
?>