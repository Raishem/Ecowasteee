<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

// Get database connection
$conn = getDBConnection();

// Function to check if project belongs to user
function verifyProjectOwnership($conn, $projectId, $userId) {
    $stmt = $conn->prepare("SELECT user_id FROM projects WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    return $project && $project['user_id'] == $userId;
}

// Function to fetch project materials
function getProjectMaterials($conn, $projectId) {
    $stmt = $conn->prepare("SELECT * FROM project_materials WHERE project_id = ? ORDER BY created_at");
    $stmt->execute([$projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to fetch project photos
function getProjectPhotos($conn, $projectId) {
    $stmt = $conn->prepare("SELECT * FROM project_photos WHERE project_id = ? ORDER BY created_at DESC");
    $stmt->execute([$projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get action from request
$action = $_GET['action'] ?? '';

// Process the action
try {
    switch ($action) {
        case 'get_project_details':
            $projectId = $_GET['project_id'] ?? '';
            if (!$projectId || !verifyProjectOwnership($conn, $projectId, $_SESSION['user_id'])) {
                throw new Exception('Invalid project');
            }

            $stmt = $conn->prepare("
                SELECT p.*, 
                       COUNT(DISTINCT m.material_id) as total_materials,
                       COUNT(DISTINCT CASE WHEN m.is_found = 1 THEN m.material_id END) as found_materials,
                       COUNT(DISTINCT ph.photo_id) as photo_count
                FROM projects p 
                LEFT JOIN project_materials m ON p.project_id = m.project_id
                LEFT JOIN project_photos ph ON p.project_id = ph.project_id
                WHERE p.project_id = ?
                GROUP BY p.project_id
            ");
            $stmt->execute([$projectId]);
            $project = $stmt->fetch();

            $materials = getProjectMaterials($conn, $projectId);
            $photos = getProjectPhotos($conn, $projectId);

            echo json_encode([
                'success' => true,
                'project' => $project,
                'materials' => $materials,
                'photos' => $photos
            ]);
            break;

        case 'add_material':
            $data = json_decode(file_get_contents('php://input'), true);
            $projectId = $data['project_id'] ?? '';
            $name = $data['name'] ?? '';
            $unit = $data['unit'] ?? '';

            if (!$projectId || !$name || !verifyProjectOwnership($conn, $projectId, $_SESSION['user_id'])) {
                throw new Exception('Invalid request');
            }

            $stmt = $conn->prepare("INSERT INTO project_materials (project_id, name, unit) VALUES (?, ?, ?)");
            $stmt->execute([$projectId, $name, $unit]);
            
            $materials = getProjectMaterials($conn, $projectId);
            echo json_encode(['success' => true, 'materials' => $materials]);
            break;

        case 'mark_material_found':
            $data = json_decode(file_get_contents('php://input'), true);
            $projectId = $data['project_id'] ?? '';
            $materialId = $data['material_id'] ?? '';

            if (!$projectId || !$materialId || !verifyProjectOwnership($conn, $projectId, $_SESSION['user_id'])) {
                throw new Exception('Invalid request');
            }

            $stmt = $conn->prepare("UPDATE project_materials SET is_found = 1 WHERE material_id = ? AND project_id = ?");
            $stmt->execute([$materialId, $projectId]);
            
            $materials = getProjectMaterials($conn, $projectId);
            echo json_encode(['success' => true, 'materials' => $materials]);
            break;

        case 'delete_material':
            $data = json_decode(file_get_contents('php://input'), true);
            $projectId = $data['project_id'] ?? '';
            $materialId = $data['material_id'] ?? '';

            if (!$projectId || !$materialId || !verifyProjectOwnership($conn, $projectId, $_SESSION['user_id'])) {
                throw new Exception('Invalid request');
            }

            $stmt = $conn->prepare("DELETE FROM project_materials WHERE material_id = ? AND project_id = ?");
            $stmt->execute([$materialId, $projectId]);
            
            $materials = getProjectMaterials($conn, $projectId);
            echo json_encode(['success' => true, 'materials' => $materials]);
            break;

        case 'update_status':
            $data = json_decode(file_get_contents('php://input'), true);
            $projectId = $data['project_id'] ?? '';
            $status = $data['status'] ?? '';

            if (!$projectId || !$status || !verifyProjectOwnership($conn, $projectId, $_SESSION['user_id'])) {
                throw new Exception('Invalid request');
            }

            $stmt = $conn->prepare("
                UPDATE projects 
                SET status = ?,
                    completion_date = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END,
                    updated_at = NOW()
                WHERE project_id = ?
            ");
            $stmt->execute([$status, $status, $projectId]);
            echo json_encode(['success' => true]);
            break;

        case 'add_photo':
            $projectId = $_POST['project_id'] ?? '';
            if (!$projectId || !verifyProjectOwnership($conn, $projectId, $_SESSION['user_id'])) {
                throw new Exception('Invalid request');
            }

            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No photo uploaded');
            }

            $file = $_FILES['photo'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (!in_array($ext, $allowedTypes)) {
                throw new Exception('Invalid file type');
            }

            $newFileName = uniqid() . '_' . date('Ymd') . '.' . $ext;
            $uploadPath = 'assets/uploads/' . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $stmt = $conn->prepare("INSERT INTO project_photos (project_id, photo_url) VALUES (?, ?)");
                $stmt->execute([$projectId, $uploadPath]);
                
                $photos = getProjectPhotos($conn, $projectId);
                echo json_encode(['success' => true, 'photos' => $photos]);
            } else {
                throw new Exception('Error uploading file');
            }
            break;

        case 'delete_photo':
            $data = json_decode(file_get_contents('php://input'), true);
            $projectId = $data['project_id'] ?? '';
            $photoId = $data['photo_id'] ?? '';

            if (!$projectId || !$photoId || !verifyProjectOwnership($conn, $projectId, $_SESSION['user_id'])) {
                throw new Exception('Invalid request');
            }

            // Get photo URL before deleting
            $stmt = $conn->prepare("SELECT photo_url FROM project_photos WHERE photo_id = ? AND project_id = ?");
            $stmt->execute([$photoId, $projectId]);
            $photo = $stmt->fetch();
            
            if ($photo && file_exists($photo['photo_url'])) {
                unlink($photo['photo_url']); // Delete the file
            }

            $stmt = $conn->prepare("DELETE FROM project_photos WHERE photo_id = ? AND project_id = ?");
            $stmt->execute([$photoId, $projectId]);
            
            $photos = getProjectPhotos($conn, $projectId);
            echo json_encode(['success' => true, 'photos' => $photos]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}