<?php
// upload_final_image.php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['status' => 'error', 'message' => 'You must be logged in to upload images']);
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
    
    // Check if file was uploaded
    if (!isset($_FILES['final_project_image']) || $_FILES['final_project_image']['error'] !== UPLOAD_ERR_OK) {
        $error_message = 'No file uploaded or upload error occurred.';
        switch ($_FILES['final_project_image']['error'] ?? 4) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error_message = 'File size too large. Maximum size is 5MB.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $error_message = 'File upload was incomplete.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $error_message = 'No file was selected.';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $error_message = 'Missing temporary folder.';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $error_message = 'Failed to write file to disk.';
                break;
            case UPLOAD_ERR_EXTENSION:
                $error_message = 'File upload stopped by extension.';
                break;
        }
        echo json_encode(['status' => 'error', 'message' => $error_message]);
        exit();
    }
    
    $file = $_FILES['final_project_image'];
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.']);
        exit();
    }
    
    // Validate file size (5MB max)
    $max_size = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $max_size) {
        echo json_encode(['status' => 'error', 'message' => 'File size exceeds 5MB limit.']);
        exit();
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = 'uploads/project_final_images/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $unique_filename = uniqid('final_', true) . '_' . time() . '.' . $file_extension;
    $upload_path = $upload_dir . $unique_filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded file.']);
        exit();
    }
    
    // Check if user already has a final image for this project
    $check_final_stmt = $conn->prepare("SELECT image_id FROM project_images WHERE project_id = ? AND is_final_image = 1");
    $check_final_stmt->bind_param("i", $project_id);
    $check_final_stmt->execute();
    $existing_final = $check_final_stmt->get_result()->fetch_assoc();
    
    if ($existing_final) {
        // Update existing final image
        $update_stmt = $conn->prepare("UPDATE project_images SET image_path = ? WHERE image_id = ? AND project_id = ?");
        $update_stmt->bind_param("sii", $upload_path, $existing_final['image_id'], $project_id);
        $update_stmt->execute();
        $image_id = $existing_final['image_id'];
    } else {
        // Insert new final image
        $insert_stmt = $conn->prepare("
            INSERT INTO project_images (project_id, image_path, is_final_image, uploaded_at) 
            VALUES (?, ?, 1, NOW())
        ");
        $insert_stmt->bind_param("is", $project_id, $upload_path);
        $insert_stmt->execute();
        $image_id = $insert_stmt->insert_id;
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Final image uploaded successfully!',
        'image_path' => $upload_path,
        'image_id' => $image_id
    ]);
    
} catch (Exception $e) {
    error_log('Upload error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
    exit();
}
?>