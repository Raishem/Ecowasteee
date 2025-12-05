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
$step_id = isset($_POST['step_id']) ? (int)$_POST['step_id'] : 0;

if ($step_id < 1) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid step ID']);
    exit();
}

try {
    $conn = getDBConnection();
    
    // Verify step belongs to user
    $check_stmt = $conn->prepare("
        SELECT ps.project_id 
        FROM project_steps ps
        JOIN projects p ON ps.project_id = p.project_id 
        WHERE ps.step_id = ? AND p.user_id = ?
    ");
    $check_stmt->bind_param("ii", $step_id, $_SESSION['user_id']);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if (!$step = $result->fetch_assoc()) {
        echo json_encode(['status' => 'error', 'message' => 'Step not found or access denied']);
        exit();
    }
    
    $project_id = $step['project_id'];
    
    // Check if files were uploaded
    if (!isset($_FILES['step_images']) || $_FILES['step_images']['error'][0] == UPLOAD_ERR_NO_FILE) {
        echo json_encode(['status' => 'error', 'message' => 'No files selected']);
        exit();
    }
    
    // Create upload directory
    $upload_dir = 'uploads/step_images/' . $step_id . '/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $uploaded_files = [];
    $errors = [];
    
    // Process each uploaded file
    for ($i = 0; $i < count($_FILES['step_images']['name']); $i++) {
        // Skip if there was an upload error
        if ($_FILES['step_images']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "File " . $_FILES['step_images']['name'][$i] . " upload error";
            continue;
        }
        
        $file_name = $_FILES['step_images']['name'][$i];
        $file_tmp = $_FILES['step_images']['tmp_name'][$i];
        $file_size = $_FILES['step_images']['size'][$i];
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $file_mime = finfo_file($file_info, $file_tmp);
        finfo_close($file_info);
        
        if (!in_array($file_mime, $allowed_types)) {
            $errors[] = "File $file_name is not a valid image type";
            continue;
        }
        
        // Validate file size (max 5MB)
        if ($file_size > 5 * 1024 * 1024) {
            $errors[] = "File $file_name exceeds 5MB limit";
            continue;
        }
        
        // Generate unique filename
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
        $destination = $upload_dir . $unique_name;
        $relative_path = 'uploads/step_images/' . $step_id . '/' . $unique_name;
        
        // Move uploaded file
        if (move_uploaded_file($file_tmp, $destination)) {
            // Save to database
            $stmt = $conn->prepare("INSERT INTO project_step_images (step_id, image_path) VALUES (?, ?)");
            $stmt->bind_param("is", $step_id, $relative_path);
            $stmt->execute();
            
            $uploaded_files[] = [
                'original_name' => $file_name,
                'saved_name' => $unique_name,
                'path' => $relative_path
            ];
        } else {
            $errors[] = "Failed to move uploaded file: $file_name";
        }
    }
    
    // Prepare response
    if (count($uploaded_files) > 0) {
        $response = [
            'status' => 'success',
            'message' => 'Successfully uploaded ' . count($uploaded_files) . ' image(s) for this step',
            'uploaded_count' => count($uploaded_files),
            'step_id' => $step_id,
            'files' => $uploaded_files
        ];
        
        if (count($errors) > 0) {
            $response['warnings'] = $errors;
        }
    } else {
        $response = [
            'status' => 'error',
            'message' => 'No files were uploaded successfully',
            'errors' => $errors
        ];
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>