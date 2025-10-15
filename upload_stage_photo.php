<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

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
$photo_type = isset($_POST['photo_type']) ? trim($_POST['photo_type']) : 'other';

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
    
    // Handle photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['photo']['tmp_name'];
        $file_type = mime_content_type($file_tmp);
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];

        if (!in_array($file_type, $allowed_types)) {
            echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, and GIF files are allowed']);
            exit;
        }

        $upload_dir = 'assets/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $unique_file_name = uniqid() . '_' . basename($_FILES['photo']['name']);
        $target_file = $upload_dir . $unique_file_name;

        if (move_uploaded_file($file_tmp, $target_file)) {
            // Normalize photo_type and restrict to known types
            $allowed_ptypes = ['before','after','other'];
            $ptype = in_array(strtolower($photo_type), $allowed_ptypes) ? strtolower($photo_type) : 'other';

            // Insert into stage_photos table (store photo_type)
            $photo_stmt = $conn->prepare("INSERT INTO stage_photos (project_id, stage_number, photo_path, photo_type, uploaded_at) VALUES (?, ?, ?, ?, NOW())");
            $photo_stmt->bind_param("iiss", $project_id, $stage_number, $unique_file_name, $ptype);
            $photo_stmt->execute();
            
            echo json_encode(['success' => true, 'photo_type' => $ptype]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>