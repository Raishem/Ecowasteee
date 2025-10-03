<?php
session_start();
require_once 'config.php';

// Initialize variables
$project = [];
$materials = [];
$steps = [];
$step_progress = [];
$success_message = '';
$error_message = '';
$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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

    // Get project materials
    $materials_stmt = $conn->prepare("SELECT * FROM project_materials WHERE project_id = ?");
    $materials_stmt->bind_param("i", $project_id);
    $materials_stmt->execute();
    $materials = $materials_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get project steps
    $steps_stmt = $conn->prepare("SELECT * FROM project_steps WHERE project_id = ? ORDER BY step_number");
    $steps_stmt->bind_param("i", $project_id);
    $steps_stmt->execute();
    $steps = $steps_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get step progress if tracking is enabled
    try {
        $prog_stmt = $conn->prepare("SELECT step_id, is_done FROM project_step_progress WHERE project_id = ?");
        if ($prog_stmt) {
                $prog_stmt->bind_param("i", $project_id);
                $prog_stmt->execute();
                $prog_res = $prog_stmt->get_result();
                while ($pr = $prog_res->fetch_assoc()) {
                    $step_progress[(int)$pr['step_id']] = (int)$pr['is_done'];
                }
            }
    } catch (mysqli_sql_exception $e) {
        // Table does not exist or another DB error occurred — leave $step_progress empty.
        // The tools/check_migration.php script can create the table if you want to enable progress tracking.
    }
    
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
                $update_stmt->bind_param("ssii", $name, $description, $project_id, $_SESSION['user_id']);
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
                WHERE material_id = ? AND project_id = ?
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
                WHERE material_id = ? AND project_id = ?
            ");
            $update_stmt->bind_param("sii", $status, $material_id, $project_id);
            $update_stmt->execute();
            $success_message = "Material status updated!";
            
            // Refresh the page
            header("Location: project_details.php?id=$project_id&success=status_updated");
            exit();
        }
    }
    
} catch (mysqli_sql_exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Get user data for header
try {
    $user_stmt = $conn->prepare("SELECT username, avatar FROM users WHERE user_id = ?");
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
} catch (mysqli_sql_exception $e) {
    $user_data = ['username' => 'User', 'avatar' => ''];
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
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/projects.css">
    <link rel="stylesheet" href="assets/css/project-details-modern-v2.css">
    <link rel="stylesheet" href="assets/css/project-description.css">
    
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
        <div class="header-right">
            <div class="notifications-icon" id="headerNotifications" title="Notifications">
                <i class="fas fa-bell"></i>
                <span class="notification-badge" id="headerUnreadCount"></span>
                <div class="header-notifications-panel" id="headerNotificationsPanel" aria-hidden="true">
                    <!-- notifications loaded dynamically -->
                </div>
            </div>

            <div class="user-profile" id="userProfile">
                <div class="profile-pic">
                    <?= !empty($user_data['avatar']) ? '<img src="'.htmlspecialchars($user_data['avatar']).'" alt="Profile">' : strtoupper(substr(htmlspecialchars($user_data['username'] ?? 'U'), 0, 1)) ?>
                </div>
                <span class="profile-name"><?= htmlspecialchars($user_data['username'] ?? ($_SESSION['first_name'] ?? 'User')) ?></span>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                <div class="profile-dropdown">
                    <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
                    <a href="settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Left Sidebar -->
        <aside class="sidebar">
            <nav class="side-navigation">
                <a href="homepage.php" class="nav-item">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="browse.php" class="nav-item">
                    <i class="fas fa-search"></i> Browse
                </a>
                <a href="projects.php" class="nav-item active">
                    <i class="fas fa-recycle"></i> My Projects
                </a>
                <a href="donations.php" class="nav-item">
                    <i class="fas fa-hand-holding-heart"></i> Donations
                </a>
                <a href="achievements.php" class="nav-item">
                    <i class="fas fa-trophy"></i> Achievements
                </a>
                <a href="leaderboard.php" class="nav-item">
                    <i class="fas fa-crown"></i> Leaderboard
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content project-details">
                        <a href="projects.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Projects</a>

            <?php if ($success_message): ?>
                <div class="toast toast-success" role="status"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="toast toast-error" role="alert"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <section class="project-header card">
    <div class="project-actions">
        <button class="edit-project edit-btn" data-action="edit-project"><i class="fas fa-edit"></i> Edit Project</button>
        <?php if (!empty($project['status']) && $project['status'] === 'completed'): ?>
            <button id="shareBtn" class="action-btn share-btn"><i class="fas fa-share"></i> Share Project</button>
        <?php endif; ?>
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

<script>
// Single event listener for the see more/see less functionality
document.addEventListener("DOMContentLoaded", function() {
    console.log("DOM Content Loaded");
    const desc = document.querySelector(".project-description");
    const toggle = document.querySelector(".see-more-btn");

    console.log("Description element:", desc);
    console.log("Toggle button:", toggle);

    if (desc && toggle) {
        // Ensure a deterministic initial state: if neither class present, default to collapsed
        if (!desc.classList.contains('collapsed') && !desc.classList.contains('expanded')) {
            desc.classList.add('collapsed');
        }

        // Sync button text/aria with current state
        const initiallyCollapsed = desc.classList.contains('collapsed');
        toggle.textContent = initiallyCollapsed ? 'See more' : 'See less';
        toggle.setAttribute('aria-expanded', initiallyCollapsed ? 'false' : 'true');

        toggle.addEventListener("click", function(e) {
            e.preventDefault();
            console.log('See more/less button clicked');

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
                // Smoothly scroll the description back into view when collapsing.
                // Wait for the collapse transition to finish (reliable) then scroll.
                const header = document.querySelector('header');
                const headerHeight = header ? header.getBoundingClientRect().height : 0;

                const doScroll = function() {
                    // Compute a target that centers the description vertically in the
                    // visible viewport area (accounting for fixed header).
                    const rect = desc.getBoundingClientRect();
                    const elemHeight = rect.height;
                    const viewportHeight = window.innerHeight;
                    const available = Math.max(0, viewportHeight - headerHeight);

                    // extra space to position element in the middle of the available area
                    const extra = Math.max(0, Math.floor((available - elemHeight) / 2));

                    // element top relative to the document
                    const elemTopDoc = window.pageYOffset + rect.top;

                    // target = element top minus header minus extra padding so it sits centered
                    const target = elemTopDoc - headerHeight - extra;

                    window.scrollTo({ top: Math.max(0, Math.floor(target)), behavior: 'smooth' });
                };

                // If the element has a CSS transition on max-height, wait for it; otherwise fallback after 300ms
                let handled = false;
                const onTransEnd = function(ev) {
                    if (ev.propertyName && ev.propertyName.indexOf('max-height') === -1) return;
                    if (handled) return;
                    handled = true;
                    desc.removeEventListener('transitionend', onTransEnd);
                    doScroll();
                };

                desc.addEventListener('transitionend', onTransEnd);
                // Fallback in case transitionend doesn't fire
                setTimeout(function() {
                    if (handled) return;
                    handled = true;
                    desc.removeEventListener('transitionend', onTransEnd);
                    doScroll();
                }, 100);
            }
        });
    } else {
        console.log("Could not find description or toggle button");
    }
});
</script>

            <section class="materials-section card">
    <div class="section-header">
        <h2 class="section-title"><i class="fas fa-box-open"></i> Materials Needed</h2>
        <button class="add-material-btn" data-action="open-add-material"><i class="fas fa-plus"></i> Add Material</button>
    </div>
    
    <div class="materials-list">
        <?php if (empty($materials)): ?>
            <p class="empty-state">No materials added yet.</p>
        <?php else: ?>
            <?php foreach ($materials as $material):
                $mid = (int)($material['material_id'] ?? $material['id'] ?? 0);
                $is_completed = isset($material['status']) && $material['status'] === 'completed';
            ?>
                <div class="material-item">
                    <div class="material-info">
                        <span class="material-name"><?= htmlspecialchars($material['material_name'] ?? '') ?></span>
                        <span class="material-quantity">Quantity: <?= htmlspecialchars($material['quantity'] ?? 0) ?></span>
                    </div>
                    <div class="material-actions">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="material_id" value="<?= $mid ?>">
                            <input type="hidden" name="status" value="<?= $is_completed ? 'needed' : 'completed' ?>">
                            <button type="submit" name="update_material_status" class="action-btn check-btn" title="<?= $is_completed ? 'Mark as Needed' : 'Mark as Obtained' ?>">
                                <i class="fas fa-square"></i>
                            </button>
                        </form>
                        <span class="status-tag status-<?= htmlspecialchars($material['status'] ?? 'needed') ?>"><?= $is_completed ? 'Obtained' : ucfirst($material['status'] ?? 'needed') ?></span>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Are you sure you want to remove this material?');">
                            <input type="hidden" name="material_id" value="<?= $mid ?>">
                            <button type="submit" name="remove_material" class="action-btn remove-btn" title="Remove Material"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

            <!-- Workflow & Documentation preserved from original -->
            <section class="project-workflow">
                <!-- ...existing workflow markup remains unchanged... -->
                <?php /* keep the workflow and documentation blocks below as-is */ ?>
            </section>
            <!-- Project Progress -->
            <section class="workflow-section card">
                <h2 class="section-title"><i class="fas fa-tasks"></i> Project Workflow</h2>
                
                <?php
                // Load workflow stage templates from DB (fall back to sensible defaults)
                $workflow_stages = [];
                try {
                    $tpl_stmt = $conn->prepare("SELECT stage_number, stage_name, description FROM stage_templates ORDER BY stage_number");
                    if ($tpl_stmt) {
                        $tpl_stmt->execute();
                        $tpl_res = $tpl_stmt->get_result();
                        while ($row = $tpl_res->fetch_assoc()) {
                            $workflow_stages[] = [
                                'name' => $row['stage_name'],
                                'description' => $row['description'],
                                'number' => (int)$row['stage_number']
                            ];
                        }
                    }
                } catch (Exception $e) {
                    $workflow_stages = [];
                }

                if (empty($workflow_stages)) {
                    $workflow_stages = [
                        ['name' => 'Planning', 'description' => 'Define project goals, list required materials, sketch your design', 'number' => 1],
                        ['name' => 'Preparation', 'description' => 'Collect materials, clean and sort materials, prepare workspace', 'number' => 2],
                        ['name' => 'Creation', 'description' => 'Build your project, follow safety guidelines, document progress', 'number' => 3],
                    ];
                }

                // Icon map by stage_number (fallback)
                $stage_icons = [1 => 'fa-lightbulb', 2 => 'fa-box', 3 => 'fa-hammer', 4 => 'fa-paint-roller', 5 => 'fa-star', 6 => 'fa-camera'];

                // Get completed stages from database
                $completed_stages = 0;
                $total_stages = count($workflow_stages);
                
                // Build a completed-stage map from the DB (use GROUP BY to coalesce duplicates)
                $completed_stage_map = [];
                try {
                    $stage_stmt = $conn->prepare(
                        "SELECT stage_number, MAX(completed_at) AS completed_at FROM project_stages WHERE project_id = ? GROUP BY stage_number"
                    );
                    if ($stage_stmt) {
                        $stage_stmt->bind_param("i", $project_id);
                        $stage_stmt->execute();
                        $stage_result = $stage_stmt->get_result();
                        while ($s = $stage_result->fetch_assoc()) {
                            // Normalize to 0-based index (DB may be 1-based)
                            $raw_num = (int)$s['stage_number'];
                            $idx = max(0, $raw_num - 1);
                            if (!is_null($s['completed_at'])) {
                                $completed_stage_map[$idx] = $s['completed_at'];
                            }
                        }
                    }
                } catch (Exception $e) {
                    // leave map empty on error
                    $completed_stage_map = [];
                }

                // Count completed stages only among the defined workflow stages
                $completed_stages = 0;
                if ($total_stages > 0) {
                    for ($i = 0; $i < $total_stages; $i++) {
                        if (array_key_exists($i, $completed_stage_map)) $completed_stages++;
                    }
                }

                // Clamp completed_stages to the range [0, total_stages]
                $completed_stages = max(0, min($completed_stages, $total_stages));

                // Calculate progress based on stages (each stage = equal weight)
                $progress_percent = $total_stages > 0 ? (int) round(($completed_stages / $total_stages) * 100) : 0;

                // Determine current stage index (first incomplete stage, or last if all complete)
                if ($total_stages === 0) {
                    $current_stage_index = 0;
                } elseif ($completed_stages >= $total_stages) {
                    $current_stage_index = max(0, $total_stages - 1);
                } else {
                    $current_stage_index = $completed_stages;
                }
                ?>

                <div class="progress-indicator">
                    <strong><?= $progress_percent ?>%</strong>
                    <?php if ($progress_percent === 100): ?>
                        of stages completed.
                    <?php else: ?>
                        of stages completed. (<?= $completed_stages ?> of <?= $total_stages ?>)
                    <?php endif; ?>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= $progress_percent ?>%;"></div>
                </div>

                <div class="workflow-stages-container">
                    <?php
                    // $completed_stage_map was built above. Use it to render stages.
                    foreach ($workflow_stages as $index => $stage): 
                        $is_completed = array_key_exists($index, $completed_stage_map);
                        // current stage is the first incomplete stage (index == completed count)
                        $is_current = !$is_completed && ($index === $current_stage_index);
                        // locked if it's after the current stage and not completed
                        $is_locked = !$is_completed && ($index > $current_stage_index);
                        $stage_class = $is_completed ? 'completed' : ($is_current ? 'current' : 'locked');
                    ?>
                        <div class="workflow-stage <?= $stage_class ?>">
                                <div class="stage-header">
                                    <?php $icon = $stage_icons[$stage['number']] ?? 'fa-circle'; ?>
                                    <i class="fas <?= $icon ?> stage-icon"></i>
                                    <div class="stage-info">
                                        <h3 class="stage-title">
                                                <?= htmlspecialchars($stage['name']) ?>
                                                <?php if ($is_completed): ?>
                                                    <i class="fas fa-check-circle stage-check" title="Completed"></i>
                                                <?php endif; ?>
                                            </h3>
                                            <?php if ($is_completed && isset($completed_stage_map[$index])): ?>
                                                <div class="stage-completed-at">Completed: <?= date('M d, Y', strtotime($completed_stage_map[$index])) ?></div>
                                            <?php endif; ?>
                                            <div class="stage-desc"><?= nl2br(htmlspecialchars($stage['description'] ?? '')) ?></div>
                                    </div>
                                </div>
                            
                            <?php
                            // Get photos for this stage (count + preview)
                            $photos_stmt = $conn->prepare("SELECT photo_path FROM stage_photos WHERE project_id = ? AND stage_number = ?");
                            $photos_stmt->bind_param("ii", $project_id, $stage['number']);
                            $photos_stmt->execute();
                            $photos_result = $photos_stmt->get_result();
                            $stage_photos = $photos_result->fetch_all(MYSQLI_ASSOC);
                            $photo_count = count($stage_photos);
                            
                            if ($photo_count > 0): ?>
                            <div class="stage-photos">
                                <?php foreach ($stage_photos as $photo): ?>
                                    <img src="assets/uploads/<?= htmlspecialchars($photo['photo_path']) ?>" 
                                         alt="Stage photo" 
                                         onclick="openImageViewer('assets/uploads/<?= htmlspecialchars($photo['photo_path']) ?>')"
                                         class="stage-photo">
                                <?php endforeach; ?>
                                <div class="photo-count"><?= $photo_count ?> photo<?= $photo_count>1 ? 's' : '' ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!$is_locked): ?>
                            <div class="stage-actions">
                                <button class="upload-photo-btn" onclick="uploadStagePhoto(<?= $stage['number'] ?>)">
                                    <i class="fas fa-camera"></i> Upload Photo
                                </button>
                                <?php if ($is_current): ?>
                                    <button class="complete-stage-btn" onclick="completeStage(<?= $stage['number'] ?>, <?= $project_id ?>)">
                                        <i class="fas fa-check"></i> Mark as Complete
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                                <div class="stage-locked-note" title="This stage is locked until previous stages are completed.">🔒 Stage locked — complete previous stage to unlock.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            
        </div>
    </div>

        <script>
        // Simplified header-notifications handling: update header badge and provide a small dropdown panel.
        let _lastUnreadCount = 0;

        async function updateNotifications() {
            try {
                const res = await fetch('notification_updates.php?action=get_recent');
                const data = await res.json();
                if (data.error) {
                    console.error('Error:', data.error);
                    return;
                }

                const headerBadge = document.getElementById('headerUnreadCount');
                const headerIcon = document.getElementById('headerNotifications');
                const panel = document.getElementById('headerNotificationsPanel');

                // Update header badge
                if (headerBadge) headerBadge.textContent = data.unread_count > 0 ? data.unread_count : '';
                if (headerIcon) {
                    if (data.unread_count > 0) headerIcon.classList.add('has-unread');
                    else headerIcon.classList.remove('has-unread');
                }

                // Pulse animation when new notifications arrive
                if (data.unread_count > _lastUnreadCount && data.unread_count > 0) {
                    if (headerBadge) {
                        headerBadge.classList.add('pulse');
                        setTimeout(() => headerBadge.classList.remove('pulse'), 1200);
                    }
                }
                _lastUnreadCount = data.unread_count || 0;

                // Populate header panel with up to 6 notifications (simple list)
                if (panel) {
                    panel.innerHTML = data.notifications && data.notifications.length ? data.notifications.slice(0,6).map(n=>`
                        <div class="header-notif ${n.is_read ? 'read' : 'unread'}" data-id="${n.notification_id}">
                            <div class="hn-title">${n.title}</div>
                            <div class="hn-msg">${n.message}</div>
                            <div class="hn-time">${new Date(n.created_at).toLocaleString()}</div>
                        </div>
                    `).join('') : '<div class="header-notif empty">No notifications</div>';
                }
            } catch (err) {
                console.error('Error fetching notifications', err);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initial fetch
            updateNotifications();
            setInterval(updateNotifications, 30000);

            // Toggle header notifications panel
            const headerNotifications = document.getElementById('headerNotifications');
            const panel = document.getElementById('headerNotificationsPanel');
            if (headerNotifications && panel) {
                headerNotifications.addEventListener('click', function(e) {
                    e.stopPropagation();
                    panel.classList.toggle('open');
                });
                // Close when clicking outside
                document.addEventListener('click', function() {
                    panel.classList.remove('open');
                });
                panel.addEventListener('click', function(e){ e.stopPropagation(); });
            }
        });
        </script>
    </div>
    
    <!-- Edit Project Modal will be created dynamically -->
    <div id="editProjectModalContainer"></div>
    <script>
    function createEditProjectModal() {
        const container = document.getElementById('editProjectModalContainer');
        if (!container) return;
        
        // Only create if it doesn't exist
        if (document.getElementById('editProjectModal')) return;
        
        const projectName = <?= json_encode($project['project_name']) ?>;
        const projectDesc = <?= json_encode($project['description']) ?>;
        
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.id = 'editProjectModal';
        modal.style.display = 'none';  // Ensure it starts hidden
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Edit Project</h3>
                    <button type="button" class="close-modal" data-action="close-edit">&times;</button>
                </div>
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
        `;
        container.appendChild(modal);
        
        // Wire up close buttons
        modal.querySelectorAll('[data-action="close-edit"]').forEach(btn => {
            btn.addEventListener('click', function() {
                modal.style.display = 'none';
            });
        });
        
        return modal;
    }
    </script>
    
    <!-- Add Material modal will be created dynamically to avoid accidental auto-open -->
    <script>
        function createAddMaterialModal(){
            if (document.getElementById('addMaterialModal')) return document.getElementById('addMaterialModal');
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.id = 'addMaterialModal';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Add Material</h3>
                        <button type="button" class="close-modal" data-action="close-add">&times;</button>
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
                            <button type="button" class="action-btn" data-action="close-add">Cancel</button>
                            <button type="submit" name="add_material" class="action-btn check-btn">Add Material</button>
                        </div>
                    </form>
                </div>
            `;
            document.body.appendChild(modal);
            // attach close handlers
            modal.querySelectorAll('[data-action="close-add"]').forEach(btn=>{
                btn.addEventListener('click', function(ev){ ev.preventDefault(); modal.classList.remove('active'); });
            });
            return modal;
        }
    </script>
    
    
    <!-- include reusable shared modal -->
    <?php include __DIR__ . '/includes/share_modal.php'; ?>

    <script>
    // helper to close any open modal to avoid stacked modals
    function closeAllModals(){
        document.querySelectorAll('.modal').forEach(m=>{ m.style.display = 'none'; });
        document.body.style.overflow = '';
    }

    // initialize shared modal with server data (only if share button exists)
    (function(){
        const shareBtn = document.getElementById('shareBtn');
        if (!shareBtn) return;
        // prepare materials & steps arrays for summary
        const materialsData = <?= json_encode(array_map(function($m){
            return [
                'id' => (int)($m['material_id'] ?? $m['id'] ?? 0),
                'name' => $m['name'] ?? $m['material_name'] ?? ''
            ];
        }, $materials)); ?>;
        const stepsData = <?= json_encode(array_map(function($s){ return ['title'=>$s['title'] ?? '']; }, $steps)); ?>;

        // initialize and override shareBtn click to close other modals first
        window.sharedModalAPI.init({ projectId: <?= $project_id ?>, materials: materialsData, steps: stepsData });
        shareBtn.addEventListener('click', function(){
            closeAllModals();
            window.sharedModalAPI.open();
        });
    })();
    </script>
    
<script>
document.addEventListener('DOMContentLoaded', function() {
    const userProfile = document.getElementById('userProfile');
    const profileDropdown = userProfile ? userProfile.querySelector('.profile-dropdown') : null;

    if (userProfile) {
        userProfile.addEventListener('click', function(e) {
            e.preventDefault();
            userProfile.classList.toggle('active');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!userProfile.contains(e.target)) {
                userProfile.classList.remove('active');
            }
        });
    }
});
</script>
<script>
// JS to toggle project step completion and update the UI
async function toggleStep(stepId, projectId, btn) {
    try {
        btn.disabled = true;
        const icon = btn.querySelector('i');
        // Determine current state from icon class
        const currentlyDone = icon.classList.contains('fa-check-circle');
        const newDone = currentlyDone ? 0 : 1;

        const form = new FormData();
        form.append('action', 'toggle_step');
        form.append('project_id', projectId);
        form.append('step_id', stepId);
        form.append('done', newDone);

        const res = await fetch('update_project.php', {
            method: 'POST',
            body: form
        });
        const json = await res.json();
        if (json.success) {
            // Toggle icon
            if (newDone) {
                icon.classList.remove('fa-circle');
                icon.classList.add('fa-check-circle');
                btn.title = 'Mark incomplete';
                btn.closest('.step-card').classList.add('step-done');
            } else {
                icon.classList.remove('fa-check-circle');
                icon.classList.add('fa-circle');
                btn.title = 'Mark complete';
                btn.closest('.step-card').classList.remove('step-done');
            }
        } else {
            if (typeof showToast === 'function') showToast(json.message || 'Could not update step status', 'error');
            else alert(json.message || 'Could not update step status');
        }
    } catch (err) {
        console.error(err);
        if (typeof showToast === 'function') showToast('Network error while updating step', 'error');
        else alert('Network error while updating step');
    } finally {
        btn.disabled = false;
    }
}

// Optional: add click handler for delegation if needed
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.step-actions .check-btn');
    if (btn && btn.dataset.bound !== '1') {
        // Bound via inline onclick in markup; no-op here
        btn.dataset.bound = '1';
    }
});

</script>
<!-- Add the new CSS file -->
<link rel="stylesheet" href="assets/css/project-stages.css">

<script>
// ...existing code continues (keeps the later, improved completeStage implementation)

// Image viewer functionality
function openImageViewer(src) {
    let viewer = document.querySelector('.image-viewer');
    if (!viewer) {
        viewer = document.createElement('div');
        viewer.className = 'image-viewer';
        viewer.onclick = () => viewer.style.display = 'none';
        document.body.appendChild(viewer);
    }
    
    viewer.innerHTML = `<img src="${src}" alt="Full size image">`;
    viewer.style.display = 'flex';
}

// Initialize modal controls only when needed
(function(){
    // Create modal when edit button is clicked
    const editBtn = document.querySelector('[data-action="edit-project"]');
    if (editBtn) {
        editBtn.addEventListener('click', function(ev) {
            ev.preventDefault();
            ev.stopPropagation();
            
            // Create modal if it doesn't exist yet
            const modal = createEditProjectModal();
            if (modal) {
                modal.style.display = 'block';
            }
        });
    }
    
    // Global click handler to ensure modals can be closed
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) {
            e.target.style.display = 'none';
        }
    });
    
    // Ensure any existing modal is hidden on load
    document.addEventListener('DOMContentLoaded', function() {
        const existingModal = document.getElementById('editProjectModal');
        if (existingModal) {
            existingModal.style.display = 'none';
        }
    });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const successToast = document.querySelector('.toast-success');
    if (successToast) {
        setTimeout(() => {
            successToast.style.display = 'none';
        }, 3000);
    }
});

// Function to handle stage completion
async function completeStage(stageNumber, projectId) {
    try {
        const btn = event.target;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        
        const response = await fetch('complete_stage.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `stage_number=${stageNumber}&project_id=${projectId}`
        });
        
        const data = await response.json();
        if (data.success) {
            // Show success message
            const toast = document.createElement('div');
            toast.className = 'toast toast-success';
            toast.innerHTML = '<i class="fas fa-check-circle"></i> Stage completed successfully!';
            document.body.appendChild(toast);
            
            setTimeout(() => window.location.reload(), 1000);
        } else {
            alert(data.message || 'Error completing stage');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Mark as Complete';
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Network error while completing stage');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Mark as Complete';
    }
}

// Function to handle photo upload
function uploadStagePhoto(stageNumber) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    
    input.onchange = async function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        // Check file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            alert('File size must be less than 5MB');
            return;
        }
        
        const formData = new FormData();
        formData.append('photo', file);
        formData.append('stage_number', stageNumber);
        formData.append('project_id', <?= $project_id ?>);
        
        try {
            const response = await fetch('upload_stage_photo.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.message || 'Error uploading photo');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Network error while uploading photo');
        }
    };
    
    input.click();
}
</script>
</body>
</html>