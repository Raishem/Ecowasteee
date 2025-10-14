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
    // If this is an AJAX request expecting JSON, return a 401 JSON response
    $is_ajax_early = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    if ($is_ajax_early) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    header('Location: login.php');
    exit();
}

try {
    $conn = getDBConnection();

    // Ensure allocations table exists (lightweight, safe to run repeatedly)
    $conn->query("CREATE TABLE IF NOT EXISTS material_allocations (
        allocation_id INT AUTO_INCREMENT PRIMARY KEY,
        allocation_group VARCHAR(64) NOT NULL,
        project_id INT NOT NULL,
        material_id INT NOT NULL,
        donation_id INT DEFAULT NULL,
        allocated_quantity INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
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

    // Normalize statuses: if any materials have quantity <= 0 but status is not 'obtained', mark them obtained
    try {
        $norm = $conn->prepare("UPDATE project_materials SET status = 'obtained' WHERE project_id = ? AND quantity <= 0 AND (status IS NULL OR LOWER(status) <> 'obtained')");
        if ($norm) { $norm->bind_param('i', $project_id); $norm->execute(); }
    } catch (Exception $e) { /* ignore normalization errors */ }

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
        // helper to detect AJAX requests
        $is_ajax_request = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
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
                if ($is_ajax_request) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $error_message]);
                    exit();
                }
            } else {
                $add_stmt = $conn->prepare("
                    INSERT INTO project_materials (project_id, material_name, quantity, status) 
                    VALUES (?, ?, ?, 'needed')
                ");
                $add_stmt->bind_param("isi", $project_id, $material_name, $quantity);
                $add_stmt->execute();
                $success_message = "Material added successfully!";
                // If AJAX, return the newly created material data
                if ($is_ajax_request) {
                    $new_id = $conn->insert_id;
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'material' => ['material_id' => (int)$new_id, 'material_name' => $material_name, 'quantity' => $quantity, 'status' => 'needed']]);
                    exit();
                }

                // Refresh the page to show new material (non-AJAX)
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
            if ($is_ajax_request) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'material_id' => $material_id]);
                exit();
            }
            // Refresh the page
            header("Location: project_details.php?id=$project_id&success=material_removed");
            exit();
        } elseif (isset($_POST['update_material_status'])) {
            // Update material status (supports optional quantity when marking obtained)
            $material_id = (int)$_POST['material_id'];
            $status = isset($_POST['status']) ? $_POST['status'] : null;

            // If quantity provided (obtained flow), deduct from stored quantity
            if (isset($_POST['quantity'])) {
                $qty = (int)$_POST['quantity'];
                if ($qty > 0) {
                    $deduct = $conn->prepare("UPDATE project_materials SET quantity = GREATEST(quantity - ?, 0) WHERE material_id = ? AND project_id = ?");
                    $deduct->bind_param("iii", $qty, $material_id, $project_id);
                    $deduct->execute();
                }
            }

            if ($status !== null) {
                $update_stmt = $conn->prepare("
                UPDATE project_materials 
                SET status = ? 
                WHERE material_id = ? AND project_id = ?
            ");
                $update_stmt->bind_param("sii", $status, $material_id, $project_id);
                $update_stmt->execute();
            }

            // Fetch updated values for client sync
            $qstmt = $conn->prepare("SELECT quantity, status FROM project_materials WHERE material_id = ? AND project_id = ?");
            $qstmt->bind_param("ii", $material_id, $project_id);
            $qstmt->execute();
            $qres = $qstmt->get_result();
            $row = $qres->fetch_assoc();
            $current_qty = $row ? (int)$row['quantity'] : null;
            $current_status = $row ? $row['status'] : ($status ?: 'needed');

            // If quantity has dropped to zero or less, ensure status is at least 'obtained'
            if (!is_null($current_qty) && $current_qty <= 0 && $current_status !== 'obtained') {
                try {
                    $up2 = $conn->prepare("UPDATE project_materials SET status = 'obtained' WHERE material_id = ? AND project_id = ?");
                    $up2->bind_param("ii", $material_id, $project_id);
                    $up2->execute();
                    $current_status = 'obtained';
                } catch (Exception $e) {
                    // ignore update failure; we'll still return the numeric quantity
                }
            }

            $success_message = "Material Updated";
            if ($is_ajax_request) {
                // Determine if all materials for this project are obtained
                $stage_completed = false;
                try {
                    $cstmt = $conn->prepare("SELECT COUNT(*) AS not_obtained FROM project_materials WHERE project_id = ? AND (status IS NULL OR status <> 'obtained')");
                    $cstmt->bind_param("i", $project_id);
                    $cstmt->execute();
                    $cres = $cstmt->get_result();
                    $crow = $cres->fetch_assoc();
                    $not_obtained = $crow ? (int)$crow['not_obtained'] : 0;
                    if ($not_obtained === 0) {
                        $stage_completed = true;
                        // mark the project stage 'Material Collection' as completed if such a stage exists
                        try {
                            $mark = $conn->prepare("UPDATE project_stages SET is_completed = 1, completed_at = NOW() WHERE project_id = ? AND stage_number = 1");
                            $mark->bind_param("i", $project_id);
                            $mark->execute();
                        } catch (Exception $e) { /* ignore */ }
                    }
                } catch (Exception $e) { /* ignore */ }

                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'material_id' => $material_id, 'status' => $current_status, 'quantity' => $current_qty, 'stage_completed' => $stage_completed]);
                exit();
            }
            // Refresh the page for non-AJAX
            header("Location: project_details.php?id=$project_id&success=status_updated");
            exit();
        
        } elseif (isset($_POST['undo_allocation'])) {
            // undo by allocation group
            $group = $_POST['undo_allocation'];
            if (!preg_match('/^[0-9a-f]{1,64}$/', $group)) {
                if ($is_ajax_request) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Invalid group']); exit(); }
            }
            try {
                $a = $conn->prepare("SELECT SUM(allocated_quantity) as total, material_id FROM material_allocations WHERE allocation_group = ? AND project_id = ? GROUP BY material_id");
                $a->bind_param("si", $group, $project_id);
                $a->execute();
                $ares = $a->get_result();
                $rolled = 0;
                while ($r = $ares->fetch_assoc()) {
                    $mid = (int)$r['material_id']; $tot = (int)$r['total'];
                    // add back to material quantity
                    $up = $conn->prepare("UPDATE project_materials SET quantity = quantity + ? , status = 'needed' WHERE material_id = ? AND project_id = ?");
                    $up->bind_param("iii", $tot, $mid, $project_id);
                    $up->execute();
                    $rolled++;
                }
                // delete allocation records for group
                $d = $conn->prepare("DELETE FROM material_allocations WHERE allocation_group = ? AND project_id = ?");
                $d->bind_param("si", $group, $project_id);
                $d->execute();
                if ($is_ajax_request) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'rolled'=>$rolled]); exit(); }
                header("Location: project_details.php?id=$project_id&success=undo"); exit();
            } catch (Exception $e) { if ($is_ajax_request) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit(); } }
        }
    }
    
} catch (mysqli_sql_exception $e) {
    $error_message = "Database error: " . $e->getMessage();
    $conn = null;
}

// Get user data for header
try {
    if ($conn) {
        $user_stmt = $conn->prepare("SELECT username, avatar FROM users WHERE user_id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_data = $user_stmt->get_result()->fetch_assoc();
    } else {
        $user_data = ['username' => 'User', 'avatar' => ''];
    }
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
    <link rel="stylesheet" href="assets/css/project-materials.css?v=4">
    <script src="assets/js/stage-completion.js?v=1" defer></script>
    
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
                <div class="toast toast-success" data-server-toast="1" role="status"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="toast toast-error" data-server-toast="1" role="alert"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?></div>
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
    // AJAX handlers for materials: intercept forms and use fetch to avoid full reloads
// Single event listener for the see more/see less functionality
document.addEventListener('DOMContentLoaded', function() {
    const desc = document.querySelector('.project-description');
    const toggle = document.querySelector('.see-more-btn');

    if (!desc || !toggle) return;

    // Default to collapsed unless explicitly expanded
    if (!desc.classList.contains('collapsed') && !desc.classList.contains('expanded')) desc.classList.add('collapsed');

    // Detect whether content overflows more than 5 lines
    function contentOverflowsFiveLines(el) {
        // Create a clone to measure full height
        const clone = el.cloneNode(true);
        clone.style.visibility = 'hidden';
        clone.style.position = 'absolute';
        clone.style.maxHeight = 'none';
        clone.style.display = 'block';
        clone.style.width = getComputedStyle(el).width;
        document.body.appendChild(clone);
        const fullHeight = clone.getBoundingClientRect().height;
        document.body.removeChild(clone);

        const lineHeight = parseFloat(getComputedStyle(el).lineHeight) || 18;
        const maxAllowed = lineHeight * 5 + 1; // small tolerance
        return fullHeight > maxAllowed;
    }

    const overflow = contentOverflowsFiveLines(desc);
    if (overflow) {
        toggle.style.display = '';
        desc.classList.add('has-overflow');
    } else {
        toggle.style.display = 'none';
        desc.classList.remove('has-overflow');
    }

    // initialize button state
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
            // when collapsing, ensure the element remains visible in viewport
            const header = document.querySelector('header');
            const headerHeight = header ? header.getBoundingClientRect().height : 0;
            const rect = desc.getBoundingClientRect();
            const elemTopDoc = window.pageYOffset + rect.top;
            const target = Math.max(0, Math.floor(elemTopDoc - headerHeight - 20));
            window.scrollTo({ top: target, behavior: 'smooth' });
        }
    });
});
</script>
<script>
// Materials AJAX handlers
(function(){
    const projectId = <?= json_encode($project_id) ?>;

    // Utility: show toast with fade-out and configurable duration
    // usage: showToast(message, type = 'success', durationMs = 4000)
    function showToast(msg, type='success', autoHide = true){
        // Ensure a top-center container exists for toasts
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            // Inline styles to avoid requiring CSS edits elsewhere
            container.style.position = 'fixed';
            container.style.top = '18px';
            container.style.left = '50%';
            container.style.transform = 'translateX(-50%)';
            // Ensure it's above other UI layers
            container.style.zIndex = '2147483647';
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.alignItems = 'center';
            container.style.gap = '8px';
            container.style.pointerEvents = 'none'; // allow clicks through except on toasts
            document.body.appendChild(container);
        }

        const t = document.createElement('div');
        t.className = 'toast toast-' + (type==='error' ? 'error' : 'success');
        t.style.pointerEvents = 'auto';
        t.innerHTML = (type === 'error' ? '<i class="fas fa-exclamation-circle"></i> ' : '<i class="fas fa-check-circle"></i> ') + String(msg);

        // Show the toast inside the container
        container.appendChild(t);
        requestAnimationFrame(()=> t.classList.add('show'));

        // Auto-hide only when requested. Do NOT add an ESC close for ephemeral toasts.
        const closeToast = () => {
            t.classList.remove('show');
            t.classList.add('hide');
            setTimeout(()=> { try{ t.remove(); } catch(e){} }, 420);
        };

        if (autoHide) {
            setTimeout(closeToast, 3000);
        }
        return t;
    }

    // Open the add-material modal and reset fields for a clean input
    function showAddMaterialModal(){
        const modal = createAddMaterialModal();
        if (!modal) return null;
        const form = modal.querySelector('form');
        if (form) {
            // clear inputs
            form.querySelectorAll('input, textarea').forEach(i=>{ if (i.type !== 'hidden') i.value = ''; });
        }
        modal.classList.add('active');
        return modal;
    }

    // Styled confirmation modal (returns Promise<boolean>)
    function showConfirm(message){
        return new Promise((resolve)=>{
            let modal = document.getElementById('confirmModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'confirmModal';
                modal.className = 'modal';
                modal.innerHTML = `
                    <div class="modal-content" style="min-width: 320px; background: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                        <div class="modal-header" style="padding: 16px; border-bottom: 1px solid #eee;">
                            <div class="modal-title" style="font-size: 18px; font-weight: 600;">Confirm Deletion</div>
                        </div>
                        <div class="modal-body" style="padding: 20px 16px;">
                            <p id="confirmMessage" style="margin:0;color:#333;font-size:16px"></p>
                        </div>
                        <div class="modal-actions" style="padding: 16px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="action-btn" data-action="confirm-cancel" style="padding: 8px 16px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">Cancel</button>
                            <button type="button" class="action-btn danger-btn" data-action="confirm-ok" style="padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">Delete</button>
                        </div>
                    </div>`;
                document.body.appendChild(modal);
                // Prevent overlay clicks from closing this modal accidentally
                modal.addEventListener('click', function(ev){
                    if (ev.target === modal) {
                        ev.stopPropagation();
                        return;
                    }
                });
                modal.dataset.persistent = '1';
            }

            // Per-call handlers so each Promise resolves correctly when buttons are clicked
            const msgEl = modal.querySelector('#confirmMessage');
            if (msgEl) msgEl.textContent = message;

            // show modal
            modal.classList.add('active');

            const btnCancel = modal.querySelector('[data-action="confirm-cancel"]');
            const btnOk = modal.querySelector('[data-action="confirm-ok"]');

            // Escape handler for this call
            const handleEscape = (e) => {
                if (e.key === 'Escape') {
                    cleanup();
                    resolve(false);
                }
            };

            function cleanup(){
                modal.classList.remove('active');
                document.removeEventListener('keydown', handleEscape);
                if (btnCancel && btnCancel._handler) { btnCancel.removeEventListener('click', btnCancel._handler); btnCancel._handler = null; }
                if (btnOk && btnOk._handler) { btnOk.removeEventListener('click', btnOk._handler); btnOk._handler = null; }
            }

            // Attach handlers referencing this resolve, and stash references for cleanup
            if (btnCancel) {
                btnCancel._handler = function(){ cleanup(); resolve(false); };
                btnCancel.addEventListener('click', btnCancel._handler);
            }
            if (btnOk) {
                btnOk._handler = function(){ cleanup(); resolve(true); };
                btnOk.addEventListener('click', btnOk._handler);
            }

            document.addEventListener('keydown', handleEscape);
        });
    }

    // Intercept remove and update forms inside material list
    document.addEventListener('submit', async function(e){
        const form = e.target;
        // If this is an obtain form that wants a quantity, show a small modal first
        if (form.dataset && form.dataset.obtainModal) {
            // Capture submitter name/value before awaiting modal so FormData can include it
            let submitter = e.submitter || document.activeElement;
            let submitterName = (submitter && submitter.name) ? submitter.name : null;
            let submitterValue = (submitter && submitter.value) ? submitter.value : '1';

            e.preventDefault();
            const materialItem = form.closest('.material-item');
            if (materialItem) {
                materialItem.dataset.obtaining = "1";
                try {
                    // determine current available quantity from the UI (if present)
                    let maxQty = null;
                    const qtyEl = materialItem.querySelector('.mat-qty');
                    if (qtyEl) {
                        const m = String(qtyEl.textContent).match(/\d+/);
                        if (m) maxQty = parseInt(m[0], 10);
                    }
                    const result = await showObtainedModal(maxQty);
                    if (result && result.confirmed) {
                        const fd = new FormData(form);
                        // ensure server receives which action (submitter)
                        if (submitterName && !fd.has(submitterName)) fd.append(submitterName, submitterValue || '1');
                        fd.append('quantity', result.qty);
                        
                        const response = await fetch('project_details.php?id=' + projectId, {
                            method: 'POST',
                            body: fd,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });

                        const respText = await response.text().catch(()=>null);
                        if (!response.ok) {
                            console.error('Non-OK response from server (obtained flow):', response.status, respText);
                            showToast(respText || 'Failed to update material status', 'error');
                            throw new Error('Network response was not ok');
                        }

                        let data = null;
                        try {
                            data = respText ? JSON.parse(respText) : null;
                        } catch (err) {
                            console.error('Failed to parse JSON (obtained flow):', respText);
                            showToast(respText || 'Failed to update material status', 'error');
                            throw err;
                        }

                        if (data && data.success) {
                            showToast('Material Updated');
                            materialItem.classList.add('material-obtained');

                            // Update displayed quantity if server returned it
                            if (typeof data.quantity !== 'undefined' && data.quantity !== null) {
                                let qtyEl = materialItem.querySelector('.mat-qty');
                                if (data.quantity <= 0) {
                                    if (qtyEl) qtyEl.remove();
                                } else {
                                    if (qtyEl) {
                                        qtyEl.textContent = String(data.quantity);
                                    } else {
                                        // insert a new quantity element next to the name
                                        const main = materialItem.querySelector('.material-main');
                                        if (main) {
                                            const span = document.createElement('span');
                                            span.className = 'mat-qty';
                                            span.textContent = String(data.quantity);
                                            main.appendChild(span);
                                        }
                                    }
                                }
                            }

                            // Update action area based on returned status (if provided)
                            if (data.status) {
                                materialItem.classList.toggle('material-obtained', data.status !== 'needed');
                                const actions = materialItem.querySelector('.material-actions');
                                const mid = materialItem.dataset.materialId || (materialItem.querySelector('input[name="material_id"]') ? materialItem.querySelector('input[name="material_id"]').value : '');
                                if (actions) {
                                    if (data.status === 'obtained') {
                                        // Move obtained badge into .mat-meta (right side of material-main)
                                        const meta = materialItem.querySelector('.mat-meta');
                                        if (meta) {
                                            // remove existing badge if any
                                            const old = meta.querySelector('.badge.obtained');
                                            if (old) old.remove();
                                            const span = document.createElement('span');
                                            span.className = 'badge obtained';
                                            span.innerHTML = '<i class="fas fa-check-circle"></i> Obtained';
                                            meta.appendChild(span);
                                        }

                                        // Ensure actions area is cleared (no buttons on obtained)
                                        actions.innerHTML = '';

                                        // Ensure upload camera button is shown beside the Obtained badge (in .mat-meta) if no photo exists
                                        const photos = materialItem.querySelector('.material-photos');
                                        const metaEl = materialItem.querySelector('.mat-meta');
                                        const hasPhotoNow = photos && photos.querySelector('.material-photo');
                                        if (metaEl && !hasPhotoNow) {
                                            // remove any placeholder that might be under photos
                                            if (photos) photos.querySelectorAll('.material-photo-placeholder').forEach(n=>n.remove());
                                            // avoid duplicate buttons
                                            if (!metaEl.querySelector('.upload-material-photo')) {
                                                const btn = document.createElement('button');
                                                btn.type = 'button'; btn.className = 'btn small upload-material-photo'; btn.setAttribute('data-material-id', mid);
                                                btn.title = 'Upload photo'; btn.innerHTML = '<i class="fas fa-camera"></i>';
                                                metaEl.appendChild(btn);
                                            }
                                        }
                                    } else {
                                        // restore basic buttons (attempt minimal update)
                                        const btnToggle = materialItem.querySelector('form button[name="update_material_status"]');
                                        if (btnToggle) btnToggle.innerHTML = (data.status === 'needed' ? '<i class="fas fa-check" aria-hidden="true"></i>' : '<i class="fas fa-undo" aria-hidden="true"></i>');
                                    }
                                }
                            }

                            // If server reports that the stage completed (all materials obtained), reload to reflect the next stage
                            if (data.stage_completed) {
                                showToast('All materials obtained — advancing stage');
                                setTimeout(()=> location.reload(), 700);
                            }
                        } else {
                            showToast((data && (data.error || data.message)) || 'Failed to update material status', 'error');
                        }
                    }
                } catch (err) {
                    console.error('Error:', err);
                    showToast('Failed to update material status. Please try again.', 'error');
                } finally {
                    delete materialItem.dataset.obtaining;
                }
            }
            return;
        }

        if (!(form.matches && form.matches('.inline-form'))) return;

        // Capture intended submitter name/value before any await() — this fixes cases
        // where awaiting a confirm dialog changes document.activeElement.
        let intendedSubmitName = null;
        let intendedSubmitValue = null;
        if (e.submitter && e.submitter.name) {
            intendedSubmitName = e.submitter.name;
            intendedSubmitValue = e.submitter.value || '1';
        } else {
            // fallback: look for a named submit button inside the form
            const sb = form.querySelector('button[type="submit"][name], input[type="submit"][name]');
            if (sb) { intendedSubmitName = sb.name; intendedSubmitValue = sb.value || '1'; }
        }

        // respect data-confirm attribute (used instead of inline onsubmit)
        if (form.dataset && form.dataset.confirm) {
            // prevent immediate submit so confirm modal can control the flow
            e.preventDefault();
            const ok = await showConfirm(form.dataset.confirm);
            if (!ok) return; // user cancelled
            // user confirmed — continue and let the AJAX handler below process the form
        } else {
            e.preventDefault();
        }

        // Build FormData and include the captured submitter name/value so server can detect action
        const fd = new FormData(form);
        if (intendedSubmitName && !fd.has(intendedSubmitName)) fd.append(intendedSubmitName, intendedSubmitValue || '1');

        // ensure request is treated as AJAX
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };

        try {
            const res = await fetch('project_details.php?id=' + projectId, { method: 'POST', body: fd, headers });
            const text = await res.text().catch(()=>null);
            let json = null;
            try {
                json = text ? JSON.parse(text) : null;
            } catch (err) {
                console.error('Invalid JSON response for action:', text);
                showToast(text || 'Action failed', 'error');
                return;
            }
            if (!json || !json.success) {
                showToast(json && json.message ? json.message : 'Action failed', 'error');
                return;
            }

            // Remove material
            if (fd.get('remove_material') !== null) {
                showToast('Material removed successfully', 'success', true); // auto-hide after 3s

                const mid = json.material_id || fd.get('material_id');
                const li = document.querySelector('.material-item[data-material-id="' + mid + '"]');
                if (li) {
                    // measure and animate height -> 0 for smooth collapse
                    const startH = li.getBoundingClientRect().height;
                    li.style.height = startH + 'px';
                    li.offsetHeight;
                    li.style.transition = 'height .22s ease, opacity .18s ease, transform .18s ease';
                    li.style.height = '0px';
                    li.style.opacity = '0';
                    li.style.transform = 'translateY(-6px)';
                    setTimeout(()=> {
                        if (li && li.parentNode) li.parentNode.removeChild(li);
                    }, 240);
                }
                return;
            }

            // Update status
            if (fd.get('update_material_status') !== null) {
                const mid = json.material_id || fd.get('material_id');
                const status = json.status || fd.get('status');
                const li = document.querySelector('.material-item[data-material-id="' + mid + '"]');
                    if (li) {
                    // Update toggle/check button if present
                    const btnToggle = li.querySelector('form button[name="update_material_status"]');
                    if (btnToggle) btnToggle.innerHTML = (status === 'needed' ? '<i class="fas fa-check" aria-hidden="true"></i>' : '<i class="fas fa-undo" aria-hidden="true"></i>');

                    // mark obtained visually and replace actions with Obtained badge + Upload Photo control
                    li.classList.toggle('material-obtained', status !== 'needed');

                    // Update displayed quantity if provided by server
                    if (typeof json.quantity !== 'undefined' && json.quantity !== null) {
                        let qtyEl = li.querySelector('.mat-qty');
                        if (qtyEl) {
                            qtyEl.textContent = String(json.quantity);
                            // If quantity has dropped to 0, keep badge but mark as 0 and switch to obtained controls
                            if (json.quantity <= 0) {
                                // Move obtained badge into .mat-meta and show upload control in .material-photos
                                const meta = li.querySelector('.mat-meta');
                                if (meta) {
                                    const old = meta.querySelector('.badge.obtained'); if (old) old.remove();
                                    const span = document.createElement('span'); span.className = 'badge obtained'; span.innerHTML = '<i class="fas fa-check-circle"></i> Obtained'; meta.appendChild(span);
                                }
                                const actions = li.querySelector('.material-actions');
                                if (actions) actions.innerHTML = '';
                                // ensure mat-qty still reflects 0
                                if (qtyEl) qtyEl.textContent = '0';
                                const photos = li.querySelector('.material-photos');
                                if (photos && !photos.querySelector('.material-photo')) {
                                    // remove any old placeholder
                                    photos.querySelectorAll('.material-photo-placeholder').forEach(n=>n.remove());
                                    const metaEl = li.querySelector('.mat-meta');
                                    if (metaEl && !metaEl.querySelector('.upload-material-photo')) {
                                        const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'btn small upload-material-photo'; btn.setAttribute('data-material-id', mid); btn.title = 'Upload photo'; btn.innerHTML = '<i class="fas fa-camera"></i>';
                                        metaEl.appendChild(btn);
                                    }
                                }
                            }
                        }
                    }
                }
                showToast('Material Updated');
                return;
            }

        } catch (err){
            console.error(err);
            showToast('Network error', 'error');
        }
    });

    // Delegated click handler for delete buttons to ensure repeated deletes work reliably.
    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('button[name="remove_material"], button[aria-label="Delete material"]');
        if (!btn) return;
        // find the form (or create one) to extract material id
        const form = btn.closest('form') || btn.closest('.material-item').querySelector('form.inline-form[data-confirm]');
        if (!form) return;
        e.preventDefault();

        const ok = await showConfirm(form.dataset && form.dataset.confirm ? form.dataset.confirm : 'Remove this material?');
        if (!ok) return;

        // Build FormData and send AJAX removal
        const fd = new FormData();
        const mid = form.querySelector('input[name="material_id"]') ? form.querySelector('input[name="material_id"]').value : (btn.closest('.material-item') ? btn.closest('.material-item').dataset.materialId : null);
        if (!mid) return;
        fd.append('material_id', mid);
        fd.append('remove_material', '1');

        try {
            const res = await fetch('project_details.php?id=' + projectId, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const text = await res.text().catch(()=>null);
            let json = null;
            try {
                json = text ? JSON.parse(text) : null;
            } catch (err) {
                console.error('Invalid JSON response for remove_material:', text);
                showToast(text || 'Could not remove material', 'error');
                return;
            }
            if (!json || !json.success) { showToast(json && json.message ? json.message : 'Could not remove material', 'error'); return; }
            showToast('Material removed', 'success');
            const li = document.querySelector('.material-item[data-material-id="' + json.material_id + '"]');
            if (li) li.remove();
        } catch (err) {
            console.error(err);
            showToast('Network error while removing material', 'error');
        }
    });

    // small modal to ask user for obtained quantity
    // accepts optional maxQty to clamp input
    async function showObtainedModal(maxQty){
        return new Promise((resolve)=>{
            let m = document.getElementById('obtainedModal');
            if (m) m.remove(); // Remove any existing modal

            m = document.createElement('div');
            m.id = 'obtainedModal';
            m.className = 'modal';
            m.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="modal-title">Mark Material as Obtained</div>
                        <button type="button" class="close-modal" data-action="close-obtained">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p>Please enter how many units of this material you have on hand:</p>
                        <div class="form-group">
                            <label>Available Quantity</label>
                            <input type="number" id="obtQty" min="1" value="1" step="1" style="text-align:center; width:96px; padding:6px;">
                            <div id="obtMaxHint" style="font-size:12px;color:#666;margin-top:6px;display:none;"></div>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="action-btn" data-action="close-obtained">Cancel</button>
                        <button type="button" class="action-btn success-btn" data-action="have-all-obtained">Have All</button>
                        <button type="button" class="action-btn check-btn" data-action="confirm-obtained">Confirm</button>
                    </div>
                </div>`;
            document.body.appendChild(m);

            // Handle close button and cancel
            const handleClose = () => {
                m.classList.remove('active');
                setTimeout(() => m.remove(), 300);
                resolve(false);
            };

            m.querySelectorAll('[data-action="close-obtained"]').forEach(b => {
                b.addEventListener('click', handleClose);
            });

            const qInput = m.querySelector('#obtQty');
            const maxHint = m.querySelector('#obtMaxHint');
            if (typeof maxQty === 'number' && isFinite(maxQty)) {
                qInput.max = String(maxQty);
                if (maxHint) { maxHint.style.display = 'block'; maxHint.textContent = 'Max available: ' + String(maxQty); }
                // Ensure default value is within range
                if (parseInt(qInput.value || '0', 10) > maxQty) qInput.value = String(maxQty);
            }

            // Clamp input on typing and prevent decimals
            qInput.addEventListener('input', function(){
                // remove non-digits
                let v = qInput.value.replace(/[^0-9]/g, '');
                if (v === '') { qInput.value = ''; return; }
                let n = parseInt(v, 10) || 0;
                if (n < 1) n = 1;
                if (typeof maxQty === 'number' && isFinite(maxQty) && n > maxQty) n = maxQty;
                qInput.value = String(n);
            });

            // Ensure direction is LTR and caret is positioned at the end on focus
            qInput.dir = 'ltr';
            qInput.addEventListener('focus', function(){
                try { const len = String(qInput.value || '').length; qInput.setSelectionRange(len, len); } catch(e){}
            });

            // Handle Have All button
            const haveAllBtn = m.querySelector('[data-action="have-all-obtained"]');
            haveAllBtn.addEventListener('click', () => {
                const materialItem = document.querySelector('.material-item[data-obtaining="1"]');
                let total = null;
                if (typeof maxQty === 'number' && isFinite(maxQty)) total = maxQty;
                if (!total && materialItem) {
                    const qtyEl = materialItem.querySelector('.mat-qty');
                    if (qtyEl) {
                        const match = String(qtyEl.textContent).match(/\d+/);
                        if (match) total = parseInt(match[0], 10);
                    }
                }
                if (total) {
                    qInput.value = String(total);
                    qInput.focus();
                    try { const l = String(qInput.value).length; qInput.setSelectionRange(l, l); } catch(e){}
                }
            });

            // Handle confirm button
            const confirmBtn = m.querySelector('[data-action="confirm-obtained"]');
            confirmBtn.addEventListener('click', () => {
                let q = parseInt(qInput.value || '0', 10) || 0;
                if (q <= 0) {
                    alert('Please enter a valid quantity greater than 0');
                    return;
                }
                if (typeof maxQty === 'number' && isFinite(maxQty) && q > maxQty) q = maxQty;
                m.classList.remove('active');
                setTimeout(() => m.remove(), 300);
                resolve({ confirmed: true, qty: q });
            });

            // Show modal and focus input
            requestAnimationFrame(() => {
                m.classList.add('active');
                if (qInput) {
                    qInput.focus();
                    // caret centered due to text-align:center above
                }
            });

            // Handle escape key
            document.addEventListener('keydown', function handler(e) {
                if (e.key === 'Escape') {
                    handleClose();
                    document.removeEventListener('keydown', handler);
                }
            });
        });
    }

    // Intercept add-material form submit (handle Enter key inside modal)
    document.addEventListener('submit', async function(e){
        const form = e.target;
        if (!form.closest || !form.closest('#addMaterialModal')) return;
        e.preventDefault();
        const fd = new FormData(form);
        // include submitter if present
        let submitter = e.submitter || document.activeElement;
        if (submitter && submitter.form === form && submitter.name) {
            if (!fd.has(submitter.name)) fd.append(submitter.name, submitter.value || '1');
        }
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        try {
            const res = await fetch('project_details.php?id=' + projectId, { method: 'POST', body: fd, headers });
            const json = await res.json();
            if (!json || !json.success) { showToast(json && json.message ? json.message : 'Could not add material', 'error'); return; }

            const mat = json.material;
            const ul = document.querySelector('.materials-list-stage');
            if (ul) {
                const li = document.createElement('li');
                li.className = 'material-item';
                li.setAttribute('data-material-id', mat.material_id);
                li.style.height = '0px';
                li.style.opacity = '0';
                li.style.transform = 'translateY(-6px)';
                const mainContent = document.createElement('div');
                mainContent.className = 'material-main';
                // Always include quantity (default to 0)
                mainContent.innerHTML = `<span class="mat-name">${mat.material_name}</span><span class="mat-qty">${typeof mat.quantity !== 'undefined' ? mat.quantity : 0}</span>`;
                li.appendChild(mainContent);
                const photosDiv = document.createElement('div');
                photosDiv.className = 'material-photos';
                photosDiv.setAttribute('data-material-id', mat.material_id);
                li.appendChild(photosDiv);
                const actionsContent = document.createElement('div');
                actionsContent.className = 'material-actions';
                // default to needed status for newly added materials; server may return status in mat.status
                const statusNow = mat.status && mat.status !== '' ? mat.status : 'needed';
                if (statusNow === 'needed') {
                    actionsContent.innerHTML = `
                        <a href="browse.php?query=${encodeURIComponent(mat.material_name)}&from_project=${projectId}" class="btn small find-donations-btn">Find Donations</a>
                        <form method="POST" class="inline-form" data-obtain-modal="1" action="project_details.php?id=${projectId}">
                            <input type="hidden" name="material_id" value="${mat.material_id}">
                            <input type="hidden" name="status" value="obtained">
                            <button type="submit" name="update_material_status" class="btn small" aria-label="Mark material obtained">
                                <i class="fas fa-check" aria-hidden="true"></i>
                            </button>
                        </form>
                        <form method="POST" class="inline-form" data-confirm="Are you sure you want to remove this material?" action="project_details.php?id=${projectId}">
                            <input type="hidden" name="material_id" value="${mat.material_id}">
                            <button type="submit" name="remove_material" class="btn small danger" aria-label="Delete material">
                                <i class="fas fa-trash" aria-hidden="true"></i>
                            </button>
                        </form>
                    `;
                } else {
                    actionsContent.innerHTML = `
                        <span class="badge obtained" aria-hidden="true"><i class="fas fa-check-circle"></i> Obtained</span>
                        <button type="button" class="btn small upload-material-photo" data-material-id="${mat.material_id}" title="Upload photo"><i class="fas fa-camera"></i></button>
                    `;
                }
                li.appendChild(actionsContent);
                ul.appendChild(li);
                requestAnimationFrame(()=>{
                    const target = li.scrollHeight;
                    li.style.transition = 'height .22s ease, opacity .18s ease, transform .18s ease';
                    li.style.height = target + 'px';
                    li.style.opacity = '1';
                    li.style.transform = 'translateY(0)';
                    li.addEventListener('transitionend', function te(ev){
                        if (ev.propertyName === 'height') {
                            li.style.height = '';
                            li.removeEventListener('transitionend', te);
                        }
                    });
                });
            }
            const modal = document.getElementById('addMaterialModal');
            if (modal) {
                const f = modal.querySelector('form'); if (f) f.reset();
                modal.classList.remove('active');
            }
            showToast('Material added');
        } catch (err) { console.error(err); showToast('Network error', 'error'); }
    });

    // Intercept add-material modal submission
    document.addEventListener('click', function(e){
        const btn = e.target.closest('#addMaterialModal button[name="add_material"]');
        if (!btn) return;
        e.preventDefault();
        const modal = document.getElementById('addMaterialModal');
        if (!modal) return;
        const form = modal.querySelector('form');
        if (!form) return;

        (async function(){
            const fd = new FormData(form);
            // Ensure server sees the action key
            if (!fd.has('add_material')) fd.append('add_material', '1');
            const headers = { 'X-Requested-With': 'XMLHttpRequest' };
            try {
                const res = await fetch('project_details.php?id=' + projectId, { method: 'POST', body: fd, headers });
                const json = await res.json();
                if (!json || !json.success) { showToast(json.message || 'Could not add material', 'error'); return; }

                const mat = json.material;
                // append to list
                const ul = document.querySelector('.materials-list-stage');
                    if (ul) {
                        const li = document.createElement('li');
                        li.className = 'material-item';
                        li.setAttribute('data-material-id', mat.material_id);
                        // Build consistent structure: main, actions, photos
                        li.innerHTML = `<div class="material-main"><span class="mat-name">${mat.material_name}</span>${mat.quantity ? '<span class="mat-qty">' + mat.quantity + '</span>' : '<span class="mat-qty">0</span>'}</div>`;
                        // actions: default to needed unless server returned status
                        const initialStatus = mat.status && mat.status !== '' ? mat.status : 'needed';
                        if (initialStatus === 'needed') {
                            li.innerHTML += `
                                <div class="material-actions">
                                    <a href="browse.php?query=${encodeURIComponent(mat.material_name)}&from_project=${projectId}" class="btn small find-donations-btn">Find Donations</a>
                                    <form method="POST" class="inline-form" data-obtain-modal="1" action="project_details.php?id=${projectId}">
                                        <input type="hidden" name="material_id" value="${mat.material_id}">
                                        <input type="hidden" name="status" value="obtained">
                                        <button type="submit" name="update_material_status" class="btn small" aria-label="Mark material obtained"><i class="fas fa-check" aria-hidden="true"></i></button>
                                    </form>
                                    <form method="POST" class="inline-form" data-confirm="Remove this material?" action="project_details.php?id=${projectId}">
                                        <input type="hidden" name="material_id" value="${mat.material_id}">
                                        <button type="submit" name="remove_material" class="btn small danger" aria-label="Delete material"><i class="fas fa-trash" aria-hidden="true"></i></button>
                                    </form>
                                </div>
                            `;
                        } else {
                            li.innerHTML += `
                                <div class="material-actions">
                                    <span class="badge obtained" aria-hidden="true"><i class="fas fa-check-circle"></i> Obtained</span>
                                    <button type="button" class="btn small upload-material-photo" data-material-id="${mat.material_id}" title="Upload photo"><i class="fas fa-camera"></i></button>
                                </div>
                            `;
                        }
                        li.innerHTML += `<div class="material-photos" data-material-id="${mat.material_id}"></div>`;
                        ul.appendChild(li);
                        requestAnimationFrame(()=> li.classList.remove('material-enter'));
                    }
                // reset and close modal
                const f = modal.querySelector('form'); if (f) f.reset();
                modal.classList.remove('active');
                showToast('Material added');
            } catch (err) { console.error(err); showToast('Network error', 'error'); }
        })();
    });

    // Delegated handler: upload photo for a specific material
    document.addEventListener('click', function(e){
        const btn = e.target.closest('.upload-material-photo');
        if (!btn) return;
        const mid = btn.dataset.materialId;
        if (!mid) return;
        // create invisible file input
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.onchange = async function(ev){
            const file = ev.target.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('material_id', mid);
            fd.append('photo', file);
            try {
                const res = await fetch('upload_material_photo.php', { method: 'POST', body: fd });
                const txt = await res.text();
                let json = null;
                try { json = txt ? JSON.parse(txt) : null; } catch(e){ console.error('Invalid JSON from upload', txt); showToast('Upload failed', 'error'); return; }
                if (json && json.success) {
                    showToast('Photo uploaded');
                    // Insert thumbnail into material's photo container
                    try {
                        const photos = document.querySelector('.material-photos[data-material-id="' + mid + '"]');
                        if (photos) {
                            // Remove any existing thumbnail (one photo per material)
                            photos.querySelectorAll('.material-photo').forEach(n=>n.remove());
                            const div = document.createElement('div');
                            div.className = 'material-photo';
                            const src = json.path && json.path.indexOf('assets/') === 0 ? json.path : ('assets/uploads/materials/' + json.path);
                            // store photo id on container for deletion
                            div.dataset.photoId = json.id || '';
                            div.innerHTML = `<img src="${src}" alt="Material photo"><button type="button" class="material-photo-delete" title="Delete photo"><i class="fas fa-trash"></i></button>`;
                            const img = div.querySelector('img');
                            if (img) img.addEventListener('click', ()=> openImageViewer(src));
                            photos.insertBefore(div, photos.firstChild);
                            // Remove any upload button in the meta area
                            const meta = document.querySelector('.material-item[data-material-id="' + mid + '"] .mat-meta');
                            if (meta) {
                                const up = meta.querySelector('.upload-material-photo'); if (up) up.remove();
                            }
                        }
                    } catch (e) { console.error('Failed to insert thumbnail', e); }
                } else {
                    showToast(json && json.message ? json.message : 'Upload failed', 'error');
                }
            } catch (err) { console.error(err); showToast('Upload failed', 'error'); }
        };
        input.click();
    });

    // Delegated handler for deleting a material photo (overlay delete button)
    document.addEventListener('click', async function(ev){
        const del = ev.target.closest('.material-photo-delete');
        if (!del) return;
        const wrapper = del.closest('.material-photo');
        if (!wrapper) return;
        const photoId = wrapper.dataset.photoId || null;
        if (!photoId) {
            wrapper.remove();
            return;
        }
        if (!confirm('Remove this photo?')) return;
        try {
            const fd = new FormData(); fd.append('photo_id', photoId);
            const res = await fetch('delete_material_photo.php', { method: 'POST', body: fd });
            const txt = await res.text();
            let json = null; try { json = txt ? JSON.parse(txt) : null; } catch(e){ console.error('Invalid JSON from delete', txt); alert('Delete failed'); return; }
            if (json && json.success) {
                wrapper.remove();
                showToast('Photo removed');
                // After removing photo, restore camera button beside Obtained badge if present
                try {
                    const mat = document.querySelector('.material-item[data-material-id="' + (json.material_id || '') + '"]');
                    // if server didn't return material_id, try to find parent material via DOM
                    const parentMat = mat || del.closest('.material-item');
                    if (parentMat) {
                        const photos = parentMat.querySelector('.material-photos');
                        const meta = parentMat.querySelector('.mat-meta');
                        const hasPhoto = photos && photos.querySelector('.material-photo');
                        if (meta && !hasPhoto && !meta.querySelector('.upload-material-photo')) {
                            const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'btn small upload-material-photo'; btn.setAttribute('data-material-id', parentMat.dataset.materialId || ''); btn.title = 'Upload photo'; btn.innerHTML = '<i class="fas fa-camera"></i>';
                            meta.appendChild(btn);
                        }
                    }
                } catch(e) { console.error('Failed to restore camera button', e); }
            } else {
                alert(json && json.message ? json.message : 'Delete failed');
            }
        } catch (err) { console.error(err); alert('Delete failed'); }
    });

    // expose modal opener globally so inline onclick="showAddMaterialModal()" works
    window.showAddMaterialModal = showAddMaterialModal;

    // Reconcile donations feature removed from UI.

})();
</script>

<style>
/* Enhanced material photos styling */
.material-photos {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    flex-wrap: wrap;
}

.material-photo {
    position: relative;
    width: 60px;
    height: 60px;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
}

.material-photo:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.material-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.material-photo-delete {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: rgba(231, 76, 60, 0.9);
    color: white;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 10px;
    opacity: 0;
    transition: all 0.2s ease;
}

.material-photo:hover .material-photo-delete {
    opacity: 1;
}

.material-photo-delete:hover {
    background: var(--danger);
    transform: scale(1.1);
}

/* Hide zero quantity */
.mat-qty:empty,
.mat-qty[data-qty="0"] {
    display: none;
}
</style>

            <!-- materials are shown inside the Material Collection stage now -->

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

                    // Defensive deduplication: ensure unique stage names (preserve original order)
                    if (!empty($workflow_stages)) {
                        $seen = [];
                        $filtered = [];
                        foreach ($workflow_stages as $st) {
                            $name = trim(strtolower($st['name'] ?? ''));
                            if ($name === '') continue;
                            if (isset($seen[$name])) continue; // skip duplicates
                            $seen[$name] = true;
                            $filtered[] = $st;
                        }
                        // renumber sequentially starting at 1
                        foreach ($filtered as $i => &$fs) { $fs['number'] = $i + 1; }
                        unset($fs);
                        $workflow_stages = array_values($filtered);
                    }

                if (empty($workflow_stages)) {
                    $workflow_stages = [
                        ['name' => 'Preparation', 'description' => 'Collect materials, clean and sort materials, prepare workspace', 'number' => 1],
                        ['name' => 'Creation', 'description' => 'Build your project, follow safety guidelines, document progress', 'number' => 2],
                    ];
                }

                // Icon map by stage_number (fallback)
                $stage_icons = [1 => 'fa-lightbulb', 2 => 'fa-box', 3 => 'fa-hammer', 4 => 'fa-paint-roller', 5 => 'fa-star', 6 => 'fa-camera'];

                // Get completed stages from database
                $completed_stages = 0;
        $total_stages = count($workflow_stages);

                // Build a completed-stage map from the DB (use GROUP BY to coalesce duplicates)
                // Map database stage_number values to the current workflow_stages indices so progress
                // remains correct even if template numbering changed.
                $completed_stage_map = [];
                try {
                    // Build a mapping of template stage_number => index in $workflow_stages
                    $numToIndex = [];
                    foreach ($workflow_stages as $i => $st) {
                        $num = isset($st['number']) ? (int)$st['number'] : ($i + 1);
                        $numToIndex[$num] = $i;
                    }

                    $stage_stmt = $conn->prepare(
                        "SELECT stage_number, MAX(completed_at) AS completed_at FROM project_stages WHERE project_id = ? GROUP BY stage_number"
                    );
                    if ($stage_stmt) {
                        $stage_stmt->bind_param("i", $project_id);
                        $stage_stmt->execute();
                        $stage_result = $stage_stmt->get_result();
                        while ($s = $stage_result->fetch_assoc()) {
                            $raw_num = (int)$s['stage_number'];
                            // Only map completed entries when the stage_number corresponds to one
                            // of the current workflow stages. Avoid a numeric fallback which can
                            // accidentally mark unrelated stages as completed.
                            if (!is_null($s['completed_at']) && isset($numToIndex[$raw_num])) {
                                $idx = $numToIndex[$raw_num];
                                $completed_stage_map[$idx] = $s['completed_at'];
                            } else {
                                // Unexpected stage_number for this project's workflow; skip it.
                                // Uncomment for debugging: error_log("Ignoring stage_number={$raw_num} for project={$project_id}");
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

                <?php
                // Debug panel: visible when ?debug=1 in the URL. Shows computed values used for progress.
                $is_debug = isset($_GET['debug']) && $_GET['debug'] === '1';
                // Ensure numToIndex exists for debug output
                $numToIndex = isset($numToIndex) ? $numToIndex : [];
                if ($is_debug):
                    $debug_payload = [
                        'total_stages' => $total_stages,
                        'completed_stages' => $completed_stages,
                        'current_stage_index' => $current_stage_index,
                        'completed_stage_map' => $completed_stage_map,
                        'numToIndex' => $numToIndex,
                        'workflow_stages' => $workflow_stages,
                    ];
                ?>
                <div id="devDebugPanel" style="position:fixed;right:12px;bottom:12px;z-index:2000;max-width:420px;max-height:60vh;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.08);font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#111;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <strong style="font-size:0.95rem;">DEBUG: progress values</strong>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <button id="devDebugClose" style="background:#f3f4f6;border:1px solid #e6e6e6;border-radius:6px;padding:4px 8px;cursor:pointer">Close</button>
                            <button id="devDebugCopy" style="background:#2e8b57;color:#fff;border:none;border-radius:6px;padding:4px 8px;cursor:pointer">Copy</button>
                        </div>
                    </div>
                    <pre id="devDebugPre" style="white-space:pre-wrap;margin:0;"><?php echo htmlspecialchars(json_encode($debug_payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></pre>
                </div>
                <script>
                (function(){
                    const closeBtn = document.getElementById('devDebugClose');
                    const copyBtn = document.getElementById('devDebugCopy');
                    const pre = document.getElementById('devDebugPre');
                    if (closeBtn) closeBtn.addEventListener('click', function(){ document.getElementById('devDebugPanel').style.display = 'none'; });
                    if (copyBtn && pre) copyBtn.addEventListener('click', function(){ navigator.clipboard && navigator.clipboard.writeText(pre.textContent).then(()=>{ copyBtn.textContent='Copied'; setTimeout(()=> copyBtn.textContent='Copy',800); }).catch(()=>{ alert('Copy failed'); }); });
                })();
                </script>
                <?php endif; ?>

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

                <div class="workflow-stages-container stages-timeline">
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
                        <div class="workflow-stage stage-card <?= $stage_class ?>">
                                <?php $icon = $stage_icons[$stage['number']] ?? 'fa-circle'; ?>
                                <i class="fas <?= $icon ?> stage-icon" aria-hidden="true"></i>
                                <div class="stage-content">
                                    <div class="stage-header">
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

                            <?php // Inject materials list into Material Collection stage (read-only) ?>
                            <?php if (strtolower(trim($stage['name'] ?? '')) === 'material collection'): ?>
    <div class="stage-materials">
        <h4>Materials Needed</h4>
        <?php if (empty($materials)): ?>
            <p class="empty-state">No materials listed.</p>
        <?php else: ?>
            <ul class="materials-list-stage">
                <?php foreach ($materials as $m): ?>
                    <?php $mid = (int)($m['material_id'] ?? $m['id'] ?? 0); ?>
                    <?php 
                        $currentQty = isset($m['quantity']) ? (int)$m['quantity'] : 0;
                        $currentStatus = strtolower($m['status'] ?? '');
                        // If quantity is zero or less, treat as obtained even if DB status wasn't updated yet
                        if ($currentQty <= 0) { $currentStatus = 'obtained'; }
                        if ($currentStatus === '') $currentStatus = 'needed';

                        // Pre-check for an existing photo (only need the most recent)
                        $hasPhoto = false; $firstPhotoRel = null; $firstPhotoId = null;
                        try {
                            $pp = $conn->prepare("SELECT id, photo_path FROM material_photos WHERE material_id = ? ORDER BY uploaded_at DESC LIMIT 1");
                            if ($pp) {
                                $pp->bind_param('i', $mid);
                                $pp->execute();
                                $pres = $pp->get_result();
                                if ($prow = $pres->fetch_assoc()) {
                                    $hasPhoto = true;
                                    $firstPhotoRel = htmlspecialchars($prow['photo_path']);
                                    $firstPhotoId = (int)$prow['id'];
                                }
                            }
                        } catch (Exception $e) { /* ignore */ }
                    ?>
                    <li class="material-item<?= ($currentStatus !== 'needed') ? ' material-obtained' : '' ?>" data-material-id="<?= $mid ?>">
                        <div class="material-main">
                            <span class="mat-name"><?= htmlspecialchars($m['material_name'] ?? $m['name'] ?? '') ?></span>
                            <div class="mat-meta">
                                <?php if ($currentQty > 0): ?>
                                    <span class="mat-qty"><?= htmlspecialchars($currentQty) ?></span>
                                <?php endif; ?>
                                <?php if ($currentStatus !== 'needed' && $currentStatus !== ''): ?>
                                    <span class="badge obtained" aria-hidden="true"><i class="fas fa-check-circle"></i> Obtained</span>
                                    <?php if (!$hasPhoto): ?>
                                        <button type="button" class="btn small upload-material-photo" data-material-id="<?= $mid ?>" title="Upload photo"><i class="fas fa-camera"></i></button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Photos for obtained materials will be shown here (thumbnails go below the row) -->
                        <?php if ($currentStatus !== 'needed' && $currentStatus !== ''): ?>
                            <div class="material-photos" data-material-id="<?= $mid ?>">
                                <?php
                                    if ($hasPhoto) {
                                        echo '<div class="material-photo" data-photo-id="' . $firstPhotoId . '"><img src="' . $firstPhotoRel . '" alt="Material photo" onclick="openImageViewer(\'' . $firstPhotoRel . '\')"><button type="button" class="material-photo-delete" title="Delete photo"><i class="fas fa-trash"></i></button></div>';
                                    } else {
                                        // For obtained materials without a photo, render a small placeholder
                                        echo '<div class="material-photo placeholder" aria-hidden="true">No photo</div>';
                                    }
                                ?>
                            </div>
                        <?php endif; ?>
                        <div class="material-actions">
                            <?php if ($currentStatus === 'needed' || $currentStatus === ''): ?>
                                <!-- Find Donations (no icon) -->
                                <a class="btn small find-donations-btn" href="browse.php?query=<?= urlencode($m['material_name'] ?? $m['name'] ?? '') ?>&from_project=<?= $project_id ?>" title="Find donations for this material">
                                    Find Donations
                                </a>
                                <form method="POST" class="inline-form" data-obtain-modal="1" style="display:inline-flex;align-items:center;">
                                    <input type="hidden" name="material_id" value="<?= $mid ?>">
                                    <input type="hidden" name="status" value="obtained">
                                    <button type="submit" name="update_material_status" class="btn small obtain-btn" title="Mark obtained" aria-label="Mark material obtained">
                                        <i class="fas fa-check" aria-hidden="true"></i>
                                    </button>
                                </form>
                                <!-- Remove material form (trash icon only) -->
                                <form method="POST" class="inline-form" data-confirm="Are you sure you want to remove this material?">
                                    <input type="hidden" name="material_id" value="<?= $mid ?>">
                                    <button type="submit" name="remove_material" class="btn small danger" title="Delete">
                                        <i class="fas fa-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <!-- Obtained: actions removed; upload rendered below in .material-photos -->
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

                                            <?php if (!$is_locked): ?>
                                            <div class="stage-actions">
                                                <?php if (strtolower(trim($stage['name'] ?? '')) === 'material collection'): ?>
                                                    <button type="button" class="btn add-material-btn" onclick="showAddMaterialModal()">
                                                        <i class="fas fa-plus"></i> Add Material
                                                    </button>
                                <?php endif; ?>

                                <!-- Stage-level upload removed; per-material upload button remains beside Obtained badge -->
                                <?php
                                    // Determine whether this stage can be completed: it must not be locked (i.e., earlier stages done)
                                    $can_attempt = !$is_locked || $is_current || $is_completed;
                                    // For Material Collection, compute whether requirements are satisfied (all materials obtained or with photos)
                                    $req_ok = true;
                                    if (stripos($stage['name'], 'material') !== false) {
                                        try {
                                            $totStmt = $conn->prepare("SELECT COUNT(*) AS total FROM project_materials WHERE project_id = ?");
                                            $totStmt->bind_param('i', $project_id);
                                            $totStmt->execute();
                                            $tot = (int)$totStmt->get_result()->fetch_assoc()['total'];

                                            $haveStmt = $conn->prepare("SELECT COUNT(*) AS have FROM project_materials pm WHERE pm.project_id = ? AND (LOWER(pm.status) = 'obtained' OR EXISTS(SELECT 1 FROM material_photos mp WHERE mp.material_id = pm.material_id LIMIT 1))");
                                            $haveStmt->bind_param('i', $project_id);
                                            $haveStmt->execute();
                                            $have = (int)$haveStmt->get_result()->fetch_assoc()['have'];

                                            if ($tot > 0 && $have < $tot) $req_ok = false;
                                        } catch (Exception $e) { /* ignore and allow */ }
                                    }

                                    // The complete button is visible for all non-locked stages; if locked, show disabled with tooltip
                                    $btn_classes = 'complete-stage-btn';
                                    $btn_disabled = false;
                                    $btn_title = '';
                                    if ($is_locked && !$is_completed) {
                                        $btn_disabled = true;
                                        $btn_title = 'Locked: complete previous stages first';
                                    } elseif (!$req_ok && !$is_completed) {
                                        $btn_disabled = true;
                                        $btn_title = 'Requirements not met for this stage';
                                    }
                                ?>
                                <button class="<?= $btn_classes ?>" onclick="completeStage(event, <?= $stage['number'] ?>, <?= $project_id ?>)" <?= $btn_disabled ? 'disabled' : '' ?> title="<?= htmlspecialchars($btn_title) ?>" data-req-ok="<?= $req_ok ? '1' : '0' ?>" data-stage-number="<?= $stage['number'] ?>">
                                    <?php if ($is_completed): ?>
                                        <i class="fas fa-check"></i> Completed
                                    <?php else: ?>
                                        <i class="fas fa-check"></i> Mark as Complete
                                    <?php endif; ?>
                                </button>
                            </div>
                            <?php else: ?>
                                <div class="stage-locked-note" title="This stage is locked until previous stages are completed.">🔒 Stage locked — complete previous stage to unlock.</div>
                            <?php endif; ?>
                        </div> <!-- /.stage-content -->
                        </div> <!-- /.workflow-stage / .stage-card -->
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
    <script>
    function createEditProjectModal() {
        // Append the modal directly to body so it's outside any transformed parents
        if (document.getElementById('editProjectModal')) return document.getElementById('editProjectModal');

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
        document.body.appendChild(modal);

        // Wire up close buttons to toggle the active class
        modal.querySelectorAll('[data-action="close-edit"]').forEach(btn => {
            btn.addEventListener('click', function(ev) {
                ev.preventDefault();
                modal.classList.remove('active');
                document.body.style.overflow = '';
            });
        });

        // Close when clicking overlay (unless persistent)
        modal.addEventListener('click', function(e){
            if (e.target === modal && modal.dataset.persistent !== '1') {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
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
            modal.dataset.persistent = '0';
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
                btn.addEventListener('click', function(ev){ ev.preventDefault(); modal.classList.remove('active'); document.body.style.overflow = ''; });
            });
            // overlay click
            modal.addEventListener('click', function(e){ if (e.target === modal && modal.dataset.persistent !== '1'){ modal.classList.remove('active'); document.body.style.overflow = ''; } });
            return modal;
        }
    </script>
    <script>
    // helper to close any open modal to avoid stacked modals
    function closeAllModals(){
        document.querySelectorAll('.modal.active').forEach(m=>{ m.classList.remove('active'); });
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
    } catch (error) {
        console.error('Error:', error);
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

// Close image viewer on Escape key as well
document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape') {
        const v = document.querySelector('.image-viewer');
        if (v && v.style && v.style.display === 'flex') v.style.display = 'none';
    }
});

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
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        });
    }
    
    // Global click handler to ensure modals can be closed by explicit controls only
    document.addEventListener('click', function(e) {
        // If clicked on overlay (element with class 'modal'), only close if it's not the confirm modal
        const target = e.target;
        if (target.classList && target.classList.contains('modal')) {
            // keep persistent modals (like confirm) open until user explicit action
            if (target.dataset && target.dataset.persistent === '1') return;
            target.classList.remove('active');
            document.body.style.overflow = '';
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
// NOTE: removed previous unconditional hide of .toast-success so dynamic toasts
// created by showToast() aren't hidden prematurely.

// Auto-hide any server-rendered toasts after 3s but leave dynamic toasts to showToast handler
document.addEventListener('DOMContentLoaded', function(){
    // Clean up URL if it has success parameter
    if (window.history && window.history.replaceState) {
        const url = new URL(window.location.href);
        if (url.searchParams.has('success')) {
            url.searchParams.delete('success');
            window.history.replaceState({}, '', url.toString());
        }
    }

    // Move any server-rendered toasts into the top-center container used by showToast()
    let toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toastContainer';
        toastContainer.style.position = 'fixed';
        toastContainer.style.top = '18px';
        toastContainer.style.left = '50%';
        toastContainer.style.transform = 'translateX(-50%)';
        toastContainer.style.zIndex = '9999';
        toastContainer.style.display = 'flex';
        toastContainer.style.flexDirection = 'column';
        toastContainer.style.alignItems = 'center';
        toastContainer.style.gap = '8px';
        toastContainer.style.pointerEvents = 'none';
        document.body.appendChild(toastContainer);
    }

    document.querySelectorAll('.toast[data-server-toast]').forEach(function(node){
        // move node into container
        node.style.pointerEvents = 'auto';
        toastContainer.appendChild(node);
        // auto-hide after 3s
        setTimeout(function(){
            node.classList.remove('show');
            node.classList.add('hide');
            setTimeout(()=>{ try{ node.remove(); }catch(e){} }, 420);
        }, 3000);
        // ensure server toasts show immediately
        requestAnimationFrame(()=> node.classList.add('show'));
    });
});

// Function to handle stage completion
async function completeStage(event, stageNumber, projectId) {
    try {
        // event may be missing if invoked programmatically; guard accordingly
        const btn = event && event.currentTarget ? event.currentTarget : (event && event.target ? event.target : null);
        if (btn && btn.tagName === 'I') {
            // if the icon was clicked, move up to the parent button
            const parent = btn.closest('button');
            if (parent) btn = parent;
        }
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }
        
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