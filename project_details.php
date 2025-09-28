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
                'quantity', pm.quantity,
                'status', COALESCE(pm.status, 'needed')
            )
        ) as materials_json
        FROM projects p
        LEFT JOIN project_materials pm ON p.project_id = pm.project_id
        WHERE p.project_id = ? AND p.user_id = ?
        GROUP BY p.project_id
    ");
    
    $project_query->bind_param("ii", $project_id, $user_id);
    $project_query->execute();
    $result = $project_query->get_result();
    $project = $result->fetch_assoc();
    
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
                $update_stmt->bind_param("ssii", $name, $description, $project_id, $user_id);
                $update_stmt->execute();
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
                $add_stmt->bind_param("isi", $project_id, $material_name, $quantity);
                $add_stmt->execute();
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
            $remove_stmt->bind_param("ii", $material_id, $project_id);
            $remove_stmt->execute();
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
    <link rel="stylesheet" href="assets/css/project-details.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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
            
            <nav class="breadcrumb">
                <a href="projects.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Projects</a>
            </nav>

            <div class="details-header">
                <div class="header-content">
                    <h1 class="project-title" id="projectTitle"><?= htmlspecialchars($project['project_name']) ?></h1>
                    <div class="project-meta">
                        <span class="project-date"><i class="far fa-calendar"></i> Created on: <?= date('M d, Y', strtotime($project['created_at'])) ?></span>
                    </div>
                </div>
                <button class="edit-btn" onclick="openEditProjectModal()">
                    <i class="fas fa-edit"></i> Edit Project
                </button>
            </div>
            
            <div class="project-info">
                <h2 class="section-title">Project Description</h2>
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