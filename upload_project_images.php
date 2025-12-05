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
    
    // === ADD THE ACTUAL UPLOAD LOGIC HERE ===
    
    // Create upload directory if it doesn't exist
    $upload_dir = 'uploads/project_images/' . $project_id . '/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }
    
    // Check if directory is writable
    if (!is_writable($upload_dir)) {
        throw new Exception('Upload directory is not writable');
    }
    
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $max_file_size = 5 * 1024 * 1024; // 5MB
    $uploaded_files = [];
    $errors = [];
    
    // Process each file
    $file_count = count($_FILES['project_images']['name']);
    
    for ($i = 0; $i < $file_count; $i++) {
        $file_error = $_FILES['project_images']['error'][$i];
        $file_name = $_FILES['project_images']['name'][$i];
        $file_size = $_FILES['project_images']['size'][$i];
        $file_type = $_FILES['project_images']['type'][$i];
        $file_tmp = $_FILES['project_images']['tmp_name'][$i];
        
        // Skip if no file
        if ($file_error == UPLOAD_ERR_NO_FILE) {
            continue;
        }
        
        // Check for upload errors
        if ($file_error !== UPLOAD_ERR_OK) {
            $errors[] = "Upload error for '$file_name': " . $this->getUploadError($file_error);
            continue;
        }
        
        // Validate file type
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "File type not allowed: '$file_name' ($file_type)";
            continue;
        }
        
        // Validate file size
        if ($file_size > $max_file_size) {
            $errors[] = "File too large (max 5MB): '$file_name'";
            continue;
        }
        
        // Generate unique filename
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $unique_name = uniqid() . '_' . time() . '_' . $i . '.' . $file_extension;
        $destination = $upload_dir . $unique_name;
        
        // Move uploaded file
        if (move_uploaded_file($file_tmp, $destination)) {
            // Save to database
            try {
                $insert_stmt = $conn->prepare("
                    INSERT INTO project_images (project_id, image_path, uploaded_at, is_final_image) 
                    VALUES (?, ?, NOW(), 0)
                ");
                
                if (!$insert_stmt) {
                    throw new Exception('Database prepare failed for image insert');
                }
                
                $insert_stmt->bind_param("is", $project_id, $destination);
                
                if (!$insert_stmt->execute()) {
                    throw new Exception('Failed to save image to database: ' . $insert_stmt->error);
                }
                
                $uploaded_files[] = [
                    'original_name' => $file_name,
                    'saved_path' => $destination,
                    'image_id' => $insert_stmt->insert_id
                ];
                
                $insert_stmt->close();
                
            } catch (Exception $e) {
                // Delete the file if database insert fails
                if (file_exists($destination)) {
                    unlink($destination);
                }
                $errors[] = "Database error for '$file_name': " . $e->getMessage();
            }
        } else {
            $errors[] = "Failed to move uploaded file: '$file_name'";
        }
    }
    
    // Check if any files were successfully uploaded
    if (empty($uploaded_files)) {
        if (empty($errors)) {
            throw new Exception('No files were uploaded');
        } else {
            throw new Exception('All uploads failed: ' . implode(', ', array_slice($errors, 0, 3)));
        }
    }
    
    // Prepare success response
    $response['status'] = 'success';
    $response['message'] = count($uploaded_files) . ' file(s) uploaded successfully';
    $response['uploaded'] = $uploaded_files;
    
    if (!empty($errors)) {
        $response['status'] = 'partial';
        $response['message'] = count($uploaded_files) . ' file(s) uploaded, ' . count($errors) . ' failed';
        $response['errors'] = $errors;
    }
    
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

// Helper function to get upload error message
function getUploadError($error_code) {
    $upload_errors = array(
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by PHP extension'
    );
    
    return isset($upload_errors[$error_code]) ? $upload_errors[$error_code] : 'Unknown upload error';
}
?>