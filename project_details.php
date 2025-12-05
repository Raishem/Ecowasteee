<?php
session_start();
require_once 'config.php';

// Initialize variables
$project = [];
$materials = [];
$steps = [];
$completed_stages = []; // ADD THIS LINE
$success_message = '';
$error_message = '';
$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Additional variables for conditional button logic
$all_materials_obtained = false;
$has_materials = false;
$has_project_images = false;
$all_steps_complete = false;
$has_steps = false;

// Check login status
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

try {
    $conn = getDBConnection();
    
    // Get project details
    $stmt = $conn->prepare("SELECT * FROM projects WHERE project_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $project_id, $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result->fetch_assoc();

    if (!$project) {
        header('Location: projects.php');
        exit();
    }
    
    // Get project materials with acquired status
    $materials_stmt = $conn->prepare("SELECT * FROM project_materials WHERE project_id = ?");
    $materials_stmt->bind_param("i", $project_id);
    $materials_stmt->execute();
    $materials = $materials_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Check if all materials are obtained for preparation phase
    $all_materials_obtained = false;
    $has_materials = count($materials) > 0;
    if ($has_materials) {
        $all_materials_obtained = true;
        foreach ($materials as &$material) { // Use reference to modify array
            // Ensure we have the correct quantity field
            $acquired = isset($material['acquired_quantity']) ? (int)$material['acquired_quantity'] : 0;
            // Use 'quantity' field instead of 'needed_quantity' if that's what your database has
            $needed = isset($material['quantity']) ? (int)$material['quantity'] : 0;
            
            // Add the calculated needed quantity to the material array
            $material['calculated_needed'] = $needed;
            $material['calculated_acquired'] = $acquired;
            
            if ($acquired < $needed) {
                $all_materials_obtained = false;
            }
        }
        unset($material); // Break the reference
    }

    // Check if project has at least one image attachment
    $has_project_images = false;
    if ($conn) {
        $images_stmt = $conn->prepare("SELECT COUNT(*) as image_count FROM project_images WHERE project_id = ?");
        $images_stmt->bind_param("i", $project_id);
        $images_stmt->execute();
        $images_result = $images_stmt->get_result()->fetch_assoc();
        $has_project_images = ($images_result['image_count'] ?? 0) > 0;
    }

    // Get project steps with image and description check
    $steps_stmt = $conn->prepare("SELECT * FROM project_steps WHERE project_id = ? ORDER BY step_number");
    $steps_stmt->bind_param("i", $project_id);
    $steps_stmt->execute();
    $steps = $steps_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Check if all steps have images and descriptions for construction phase
    $all_steps_complete = false;
    $has_steps = count($steps) > 0;
    if ($has_steps) {
        $all_steps_complete = true;
        foreach ($steps as $step) {
            // Check if step has description - USE 'instructions' COLUMN
            $has_description = isset($step['instructions']) && !empty(trim($step['instructions']));
            
            // Check if step has at least one image
            $has_step_image = false;
            if ($conn) {
                try {
                    $step_images_stmt = $conn->prepare("SELECT COUNT(*) as image_count FROM project_step_images WHERE step_id = ?");
                    $step_images_stmt->bind_param("i", $step['step_id']);
                    $step_images_stmt->execute();
                    $step_images_result = $step_images_stmt->get_result()->fetch_assoc();
                    $has_step_image = ($step_images_result['image_count'] ?? 0) > 0;
                } catch (Exception $e) {
                    // If table doesn't exist yet, assume no images
                    $has_step_image = false;
                }
            }
            
            if (!$has_description || !$has_step_image) {
                $all_steps_complete = false;
                break;
            }
        }
    }

    // Get completed stages
    $completed_stages = []; // Initialize empty array
    if ($conn) {
        try {
            $stages_stmt = $conn->prepare("SELECT stage_number FROM project_stages WHERE project_id = ? AND is_completed = 1");
            $stages_stmt->bind_param("i", $project_id);
            $stages_stmt->execute();
            $completed_stages_result = $stages_stmt->get_result();
            while ($row = $completed_stages_result->fetch_assoc()) {
                $completed_stages[] = (int)$row['stage_number'];
            }
        } catch (Exception $e) {
            // If error, keep empty array
            $completed_stages = [];
        }
    }
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check if it's an AJAX request
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        // Update project details
        if (isset($_POST['update_project'])) {
            $name = isset($_POST['project_name']) ? trim($_POST['project_name']) : '';
            $description = isset($_POST['project_description']) ? trim($_POST['project_description']) : '';

            if ($name === '') {
                $error_message = "Project name is required.";
            } else {
                try {
                    $u = $conn->prepare("UPDATE projects SET project_name = ?, description = ? WHERE project_id = ? AND user_id = ?");
                    $u->bind_param('ssii', $name, $description, $project_id, $_SESSION['user_id']);
                    $u->execute();
                    $success_message = 'Project updated successfully';
                    header("Location: project_details.php?id=$project_id&success=updated");
                    exit();
                } catch (Exception $e) {
                    $error_message = 'Failed to update project';
                }
            }
        }
        // Complete stage
        elseif (isset($_POST['complete_stage'])) {
            $stage_number = (int)$_POST['stage_number'];
            
            // Check if stage can be completed (all requirements met)
            $can_complete = true;
            $reason = '';
            
            if ($stage_number === 1) {
                // Stage 1: Preparation - requires at least one material
                if (count($materials) === 0) {
                    $can_complete = false;
                    $reason = 'Add at least one material to complete preparation.';
                }
            } elseif ($stage_number === 2) {
                // Stage 2: Construction - requires at least one step
                if (count($steps) === 0) {
                    $can_complete = false;
                    $reason = 'Add at least one step to complete construction.';
                }
            }
            
            if ($can_complete) {
                try {
                    // Mark stage as completed
                    $complete_stmt = $conn->prepare("
                        INSERT INTO project_stages (project_id, stage_number, is_completed, completed_at) 
                        VALUES (?, ?, 1, NOW()) 
                        ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = NOW()
                    ");
                    $complete_stmt->bind_param("ii", $project_id, $stage_number);
                    $complete_stmt->execute();
                    
                    $success_message = "Stage $stage_number completed successfully!";
                    header("Location: project_details.php?id=$project_id");
                    exit();
                } catch (Exception $e) {
                    $error_message = "Failed to complete stage: " . $e->getMessage();
                }
            } else {
                $error_message = $reason;
            }
        }

        // Add material - FIXED VERSION
        elseif (isset($_POST['add_material'])) {
            $material_name = trim($_POST['material_name']);
            $quantity = (int)$_POST['quantity'];
            
            if ($material_name === '') {
                $error_message = "Material name is required.";
            } elseif ($quantity < 1) {
                $error_message = "Quantity must be at least 1.";
            } else {
                try {
                    // FIXED: Corrected the SQL query and parameter binding
                    $add_stmt = $conn->prepare("
                        INSERT INTO project_materials (project_id, material_name, quantity, acquired_quantity, status) 
                        VALUES (?, ?, ?, 0, 'needed')
                    ");
                    // Only 4 parameters needed: project_id, material_name, quantity
                    $add_stmt->bind_param("isi", $project_id, $material_name, $quantity);
                    $add_stmt->execute();
                    
                    $new_material_id = $add_stmt->insert_id;
                    
                    if ($is_ajax) {
                        // Return JSON response for AJAX
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Material added successfully!',
                            'material_id' => $new_material_id,
                            'material_name' => htmlspecialchars($material_name),
                            'needed_quantity' => $quantity,
                            'acquired_quantity' => 0
                        ]);
                        exit();
                    } else {
                        // Traditional redirect
                        $success_message = "Material added successfully!";
                        header("Location: project_details.php?id=$project_id");
                        exit();
                    }
                } catch (Exception $e) {
                    $error_message = "Failed to add material: " . $e->getMessage();
                    error_log("Material add error: " . $e->getMessage()); // Add error logging
                    if ($is_ajax) {
                        echo json_encode(['status' => 'error', 'message' => $error_message]);
                        exit();
                    }
                }
            }
            
            if ($is_ajax && $error_message) {
                echo json_encode(['status' => 'error', 'message' => $error_message]);
                exit();
            }
        }

        // Update material acquired quantity - FIXED FOR INCREMENTAL ADDITION
        elseif (isset($_POST['update_material_quantity'])) {
            $material_id = (int)$_POST['material_id'];
            $additional_quantity = (int)$_POST['acquired_quantity']; // This is now ADDITIONAL quantity
            
            try {
                // First get the current acquired quantity and total quantity
                $check_stmt = $conn->prepare("SELECT quantity, acquired_quantity FROM project_materials WHERE material_id = ? AND project_id = ?");
                $check_stmt->bind_param("ii", $material_id, $project_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result()->fetch_assoc();
                
                if (!$check_result) {
                    throw new Exception("Material not found");
                }
                
                $total_quantity = (int)$check_result['quantity'];
                $current_acquired = (int)$check_result['acquired_quantity'];
                
                // Calculate new total acquired (current + additional)
                $new_acquired_quantity = $current_acquired + $additional_quantity;
                
                // Ensure not exceeding total quantity
                $new_acquired_quantity = min($new_acquired_quantity, $total_quantity);
                
                // Update the acquired quantity
                $update_stmt = $conn->prepare("UPDATE project_materials SET acquired_quantity = ? WHERE material_id = ? AND project_id = ?");
                $update_stmt->bind_param("iii", $new_acquired_quantity, $material_id, $project_id);
                $update_stmt->execute();
                
                if ($is_ajax) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Quantity updated successfully!',
                        'material_id' => $material_id,
                        'acquired_quantity' => $new_acquired_quantity,
                        'additional_quantity' => $additional_quantity,
                        'total_quantity' => $total_quantity
                    ]);
                    exit();
                } else {
                    $success_message = "Quantity updated successfully!";
                    header("Location: project_details.php?id=$project_id");
                    exit();
                }
            } catch (Exception $e) {
                $error_message = "Failed to update quantity: " . $e->getMessage();
                if ($is_ajax) {
                    echo json_encode(['status' => 'error', 'message' => $error_message]);
                    exit();
                }
            }
        }

        // Add step - SIMPLIFIED VERSION (using instructions column)
        elseif (isset($_POST['add_step'])) {
            $step_title = trim($_POST['step_title']);
            $step_description = trim($_POST['step_description'] ?? ''); // This will map to instructions column
            
            if ($step_title === '') {
                $error_message = "Step title is required.";
            } elseif ($step_description === '') {
                $error_message = "Step description is required.";
            } else {
                // Get next step number
                $max_stmt = $conn->prepare("SELECT MAX(step_number) as max_num FROM project_steps WHERE project_id = ?");
                $max_stmt->bind_param("i", $project_id);
                $max_stmt->execute();
                $max_result = $max_stmt->get_result()->fetch_assoc();
                $next_step = ($max_result['max_num'] ?? 0) + 1;
                
                try {
                    // Use instructions column directly
                    $add_stmt = $conn->prepare("
                        INSERT INTO project_steps (project_id, step_number, title, instructions) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $add_stmt->bind_param("iiss", $project_id, $next_step, $step_title, $step_description);
                    $add_stmt->execute();
                    
                    $success_message = "Step added successfully!";
                    header("Location: project_details.php?id=$project_id");
                    exit();
                } catch (Exception $e) {
                    $error_message = "Failed to add step: " . $e->getMessage();
                }
            }
        }

        // Remove material
        elseif (isset($_POST['remove_material'])) {
            $material_id = (int)$_POST['material_id'];
            
            try {
                $remove_stmt = $conn->prepare("DELETE FROM project_materials WHERE material_id = ? AND project_id = ?");
                $remove_stmt->bind_param("ii", $material_id, $project_id);
                $remove_stmt->execute();
                
                if ($is_ajax) {
                    // Return JSON response for AJAX
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Material removed successfully!',
                        'material_id' => $material_id
                    ]);
                    exit();
                } else {
                    // Traditional redirect
                    $success_message = "Material removed successfully!";
                    header("Location: project_details.php?id=$project_id");
                    exit();
                }
            } catch (Exception $e) {
                $error_message = "Failed to remove material: " . $e->getMessage();
                if ($is_ajax) {
                    echo json_encode(['status' => 'error', 'message' => $error_message]);
                    exit();
                }
            }
        }

        // Remove step
        elseif (isset($_POST['remove_step'])) {
            $step_id = (int)$_POST['step_id'];
            
            try {
                $remove_stmt = $conn->prepare("DELETE FROM project_steps WHERE step_id = ? AND project_id = ?");
                $remove_stmt->bind_param("ii", $step_id, $project_id);
                $remove_stmt->execute();
                
                if ($is_ajax) {
                    // Return JSON response for AJAX
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Step removed successfully!',
                        'step_id' => $step_id
                    ]);
                    exit();
                } else {
                    // Traditional redirect
                    $success_message = "Step removed successfully!";
                    header("Location: project_details.php?id=$project_id");
                    exit();
                }
            } catch (Exception $e) {
                $error_message = "Failed to remove step: " . $e->getMessage();
                if ($is_ajax) {
                    echo json_encode(['status' => 'error', 'message' => $error_message]);
                    exit();
                }
            }
        }

        // Share project
        elseif (isset($_POST['share_project'])) {
            $is_public = isset($_POST['is_public']) ? 1 : 0;
            
            try {
                // Update project visibility in projects table (optional, but good to have)
                try {
                    // Check if is_public column exists in projects table
                    $check_column = $conn->query("SHOW COLUMNS FROM projects LIKE 'is_public'");
                    if ($check_column->num_rows > 0) {
                        $update_project_stmt = $conn->prepare("UPDATE projects SET is_public = ? WHERE project_id = ? AND user_id = ?");
                        $update_project_stmt->bind_param("iii", $is_public, $project_id, $_SESSION['user_id']);
                        $update_project_stmt->execute();
                    }
                } catch (Exception $e) {
                    // Ignore if column doesn't exist
                }
                
                // Mark stage 3 as completed
                $stage_stmt = $conn->prepare("
                    INSERT INTO project_stages (project_id, stage_number, is_completed, completed_at) 
                    VALUES (?, 3, 1, NOW()) 
                    ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = NOW()
                ");
                $stage_stmt->bind_param("i", $project_id);
                $stage_stmt->execute();
                
                // If sharing to community, save to recycled_ideas table
                if ($is_public) {
                    $final_image_id = isset($_POST['final_image_id']) ? (int)$_POST['final_image_id'] : 0;
                    
                    // Get project details
                    $project_query = $conn->prepare("
                        SELECT p.project_name, p.description, u.first_name, u.last_name 
                        FROM projects p 
                        JOIN users u ON p.user_id = u.user_id 
                        WHERE p.project_id = ? AND p.user_id = ?
                    ");
                    $project_query->bind_param("ii", $project_id, $_SESSION['user_id']);
                    $project_query->execute();
                    $project_data = $project_query->get_result()->fetch_assoc();
                    
                    if ($project_data) {
                        // Get final project image path if exists
                        $image_path = '';
                        if ($final_image_id > 0) {
                            $image_query = $conn->prepare("SELECT image_path FROM project_images WHERE image_id = ? AND project_id = ?");
                            $image_query->bind_param("ii", $final_image_id, $project_id);
                            $image_query->execute();
                            $image_result = $image_query->get_result()->fetch_assoc();
                            if ($image_result) {
                                $image_path = $image_result['image_path'];
                            }
                        }
                        
                        // Prepare author name
                        $author_name = $project_data['first_name'] . ' ' . $project_data['last_name'];
                        
                        // Check if this project is already in recycled_ideas
                        $check_idea_stmt = $conn->prepare("SELECT idea_id FROM recycled_ideas WHERE project_id = ?");
                        $check_idea_stmt->bind_param("i", $project_id);
                        $check_idea_stmt->execute();
                        $existing_idea = $check_idea_stmt->get_result()->fetch_assoc();
                        
                        if ($existing_idea) {
                            // Update existing entry
                            $update_idea_stmt = $conn->prepare("
                                UPDATE recycled_ideas 
                                SET title = ?, description = ?, author = ?, image_path = ?, posted_at = NOW() 
                                WHERE project_id = ?
                            ");
                            // Correct parameter binding: "ssssi" (3 strings, 1 string, 1 integer)
                            $update_idea_stmt->bind_param(
                                "ssssi", 
                                $project_data['project_name'],
                                $project_data['description'],
                                $author_name,
                                $image_path,
                                $project_id
                            );
                            $update_idea_stmt->execute();
                        } else {
                            // Insert new entry
                            $insert_idea_stmt = $conn->prepare("
                                INSERT INTO recycled_ideas (project_id, title, description, author, image_path, posted_at) 
                                VALUES (?, ?, ?, ?, ?, NOW())
                            ");
                            // Correct parameter binding: "issss" (1 integer, 3 strings, 1 string)
                            $insert_idea_stmt->bind_param(
                                "issss", 
                                $project_id,
                                $project_data['project_name'],
                                $project_data['description'],
                                $author_name,
                                $image_path
                            );
                            $insert_idea_stmt->execute();
                        }
                        
                        $success_message = "Project shared to Recycled Ideas feed!";
                    }
                } else {
                    // If keeping private, remove from recycled_ideas if exists
                    $delete_idea_stmt = $conn->prepare("DELETE FROM recycled_ideas WHERE project_id = ?");
                    $delete_idea_stmt->bind_param("i", $project_id);
                    $delete_idea_stmt->execute();
                    
                    $success_message = "Project kept private.";
                }
                
                header("Location: project_details.php?id=$project_id&success=shared");
                exit();
            } catch (Exception $e) {
                $error_message = "Failed to update project: " . $e->getMessage();
                error_log("Share project error: " . $e->getMessage());
            }
        }
    }
    
} catch (mysqli_sql_exception $e) {
    $error_message = "Database error: " . $e->getMessage();
    $conn = null;
    // Ensure variables are set even on error
    $completed_stages = $completed_stages ?? [];
    $all_materials_obtained = $all_materials_obtained ?? false;
    $has_materials = $has_materials ?? false;
    $has_project_images = $has_project_images ?? false;
    $all_steps_complete = $all_steps_complete ?? false;
    $has_steps = $has_steps ?? false;
}

// Determine current stage
$current_stage = 1;
if (in_array(1, $completed_stages)) {
    $current_stage = 2;
    if (in_array(2, $completed_stages)) {
        $current_stage = 3;
    }
}

// Get user data for header
try {
    if ($conn) {
        $user_stmt = $conn->prepare("SELECT first_name, last_name, avatar FROM users WHERE user_id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_data = $user_stmt->get_result()->fetch_assoc();
    } else {
        $user_data = ['username' => 'User', 'avatar' => ''];
    }
} catch (mysqli_sql_exception $e) {
    $user_data = ['username' => 'User', 'avatar' => ''];
}

// Check if project_steps table has instructions column
$has_instructions_column = false;
try {
    if ($conn) {
        $check_col_stmt = $conn->query("SHOW COLUMNS FROM project_steps LIKE 'instructions'");
        $has_instructions_column = $check_col_stmt->num_rows > 0;
    }
} catch (Exception $e) {
    // If we can't check, assume instructions column exists
    $has_instructions_column = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Details | EcoWaste</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Open+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/project-details.css">
    <link rel="stylesheet" href="assets/css/project-description.css">
    <link rel="stylesheet" href="assets/css/project-details-modern-v2.css">
</head>
<body>
    <!-- Header -->
    <header>
        <div class="logo-container">
            <div class="logo">
                <img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo">
            </div>
            <h1>EcoWaste</h1>
        </div>
        <div class="user-profile" id="userProfile">
            <div class="profile-pic">
                <?= strtoupper(substr(htmlspecialchars($_SESSION['first_name'] ?? 'User'), 0, 1)) ?>
            </div>
            <span class="profile-name"><?= htmlspecialchars($_SESSION['first_name'] ?? 'User') ?></span>
            <i class="fas fa-chevron-down dropdown-arrow"></i>
            <div class="profile-dropdown">
                <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
                <a href="#" class="dropdown-item" id="settingsLink">
                    <i class="fas fa-cog"></i> Settings
                </a>
                <div class="dropdown-divider"></div>
                <a href="logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <nav>
                <ul>
                    <li><a href="homepage.php"><i class="fas fa-home"></i> Home</a></li>
                    <li><a href="browse.php"><i class="fas fa-search"></i> Browse</a></li>
                    <li><a href="achievements.php"><i class="fas fa-star"></i> Achievements</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i> Leaderboard</a></li>
                    <li><a href="projects.php" class="active"><i class="fas fa-recycle"></i> Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-hand-holding-heart"></i> Donations</a></li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Back Link -->
            <a href="projects.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Projects</a>
            
            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <!-- Project Header -->
            <section class="project-header card">
                <div class="project-actions">
                    <button class="edit-project edit-btn" data-action="edit-project"><i class="fas fa-edit"></i> Edit Project</button>
                </div>
                
                <div class="project-title-section">
                    <span class="project-section-label">Project Title</span>
                    <h1 class="project-title"><?= htmlspecialchars($project['project_name']) ?></h1>
                </div>
                
                <div class="project-description-section">
                    <span class="project-section-label">Project Description</span>
                    <div class="project-description collapsed">
                        <?= nl2br(htmlspecialchars($project['description'])) ?>
                    </div>
                    <button type="button" class="see-more-btn" aria-expanded="false">See more</button>
                </div>
                
                <div class="project-meta">
                    <i class="far fa-calendar-alt"></i> Created: <?= date('M d, Y', strtotime($project['created_at'])) ?>
                </div>
            </section>

            <!-- Project Progress -->
                <section class="workflow-section">
                    <h2 class="section-title"><i class="fas fa-tasks"></i> Project Workflow</h2>
                    
                    <!-- Progress Bar -->
                    <div class="progress-container">
                        <div class="progress-text">
                            <?php if (in_array(3, $completed_stages)): ?>
                                Project Complete!
                            <?php else: ?>
                                Stage <?= $current_stage ?> of 3
                            <?php endif; ?>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= (count($completed_stages) / 3) * 100 ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Tab Navigation -->
                    <div class="workflow-tabs">
                        <div class="tab-nav">
                            <button class="tab-button <?= $current_stage == 1 ? 'active' : '' ?>" data-tab="preparation">
                                <span class="tab-number">1</span>
                                Preparation
                            </button>
                            <button class="tab-button <?= $current_stage == 2 ? 'active' : '' ?> <?= !in_array(1, $completed_stages) ? 'locked' : '' ?>" 
                                    data-tab="construction">
                                <span class="tab-number">2</span>
                                Construction
                            </button>
                            <button class="tab-button <?= $current_stage == 3 ? 'active' : '' ?> <?= !in_array(2, $completed_stages) ? 'locked' : '' ?>" 
                                    data-tab="share">
                                <span class="tab-number">3</span>
                                Share
                            </button>
                        </div>
                    </div>
                    
                    <!-- Tab Content Container -->
                    <div class="tab-content-container">
                        <!-- Tab 1: Preparation -->
                        <div class="tab-content <?= $current_stage == 1 ? 'active' : '' ?>" id="preparation-tab">
                            <div class="stage-card <?= in_array(1, $completed_stages) ? 'completed' : '' ?> <?= $current_stage == 1 ? 'current' : '' ?>">
                                <?php if (in_array(1, $completed_stages)): ?>
                                    <span class="stage-check"><i class="fas fa-check"></i></span>
                                <?php endif; ?>
                                
                                <div class="stage-header">
                                    <div class="stage-marker">
                                        <span class="stage-number">1</span>
                                    </div>
                                    <div class="stage-title-container">
                                        <h3 class="stage-title">Preparation</h3>
                                        <p class="stage-subtitle">Collect materials required for this project</p>
                                    </div>
                                    <div class="stage-status">
                                        <?php if (in_array(1, $completed_stages)): ?>
                                            <span class="status-badge completed">COMPLETED</span>
                                        <?php elseif ($current_stage == 1): ?>
                                            <span class="status-badge current">CURRENT</span>
                                        <?php else: ?>
                                            <span class="status-badge locked">LOCKED</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="stage-content">
                                    <h4 class="content-title">Materials Needed</h4>
                                    <?php if (empty($materials)): ?>
                                        <div class="empty-state">No materials listed yet.</div>
                                    <?php else: ?>
                                        <ul class="materials-list">
                                            <?php foreach ($materials as $material): 
                                                // Use the calculated values
                                                $acquired = $material['calculated_acquired'] ?? 0;
                                                $needed = $material['calculated_needed'] ?? 0;
                                                $quantity_class = 'quantity-display';
                                                $is_completed = $acquired >= $needed;
                                                
                                                if ($is_completed) {
                                                    $quantity_class .= ' completed';
                                                    $material_class = 'completed';
                                                } elseif ($acquired == 0) {
                                                    $quantity_class .= ' none';
                                                    $material_class = '';
                                                } else {
                                                    $quantity_class .= ' low';
                                                    $material_class = '';
                                                }
                                            ?>
                                                <li class="material-item" data-material-id="<?= $material['material_id'] ?>">
                                                    <div class="material-info">
                                                        <div class="material-name"><?= htmlspecialchars($material['material_name']) ?></div>
                                                        <div class="material-quantity">
                                                            <span class="<?= $quantity_class ?>">
                                                                <?= $acquired ?>/<?= $needed ?>
                                                            </span> units needed
                                                            <?php if ($acquired >= $needed): ?>
                                                                <span class="material-complete-badge"><i class="fas fa-check-circle"></i> Complete</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="material-actions">
                                                        <button class="btn find-donations <?= $acquired >= $needed ? 'disabled' : '' ?>" 
                                                                data-material-id="<?= $material['material_id'] ?>"
                                                                data-material-name="<?= htmlspecialchars($material['material_name'], ENT_QUOTES) ?>"
                                                                <?= $acquired >= $needed ? 'disabled' : '' ?>>
                                                            <i class="fas fa-search"></i> Find Donations
                                                        </button>
                                                        <button class="btn checklist-btn <?= $acquired >= $needed ? 'disabled completed' : '' ?>" 
                                                                data-material-id="<?= $material['material_id'] ?>"
                                                                data-material-name="<?= htmlspecialchars($material['material_name'], ENT_QUOTES) ?>"
                                                                data-needed-quantity="<?= $needed ?>"
                                                                data-acquired-quantity="<?= $acquired ?>"
                                                                <?= $acquired >= $needed ? 'disabled' : '' ?>>
                                                            <i class="fas fa-check-circle"></i> Checklist
                                                            <?php if ($acquired >= $needed): ?>
                                                                <i class="fas fa-check ml-1"></i>
                                                            <?php endif; ?>
                                                        </button>
                                                        <button class="btn small danger" onclick="removeMaterial(<?= $material['material_id'] ?>)" 
                                                                <?= $current_stage != 1 ? 'disabled' : '' ?>>
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>

                                    <!-- ADD THIS NEW SECTION: Project Images Button (Only show when all materials are obtained) -->
                                    <?php if ($has_materials && $all_materials_obtained && !in_array(1, $completed_stages) && $current_stage == 1): ?>
                                    <div class="project-images-prompt" style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border: 1px dashed #ddd;">
                                        <h4 class="content-title" style="margin-top: 0;">
                                            <i class="fas fa-camera"></i> Ready for Project Images
                                        </h4>
                                        <p style="margin-bottom: 15px; color: #666;">
                                            All materials have been obtained! Now you can upload project images to document your work.
                                            Upload at least one project image before proceeding to the next stage.
                                        </p>
                                        <button type="button" class="btn primary" onclick="openProjectImageUploadModal()">
                                            <i class="fas fa-camera"></i> Upload Project Images
                                        </button>
                                        
                                        <!-- Display existing project images if any -->
                                        <?php if ($has_project_images): ?>
                                        <div style="margin-top: 15px;">
                                            <p><strong>Already uploaded images:</strong></p>
                                            <div id="projectImagesContainer" class="images-container" style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                                                <?php
                                                try {
                                                    $images_query = $conn->prepare("SELECT * FROM project_images WHERE project_id = ? ORDER BY uploaded_at DESC");
                                                    $images_query->bind_param("i", $project_id);
                                                    $images_query->execute();
                                                    $project_images = $images_query->get_result()->fetch_all(MYSQLI_ASSOC);
                                                    
                                                    foreach ($project_images as $image) {
                                                        echo '<div class="image-preview" style="width: 100px; height: 100px; position: relative;">';
                                                        echo '<img src="' . htmlspecialchars($image['image_path']) . '" alt="Project Image" style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;">';
                                                        echo '<button class="remove-image-btn" data-image-id="' . $image['image_id'] . '" onclick="removeProjectImage(' . $image['image_id'] . ')" style="position: absolute; top: -5px; right: -5px; background: red; color: white; border: none; border-radius: 50%; width: 20px; height: 20px; cursor: pointer;">&times;</button>';
                                                        echo '</div>';
                                                    }
                                                } catch (Exception $e) {
                                                    echo '<div class="empty-state small">Error loading images</div>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <div class="stage-actions">
                                        <button class="btn primary" onclick="openAddMaterialModal()" 
                                                <?= $current_stage != 1 ? 'disabled' : '' ?>>
                                            <i class="fas fa-plus"></i> Add Material
                                        </button>
                                        <?php if (!in_array(1, $completed_stages) && $current_stage == 1): ?>
                                            <?php
                                            // Check if all requirements are met for preparation phase
                                            // Only require project images IF all materials are obtained
                                            $preparation_ready = $has_materials && $all_materials_obtained && $has_project_images;
                                            ?>
                                            <button class="btn secondary proceed-btn <?= $preparation_ready ? 'enabled' : '' ?>" 
                                                    onclick="<?= $preparation_ready ? 'completeStage(1)' : '' ?>" 
                                                    <?= !$preparation_ready ? 'disabled' : '' ?>>
                                                Proceed
                                            </button>
                                            <div class="proceed-requirements">
                                                <div class="<?= $has_materials ? 'requirement-met' : 'requirement-not-met' ?>">
                                                    <i class="fas <?= $has_materials ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                                    At least one material added
                                                </div>
                                                <div class="<?= $all_materials_obtained ? 'requirement-met' : 'requirement-not-met' ?>">
                                                    <i class="fas <?= $all_materials_obtained ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                                    All materials obtained
                                                </div>
                                                <div class="<?= $has_project_images ? 'requirement-met' : 'requirement-not-met' ?>">
                                                    <i class="fas <?= $has_project_images ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                                    At least one project image uploaded
                                                    <?php if (!$has_project_images && $all_materials_obtained): ?>
                                                        <div style="margin-top: 5px; font-size: 12px; color: #666;">
                                                            Click "Upload Project Images" button above
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Construction -->
                        <div class="tab-content <?= $current_stage == 2 ? 'active' : '' ?>" id="construction-tab">
                            <div class="stage-card <?= in_array(2, $completed_stages) ? 'completed' : '' ?> <?= $current_stage == 2 ? 'current' : '' ?>">
                                <?php if (in_array(2, $completed_stages)): ?>
                                    <span class="stage-check"><i class="fas fa-check"></i></span>
                                <?php endif; ?>
                                
                                <div class="stage-header">
                                    <div class="stage-marker <?= !in_array(1, $completed_stages) ? 'locked' : '' ?>">
                                        <span class="stage-number">2</span>
                                    </div>
                                    <div class="stage-title-container">
                                        <h3 class="stage-title">Construction</h3>
                                        <p class="stage-subtitle">Build your project, follow safety guidelines, document progress</p>
                                    </div>
                                    <div class="stage-status">
                                        <?php if (in_array(2, $completed_stages)): ?>
                                            <span class="status-badge completed">COMPLETED</span>
                                        <?php elseif ($current_stage == 2): ?>
                                            <span class="status-badge current">CURRENT</span>
                                        <?php else: ?>
                                            <span class="status-badge locked">LOCKED</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="stage-content">
                                    <h4 class="content-title">Steps</h4>
                                    <!-- Steps display in Construction phase - SIMPLIFIED -->
                                    <?php if (empty($steps)): ?>
                                        <div class="empty-state">No steps added yet.</div>
                                    <?php else: ?>
                                        <ul class="steps-list">
                                            <?php foreach ($steps as $step): 
                                                // Check step completion status
                                                $has_step_image = false;
                                                $image_count = 0;
                                                
                                                // Check for step images
                                                if ($conn) {
                                                    try {
                                                        $step_images_check = $conn->prepare("SELECT COUNT(*) as image_count FROM project_step_images WHERE step_id = ?");
                                                        $step_images_check->bind_param("i", $step['step_id']);
                                                        $step_images_check->execute();
                                                        $step_images_result = $step_images_check->get_result()->fetch_assoc();
                                                        $image_count = $step_images_result['image_count'] ?? 0;
                                                        $has_step_image = $image_count > 0;
                                                    } catch (Exception $e) {
                                                        $has_step_image = false;
                                                    }
                                                }
                                                
                                                // Check for description (instructions column)
                                                $has_description = isset($step['instructions']) && !empty(trim($step['instructions']));
                                                $step_complete = $has_description && $has_step_image;
                                                
                                                // Determine what's missing
                                                $missing = [];
                                                if (!$has_description) {
                                                    $missing[] = 'description';
                                                }
                                                if (!$has_step_image) {
                                                    $missing[] = 'images';
                                                }
                                                
                                                // Determine step status class
                                                $step_status_class = $step_complete ? 'complete' : 'incomplete';
                                            ?>
                                                <li class="step-item <?= $step_status_class ?>">
                                                    <div class="step-info">
                                                        <div class="step-title">
                                                            <?= htmlspecialchars($step['title']) ?>
                                                            <?php if ($step_complete): ?>
                                                                <span class="step-status-badge complete">
                                                                    <i class="fas fa-check-circle"></i> Complete
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="step-status-badge incomplete">
                                                                    <i class="fas fa-exclamation-circle"></i>
                                                                    <?php if (count($missing) === 2): ?>
                                                                        Needs description and images
                                                                    <?php elseif (in_array('description', $missing)): ?>
                                                                        Needs description
                                                                    <?php else: ?>
                                                                        Needs images (<?= $image_count ?> uploaded)
                                                                    <?php endif; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <!-- ALWAYS SHOW DESCRIPTION IF IT EXISTS IN INSTRUCTIONS COLUMN -->
                                                        <?php if ($has_description): ?>
                                                            <div class="step-description always-visible">
                                                                <?= nl2br(htmlspecialchars($step['instructions'])) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <!-- Step Images Section -->
                                                        <div class="step-images-section" style="margin-top: 10px;">
                                                            <div class="step-images-container images-container">
                                                                <?php
                                                                if ($has_step_image && $conn) {
                                                                    try {
                                                                        $step_images_query = $conn->prepare("SELECT * FROM project_step_images WHERE step_id = ? ORDER BY uploaded_at DESC");
                                                                        $step_images_query->bind_param("i", $step['step_id']);
                                                                        $step_images_query->execute();
                                                                        $step_images = $step_images_query->get_result()->fetch_all(MYSQLI_ASSOC);
                                                                        
                                                                        foreach ($step_images as $image) {
                                                                            echo '<div class="image-preview">';
                                                                            echo '<img src="' . htmlspecialchars($image['image_path']) . '" alt="Step Image">';
                                                                            echo '<button class="remove-image-btn" data-image-id="' . $image['step_image_id'] . '" onclick="removeStepImage(' . $image['step_image_id'] . ', ' . $step['step_id'] . ')">&times;</button>';
                                                                            echo '</div>';
                                                                        }
                                                                    } catch (Exception $e) {
                                                                        echo '<div class="empty-state small">Error loading images</div>';
                                                                    }
                                                                } else {
                                                                    echo '<div class="empty-state small">No images for this step. Add at least one image.</div>';
                                                                }
                                                                ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if (in_array(1, $completed_stages)): ?>
                                                    <div class="step-actions">
                                                        <button class="btn small primary" onclick="openStepImageUploadModal(<?= $step['step_id'] ?>, '<?= htmlspecialchars(addslashes($step['title'])) ?>')">
                                                            <i class="fas fa-camera"></i> Add Images
                                                        </button>
                                                        <button class="btn small danger" onclick="removeStep(<?= $step['step_id'] ?>)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="stage-actions">
                                    <?php if (in_array(1, $completed_stages)): ?>
                                    <button class="btn primary" onclick="openAddStepModal()">
                                        <i class="fas fa-plus"></i> Add Step
                                    </button>
                                    <?php if (!in_array(2, $completed_stages)): ?>
                                        <?php
                                        // Check if all requirements are met for construction phase
                                        $construction_ready = $has_steps && $all_steps_complete;
                                        ?>
                                        <button class="btn secondary proceed-btn <?= $construction_ready ? 'enabled' : '' ?>" 
                                                onclick="<?= $construction_ready ? 'completeStage(2)' : '' ?>" 
                                                <?= !$construction_ready ? 'disabled' : '' ?>>
                                            Proceed
                                        </button>
                                        <div class="proceed-requirements">
                                            <div class="<?= $has_steps ? 'requirement-met' : 'requirement-not-met' ?>">
                                                <i class="fas <?= $has_steps ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                                At least one step added and description
                                            </div>
                                            <div class="<?= $all_steps_complete ? 'requirement-met' : 'requirement-not-met' ?>">
                                                <i class="fas <?= $all_steps_complete ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                                All steps have images
                                                <?php if (!$all_steps_complete && count($steps) > 0): ?>
                                                    <div class="incomplete-steps-list">
                                                        <?php 
                                                        foreach ($steps as $step) {
                                                            $has_description = isset($step['instructions']) && !empty(trim($step['instructions']));
                                                            
                                                            $has_step_image = false;
                                                            if ($conn) {
                                                                try {
                                                                    $step_images_check = $conn->prepare("SELECT COUNT(*) as image_count FROM project_step_images WHERE step_id = ?");
                                                                    $step_images_check->bind_param("i", $step['step_id']);
                                                                    $step_images_check->execute();
                                                                    $step_images_result = $step_images_check->get_result()->fetch_assoc();
                                                                    $has_step_image = ($step_images_result['image_count'] ?? 0) > 0;
                                                                } catch (Exception $e) {
                                                                    $has_step_image = false;
                                                                }
                                                            }
                                                            
                                                            if (!$has_description || !$has_step_image) {
                                                                echo '<div class="incomplete-step">';
                                                                echo '<strong>' . htmlspecialchars($step['title']) . ':</strong> ';
                                                                if (!$has_description && !$has_step_image) {
                                                                    echo 'Needs description and images';
                                                                } elseif (!$has_description) {
                                                                    echo 'Needs description';
                                                                } else {
                                                                    echo 'Needs images';
                                                                }
                                                                echo '</div>';
                                                            }
                                                        }
                                                        ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <div class="locked-message">
                                        <i class="fas fa-lock"></i> Complete the Preparation stage to unlock Construction
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Share -->
                        <div class="tab-content <?= $current_stage == 3 ? 'active' : '' ?>" id="share-tab">
                            <div class="stage-card <?= in_array(3, $completed_stages) ? 'completed' : '' ?> <?= $current_stage == 3 ? 'current' : '' ?>">
                                <?php if (in_array(3, $completed_stages)): ?>
                                    <span class="stage-check"><i class="fas fa-check"></i></span>
                                <?php endif; ?>
                                
                                <div class="stage-header">
                                    <div class="stage-marker <?= !in_array(2, $completed_stages) ? 'locked' : '' ?>">
                                        <span class="stage-number">3</span>
                                    </div>
                                    <div class="stage-title-container">
                                        <h3 class="stage-title">Share</h3>
                                        <p class="stage-subtitle">Share your project with the community</p>
                                    </div>
                                    <div class="stage-status">
                                        <?php if (in_array(3, $completed_stages)): ?>
                                            <span class="status-badge completed">COMPLETED</span>
                                        <?php elseif ($current_stage == 3): ?>
                                            <span class="status-badge current">CURRENT</span>
                                        <?php else: ?>
                                            <span class="status-badge locked">LOCKED</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="stage-content">
                                    <form id="shareForm" method="POST">
                                        <!-- Project Description Display -->
                                        <div class="form-group">
                                            <label>Project Description</label>
                                            <div class="share-description-display">
                                                <?= nl2br(htmlspecialchars($project['description'])) ?>
                                            </div>
                                        </div>
                                        
                                        <!-- Final Project Image Section -->
                                        <div class="form-group">
                                            <label>Final Project Image *</label>
                                            <div id="finalProjectImageContainer" class="final-image-container">
                                                <?php
                                                // Check if a final image has been uploaded
                                                $has_final_image = false;
                                                $final_image_path = '';
                                                $final_image_id = '';
                                                
                                                if ($conn) {
                                                    try {
                                                        $final_image_check = $conn->prepare("
                                                            SELECT image_path, image_id 
                                                            FROM project_images 
                                                            WHERE project_id = ? AND is_final_image = 1
                                                        ");
                                                        $final_image_check->bind_param("i", $project_id);
                                                        $final_image_check->execute();
                                                        $final_image_result = $final_image_check->get_result()->fetch_assoc();
                                                        
                                                        if ($final_image_result) {
                                                            $has_final_image = true;
                                                            $final_image_path = $final_image_result['image_path'];
                                                            $final_image_id = $final_image_result['image_id'];
                                                            
                                                            // Show uploaded final image
                                                            echo '<div class="final-image-preview">';
                                                            echo '<img src="' . htmlspecialchars($final_image_path) . '" alt="Final Project">';
                                                            echo '<button type="button" class="remove-final-image-btn" onclick="removeFinalProjectImage()">&times;</button>';
                                                            echo '</div>';
                                                        } else {
                                                            // No final image yet - show upload area
                                                            echo '<div class="final-image-upload-area" id="finalImageUploadArea">';
                                                            echo '<div class="upload-icon"><i class="fas fa-camera fa-2x"></i></div>';
                                                            echo '<div class="upload-text">Click to upload final project image</div>';
                                                            echo '<div class="upload-hint">* Required for sharing to community</div>';
                                                            echo '</div>';
                                                        }
                                                    } catch (Exception $e) {
                                                        // Show upload area on error
                                                        echo '<div class="final-image-upload-area" id="finalImageUploadArea">';
                                                        echo '<div class="upload-icon"><i class="fas fa-camera fa-2x"></i></div>';
                                                        echo '<div class="upload-text">Click to upload final project image</div>';
                                                        echo '<div class="upload-hint">* Required for sharing to community</div>';
                                                        echo '</div>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                            
                                            <div class="image-upload-actions" style="margin-top: 10px;">
                                                <button type="button" class="btn primary" onclick="openFinalImageUploadModal()">
                                                    <i class="fas fa-camera"></i> Upload Final Image
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="share-options">
                                            <div class="share-option <?= ($project['is_public'] ?? 0) == 0 ? 'selected' : '' ?> <?= !in_array(2, $completed_stages) ? 'locked' : '' ?>" 
                                                data-option="private">
                                                <i class="fas fa-lock"></i>
                                                <div class="share-option-title">Keep Private</div>
                                                <div class="share-option-description">Only you can view this project</div>
                                            </div>
                                            <div class="share-option <?= ($project['is_public'] ?? 0) == 1 ? 'selected' : '' ?> <?= !in_array(2, $completed_stages) ? 'locked' : '' ?>" 
                                                data-option="public">
                                                <i class="fas fa-share-alt"></i>
                                                <div class="share-option-title">Share to Community</div>
                                                <div class="share-option-description">Share with other EcoWaste users</div>
                                            </div>
                                        </div>
                                        
                                        <input type="hidden" name="is_public" id="is_public" value="<?= $project['is_public'] ?? 0 ?>">
                                        
                                        <!-- Materials Summary -->
                                        <div class="materials-summary">
                                            <div class="summary-title">Materials Used</div>
                                            <ul class="summary-list">
                                                <?php foreach ($materials as $material): ?>
                                                    <li class="summary-item">
                                                        <span><?= htmlspecialchars($material['material_name']) ?></span>
                                                        <span><?= htmlspecialchars($material['quantity']) ?> units</span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        
                                        <!-- Final Image ID for sharing -->
                                        <input type="hidden" name="final_image_id" id="final_image_id" value="<?= $final_image_id ?>">
                                    </form>
                                </div>
                                
                                <div class="stage-actions">
                                    <?php if (in_array(2, $completed_stages)): ?>
                                        <?php if (!in_array(3, $completed_stages)): ?>
                                            <!-- Only show buttons when stage is not completed -->
                                            <button type="button" class="btn secondary" onclick="submitShareForm('private')">
                                                Keep Private
                                            </button>
                                            <button type="button" class="btn primary" onclick="submitShareForm('public')">
                                                Share to Community
                                            </button>
                                        <?php else: ?>
                                            <!-- When stage is completed, show nothing in stage-actions -->
                                            <div style="height: 40px;"></div> <!-- Spacer to maintain layout -->
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="locked-message">
                                            <i class="fas fa-lock"></i> Complete the Construction stage to unlock Sharing
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                </section>
        </main>
    </div>

    
    <!-- Add Material Modal - STATIC HTML WITH CATEGORIZED DROPDOWN -->
    <div class="modal" id="addMaterialModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add Material</h3>
                <button class="close-modal" onclick="closeAddMaterialModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addMaterialForm">
                    <div class="form-group">
                        <label for="material_category">Select Waste Category *</label>
                        <select id="material_category" name="material_category" required class="category-select">
                            <option value="">-- Select a category --</option>
                            <option value="Plastic">♻️ Plastic</option>
                            <option value="Paper">📄 Paper</option>
                            <option value="Glass">🍶 Glass</option>
                            <option value="Metal">🔩 Metal</option>
                            <option value="Electronic">🔌 Electronic Waste</option>
                            <option value="Other">💡 Other Materials</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="materialSelectGroup">
                        <label for="material_name">Material Name *</label>
                        <select id="material_name" name="material_name" required class="material-select" disabled>
                            <option value="">-- First select a category --</option>
                        </select>
                        <div class="form-hint">Or enter custom material name:</div>
                        <input type="text" id="custom_material_name" name="custom_material_name" 
                            placeholder="Enter custom material name" style="margin-top: 5px; display: none;">
                    </div>
                    
                    <div class="form-group" id="otherWasteGroup" style="display: none;">
                        <label for="otherWaste">Specify Other Material *</label>
                        <input type="text" id="otherWaste" name="otherWaste" 
                            placeholder="Enter the specific material name">
                    </div>
                    
                    <div class="form-group">
                        <label for="quantity">Quantity *</label>
                        <input type="number" id="quantity" name="quantity" min="1" value="1" required 
                            placeholder="Enter quantity">
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="action-btn" onclick="closeAddMaterialModal()">Cancel</button>
                        <button type="submit" name="add_material" class="action-btn check-btn">
                            <i class="fas fa-plus"></i> Add Material
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

        <!-- Checklist Modal -->
    <div class="modal checklist-modal" id="checklistModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">How much do you have?</h3>
                <button class="close-modal" onclick="closeChecklistModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="checklist-quantity-info">
                    <p>Material: <strong id="checklistMaterialName"></strong></p>
                    <p class="total-needed">Total needed: <span id="checklistTotalNeeded">0</span> units</p>
                    <p class="already-have">Already have: <span id="checklistAlreadyHave">0</span> units</p>
                    <p class="remaining">Remaining: <span id="checklistRemaining">0</span> units</p>
                </div>
                
                <div class="checklist-input-group">
                    <label for="acquiredQuantity">Enter the amount of this material you already have:</label>
                    <input type="number" 
                           id="acquiredQuantity" 
                           class="checklist-input"
                           min="0" 
                           value="0"
                           placeholder="Enter quantity">
                    <div class="checklist-input-hint">Maximum: <span id="maxQuantityHint">0</span> units</div>
                    <div class="quantity-validation-error" id="quantityError">
                        Quantity cannot exceed total needed amount.
                    </div>
                </div>
                
                <div class="checklist-modal-actions">
                    <button type="button" class="action-btn cancel-btn" onclick="closeChecklistModal()">Cancel</button>
                    <button type="button" class="action-btn have-all-btn" id="haveAllBtn">Have All</button>
                    <button type="button" class="action-btn mark-obtained-btn" id="markObtainedBtn">Mark Obtained</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Project Image Upload Modal -->
<div class="modal" id="projectImageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Upload Project Images</h3>
            <button class="close-modal" onclick="closeProjectImageModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="projectImageForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="projectImages">Select images to upload</label>
                    <input type="file" id="projectImages" name="project_images[]" multiple accept="image/*" required>
                    <small class="form-hint">You can select multiple images. At least one image is required to proceed.</small>
                </div>
                <div id="projectImagesPreview" class="images-preview"></div>
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <div class="modal-actions">
                    <button type="button" class="action-btn" onclick="closeProjectImageModal()">Cancel</button>
                    <button type="button" class="action-btn check-btn" onclick="uploadProjectImages()">
                        <i class="fas fa-upload"></i> Upload Images
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Step Image Upload Modal -->
<div class="modal" id="stepImageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Add Images to Step: <span id="stepImageModalTitle"></span></h3>
            <button class="close-modal" onclick="closeStepImageModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="stepImageForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="stepImages">Select images for this step</label>
                    <input type="file" id="stepImages" name="step_images[]" multiple accept="image/*" required>
                    <small class="form-hint">You can select multiple images. At least one image is required per step.</small>
                </div>
                <div id="stepImagesPreview" class="images-preview"></div>
                <input type="hidden" id="currentStepId" name="step_id" value="">
                <div class="modal-actions">
                    <button type="button" class="action-btn" onclick="closeStepImageModal()">Cancel</button>
                    <button type="button" class="action-btn check-btn" onclick="uploadStepImages()">
                        <i class="fas fa-upload"></i> Upload Images
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Add Step Modal -->
    <div class="modal" id="addStepModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add Step</h3>
                <button class="close-modal" onclick="closeAddStepModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addStepForm">
                    <div class="form-group">
                        <label for="step_title">Step Title *</label>
                        <input type="text" id="step_title" name="step_title" required 
                            placeholder="Enter step title...">
                    </div>

                    <div class="form-group">
                        <label for="step_description">Description *</label>
                        <textarea id="step_description" name="step_description" required 
                                placeholder="Describe what needs to be done in this step..."></textarea>
                        <small class="form-hint">Detailed description is required for step completion</small>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="action-btn" onclick="closeAddStepModal()">Cancel</button>
                        <button type="submit" name="add_step" class="action-btn check-btn">Add Step</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Final Project Image Upload Modal -->
<div class="modal" id="finalImageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Upload Final Project Image</h3>
            <button class="close-modal" onclick="closeFinalImageModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="finalImageForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="finalProjectImage">Select final project image</label>
                    <input type="file" id="finalProjectImage" name="final_project_image" accept="image/*" required>
                    <small class="form-hint">This will be the main image displayed when sharing your project.</small>
                </div>
                <div id="finalImagePreview" class="final-image-preview-large"></div>
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <input type="hidden" name="is_final_image" value="1">
                <div class="modal-actions">
                    <button type="button" class="action-btn" onclick="closeFinalImageModal()">Cancel</button>
                    <button type="button" class="action-btn check-btn" onclick="uploadFinalProjectImage()">
                        <i class="fas fa-upload"></i> Upload Final Image
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

        <!-- Custom Confirmation Modal -->
    <div class="confirmation-modal" id="confirmationModal">
        <div class="confirmation-content">
            <div class="confirmation-header">
                <h3 class="confirmation-title"><i class="fas fa-exclamation-triangle"></i> Confirm Action</h3>
            </div>
            <div class="confirmation-body">
                <p class="confirmation-message" id="confirmationMessage">Are you sure you want to perform this action?</p>
            </div>
            <div class="confirmation-actions">
                <button type="button" class="confirmation-btn cancel" id="confirmCancel">Cancel</button>
                <button type="button" class="confirmation-btn confirm" id="confirmAction">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Include Settings Modal -->
    <?php include 'includes/settings_modal.php'; ?>

    <!-- Feedback Button -->
    <div class="feedback-btn" id="feedbackBtn">💬</div>

    <!-- Feedback Modal -->
    <div class="feedback-modal" id="feedbackModal">
        <div class="feedback-content">
            <span class="feedback-close-btn" id="feedbackCloseBtn">&times;</span>
            <div class="feedback-form" id="feedbackForm">
                <h3>Share Your Feedback</h3>
                
                <div class="emoji-rating" id="emojiRating">
                    <div class="emoji-option" data-rating="1">
                        <span class="emoji">😞</span>
                        <span class="emoji-label">Very Sad</span>
                    </div>
                    <div class="emoji-option" data-rating="2">
                        <span class="emoji">😕</span>
                        <span class="emoji-label">Sad</span>
                    </div>
                    <div class="emoji-option" data-rating="3">
                        <span class="emoji">😐</span>
                        <span class="emoji-label">Neutral</span>
                    </div>
                    <div class="emoji-option" data-rating="4">
                        <span class="emoji">🙂</span>
                        <span class="emoji-label">Happy</span>
                    </div>
                    <div class="emoji-option" data-rating="5">
                        <span class="emoji">😍</span>
                        <span class="emoji-label">Very Happy</span>
                    </div>
                </div>
                <div class="error-message" id="ratingError">Please select a rating</div>
                
                <p class="feedback-detail">Please share in detail what we can improve more?</p>
                
                <textarea id="feedbackText" placeholder="Your feedback helps us make EcoWaste better..."></textarea>
                <div class="error-message" id="textError">Please provide your feedback</div>
                
                <button type="submit" class="feedback-submit-btn" id="feedbackSubmitBtn">
                    Submit Feedback
                    <div class="spinner" id="spinner"></div>
                </button>
            </div>
            
            <div class="thank-you-message" id="thankYouMessage">
                <span class="thank-you-emoji">🎉</span>
                <h3>Thank You!</h3>
                <p>We appreciate your feedback and will use it to improve EcoWaste.</p>
                <p>Your opinion matters to us!</p>
            </div>
        </div>
    </div>

    <script>

        // Debug function to check current step ID
        function debugStepId() {
            console.log('Current step ID from variable:', currentStepIdForUpload);
            console.log('Current step ID from input field:', document.getElementById('currentStepId').value);
            console.log('Step modal active:', document.getElementById('stepImageModal').classList.contains('active'));
        }

        // Custom Confirmation System
        let currentConfirmationCallback = null;
        let currentConfirmationType = '';
        let currentMaterialId = null;
        let currentStepId = null;
        
        function showConfirmation(message, type = 'remove_material', callback, materialId = null, stepId = null) {
            const modal = document.getElementById('confirmationModal');
            const messageEl = document.getElementById('confirmationMessage');
            const confirmBtn = document.getElementById('confirmAction');
            const cancelBtn = document.getElementById('confirmCancel');
            
            // Set modal content based on type
            messageEl.textContent = message;
            currentConfirmationCallback = callback;
            currentConfirmationType = type;
            currentMaterialId = materialId;
            currentStepId = stepId;
            
            // Customize button text and color based on action type
            if (type === 'remove_material') {
                confirmBtn.textContent = 'Remove Material';
                confirmBtn.className = 'confirmation-btn confirm';
            } else if (type === 'remove_step') {
                confirmBtn.textContent = 'Remove Step';
                confirmBtn.className = 'confirmation-btn confirm';
            } else if (type === 'complete_stage') {
                confirmBtn.textContent = 'Proceed';
                confirmBtn.className = 'confirmation-btn primary';
            } else if (type === 'share_project') {
                confirmBtn.textContent = 'Share Project';
                confirmBtn.className = 'confirmation-btn primary';
            } else {
                confirmBtn.textContent = 'Confirm';
                confirmBtn.className = 'confirmation-btn confirm';
            }
            
            // Show modal
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Handle confirm button
            confirmBtn.onclick = function() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
                if (currentConfirmationCallback) {
                    currentConfirmationCallback();
                }
            };
            
            // Handle cancel button
            cancelBtn.onclick = function() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
                currentConfirmationCallback = null;
            };
            
            // Close when clicking outside
            modal.onclick = function(e) {
                if (e.target === modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                    currentConfirmationCallback = null;
                }
            };
            
            // Close with Escape key
            document.addEventListener('keydown', function closeOnEscape(e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                    currentConfirmationCallback = null;
                    document.removeEventListener('keydown', closeOnEscape);
                }
            });
        }

         // Toast notification function
        function showToast(message, type = 'success') {
            // Remove any existing toast
            const existingToast = document.querySelector('.toast-notification');
            if (existingToast) {
                existingToast.remove();
            }
            
            // Create new toast
            const toast = document.createElement('div');
            toast.className = `toast-notification ${type === 'error' ? 'error' : ''}`;
            toast.innerHTML = `
                <span class="toast-icon">${type === 'error' ? '❌' : '✅'}</span>
                <span class="toast-message">${message}</span>
            `;
            
            document.body.appendChild(toast);
            
            // Trigger animation
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                    }
                }, 300);
            }, 3000);
        }
        

        // User Profile Dropdown
        document.getElementById('userProfile').addEventListener('click', function() {
            this.classList.toggle('active');
        });
        
        document.addEventListener('click', function(event) {
            const userProfile = document.getElementById('userProfile');
            if (!userProfile.contains(event.target)) {
                userProfile.classList.remove('active');
            }
        });
        
        // Settings Modal Handler
        document.getElementById('settingsLink')?.addEventListener('click', function(e) {
            e.preventDefault();
            // This would open your settings modal
            console.log('Open settings modal');
        });
        
        // "See more" functionality (from original)
        document.addEventListener('DOMContentLoaded', function() {
            const desc = document.querySelector('.project-description');
            const toggle = document.querySelector('.see-more-btn');
            
            if (!desc || !toggle) return;
            
            // Default to collapsed unless explicitly expanded
            if (!desc.classList.contains('collapsed') && !desc.classList.contains('expanded')) {
                desc.classList.add('collapsed');
            }
            
            // Detect whether content overflows more than 5 lines
            function contentOverflowsFiveLines(el) {
                try {
                    const clone = el.cloneNode(true);
                    clone.style.visibility = 'hidden';
                    clone.style.position = 'absolute';
                    clone.style.maxHeight = 'none';
                    clone.style.display = 'block';
                    clone.style.whiteSpace = 'normal';
                    clone.style.wordBreak = 'break-word';
                    
                    clone.classList.remove('collapsed');
                    clone.classList.remove('expanded');
                    clone.classList.remove('has-overflow');
                    
                    const elStyle = getComputedStyle(el);
                    const widthPx = el.getBoundingClientRect().width || parseFloat(elStyle.width) || 360;
                    clone.style.width = widthPx + 'px';
                    clone.style.lineHeight = elStyle.lineHeight || '1.8';
                    
                    document.body.appendChild(clone);
                    const fullHeight = clone.getBoundingClientRect().height;
                    document.body.removeChild(clone);
                    
                    const lineHeight = parseFloat(elStyle.lineHeight) || 18;
                    const maxAllowed = lineHeight * 5 + 1;
                    return fullHeight > maxAllowed;
                } catch (e) {
                    return true;
                }
            }
            
            let overflow = contentOverflowsFiveLines(desc);
            
            // Fallback: if measurement didn't detect overflow but the text is very long
            try {
                if (!overflow) {
                    const txt = (desc.textContent || '').trim();
                    if (txt.length > 200) overflow = true;
                }
            } catch(e) {}
            
            // Additional runtime check
            try {
                if (!overflow) {
                    const scrollH = desc.scrollHeight || 0;
                    const clientH = desc.clientHeight || 0;
                    if (scrollH > clientH + 2) overflow = true;
                }
            } catch(e) {}
            
            if (overflow) {
                toggle.style.display = 'inline-block';
                desc.classList.add('has-overflow');
            } else {
                toggle.style.display = 'none';
                desc.classList.remove('has-overflow');
            }
            
            // Initialize button state
            toggle.textContent = desc.classList.contains('collapsed') ? 'See more' : 'See less';
            toggle.setAttribute('aria-expanded', desc.classList.contains('expanded') ? 'true' : 'false');
            
            toggle.addEventListener('click', function(e){
                e.preventDefault();
                const isCollapsed = desc.classList.contains('collapsed');
                if (isCollapsed) {
                    desc.classList.remove('collapsed');
                    desc.classList.add('expanded');
                    toggle.textContent = 'See less';
                    toggle.setAttribute('aria-expanded', 'true');
                } else {
                    desc.classList.remove('expanded');
                    desc.classList.add('collapsed');
                    toggle.textContent = 'See more';
                    toggle.setAttribute('aria-expanded', 'false');
                    // Scroll to keep element visible
                    const header = document.querySelector('header');
                    const headerHeight = header ? header.getBoundingClientRect().height : 0;
                    const rect = desc.getBoundingClientRect();
                    const elemTopDoc = window.pageYOffset + rect.top;
                    const target = Math.max(0, Math.floor(elemTopDoc - headerHeight - 20));
                    window.scrollTo({ top: target, behavior: 'smooth' });
                }
            });
        });
        
        // Create Edit Project Modal dynamically (like original)
        function createEditProjectModal() {
            if (document.getElementById('editProjectModal')) {
                return document.getElementById('editProjectModal');
            }
            
            const projectName = <?= json_encode($project['project_name']) ?>;
            const projectDesc = <?= json_encode($project['description']) ?>;
            
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.id = 'editProjectModal';
            modal.dataset.persistent = '0';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Edit Project</h3>
                        <button type="button" class="close-modal" data-action="close-edit">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <div class="form-group">
                                <label for="project_name">Project Name</label>
                                <input type="text" id="project_name" name="project_name" value="${projectName}" required>
                            </div>
                            <div class="form-group">
                                <label for="project_description">Description</label>
                                <textarea id="project_description" name="project_description" required>${projectDesc}</textarea>
                            </div>
                            <div class="modal-actions">
                                <button type="button" class="action-btn" data-action="close-edit">Cancel</button>
                                <button type="submit" name="update_project" class="action-btn check-btn">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Wire up close buttons
            modal.querySelectorAll('[data-action="close-edit"]').forEach(btn => {
                btn.addEventListener('click', function(ev) {
                    ev.preventDefault();
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                });
            });
            
            // Close when clicking overlay
            modal.addEventListener('click', function(e){
                if (e.target === modal && modal.dataset.persistent !== '1') {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
            
            return modal;
        }
        
        // Edit Project button handler
        document.addEventListener('click', function(e) {
            if (e.target.closest('[data-action="edit-project"]') || e.target.closest('.edit-project')) {
                e.preventDefault();
                const modal = createEditProjectModal();
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        });
        
        // Modal Functions
        function openAddMaterialModal() {
            // Preparation is always available
            document.getElementById('addMaterialModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            initializeMaterialDropdown();
        }

        // Modal Functions
        function openAddStepModal() {
            // Check if preparation stage is completed
            if (!<?= in_array(1, $completed_stages) ? 'true' : 'false' ?>) {
                alert('Please complete the preparation stage first by adding materials and clicking "Proceed".');
                return;
            }
            document.getElementById('addStepModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeAddMaterialModal() {
            document.getElementById('addMaterialModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function closeAddStepModal() {
            document.getElementById('addStepModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // Close modals when clicking outside or escape key
        document.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal.active');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                });
            }
        });
        
        // Stage Completion
        function completeStage(stageNumber) {
            let stageName = '';
            switch(stageNumber) {
                case 1: stageName = 'Preparation'; break;
                case 2: stageName = 'Construction'; break;
                case 3: stageName = 'Share'; break;
            }
            
            showConfirmation(
                `Are you ready to proceed to the ${stageName} stage? You won't be able to go back to previous stages.`,
                'complete_stage',
                function() {
                    if (stageNumber === 2 && !<?= in_array(1, $completed_stages) ? 'true' : 'false' ?>) {
                        showToast('Please complete the preparation stage first.', 'error');
                        return;
                    }
                    
                    if (stageNumber === 3 && !<?= in_array(2, $completed_stages) ? 'true' : 'false' ?>) {
                        showToast('Please complete the construction stage first.', 'error');
                        return;
                    }
                    
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'complete_stage';
                    input.value = '1';
                    form.appendChild(input);
                    
                    const stageInput = document.createElement('input');
                    stageInput.type = 'hidden';
                    stageInput.name = 'stage_number';
                    stageInput.value = stageNumber;
                    form.appendChild(stageInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            );
        }
        
        // Material Management
        function removeMaterial(materialId) {
            showConfirmation(
                'Are you sure you want to remove this material? This action cannot be undone.',
                'remove_material',
                function() {
                    // Create form data
                    const formData = new FormData();
                    formData.append('remove_material', '1');
                    formData.append('material_id', materialId);
                    
                    // Send AJAX request
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            // Show success toast
                            showToast('Material removed successfully!');
                            
                            // Remove the material from DOM
                            removeMaterialFromDOM(materialId);
                        } else {
                            showToast(data.message || 'Error removing material', 'error');
                        }
                    })
                    .catch(error => {
                        showToast('Failed to remove material. Please try again.', 'error');
                        console.error('Error:', error);
                    });
                },
                materialId
            );
        }

        // Function to remove material from DOM
        function removeMaterialFromDOM(materialId) {
            // Find the material item
            const materialItem = document.querySelector(`.material-item .btn[onclick*="removeMaterial(${materialId})"]`)?.closest('.material-item');
            
            if (materialItem) {
                // Remove the item
                materialItem.remove();
                
                // Check if list is now empty
                const materialsList = document.querySelector('.materials-list');
                if (materialsList && materialsList.children.length === 0) {
                    // Add empty state message
                    const stageContent = document.querySelector('.stage-content');
                    if (stageContent) {
                        const emptyState = document.createElement('div');
                        emptyState.className = 'empty-state';
                        emptyState.textContent = 'No materials listed yet.';
                        
                        // Insert after the content title
                        const contentTitle = stageContent.querySelector('.content-title');
                        if (contentTitle) {
                            contentTitle.parentNode.insertBefore(emptyState, contentTitle.nextSibling);
                        }
                        
                        // Remove the empty list
                        materialsList.remove();
                    }
                }
                
                // Update the "Proceed" button state
                updateProceedButton();
            }
        }
        
        // Step Management
        function removeStep(stepId) {
            showConfirmation(
                'Are you sure you want to remove this step? This action cannot be undone.',
                'remove_step',
                function() {
                    // Create form data
                    const formData = new FormData();
                    formData.append('remove_step', '1');
                    formData.append('step_id', stepId);
                    
                    // Send AJAX request
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            // Show success toast
                            showToast('Step removed successfully!');
                            
                            // Remove the step from DOM
                            removeStepFromDOM(stepId);
                        } else {
                            showToast(data.message || 'Error removing step', 'error');
                        }
                    })
                    .catch(error => {
                        showToast('Failed to remove step. Please try again.', 'error');
                        console.error('Error:', error);
                    });
                },
                null,
                stepId
            );
        }

        // Function to remove step from DOM
        function removeStepFromDOM(stepId) {
            // Find the step item
            const stepItem = document.querySelector(`.step-item .btn[onclick*="removeStep(${stepId})"]`)?.closest('.step-item');
            
            if (stepItem) {
                // Remove the item
                stepItem.remove();
                
                // Check if list is now empty
                const stepsList = document.querySelector('.steps-list');
                if (stepsList && stepsList.children.length === 0) {
                    // Add empty state message
                    const stageContent = document.querySelector('.stage-content');
                    if (stageContent) {
                        const emptyState = document.createElement('div');
                        emptyState.className = 'empty-state';
                        emptyState.textContent = 'No steps added yet.';
                        
                        // Insert after the content title
                        const contentTitle = stageContent.querySelector('.content-title');
                        if (contentTitle) {
                            contentTitle.parentNode.insertBefore(emptyState, contentTitle.nextSibling);
                        }
                        
                        // Remove the empty list
                        stepsList.remove();
                    }
                }
            }
        }
        
        // Share Options
        function selectShareOption(option) {
            const shareOptions = document.querySelectorAll('.share-option');
            shareOptions.forEach(opt => opt.classList.remove('selected'));
            
            if (option === 'private') {
                shareOptions[0].classList.add('selected');
                document.getElementById('is_public').value = '0';
            } else {
                shareOptions[1].classList.add('selected');
                document.getElementById('is_public').value = '1';
            }
        }

        function submitShareForm(option) {
            // Check if construction stage is completed
            if (!<?= in_array(2, $completed_stages) ? 'true' : 'false' ?>) {
                showToast('Please complete the construction stage first.', 'error');
                return;
            }
            
            // Check if final image is uploaded for public sharing
            if (option === 'public' && !document.getElementById('final_image_id').value) {
                showToast('Please upload a final project image before sharing.', 'error');
                openFinalImageUploadModal();
                return;
            }
            
            selectShareOption(option);
            
            const message = option === 'public' 
                ? 'Are you sure you want to share this project with the community?' 
                : 'Are you sure you want to keep this project private?';
            
            showConfirmation(
                message,
                'share_project',
                function() {
                    const form = document.getElementById('shareForm');
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'share_project';
                    actionInput.value = '1';
                    form.appendChild(actionInput);
                    
                    form.submit();
                }
            );
        }
        
        // Feedback Modal functionality
        document.addEventListener("DOMContentLoaded", function () {
            const feedbackBtn = document.getElementById("feedbackBtn");
            const feedbackModal = document.getElementById("feedbackModal");
            const feedbackCloseBtn = document.getElementById("feedbackCloseBtn");
            const emojiOptions = feedbackModal ? feedbackModal.querySelectorAll(".emoji-option") : [];
            const feedbackSubmitBtn = document.getElementById("feedbackSubmitBtn");
            const feedbackText = document.getElementById("feedbackText");
            const ratingError = document.getElementById("ratingError");
            const textError = document.getElementById("textError");
            const thankYouMessage = document.getElementById("thankYouMessage");
            const feedbackForm = document.getElementById("feedbackForm");
            const spinner = document.getElementById("spinner");
            
            if (!feedbackBtn || !feedbackModal || !feedbackSubmitBtn || !feedbackText) return;
            
            let selectedRating = 0;
            
            // Open modal
            feedbackBtn.addEventListener("click", () => {
                feedbackModal.style.display = "flex";
                feedbackForm.style.display = "block";
                thankYouMessage.style.display = "none";
            });
            
            // Close modal
            feedbackCloseBtn?.addEventListener("click", () => feedbackModal.style.display = "none");
            window.addEventListener("click", e => {
                if (e.target === feedbackModal) feedbackModal.style.display = "none";
            });
            
            // Emoji rating selection
            emojiOptions.forEach(option => {
                option.addEventListener("click", () => {
                    emojiOptions.forEach(o => o.classList.remove("selected"));
                    option.classList.add("selected");
                    selectedRating = option.getAttribute("data-rating");
                    ratingError.style.display = "none";
                });
            });
            
            // Submit feedback
            feedbackSubmitBtn.addEventListener("click", e => {
                e.preventDefault();
                
                let valid = true;
                if (selectedRating === 0) { ratingError.style.display = "block"; valid = false; }
                if (feedbackText.value.trim() === "") { textError.style.display = "block"; valid = false; }
                else { textError.style.display = "none"; }
                
                if (!valid) return;
                
                spinner.style.display = "inline-block";
                feedbackSubmitBtn.disabled = true;
                
                // AJAX POST
                fetch("feedback_process.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: `rating=${selectedRating}&feedback=${encodeURIComponent(feedbackText.value)}`
                })
                .then(res => res.json())
                .then(data => {
                    spinner.style.display = "none";
                    feedbackSubmitBtn.disabled = false;
                    
                    if (data.status === "success") {
                        feedbackForm.style.display = "none";
                        thankYouMessage.style.display = "block";
                        
                        // Reset after 3 seconds
                        setTimeout(() => {
                            feedbackModal.style.display = "none";
                            feedbackForm.style.display = "block";
                            thankYouMessage.style.display = "none";
                            feedbackText.value = "";
                            selectedRating = 0;
                            emojiOptions.forEach(o => o.classList.remove("selected"));
                        }, 3000);
                    } else {
                        alert(data.message || "Failed to submit feedback.");
                    }
                })
                .catch(err => {
                    spinner.style.display = "none";
                    feedbackSubmitBtn.disabled = false;
                    alert("Failed to submit feedback. Please try again.");
                    console.error(err);
                });
            });
        });

        // Tab Navigation functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Remove active class from all buttons and contents
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Add active class to clicked button
                    this.classList.add('active');
                    
                    // Show corresponding tab content
                    document.getElementById(`${tabId}-tab`).classList.add('active');
                    
                    // Update URL hash for bookmarking
                    window.location.hash = tabId;
                });
            });
            
            // Handle initial tab based on hash or current stage
            const hash = window.location.hash.substring(1);
            const validTabs = ['preparation', 'construction', 'share'];
            
            if (hash && validTabs.includes(hash)) {
                const tabButton = document.querySelector(`.tab-button[data-tab="${hash}"]`);
                if (tabButton) {
                    tabButton.click();
                }
            } else {
                // Set initial active tab based on current stage
                const currentTab = document.querySelector(`.tab-button[data-tab="${getTabForStage(<?= $current_stage ?>)}"]`);
                if (currentTab) {
                    currentTab.click();
                }
            }
            
            function getTabForStage(stage) {
                switch(stage) {
                    case 1: return 'preparation';
                    case 2: return 'construction';
                    case 3: return 'share';
                    default: return 'preparation';
                }
            }
        });

        // Share option click handlers
        document.addEventListener('DOMContentLoaded', function() {
            const shareOptions = document.querySelectorAll('.share-option:not(.locked)');
            
            shareOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const optionType = this.getAttribute('data-option');
                    selectShareOption(optionType);
                });
            });
        });

        // Initialize Material Dropdown Functionality
        function initializeMaterialDropdown() {
            const categorySelect = document.getElementById('material_category');
            const materialSelect = document.getElementById('material_name');
            const customInput = document.getElementById('custom_material_name');
            const otherWasteGroup = document.getElementById("otherWasteGroup");
            const otherWasteInput = document.getElementById("otherWaste");
            const materialSelectGroup = document.getElementById("materialSelectGroup");
            
            if (!categorySelect) return;
            
            // Define waste categories structure
            const wasteCategories = {
                'Plastic': [
                    'Plastic Bottles',
                    'Plastic Containers', 
                    'Plastic Bags',
                    'Wrappers',
                    'Other Plastic'
                ],
                'Paper': [
                    'Newspapers',
                    'Cardboard',
                    'Magazines',
                    'Office Paper',
                    'Other Paper'
                ],
                'Glass': [
                    'Glass Bottles',
                    'Glass Jars',
                    'Broken Glassware',
                    'Other Glass'
                ],
                'Metal': [
                    'Aluminum Cans',
                    'Tin Cans',
                    'Scrap Metal',
                    'Other Metal'
                ],
                'Electronic': [
                    'Old Phones',
                    'Chargers',
                    'Batteries',
                    'Broken Gadgets',
                    'Other Electronic'
                ]
            };
            
            // Reset on modal open
            categorySelect.selectedIndex = 0;
            materialSelect.innerHTML = '<option value="">-- First select a category --</option>';
            materialSelect.disabled = true;
            if (customInput) customInput.style.display = 'none';
            if (otherWasteGroup) otherWasteGroup.style.display = 'none';
            if (materialSelectGroup) materialSelectGroup.style.display = 'block';
            
            // Category change handler
            categorySelect.addEventListener('change', function() {
                const selectedCategory = this.value;
                
                // Reset states
                materialSelect.innerHTML = '<option value="">-- Select a material --</option>';
                materialSelect.disabled = true;
                
                if (customInput) {
                    customInput.style.display = 'none';
                    customInput.value = '';
                }
                
                // Handle Other category
                if (selectedCategory === 'Other') {
                    if (otherWasteGroup) {
                        otherWasteGroup.style.display = 'block';
                        if (otherWasteInput) {
                            otherWasteInput.required = true;
                            setTimeout(() => otherWasteInput.focus(), 100);
                        }
                    }
                    if (materialSelectGroup) materialSelectGroup.style.display = 'none';
                    return;
                } else {
                    if (otherWasteGroup) otherWasteGroup.style.display = 'none';
                    if (materialSelectGroup) materialSelectGroup.style.display = 'block';
                }
                
                // Populate material dropdown for regular categories
                if (selectedCategory && wasteCategories[selectedCategory]) {
                    materialSelect.disabled = false;
                    
                    wasteCategories[selectedCategory].forEach(material => {
                        const option = document.createElement('option');
                        option.value = material;
                        option.textContent = material;
                        materialSelect.appendChild(option);
                    });
                    
                    setTimeout(() => materialSelect.focus(), 100);
                }
            });
            
            // Material select change handler
            materialSelect.addEventListener('change', function() {
                const selectedValue = this.value;
                
                // Show custom input for "Other [Category]" options
                if (selectedValue && selectedValue.startsWith('Other ') && customInput) {
                    customInput.style.display = 'block';
                    customInput.required = true;
                    customInput.placeholder = `Specify ${selectedValue.toLowerCase()}`;
                    setTimeout(() => customInput.focus(), 100);
                } else if (customInput) {
                    customInput.style.display = 'none';
                    customInput.required = false;
                    customInput.value = '';
                }
            });
            
            // Form submission handler
            const addMaterialForm = document.getElementById('addMaterialForm');
            if (addMaterialForm) {
                addMaterialForm.addEventListener('submit', function(e) {
                    e.preventDefault(); // Prevent default form submission
                    
                    const category = categorySelect.value;
                    let materialName = '';
                    
                    // Determine the final material name
                    if (category === 'Other') {
                        if (otherWasteInput && otherWasteInput.value.trim()) {
                            materialName = otherWasteInput.value.trim();
                        } else {
                            alert('Please specify the other material name.');
                            return;
                        }
                    } else if (materialSelect.value && materialSelect.value.startsWith('Other ')) {
                        if (customInput && customInput.value.trim()) {
                            materialName = customInput.value.trim();
                        } else {
                            alert(`Please specify the ${materialSelect.value.toLowerCase()} details.`);
                            return;
                        }
                    } else {
                        materialName = materialSelect.value;
                    }
                    
                    // Get form data
                    const formData = new FormData();
                    formData.append('add_material', '1');
                    formData.append('material_name', materialName);
                    formData.append('quantity', document.getElementById('quantity').value);
                    
                    // Show loading state
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
                    submitBtn.disabled = true;
                    
                    // Send AJAX request
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            // Show success toast
                            showToast(data.message || 'Material added successfully!');
                            
                            // Close modal
                            closeAddMaterialModal();
                            
                            // Reset form
                            addMaterialForm.reset();
                            
                            // Dynamically add the new material to the list
                            addMaterialToDOM(data.material_name, data.quantity, data.material_id);
                        } else {
                            showToast(data.message || 'Error adding material', 'error');
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        showToast('Failed to add material. Please try again.', 'error');
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                        console.error('Error:', error);
                    });
                });
            }
        }

        // Function to dynamically add material to the DOM (updated)
        function addMaterialToDOM(materialName, neededQuantity, materialId) {
            const materialsList = document.querySelector('.materials-list');
            const emptyState = document.querySelector('.empty-state');
            
            // If there's an empty state, remove it
            if (emptyState) {
                emptyState.remove();
            }
            
            // If materials list doesn't exist, find or create it
            if (!materialsList) {
                const stageContent = document.querySelector('.stage-content');
                if (stageContent) {
                    // Create materials list if it doesn't exist
                    const contentTitle = stageContent.querySelector('.content-title');
                    if (contentTitle) {
                        const newMaterialsList = document.createElement('ul');
                        newMaterialsList.className = 'materials-list';
                        contentTitle.parentNode.insertBefore(newMaterialsList, contentTitle.nextSibling);
                        
                        // Now add the material
                        addMaterialItem(newMaterialsList, materialName, neededQuantity, materialId);
                    }
                }
            } else {
                // Add the material to existing list
                addMaterialItem(materialsList, materialName, neededQuantity, materialId);
            }
            
            // Update the "Proceed" button state
            updateProceedButton();
        }

        // Helper function to create and append material item (updated with checklist button)
        function addMaterialItem(listElement, materialName, neededQuantity, materialId, acquiredQuantity = 0) {
        const isCompleted = acquiredQuantity >= neededQuantity;
        const quantityClass = isCompleted ? 'quantity-display completed' :
                            acquiredQuantity === 0 ? 'quantity-display none' :
                            'quantity-display low';
        
        const btnClass = isCompleted ? 'btn checklist-btn disabled completed' : 'btn checklist-btn';
        const findDonationsClass = isCompleted ? 'btn find-donations disabled' : 'btn find-donations';
        
        const materialItem = document.createElement('li');
        materialItem.className = `material-item ${isCompleted ? 'completed' : ''}`;
        materialItem.setAttribute('data-material-id', materialId);
        materialItem.setAttribute('data-acquired-full', isCompleted ? 'true' : 'false');
        
        materialItem.innerHTML = `
            <div class="material-info">
                <div class="material-name">${escapeHtml(materialName)}</div>
                <div class="material-quantity">
                    <span class="${quantityClass}">${acquiredQuantity}/${neededQuantity}</span> units needed
                    ${isCompleted ? '<span class="material-complete-badge"><i class="fas fa-check-circle"></i> Complete</span>' : ''}
                </div>
            </div>
            <div class="material-actions">
                <button class="${findDonationsClass}" 
                        data-material-id="${materialId}"
                        data-material-name="${escapeHtml(materialName)}"
                        ${isCompleted ? 'disabled' : ''}>
                    <i class="fas fa-search"></i> Find Donations
                </button>
                <button class="${btnClass}" 
                        data-material-id="${materialId}"
                        data-material-name="${escapeHtml(materialName)}"
                        data-needed-quantity="${neededQuantity}"
                        data-acquired-quantity="${acquiredQuantity}"
                        ${isCompleted ? 'disabled' : ''}>
                    <i class="fas fa-check-circle"></i> Checklist
                    ${isCompleted ? '<i class="fas fa-check ml-1"></i>' : ''}
                </button>
                <button class="btn small danger" onclick="removeMaterial(${materialId})">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        listElement.appendChild(materialItem);
    }

        // Function to update quantity display for a material
        function updateMaterialQuantity(materialId, acquiredQuantity) {
            const materialItem = document.querySelector(`.material-item .btn[onclick*="removeMaterial(${materialId})"]`)?.closest('.material-item');
            if (materialItem) {
                const quantitySpan = materialItem.querySelector('.quantity-display');
                const quantityText = materialItem.querySelector('.material-quantity');
                if (quantitySpan && quantityText) {
                    const currentText = quantitySpan.textContent;
                    const parts = currentText.split('/');
                    if (parts.length === 2) {
                        const neededQuantity = parseInt(parts[1]);
                        quantitySpan.textContent = `${acquiredQuantity}/${neededQuantity}`;
                        
                        // Update class based on quantity
                        quantitySpan.className = 'quantity-display';
                        if (acquiredQuantity >= neededQuantity) {
                            quantitySpan.classList.add('completed');
                        } else if (acquiredQuantity === 0) {
                            quantitySpan.classList.add('none');
                        } else {
                            quantitySpan.classList.add('low');
                        }
                    }
                }
            }
        }

        // Function to handle Find Donations
        function findDonations(materialId, materialName) {
            // Redirect to browse page with material name as search query
            const encodedMaterialName = encodeURIComponent(materialName);
            window.location.href = `browse.php?search=${encodedMaterialName}&type=material`;
        }

        // Event listener for Find Donations buttons
        document.addEventListener('click', function(e) {
            if (e.target.closest('.find-donations')) {
                e.preventDefault();
                const button = e.target.closest('.find-donations');
                const materialId = button.getAttribute('data-material-id');
                const materialName = button.getAttribute('data-material-name');
                
                // Get current URL for return parameter
                const currentUrl = window.location.href;
                
                // Show a quick toast before redirecting
                showToast(`Searching for ${materialName} donations...`);
                
                // Redirect after a short delay so toast is visible
                setTimeout(() => {
                    const encodedMaterialName = encodeURIComponent(materialName);
                    const encodedReturnUrl = encodeURIComponent(currentUrl);
                    window.location.href = `browse.php?search=${encodedMaterialName}&type=material&source=project&material_id=${materialId}&return_url=${encodedReturnUrl}`;
                }, 500);
            }
        });


        // Update escapeHtml function if not already defined
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Function to update the "Proceed" button state
        function updateProceedButton() {
            const materialsList = document.querySelector('.materials-list');
            const proceedBtn = document.querySelector('.btn.secondary[onclick*="completeStage(1)"]');
            
            if (materialsList && proceedBtn) {
                const materialItems = materialsList.querySelectorAll('.material-item');
                proceedBtn.disabled = materialItems.length === 0;
            }
        }

        // Checklist functionality
        let currentChecklistMaterialId = null;
        let currentChecklistNeededQuantity = 0;
        let currentChecklistAcquiredQuantity = 0;

        // Event listener for Checklist buttons
        document.addEventListener('click', function(e) {
            if (e.target.closest('.checklist-btn')) {
                e.preventDefault();
                const button = e.target.closest('.checklist-btn');
                const materialId = button.getAttribute('data-material-id');
                const materialName = button.getAttribute('data-material-name');
                const neededQuantity = parseInt(button.getAttribute('data-needed-quantity'));
                const acquiredQuantity = parseInt(button.getAttribute('data-acquired-quantity'));
                
                openChecklistModal(materialId, materialName, neededQuantity, acquiredQuantity);
            }
        });

        // Function to open checklist modal - updated for incremental addition
        function openChecklistModal(materialId, materialName, neededQuantity, acquiredQuantity) {
            currentChecklistMaterialId = materialId;
            currentChecklistNeededQuantity = neededQuantity;
            currentChecklistAcquiredQuantity = acquiredQuantity;
            
            // Update modal content
            document.getElementById('checklistMaterialName').textContent = materialName;
            document.getElementById('checklistTotalNeeded').textContent = neededQuantity;
            document.getElementById('checklistAlreadyHave').textContent = acquiredQuantity;
            
            const remaining = Math.max(0, neededQuantity - acquiredQuantity);
            document.getElementById('checklistRemaining').textContent = remaining;
            
            // Set input value to 0 by default (additional quantity)
            const quantityInput = document.getElementById('acquiredQuantity');
            quantityInput.max = remaining; // Maximum you can add is the remaining quantity
            quantityInput.value = 0; // Default to 0 additional
            
            // Update the hint text
            const hintElement = document.querySelector('.checklist-input-hint');
            if (hintElement) {
                hintElement.innerHTML = `Maximum additional: <span id="maxQuantityHint">${remaining}</span> units<br>Current: ${acquiredQuantity}/${neededQuantity}`;
            }
            
            // Clear any previous error
            document.getElementById('quantityError').style.display = 'none';
            quantityInput.classList.remove('invalid');
            
            // Show modal
            document.getElementById('checklistModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Focus on input
            setTimeout(() => quantityInput.focus(), 100);
        }

        // Update the input validation to use remaining as max
        document.getElementById('acquiredQuantity').addEventListener('input', function() {
            const value = parseInt(this.value) || 0;
            const remaining = currentChecklistNeededQuantity - currentChecklistAcquiredQuantity;
            const max = Math.min(currentChecklistNeededQuantity, remaining); // Can't add more than remaining
            
            if (value > max) {
                this.value = max;
                showQuantityError(`Cannot add more than ${remaining} units (would exceed total needed)`);
            } else if (value < 0) {
                this.value = 0;
                showQuantityError('Quantity cannot be negative');
            } else {
                hideQuantityError();
            }
        });

        // Update Have All button to add remaining quantity
        document.getElementById('haveAllBtn').addEventListener('click', function() {
            const quantityInput = document.getElementById('acquiredQuantity');
            const remaining = currentChecklistNeededQuantity - currentChecklistAcquiredQuantity;
            quantityInput.value = remaining;
            hideQuantityError();
        });

        // Update error function to accept custom message
        function showQuantityError(message = 'Quantity cannot exceed remaining needed amount.') {
            const errorElement = document.getElementById('quantityError');
            const quantityInput = document.getElementById('acquiredQuantity');
            
            errorElement.textContent = message;
            errorElement.style.display = 'block';
            quantityInput.classList.add('invalid');
            
            // Auto-correct to max value
            setTimeout(() => {
                const remaining = currentChecklistNeededQuantity - currentChecklistAcquiredQuantity;
                quantityInput.value = Math.min(parseInt(quantityInput.value) || 0, remaining);
                hideQuantityError();
            }, 1000);
        }

        // Update the Mark Obtained button handler for incremental addition
        document.getElementById('markObtainedBtn').addEventListener('click', function() {
            const quantityInput = document.getElementById('acquiredQuantity');
            const additionalQuantity = parseInt(quantityInput.value) || 0;
            
            // Validate
            if (additionalQuantity < 0) {
                showQuantityError('Quantity cannot be negative');
                quantityInput.focus();
                return;
            }
            
            const remaining = currentChecklistNeededQuantity - currentChecklistAcquiredQuantity;
            if (additionalQuantity > remaining) {
                showQuantityError(`Cannot add more than ${remaining} units`);
                quantityInput.focus();
                return;
            }
            
            if (additionalQuantity === 0) {
                if (!confirm('You entered 0. Do you want to proceed without adding any?')) {
                    return;
                }
            }
            
            // Update material in database via form submission
            updateMaterialAcquiredQuantity(currentChecklistMaterialId, additionalQuantity);
        });

        // Function to update material acquired quantity via AJAX - DEBUG VERSION
        function updateMaterialAcquiredQuantity(materialId, acquiredQuantity) {
            const markBtn = document.getElementById('markObtainedBtn');
            const originalText = markBtn.innerHTML;
            markBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            markBtn.disabled = true;
            
            // Create a simple form submission instead of fetch
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'update_material_quantity';
            actionInput.value = '1';
            form.appendChild(actionInput);
            
            const materialInput = document.createElement('input');
            materialInput.type = 'hidden';
            materialInput.name = 'material_id';
            materialInput.value = materialId;
            form.appendChild(materialInput);
            
            const quantityInput = document.createElement('input');
            quantityInput.type = 'hidden';
            quantityInput.name = 'acquired_quantity';
            quantityInput.value = acquiredQuantity;
            form.appendChild(quantityInput);
            
            // Add AJAX header manually
            const ajaxHeader = document.createElement('input');
            ajaxHeader.type = 'hidden';
            ajaxHeader.name = 'HTTP_X_REQUESTED_WITH';
            ajaxHeader.value = 'XMLHttpRequest';
            form.appendChild(ajaxHeader);
            
            document.body.appendChild(form);
            
            // Submit the form
            form.submit();
            
            // Show a message that it should refresh
            setTimeout(() => {
                showToast('Quantity updated! Page will refresh...');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            }, 500);
        }

        // Function to update material display
        function updateMaterialDisplay(materialId, acquiredQuantity) {
            // Update the material item in the list
            const materialItem = document.querySelector(`.material-item[data-material-id="${materialId}"]`);
            if (materialItem) {
                const neededQuantity = parseInt(materialItem.querySelector('.checklist-btn').getAttribute('data-needed-quantity'));
                const quantitySpan = materialItem.querySelector('.quantity-display');
                const quantityClass = acquiredQuantity >= neededQuantity ? 'quantity-display completed' :
                                    acquiredQuantity === 0 ? 'quantity-display none' :
                                    'quantity-display low';
                
                // Update display
                quantitySpan.textContent = `${acquiredQuantity}/${neededQuantity}`;
                quantitySpan.className = quantityClass;
                
                // Update the checklist button data attribute
                const checklistBtn = materialItem.querySelector('.checklist-btn');
                checklistBtn.setAttribute('data-acquired-quantity', acquiredQuantity);
                
                // Update button color if completed
                if (acquiredQuantity >= neededQuantity) {
                    checklistBtn.classList.add('completed');
                } else {
                    checklistBtn.classList.remove('completed');
                }
            }
        }

        // Image Upload Functions
        function openProjectImageUploadModal() {
            document.getElementById('projectImageModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeProjectImageModal() {
            document.getElementById('projectImageModal').classList.remove('active');
            document.body.style.overflow = '';
            document.getElementById('projectImageForm').reset();
            document.getElementById('projectImagesPreview').innerHTML = '';
        }

        // Update the openStepImageUploadModal function
        // Update the openStepImageUploadModal function
        let currentStepIdForUpload = null;

        function openStepImageUploadModal(stepId, stepTitle) {
            currentStepIdForUpload = stepId;
            document.getElementById('stepImageModalTitle').textContent = stepTitle;
            document.getElementById('currentStepId').value = stepId;
            document.getElementById('stepImageForm').reset();
            document.getElementById('stepImagesPreview').innerHTML = '';
            document.getElementById('stepImageModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            
            console.log('Opened step image modal for step:', stepId, 'title:', stepTitle);
        }

        function closeStepImageModal() {
            document.getElementById('stepImageModal').classList.remove('active');
            document.body.style.overflow = '';
            document.getElementById('stepImageForm').reset();
            document.getElementById('stepImagesPreview').innerHTML = '';
            currentStepIdForUpload = null;
        }

        // Image preview
        document.getElementById('projectImages')?.addEventListener('change', function(e) {
            previewImages(e.target.files, 'projectImagesPreview');
        });

        document.getElementById('stepImages')?.addEventListener('change', function(e) {
            previewImages(e.target.files, 'stepImagesPreview');
        });

        function previewImages(files, previewContainerId) {
            const previewContainer = document.getElementById(previewContainerId);
            previewContainer.innerHTML = '';
            
            if (files.length > 0) {
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.className = 'image-preview-thumb';
                            previewContainer.appendChild(img);
                        }
                        reader.readAsDataURL(file);
                    }
                }
            }
        }

        // Update your uploadProjectImages function
        function uploadProjectImages() {
            const formElement = document.getElementById('projectImageForm');
            const files = document.getElementById('projectImages').files;
            
            if (!files || files.length === 0) {
                showToast('Please select at least one image to upload', 'error');
                return;
            }
            
            // Validate file types before upload
            let validFiles = true;
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            for (let i = 0; i < files.length; i++) {
                if (!allowedTypes.includes(files[i].type)) {
                    showToast(`File "${files[i].name}" is not a valid image type`, 'error');
                    validFiles = false;
                    break;
                }
                if (files[i].size > 5 * 1024 * 1024) {
                    showToast(`File "${files[i].name}" exceeds 5MB limit`, 'error');
                    validFiles = false;
                    break;
                }
            }
            
            if (!validFiles) return;
            
            const formData = new FormData(formElement);
            const uploadBtn = document.querySelector('#projectImageModal .check-btn');
            const originalText = uploadBtn.innerHTML;
            
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            uploadBtn.disabled = true;
            
            // Show a simple progress indicator
            showToast(`Uploading ${files.length} image(s)...`, 'info');
            
            // Use a more robust fetch approach
            fetch('upload_project_images.php', {
                method: 'POST',
                body: formData,
                // Don't set Content-Type header when using FormData - let browser set it
                credentials: 'same-origin' // Include cookies/session
            })
            .then(response => {
                // First check if response is OK
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                
                // Check content type
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        console.error('Non-JSON response:', text.substring(0, 200));
                        throw new Error('Server returned non-JSON response');
                    });
                }
                
                return response.json();
            })
            .then(data => {
                console.log('Upload response:', data);
                
                if (data.status === 'success') {
                    showToast(data.message);
                    
                    // Close modal and reload after short delay
                    setTimeout(() => {
                        closeProjectImageModal();
                        location.reload();
                    }, 1500);
                } else {
                    let errorMsg = data.message || 'Upload failed';
                    if (data.errors && Array.isArray(data.errors)) {
                        errorMsg += ': ' + data.errors.join(', ');
                    } else if (data.errors) {
                        errorMsg += ': ' + data.errors;
                    }
                    showToast(errorMsg, 'error');
                    resetUploadButton(uploadBtn, originalText);
                }
            })
            .catch(error => {
                console.error('Upload error:', error);
                
                let errorMessage = 'Upload failed: ';
                if (error.name === 'TypeError' && error.message.includes('fetch')) {
                    errorMessage += 'Network error. Check if upload_project_images.php exists and is accessible.';
                } else {
                    errorMessage += error.message;
                }
                
                showToast(errorMessage, 'error');
                resetUploadButton(uploadBtn, originalText);
            });
        }

        // Helper function
        function resetUploadButton(button, originalText) {
            button.innerHTML = originalText;
            button.disabled = false;
        }

        // Simpler version for testing - try this first
        function uploadProjectImagesSimple() {
            const form = document.getElementById('projectImageForm');
            const uploadBtn = document.querySelector('#projectImageModal .check-btn');
            const originalText = uploadBtn.innerHTML;
            
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            uploadBtn.disabled = true;
            
            // Create hidden iframe for form submission (old school but reliable)
            const iframe = document.createElement('iframe');
            iframe.name = 'upload_iframe';
            iframe.style.display = 'none';
            document.body.appendChild(iframe);
            
            iframe.onload = function() {
                try {
                    const responseText = iframe.contentDocument.body.innerHTML;
                    console.log('Upload response:', responseText);
                    
                    let data;
                    try {
                        data = JSON.parse(responseText);
                    } catch (e) {
                        data = { status: 'error', message: 'Invalid response' };
                    }
                    
                    if (data.status === 'success') {
                        showToast('Upload successful!');
                        setTimeout(() => {
                            closeProjectImageModal();
                            location.reload();
                        }, 1000);
                    } else {
                        showToast(data.message || 'Upload failed', 'error');
                        resetUploadButton(uploadBtn, originalText);
                    }
                } catch (e) {
                    showToast('Upload completed but could not parse response', 'info');
                    setTimeout(() => location.reload(), 1000);
                }
                
                document.body.removeChild(iframe);
            };
            
            // Submit form to iframe
            form.target = 'upload_iframe';
            form.submit();
        }

        // Remove image functions
        function removeProjectImage(imageId) {
            if (confirm('Are you sure you want to remove this image?')) {
                fetch('remove_image.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'type=project&image_id=' + imageId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast('Image removed successfully!');
                        location.reload();
                    } else {
                        showToast(data.message || 'Error removing image', 'error');
                    }
                })
                .catch(error => {
                    showToast('Failed to remove image', 'error');
                });
            }
        }

        // Upload step images
        // Upload step images - FIXED VERSION
        function uploadStepImages() {
            const stepId = currentStepIdForUpload || document.getElementById('currentStepId').value;
            const files = document.getElementById('stepImages').files;
            
            console.log('Uploading images for step ID:', stepId);
            console.log('Number of files:', files ? files.length : 0);
            
            if (!files || files.length === 0) {
                showToast('Please select at least one image to upload', 'error');
                return;
            }
            
            // Validate step ID
            if (!stepId || stepId < 1) {
                showToast('Invalid step ID. Please refresh the page and try again.', 'error');
                return;
            }
            
            // Validate file types before upload
            let validFiles = true;
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            for (let i = 0; i < files.length; i++) {
                if (!allowedTypes.includes(files[i].type)) {
                    showToast(`File "${files[i].name}" is not a valid image type`, 'error');
                    validFiles = false;
                    break;
                }
                if (files[i].size > 5 * 1024 * 1024) {
                    showToast(`File "${files[i].name}" exceeds 5MB limit`, 'error');
                    validFiles = false;
                    break;
                }
            }
            
            if (!validFiles) return;
            
            // Create FormData with proper step_id
            const formData = new FormData();
            formData.append('step_id', stepId);
            
            // Add all files
            for (let i = 0; i < files.length; i++) {
                formData.append('step_images[]', files[i]);
            }
            
            const uploadBtn = document.querySelector('#stepImageModal .check-btn');
            const originalText = uploadBtn.innerHTML;
            
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            uploadBtn.disabled = true;
            
            // Show loading message
            showToast(`Uploading ${files.length} image(s)...`, 'info');
            
            fetch('upload_step_images.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Upload response:', data);
                
                if (data.status === 'success') {
                    showToast(data.message);
                    
                    // Close modal and reload after short delay
                    setTimeout(() => {
                        closeStepImageModal();
                        location.reload();
                    }, 1500);
                } else {
                    let errorMsg = data.message || 'Upload failed';
                    if (data.errors) {
                        errorMsg += ': ' + (Array.isArray(data.errors) ? data.errors.join(', ') : data.errors);
                    }
                    showToast(errorMsg, 'error');
                    resetUploadButton(uploadBtn, originalText);
                }
            })
            .catch(error => {
                console.error('Upload error:', error);
                
                // Provide more specific error message
                let errorMessage = 'Upload failed: ';
                if (error.name === 'TypeError') {
                    errorMessage += 'Network error. Please check your connection.';
                } else {
                    errorMessage += error.message;
                }
                
                showToast(errorMessage, 'error');
                resetUploadButton(uploadBtn, originalText);
            });
        }

        function resetUploadButton(button, originalText) {
            button.innerHTML = originalText;
            button.disabled = false;
        }

        function removeStepImage(imageId, stepId) {
            if (confirm('Are you sure you want to remove this image?')) {
                fetch('remove_image.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'type=step&image_id=' + imageId + '&step_id=' + stepId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast('Image removed successfully!');
                        location.reload();
                    } else {
                        showToast(data.message || 'Error removing image', 'error');
                    }
                })
                .catch(error => {
                    showToast('Failed to remove image', 'error');
                });
            }
        }

        // Prevent clicks on disabled buttons
document.addEventListener('click', function(e) {
    // Check if clicked element is a disabled button
    if (e.target.closest('.btn.disabled')) {
        e.preventDefault();
        e.stopPropagation();
        
        // Optional: Show a tooltip/message
        const button = e.target.closest('.btn.disabled');
        const materialName = button.getAttribute('data-material-name') || 'this material';
        
        // Only show message for non-trash buttons
        if (!button.classList.contains('danger')) {
            showToast(`"${materialName}" is already fully obtained!`, 'info');
        }
        return false;
    }
    
    // Also handle the find-donations button specifically
    if (e.target.closest('.find-donations.disabled')) {
        e.preventDefault();
        e.stopPropagation();
        const button = e.target.closest('.find-donations.disabled');
        const materialName = button.getAttribute('data-material-name');
        showToast(`"${materialName}" is already fully obtained. No need for donations!`, 'info');
        return false;
    }
    
    // Handle disabled checklist button
    if (e.target.closest('.checklist-btn.disabled')) {
        e.preventDefault();
        e.stopPropagation();
        const button = e.target.closest('.checklist-btn.disabled');
        const materialName = button.getAttribute('data-material-name');
        showToast(`"${materialName}" checklist is already complete!`, 'info');
        return false;
    }
});

// Step form submission handler
document.getElementById('addStepForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const stepTitle = document.getElementById('step_title').value.trim();
    const stepDescription = document.getElementById('step_description').value.trim();
    
    if (!stepTitle) {
        showToast('Step title is required', 'error');
        document.getElementById('step_title').focus();
        return;
    }
    
    if (!stepDescription) {
        showToast('Step description is required', 'error');
        document.getElementById('step_description').focus();
        return;
    }
    
    // Create form data
    const formData = new FormData();
    formData.append('add_step', '1');
    formData.append('step_title', stepTitle);
    formData.append('step_description', stepDescription);
    
    // Submit the form (traditional way for simplicity)
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const titleInput = document.createElement('input');
    titleInput.type = 'hidden';
    titleInput.name = 'step_title';
    titleInput.value = stepTitle;
    form.appendChild(titleInput);
    
    const descInput = document.createElement('input');
    descInput.type = 'hidden';
    descInput.name = 'step_description';
    descInput.value = stepDescription;
    form.appendChild(descInput);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'add_step';
    actionInput.value = '1';
    form.appendChild(actionInput);
    
    document.body.appendChild(form);
    form.submit();
});

// Final Project Image Functions
function openFinalImageUploadModal() {
    document.getElementById('finalImageModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeFinalImageModal() {
    document.getElementById('finalImageModal').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('finalImageForm').reset();
    document.getElementById('finalImagePreview').innerHTML = '';
}

// Click on upload area to open modal
document.addEventListener('DOMContentLoaded', function() {
    const uploadArea = document.getElementById('finalImageUploadArea');
    if (uploadArea) {
        uploadArea.addEventListener('click', function() {
            openFinalImageUploadModal();
        });
    }
});

// Preview final image in modal
document.getElementById('finalProjectImage')?.addEventListener('change', function(e) {
    const file = this.files[0];
    const previewContainer = document.getElementById('finalImagePreview');
    previewContainer.innerHTML = '';
    
    if (file && file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'final-image-preview-large';
            previewContainer.appendChild(img);
        }
        reader.readAsDataURL(file);
    }
});

// Upload final project image
function uploadFinalProjectImage() {
    const formElement = document.getElementById('finalImageForm');
    const file = document.getElementById('finalProjectImage').files[0];
    
    if (!file) {
        showToast('Please select an image to upload', 'error');
        return;
    }
    
    // Validate file
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        showToast(`"${file.name}" is not a valid image type`, 'error');
        return;
    }
    
    if (file.size > 5 * 1024 * 1024) {
        showToast(`"${file.name}" exceeds 5MB limit`, 'error');
        return;
    }
    
    const formData = new FormData(formElement);
    const uploadBtn = document.querySelector('#finalImageModal .check-btn');
    const originalText = uploadBtn.innerHTML;
    
    uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    uploadBtn.disabled = true;
    
    fetch('upload_final_image.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            showToast('Final image uploaded successfully!');
            
            // Update the display with new image
            updateFinalImageDisplay(data.image_path, data.image_id);
            
            // Close modal after delay
            setTimeout(() => {
                closeFinalImageModal();
            }, 1500);
        } else {
            showToast(data.message || 'Upload failed', 'error');
            uploadBtn.innerHTML = originalText;
            uploadBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Upload error:', error);
        showToast('Failed to upload image. Please try again.', 'error');
        uploadBtn.innerHTML = originalText;
        uploadBtn.disabled = false;
    });
}

// Update final image display
function updateFinalImageDisplay(imagePath, imageId) {
    const container = document.getElementById('finalProjectImageContainer');
    
    container.innerHTML = `
        <div class="final-image-preview">
            <img src="${imagePath}" alt="Final Project">
            <button type="button" class="remove-final-image-btn" onclick="removeFinalProjectImage()">&times;</button>
        </div>
    `;
    
    // Store the final image ID for sharing
    document.getElementById('final_image_id').value = imageId;
}

// Remove final project image
function removeFinalProjectImage() {
    if (confirm('Are you sure you want to remove the final project image?')) {
        fetch('remove_final_image.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'project_id=' + <?= $project_id ?>
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showToast('Final image removed successfully!');
                
                // Update display back to upload area
                const container = document.getElementById('finalProjectImageContainer');
                container.innerHTML = `
                    <div class="final-image-upload-area" id="finalImageUploadArea">
                        <div class="upload-icon"><i class="fas fa-camera fa-2x"></i></div>
                        <div class="upload-text">Click to upload final project image</div>
                        <div class="upload-hint">* Required for sharing to community</div>
                    </div>
                `;
                
                // Re-add click event to new upload area
                const uploadArea = document.getElementById('finalImageUploadArea');
                if (uploadArea) {
                    uploadArea.addEventListener('click', function() {
                        openFinalImageUploadModal();
                    });
                }
                
                // Clear the final image ID
                document.getElementById('final_image_id').value = '';
            } else {
                showToast(data.message || 'Error removing image', 'error');
            }
        })
        .catch(error => {
            showToast('Failed to remove image', 'error');
        });
    }
}

// Update submitShareForm to check for final image
function submitShareForm(option) {
    // Check if construction stage is completed
    if (!<?= in_array(2, $completed_stages) ? 'true' : 'false' ?>) {
        showToast('Please complete the construction stage first.', 'error');
        return;
    }
    
    // Check if final image is uploaded for public sharing
    if (option === 'public') {
        const finalImageId = document.getElementById('final_image_id').value;
        const hasFinalImage = finalImageId && finalImageId !== '';
        
        if (!hasFinalImage) {
            showToast('Please upload a final project image before sharing to the community.', 'error');
            openFinalImageUploadModal();
            return;
        }
    }
    
    selectShareOption(option);
    
    const message = option === 'public' 
        ? 'Are you sure you want to share this project with the community? It will be visible in the Recycled Ideas feed.' 
        : 'Are you sure you want to keep this project private? It will only be visible in your profile.';
    
    showConfirmation(
        message,
        'share_project',
        function() {
            const form = document.getElementById('shareForm');
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'share_project';
            actionInput.value = '1';
            form.appendChild(actionInput);
            
            form.submit();
        }
    );
}

// Function to check if all materials are obtained and show project images section
function checkMaterialsCompletion() {
    const materialItems = document.querySelectorAll('.material-item');
    let allCompleted = true;
    
    materialItems.forEach(item => {
        const quantitySpan = item.querySelector('.quantity-display');
        if (quantitySpan) {
            const text = quantitySpan.textContent;
            const parts = text.split('/');
            if (parts.length === 2) {
                const acquired = parseInt(parts[0]);
                const needed = parseInt(parts[1]);
                if (acquired < needed) {
                    allCompleted = false;
                }
            }
        }
    });
    
    // Show/hide project images prompt based on completion
    const projectImagesPrompt = document.querySelector('.project-images-prompt');
    if (projectImagesPrompt) {
        projectImagesPrompt.style.display = allCompleted ? 'block' : 'none';
    }
    
    return allCompleted;
}

// Call this function whenever material quantities are updated
// Add this to your updateMaterialDisplay function:
function updateMaterialDisplay(materialId, acquiredQuantity) {
    // ... existing code ...
    
    // Check if all materials are now complete
    setTimeout(checkMaterialsCompletion, 100);
}

    </script>
</body>
</html>