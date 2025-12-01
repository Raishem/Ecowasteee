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

    // Server-side reconciliation: ensure any project_stages marked completed still meet
    // the server-side requirements (avoid showing Completed when photos/materials missing)
    try {
        // Find template stage_numbers that correspond to Material Collection (name contains 'material')
        $matTplStmt = $conn->prepare("SELECT stage_number FROM stage_templates WHERE LOWER(stage_name) LIKE '%material%'");
        $matTplStmt->execute();
        $matTplRes = $matTplStmt->get_result();
        $materialTemplateNumbers = [];
        while ($r = $matTplRes->fetch_assoc()) { $materialTemplateNumbers[] = (int)$r['stage_number']; }

        if (!empty($materialTemplateNumbers)) {
            // For each material template stage, if project_stages indicates completed, re-validate
            $checkStmt = $conn->prepare("SELECT is_completed FROM project_stages WHERE project_id = ? AND stage_number = ? LIMIT 1");
            $totStmt = $conn->prepare("SELECT COUNT(*) AS tot FROM project_materials WHERE project_id = ?");
            $haveStmt = $conn->prepare("SELECT COUNT(*) AS have FROM project_materials pm WHERE pm.project_id = ? AND (LOWER(pm.status) = 'obtained' OR EXISTS(SELECT 1 FROM material_photos mp WHERE mp.material_id = pm.material_id LIMIT 1))");
            $unsetStmt = $conn->prepare("UPDATE project_stages SET is_completed = 0, completed_at = NULL WHERE project_id = ? AND stage_number = ?");

            foreach ($materialTemplateNumbers as $tplNum) {
                $checkStmt->bind_param('ii', $project_id, $tplNum);
                $checkStmt->execute();
                $cres = $checkStmt->get_result()->fetch_assoc();
                $already = ($cres && (int)$cres['is_completed'] === 1);
                if ($already) {
                    $totStmt->bind_param('i', $project_id); $totStmt->execute(); $tot = (int)$totStmt->get_result()->fetch_assoc()['tot'];
                    if ($tot > 0) {
                        $haveStmt->bind_param('i', $project_id); $haveStmt->execute(); $have = (int)$haveStmt->get_result()->fetch_assoc()['have'];
                        if ($have < $tot) {
                            // Unset completion so page renders as incomplete
                            $unsetStmt->bind_param('ii', $project_id, $tplNum);
                            $unsetStmt->execute();
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Non-fatal -- if reconciliation fails just continue and render existing DB state
    }

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
            // Update project details (minimal safe implementation)
            $name = isset($_POST['project_name']) ? trim($_POST['project_name']) : '';
            $description = isset($_POST['project_description']) ? trim($_POST['project_description']) : '';

            if ($name === '') {
                $error_message = "Project name is required.";
            } else {
                try {
                    $u = $conn->prepare("UPDATE projects SET project_name = ?, description = ? WHERE project_id = ? AND user_id = ?");
                    if ($u) {
                        $u->bind_param('ssii', $name, $description, $project_id, $_SESSION['user_id']);
                        $u->execute();
                        $success_message = 'Project updated successfully';
                        if ($is_ajax_request) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true]);
                            exit();
                        }
                        header("Location: project_details.php?id=$project_id&success=updated");
                        exit();
                    }
                } catch (Exception $e) {
                    $error_message = 'Failed to update project';
                }
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
                        // All materials obtained — inform client so it can request stage completion via complete_stage.php
                        // Do NOT auto-mark the stage here; that would bypass server-side checks (e.g., required photos).
                        $stage_completed = true;
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

// Get waste categories and subcategories
$waste_categories = [];
try {
    $categories_stmt = $conn->prepare("
        SELECT wc.category_id, wc.category_name, wc.parent_id, wc.description 
        FROM waste_categories wc 
        ORDER BY wc.parent_id IS NULL DESC, wc.category_name
    ");
    $categories_stmt->execute();
    $categories_result = $categories_stmt->get_result();
    
    while ($category = $categories_result->fetch_assoc()) {
        $waste_categories[] = $category;
    }
} catch (Exception $e) {
    // If table doesn't exist, use default categories
    $waste_categories = [];
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
    <link rel="stylesheet" href="assets/css/project-details-modern-v2.css">
    <link rel="stylesheet" href="assets/css/project-description.css">
    <link rel="stylesheet" href="assets/css/project-materials.css?v=4">
    <script src="assets/js/stage-completion.js?v=1" defer></script>
    <script>
    // Silence console output in production pages per user request, but allow console when debug=1
    // so developers can see errors during troubleshooting.
    (function(){
        try {
            var dbgFlag = /(?:\?|&|#)debug=1\b/.test((location && (location.search||'') + (location.hash||'')) || '');
            if (dbgFlag) return; // keep console intact in debug mode
            if (typeof window !== 'undefined' && !window.__silentConsolePatchApplied) {
                ['log','debug','info','warn','error'].forEach(function(fn){
                    try { if (console && console[fn]) console[fn] = function(){}; } catch(e){}
                });
                window.__silentConsolePatchApplied = true;
            }
        } catch(e) { /* ignore */ }
    })();
    </script>
    
</head>
<body>
    <script>
    // Ensure server-rendered completed stages are shown as checked buttons on load
    document.addEventListener('DOMContentLoaded', function(){
        try {
            // Ensure completed stages/tabs don't expose actionable controls. We *do not*
            // render duplicate "Completed/Incomplete" labels in the stage action area;
            // the tab badge already shows the status below the step name and is the
            // single source of truth for step status.
            const completedStages = Array.from(document.querySelectorAll('.workflow-stage.completed, .stage-card.completed'));
            completedStages.forEach(stageEl => {
                try {
                    // remove any actionable buttons from the actions area
                    const actions = stageEl.querySelector('.stage-actions');
                    if (actions) {
                        actions.querySelectorAll('button, a').forEach(n=>{ try{ n.remove(); }catch(e){} });
                    }
                } catch(e) { /* ignore per-stage error */ }
            });
            // Sync top-level stage tabs/cards so they don't show actionable controls when completed
            try {
                const completedTabs = Array.from(document.querySelectorAll('.stage-tab.completed'));
                completedTabs.forEach(tab => {
                    try {
                        // ensure badge shows Completed and isn't interactive
                        const badge = tab.querySelector('.tab-badge');
                        if (badge) {
                            badge.classList.remove('active');
                            badge.classList.add('completed');
                            try { badge.textContent = 'Completed'; } catch(e){}
                        }
                        // if the tab contains a small action button, remove it
                        tab.querySelectorAll('button, a').forEach(n=>{ try{ n.remove(); }catch(e){} });
                    } catch(e) { /* ignore per-tab errors */ }
                });
            } catch(e) { /* ignore */ }
        } catch(e) { /* ignore */ }
    });
    // Also observe the document for buttons that may be inserted/replaced after load
    (function(){
        function replaceIfCompleted(btn){
            try {
                if (!btn || !btn.tagName) return;
                const tag = btn.tagName.toLowerCase();
                if (tag !== 'button' && tag !== 'a') return;
                const txt = (btn.textContent || '').toLowerCase();
                const html = (btn.innerHTML || '').toLowerCase();
                const looksCompleted = txt.indexOf('completed') !== -1 || html.indexOf('fa-check') !== -1;
                if (!looksCompleted) return false;
                const reqOk = (btn.dataset && btn.dataset.reqOk) ? btn.dataset.reqOk : null;
                const ariaDisabled = btn.getAttribute && btn.getAttribute('aria-disabled') === 'true';
                const isDisabled = !!btn.disabled || ariaDisabled;
                    const showStatus = (reqOk === '0' || isDisabled) ? 'incomplete' : 'completed';
                let sn = (btn.getAttribute('data-stage-number') || btn.getAttribute('data-stage-index'));
                sn = (sn !== null && sn !== undefined) ? parseInt(sn, 10) : NaN;
                    // Remove any actionable control that appears to be a completed marker.
                    // We intentionally do not render duplicate 'Incomplete' labels here; the
                    // tab badge under the step name is the visible status indicator.
                    try {
                        if (btn && btn.parentNode) {
                            withObserverDisabled(function(){ try { btn.parentNode.removeChild(btn); } catch(e){} }, 80);
                        }
                    } catch(e) { /* ignore */ }
                return true;
            } catch(e) { return false; }
        }

    // Guarded observer: ignore mutations we created ourselves (data-observer-ignore)
    // Keep references to observed root/options so we can disconnect/reconnect around programmatic DOM writes
    let __stageObserverRoot = document.body;
    const __stageObserverOptions = { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'data-stage-number', 'data-stage-index', 'aria-disabled'] };
    const observer = new MutationObserver(function(mutations){
            for (const m of mutations) {
                try {
                    // if the mutation target (or its ancestor) was marked as internal update, skip
                    if (m.target && m.target.closest && m.target.closest('[data-observer-ignore]')) continue;
                    if (m.addedNodes && m.addedNodes.length) {
                        m.addedNodes.forEach(n => {
                            try {
                                if (n.nodeType !== 1) return;
                                // skip nodes we flagged ourselves
                                if (n.hasAttribute && n.hasAttribute('data-observer-ignore')) return;
                                if (n.matches && (n.matches('button') || n.matches('a'))) replaceIfCompleted(n);
                                // also scan inside
                                const btns = n.querySelectorAll && n.querySelectorAll('.complete-stage-btn, button[data-stage-number], button');
                                if (btns && btns.length) btns.forEach(b => { if (!b.hasAttribute || !b.hasAttribute('data-observer-ignore')) replaceIfCompleted(b); });
                            } catch(e){}
                        });
                    }
                    if (m.type === 'attributes' && (m.target && (m.target.matches && m.target.matches('button')))) {
                        if (!m.target.hasAttribute || !m.target.hasAttribute('data-observer-ignore')) replaceIfCompleted(m.target);
                    }
                } catch(e) { /* ignore per-mutation errors */ }
            }
        });
        try { observer.observe(__stageObserverRoot, __stageObserverOptions); } catch(e){}

        // Helper to perform DOM writes without re-triggering this observer
        function withObserverDisabled(fn, timeout = 60){
            try {
                // disconnect the observer while we make DOM changes
                try { observer.disconnect(); } catch(e){}
                try { fn(); } catch(e){}
            } finally {
                // Reconnect after a short delay so other external mutations can be observed
                try { setTimeout(()=> { try { observer.observe(__stageObserverRoot, __stageObserverOptions); } catch(e){} }, timeout); } catch(e){}
            }
        }

        // also run a retry pass a short time after load to catch late scripts
        setTimeout(function(){
            try { document.querySelectorAll('.complete-stage-btn, button[data-stage-number], button').forEach(b => replaceIfCompleted(b)); } catch(e){}
        }, 350);
    })();
    </script>
    <!-- Header -->
    <header>
        <div class="logo-container">
            <div class="logo">
                <img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo">
            </div>
            <h1>EcoWaste</h1>
        </div>
        <!-- <div class="header-right">
            <div class="notifications-icon" id="headerNotifications" title="Notifications">
                <i class="fas fa-bell"></i>
                <span class="notification-badge" id="headerUnreadCount"></span>
                <div class="header-notifications-panel" id="headerNotificationsPanel" aria-hidden="true">
                     notifications loaded dynamically 
                </div>
            </div> -->

            <div class="user-profile" id="userProfile">
                <div class="profile-pic">
                    <?= strtoupper(substr(htmlspecialchars($user_data['first_name'] ?? 'U'), 0, 1)) ?>
                </div>
                <span class="profile-name"><?= htmlspecialchars($user_data['first_name'] ?? 'User') ?></span>
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
        </div>
    </header>

    <div class="container">
        <!-- Left Sidebar -->
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
        <main class="main-content project-details">

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['password_success'])): ?>
            <div class="alert alert-success" style="margin: 20px; padding: 15px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px;">
                <?php echo htmlspecialchars($_SESSION['password_success']); ?>
                <?php unset($_SESSION['password_success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['password_error'])): ?>
            <div class="alert alert-danger" style="margin: 20px; padding: 15px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px;">
                <?php echo htmlspecialchars($_SESSION['password_error']); ?>
                <?php unset($_SESSION['password_error']); ?>
            </div>
        <?php endif; ?>

        <!-- Include Settings Modal -->
    <?php include 'includes/settings_modal.php'; ?>


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
        <?php // Share button removed because project sharing is handled as a separate workflow step ?>
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
        try {
            // Create a clone to measure full height. Ensure the clone is not affected by
            // any CSS line-clamp or collapsed styles by forcing display and removing classes.
            const clone = el.cloneNode(true);
            clone.style.visibility = 'hidden';
            clone.style.position = 'absolute';
            clone.style.maxHeight = 'none';
            clone.style.display = 'block';
            clone.style.whiteSpace = 'normal';
            clone.style.wordBreak = 'break-word';

            // Remove any collapse/expanded classes that might exist on the element
            clone.classList.remove('collapsed');
            clone.classList.remove('expanded');
            clone.classList.remove('has-overflow');

            // Force computed width as an explicit pixel value so measurements are accurate
            const elStyle = getComputedStyle(el);
            const widthPx = el.getBoundingClientRect().width || parseFloat(elStyle.width) || 360;
            clone.style.width = widthPx + 'px';
            // Ensure line-height used for calculation is consistent with the real element
            clone.style.lineHeight = elStyle.lineHeight || '1.8';

            document.body.appendChild(clone);
            const fullHeight = clone.getBoundingClientRect().height;
            document.body.removeChild(clone);

            const lineHeight = parseFloat(elStyle.lineHeight) || 18;
            const maxAllowed = lineHeight * 5 + 1; // small tolerance
            return fullHeight > maxAllowed;
        } catch (e) {
            // If measurement fails, be conservative and treat as overflow
            return true;
        }
    }

    let overflow = contentOverflowsFiveLines(desc);
    // Fallback: if measurement didn't detect overflow but the text is very long,
    // assume overflow so the user can expand/collapse the long description.
        try {
        if (!overflow) {
            const txt = (desc.textContent || '').trim();
            // Be more permissive so shorter long descriptions still show the toggle
            if (txt.length > 200) overflow = true;
        }
    } catch(e) { /* ignore */ }

    // Additional runtime check: compare rendered scrollHeight/clientHeight as a final guard
    try {
        if (!overflow) {
            const scrollH = desc.scrollHeight || 0;
            const clientH = desc.clientHeight || 0;
            if (scrollH > clientH + 2) overflow = true;
        }
    } catch(e) { /* ignore */ }

    // Debug: optional console output when ?debug=1 present
    try {
        const dbg = /(?:\?|&|#)debug=1\b/.test((location && (location.search || '') + (location.hash || '')) || '');
        if (dbg || window.__ECOWASTE_DEBUG__) {
            try { console.debug('[see-more] overflow:', overflow, 'textLen:', (desc.textContent||'').length, 'scrollH:', desc.scrollHeight, 'clientH:', desc.clientHeight); } catch(e){}
        }
    } catch(e) {}

    if (overflow) {
        // ensure the toggle is visible as an inline control (avoid CSS collapse hiding it)
        toggle.style.display = 'inline-block';
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
        // expose globally so external scripts can call it
        try { window.showToast = showToast; } catch(e) {}
        return t;
    }

    // Helper: mark a stage as uncompleted in the UI (optimistic update)
    // Moved out of showToast so it is callable from add-material flows.
    function markStageUncompletedUI(templateNum){
        try {
            if (!templateNum && templateNum !== 0) return;
            const selBtn = '.complete-stage-btn[data-stage-number="' + templateNum + '"]';
            const selBtnAny = 'button[data-stage-number="' + templateNum + '"]';
            const btn = document.querySelector(selBtn) || document.querySelector(selBtnAny) || null;
            try {
                // Remove any actionable control for this stage — we don't render an
                // 'Incomplete' label in the actions area. The tab badge shows the
                // status below the step name instead.
                if (btn && btn.parentNode) {
                    withObserverDisabled(function(){ try { btn.parentNode.removeChild(btn); } catch(e){} }, 60);
                }
            } catch(e) { /* ignore */ }

            // Find the stage element: prefer closest stage, else map from tab data-stage-number -> data-stage-index
            let stageEl = null;
            if (btn) stageEl = btn.closest('.workflow-stage, .stage-card');

            if (!stageEl) {
                const tab = document.querySelector('.stage-tab[data-stage-number="' + templateNum + '"]');
                if (tab) {
                    const idx = tab.getAttribute('data-stage-index');
                    if (idx) stageEl = document.querySelector('.workflow-stage[data-stage-index="' + idx + '"]');
                }
            }

            // Fallback: try to find stage whose title contains 'material' (case-insensitive)
            if (!stageEl) {
                const candidates = Array.from(document.querySelectorAll('.workflow-stage, .stage-card'));
                for (let c of candidates) {
                    try {
                        const title = (c.querySelector('.stage-title') && c.querySelector('.stage-title').textContent) || '';
                        if (/material/i.test(title)) { stageEl = c; break; }
                    } catch(e) { /* ignore */ }
                }
            }

            if (stageEl) {
                stageEl.classList.remove('completed');
                stageEl.classList.add('current');
            }

            // We don't create actionable buttons — status is displayed as a label and
            // updates automatically based on requirements.

            // Update tab badge text/class if present (try both data-stage-number and mapping to index)
            let tabBadge = document.querySelector('.stage-tab[data-stage-number="' + templateNum + '"] .tab-badge');
            if (!tabBadge) {
                const tabByIdx = document.querySelector('.stage-tab[data-stage-index="' + templateNum + '"]');
                if (tabByIdx) tabBadge = tabByIdx.querySelector('.tab-badge');
            }
            if (!tabBadge && stageEl) {
                const idx = stageEl.getAttribute('data-stage-index');
                if (idx) {
                    const tab = document.querySelector('.stage-tab[data-stage-index="' + idx + '"]');
                    if (tab) tabBadge = tab.querySelector('.tab-badge');
                }
            }

            if (tabBadge) {
                tabBadge.classList.remove('completed');
                tabBadge.classList.add('active');
                try { tabBadge.textContent = 'Current'; } catch(e){}
            }
        } catch (err) { /* silent */ }
    }

    // Helper: parse displayed quantity format "X / Y" and return obtained, orig, remaining
    function parseMatQtyText(txt) {
        try {
            if (!txt) return null;
            const m = String(txt).match(/(\d+)\s*\/\s*(\d+)/);
            if (m && m.length >= 3) {
                const obtained = parseInt(m[1], 10);
                const orig = parseInt(m[2], 10);
                const remaining = (isNaN(orig) || isNaN(obtained)) ? null : Math.max(0, orig - obtained);
                return { obtained: obtained, orig: orig, remaining: remaining };
            }
            // Fallback: single number – treat as 'orig' and 0 obtained (legacy remaining/orig format)
            const s = String(txt).match(/-?\d+/);
            if (s) {
                const n = parseInt(s[0], 10);
                return { obtained: 0, orig: n, remaining: n };
            }
        } catch (e) { /* ignore parse errors */ }
        return null;
    }

    // Helper: compute the displayed obtained/orig string given server-provided remaining and optional original
    // Prefer data from server (origFromJson) -> existing element attribute -> existing displayed value -> sane fallback
    function computeDisplayedFromServer(remaining, origFromJson, node) {
        try {
            const rem = (typeof remaining !== 'undefined' && remaining !== null) ? (parseInt(remaining, 10) || 0) : 0;
            let origVal = null;
            if (typeof origFromJson !== 'undefined' && origFromJson !== null) {
                origVal = parseInt(origFromJson, 10);
            }
            // prefer existing DOM attribute when available
            if ((origVal === null || isNaN(origVal)) && node && node.getAttribute) {
                const nodeAttr = node.getAttribute('data-original-quantity');
                if (nodeAttr !== null && nodeAttr !== undefined && String(nodeAttr).trim() !== '') {
                    const n = parseInt(nodeAttr, 10);
                    if (!isNaN(n)) origVal = n;
                }
            }
            // fallback to parsing existing text if still unknown
            if ((origVal === null || isNaN(origVal)) && node && node.textContent) {
                const parsed = parseMatQtyText(String(node.textContent || ''));
                if (parsed && typeof parsed.orig === 'number') origVal = parsed.orig;
            }
            // final fallback
            if (origVal === null || isNaN(origVal)) origVal = rem || 1;

            const obtained = Math.max(0, origVal - rem);
            return { obtained: obtained, orig: origVal };
        } catch (e) { return { obtained: 0, orig: (typeof remaining !== 'undefined' && remaining !== null ? parseInt(remaining,10)||0 : 0) }; }
    }

    // Request server to toggle completion for a stage (calls complete_stage.php) and update UI based on response
    async function requestToggleStage(stageNumber, projectId, options = {}) {
        try {
            let body = 'stage_number=' + encodeURIComponent(stageNumber) + '&project_id=' + encodeURIComponent(projectId);
            if (options && options.force_uncomplete) body += '&force_uncomplete=1';
            // Debug: print request info when debug flag set
            try { const dbg = /(?:\?|&|#)debug=1\b/.test((location && (location.search || '') + (location.hash || '')) || ''); if (window.__ECOWASTE_DEBUG__ || dbg) console.debug('[requestToggleStage] body:', body); } catch(e){}
            const res = await fetch('complete_stage.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body
            });
            const data = await res.json().catch(()=>null);
            try { const dbg = /(?:\?|&|#)debug=1\b/.test((location && (location.search || '') + (location.hash || '')) || ''); if (window.__ECOWASTE_DEBUG__ || dbg) console.debug('[requestToggleStage] response:', data); } catch(e){}
            if (!data) return null;
            // If server marked as uncompleted, update UI
            if (data.success && data.action === 'uncompleted') {
                try {
                    if (typeof markStageUncompletedUI === 'function') markStageUncompletedUI(stageNumber);
                    else {
                        const btn = document.querySelector('.complete-stage-btn[data-stage-number="' + stageNumber + '"]') || document.querySelector('button[data-stage-number="' + stageNumber + '"]');
                        if (btn) { btn.innerHTML = '<i class="fas fa-undo"></i> Mark as Complete'; }
                    }
                } catch(e){}
            }
            // If server returned completed, ensure UI shows completed (use label renderer when available)
            if (data.success && data.action === 'completed') {
                try {
                    if (typeof renderStageStatusLabel === 'function') {
                        try { renderStageStatusLabel(stageNumber, 'completed'); }
                        catch(e) { /* fallback below */ }
                    } else {
                        const btn = document.querySelector('.complete-stage-btn[data-stage-number="' + stageNumber + '"]') || document.querySelector('button[data-stage-number="' + stageNumber + '"]');
                        if (btn) btn.innerHTML = '<i class="fas fa-check"></i> Completed!';
                    }
                    // Ensure classes for stage and tab are correct even if label renderer modified DOM
                    const stageEl = document.querySelector('.workflow-stage[data-stage-number="' + stageNumber + '"]') || document.querySelector('.workflow-stage[data-stage-index]');
                    if (stageEl) {
                        stageEl.classList.remove('current');
                        stageEl.classList.add('completed');
                        const idx = parseInt(stageEl.getAttribute('data-stage-index'), 10);
                        if (!isNaN(idx)) {
                            const tab = document.querySelector('.stage-tab[data-stage-index="' + idx + '"]');
                            if (tab) { tab.classList.remove('active'); tab.classList.add('completed'); }
                        }
                    }
                } catch(e){}
            }
            // If server returned a failure reason about missing materials/photos, show Incomplete state
            if (data && data.success === false && (data.reason === 'missing_materials' || data.reason === 'missing_after_photos' || data.reason === 'missing_stage_photos')) {
                try {
                    if (typeof renderStageStatusLabel === 'function') renderStageStatusLabel(stageNumber, 'incomplete');
                    else if (typeof markStageUncompletedUI === 'function') markStageUncompletedUI(stageNumber);
                    else {
                        const btn = document.querySelector('.complete-stage-btn[data-stage-number="' + stageNumber + '"]') || document.querySelector('button[data-stage-number="' + stageNumber + '"]');
                        if (btn) btn.innerHTML = '<i class="fas fa-undo"></i> Mark as Complete';
                    }
                } catch(e){}
            }
            return data;
        } catch (err) {
            return null;
        }
    }

    // Render non-clickable status label for a stage: 'completed' or 'incomplete'
    function renderStageStatusLabel(stageNumber, status) {
        try {
            // Instead of rendering a status label inside the stage actions area, we
            // keep the tab badge (below the step name) as the single visible status
            // indicator. Remove any actionable controls and update stage/tab classes.
            const btn = document.querySelector('.complete-stage-btn[data-stage-number="' + stageNumber + '"]') || document.querySelector('button[data-stage-number="' + stageNumber + '"]');
            try {
                if (btn && btn.parentNode) {
                    withObserverDisabled(function(){ try { btn.parentNode.removeChild(btn); } catch(e){} }, 60);
                }
            } catch(e) { /* ignore */ }

            let stageEl = null;
            if (btn) stageEl = btn.closest('.workflow-stage, .stage-card');
            if (!stageEl) stageEl = document.querySelector('.workflow-stage[data-stage-number="' + stageNumber + '"]') || document.querySelector('.workflow-stage[data-stage-index="' + stageNumber + '"]');
            if (!stageEl) return;

            if (status === 'completed') {
                stageEl.classList.remove('current');
                stageEl.classList.add('completed');
                // advance to next stage
                let next = stageEl.nextElementSibling;
                while (next && !next.classList.contains('workflow-stage')) next = next.nextElementSibling;
                if (next) {
                    next.classList.remove('locked');
                    next.classList.add('current');
                    const nextIdx = parseInt(next.getAttribute('data-stage-index'), 10);
                    if (typeof showStageByIndex === 'function') showStageByIndex(nextIdx);
                }
            } else {
                stageEl.classList.remove('completed');
                stageEl.classList.add('current');
            }

            // update tab badge
            const idx = stageEl.getAttribute('data-stage-index') || stageEl.getAttribute('data-stage-number');
            if (idx) {
                const tab = document.querySelector('.stage-tab[data-stage-index="' + idx + '"]') || document.querySelector('.stage-tab[data-stage-number="' + idx + '"]');
                if (tab) {
                    const badge = tab.querySelector('.tab-badge');
                    if (badge) {
                        if (status === 'completed') { badge.classList.remove('active'); badge.classList.add('completed'); badge.textContent = 'Completed'; }
                        else { badge.classList.remove('completed'); badge.classList.add('active'); badge.textContent = 'Current'; }
                    }
                }
            }
        } catch(e) { /* ignore */ }
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

    // Modal to update material quantity (mark as obtained with amount user has)
    function showMaterialHaveModal(materialId, maxQty) {
        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.style.position = 'fixed'; modal.style.top = '0'; modal.style.left = '0'; modal.style.width = '100%'; modal.style.height = '100%'; modal.style.backgroundColor = 'rgba(0,0,0,0.5)'; modal.style.display = 'flex'; modal.style.alignItems = 'center'; modal.style.justifyContent = 'center'; modal.style.zIndex = '10000';
            const content = document.createElement('div');
            content.style.backgroundColor = '#fff'; content.style.padding = '20px'; content.style.borderRadius = '8px'; content.style.maxWidth = '400px'; content.style.boxShadow = '0 8px 24px rgba(0,0,0,0.2)';
            content.innerHTML = `<h3 style="margin-top:0;">How much do you have?</h3><p style="color:#666;font-size:14px;">Enter the amount of this material you already have (max: ${maxQty || 'unlimited'})</p><div style="display:flex;gap:8px;margin:12px 0;"><input type="number" id="haveQty" min="1" ${maxQty ? `max="${maxQty}"` : ''} placeholder="Amount" style="flex:1;padding:8px;border:1px solid #ddd;border-radius:4px;"></div><div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;"><button id="haveCancel" style="padding:8px 16px;border:1px solid #ddd;border-radius:4px;background:#f3f4f6;cursor:pointer;">Cancel</button><button id="haveAll" style="padding:8px 16px;border:none;border-radius:4px;background:#2E8B57;color:#fff;cursor:pointer;">Have all</button><button id="haveSubmit" style="padding:8px 16px;border:none;border-radius:4px;background:#007bff;color:#fff;cursor:pointer;">Mark obtained</button></div>`;
            modal.appendChild(content);
            document.body.appendChild(modal);
            const input = content.querySelector('#haveQty');
            const submitBtn = content.querySelector('#haveSubmit');
            const haveAllBtn = content.querySelector('#haveAll');
            const cancelBtn = content.querySelector('#haveCancel');
            // Clamp on input and prevent non-numeric characters; enforce min/max while typing
            input.addEventListener('input', function(){
                try {
                    let v = String(input.value || '').replace(/[^0-9]/g, '');
                    if (v === '') { input.value = ''; return; }
                    let n = parseInt(v, 10) || 0;
                    if (n < 1) n = 1;
                    if (typeof maxQty === 'number' && isFinite(maxQty) && n > maxQty) n = maxQty;
                    input.value = String(n);
                } catch(e) {}
            });

            submitBtn.addEventListener('click', function() {
                let qty = parseInt(input.value || '1', 10);
                if (isNaN(qty) || qty < 1) { alert('Please enter a valid quantity'); return; }
                // If user supplied a value greater than the available quantity, clamp to max
                if (typeof maxQty === 'number' && isFinite(maxQty) && qty > maxQty) {
                    qty = maxQty;
                    // reflect the clamped value in the UI so users see it corrected
                    try { input.value = String(qty); } catch(e){}
                }
                modal.remove();
                resolve({ qty: qty });
            });
            haveAllBtn.addEventListener('click', function() {
                modal.remove();
                resolve({ qty: maxQty || 1 });
            });
            cancelBtn.addEventListener('click', function() { modal.remove(); resolve(null); });
            input.addEventListener('keydown', function(e) { if (e.key === 'Enter') submitBtn.click(); });
            input.focus();
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
                        const parsed = parseMatQtyText(String(qtyEl.textContent || ''));
                        if (parsed && typeof parsed.remaining === 'number') maxQty = parsed.remaining;
                    }
                    // Use the newer showMaterialHaveModal to collect quantity from user.
                    // Pass material id (if available) and maxQty for clamping.
                    const midForModal = materialItem.dataset.materialId || (materialItem.querySelector('input[name="material_id"]') ? materialItem.querySelector('input[name="material_id"]').value : null);
                    const result = await showMaterialHaveModal(midForModal, maxQty);
                    if (result && typeof result.qty !== 'undefined' && result !== null) {
                        const fd = new FormData(form);
                        // ensure server receives which action (submitter)
                        if (submitterName && !fd.has(submitterName)) fd.append(submitterName, submitterValue || '1');
                        fd.append('quantity', result.qty);
                        
                        // Ensure API action and project_id are present for the obtained flow
                        if (!fd.has('action')) fd.append('action', 'update_material_status');
                        if (!fd.has('project_id')) fd.append('project_id', projectId);
                        const response = await fetch('update_project.php', {
                            method: 'POST',
                            body: fd,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });

                        const respText = await response.text().catch(()=>null);
                        if (!response.ok) {
                            showToast(respText || 'Failed to update material status', 'error');
                            throw new Error('Network response was not ok');
                        }

                        let data = null;
                        try {
                            data = respText ? JSON.parse(respText) : null;
                        } catch (err) {
                            showToast(respText || 'Failed to update material status', 'error');
                            throw err;
                        }

                        if (data && data.success) {
                            showToast('Material Updated');
                            materialItem.classList.add('material-obtained');

                            // Update displayed quantity if server returned it
                                if (typeof data.quantity !== 'undefined' && data.quantity !== null) {
                                let qtyEl = materialItem.querySelector('.mat-qty');
                                const orig = (typeof data.original_quantity !== 'undefined' && data.original_quantity !== null) ? data.original_quantity : (materialItem.dataset.originalQuantity ? parseInt(materialItem.dataset.originalQuantity,10) : data.quantity);
                                // always show current / original so UX is clear
                                if (qtyEl) {
                                    // server returns remaining quantity in data.quantity; compute display with robust fallback
                                    const dd = computeDisplayedFromServer(data.quantity, (typeof data.original_quantity !== 'undefined' ? data.original_quantity : null), qtyEl);
                                    // only write when we have a sensible total; avoid writing 0/0 when server response is missing data
                                    if (typeof dd.orig === 'number' && dd.orig > 0) qtyEl.textContent = String(dd.obtained) + ' / ' + String(dd.orig);
                                    else console && console.warn && console.warn('Skipping invalid qty update (orig missing or zero)', dd, data, qtyEl);
                                } else {
                                    // insert a new quantity element next to the name
                                    const main = materialItem.querySelector('.material-main');
                                    if (main) {
                                        const span = document.createElement('span');
                                        span.className = 'mat-qty';
                                        const dd2 = computeDisplayedFromServer(data.quantity, (typeof data.original_quantity !== 'undefined' ? data.original_quantity : null), span);
                                        if (typeof dd2.orig === 'number' && dd2.orig > 0) span.textContent = String(dd2.obtained) + ' / ' + String(dd2.orig);
                                        else console && console.warn && console.warn('Skipping invalid qty insertion (orig missing or zero)', dd2, data, span);
                                        main.appendChild(span);
                                    }
                                }
                                // persist original quantity on the node if provided
                                try { if (typeof data.original_quantity !== 'undefined' && data.original_quantity !== null) materialItem.setAttribute('data-original-quantity', String(data.original_quantity)); } catch(e){}
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

                                        // Ensure a material-photos container exists (some server-rendered items may not have one)
                                        let photos = materialItem.querySelector('.material-photos');
                                        if (!photos) {
                                            const main = materialItem.querySelector('.material-main');
                                            photos = document.createElement('div');
                                            photos.className = 'material-photos';
                                            photos.setAttribute('data-material-id', mid);
                                            if (main && main.parentNode) main.parentNode.insertBefore(photos, main.nextSibling);
                                            else materialItem.appendChild(photos);
                                        }

                                        // Ensure upload camera button is shown beside the Obtained badge (in .mat-meta) if no photo exists
                                        const metaEl = materialItem.querySelector('.mat-meta');
                                        const hasPhotoNow = photos && photos.querySelector('.material-photo:not(.placeholder)');
                                        if (metaEl && !hasPhotoNow) {
                                            // remove any placeholder that might be under photos
                                            photos.querySelectorAll('.material-photo.placeholder').forEach(n=>n.remove());
                                            // avoid duplicate buttons
                                            if (!metaEl.querySelector('.upload-material-photo')) {
                                                const btn = document.createElement('button');
                                                btn.type = 'button'; btn.className = 'btn small upload-material-photo'; btn.setAttribute('data-material-id', mid);
                                                btn.title = 'Upload photo'; btn.setAttribute('aria-label', 'Upload material photo'); btn.innerHTML = '<i class="fas fa-camera"></i>';
                                                metaEl.appendChild(btn);
                                            }
                                        }

                                        // refresh stage completion button state (in case this change satisfies requirements)
                                        try { refreshMaterialCollectionReqState(); } catch(e){/* ignore */}
                                    } else {
                                        // restore basic buttons (attempt minimal update)
                                        const btnToggle = materialItem.querySelector('form button[name="update_material_status"]');
                                        if (btnToggle) btnToggle.innerHTML = (data.status === 'needed' ? '<i class="fas fa-check" aria-hidden="true"></i>' : '<i class="fas fa-undo" aria-hidden="true"></i>');
                                    }
                                }
                            }

                            // If server reports that the stage completed (all materials obtained), reload to reflect the next stage
                            if (data.stage_completed) {
                                // Instead of auto-completing, enable the Proceed button and instruct the user to confirm
                                showToast('All materials obtained — click Proceed to complete this stage');
                                setTimeout(()=>{
                                    try {
                                        const container = document.querySelector('.workflow-stages-container');
                                        let proceedFound = false;
                                        if (container) {
                                            // Find the stage that looks like Material Collection and enable its proceed button
                                            const candidateStages = container.querySelectorAll('.workflow-stage, .stage-card');
                                            candidateStages.forEach(st => {
                                                if (proceedFound) return;
                                                if (/material/i.test(st.textContent || '')) {
                                                    const p = st.querySelector('.proceed-stage-btn');
                                                    if (p) {
                                                        p.classList.remove('is-disabled'); p.removeAttribute('aria-disabled'); p.dataset.reqOk = '1'; proceedFound = true;
                                                    }
                                                }
                                            });
                                        }
                                        if (!proceedFound) {
                                            // Fallback: try to enable any present proceed buttons
                                            const any = document.querySelectorAll('.proceed-stage-btn');
                                            if (any && any.length) {
                                                any.forEach(p=>{ p.classList.remove('is-disabled'); p.removeAttribute('aria-disabled'); p.dataset.reqOk = '1'; });
                                                proceedFound = true;
                                            }
                                        }
                                        // final fallback: reload page to reconcile server state
                                        if (!proceedFound) location.reload();
                                    } catch (e) { location.reload(); }
                                }, 300);
                            }
                        } else {
                            showToast((data && (data.error || data.message)) || 'Failed to update material status', 'error');
                        }
                    }
                } catch (err) {
                    /* error suppressed in production */
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
            // ensure API action and project_id present for server
            if (fd.get('remove_material') !== null && !fd.has('action')) fd.append('action', 'remove_material');
            // Ensure update_material_status uses the API action expected by update_project.php
            if (fd.get('update_material_status') !== null && !fd.has('action')) fd.append('action', 'update_material_status');
            if (!fd.has('project_id')) fd.append('project_id', projectId);
            // use API endpoint that consistently returns JSON
            const res = await fetch('update_project.php', { method: 'POST', body: fd, headers });
            const text = await res.text().catch(()=>null);
            let json = null;
            try {
                json = text ? JSON.parse(text) : null;
            } catch (err) {
                /* invalid JSON response (silenced) */
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
                            // use original_quantity returned by server or fall back to element's data attribute
                            const origFromJson = (typeof json.original_quantity !== 'undefined' && json.original_quantity !== null) ? json.original_quantity : null;
                            const nodeOrig = li.getAttribute('data-original-quantity') || null;
                            const origVal = origFromJson !== null ? origFromJson : (nodeOrig ? parseInt(nodeOrig, 10) : json.quantity);
                            // json.quantity is remaining; display obtained = origVal - remaining
                            const ddJ = computeDisplayedFromServer(json.quantity, origFromJson, qtyEl);
                            if (typeof ddJ.orig === 'number' && ddJ.orig > 0) qtyEl.textContent = String(ddJ.obtained) + ' / ' + String(ddJ.orig);
                            else console && console.warn && console.warn('Skipping invalid qty update (orig missing or zero)', ddJ, json, li);
                            if (origFromJson !== null) try { li.setAttribute('data-original-quantity', String(origFromJson)); } catch(e){}
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
                                if (qtyEl) {
                                    // fully obtained -> show orig / orig
                                    qtyEl.textContent = String(origVal) + ' / ' + String(origVal);
                                }
                                const photos = li.querySelector('.material-photos');
                                if (photos && !photos.querySelector('.material-photo:not(.placeholder)')) {
                                    // remove any old placeholder
                                    photos.querySelectorAll('.material-photo.placeholder').forEach(n=>n.remove());
                                    const metaEl = li.querySelector('.mat-meta');
                                    if (metaEl && !metaEl.querySelector('.upload-material-photo')) {
                                        const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'btn small upload-material-photo'; btn.setAttribute('data-material-id', mid); btn.title = 'Upload photo'; btn.setAttribute('aria-label','Upload material photo'); btn.innerHTML = '<i class="fas fa-camera"></i>';
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
            /* suppressed error */
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
    // Ensure API action and project id are present for update_project.php
    if (!fd.has('action')) fd.append('action', 'remove_material');
    if (!fd.has('project_id')) fd.append('project_id', projectId);

        try {
            const res = await fetch('update_project.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const text = await res.text().catch(()=>null);
            let json = null;
            try {
                json = text ? JSON.parse(text) : null;
            } catch (err) {
                /* suppressed invalid JSON response */
                showToast(text || 'Could not remove material', 'error');
                return;
            }
            if (!json || !json.success) { showToast(json && json.message ? json.message : 'Could not remove material', 'error'); return; }
            showToast('Material removed', 'success');
            const li = document.querySelector('.material-item[data-material-id="' + json.material_id + '"]');
            if (li) {
                // Before removing, check if we need to restore the upload button for the next material (if any)
                const nextMaterial = li.nextElementSibling || li.previousElementSibling;
                li.remove();
                // If there is a next/prev material, only show upload button when that material is 'obtained'
                // (either marked obtained or quantity is 0). Avoid adding upload buttons for materials
                // that are still in 'needed' state.
                if (nextMaterial && nextMaterial.classList.contains('material-item')) {
                    const meta = nextMaterial.querySelector('.mat-meta');
                    const hasPhoto = nextMaterial.querySelector('.material-photos .material-photo');
                    // Determine obtained state from class or badge or quantity == 0
                    const hasObtainedBadge = !!nextMaterial.querySelector('.badge.obtained');
                    let qtyEl = nextMaterial.querySelector('.mat-qty');
                    let remainingVal = null;
                    if (qtyEl) {
                        try { const parsed = parseMatQtyText(String(qtyEl.textContent || '')); if (parsed && typeof parsed.remaining === 'number') remainingVal = parsed.remaining; } catch(e) { remainingVal = null; }
                    }
                    const isObtained = hasObtainedBadge || (remainingVal !== null && !isNaN(remainingVal) && remainingVal <= 0) || nextMaterial.classList.contains('material-obtained');
                    if (meta && !hasPhoto && isObtained && !meta.querySelector('.upload-material-photo')) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'btn small upload-material-photo';
                        btn.setAttribute('data-material-id', nextMaterial.dataset.materialId || '');
                        btn.title = 'Upload photo';
                        btn.setAttribute('aria-label', 'Upload material photo');
                        btn.innerHTML = '<i class="fas fa-camera"></i>';
                        meta.appendChild(btn);
                    }
                }
            }
            // Always refresh requirements state after removal
            try { refreshMaterialCollectionReqState(); } catch(e){}
        } catch (err) {
            /* suppressed error */
            showToast('Network error while removing material', 'error');
        }
    });

    // small modal to ask user for obtained quantity
    // accepts optional maxQty to clamp input
    async function showObtainedModal(maxQty){
        return new Promise((resolve)=>{
            // remove any existing modal
            let m = document.getElementById('obtainedModal');
            if (m) try { m.remove(); } catch(e){}

            // create modal skeleton
            m = document.createElement('div');
            m.id = 'obtainedModal';
            m.className = 'modal';
            m.dataset.persistent = '0';
            m.innerHTML = `
                <div class="modal-content" style="min-width:320px;">
                    <div class="modal-header">
                        <div class="modal-title">Enter quantity obtained</div>
                    </div>
                    <div class="modal-body" style="padding:12px 16px;">
                        <input id="obtQty" type="number" min="1" value="1" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;">
                        <div id="obtMaxHint" style="display:none;font-size:12px;color:#666;margin-top:8px"></div>
                    </div>
                    <div class="modal-actions" style="display:flex;justify-content:flex-end;gap:8px;padding:12px;">
                        <button type="button" data-action="have-all-obtained" class="btn">Have all</button>
                        <button type="button" data-action="cancel-obtained" class="btn">Cancel</button>
                        <button type="button" data-action="confirm-obtained" class="btn btn-primary">Confirm</button>
                    </div>
                </div>`;
            document.body.appendChild(m);

            const qInput = m.querySelector('#obtQty');
            const maxHint = m.querySelector('#obtMaxHint');
            const confirmBtn = m.querySelector('[data-action="confirm-obtained"]');
            const haveAllBtn = m.querySelector('[data-action="have-all-obtained"]');
            const cancelBtn = m.querySelector('[data-action="cancel-obtained"]');

            // apply max hint if provided
            if (typeof maxQty === 'number' && isFinite(maxQty)) {
                try { qInput.max = String(maxQty); } catch(e){}
                if (maxHint) { maxHint.style.display = 'block'; maxHint.textContent = 'Max available: ' + String(maxQty); }
                try { if (parseInt(qInput.value || '0', 10) > maxQty) qInput.value = String(maxQty); } catch(e){}
            }

            // clamp input and prevent decimals
            qInput.addEventListener('input', function(){
                try {
                    let v = String(qInput.value || '').replace(/[^0-9]/g, '');
                    if (v === '') { qInput.value = ''; return; }
                    let n = parseInt(v, 10) || 0;
                    if (n < 1) n = 1;
                    if (typeof maxQty === 'number' && isFinite(maxQty) && n > maxQty) n = maxQty;
                    qInput.value = String(n);
                } catch(e){}
            });

            haveAllBtn && haveAllBtn.addEventListener('click', function(){
                try {
                    let total = null;
                    if (typeof maxQty === 'number' && isFinite(maxQty)) total = maxQty;
                    const materialItem = document.querySelector('.material-item[data-obtaining="1"]');
                    if (!total && materialItem) {
                        const qtyEl = materialItem.querySelector('.mat-qty');
                        if (qtyEl) {
                            const parsed = parseMatQtyText(String(qtyEl.textContent || ''));
                            if (parsed && typeof parsed.remaining === 'number') total = parsed.remaining;
                        }
                    }
                    if (total) { qInput.value = String(total); qInput.focus(); try { const l = String(qInput.value || '').length; qInput.setSelectionRange(l,l); } catch(e){} }
                } catch(e){}
            });

            cancelBtn && cancelBtn.addEventListener('click', function(){
                try { m.classList.remove('active'); setTimeout(()=>{ try{ m.remove(); }catch(e){} },220); } catch(e){}
                resolve({ confirmed: false });
            });

            confirmBtn && confirmBtn.addEventListener('click', function(){
                try {
                    let q = parseInt(qInput.value || '0', 10) || 0;
                    if (q <= 0) { alert('Please enter a valid quantity greater than 0'); return; }
                    if (typeof maxQty === 'number' && isFinite(maxQty) && q > maxQty) q = maxQty;
                    m.classList.remove('active');
                    setTimeout(()=>{ try{ m.remove(); }catch(e){} }, 220);
                    resolve({ confirmed: true, qty: q });
                } catch(e) { resolve({ confirmed: false }); }
            });

            // show modal and focus
            requestAnimationFrame(()=>{ try{ m.classList.add('active'); qInput && qInput.focus(); } catch(e){} });

            // Escape handler
            function handleClose(){ try { if (m) { m.classList.remove('active'); setTimeout(()=>{ try{ m.remove(); }catch(e){} },220); } } catch(e){} }
            const keyHandler = function(e){ if (e.key === 'Escape') { handleClose(); document.removeEventListener('keydown', keyHandler); resolve({ confirmed: false }); } };
            document.addEventListener('keydown', keyHandler);
        });
    }

    // Intercept add-material form submit (handle Enter key inside modal)
    document.addEventListener('submit', async function(e){
        const form = e.target;
        if (!form.closest || !form.closest('#addMaterialModal')) return;
        e.preventDefault();
        const fd = new FormData(form);

        // Get the final material name (either from dropdown or custom input)
        let materialName = fd.get('material_name');
        const customName = fd.get('custom_material_name');

        if (customName && customName.trim() !== '') {
            materialName = customName.trim();
        }

        // Ensure we have a material name
        if (!materialName || materialName.trim() === '') {
            showToast('Please select or enter a material name', 'error');
            return;
        }

        // Update the form data with the final material name
        fd.set('material_name', materialName);

        // Remove the custom field as it's not needed by the server
        fd.delete('custom_material_name');
        fd.delete('material_category');

        // include submitter if present
        let submitter = e.submitter || document.activeElement;
        if (submitter && submitter.form === form && submitter.name) {
            if (!fd.has(submitter.name)) fd.append(submitter.name, submitter.value || '1');
        }
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        try {
            if (!fd.has('action')) fd.append('action', 'add_material');
            if (!fd.has('project_id')) fd.append('project_id', projectId);
            const res = await fetch('update_project.php', { method: 'POST', body: fd, headers });
            const json = await res.json();
            if (!json || !json.success) { showToast(json && json.message ? json.message : 'Could not add material', 'error'); return; }

            const mat = json.material;
            if (!mat) {
                showToast('Server returned invalid material data', 'error');
                console && console.warn && console.warn('add_material response missing material:', json);
                return;
            }
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
                // Always include quantity. Default to 1 when server didn't provide a number so
                // newly-created materials start as 'needed' and receive the Check button.
                const q = (typeof mat.quantity !== 'undefined' && mat.quantity !== null) ? mat.quantity : 1;
                // Show obtained / original instead of remaining/original. Server returns original_quantity when available.
                const origForInsert = (typeof mat.original_quantity !== 'undefined' && mat.original_quantity !== null) ? mat.original_quantity : q;
                const obtainedForInsert = (isNaN(origForInsert) ? 0 : Math.max(0, parseInt(origForInsert,10) - parseInt(q,10)));
                mainContent.innerHTML = `<span class="mat-name">${mat.material_name}</span><span class="mat-qty">${obtainedForInsert} / ${origForInsert}</span>`;
                li.appendChild(mainContent);
                li.setAttribute('data-original-quantity', String(origForInsert));
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
                        <button type="button" class="btn small upload-material-photo" data-material-id="${mat.material_id}" title="Upload photo" aria-label="Upload material photo"><i class="fas fa-camera"></i></button>
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

            // If there is nowhere to append the newly added material (no materials list present)
            // or the page is missing the full set of workflow tabs (less than 3), reload to make
            // the server-rendered normalization (Preparation/Construction/Share) and material list visible.
            try {
                let hadMaterialsList = !!document.querySelector('.materials-list-stage');
                const tabCount = document.querySelectorAll('.stage-tab').length || document.querySelectorAll('.stage-card').length || 0;
                if (!hadMaterialsList) {
                    try { if (typeof ensurePreparationStageInDOM === 'function') ensurePreparationStageInDOM(); } catch(e){}
                    try { if (typeof insertMaterialIntoDOM === 'function' && insertMaterialIntoDOM(mat)) hadMaterialsList = true; } catch(e) { /* fallback silently */ }
                }
                if (!hadMaterialsList) {
                    try { if (typeof ensurePreparationStageInDOM === 'function') ensurePreparationStageInDOM(); } catch(e){}
                    try { if (typeof insertMaterialIntoDOM === 'function' && insertMaterialIntoDOM(mat)) hadMaterialsList = true; } catch(e) { /* fallback silently */ }
                }
                if (!hadMaterialsList || tabCount < 3) {
                    // last resort: reload so the server render shows canonical UI
                    setTimeout(function(){ try { location.reload(); } catch(e){} }, 700);
                }
            } catch(e) { /* ignore */ }
            // If this material was added inside a completed stage, auto-toggle that stage back to incomplete
                try {
                    const newItem = document.querySelector('.material-item[data-material-id="' + (mat && mat.material_id ? mat.material_id : '') + '"]');
                    if (newItem) {
                            const stageEl = newItem.closest('.workflow-stage, .stage-card');
                            if (stageEl) {
                                // optimistic UI restore: try to determine templateNum quickly and restore UI immediately
                                try {
                                    let quickNum = stageEl.getAttribute('data-stage-number') || stageEl.getAttribute('data-stage-index') || null;
                                    if (!quickNum) {
                                        const lbl = stageEl.querySelector('.stage-status-label[data-stage-number]');
                                        if (lbl) quickNum = lbl.getAttribute('data-stage-number');
                                    }
                                    if (!quickNum) {
                                        const btnInside = stageEl.querySelector('.complete-stage-btn[data-stage-number], button[data-stage-number]');
                                        if (btnInside && btnInside.dataset && btnInside.dataset.stageNumber) quickNum = btnInside.dataset.stageNumber;
                                    }
                                    if (quickNum) {
                                        const qn = parseInt(quickNum, 10);
                                        if (!isNaN(qn) && typeof markStageUncompletedUI === 'function') {
                                            try { markStageUncompletedUI(qn); } catch(e){}
                                        }
                                    }
                                } catch(e) {}
                            // Try to find the template stage number reliably. The DOM may not be ready
                            // immediately after inserting the material, so retry a few times before giving up.
                            (async function tryUncomplete(attemptsLeft){
                                attemptsLeft = typeof attemptsLeft === 'number' ? attemptsLeft : 10;
                                // Attempt multiple strategies to determine the template stage number
                                let templateNum = NaN;
                                try {
                                    // 1) direct attribute on stage element
                                    if (stageEl) {
                                        const attrNum = stageEl.getAttribute('data-stage-number') || stageEl.getAttribute('data-stage-index');
                                        if (attrNum) templateNum = parseInt(attrNum, 10);
                                    }
                                    // 2) look for a stored label or hidden input inside the stage
                                    if ((isNaN(templateNum) || !isFinite(templateNum)) && stageEl) {
                                        const lbl = stageEl.querySelector('.stage-status-label[data-stage-number]');
                                        if (lbl) templateNum = parseInt(lbl.getAttribute('data-stage-number'), 10);
                                    }
                                    // 3) find a button anywhere with matching stage in this section
                                    if ((isNaN(templateNum) || !isFinite(templateNum)) && stageEl) {
                                        const stageBtn = stageEl.querySelector('.complete-stage-btn[data-stage-number], button[data-stage-number]');
                                        if (stageBtn) templateNum = parseInt(stageBtn.getAttribute('data-stage-number') || stageBtn.getAttribute('data-stage-index'), 10);
                                    }
                                    // 4) fallback to mapping via the active tab inside this stage region
                                    if ((isNaN(templateNum) || !isFinite(templateNum))) {
                                        const tab = document.querySelector('.stage-tab.active, .stage-tab.current');
                                        if (tab) templateNum = parseInt(tab.getAttribute('data-stage-number') || tab.getAttribute('data-stage-index'), 10);
                                    }
                                } catch(e) { templateNum = NaN; }

                                if (!isNaN(templateNum) && isFinite(templateNum)) {
                                    try {
                                        try { if (window.__ECOWASTE_DEBUG__) console.debug('[auto-uncomplete] calling requestToggleStage for', templateNum); } catch(e){}
                                        try { await requestToggleStage(templateNum, <?= json_encode($project_id) ?>, { force_uncomplete: 1 }); } catch(e) {}
                                        try { markStageUncompletedUI(templateNum); } catch(e){}
                                        return; // success — stop retrying
                                    } catch(e) { /* auto-uncomplete (post-add) failed */ }
                                }

                                if (attemptsLeft > 0) {
                                    setTimeout(function(){ tryUncomplete(attemptsLeft - 1); }, 120);
                                } else {
                                    /* auto-uncomplete: could not determine template stage number for stage */
                                }
                            })(10);
                        }
                    }
                } catch(e) { /* auto-uncomplete check failed */ }
    } catch (err) { showToast('Network error', 'error'); }
    });

    // Fixed add material modal submission handler
    document.addEventListener('click', function(e){
        // Handle Proceed button for stages: enabled only when requirements satisfied
        const pbtn = e.target.closest && e.target.closest('.proceed-stage-btn');
        if (pbtn) {
            e.preventDefault();
            try {
                // If disabled, inform user
                if (pbtn.getAttribute && pbtn.getAttribute('aria-disabled') === 'true' || pbtn.classList && pbtn.classList.contains('is-disabled')) {
                    showToast('Complete all materials and upload required photos before proceeding', 'error');
                    return;
                }
                // Determine stage number
                let sn = pbtn.getAttribute('data-stage-number');
                if (!sn) {
                    const stageEl = pbtn.closest('.workflow-stage, .stage-card');
                    if (stageEl) sn = stageEl.getAttribute('data-stage-number') || stageEl.getAttribute('data-stage-index');
                }
                const stageNum = sn ? parseInt(sn, 10) : NaN;
                if (!stageNum || isNaN(stageNum)) { showToast('Cannot determine stage to proceed', 'error'); return; }

                // Prevent double clicks
                if (pbtn.dataset._processing === '1') return;
                pbtn.dataset._processing = '1';
                pbtn.classList.add('is-processing');
                // Request server to complete the stage
                (async function(){ try { await requestToggleStage(stageNum, projectId); } catch(e){ showToast('Failed to proceed — try again', 'error'); } finally { try{ delete pbtn.dataset._processing; pbtn.classList.remove('is-processing'); } catch(e){} } })();
            } catch(e) { /* ignore */ }
            return;
        }
        const btn = e.target.closest('#addMaterialModal button[name="add_material"]');
        if (!btn) return;
        e.preventDefault();
        const modal = document.getElementById('addMaterialModal');
        if (!modal) return;
        const form = modal.querySelector('form');
        if (!form) return;

        (async function(){
            const fd = new FormData(form);

            // Get the final material name (either from dropdown or custom input)
            let materialName = fd.get('material_name');
            const customName = fd.get('custom_material_name');

            if (customName && customName.trim() !== '') {
                materialName = customName.trim();
            }

            // Ensure we have a material name
            if (!materialName || materialName.trim() === '') {
                showToast('Please select or enter a material name', 'error');
                return;
            }

            // Update the form data with the final material name
            fd.set('material_name', materialName);

            // Remove the custom field as it's not needed by the server
            fd.delete('custom_material_name');
            fd.delete('material_category');

            // Ensure server sees the action key expected by update_project.php
            if (!fd.has('action')) fd.append('action', 'add_material');
            if (!fd.has('project_id')) fd.append('project_id', projectId);
            const headers = { 'X-Requested-With': 'XMLHttpRequest' };
            
            try {
                // Post to the API endpoint which handles material creation and returns JSON
                const res = await fetch('update_project.php', { method: 'POST', body: fd, headers });
                const json = await res.json();
                
                if (!json || !json.success) { 
                    showToast(json.message || 'Could not add material', 'error'); 
                    return; 
                }

                const mat = json.material;
                if (!mat) {
                    showToast('Server returned invalid material data', 'error');
                    console && console.warn && console.warn('add_material response missing material:', json);
                    return;
                }
                
                // Find the materials list in the current active stage
                const activeStage = document.querySelector('.workflow-stage.active') || 
                                document.querySelector('.stage-card.current') ||
                                document.querySelector('.workflow-stage.current');
                let materialsList = activeStage ? activeStage.querySelector('.materials-list-stage') : null;
                
                // Fallback to first materials list if not found in active stage
                if (!materialsList) {
                    materialsList = document.querySelector('.materials-list-stage');
                }
                
                if (materialsList) {
                    const li = document.createElement('li');
                    li.className = 'material-item';
                    // store original total quantity for client updates
                    const origQ = (typeof mat.original_quantity !== 'undefined' && mat.original_quantity !== null) ? mat.original_quantity : ((typeof mat.quantity !== 'undefined' && mat.quantity !== null) ? mat.quantity : 1);
                    li.setAttribute('data-original-quantity', String(origQ));
                    li.setAttribute('data-material-id', mat.material_id);
                    
                    // Build the material item structure (display obtained / original)
                    const obtainedQ = (isNaN(origQ) ? 0 : Math.max(0, parseInt(origQ,10) - ((typeof mat.quantity !== 'undefined' && mat.quantity !== null) ? parseInt(mat.quantity,10) : origQ)));
                    li.innerHTML = `
                        <div class="material-main">
                            <span class="mat-name">${mat.material_name}</span>
                            <div class="mat-meta">
                                ${((typeof mat.quantity !== 'undefined' && mat.quantity !== null) ? `<span class="mat-qty">${obtainedQ} / ${(typeof mat.original_quantity !== 'undefined' && mat.original_quantity !== null) ? mat.original_quantity : mat.quantity}</span>` : `<span class="mat-qty">0 / 1</span>`)}
                            </div>
                        </div>
                        <div class="material-actions">
                            <a href="browse.php?query=${encodeURIComponent(mat.material_name)}&from_project=${projectId}" class="btn small find-donations-btn">Find Donations</a>
                            <form method="POST" class="inline-form" data-obtain-modal="1" action="project_details.php?id=${projectId}">
                                <input type="hidden" name="material_id" value="${mat.material_id}">
                                <input type="hidden" name="status" value="obtained">
                                <button type="submit" name="update_material_status" class="btn small" aria-label="Mark material obtained">
                                    <i class="fas fa-check" aria-hidden="true"></i>
                                </button>
                            </form>
                            <form method="POST" class="inline-form" data-confirm="Remove this material?" action="project_details.php?id=${projectId}">
                                <input type="hidden" name="material_id" value="${mat.material_id}">
                                <button type="submit" name="remove_material" class="btn small danger" aria-label="Delete material">
                                    <i class="fas fa-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                        <div class="material-photos" data-material-id="${mat.material_id}"></div>
                    `;
                    
                    // Add to list with animation
                    materialsList.appendChild(li);
                    
                    // Trigger smooth animation
                    requestAnimationFrame(() => {
                        li.style.opacity = '0';
                        li.style.transform = 'translateY(-10px)';
                        li.style.transition = 'all 0.3s ease';
                        
                        requestAnimationFrame(() => {
                            li.style.opacity = '1';
                            li.style.transform = 'translateY(0)';
                        });
                    });
                }
                
                // Reset and close modal
                form.reset();
                modal.classList.remove('active');
                document.body.style.overflow = '';
                
                showToast('Material added successfully');

                // If the client did not find a materials list to add into, or there are fewer than
                // 3 stage tabs rendered client-side, reload the page so the server can render the
                // canonical Preparation stage and the new material will appear immediately.
                try {
                    const materialsListPresent = !!(document.querySelector('.materials-list-stage'));
                    const tabs = document.querySelectorAll('.stage-tab');
                    const tabCount = tabs ? tabs.length : 0;
                    if (!materialsListPresent) {
                        try { insertMaterialIntoDOM(mat); } catch(e) { /* fallback silently */ }
                    }
                    if (tabCount < 3) {
                        try { ensureThreeStageTabs(); } catch(e) { /* ignore */ }
                    }
                } catch(e) { /* ignore */ }
                
                // Refresh material requirements state
                try { 
                    refreshMaterialCollectionReqState(); 
                } catch(e) { /* ignore */ }
                
                // If this material was added to a completed stage, auto-uncomplete that stage so it can be re-validated
                try {
                    const li = document.querySelector('.material-item[data-material-id="' + (mat && mat.material_id ? mat.material_id : '') + '"]');
                    if (li) {
                        const stageEl = li.closest('.workflow-stage, .stage-card');
                        if (stageEl && stageEl.classList.contains('completed')) {
                            // Prefer template stage number (data-stage-number) when calling completeStage
                            let templateNum = NaN;
                            try {
                                // Try direct attribute on the stage element
                                templateNum = parseInt(stageEl.getAttribute('data-stage-number'), 10);
                            } catch(e) { templateNum = NaN; }
                            // Fallback: try to read from a button inside the stage
                            if (isNaN(templateNum) || !isFinite(templateNum)) {
                                try {
                                    const btn = stageEl.querySelector('.complete-stage-btn[data-stage-number], button[data-stage-number]');
                                    if (btn) templateNum = parseInt(btn.getAttribute('data-stage-number'), 10);
                                } catch(e) { /* ignore */ }
                            }
                            if (!isNaN(templateNum) && isFinite(templateNum)) {
                                try {
                                    await requestToggleStage(templateNum, projectId);
                                    try { markStageUncompletedUI(templateNum); } catch(e){}
                                } catch(e){ /* auto-uncomplete after modal add failed */ }
                            }
                        }
                    }
                } catch(e) { /* auto-uncomplete modal add check failed */ }
                
            } catch (err) { 
                showToast('Network error: ' + err.message, 'error'); 
            }
        })();
    });


    // Fixed form submit handler for Enter key in add material modal
    document.addEventListener('submit', function(e){
        const form = e.target;
        if (!form.closest || !form.closest('#addMaterialModal')) return;
        e.preventDefault();
        
        // Trigger the same logic as the click handler
        const submitBtn = form.querySelector('button[name="add_material"]');
        if (submitBtn) {
            const clickEvent = new Event('click', { bubbles: true });
            submitBtn.dispatchEvent(clickEvent);
        }
    });

    // Initialize Material Collection completion state on load
    document.addEventListener('DOMContentLoaded', function(){ try{ refreshMaterialCollectionReqState(); }catch(e){} });

    // Ensure every unobtained material has a visible 'Check' button so users can
    // mark it obtained. Some templates or dynamic flows can accidentally omit the
    // inline obtain form, so this helper re-syncs the DOM and adds a small form
    // with update_material_status where missing.
    function ensureCheckButtonsForNeededMaterials() {
        try {
            const items = Array.from(document.querySelectorAll('.material-item'));
            items.forEach(li => {
                try {
                    // determine if this material appears obtained
                    const qtyNode = li.querySelector('.mat-qty');
                    let parsedQty = null;
                    try { parsedQty = qtyNode ? parseMatQtyText(String(qtyNode.textContent || '')) : null; } catch(e) { parsedQty = null; }
                    const hasObtained = !!li.querySelector('.badge.obtained') || li.classList.contains('material-obtained') || (parsedQty && typeof parsedQty.remaining === 'number' && parsedQty.remaining <= 0);
                    if (hasObtained) return; // nothing to do for obtained materials

                    // find actions container (create if missing)
                    let actions = li.querySelector('.material-actions');
                    if (!actions) {
                        actions = document.createElement('div'); actions.className = 'material-actions';
                        li.appendChild(actions);
                    }

                    // If an update_material_status form/button already present, keep it
                    if (actions.querySelector('form button[name="update_material_status"]')) return;

                    // find existing find-donations anchor or delete form position to insert beside
                    const findLink = actions.querySelector('.find-donations-btn');

                    // build an inline-form to match our existing pattern
                    const mid = li.getAttribute('data-material-id') || (li.querySelector('input[name="material_id"]') ? li.querySelector('input[name="material_id"]').value : '');
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.className = 'inline-form';
                    form.setAttribute('data-obtain-modal', '1');

                    const hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'material_id'; hid.value = mid;
                    const hs = document.createElement('input'); hs.type = 'hidden'; hs.name = 'status'; hs.value = 'obtained';
                    const btn = document.createElement('button'); btn.type = 'submit'; btn.name = 'update_material_status'; btn.className = 'btn small obtain-btn'; btn.setAttribute('title','Mark obtained'); btn.setAttribute('aria-label','Mark material obtained'); btn.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i>';
                    form.appendChild(hid); form.appendChild(hs); form.appendChild(btn);

                    // Insert before delete button where possible, otherwise append
                    const deleteBtn = actions.querySelector('button[name="remove_material"], button.danger');
                    if (deleteBtn && deleteBtn.parentNode === actions) actions.insertBefore(form, deleteBtn);
                    else if (findLink && findLink.parentNode === actions) actions.insertBefore(form, findLink.nextSibling);
                    else actions.appendChild(form);

                } catch(e) { /* ignore per-item failures */ }
            });
        } catch(e){ /* ignore */ }
    }

    // Ensure check buttons are present shortly after load and after any known DOM mutations
    document.addEventListener('DOMContentLoaded', function(){ try { ensureCheckButtonsForNeededMaterials(); } catch(e){} });
    try { // also re-run after a small delay to catch any late DOM-inserted lists
        setTimeout(function(){ try { ensureCheckButtonsForNeededMaterials(); } catch(e){} }, 450);
    } catch(e){}

    // Observe material lists for changes and ensure check buttons are present after any DOM updates
    try {
        const materialsRoot = document.querySelector('.materials-list-stage') || document.querySelector('.stage-materials') || document.body;
        if (materialsRoot) {
            const checkObserver = new MutationObserver(function(mutations){
                try {
                    let didChange = false;
                    for (let m of mutations) {
                        if (m.type === 'childList' || m.type === 'attributes') { didChange = true; break; }
                    }
                    if (didChange) try { ensureCheckButtonsForNeededMaterials(); } catch(e){}
                } catch(e){}
            });
            checkObserver.observe(materialsRoot, { childList: true, subtree: true, attributes: true });
        }
    } catch(e) {}

    // Delegated handler: upload photo for a specific material
    // Helper: recompute whether Material Collection stage requirements are satisfied
    function refreshMaterialCollectionReqState(){
        try {
            // For each materials container in the page, compute whether its stage requirements are satisfied
            const allMaterialsNodes = Array.from(document.querySelectorAll('.stage-materials'));
            if (!allMaterialsNodes || allMaterialsNodes.length === 0) return;

            allMaterialsNodes.forEach(materialsNode => {
                try {
                    const stageCard = materialsNode.closest('.stage-card') || materialsNode.closest('.workflow-stage') || materialsNode.parentElement;
                    if (!stageCard) return;
                    // prefer a button inside .stage-actions; fall back to any complete-stage-btn with matching data-stage-number
                    let btn = stageCard.querySelector('.stage-actions button[data-stage-number]');
                    if (!btn) btn = stageCard.querySelector('button.complete-stage-btn[data-stage-number]');
                    // Note: don't return if there's no button. We used to rely on the button to identify
                    // the stage and hold request state, but buttons were removed per UX changes. Instead,
                    // fall back to using attributes on the stage card itself (data-stage-number / data-stage-index).

                    const items = Array.from(materialsNode.querySelectorAll('.material-item'));
                    const total = items.length;
                    let have = 0;
                    items.forEach(li => {
                        const statusBadge = li.querySelector('.badge.obtained');
                        const photos = li.querySelector('.material-photos');
                        const hasPhoto = photos && photos.querySelector('.material-photo:not(.placeholder)');
                        if (statusBadge || hasPhoto) have++;

                        // If a photo exists, hide the upload button in the mat-meta to avoid redundant uploads
                        try {
                            const metaEl = li.querySelector('.mat-meta');
                            if (metaEl && hasPhoto) {
                                const up = metaEl.querySelector('.upload-material-photo');
                                if (up) up.remove();
                            }
                        } catch(e) { /* ignore */ }
                    });

                    // final decision: satisfied when every material has either obtained badge or photo
                    const finalOk = (total === 0) ? true : (have >= total);
                    // debug removed

                    // If debug flag present in URL (search or hash), render/refresh a small overlay showing per-stage counts
                    try {
                        const searchAndHash = String((location && (location.search || '') + (location.hash || '')) || '');
                        const isDebug = /(?:\?|&|#)debug=1\b/.test(searchAndHash) || searchAndHash.indexOf('debug=1') !== -1;
                        if (isDebug) {
                            let dbg = document.getElementById('matDebugOverlay');
                            if (!dbg) {
                                dbg = document.createElement('div'); dbg.id = 'matDebugOverlay';
                                dbg.style.position = 'fixed'; dbg.style.right = '12px'; dbg.style.bottom = '12px'; dbg.style.width = '360px'; dbg.style.maxHeight = '60vh'; dbg.style.overflow = 'auto'; dbg.style.background = 'rgba(0,0,0,0.85)'; dbg.style.color = '#fff'; dbg.style.zIndex = '99999'; dbg.style.padding = '10px'; dbg.style.fontSize = '13px'; dbg.style.borderRadius = '8px'; dbg.style.boxShadow = '0 8px 24px rgba(0,0,0,0.4)';
                                // add a small header with manual run button
                                const hdr = document.createElement('div'); hdr.style.display = 'flex'; hdr.style.justifyContent = 'space-between'; hdr.style.alignItems = 'center'; hdr.style.marginBottom = '8px';
                                const hTitle = document.createElement('div'); hTitle.textContent = 'Material Debug'; hTitle.style.fontWeight = '700'; hTitle.style.fontSize = '13px';
                                const runBtn = document.createElement('button'); runBtn.textContent = 'Run check'; runBtn.style.fontSize = '12px'; runBtn.style.padding = '4px 8px'; runBtn.style.borderRadius = '6px'; runBtn.style.cursor = 'pointer'; runBtn.style.border = 'none'; runBtn.style.background = '#2E8B57'; runBtn.style.color = '#fff'; runBtn.addEventListener('click', function(){ try{ window.runMaterialStageCheck && window.runMaterialStageCheck(); }catch(e){} });
                                hdr.appendChild(hTitle); hdr.appendChild(runBtn);
                                dbg.appendChild(hdr);
                                const content = document.createElement('div'); content.id = 'matDebugContent'; dbg.appendChild(content);
                                document.body.appendChild(dbg);
                            }
                            const idx = stageCard.getAttribute('data-stage-index') || 'unknown';
                            const title = stageCard.querySelector('.stage-title') ? stageCard.querySelector('.stage-title').textContent.trim() : ('Stage ' + idx);
                            // accumulate per-stage entries and then render them once (avoid duplicate appends)
                            let content = document.getElementById('matDebugContent');
                            if (content) {
                                // initialize storage on element if not present
                                if (!content._entries) content._entries = [];
                                // store an object keyed by stage idx to avoid duplicates
                                content._entries[idx] = `<div style="margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,0.08)"><strong>${title}</strong><br>stageIndex: ${idx}<br>materials: ${total}<br>satisfied: ${have}<br>reqOk: ${reqOk}</div>`;
                                // render all entries
                                content.innerHTML = Object.keys(content._entries).sort().map(k => content._entries[k]).join('');
                            }
                        }
                    } catch(di) { /* debug overlay failed (silenced) */ }

                    const forceEnabled = (btn && btn.dataset && btn.dataset.forceEnabled) ? true : false;
                    if (!finalOk && !forceEnabled) {
                        // visual disabled (but keep clickable so JS can re-evaluate)
                        try { if (btn) btn.setAttribute('aria-disabled', 'true'); } catch(e){}
                        try { if (btn) btn.classList.add('is-disabled'); } catch(e){}
                        if (btn) btn.title = 'Requirements not met for this stage';
                        // store reqOk for either button or stageCard so other flows can inspect it
                        try { if (btn) btn.dataset.reqOk = '0'; else stageCard.dataset.reqOk = '0'; } catch(e){}
                        // If debug flag present, attach a small helper that explains missing requirements
                        try {
                            const searchAndHash = String((location && (location.search || '') + (location.hash || '')) || '');
                            const isDebug = /(?:\?|&|#)debug=1\b/.test(searchAndHash) || searchAndHash.indexOf('debug=1') !== -1;
                            if (isDebug) {
                                // remove existing helper for this stage
                                let existing = stageCard.querySelector('.why-disabled-btn');
                                if (existing) existing.remove();
                                const why = document.createElement('button');
                                why.type = 'button';
                                why.className = 'why-disabled-btn';
                                why.textContent = '?';
                                why.title = 'Why is this disabled?';
                                why.setAttribute('aria-hidden','false');
                                // build missing list when clicked
                                why.addEventListener('click', function(ev){
                                    ev.stopPropagation();
                                    // remove any other panels
                                    document.querySelectorAll('.why-disabled-panel').forEach(n=>n.remove());
                                    const panel = document.createElement('div');
                                    panel.className = 'why-disabled-panel';
                                    const title = document.createElement('h4'); title.textContent = 'Missing requirements'; panel.appendChild(title);
                                    const list = document.createElement('ul');
                                    const itemsScoped = Array.from(materialsNode.querySelectorAll('.material-item'));
                                    itemsScoped.forEach(li => {
                                        const name = (li.querySelector('.mat-name') && li.querySelector('.mat-name').textContent.trim()) || ('#' + (li.dataset.materialId || ''));
                                        const missingPhoto = !(li.querySelector('.material-photos') && li.querySelector('.material-photos').querySelector('.material-photo:not(.placeholder)'));
                                        const missingObtained = !li.querySelector('.badge.obtained');
                                        if (missingPhoto || missingObtained) {
                                            const liNode = document.createElement('li');
                                            liNode.textContent = name + ':';
                                            if (missingObtained) {
                                                const t = document.createElement('span'); t.textContent = ' Not marked obtained'; t.className = 'missing-obtained'; liNode.appendChild(t);
                                            }
                                            if (missingPhoto) {
                                                const t2 = document.createElement('span'); t2.textContent = ' Missing photo'; t2.className = 'missing-photo'; liNode.appendChild(t2);
                                            }
                                            list.appendChild(liNode);
                                        }
                                    });
                                    if (!list.children.length) {
                                        const ok = document.createElement('div'); ok.textContent = 'No missing items detected.'; panel.appendChild(ok);
                                    } else panel.appendChild(list);
                                    document.body.appendChild(panel);
                                    // position near the button (prefer above if space, otherwise below)
                                    const r = why.getBoundingClientRect();
                                    const aboveTop = window.scrollY + r.top - panel.offsetHeight - 10;
                                    const belowTop = window.scrollY + r.bottom + 8;
                                    let top = aboveTop;
                                    if (aboveTop < window.scrollY + 8) top = belowTop;
                                    let left = window.scrollX + r.left;
                                    // clamp to viewport
                                    const maxLeft = window.scrollX + Math.max(document.documentElement.clientWidth, window.innerWidth) - panel.offsetWidth - 8;
                                    if (left > maxLeft) left = maxLeft;
                                    if (left < window.scrollX + 8) left = window.scrollX + 8;
                                    panel.style.top = top + 'px';
                                    panel.style.left = left + 'px';
                                });
                                // Insert the helper right after the primary complete button to avoid overflow hiding
                                try {
                                    const primaryBtn = stageCard.querySelector('.complete-stage-btn');
                                    if (primaryBtn && primaryBtn.parentNode) primaryBtn.parentNode.insertBefore(why, primaryBtn.nextSibling);
                                    else {
                                        const actions = stageCard.querySelector('.stage-actions');
                                        if (actions) actions.appendChild(why);
                                    }
                                } catch(e) { const actions = stageCard.querySelector('.stage-actions'); if (actions) actions.appendChild(why); }
                            }
                        } catch(e){ /* why helper failed (silenced) */ }
                    } else {
                        try { if (btn) btn.removeAttribute('aria-disabled'); } catch(e){}
                        try { if (btn) btn.classList.remove('is-disabled'); } catch(e){}
                        if (btn) btn.title = '';
                        try { if (btn) btn.dataset.reqOk = '1'; else stageCard.dataset.reqOk = '1'; } catch(e){}
                        // If the stage now satisfies requirements, ensure status label and server state follow
                        try {
                            // determine stage number reliably
                            let stageNum = stageCard.getAttribute('data-stage-number') || stageCard.getAttribute('data-stage-index') || (btn ? btn.getAttribute('data-stage-number') : null);
                            if (!stageNum) {
                                const tab = document.querySelector('.stage-tab.active, .stage-tab.current');
                                if (tab) stageNum = tab.getAttribute('data-stage-number') || tab.getAttribute('data-stage-index');
                            }
                            const sn = stageNum ? parseInt(stageNum, 10) : NaN;
                            if (!isNaN(sn)) {
                                // When all requirements are present, do NOT auto-complete the stage.
                                // Instead: enable the explicit "Proceed" button so the user can confirm moving on.
                                const isCompleted = stageCard.classList.contains('completed');
                                if (finalOk && !isCompleted) {
                                    try {
                                        const proceedBtn = stageCard.querySelector('.proceed-stage-btn') || (btn ? btn.parentNode && btn.parentNode.querySelector('.proceed-stage-btn') : null);
                                        if (proceedBtn) {
                                            proceedBtn.classList.remove('is-disabled');
                                            proceedBtn.removeAttribute('aria-disabled');
                                            proceedBtn.dataset.reqOk = '1';
                                            proceedBtn.title = 'Proceed to next stage';
                                        }
                                    } catch(e) { /* enabling proceed button failed (silenced) */ }
                                }
                                // If requirements are met but server already marked completed, ensure label updated (renderStageStatusLabel handled in response)
                            }
                        } catch(e) { /* ignore auto-complete errors */ }
                        // If requirements were previously satisfied but now not, uncomplete the stage on the server
                        try {
                            let stageNum2 = stageCard.getAttribute('data-stage-number') || stageCard.getAttribute('data-stage-index') || (btn ? btn.getAttribute('data-stage-number') : null);
                            const sn2 = stageNum2 ? parseInt(stageNum2, 10) : NaN;
                            const currentlyMarkedCompleted = stageCard.classList.contains('completed');
                            if (!finalOk && currentlyMarkedCompleted && !isNaN(sn2)) {
                                try {
                                    // Optimistic UI: mark stage as current/uncompleted immediately while we send the server request
                                    try {
                                        stageCard.classList.remove('completed');
                                        stageCard.classList.add('current');
                                        const tab2 = document.querySelector('.stage-tab[data-stage-index="' + (parseInt(stageCard.getAttribute('data-stage-index')||sn2,10)) + '"]') || document.querySelector('.stage-tab[data-stage-number="' + sn2 + '"]');
                                        if (tab2) {
                                            const badge2 = tab2.querySelector('.tab-badge');
                                            if (badge2) { badge2.classList.remove('completed'); badge2.classList.add('active'); badge2.textContent = 'Current'; }
                                            tab2.classList.remove('completed'); tab2.classList.add('active');
                                        }
                                            try {
                                                // Update proceed button enabled state (if present)
                                                const proceedBtn = stageCard.querySelector('.proceed-stage-btn') || (btn ? btn.parentNode && btn.parentNode.querySelector('.proceed-stage-btn') : null);
                                                if (proceedBtn) {
                                                    if (finalOk) {
                                                        proceedBtn.classList.remove('is-disabled');
                                                        proceedBtn.removeAttribute('aria-disabled');
                                                        proceedBtn.dataset.reqOk = '1';
                                                    } else {
                                                        proceedBtn.classList.add('is-disabled');
                                                        proceedBtn.setAttribute('aria-disabled', 'true');
                                                        proceedBtn.dataset.reqOk = '0';
                                                    }
                                                }
                                            } catch (e) { /* ignore proceed btn update */ }
                                    } catch(e){}
                                    try { requestToggleStage(sn2, projectId, { force_uncomplete: 1 }).then(()=>{/* auto-uncomplete attempted */}).catch(()=>{}); } catch(e){}
                                } catch(e){}
                            }
                        } catch(e) { /* ignore */ }
                    }
                } catch (innerErr) { /* Error computing state for materialsNode (silenced) */ }
            });

    } catch(e){ /* refreshMaterialCollectionReqState failed (silenced) */ }
    }

    // Check helpers used by debug and the materialPhotoChanged handler
    function getActiveMaterialsNode(){
        // prefer the currently visible or current stage
        let stage = document.querySelector('.workflow-stage.active') || document.querySelector('.stage-card.current') || document.querySelector('.workflow-stage.current');
        if (stage) {
            const m = stage.querySelector('.stage-materials');
            if (m) return m;
        }
        // fallback to first materials list on page
        return document.querySelector('.stage-materials');
    }

    function checkAllMaterialsObtained(){
        const materialsNode = getActiveMaterialsNode();
        if (!materialsNode) return false;
        const items = Array.from(materialsNode.querySelectorAll('.material-item'));
        if (items.length === 0) return true; // nothing to satisfy
        return items.every(li => !!li.querySelector('.badge.obtained'));
    }

    function checkAllPhotosUploaded(){
        const materialsNode = getActiveMaterialsNode();
        if (!materialsNode) return false;
        const items = Array.from(materialsNode.querySelectorAll('.material-item'));
        if (items.length === 0) return true;
        return items.every(li => {
            const photos = li.querySelector('.material-photos');
            return !!(photos && photos.querySelector('.material-photo:not(.placeholder)'));
        });
    }

    // Listen for material photo changes and update the first complete-stage button in the active stage
    document.addEventListener('materialPhotoChanged', () => {
        try {
                const btn = document.querySelector('.workflow-stage.active .complete-stage-btn, .stage-card.current .complete-stage-btn, .complete-stage-btn');
                if (!btn) return;
                // scoped checks for active stage
                const materialsNode = getActiveMaterialsNode();
                let scopedAllObtained = true;
                let scopedAllPhotos = true;
                let items = [];
                if (materialsNode) {
                    items = Array.from(materialsNode.querySelectorAll('.material-item'));
                    items.forEach(li => {
                        if (!li.querySelector('.badge.obtained')) scopedAllObtained = false;
                        const photos = li.querySelector('.material-photos');
                        if (!(photos && photos.querySelector('.material-photo:not(.placeholder)'))) scopedAllPhotos = false;
                    });
                }
                const ok = (items && items.length === 0) ? true : (scopedAllObtained && scopedAllPhotos);
                if (!ok) {
                    try { btn.setAttribute('aria-disabled', 'true'); } catch(e){}
                    try { btn.classList.add('is-disabled'); } catch(e){}
                    try { btn.dataset.reqOk = '0'; } catch(e){}
                } else {
                    try { btn.removeAttribute('aria-disabled'); } catch(e){}
                    try { btn.classList.remove('is-disabled'); } catch(e){}
                    try { btn.dataset.reqOk = '1'; } catch(e){}
                }
    } catch(e) { /* materialPhotoChanged handler failed (silenced) */ }
    });

    document.addEventListener('click', function(e){
        const btn = e.target.closest('.upload-material-photo');
        if (!btn) return;
        const mid = btn.dataset.materialId;
        if (!mid) return;
        // Simplify upload flow: always open file picker directly and upload with a single default photo type.
        // This removes the before/after chooser so uploads are faster and simpler.
        const cleanUp = () => { /* noop for non-modal flow */ };
        // Default photo type used for material uploads. Keep 'after' so server defaults still apply.
        pickAndUpload('after');

        // guard against accidental double-invocation from duplicate events
        let _pickInProgress = false;
        async function pickAndUpload(photoType){
            if (_pickInProgress) return;
            _pickInProgress = true;
            cleanUp();
            const input = document.createElement('input');
            input.type = 'file'; input.accept = 'image/*';
            input.onchange = async function(ev){
                const file = ev.target.files[0];
                if (!file) return;
                input.value = '';
                const fd = new FormData();
                fd.append('material_id', mid);
                fd.append('photo', file);
                fd.append('photo_type', photoType);
                try {
                    const res = await fetch('upload_material_photo.php', { method: 'POST', body: fd });
                    const txt = await res.text();
                    let json = null;
                    try { json = txt ? JSON.parse(txt) : null; } catch(e){ showToast('Upload failed', 'error'); return; }
                    if (json && json.success) {
                        showToast('Photo uploaded');
                        try {
                            let photos = document.querySelector('.material-photos[data-material-id="' + mid + '"]');
                            if (!photos) {
                                const item = document.querySelector('.material-item[data-material-id="' + mid + '"]');
                                if (item) {
                                    photos = document.createElement('div');
                                    photos.className = 'material-photos';
                                    photos.setAttribute('data-material-id', mid);
                                    const main = item.querySelector('.material-main');
                                    if (main && main.parentNode) main.parentNode.insertBefore(photos, main.nextSibling);
                                    else item.appendChild(photos);
                                }
                            }
                                if (photos) {
                                // create a thumbnail entry without removing others (allow multiple)
                                const div = document.createElement('div');
                                div.className = 'material-photo';
                                div.dataset.photoId = json.id || '';
                                div.dataset.photoType = photoType;
                                const src = json.path && json.path.indexOf('assets/') === 0 ? json.path : ('assets/uploads/materials/' + json.path);
                                // Only show the small "Before/After" badge when we're in the Preparation stage
                                const badgeHtml = (typeof inPreparation !== 'undefined' && inPreparation) ? `<div class="photo-type-badge">${photoType}</div>` : '';
                                div.innerHTML = `<img src="${src}" alt="Material photo"><button type="button" class="material-photo-delete" title="Delete photo" onclick="(function(el){try{el.style.boxShadow='0 0 0 6px rgba(220,53,69,0.95)'; setTimeout(function(){try{el.style.boxShadow='';}catch(e){}},800);}catch(e){}; try{processMaterialPhotoDelete(el);}catch(e){}; })(this); return false;" onpointerdown="try{this.style.boxShadow='0 0 0 6px rgba(220,53,69,0.95)';}catch(e){}" onmousedown="try{this.style.boxShadow='0 0 0 6px rgba(220,53,69,0.95)';}catch(e){}" ontouchstart="try{this.style.boxShadow='0 0 0 6px rgba(220,53,69,0.95)';}catch(e){}"><i class="fas fa-trash"></i></button>${badgeHtml}`;
                                const img = div.querySelector('img'); if (img) img.addEventListener('click', ()=> openImageViewer(src));
                                photos.insertBefore(div, photos.firstChild);
                                // Hide the per-material upload button now that a real (non-placeholder) photo exists
                                try {
                                    const parentMat = photos.closest('.material-item');
                                    if (parentMat) {
                                        const meta = parentMat.querySelector('.mat-meta');
                                        const upBtn = meta && meta.querySelector('.upload-material-photo');
                                        if (upBtn) upBtn.remove();
                                    }
                                } catch(e) { /* ignore removal errors */ }
                            }
                        } catch (e) { /* failed to insert thumbnail (silenced) */ }
                        try { refreshMaterialCollectionReqState(); } catch(e){}
                        try { document.dispatchEvent(new Event('materialPhotoChanged')); } catch(e) {}

                        // If after this upload all requirements are satisfied, try to auto-complete the stage
                        try {
                            const dbg = /(?:\?|&|#)debug=1\b/.test((location && (location.search || '') + (location.hash || '')) || '');
                            // Determine the stage-element and its materials container for this uploaded photo
                            let stageEl = null;
                            try {
                                if (photos) stageEl = photos.closest('.workflow-stage, .stage-card');
                                if (!stageEl && btn) stageEl = btn.closest('.workflow-stage, .stage-card');
                            } catch(e) { stageEl = null; }

                            // Scoped checks: operate on the materials list inside this stage if available
                            let scopedAllObtained = true;
                            let scopedAllPhotos = true;
                            let materialsNode = null;
                            if (stageEl) materialsNode = stageEl.querySelector('.stage-materials');
                            if (!materialsNode) materialsNode = document.querySelector('.stage-materials'); // fallback

                            if (materialsNode) {
                                const itemsScoped = Array.from(materialsNode.querySelectorAll('.material-item'));
                                if (itemsScoped.length === 0) {
                                    // nothing to satisfy -> treat as ok
                                    scopedAllObtained = true; scopedAllPhotos = true;
                                } else {
                                    itemsScoped.forEach(li => {
                                        if (!li.querySelector('.badge.obtained')) scopedAllObtained = false;
                                        const ph = li.querySelector('.material-photos');
                                        if (!(ph && ph.querySelector('.material-photo:not(.placeholder)'))) scopedAllPhotos = false;
                                    });
                                }
                            } else {
                                // fallback to global helpers
                                try { scopedAllObtained = checkAllMaterialsObtained(); } catch(e) { scopedAllObtained = false; }
                                try { scopedAllPhotos = checkAllPhotosUploaded(); } catch(e) { scopedAllPhotos = false; }
                            }

                            /* debug: auto-complete scoped checks (silenced) */

                            if (scopedAllObtained && scopedAllPhotos) {
                                // try to determine the template stage number
                                let stageNum = null;
                                try {
                                    if (stageEl) {
                                        stageNum = stageEl.getAttribute('data-stage-number') || stageEl.getAttribute('data-stage-index') || null;
                                    }
                                    if (!stageNum) {
                                        const completeBtn = stageEl ? (stageEl.querySelector('.stage-actions button[data-stage-number]') || stageEl.querySelector('button.complete-stage-btn[data-stage-number]')) : null;
                                        if (completeBtn && completeBtn.dataset && completeBtn.dataset.stageNumber) stageNum = completeBtn.dataset.stageNumber;
                                    }
                                    if (!stageNum) {
                                        // final fallback: pick first stage-tab matching name
                                        const tab = document.querySelector('.stage-tab.active, .stage-tab.current');
                                        if (tab) stageNum = tab.getAttribute('data-stage-number') || tab.getAttribute('data-stage-index');
                                    }
                                } catch(e) { stageNum = null; }

                                if (stageNum) {
                                    const sn = parseInt(stageNum, 10);
                                    if (!isNaN(sn)) {
                                        try {
                                            // Do not auto-complete — enable the explicit Proceed button in this stage
                                            try {
                                                const stageEl2 = stageEl || (document.querySelector('.workflow-stage[data-stage-number="' + sn + '"]') || document.querySelector('.stage-card[data-stage-number="' + sn + '"]'));
                                                const proceedBtn2 = stageEl2 ? (stageEl2.querySelector('.proceed-stage-btn') || stageEl2.querySelector('button.proceed-stage-btn')) : null;
                                                if (proceedBtn2) {
                                                    proceedBtn2.classList.remove('is-disabled');
                                                    proceedBtn2.removeAttribute('aria-disabled');
                                                    proceedBtn2.dataset.reqOk = '1';
                                                    proceedBtn2.title = 'Proceed to next stage';
                                                }
                                            } catch(e) {}
                                        } catch(e) { if (dbg) console.debug('[auto-complete] enabling proceed button threw', e); }
                                    } else if (dbg) console.debug('[auto-complete] stageNum parsed NaN', stageNum);
                                } else if (dbg) console.debug('[auto-complete] stageNum not found');
                            }
                                        } catch(e) { /* auto-complete after upload failed (silenced) */ }
                    } else {
                        showToast(json && json.message ? json.message : 'Upload failed', 'error');
                    }
                    } catch (err) { showToast('Upload failed', 'error'); }
                    finally { try { _pickInProgress = false; } catch(e){} }
            };
            input.click();
        }
    });

    // Fallback helper: POST FormData using XHR when fetch() is unavailable or blocked
    async function xhrPostForm(url, formData){
        return new Promise(function(resolve){
            try{
                var xhr = new XMLHttpRequest();
                xhr.open('POST', url, true);
                xhr.onreadystatechange = function(){
                    if (xhr.readyState !== 4) return;
                    var txt = xhr.responseText || '';
                    try { var j = txt ? JSON.parse(txt) : null; resolve(j); } catch(e){ resolve(null); }
                };
                xhr.onerror = function(){ resolve(null); };
                xhr.send(formData);
            } catch(e){ resolve(null); }
        });
    }

    // Final-resort fallback: submit a hidden form to an invisible iframe and read the response
    function formPostFallback(url, formData){
        return new Promise(function(resolve){
            try {
                let iframe = document.getElementById('ecw-delete-iframe');
                if (!iframe) {
                    iframe = document.createElement('iframe');
                    iframe.id = 'ecw-delete-iframe';
                    iframe.name = 'ecw-delete-iframe';
                    iframe.style.display = 'none';
                    document.body.appendChild(iframe);
                }

                const form = document.createElement('form');
                form.style.display = 'none';
                form.method = 'POST';
                form.action = url;
                form.target = iframe.name;

                // Copy entries from FormData into hidden inputs
                try {
                    for (const pair of formData.entries()) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = pair[0];
                        input.value = pair[1];
                        form.appendChild(input);
                    }
                } catch(e) { /* ignore */ }

                document.body.appendChild(form);

                const cleanup = function(){ try { form.remove(); } catch(e){} };

                // Wait for iframe load and attempt to parse response body as JSON
                iframe.onload = function(){
                    try {
                        const doc = iframe.contentDocument || iframe.contentWindow && iframe.contentWindow.document;
                        const txt = doc && doc.body ? (doc.body.textContent || doc.body.innerText || '') : '';
                        try { const j = txt ? JSON.parse(txt) : null; resolve(j); } catch(e) { resolve(null); }
                    } catch(e) { resolve(null); }
                    setTimeout(cleanup, 60);
                };

                // Submit the form to the hidden iframe
                try { form.submit(); } catch(e) { cleanup(); resolve(null); }
            } catch(e) { try{ resolve(null); } catch(err){} }
        });
    }

    // Centralized processor for deleting a material photo. We expose this so both delegated
    // and direct element listeners can call the same logic. The function guards against
    // double-processing by using a data attribute on the delete button element.
    async function processMaterialPhotoDelete(del){
        if (!del || del.dataset._processing === '1') return;
        del.dataset._processing = '1';
        // small UI debug helper (visible on-page) when ?debug=1 or window.__ECOWASTE_DEBUG__
        const _isDbg = (window.__ECOWASTE_DEBUG__ || /(?:\?|&|#)debug=1\b/.test((location && (location.search || '') + (location.hash || '')) || ''));
        // Always show a short visual indicator so the user has immediate feedback that
        // the delete handler executed — this helps when console/network logs are hidden.
        try {
            (function showTransientIndicator(){
                try {
                    const wrapper = del && del.closest ? del.closest('.material-photo') : null;
                    // Create a small floating badge near the clicked thumbnail
                    const badge = document.createElement('div');
                    badge.className = 'ecw-delete-indicator';
                    badge.textContent = 'Deleting...';
                    badge.style.position = 'fixed';
                    badge.style.zIndex = '2147483646';
                    badge.style.padding = '8px 12px';
                    badge.style.borderRadius = '6px';
                    badge.style.background = 'rgba(220,53,69,0.95)';
                    badge.style.color = '#fff';
                    badge.style.fontWeight = '700';
                    badge.style.fontSize = '13px';
                    // position near wrapper if possible, otherwise top-right
                    if (wrapper) {
                        try {
                            const r = wrapper.getBoundingClientRect();
                            badge.style.top = Math.max(8, (window.scrollY + r.top) - 6) + 'px';
                            badge.style.left = Math.max(8, (window.scrollX + r.left) - 6) + 'px';
                            badge.style.position = 'absolute';
                        } catch(e) { badge.style.top = '12px'; badge.style.right = '12px'; badge.style.position = 'fixed'; }
                    } else {
                        badge.style.top = '12px'; badge.style.right = '12px'; badge.style.position = 'fixed';
                    }
                    document.body.appendChild(badge);
                    // also flash the wrapper using boxShadow for better visibility than outline
                    try {
                        if (wrapper) {
                            const oldBS = wrapper.style.boxShadow || '';
                            wrapper.style.boxShadow = '0 0 0 4px rgba(220,53,69,0.95)';
                            wrapper.style.transition = 'box-shadow .22s ease, transform .18s ease, background-color .18s ease';
                            setTimeout(()=>{ try { wrapper.style.boxShadow = oldBS; } catch(e){} }, 700);
                        }
                    } catch(e){}
                    setTimeout(()=>{ try{ badge.remove(); } catch(e){} }, 900);
                } catch(e){}
            })();
        } catch(e){}
        function dbgUI(msg){
            try {
                if (!_isDbg) return;
                let el = document.getElementById('ecw-debug');
                if (!el) { el = document.createElement('div'); el.id = 'ecw-debug'; el.style.position='fixed'; el.style.right='12px'; el.style.top='12px'; el.style.zIndex='999999'; el.style.background='rgba(0,0,0,0.85)'; el.style.color='#fff'; el.style.padding='8px 10px'; el.style.borderRadius='6px'; el.style.fontSize='12px'; el.style.maxWidth='320px'; el.style.maxHeight='60vh'; el.style.overflow='auto'; document.body.appendChild(el); }
                const line = document.createElement('div'); line.textContent = (new Date()).toLocaleTimeString() + ' - ' + String(msg);
                el.insertBefore(line, el.firstChild);
                // keep it short
                while (el.childNodes.length > 8) el.removeChild(el.lastChild);
            } catch(e){}
        }
        try {
            try { const dbg = _isDbg; if (dbg) { try { console.debug('[material-photo-delete] processing', del); } catch(e){} } } catch(e){}
            const wrapper = del.closest('.material-photo');
            dbgUI('found wrapper: ' + (!!wrapper));
            if (!wrapper) return;
            // brief visual flash so user can see handler fired even if network is hidden
            try {
                const prevOutline = wrapper.style.outline || '';
                wrapper.style.outline = '3px solid rgba(220,53,69,0.95)';
                setTimeout(() => { try { wrapper.style.outline = prevOutline; } catch(e){} }, 1200);
            } catch(e) {}
            // Visual quick indicator so users can see the handler fired even if network/console is silent
            try {
                try { wrapper.__ecw_old_outline = wrapper.style.outline || ''; } catch(e){}
                try { wrapper.style.outline = '3px solid rgba(220,53,69,0.18)'; } catch(e){}
            } catch(e){}
            const photoId = wrapper.dataset.photoId || null;
            dbgUI('photoId: ' + String(photoId));
            if (!photoId) {
                // client-only photo element (no server id) — remove and restore upload control
                const parentMat = wrapper.closest('.material-item');
                wrapper.remove();
                try {
                    if (parentMat) {
                        const meta = parentMat.querySelector('.mat-meta');
                        const hasPhoto = parentMat.querySelector('.material-photos .material-photo');
                        if (meta && !hasPhoto && !meta.querySelector('.upload-material-photo')) {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'btn small upload-material-photo';
                            btn.setAttribute('data-material-id', parentMat.dataset.materialId || '');
                            btn.title = 'Upload photo';
                            btn.setAttribute('aria-label', 'Upload material photo');
                            btn.innerHTML = '<i class="fas fa-camera"></i>';
                            meta.appendChild(btn);
                        }
                    }
                } catch(e){ /* restore upload button failed (silenced) */ }
                try { refreshMaterialCollectionReqState(); } catch(e){}
                try { document.dispatchEvent(new Event('materialPhotoChanged')); } catch(e) {}
                dbgUI('removed client-only thumbnail');
                return;
            }
            dbgUI('will confirm delete');
            if (!confirm('Remove this photo?')) { dbgUI('user cancelled delete'); return; }
            dbgUI('user confirmed delete');
            try {
                const fd = new FormData(); fd.append('photo_id', photoId);
                dbgUI('sending delete POST: ' + photoId);
                let txt = null;
                try {
                    // Visible transport indicator for debugging in case fetch/XHR are blocked
                    try { showToast('Deleting… (attempting fetch)', 'success', true); } catch(e){}
                    const res = await fetch('delete_material_photo.php', { method: 'POST', body: fd });
                    txt = await res.text();
                } catch (fetchErr) {
                    dbgUI('fetch failed, attempting XHR fallback');
                    try { showToast('Deleting… (fetch failed — using XHR fallback)', 'error', true); } catch(e){}
                    const j = await xhrPostForm('delete_material_photo.php', fd);
                    if (j === null) { alert('Delete failed'); return; }
                    // emulate text response so downstream parser works
                    txt = typeof j === 'string' ? j : JSON.stringify(j);
                }
                let json = null; try { json = txt ? JSON.parse(txt) : null; } catch(e){ alert('Delete failed'); return; }
                dbgUI('delete response parsed: ' + (json && json.success ? 'success' : 'fail'));
                if (json && json.success) {
                    // Find the parent material row BEFORE removing the wrapper so we can restore controls reliably
                    const mat = document.querySelector('.material-item[data-material-id="' + (json.material_id || '') + '"]');
                    // use wrapper.closest since it points at the element to remove; fallback to del.closest
                    const parentMat = mat || (wrapper && wrapper.closest ? wrapper.closest('.material-item') : null) || (del && del.closest ? del.closest('.material-item') : null);
                    wrapper.remove();
                    dbgUI('removed server photo element');
                    // After removing photo, restore camera button beside Obtained badge if present
                    try {
                        if (parentMat) {
                            const photos = parentMat.querySelector('.material-photos');
                            const meta = parentMat.querySelector('.mat-meta');
                            const hasPhoto = photos && photos.querySelector('.material-photo:not(.placeholder)');
                            if (meta && !hasPhoto && !meta.querySelector('.upload-material-photo')) {
                                const btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'btn small upload-material-photo';
                                btn.setAttribute('data-material-id', parentMat.dataset.materialId || '');
                                btn.title = 'Upload photo';
                                btn.setAttribute('aria-label', 'Upload material photo');
                                btn.innerHTML = '<i class="fas fa-camera"></i>';
                                meta.appendChild(btn);
                                // focus but do not auto-open file picker — allow user control
                                try { btn.focus(); } catch(e){}
                            }
                        }
                    } catch(e) { /* Failed to restore camera button (silenced) */ }
                    // refresh stage completion state after delete and notify listeners
                    try { refreshMaterialCollectionReqState(); } catch(e){}
                    try { document.dispatchEvent(new Event('materialPhotoChanged')); } catch(e) {}
                } else {
                    alert(json && json.message ? json.message : 'Delete failed');
                }
            } catch (err) { alert('Delete failed'); }
        } finally {
            try { delete del.dataset._processing; } catch(e){}
            try { if (del && del.closest) { const w = del.closest('.material-photo'); if (w) { try { w.style.outline = w.__ecw_old_outline || ''; } catch(e){} } } } catch(e){}
        }
    }

    // Expose to global scope so inline onclick="processMaterialPhotoDelete(this)" works
    try { window.processMaterialPhotoDelete = processMaterialPhotoDelete; } catch(e) {}
    // Delegated handler: still listen on document for clicks as a fallback.
    document.addEventListener('click', function(ev){
        try {
            const del = ev.target.closest && ev.target.closest('.material-photo-delete');
            if (!del) return;
            if (del.dataset._processing === '1') return;
            // Small log to help debug when clicks reach the delegated handler
            try { if (window.__ECOWASTE_DEBUG__ || /(?:\?|&|#)debug=1\b/.test((location && (location.search || '') + (location.hash || '')) || '')) console.debug('[delegated] material-photo-delete clicked', del); } catch(e){}
            // Let the centralized processor handle it
            processMaterialPhotoDelete(del);
        } catch(e){
            try { console.error('Error in delegated material-photo-delete handler', e); } catch(err){}
        }
    });

    // As a last-resort capture-phase listener to ensure clicks reach us even when
    // other overlays stop propagation, listen for pointerdown in the capture phase.
    // This helps when a CSS overlay or layering prevents normal click handlers.
    try {
        document.addEventListener('pointerdown', function(ev){
            try {
                const del = ev.target && ev.target.closest ? ev.target.closest('.material-photo-delete') : null;
                if (!del) return;
                // If it's already processing, ignore
                if (del.dataset && del.dataset._processing === '1') return;
                // Prevent other handlers from interfering for this event
                try { ev.stopPropagation(); ev.preventDefault(); } catch(e){}
                // Call centralized processor
                processMaterialPhotoDelete(del);
            } catch(e){
                try { if (window.__ECOWASTE_DEBUG__) console.error('[capture] material-photo-delete handler error', e); } catch(ignore){}
            }
        }, { capture: true, passive: false });
    } catch(e) {}

    // Extra capture-phase handlers (mousedown / touchstart) to catch environments where pointer events
    // are unavailable or blocked. These call the same centralized processor but avoid preventing default
    // so normal behavior remains for non-delete clicks.
    try {
        document.addEventListener('mousedown', function(ev){
            try {
                const del = ev.target && ev.target.closest ? ev.target.closest('.material-photo-delete') : null;
                if (!del) return;
                if (del.dataset && del.dataset._processing === '1') return;
                // do not stop propagation here; just ensure handler runs
                try { processMaterialPhotoDelete(del); } catch(e) { dbgUI && dbgUI('[mousedown capture] error'); }
            } catch(e){}
        }, { capture: true, passive: true });
    } catch(e){}

    try {
        document.addEventListener('touchstart', function(ev){
            try {
                const del = ev.target && ev.target.closest ? ev.target.closest('.material-photo-delete') : null;
                if (!del) return;
                if (del.dataset && del.dataset._processing === '1') return;
                try { processMaterialPhotoDelete(del); } catch(e) { dbgUI && dbgUI('[touchstart capture] error'); }
            } catch(e){}
        }, { capture: true, passive: true });
    } catch(e){}

    // Also bind direct listeners to existing elements on load so clicks aren't blocked by overlay layers
    function bindExistingMaterialPhotoDeletes(){
        try {
            document.querySelectorAll('.material-photo-delete').forEach(function(d){
                try {
                    if (d.dataset._directBound === '1') return;
                    // attach several fallbacks: click listener, onclick attr, and touchstart for mobile
                    d.addEventListener('click', function(ev){ ev.stopPropagation(); if (d.dataset._processing === '1') return; processMaterialPhotoDelete(d); });
                    try { d.onclick = function(ev){ try{ ev && ev.preventDefault(); if (d.dataset._processing === '1') return; processMaterialPhotoDelete(d); } catch(e){} }; } catch(e){}
                    try { d.setAttribute('onclick', 'processMaterialPhotoDelete(this); return false;'); } catch(e){}
                    d.addEventListener('touchstart', function(ev){ try{ ev.stopPropagation(); if (d.dataset._processing === '1') return; processMaterialPhotoDelete(d); } catch(e){} }, { passive: true });
                    d.dataset._directBound = '1';
                } catch(e){}
            });
        } catch(e){}
    }

    document.addEventListener('DOMContentLoaded', function(){
        try {
            bindExistingMaterialPhotoDeletes();
            // Simple observer to attach to newly inserted thumbnails
            const phObserver = new MutationObserver(function(muts){
                for (const m of muts) {
                    if (!m.addedNodes) continue;
                    for (const n of m.addedNodes) {
                        try {
                            if (n.nodeType !== 1) continue;
                            if (n.matches && n.matches('.material-photo') ) {
                                const d = n.querySelector('.material-photo-delete');
                                if (d && d.dataset._directBound !== '1') {
                                    d.addEventListener('click', function(ev){ ev.stopPropagation(); if (d.dataset._processing === '1') return; processMaterialPhotoDelete(d); });
                                    d.dataset._directBound = '1';
                                }
                            } else if (n.querySelectorAll) {
                                const nodes = n.querySelectorAll('.material-photo-delete');
                                nodes && nodes.forEach(function(dd){ if (dd && dd.dataset._directBound !== '1') { dd.addEventListener('click', function(ev){ ev.stopPropagation(); if (dd.dataset._processing === '1') return; processMaterialPhotoDelete(dd); }); dd.dataset._directBound = '1'; } });
                            }
                        } catch(e){}
                    }
                }
            });
            try { phObserver.observe(document.body, { childList: true, subtree: true }); } catch(e){}
        } catch(e){}
    });
    // If DOM has already loaded by the time this script ran, bind immediately
    try { if (document.readyState && document.readyState !== 'loading') bindExistingMaterialPhotoDeletes(); } catch(e) {}

    // expose modal opener globally so inline onclick="showAddMaterialModal()" works
    window.showAddMaterialModal = showAddMaterialModal;

    // Material debug UI logic (visible only when ?debug=1)
    (function(){
        try {
            const dbgPanel = document.getElementById('matDebugPanel');
            if (!dbgPanel) return;
            const summary = document.getElementById('matDebugSummary');
            const recompute = document.getElementById('matDebugRecompute');
            const copyBtn = document.getElementById('matDebugCopy');

            function buildReport(){
                const items = Array.from(document.querySelectorAll('.materials-list-stage .material-item'));
                const total = items.length;
                const okItems = [];
                const missing = [];
                items.forEach(li=>{
                    const name = (li.querySelector('.mat-name') && li.querySelector('.mat-name').textContent.trim()) || ('#' + (li.dataset.materialId || ''));
                    const badge = !!li.querySelector('.badge.obtained');
                    const photo = !!li.querySelector('.material-photos .material-photo');
                    if (badge || photo) okItems.push(name); else missing.push(name);
                });
                const report = [];
                report.push('Total materials: ' + total);
                report.push('Satisfied (obtained or photo): ' + okItems.length);
                report.push('Missing: ' + missing.length + (missing.length ? '\n - ' + missing.join('\n - ') : ''));
                return { text: report.join('\n'), total, ok: okItems.length, missing };
            }

            function refreshDebug(){
                try {
                    const r = buildReport();
                    if (summary) summary.textContent = r.text;
                    // also ensure the stage button state is recomputed
                    refreshMaterialCollectionReqState();
                } catch(e){ /* silenced */ }
            }

            recompute && recompute.addEventListener('click', function(){ refreshDebug(); });
            copyBtn && copyBtn.addEventListener('click', function(){ const r = buildReport(); navigator.clipboard && navigator.clipboard.writeText(r.text).then(()=>{ copyBtn.textContent='Copied'; setTimeout(()=> copyBtn.textContent='Copy',800); }).catch(()=>{ alert('Copy failed'); }); });

            // initial run
            refreshDebug();
    } catch(e) { /* mat debug init failed (silenced) */ }
    })();

    // Reconcile donations feature removed from UI.

})();
</script>

<script>
// Fallback MutationObserver: if a new material-item is inserted into a stage that is currently marked completed,
// attempt to auto-uncomplete that stage (force_uncomplete). This helps when DOM timing prevents other flows
// from discovering the insertion in time.
(function(){
    try {
        const projectId = <?= json_encode($project_id) ?>;
        const recent = {}; // stageNum -> timestamp (ms) of last attempt
        const MIN_RETRY_MS = 2000;

        const observer = new MutationObserver(function(mutations){
            try {
                for (const m of mutations) {
                    if (!m.addedNodes || m.addedNodes.length === 0) continue;
                    for (const n of m.addedNodes) {
                        try {
                            const el = (n.nodeType === 1) ? n : null;
                            if (!el) continue;
                            // ignore nodes we marked ourselves
                            if (el.hasAttribute && el.hasAttribute('data-observer-ignore')) continue;
                            // if a wrapper added contains material-item children, also handle
                            let material = null;
                            if (el.classList && el.classList.contains('material-item')) material = el;
                            else {
                                material = el.querySelector && el.querySelector('.material-item');
                            }
                            if (!material) continue;

                            const stageEl = material.closest('.workflow-stage, .stage-card');
                            if (!stageEl) continue;

                            // if this stage is not currently completed, nothing to do
                            if (!stageEl.classList.contains('completed')) continue;

                            // determine stage number
                            let stageNum = null;
                            try {
                                stageNum = stageEl.getAttribute('data-stage-number') || stageEl.getAttribute('data-stage-index') || null;
                                if (!stageNum) {
                                    const lbl = stageEl.querySelector('.stage-status-label[data-stage-number]');
                                    if (lbl) stageNum = lbl.getAttribute('data-stage-number');
                                }
                                if (!stageNum) {
                                    const btn = stageEl.querySelector('.complete-stage-btn[data-stage-number], button[data-stage-number]');
                                    if (btn && btn.dataset && btn.dataset.stageNumber) stageNum = btn.dataset.stageNumber;
                                }
                                if (!stageNum) {
                                    const tab = document.querySelector('.stage-tab.active, .stage-tab.current');
                                    if (tab) stageNum = tab.getAttribute('data-stage-number') || tab.getAttribute('data-stage-index');
                                }
                            } catch(e) { stageNum = null; }

                            if (!stageNum) continue;
                            const sn = parseInt(stageNum, 10);
                            if (isNaN(sn)) continue;

                            const now = Date.now();
                            if (recent[sn] && (now - recent[sn]) < MIN_RETRY_MS) continue;
                            recent[sn] = now;

                            try { /* mut-observer: new material inserted (silenced) */ } catch(e){}

                            // Request uncomplete but mark any UI changes we make to avoid re-triggering observers
                            try {
                                // set a short-lived flag on the stage to indicate our change
                                if (stageEl.setAttribute) stageEl.setAttribute('data-observer-ignore', '1');
                                requestToggleStage(sn, projectId, { force_uncomplete: 1 }).then(r=>{
                                    try { /* mut-observer uncomplete response (silenced) */ } catch(e){}
                                }).catch(()=>{}).finally(()=>{
                                    try { if (stageEl.removeAttribute) setTimeout(()=> stageEl.removeAttribute('data-observer-ignore'), 250); } catch(e){}
                                });
                            } catch(e){}

                        } catch(e) { /* per-node ignore */ }
                    }
                }
            } catch(e) { /* ignore mutation loop errors */ }
        });

        // Prefer observing the materials container when present to limit mutation volume
        try {
            const materialsRoot = document.querySelector('.materials-list-stage') || document.querySelector('.materials-list') || document.body;
            observer.observe(materialsRoot, { childList: true, subtree: true });
        } catch(e) { /* ignore if observe fails */ }
    } catch(e) { /* ignore observer init errors */ }
})();
</script>

<script>
// Close any open debug panels when clicking outside or pressing Escape
document.addEventListener('click', function(e){
    if (!e.target.closest || !e.target.closest('.why-disabled-btn') && !e.target.closest('.why-disabled-panel')) {
        document.querySelectorAll('.why-disabled-panel').forEach(n=>n.remove());
    }
});
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') document.querySelectorAll('.why-disabled-panel').forEach(n=>n.remove()); });
</script>

<!-- Styles moved to assets/css/project-stages.css -->

<script>
document.addEventListener('DOMContentLoaded', function(){
    const tabs = document.querySelectorAll('.stage-tab');
    const stages = document.querySelectorAll('.workflow-stage');

    function showStageByIndex(idx){
        // Ensure canonical tabs/stage ordering present before selecting (helps avoid race with ensureThreeStageTabs)
        try { if (typeof ensureThreeStageTabs === 'function') ensureThreeStageTabs(); } catch(e){}

        // Toggle active classes on tabs and stages
        tabs.forEach(t => t.classList.toggle('active', parseInt(t.dataset.stageIndex,10) === idx));
        stages.forEach(s => s.classList.toggle('active', parseInt(s.getAttribute('data-stage-index'),10) === idx));

        // Keep tab-badges in sync with tab state (completed/current/locked/incomplete).
        try {
            tabs.forEach(t => {
                try {
                    const badge = t.querySelector('.tab-badge');
                    if (!badge) return;
                    // reset classes
                    badge.classList.remove('active','completed','locked','incomplete');
                    // prefer existing tab classes where available
                    if (t.classList.contains('completed')) { badge.classList.add('completed'); badge.textContent = 'Completed'; }
                    else if (t.classList.contains('active')) { badge.classList.add('active'); badge.textContent = 'Current'; }
                    else if (t.classList.contains('locked')) { badge.classList.add('locked'); badge.textContent = 'Locked'; }
                    else { badge.classList.add('incomplete'); badge.textContent = 'Incomplete'; }
                } catch(e){}
            });
        } catch(e){}
        // update URL hash for shareable link
        try {
            const hash = '#step-' + idx;
            if (history && history.replaceState) history.replaceState(null, '', hash);
            else location.hash = hash;
        } catch (e) {}
    }

    // add click handlers and keyboard support
    tabs.forEach((t, ti) => {
        t.setAttribute('tabindex', '0');
        t.dataset.stageIndex = t.dataset.stageIndex || ti;
        t.addEventListener('click', function(){
            const idx = parseInt(this.dataset.stageIndex,10);
            if (isNaN(idx)) return;
            // always activate the tab (this updates the URL hash via showStageByIndex)
            showStageByIndex(idx);
            // scroll the selected stage into view for clarity
            const s = document.querySelector('.workflow-stage[data-stage-index="' + idx + '"]');
            if (s) s.scrollIntoView({behavior:'smooth', block:'start'});
        });
        // keyboard nav: left/right to move, enter/space to activate
        t.addEventListener('keydown', function(ev){
            if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') {
                ev.preventDefault();
                const next = tabs[(ti + 1) % tabs.length];
                if (next) next.focus();
            } else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                const prev = tabs[(ti - 1 + tabs.length) % tabs.length];
                if (prev) prev.focus();
            } else if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                this.click();
            }
        });
    });

    // on load, check URL hash like #step-2 and open that step if present
    try {
        const m = (location.hash || '').match(/#step-(\d+)/i);
        if (m && m[1]) {
            const idx = parseInt(m[1], 10);
            if (!isNaN(idx)) showStageByIndex(idx);
        }
    } catch (e) {}
    // always show only the current stage by default (no 'show all' control)
    // initialize to current active tab or index 0
    (function(){
        const active = document.querySelector('.stage-tab.active');
        const idx = active ? parseInt(active.dataset.stageIndex,10) : 0;
        showStageByIndex(idx);
    })();

    // JS fallback: ensure locked tabs show a consistent forbidden cursor on hover
    try {
        const svgCursor = `url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='32' height='32'><circle cx='16' cy='16' r='12' stroke='black' stroke-width='2' fill='white'/><line x1='6' y1='26' x2='26' y2='6' stroke='black' stroke-width='3' stroke-linecap='round'/></svg>") 16 16, not-allowed`;
        document.querySelectorAll('.stage-tab.locked').forEach(el => {
            el.addEventListener('mouseenter', function(){ el.style.cursor = svgCursor; el.style.pointerEvents = 'auto'; });
            el.addEventListener('mouseleave', function(){ el.style.cursor = ''; });
        });
    } catch (e) { /* ignore */ }
    // (explicit uncomplete button removed — completion toggles now happen automatically when materials change)
});
</script>

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
                            // preserve the original template stage_number as 'template_number'
                            $workflow_stages[] = [
                                'name' => $row['stage_name'],
                                'description' => $row['description'],
                                'number' => (int)$row['stage_number'], // will be renumbered later
                                'template_number' => (int)$row['stage_number']
                            ];
                        }
                    }
                } catch (Exception $e) {
                    $workflow_stages = [];
                }

                    // Defensive deduplication: ensure unique stage names (preserve original order)
                    // Then normalize the workflow to the simplified three-stage layout requested by the UI:
                    // 1) Preparation (this will host the Material Collection UI), 2) Construction, 3) Finishing
                    if (!empty($workflow_stages)) {
                        $seen = [];
                        $filtered = [];
                        foreach ($workflow_stages as $st) {
                            $name = trim(strtolower($st['name'] ?? $st['stage_name'] ?? ''));
                            if ($name === '') continue;
                            if (isset($seen[$name])) continue; // skip duplicates
                            $seen[$name] = true;
                            $filtered[] = $st;
                        }
                        // renumber sequentially starting at 1 for UI ordering, but keep original template_number
                        foreach ($filtered as $i => &$fs) { $fs['number'] = $i + 1; if (!isset($fs['template_number'])) $fs['template_number'] = $fs['number']; }
                        unset($fs);
                        $workflow_stages = array_values($filtered);

                        // Normalize into the three-stage set. We prefer existing templates when possible.
                        $newStages = [];

                        // 1) Preparation: prefer an existing 'material' stage (rename it to Preparation),
                        // otherwise prefer an existing 'prepar' stage, otherwise create a generic Preparation.
                        $foundPrep = false;
                        foreach ($workflow_stages as $ws) {
                            $n = strtolower(trim((string)($ws['stage_name'] ?? $ws['name'] ?? '')));
                            if ($n !== '' && stripos($n, 'material') !== false) {
                                $ws['name'] = 'Preparation';
                                $newStages[] = $ws;
                                $foundPrep = true;
                                break;
                            }
                        }
                        if (!$foundPrep) {
                            // look for an existing preparation stage (we'll reuse it but prefer material semantics)
                            foreach ($workflow_stages as $ws) {
                                $n = strtolower(trim((string)($ws['stage_name'] ?? $ws['name'] ?? '')));
                                if ($n !== '' && stripos($n, 'prepar') !== false) {
                                    // use it but clear tasks in UI are handled separately; keep description
                                    $ws['name'] = 'Preparation';
                                    $newStages[] = $ws;
                                    $foundPrep = true;
                                    break;
                                }
                            }
                        }
                        if (!$foundPrep) {
                            $newStages[] = ['name' => 'Preparation', 'description' => 'Collect materials required for this project', 'number' => 1];
                        }

                        // 2) Construction
                        $found = false;
                        foreach ($workflow_stages as $ws) {
                            $n = strtolower(trim((string)($ws['stage_name'] ?? $ws['name'] ?? '')));
                            if ($n !== '' && stripos($n, 'construct') !== false) {
                                $ws['name'] = 'Construction';
                                $newStages[] = $ws;
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $newStages[] = ['name' => 'Construction', 'description' => 'Build your project, follow safety guidelines, document progress', 'number' => 2];
                        }

                        // 3) Share
                        $found = false;
                        foreach ($workflow_stages as $ws) {
                            $n = strtolower(trim((string)($ws['stage_name'] ?? $ws['name'] ?? '')));
                            if ($n !== '' && (stripos($n, 'finish') !== false || stripos($n, 'share') !== false)) {
                                $ws['name'] = 'Share';
                                $newStages[] = $ws;
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $newStages[] = ['name' => 'Share', 'description' => 'Share your project with the community', 'number' => 3];
                        }

                        // assign sequential numbers and ensure template_number exists
                        foreach ($newStages as $i => &$ns) { $ns['number'] = $i + 1; if (!isset($ns['template_number'])) $ns['template_number'] = $ns['number']; }
                        unset($ns);
                        $workflow_stages = $newStages;

                        // Safety: ensure the first (visible) stage is Preparation so materials UI appears.
                        // If templates produced a different ordering, insert a Preparation stage at index 0.
                        $firstName = strtolower(trim((string)($workflow_stages[0]['name'] ?? '')));
                        if (stripos($firstName, 'prepar') === false && stripos($firstName, 'material') === false) {
                            array_unshift($workflow_stages, ['name' => 'Preparation', 'description' => 'Collect materials required for this project', 'number' => 1]);
                            // renumber again
                            foreach ($workflow_stages as $i => &$fs2) { $fs2['number'] = $i + 1; if (!isset($fs2['template_number'])) $fs2['template_number'] = $fs2['number']; }
                            unset($fs2);
                        }
                    }

                if (empty($workflow_stages)) {
                    $workflow_stages = [
                        ['name' => 'Preparation', 'description' => 'Collect materials required for this project', 'number' => 1],
                        ['name' => 'Construction', 'description' => 'Build your project, follow safety guidelines, document progress', 'number' => 2],
                        ['name' => 'Share', 'description' => 'Share your project with the community', 'number' => 3],
                    ];
                }

                // Icon map by stage_number (fallback)
                $stage_icons = [1 => 'fa-lightbulb', 2 => 'fa-box', 3 => 'fa-hammer', 4 => 'fa-paint-roller', 5 => 'fa-star', 6 => 'fa-camera'];

                // Normalize number of top-level workflow tabs to exactly 3 stages
                // We prefer existing templates when their names match the canonical set,
                // otherwise fall back to sensible defaults. This guarantees consistent
                // UI: Preparation, Construction, Share.
                try {
                    // Load canonical 3-stage definition from a centralized include so all
                    // project detail variants use the same stage list and cannot drift.
                    $canonical = [];
                    try {
                        $path = __DIR__ . '/includes/project_stages.php';
                        if (file_exists($path)) {
                            $canonical = include $path;
                        }
                    } catch (Exception $e) { $canonical = []; }

                    // Fallback to embedded canonical set if include couldn't be loaded
                    if (empty($canonical)) {
                        $canonical = [
                            ['name' => 'Preparation', 'description' => 'Collect materials required for this project'],
                            ['name' => 'Construction', 'description' => 'Build your project, follow safety guidelines, document progress'],
                            ['name' => 'Share', 'description' => 'Share your project with the community']
                        ];
                    }

                    // prepare a map of existing stages by normalized name
                    $haveMap = [];
                    foreach ($workflow_stages as $st) {
                        $nm = strtolower(trim((string)($st['name'] ?? $st['stage_name'] ?? '')));
                        if ($nm === '') continue;
                        $haveMap[$nm] = $st;
                    }

                    $normalized = [];
                    foreach ($canonical as $idx => $canon) {
                        $key = strtolower($canon['name']);
                        if (isset($haveMap[$key])) {
                            // preserve description/template_number when present
                            $item = $haveMap[$key];
                            $item['name'] = $canon['name'];
                            if (!isset($item['description']) || trim($item['description']) === '') $item['description'] = $canon['description'];
                            $normalized[] = $item;
                        } else {
                            // create a minimal canonical entry
                            $normalized[] = ['name' => $canon['name'], 'description' => $canon['description'], 'number' => $idx + 1, 'template_number' => ($idx + 1)];
                        }
                    }

                    // set workflow_stages to exactly the normalized 3 entries
                    $workflow_stages = $normalized;

                } catch (Exception $e) {
                    // keep whatever $workflow_stages had — fail silently to avoid breaking page
                }

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
                        // Use the original template number if present; fall back to UI number
                        $num = isset($st['template_number']) ? (int)$st['template_number'] : (isset($st['number']) ? (int)$st['number'] : ($i + 1));
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

                <!-- Tabs: show step titles and highlight current -->
                <div class="stage-tabs">
                    <?php foreach ($workflow_stages as $i => $st): 
                        $tn = isset($st['template_number']) ? (int)$st['template_number'] : (int)($st['stage_number'] ?? $st['number'] ?? $i + 1);
                        // determine badge class
                        $is_completed = array_key_exists($i, $completed_stage_map);
                        // If this is a Material Collection (now also 'Preparation') stage and DB shows completed, re-validate server-side
                        $stage_name_lower = strtolower($st['stage_name'] ?? $st['name'] ?? '');
                        if ($is_completed && (stripos($stage_name_lower, 'material') !== false || stripos($stage_name_lower, 'prepar') !== false)) {
                            try {
                                $cstmt = $conn->prepare("SELECT COUNT(*) AS not_obtained FROM project_materials WHERE project_id = ? AND (status IS NULL OR LOWER(status) <> 'obtained')");
                                $cstmt->bind_param('i', $project_id);
                                $cstmt->execute();
                                $cres = $cstmt->get_result()->fetch_assoc();
                                $not_obtained = $cres ? (int)$cres['not_obtained'] : 0;
                                if ($not_obtained > 0) {
                                    // some materials still missing — treat as not completed for rendering
                                    $is_completed = false;
                                }
                            } catch (Exception $e) { /* ignore validation errors */ }
                        }
                        $is_current = !$is_completed && ($i === $current_stage_index);
                        $is_locked = !$is_completed && ($i > $current_stage_index);
                        $badgeClass = $is_completed ? 'completed' : ($is_current ? 'current' : ($is_locked ? 'locked' : 'incomplete'));

                        // determine small thumbnail: prefer stage_photos, fallback to material photo for material collection
                        $thumb = '';
                        try {
                            // stage photos
                            $tp = $conn->prepare("SELECT photo_path FROM stage_photos WHERE project_id = ? AND stage_number = ? ORDER BY created_at DESC LIMIT 1");
                            $tp->bind_param('ii', $project_id, $tn);
                            $tp->execute();
                            $tres = $tp->get_result()->fetch_assoc();
                            if ($tres && !empty($tres['photo_path'])) $thumb = 'assets/uploads/' . $tres['photo_path'];
                        } catch (Exception $e) { /* ignore */ }
                        if (empty($thumb) && (stripos($st['stage_name'] ?? $st['name'] ?? '', 'material') !== false || stripos($st['stage_name'] ?? $st['name'] ?? '', 'prepar') !== false)) {
                            try {
                                $mp = $conn->prepare("SELECT photo_path FROM material_photos WHERE project_id = ? AND material_id IN (SELECT material_id FROM project_materials WHERE project_id = ?) ORDER BY created_at DESC LIMIT 1");
                                $mp->bind_param('ii', $project_id, $project_id);
                                $mp->execute();
                                $mres = $mp->get_result()->fetch_assoc();
                                if ($mres && !empty($mres['photo_path'])) $thumb = 'assets/uploads/' . $mres['photo_path'];
                            } catch (Exception $e) { /* ignore */ }
                        }
                    ?>
                        <?php
                            $stage_name_lower = strtolower($st['stage_name'] ?? $st['name'] ?? '');
                            $iconClass = 'fas fa-circle';
                                    if (stripos($stage_name_lower, 'material') !== false || stripos($stage_name_lower, 'prepar') !== false) $iconClass = 'fas fa-box-open';
                            else if (stripos($stage_name_lower, 'prepar') !== false) $iconClass = 'fas fa-tools';
                            else if (stripos($stage_name_lower, 'construct') !== false) $iconClass = 'fas fa-hard-hat';
                            else if (stripos($stage_name_lower, 'finish') !== false) $iconClass = 'fas fa-paint-roller';
                            else if (stripos($stage_name_lower, 'share') !== false) $iconClass = 'fas fa-share-alt';
                            // Use !important on inline style to override any stylesheet rules that may use !important
                            $inlineStyle = $is_locked ? 'style="cursor: not-allowed !important;"' : '';
                        ?>
                        <button <?= $inlineStyle ?> class="stage-tab <?php echo ($i === $current_stage_index) ? 'active' : ''; ?> <?php echo $is_locked ? 'locked' : ''; ?>" data-stage-index="<?= $i ?>" data-stage-number="<?= $tn ?>" aria-label="<?= htmlspecialchars($st['stage_name'] ?? $st['name'] ?? 'Step') ?>">
                            <span class="tab-icon"><i class="<?= $iconClass ?>" aria-hidden="true"></i></span>
                            <span class="tab-meta">
                                <span class="tab-title"><?= htmlspecialchars($st['stage_name'] ?? $st['name'] ?? ('Step ' . ($i+1))) ?></span>
                                <span class="tab-badge <?= $badgeClass ?>"><?php echo $is_completed ? 'Completed' : ($is_current ? 'Current' : ($is_locked ? 'Locked' : 'Incomplete')) ?></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                    <div style="margin-left:auto;font-size:13px;color:#666;">
                        <!-- removed 'Show all steps' control for simplified UI -->
                    </div>
                </div>

                <div class="workflow-stages-container stages-timeline">
                    <?php
                    // $completed_stage_map was built above. Use it to render stages.
                    foreach ($workflow_stages as $index => $stage): 
                        $is_completed = array_key_exists($index, $completed_stage_map);
                        // re-validate Material Collection completed state to avoid false positives
                        $stage_name_lower = strtolower($stage['name'] ?? $stage['stage_name'] ?? '');
                        if ($is_completed && stripos($stage_name_lower, 'material') !== false) {
                            try {
                                $cstmt2 = $conn->prepare("SELECT COUNT(*) AS not_obtained FROM project_materials WHERE project_id = ? AND (status IS NULL OR LOWER(status) <> 'obtained')");
                                $cstmt2->bind_param('i', $project_id);
                                $cstmt2->execute();
                                $cres2 = $cstmt2->get_result()->fetch_assoc();
                                $not_obtained2 = $cres2 ? (int)$cres2['not_obtained'] : 0;
                                if ($not_obtained2 > 0) {
                                    $is_completed = false;
                                }
                            } catch (Exception $e) { /* ignore */ }
                        }
                        // current stage is the first incomplete stage (index == completed count)
                        $is_current = !$is_completed && ($index === $current_stage_index);
                        // locked if it's after the current stage and not completed
                        $is_locked = !$is_completed && ($index > $current_stage_index);
                        if ($is_completed) {
                            $stage_class = 'completed';
                        } elseif ($is_current) {
                            $stage_class = 'current';
                        } elseif ($index > $current_stage_index) {
                            $stage_class = 'locked';
                        } else {
                            // earlier incomplete stages are shown as inactive (not locked)
                            $stage_class = 'inactive';
                        }
                    ?>
                        <?php $activeClass = $is_current ? 'active' : ''; ?>
                        <div class="workflow-stage stage-card <?= $stage_class ?> <?= $activeClass ?>" data-stage-index="<?= $index ?>">
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
                            <?php if (in_array(strtolower(trim($stage['name'] ?? '')), ['material collection','preparation'])): ?>
    <div class="stage-materials">
        <h4>Materials Needed</h4>
        <?php if (isset($is_debug) && $is_debug): ?>
        <div id="matDebugPanel" style="border:1px dashed #bfe6c9;padding:10px;margin:8px 0;border-radius:8px;background:#f7fbf7;color:#0f5132;">
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                <strong style="flex:1;">Material Collection Debug</strong>
                <button id="matDebugRecompute" class="btn small" style="background:#fff;color:#1b543d;border:1px solid rgba(0,0,0,0.06);">Recompute</button>
                <button id="matDebugCopy" class="btn small" style="background:#fff;color:#1b543d;border:1px solid rgba(0,0,0,0.06);">Copy</button>
            </div>
            <div id="matDebugSummary" style="font-size:13px;white-space:pre-wrap;max-height:160px;overflow:auto;background:transparent;padding:6px 4px;">Computing...</div>
        </div>
        <?php endif; ?>
        <?php if (empty($materials)): ?>
            <p class="empty-state">No materials listed.</p>
        <?php else: ?>
            <ul class="materials-list-stage">
                <?php foreach ($materials as $m): ?>
                    <?php $mid = (int)($m['material_id'] ?? $m['id'] ?? 0); ?>
                        <?php 
                        $currentQty = isset($m['quantity']) ? (int)$m['quantity'] : 0;
                        $origQty = isset($m['original_quantity']) && $m['original_quantity'] !== null ? (int)$m['original_quantity'] : $currentQty;
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
                    <li class="material-item<?= ($currentStatus !== 'needed') ? ' material-obtained' : '' ?>" data-material-id="<?= $mid ?>" data-original-quantity="<?= htmlspecialchars($origQty) ?>">
                        <div class="material-main">
                            <span class="mat-name"><?= htmlspecialchars($m['material_name'] ?? $m['name'] ?? '') ?></span>
                            <div class="mat-meta">
                                <?php if ($currentQty >= 0): ?>
                                            <?php $obtained = max(0, $origQty - $currentQty); ?>
                                            <span class="mat-qty"><?= htmlspecialchars($obtained) ?> / <?= htmlspecialchars($origQty) ?></span>
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
                                        echo '<div class="material-photo" data-photo-id="' . $firstPhotoId . '"><img src="' . $firstPhotoRel . '" alt="Material photo" onclick="openImageViewer(\'' . $firstPhotoRel . '\')"><button type="button" class="material-photo-delete" title="Delete photo" onclick="(function(el){try{el.style.boxShadow=\'0 0 0 6px rgba(220,53,69,0.95)\'; setTimeout(function(){try{el.style.boxShadow=\\\'\\\';}catch(e){}},800);}catch(e){}; try{processMaterialPhotoDelete(el);}catch(e){}; })(this); return false;" onpointerdown="try{this.style.boxShadow=\'0 0 0 6px rgba(220,53,69,0.95)\';}catch(e){}" onmousedown="try{this.style.boxShadow=\'0 0 0 6px rgba(220,53,69,0.95)\';}catch(e){}" ontouchstart="try{this.style.boxShadow=\'0 0 0 6px rgba(220,53,69,0.95)\';}catch(e){}"><i class="fas fa-trash"></i></button></div>';
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
                                <form method="POST" class="inline-form" data-obtain-modal="1" style="display:inline-flex !important;align-items:center;margin:0;padding:0;">
                                    <input type="hidden" name="material_id" value="<?= $mid ?>">
                                    <input type="hidden" name="status" value="obtained">
                                    <button type="submit" name="update_material_status" class="btn small obtain-btn" title="Mark obtained" aria-label="Mark material obtained" style="display:inline-block !important;visibility:visible !important;">
                                        <i class="fas fa-check" aria-hidden="true"></i> Check
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
                                                <div class="stage-actions-left">
                                                <?php if (in_array(strtolower(trim($stage['name'] ?? '')), ['material collection','preparation'])): ?>
                                                    <button type="button" class="btn add-material-btn" onclick="showAddMaterialModal()">
                                                        <i class="fas fa-plus"></i> Add Material
                                                    </button>
                                                </div>
                                                <div class="stage-actions-right">
                                                    <!-- Proceed button: enabled only when all materials obtained and photos present -->
                                                    <button type="button" class="btn proceed-stage-btn is-disabled" data-stage-number="<?= htmlspecialchars($stage['number'] ?? '') ?>" aria-disabled="true" title="Proceed to next stage">
                                                        Proceed
                                                    </button>
                                                </div>
                                                <?php endif; ?>
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
                let data = null;
                try { data = await res.json(); } catch (err) { return; }
                if (data && data.error) return;

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
            } catch (err) { /* Error fetching notifications (silenced) */ }
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
        // Updated createAddMaterialModal function with categorized dropdown
        function createAddMaterialModal(){
            if (document.getElementById('addMaterialModal')) {
                return document.getElementById('addMaterialModal');
            }
            
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
                    <form method="POST" id="addMaterialForm">
                        <div class="form-group">
                            <label for="material_category">Select Waste Category *</label>
                            <select id="material_category" name="material_category" required class="category-select">
                                <option value="">-- Select a category --</option>
                                <!-- Categories will be populated by JavaScript -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="material_name">Material Name *</label>
                            <select id="material_name" name="material_name" required class="material-select" disabled>
                                <option value="">-- First select a category --</option>
                            </select>
                            <div class="form-hint">Or enter custom material name:</div>
                            <input type="text" id="custom_material_name" name="custom_material_name" 
                                placeholder="Enter custom material name" style="margin-top: 5px; display: none;">
                        </div>
                        <div class="form-group">
                            <label for="quantity">Quantity *</label>
                            <input type="number" id="quantity" name="quantity" min="1" value="1" required 
                                placeholder="Enter quantity">
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="action-btn" data-action="close-add">Cancel</button>
                            <button type="submit" name="add_material" class="action-btn check-btn">
                                <i class="fas fa-plus"></i> Add Material
                            </button>
                        </div>
                    </form>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Initialize the category dropdown
            initializeCategoryDropdown();
            
            // Attach close handlers
            modal.querySelectorAll('[data-action="close-add"]').forEach(btn => {
                btn.addEventListener('click', function(ev) {
                    ev.preventDefault();
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                });
            });
            
            // Overlay click
            modal.addEventListener('click', function(e) {
                if (e.target === modal && modal.dataset.persistent !== '1') {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
            
            return modal;
        }

        // Initialize category dropdown with waste categories
        function initializeCategoryDropdown() {
            const categorySelect = document.getElementById('material_category');
            const materialSelect = document.getElementById('material_name');
            const customInput = document.getElementById('custom_material_name');
            const otherWasteGroup = document.getElementById("otherWasteGroup");
            const otherWasteInput = document.getElementById("otherWaste");
            
            if (!categorySelect) return;
            
            // Clear existing options
            categorySelect.innerHTML = '<option value="">-- Select a category --</option>';
            
            // Define waste categories structure using your subcategories
            const wasteCategories = [
                {
                    id: 'Plastic',
                    name: '♻️ Plastic',
                    subcategories: [
                        'Plastic Bottles',
                        'Plastic Containers', 
                        'Plastic Bags',
                        'Wrappers',
                        'Other Plastic'
                    ]
                },
                {
                    id: 'Paper',
                    name: '📄 Paper',
                    subcategories: [
                        'Newspapers',
                        'Cardboard',
                        'Magazines',
                        'Office Paper',
                        'Other Paper'
                    ]
                },
                {
                    id: 'Glass',
                    name: '🍶 Glass',
                    subcategories: [
                        'Glass Bottles',
                        'Glass Jars',
                        'Broken Glassware',
                        'Other Glass'
                    ]
                },
                {
                    id: 'Metal',
                    name: '🔩 Metal',
                    subcategories: [
                        'Aluminum Cans',
                        'Tin Cans',
                        'Scrap Metal',
                        'Other Metal'
                    ]
                },
                {
                    id: 'Electronic',
                    name: '🔌 Electronic Waste',
                    subcategories: [
                        'Old Phones',
                        'Chargers',
                        'Batteries',
                        'Broken Gadgets',
                        'Other Electronic'
                    ]
                }
            ];
            
            // Populate category dropdown
            wasteCategories.forEach(category => {
                const option = document.createElement('option');
                option.value = category.id;
                option.textContent = category.name;
                categorySelect.appendChild(option);
            });
            
            // Add "Other" category option
            const otherOption = document.createElement('option');
            otherOption.value = 'Other';
            otherOption.textContent = '💡 Other Materials';
            categorySelect.appendChild(otherOption);
            
            // Category change handler
            categorySelect.addEventListener('change', function() {
                const selectedCategory = this.value;
                
                // Reset states
                materialSelect.innerHTML = '<option value="">-- Select a material --</option>';
                materialSelect.disabled = true;
                
                // Handle Other Waste Group visibility
                if (otherWasteGroup && otherWasteInput) {
                    if (selectedCategory === 'Other') {
                        otherWasteGroup.style.display = 'block';
                        otherWasteInput.required = true;
                        materialSelect.style.display = 'none';
                        if (customInput) customInput.style.display = 'none';
                    } else {
                        otherWasteGroup.style.display = 'none';
                        otherWasteInput.required = false;
                        materialSelect.style.display = 'block';
                    }
                }
                
                // Handle custom input visibility
                if (customInput) {
                    customInput.style.display = 'none';
                    customInput.required = false;
                    materialSelect.required = true;
                }
                
                if (selectedCategory === 'Other') {
                    // Focus on other waste input when Other category is selected
                    if (otherWasteInput) {
                        setTimeout(() => otherWasteInput.focus(), 100);
                    }
                } else if (selectedCategory) {
                    // Regular category flow - populate subcategories
                    materialSelect.disabled = false;
                    
                    // Find the selected category and populate its subcategories
                    const category = wasteCategories.find(cat => cat.id === selectedCategory);
                    if (category && category.subcategories) {
                        category.subcategories.forEach(subcat => {
                            const option = document.createElement('option');
                            option.value = subcat;
                            option.textContent = subcat;
                            materialSelect.appendChild(option);
                        });
                    }
                    
                    // Focus material select
                    setTimeout(() => materialSelect.focus(), 100);
                }
            });
            
            // Material select change handler for "Other" subcategories
            materialSelect.addEventListener('change', function() {
                const selectedValue = this.value;
                
                // Show custom input when user selects "Other [Category]" option
                if (selectedValue && selectedValue.startsWith('Other ') && customInput) {
                    customInput.style.display = 'block';
                    customInput.required = true;
                    customInput.placeholder = `Specify ${selectedValue.toLowerCase()}`;
                    setTimeout(() => customInput.focus(), 100);
                } else if (customInput) {
                    customInput.style.display = 'none';
                    customInput.required = false;
                }
            });
            
            // Form submission handler to ensure proper data collection
            const addMaterialForm = document.getElementById('addMaterialForm');
            if (addMaterialForm) {
                addMaterialForm.addEventListener('submit', function(e) {
                    // Ensure the final material name is captured correctly
                    const category = categorySelect.value;
                    let finalMaterialName = '';
                    
                    if (category === 'Other' && otherWasteInput) {
                        // Use the other waste input value
                        finalMaterialName = otherWasteInput.value.trim();
                    } else if (materialSelect.value && materialSelect.value.startsWith('Other ')) {
                        // Use the custom input value for "Other" subcategories
                        finalMaterialName = customInput ? customInput.value.trim() : materialSelect.value;
                    } else {
                        // Use the selected material value
                        finalMaterialName = materialSelect.value;
                    }
                    
                    // You can add validation here if needed
                    if (category === 'Other' && !finalMaterialName) {
                        e.preventDefault();
                        showToast('Please specify the other material', 'error');
                        return;
                    }
                    
                    if (materialSelect.value.startsWith('Other ') && !finalMaterialName) {
                        e.preventDefault();
                        showToast('Please specify the material details', 'error');
                        return;
                    }
                    
                    // Set the final material name in a hidden field or ensure it's captured
                    // This depends on your form structure and backend expectations
                });
            }
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
        (function initSharedModal(attemptsLeft){
            attemptsLeft = typeof attemptsLeft === 'number' ? attemptsLeft : 20; // ~2s total (20 * 100ms)
            if (window.sharedModalAPI && typeof window.sharedModalAPI.init === 'function') {
                try {
                    window.sharedModalAPI.init({ projectId: <?= $project_id ?>, materials: materialsData, steps: stepsData });
                } catch (e) {
                    /* sharedModalAPI.init() threw (silenced) */
                }
                // attach click handler that safely opens the modal if available
                shareBtn.addEventListener('click', function(){
                    closeAllModals();
                    if (window.sharedModalAPI && typeof window.sharedModalAPI.open === 'function') {
                        window.sharedModalAPI.open();
                    }
                });
            } else if (attemptsLeft > 0) {
                // try again shortly in case the library is still loading
                setTimeout(function(){ initSharedModal(attemptsLeft - 1); }, 100);
            } else {
                // give up after retries; attach a safe click handler so UX isn't broken
                shareBtn.addEventListener('click', function(){ closeAllModals(); });
            }
        })();
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
    // -------------------------
    // Feedback Modal
    // -------------------------
    const feedbackBtn = document.getElementById('feedbackBtn');
    const feedbackModal = document.getElementById('feedbackModal');
    const feedbackCloseBtn = document.getElementById('feedbackCloseBtn');
    const emojiOptions = document.querySelectorAll('.emoji-option');
    const feedbackForm = document.getElementById('feedbackForm');
    const thankYouMessage = document.getElementById('thankYouMessage');
    const feedbackSubmitBtn = document.getElementById('feedbackSubmitBtn');
    const spinner = document.getElementById('spinner');
    const ratingError = document.getElementById('ratingError');
    const textError = document.getElementById('textError');
    const feedbackText = document.getElementById('feedbackText');
    let selectedRating = 0;

    if(feedbackBtn && feedbackModal && feedbackCloseBtn && feedbackForm){
        // Emoji selection
        emojiOptions.forEach(option => {
            option.addEventListener('click', () => {
                emojiOptions.forEach(opt => opt.classList.remove('selected'));
                option.classList.add('selected');
                selectedRating = option.getAttribute('data-rating');
                ratingError.style.display = 'none';
            });
        });

        // Submit feedback
        feedbackForm.addEventListener('submit', function(e) {
            e.preventDefault();
            let isValid = true;

            if (selectedRating === 0) {
                ratingError.style.display = 'block';
                isValid = false;
            } else {
                ratingError.style.display = 'none';
            }

            if (feedbackText.value.trim() === '') {
                textError.style.display = 'block';
                isValid = false;
            } else {
                textError.style.display = 'none';
            }

            if (!isValid) return;

            feedbackSubmitBtn.disabled = true;
            spinner.style.display = 'block';

            setTimeout(() => {
                spinner.style.display = 'none';
                feedbackForm.style.display = 'none';
                thankYouMessage.style.display = 'block';

                setTimeout(() => {
                    closeFeedbackModal();
                }, 3000);

            }, 1500);
        });

        // Open/Close feedback modal
        feedbackBtn.addEventListener('click', () => {
            feedbackModal.style.display = 'flex';
        });
        feedbackCloseBtn.addEventListener('click', closeFeedbackModal);
        window.addEventListener('click', (event) => {
            if (event.target === feedbackModal) {
                closeFeedbackModal();
            }
        });
    }

    function closeFeedbackModal() {
        feedbackModal.style.display = 'none';
        feedbackForm.style.display = 'block';
        thankYouMessage.style.display = 'none';
        feedbackText.value = '';
        emojiOptions.forEach(opt => opt.classList.remove('selected'));
        selectedRating = 0;
        ratingError.style.display = 'none';
        textError.style.display = 'none';
        feedbackSubmitBtn.disabled = false;
        spinner.style.display = 'none';
    }

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
    <style>
    /* Proceed button styling: right aligned, disabled (gray) -> enabled (green) */
    .stage-actions { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .stage-actions .stage-actions-left, .stage-actions .stage-actions-right { display:flex; align-items:center; gap:8px; }
    .proceed-stage-btn { padding: 8px 14px; border-radius: 8px; border: 1px solid #cfcfcf; background: #ffffff; color: #111; cursor: not-allowed; opacity: 0.6; transition: all .12s ease; font-weight:600; }
    .proceed-stage-btn.is-disabled { pointer-events: none; }
    .proceed-stage-btn:not(.is-disabled) { background:#2E8B57; border-color:#2E8B57; color:#fff; opacity:1; cursor:pointer; box-shadow: 0 6px 20px rgba(46,139,87,0.18); }
    .proceed-stage-btn.is-processing { opacity:0.85; transform: translateY(0); }
    </style>

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
document.addEventListener('DOMContentLoaded', function(){
    try {
        const searchAndHash = String((location && (location.search || '') + (location.hash || '')) || '');
        const isDebug = /(?:\?|&|#)debug=1\b/.test(searchAndHash) || searchAndHash.indexOf('debug=1') !== -1;
        if (!isDebug) return;
        // Create overlay skeleton if not present
        if (!document.getElementById('matDebugOverlay')) {
            const dbg = document.createElement('div'); dbg.id = 'matDebugOverlay';
            dbg.style.position = 'fixed'; dbg.style.right = '12px'; dbg.style.bottom = '12px'; dbg.style.width = '360px'; dbg.style.maxHeight = '60vh'; dbg.style.overflow = 'auto'; dbg.style.background = 'rgba(0,0,0,0.85)'; dbg.style.color = '#fff'; dbg.style.zIndex = '99999'; dbg.style.padding = '10px'; dbg.style.fontSize = '13px'; dbg.style.borderRadius = '8px'; dbg.style.boxShadow = '0 8px 24px rgba(0,0,0,0.4)';
            const hdr = document.createElement('div'); hdr.style.display = 'flex'; hdr.style.justifyContent = 'space-between'; hdr.style.alignItems = 'center'; hdr.style.marginBottom = '8px';
            const hTitle = document.createElement('div'); hTitle.textContent = 'Material Debug'; hTitle.style.fontWeight = '700'; hTitle.style.fontSize = '13px';
            const runBtn = document.createElement('button'); runBtn.textContent = 'Run check'; runBtn.style.fontSize = '12px'; runBtn.style.padding = '4px 8px'; runBtn.style.borderRadius = '6px'; runBtn.style.cursor = 'pointer'; runBtn.style.border = 'none'; runBtn.style.background = '#2E8B57'; runBtn.style.color = '#fff'; runBtn.addEventListener('click', function(){ try{ window.runMaterialStageCheck && window.runMaterialStageCheck(); }catch(e){} });
            hdr.appendChild(hTitle); hdr.appendChild(runBtn);
            dbg.appendChild(hdr);
            const content = document.createElement('div'); content.id = 'matDebugContent'; content.textContent = 'Waiting for data...'; dbg.appendChild(content);
            document.body.appendChild(dbg);
        }
        // Force a check so entries populate
        try { if (typeof refreshMaterialCollectionReqState === 'function') refreshMaterialCollectionReqState(); } catch(e) { console.error('Initial debug refresh failed', e); }
        // define a detailed run function for debugging
        try {
            window.runMaterialStageCheck = function(){
                try {
                    const stages = Array.from(document.querySelectorAll('.stage-materials'));
                    const report = [];
                    stages.forEach((materialsNode, si) => {
                        const stageCard = materialsNode.closest('.stage-card') || materialsNode.closest('.workflow-stage') || materialsNode.parentElement;
                        const stageIdx = stageCard ? (stageCard.getAttribute('data-stage-index') || 'unknown') : 'unknown';
                        const btn = stageCard ? (stageCard.querySelector('.stage-actions button[data-stage-number]') || stageCard.querySelector('button.complete-stage-btn[data-stage-number]')) : null;
                        const items = Array.from(materialsNode.querySelectorAll('.material-item'));
                        const per = items.map(li => {
                            const nameEl = li.querySelector('.mat-name');
                            const name = nameEl ? nameEl.textContent.trim() : ('#' + (li.dataset.materialId || ''));
                            const qtyEl = li.querySelector('.mat-qty');
                            const qty = qtyEl ? qtyEl.textContent.trim() : null;
                            const badge = !!li.querySelector('.badge.obtained');
                            const photos = li.querySelector('.material-photos');
                            const photo = !!(photos && photos.querySelector('.material-photo:not(.placeholder)'));
                            return { name, qty, badge, photo };
                        });
                        const total = per.length;
                        const have = per.filter(x => x.badge || x.photo).length;
                        const reqOk = total === 0 ? true : (have >= total);
                        /* debug: stage check (silenced) */
                        const content = document.getElementById('matDebugContent');
                        if (content) {
                            if (!content._entries) content._entries = [];
                            content._entries[stageIdx] = `<div style="margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,0.08)"><strong>Stage ${stageIdx}</strong><br>materials: ${total}<br>satisfied: ${have}<br>reqOk: ${reqOk}</div>`;
                            content.innerHTML = Object.keys(content._entries).sort().map(k => content._entries[k]).join('');
                        }
                        if (btn) {
                            btn.dataset.reqOk = reqOk ? '1' : '0';
                            if (reqOk) btn.removeAttribute('disabled'); else if (!btn.dataset.forceEnabled) btn.setAttribute('disabled','');
                        }
                        report.push({ stageIdx, total, have, reqOk, button: !!btn });
                    });
                    // debug logging removed
                    return report;
                } catch(err) { /* runMaterialStageCheck failed (silenced) */ return null; }
            };
        } catch(e) { /* failed to define runMaterialStageCheck (silenced) */ }
    } catch(e) { /* debug init failed (silenced) */ }
});
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

// Function to handle photo upload
function uploadStagePhoto(stageNumber){
    // Open the modal allowing selecting before/after and the file
    showStagePhotoModal(stageNumber, null, projectId);
}

// Create and show a modal that allows uploading specific stage photo types
function showStagePhotoModal(stageNumber, missingTypes, projectId){
    // missingTypes: null or array like ['before','after']
    let modal = document.getElementById('stagePhotoModal');
    if (modal) modal.remove();

    modal = document.createElement('div');
    modal.className = 'modal';
    modal.id = 'stagePhotoModal';
    modal.dataset.persistent = '0';

    const types = Array.isArray(missingTypes) && missingTypes.length ? missingTypes : ['before','after','other'];

    modal.innerHTML = `
        <div class="modal-content" style="max-width:520px;padding:18px;">
            <h3 style="margin-top:0;">Upload stage photo</h3>
            <p>Please upload the required photo(s) for this stage.</p>
            <div id="stagePhotoButtons" style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
                <button data-action="close-stage-photo" class="btn">Close</button>
                <button data-action="try-complete" class="btn btn-primary">Try to complete stage</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(()=> modal.classList.add('active'));

    const container = modal.querySelector('#stagePhotoButtons');

    function createUploader(type){
        const wrapper = document.createElement('div');
        wrapper.style.display = 'flex';
        wrapper.style.flexDirection = 'column';
        wrapper.style.alignItems = 'stretch';
        wrapper.style.minWidth = '160px';

        const label = document.createElement('div');
        label.textContent = type.toUpperCase();
        label.style.fontWeight = '600';
        label.style.marginBottom = '6px';

        const btn = document.createElement('button');
        btn.className = 'btn';
        btn.textContent = 'Upload ' + type;

        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.style.display = 'none';

        // Prevent accidental double-click/open by tracking an opener flag on the wrapper
        btn.addEventListener('click', ()=> {
            try {
                if (wrapper._opening) return;
                wrapper._opening = true;
                // ensure flag clears after a reasonable timeout if change handler fails to run
                setTimeout(()=> { try { wrapper._opening = false; } catch(e){} }, 4000);
                input.click();
            } catch(e) { try { input.click(); } catch(err){} }
        });

        input.addEventListener('change', async function(e){
            const file = input.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) { showToast('File must be <5MB', 'error'); return; }
            const fd = new FormData();
            fd.append('photo', file);
            fd.append('photo_type', type);
            fd.append('stage_number', stageNumber);
            fd.append('project_id', projectId);

            try {
                btn.disabled = true;
                btn.textContent = 'Uploading…';
                const res = await fetch('upload_stage_photo.php', { method: 'POST', body: fd });
                const j = await res.json();
                if (j && j.success) {
                    showToast(type + ' uploaded', 'success');
                    // remove this uploader if it was a missing requirement
                    if (Array.isArray(missingTypes)) {
                        wrapper.remove();
                        // if none left, enable Try button so user can attempt completion
                        try {
                            const tryBtn = modal.querySelector('[data-action="try-complete"]');
                            const remaining = modal.querySelectorAll('#stagePhotoButtons > div');
                            if (tryBtn && (!remaining || remaining.length === 0)) {
                                tryBtn.disabled = false;
                            }
                        } catch (e) { /* ignore */ }
                    } else {
                        // leave as-is but show thumbnail? (omitted)
                    }
                    // tell the page to recompute requirement state (enables the main complete button)
                    // clear the opener flag so a subsequent upload can be started manually
                    try { wrapper._opening = false; } catch(e){}
                    try { if (typeof refreshMaterialCollectionReqState === 'function') refreshMaterialCollectionReqState(); } catch(e){}
                } else {
                    try { wrapper._opening = false; } catch(e){}
                    showToast(j && j.message ? j.message : 'Upload failed', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Upload ' + type;
                }
            } catch (err) {
                console.error(err);
                showToast('Network error', 'error');
                btn.disabled = false;
                btn.textContent = 'Upload ' + type;
            }
        });

        wrapper.appendChild(label);
        wrapper.appendChild(btn);
        wrapper.appendChild(input);
        return wrapper;
    }

    types.forEach(t => container.appendChild(createUploader(t)));

    // If modal was opened specifically for missing types, disable the Try button until uploads complete
    try {
        const tryBtn = modal.querySelector('[data-action="try-complete"]');
        if (tryBtn && Array.isArray(missingTypes) && missingTypes.length) tryBtn.disabled = true;
    } catch(e) {}

    // Close handlers
    modal.querySelector('[data-action="close-stage-photo"]').addEventListener('click', function(){ modal.classList.remove('active'); setTimeout(()=>{ try{ modal.remove(); document.body.style.overflow = ''; }catch(e){} }, 220); });

    modal.querySelector('[data-action="try-complete"]').addEventListener('click', function(){
        // attempt to complete the stage now (re-use existing completeStage)
        modal.classList.remove('active');
        setTimeout(()=>{ try{ modal.remove(); document.body.style.overflow = ''; } catch(e){} }, 220);
        // call completeStage programmatically without an event
        completeStage(null, stageNumber, projectId);
    });

    // close if clicking overlay
    modal.addEventListener('click', function(e){ if (e.target === modal) { modal.classList.remove('active'); setTimeout(()=>{ try{ modal.remove(); document.body.style.overflow = ''; }catch(e){} }, 220); } });
}
    // Define completeStage function and resolve the button element from event or by stageNumber
    async function completeStage(event, stageNumber, projectId) {
    // Resolve the button element from event or by stageNumber
    let btn = event && event.currentTarget ? event.currentTarget : (event && event.target ? event.target : null);
    if (btn && btn.tagName && btn.tagName.toLowerCase() === 'i') btn = btn.closest('button');
    if (!btn) btn = document.querySelector('.complete-stage-btn[data-stage-number="' + stageNumber + '"]');

    try {
        // If requirements are not satisfied, open the photo modal instead of posting
        if (btn && btn.dataset && btn.dataset.reqOk === '0') {
                showStagePhotoModal(stageNumber, null, projectId);
                return;
        }
        // If button is disabled/aria-disabled and not a Completed state, ignore click (but allow toggling when it shows Completed)
        if (btn) {
            const ariaDisabled = btn.getAttribute && btn.getAttribute('aria-disabled') === 'true';
            if ((btn.disabled || ariaDisabled) && !(btn.textContent || '').toLowerCase().includes('completed')) {
                // debug removed
                return;
            }
        }

        // Provide immediate UI feedback
        if (btn) {
            btn.disabled = true;
            btn.dataset._origHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Working...';
        }

        // POST to server with proper headers
        const response = await fetch('complete_stage.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'stage_number=' + encodeURIComponent(stageNumber) + '&project_id=' + encodeURIComponent(projectId)
        });

        const data = await response.json().catch(() => null);
        if (data && data.success) {
            // Update DOM: mark this stage completed and advance to the next stage
            try {
                const stageEl = btn ? btn.closest('.workflow-stage') : null;
                if (stageEl) {
                    stageEl.classList.remove('current');
                    stageEl.classList.add('completed');

                    // mark matching tab as completed
                    const idx = parseInt(stageEl.getAttribute('data-stage-index'), 10);
                    const tab = document.querySelector('.stage-tab[data-stage-index="' + idx + '"]');
                    if (tab) { tab.classList.remove('active'); tab.classList.add('completed'); }

                    // move to next stage (if any)
                    let next = stageEl.nextElementSibling;
                    while (next && !next.classList.contains('workflow-stage')) next = next.nextElementSibling;
                    if (next) {
                        next.classList.remove('locked');
                        next.classList.add('current');
                        const nextIdx = parseInt(next.getAttribute('data-stage-index'), 10);
                        // switch visible content and active tab
                        if (typeof showStageByIndex === 'function') showStageByIndex(nextIdx);
                    } else {
                        // If there is no next stage, refresh to show final state
                        setTimeout(() => window.location.reload(), 700);
                    }
                }
            } catch (err) { console.error('DOM update after completeStage failed', err); }

            // Update progress bar text & fill
            try {
                const total = document.querySelectorAll('.workflow-stage').length;
                const completed = document.querySelectorAll('.workflow-stage.completed').length;
                const percent = total > 0 ? Math.round((completed / total) * 100) : 0;
                const fill = document.querySelector('.progress-fill');
                const strong = document.querySelector('.progress-indicator strong');
                if (fill) fill.style.width = percent + '%';
                if (strong) strong.textContent = percent + '%';
            } catch (err) { /* non-fatal */ }

            // restore button state (if still present)
            if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset._origHtml || '<i class="fas fa-check"></i> Completed'; }
            if (typeof showToast === 'function') showToast(data.message || 'Stage completed', 'success');

        } else {
            // Server indicated failure
            if (data && data.reason === 'missing_stage_photos') {
                // open photo modal with missing types suggested by server
                showStagePhotoModal(stageNumber, data.missing || null, projectId);
                if (typeof showToast === 'function') showToast('Please upload photos for every materials', 'error');
            } else {
                if (typeof showToast === 'function') showToast((data && data.message) ? data.message : 'Could not complete stage', 'error');
            }
            if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset._origHtml || '<i class="fas fa-check"></i> Mark as Complete'; }
        }

    } catch (error) {
        if (typeof showToast === 'function') showToast('Network error while completing stage', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset._origHtml || '<i class="fas fa-check"></i> Mark as Complete'; }
    }
    }
// End of completeStage function

<?php
// Output materials script config and include via PHP to avoid editor parse issues
echo '<script>window.ECW_DATA = window.ECW_DATA || {}; window.ECW_DATA.projectId = ' . json_encode($project_id) . ';</script>' . "\n";
// expose canonical stages to the client for DOM-injection and fallbacks
try {
    $clientStages = isset($canonical) && is_array($canonical) ? $canonical : (file_exists(__DIR__ . '/includes/project_stages.php') ? include __DIR__ . '/includes/project_stages.php' : []);
} catch (Exception $e) { $clientStages = []; }
echo '<script>window.ECW_DATA = window.ECW_DATA || {}; window.ECW_DATA.canonicalStages = ' . json_encode(array_values($clientStages)) . ';</script>' . "\n";
echo '<script src="assets/js/project-details-materials.js"></script>' . "\n";
?>
<script>
// Client helper: ensure exactly three stage tabs exist (canonical stages). If any are missing
// insert them so UI can't drift when server-side or templates omit a stage.
function ensureThreeStageTabs() {
    try {
        const canonical = (window.ECW_DATA && Array.isArray(window.ECW_DATA.canonicalStages)) ? window.ECW_DATA.canonicalStages : [
            { name: 'Preparation', description: 'Collect materials required for this project', icon: 'fa-lightbulb' },
            { name: 'Construction', description: 'Build your project, follow safety guidelines, document progress', icon: 'fa-hard-hat' },
            { name: 'Share', description: 'Share your project with the community', icon: 'fa-share-alt' }
        ];

        const tabsRoot = document.querySelector('.stage-tabs');
        if (!tabsRoot) return;

        // map existing by normalized name
        // Build map of existing tabs by normalized canonical title (use .tab-title if available)
        const existingMap = {};
        const tabs = Array.from(tabsRoot.querySelectorAll('.stage-tab'));
        tabs.forEach(t => {
            let titleEl = t.querySelector('.tab-title');
            let ttext = titleEl ? (titleEl.textContent || '') : (t.textContent || '');
            // strip common badge words to get a clean key
            ttext = ttext.replace(/Completed|Current|Locked|Locked|\n|\r|\t/gi,'').trim().toLowerCase();
            // sometimes inline text contains icons or extra markup; reduce to single name token
            const token = (ttext.match(/[a-z0-9\s]+/i) || [''])[0].trim();
            if (token) {
                if (!existingMap[token]) existingMap[token] = t; else {
                    // duplicate — remove the extra one to avoid duplicate tabs
                    try { t.remove(); } catch(e){}
                }
            }
        });

        // If a canonical stage is missing, create and insert it in the canonical order
        canonical.forEach((cs, idx) => {
            const key = (cs.name || '').toLowerCase();
            if (!existingMap[key]) {
                const btn = document.createElement('button');
                btn.className = 'stage-tab';
                btn.setAttribute('data-stage-index', String(idx));
                btn.setAttribute('data-stage-number', String(idx+1));
                btn.setAttribute('aria-label', cs.name || 'Stage');
                btn.innerHTML = `<span class="tab-icon"><i class="fas ${cs.icon || 'fa-circle'}" aria-hidden="true"></i></span><span class="tab-meta"><span class="tab-title">${cs.name}</span><span class="tab-badge">Locked</span></span>`;
                // Insert at desired index if possible
                const ref = tabsRoot.children[idx];
                if (ref) tabsRoot.insertBefore(btn, ref); else tabsRoot.appendChild(btn);
                // ensure the new button has interactive handlers so it works with the tab logic
                attachTabHandlers(btn);
            } else {
                // Move existing tab into canonical position for consistent ordering
                const existing = existingMap[key];
                const ref = tabsRoot.children[idx];
                if (ref && ref !== existing) tabsRoot.insertBefore(existing, ref);
            }
        });
        // After ensuring canonical tabs exist, ensure stage-cards and tabs share consistent indices
        try {
            const stageCards = Array.from(document.querySelectorAll('.workflow-stage, .stage-card'));
            // normalize stage-cards by canonical order: if a card's title matches canonical name, assign the canonical index
            canonical.forEach((cs, ci) => {
                const match = stageCards.find(sc => {
                    try {
                        const title = (sc.querySelector && sc.querySelector('.stage-title')) ? (sc.querySelector('.stage-title').textContent || '') : (sc.textContent || '');
                        return typeof title === 'string' && title.toLowerCase().indexOf((cs.name || '').toLowerCase()) !== -1;
                    } catch(e){ return false; }
                });
                if (match) {
                    match.setAttribute('data-stage-index', String(ci));
                }
            });

            // Now align tabs with canonical indices where possible
            Array.from(tabsRoot.querySelectorAll('.stage-tab')).forEach((t, ti) => {
                // find a matching canonical entry by title
                let titleEl = t.querySelector('.tab-title');
                let ttext = titleEl ? (titleEl.textContent || '') : (t.textContent || '');
                ttext = ttext.replace(/Completed|Current|Locked|\n|\r|\t/gi,'').trim().toLowerCase();
                const token = (ttext.match(/[a-z0-9\s]+/i) || [''])[0].trim();
                const foundIndex = canonical.findIndex(cs => ((cs.name || '').toLowerCase() === token));
                if (foundIndex >= 0) t.dataset.stageIndex = String(foundIndex);
                else if (!t.dataset.stageIndex) t.dataset.stageIndex = String(ti);
                // attach handlers now that indexes are set
                attachTabHandlers(t);
            });
        } catch(e) { /* ignore */ }
    } catch(e) { /* fail silently */ }
}

// Attach click/keydown handlers to tab if not already bound
function attachTabHandlers(tabEl) {
    try {
        if (!tabEl || tabEl.dataset && tabEl.dataset._tabInit === '1') return;
        const tabsRoot = tabEl.closest('.stage-tabs');
        const all = tabsRoot ? Array.from(tabsRoot.querySelectorAll('.stage-tab')) : [tabEl];
        const idx = Array.prototype.indexOf.call(all, tabEl);
        tabEl.setAttribute('tabindex', '0');
        if (!tabEl.dataset.stageIndex) tabEl.dataset.stageIndex = idx;
        tabEl.addEventListener('click', function(){ try { const i = parseInt(this.dataset.stageIndex,10); const idx = isNaN(i) ? 0 : i; if (typeof showStageByIndex === 'function') showStageByIndex(idx); else { // fallback: toggle classes
                document.querySelectorAll('.stage-tab').forEach(t=>t.classList.toggle('active', parseInt(t.dataset.stageIndex,10) === idx));
                document.querySelectorAll('.workflow-stage, .stage-card').forEach(s=>s.classList.toggle('active', parseInt(s.getAttribute('data-stage-index'),10) === idx));
            } } catch(e){} });
        tabEl.addEventListener('keydown', function(ev){ try { const ti = parseInt(this.dataset.stageIndex,10); const tabs = Array.from(this.closest('.stage-tabs').querySelectorAll('.stage-tab')); if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') { ev.preventDefault(); const next = tabs[(ti + 1) % tabs.length]; if (next) next.focus(); } else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') { ev.preventDefault(); const prev = tabs[(ti - 1 + tabs.length) % tabs.length]; if (prev) prev.focus(); } else if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); this.click(); } } catch(e){} });
        // make sure locked styling doesn't prevent focus/click
        try { tabEl.style.pointerEvents = 'auto'; } catch(e){}
        if (tabEl.dataset) tabEl.dataset._tabInit = '1';
    } catch(e) { /* ignore */ }
}

// Client helper: when a Preparation / material-collection stage is not present in the DOM
// create a minimal stage-card with materials section so add_material can attach items.
function ensurePreparationStageInDOM() {
    try {
        // If a materials-list-stage already exists, nothing more to do
        if (document.querySelector('.materials-list-stage')) return true;

        // Look for an existing stage-card with a title that matches Preparation / Material Collection
        const existingPrep = Array.from(document.querySelectorAll('.workflow-stage, .stage-card')).find(sc => {
            try {
                const titleEl = sc.querySelector && sc.querySelector('.stage-title');
                const txt = titleEl ? (titleEl.textContent || '') : (sc.textContent || '');
                const t = String(txt || '').trim().toLowerCase();
                return t.indexOf('prepar') !== -1 || t.indexOf('material') !== -1;
            } catch(e){ return false; }
        });

        // If an existing Preparation stage-card exists, ensure it contains a materials-list-stage element
        if (existingPrep) {
            try {
                // prefer .stage-materials container inside this card
                let materialsWrap = existingPrep.querySelector('.stage-materials');
                if (!materialsWrap) {
                    // create a reasonable container matching our template
                    materialsWrap = document.createElement('div');
                    materialsWrap.className = 'stage-materials';
                    materialsWrap.innerHTML = '<h4>Materials Needed</h4><ul class="materials-list-stage"></ul>';
                    // Insert before stage-actions or append at end
                    const actions = existingPrep.querySelector('.stage-actions');
                    if (actions && actions.parentNode) actions.parentNode.insertBefore(materialsWrap, actions);
                    else existingPrep.appendChild(materialsWrap);
                } else {
                    // ensure inner list exists
                    if (!materialsWrap.querySelector('.materials-list-stage')) {
                        const ul = document.createElement('ul'); ul.className = 'materials-list-stage'; materialsWrap.appendChild(ul);
                        // remove any empty-state placeholder message if present
                        const empty = materialsWrap.querySelector('.empty-state'); if (empty) try { empty.remove(); } catch(e){}
                    }
                }
                // ensure add material button exists inside this stage if missing
                if (!existingPrep.querySelector('.add-material-btn')) {
                    const actions = existingPrep.querySelector('.stage-actions') || existingPrep;
                    // find left-side container if present, otherwise fall back to actions
                    const left = actions && actions.querySelector ? (actions.querySelector('.stage-actions-left') || actions) : actions;
                    const btn = document.createElement('button'); btn.type='button'; btn.className='btn add-material-btn'; btn.innerHTML='<i class="fas fa-plus"></i> Add Material'; btn.addEventListener('click', function(){ try{ if (typeof showAddMaterialModal === 'function') showAddMaterialModal(); }catch(e){} });
                    if (left && left.appendChild) left.appendChild(btn); else if (actions && actions.appendChild) actions.appendChild(btn);
                }
                // ensure this stage is active (so insertion shows in current view)
                try { existingPrep.classList.add('active'); existingPrep.classList.remove('locked'); } catch(e){}
                return true;
            } catch(e) { /* ignore */ }
        }

        const canonical = window.ECW_DATA && Array.isArray(window.ECW_DATA.canonicalStages) ? window.ECW_DATA.canonicalStages : [];
        const prep = (canonical.length ? canonical[0] : { name: 'Preparation', description: 'Collect materials required for this project', icon: 'fa-box-open' });

        // Find container for stages
        const container = document.querySelector('.workflow-stages-container') || document.querySelector('.workflow-section') || document.body;
        if (!container) return false;

        // Create a minimal stage-card element for Preparation
        const card = document.createElement('div');
        card.className = 'workflow-stage stage-card inactive';
        card.setAttribute('data-stage-index', '0');
        card.innerHTML = `
            <i class="fas ${prep.icon || 'fa-box-open'} stage-icon" aria-hidden="true"></i>
            <div class="stage-content">
                <div class="stage-header">
                    <div class="stage-info">
                        <h3 class="stage-title">${prep.name}</h3>
                        <div class="stage-desc">${prep.description || ''}</div>
                    </div>
                </div>
                <div class="stage-materials">
                    <h4>Materials Needed</h4>
                    <ul class="materials-list-stage"></ul>
                </div>
                <div class="stage-actions">
                    <div class="stage-actions-left"><button type="button" class="btn add-material-btn" onclick="showAddMaterialModal()"><i class="fas fa-plus"></i> Add Material</button></div>
                    <div class="stage-actions-right"><button type="button" class="btn proceed-stage-btn is-disabled" aria-disabled="true" title="Proceed to next stage">Proceed</button></div>
                </div>
            </div>`;

        // Insert near the beginning of the stages container
        container.insertBefore(card, container.firstChild);

        // Also ensure a top tab for Preparation exists
        ensureThreeStageTabs();
        return true;
    } catch(e) { return false; }
}

// Helper to insert a new material item element into the materials list
function insertMaterialIntoDOM(mat) {
    try {
        if (!mat) {
            try { showToast('Invalid material data returned from server', 'error'); } catch(e) {}
            console && console.warn && console.warn('insertMaterialIntoDOM called with null/invalid mat:', mat);
            return false;
        }
        // ensure Preparation stage exists
        ensurePreparationStageInDOM();
        let list = document.querySelector('.materials-list-stage');
        // If list still missing, attempt to locate or create it inside existing Preparation stage
        if (!list) {
            const prepStage = Array.from(document.querySelectorAll('.workflow-stage, .stage-card')).find(sc => {
                try { const t = (sc.querySelector && sc.querySelector('.stage-title')) ? sc.querySelector('.stage-title').textContent || '' : sc.textContent || ''; return String(t || '').toLowerCase().indexOf('prepar') !== -1 || String(t || '').toLowerCase().indexOf('material') !== -1;} catch(e){return false;} });
            if (prepStage) {
                let materialsWrap = prepStage.querySelector('.stage-materials');
                if (!materialsWrap) {
                    materialsWrap = document.createElement('div'); materialsWrap.className = 'stage-materials'; materialsWrap.innerHTML = '<h4>Materials Needed</h4><ul class="materials-list-stage"></ul>';
                    const actions = prepStage.querySelector('.stage-actions'); if (actions && actions.parentNode) actions.parentNode.insertBefore(materialsWrap, actions); else prepStage.appendChild(materialsWrap);
                }
                list = materialsWrap.querySelector('.materials-list-stage');
            }
        }
        if (!list) return false;
        if (!list) return false;

        // normalize material fields (support multiple API shapes)
        const matId = String(mat.material_id || mat.id || mat.id_str || mat.mid || '').trim();
        const matName = String(mat.material_name || mat.name || mat.label || '').trim();
        const matQty = (typeof mat.quantity !== 'undefined' && mat.quantity !== null) ? parseInt(mat.quantity, 10) : (typeof mat.qty !== 'undefined' ? parseInt(mat.qty, 10) : 1);
        const matOrig = (typeof mat.original_quantity !== 'undefined' && mat.original_quantity !== null) ? parseInt(mat.original_quantity, 10) : matQty;
        // Compute obtained = original - remaining
        const matObtained = (isNaN(matOrig) ? 0 : Math.max(0, matOrig - matQty));

        // avoid duplicating an already-inserted material with the same id
        if (matId) {
            try {
                const existing = list.querySelector('li.material-item[data-material-id="' + matId + '"]');
                if (existing) {
                    // update quantity and name in place
                    try { const qEl = existing.querySelector('.mat-qty'); if (qEl) {
                        const existingOrig = existing.getAttribute('data-original-quantity');
                        const origVal = (typeof mat.original_quantity !== 'undefined' && mat.original_quantity !== null) ? mat.original_quantity : (existingOrig ? parseInt(existingOrig,10) : matQty);
                        const ddExist = computeDisplayedFromServer(matQty, (typeof mat.original_quantity !== 'undefined' && mat.original_quantity !== null) ? mat.original_quantity : null, existing);
                        qEl.textContent = String(ddExist.obtained) + ' / ' + String(ddExist.orig);
                        if (typeof mat.original_quantity !== 'undefined' && mat.original_quantity !== null) existing.setAttribute('data-original-quantity', String(mat.original_quantity));
                    } } catch(e){}
                    try { const nEl = existing.querySelector('.mat-name'); if (nEl) nEl.textContent = matName || (nEl.textContent || ''); } catch(e){}
                    // ensure the item is visible/active
                    try { existing.classList.remove('hidden'); } catch(e){}
                    try { const stageEl = list.closest('.workflow-stage, .stage-card'); if (stageEl) { stageEl.classList.add('active'); stageEl.classList.remove('locked'); } } catch(e){}
                    try { refreshMaterialCollectionReqState(); } catch(e){}
                    return true;
                }
            } catch(e) { /* ignore lookup errors */ }
        }

        const li = document.createElement('li');
        li.className = 'material-item';
        if (matId) li.setAttribute('data-material-id', matId);
        li.setAttribute('data-original-quantity', String(matOrig));
        li.innerHTML = `
            <div class="material-main">
                <span class="mat-name">${(matName || '')}</span>
                <div class="mat-meta"><span class="mat-qty">${(isNaN(matObtained) ? 0 : matObtained)} / ${isNaN(matOrig) ? (isNaN(matQty) ? 0 : matQty) : matOrig}</span></div>
            </div>
            <div class="material-actions">
                <a href="browse.php?query=${encodeURIComponent(matName || '')}&from_project=${window.ECW_DATA && window.ECW_DATA.projectId ? window.ECW_DATA.projectId : ''}" class="btn small find-donations-btn">Find Donations</a>
                <form method="POST" class="inline-form" data-obtain-modal="1" action="project_details.php?id=${window.ECW_DATA && window.ECW_DATA.projectId ? window.ECW_DATA.projectId : ''}">
                    <input type="hidden" name="material_id" value="${matId}"><input type="hidden" name="status" value="obtained"><button type="submit" name="update_material_status" class="btn small"><i class="fas fa-check"></i></button>
                </form>
                <form method="POST" class="inline-form" data-confirm="Remove this material?">
                    <input type="hidden" name="material_id" value="${matId}"><button type="submit" name="remove_material" class="btn small danger"><i class="fas fa-trash"></i></button>
                </form>
            </div>
            <div class="material-photos" data-material-id="${matId}"></div>`;

        list.appendChild(li);
        // remove any 'No materials listed.' or placeholder messages in this container
        try { const containerWrap = list.closest('.stage-materials'); if (containerWrap) {
                const p = containerWrap.querySelector('.empty-state, .no-materials, .placeholder'); if (p) try { p.remove(); } catch(e){}
            }
        } catch(e){}
        // make sure the preparation stage and tab are shown
        try { const stageEl = list.closest('.workflow-stage, .stage-card'); if (stageEl) { stageEl.classList.add('active'); stageEl.classList.remove('locked'); } if (typeof showStageByIndex === 'function') try { showStageByIndex(0); } catch(e){} } catch(e){}
        try { refreshMaterialCollectionReqState(); } catch(e){}
        return true;
    } catch(e){ return false; }
}

// Ensure tabs/stage exist on page load to avoid pages with only 2 tabs when no materials
document.addEventListener('DOMContentLoaded', function(){ try { ensureThreeStageTabs(); } catch(e){} });
</script>