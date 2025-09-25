<?php
session_start();
require_once 'config.php';

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Get project data
$conn = getDBConnection();
try {
    // Get project details with materials
    $project_query = $conn->prepare("
        SELECT p.*, GROUP_CONCAT(
            JSON_OBJECT(
                'id', pm.material_id,
                'name', pm.material_name,
                'quantity', pm.quantity
            )
        ) as materials_json
        FROM projects p
        LEFT JOIN project_materials pm ON p.project_id = pm.project_id
        WHERE p.project_id = ? AND p.user_id = ?
        GROUP BY p.project_id
    ");
    
    $project_query->execute([$project_id, $user_id]);
    $project = $project_query->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        header('Location: projects.php');
        exit();
    }
    
    $materials = $project['materials_json'] ? json_decode('[' . $project['materials_json'] . ']', true) : [];
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_project'])) {
            // Update project details
            $name = trim($_POST['project_name']);
            $description = trim($_POST['project_description']);
            
            if (empty($name)) {
                $error_message = "Project name is required.";
            } else {
                $update_stmt = $conn->prepare("
                    UPDATE projects 
                    SET project_name = ?, description = ? 
                    WHERE project_id = ? AND user_id = ?
                ");
                $update_stmt->execute([$name, $description, $project_id, $user_id]);
                $success_message = "Project updated successfully!";
                
                // Refresh project data
                $project['project_name'] = $name;
                $project['description'] = $description;
            }
        } elseif (isset($_POST['add_material'])) {
            // Add new material
            $material_name = trim($_POST['material_name']);
            $quantity = (int)$_POST['quantity'];
            
            if (empty($material_name) || $quantity <= 0) {
                $error_message = "Valid material name and quantity are required.";
            } else {
                $add_stmt = $conn->prepare("
                    INSERT INTO project_materials (project_id, material_name, quantity, status) 
                    VALUES (?, ?, ?, 'needed')
                ");
                $add_stmt->execute([$project_id, $material_name, $quantity]);
                $success_message = "Material added successfully!";
                
                // Refresh the page to show new material
                header("Location: project_details.php?id=$project_id&success=material_added");
                exit();
            }
        } elseif (isset($_POST['remove_material'])) {
            // Remove material
            $material_id = (int)$_POST['material_id'];
            $remove_stmt = $conn->prepare("
                DELETE FROM project_materials 
                WHERE id = ? AND project_id = ?
            ");
            $remove_stmt->execute([$material_id, $project_id]);
            $success_message = "Material removed successfully!";
            
            // Refresh the page
            header("Location: project_details.php?id=$project_id&success=material_removed");
            exit();
        } elseif (isset($_POST['update_material_status'])) {
            // Update material status
            $material_id = (int)$_POST['material_id'];
            $status = $_POST['status'];
            $update_stmt = $conn->prepare("
                UPDATE project_materials 
                SET status = ? 
                WHERE id = ? AND project_id = ?
            ");
            $update_stmt->execute([$status, $material_id, $project_id]);
            $success_message = "Material status updated!";
            
            // Refresh the page
            header("Location: project_details.php?id=$project_id&success=status_updated");
            exit();
        }
    }
    
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Details | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/homepage.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .project-details {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .details-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .edit-btn {
            background: #2e8b57;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .edit-btn:hover {
            background: #3cb371;
        }
        
        .project-info {
            margin-bottom: 30px;
        }
        
        .project-title {
            color: #2e8b57;
            margin: 0 0 10px 0;
        }
        
        .project-description {
            color: #555;
            line-height: 1.6;
        }
        
        .materials-section {
            background: #f5f9f5;
            border-radius: 8px;
            padding: 20px;
        }
        
        .materials-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .add-material-btn {
            background: #2e8b57;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .materials-list {
            display: grid;
            gap: 15px;
        }
        
        .material-item {
            background: white;
            padding: 15px;
            border-radius: 6px;
            display: grid;
            grid-template-columns: 1fr auto auto auto;
            align-items: center;
            gap: 15px;
        }
        
        .material-info {
            display: flex;
            flex-direction: column;
        }
        
        .material-name {
            font-weight: 600;
            color: #333;
        }
        
        .material-quantity {
            color: #666;
            font-size: 0.9rem;
        }
        
        .material-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            text-align: center;
        }
        
        .status-needed {
            background: #fff3e0;
            color: #ef6c00;
        }
        
        .status-requested {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .status-donated {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-received {
            background: #e8eaf6;
            color: #303f9f;
        }
        
        .status-completed {
            background: #e0f2f1;
            color: #00695c;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .find-btn {
            background: #2196f3;
            color: white;
        }
        
        .find-btn:hover {
            background: #1976d2;
        }
        
        .check-btn {
            background: #4caf50;
            color: white;
        }
        
        .check-btn:hover {
            background: #388e3c;
        }
        
        .remove-btn {
            background: #f44336;
            color: white;
        }
        
        .remove-btn:hover {
            background: #d32f2f;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            width: 100%;
            max-width: 500px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 1.25rem;
            color: #2e8b57;
            margin: 0;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        
        .success-message,
        .error-message {
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .success-message {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        
        .error-message {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="project-details">
            <?php if ($success_message): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <div class="details-header">
                <h1 class="project-title" id="projectTitle"><?= htmlspecialchars($project['project_name']) ?></h1>
                <button class="edit-btn" onclick="openEditProjectModal()">
                    <i class="fas fa-edit"></i> Edit Project
                </button>
            </div>
            
            <div class="project-info">
                <p class="project-description" id="projectDescription">
                    <?= htmlspecialchars($project['description']) ?>
                </p>
            </div>
            
            <div class="materials-section">
                <div class="materials-header">
                    <h2>Materials Needed</h2>
                    <button class="add-material-btn" onclick="openAddMaterialModal()">
                        <i class="fas fa-plus"></i> Add Material
                    </button>
                </div>
                
                <div class="materials-list">
                    <?php foreach ($materials as $material): ?>
                        <div class="material-item">
                            <div class="material-info">
                                <span class="material-name"><?= htmlspecialchars($material['name']) ?></span>
                                <span class="material-quantity">Quantity: <?= htmlspecialchars($material['quantity']) ?></span>
                            </div>
                            
                            <div class="material-status status-<?= $material['status'] ?>">
                                <?= ucfirst($material['status']) ?>
                            </div>
                            
                            <div class="action-buttons">
                                <?php if ($material['status'] === 'needed'): ?>
                                    <button class="action-btn find-btn" onclick="window.location.href='material_donation.php?material_id=<?= $material['id'] ?>'">
                                        <i class="fas fa-search"></i> Find Donations
                                    </button>
                                    <button class="action-btn check-btn" onclick="updateMaterialStatus(<?= $material['id'] ?>, 'completed')">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($material['status'] === 'requested'): ?>
                                    <button class="action-btn check-btn" onclick="updateMaterialStatus(<?= $material['id'] ?>, 'completed')">
                                        <i class="fas fa-check"></i> Mark Received
                                    </button>
                                <?php endif; ?>
                                
                                <button class="action-btn remove-btn" onclick="removeMaterial(<?= $material['id'] ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Project Modal -->
    <div class="modal" id="editProjectModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Project</h3>
                <button class="close-modal" onclick="closeEditProjectModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label for="project_name">Project Name</label>
                    <input type="text" id="project_name" name="project_name" value="<?= htmlspecialchars($project['project_name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="project_description">Description</label>
                    <textarea id="project_description" name="project_description" required><?= htmlspecialchars($project['description']) ?></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="action-btn" onclick="closeEditProjectModal()">Cancel</button>
                    <button type="submit" name="update_project" class="action-btn check-btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add Material Modal -->
    <div class="modal" id="addMaterialModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add Material</h3>
                <button class="close-modal" onclick="closeAddMaterialModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label for="material_name">Material Name</label>
                    <input type="text" id="material_name" name="material_name" required>
                </div>
                
                <div class="form-group">
                    <label for="quantity">Quantity</label>
                    <input type="number" id="quantity" name="quantity" min="1" required>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="action-btn" onclick="closeAddMaterialModal()">Cancel</button>
                    <button type="submit" name="add_material" class="action-btn check-btn">Add Material</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Modal functionality
        function openEditProjectModal() {
            document.getElementById('editProjectModal').classList.add('active');
        }
        
        function closeEditProjectModal() {
            document.getElementById('editProjectModal').classList.remove('active');
        }
        
        function openAddMaterialModal() {
            document.getElementById('addMaterialModal').classList.add('active');
        }
        
        function closeAddMaterialModal() {
            document.getElementById('addMaterialModal').classList.remove('active');
        }
        
        // Material actions
        function removeMaterial(materialId) {
            if (confirm('Are you sure you want to remove this material?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="material_id" value="${materialId}">
                    <input type="hidden" name="remove_material" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function updateMaterialStatus(materialId, status) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="material_id" value="${materialId}">
                <input type="hidden" name="status" value="${status}">
                <input type="hidden" name="update_material_status" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        
        // Close modals when clicking outside
        window.addEventListener('click', (event) => {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>