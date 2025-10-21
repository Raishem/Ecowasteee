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
    <script>
    // Silence console output in production pages per user request.
    // This replaces console methods with no-ops so the browser console remains quiet.
    (function(){
        try {
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
            const completedStages = Array.from(document.querySelectorAll('.workflow-stage.completed, .stage-card.completed'));
            completedStages.forEach(stageEl => {
                try {
                    // prefer explicit data-stage-number then data-stage-index
                    let sn = stageEl.getAttribute('data-stage-number') || stageEl.getAttribute('data-stage-index');
                    if (!sn) {
                        // try to find a button inside
                        const btnInside = stageEl.querySelector('.complete-stage-btn[data-stage-number], button[data-stage-number]');
                        if (btnInside) sn = btnInside.getAttribute('data-stage-number') || btnInside.getAttribute('data-stage-index');
                    }
                    const stageNumber = (sn !== null && sn !== undefined) ? parseInt(sn, 10) : NaN;
                    // Style the button to show it's completed but keep it as a button
                    const btn = stageEl.querySelector('.complete-stage-btn[data-stage-number], button[data-stage-number], .complete-stage-btn, button');
                    if (btn) {
                        btn.innerHTML = '<i class="fas fa-check-circle" aria-hidden="true"></i> Completed';
                        btn.style.background = '#dff3e6';
                        btn.style.color = '#2f7a3a';
                        btn.classList.add('completed');
                    } else {
                        // If no button found, create one as a fallback
                        const actions = stageEl.querySelector('.stage-actions');
                        if (actions) {
                            const newBtn = document.createElement('button');
                            newBtn.className = 'complete-stage-btn completed';
                            if (!isNaN(stageNumber)) newBtn.setAttribute('data-stage-number', stageNumber);
                            newBtn.style.display = 'inline-block';
                            newBtn.style.padding = '10px 14px';
                            newBtn.style.borderRadius = '8px';
                            newBtn.style.fontWeight = '600';
                            newBtn.style.background = '#dff3e6';
                            newBtn.style.color = '#2f7a3a';
                            newBtn.style.border = 'none';
                            newBtn.style.cursor = 'pointer';
                            newBtn.innerHTML = '<i class="fas fa-check-circle" aria-hidden="true"></i> Completed';
                            actions.appendChild(newBtn);
                        }
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
                        // add a non-clickable label area under the title if not present
                        if (!tab.querySelector('.stage-status-label')) {
                            const lbl = document.createElement('div');
                            lbl.className = 'stage-status-label completed';
                            lbl.style.marginTop = '8px';
                            lbl.style.fontSize = '13px';
                            lbl.style.color = '#2f7a3a';
                            lbl.innerHTML = '<i class="fas fa-check-circle"></i> Completed';
                            tab.appendChild(lbl);
                        }
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
                const label = document.createElement('div');
                label.className = 'stage-status-label ' + (showStatus === 'completed' ? 'completed' : 'incomplete');
                if (!isNaN(sn)) label.setAttribute('data-stage-number', sn);
                label.setAttribute('aria-hidden', 'true');
                label.style.display = 'inline-block';
                label.style.padding = '10px 14px';
                label.style.borderRadius = '8px';
                label.style.fontWeight = '600';
                label.style.cursor = 'pointer';
                if (showStatus === 'completed') { 
                    label.style.background = '#dff3e6'; 
                    label.style.color = '#2f7a3a'; 
                    label.innerHTML = '<i class="fas fa-check-circle"></i> Completed'; 
                    label.className = 'complete-stage-btn ' + (showStatus === 'completed' ? 'completed' : 'incomplete');
                    // Keep it as a button instead of replacing
                    if (btn.parentNode) {
                        btn.innerHTML = '<i class="fas fa-check-circle"></i> Completed';
                        btn.style.background = '#dff3e6';
                        btn.style.color = '#2f7a3a';
                        btn.classList.add('completed');
                    }
                }
                else { 
                    label.style.background = '#f1f5f1'; 
                    label.style.color = '#566a5a'; 
                    label.innerHTML = 'Incomplete'; 
                    if (btn.parentNode) btn.parentNode.replaceChild(label, btn);
                }
                return true;
            } catch(e) { return false; }
        }

        // Guarded observer: ignore mutations we created ourselves (data-observer-ignore)
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
        try { observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'data-stage-number', 'data-stage-index', 'aria-disabled'] }); } catch(e){}

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
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-undo"></i> Mark as Complete';
                    btn.classList.remove('is-disabled');
                    btn.removeAttribute('disabled');
                    btn.removeAttribute('aria-disabled');
                    btn.dataset.reqOk = '1';
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

            // If we replaced the button with a non-clickable label earlier, recreate the interactive button so the user can re-complete the stage
            try {
                const actions = stageEl ? stageEl.querySelector('.stage-actions') : null;
                // remove any existing non-clickable label
                if (actions) {
                    const lbl = actions.querySelector('.stage-status-label');
                    if (lbl) lbl.remove();
                }
                // if no button exists, create one
                let checkBtn = stageEl ? (stageEl.querySelector('.complete-stage-btn[data-stage-number]') || stageEl.querySelector('button[data-stage-number]')) : null;
                if (!checkBtn && actions) {
                    checkBtn = document.createElement('button');
                    checkBtn.className = 'btn complete-stage-btn';
                    try { checkBtn.setAttribute('data-stage-number', templateNum); } catch(e){}
                    checkBtn.title = 'Mark as Complete';
                    checkBtn.innerHTML = '<i class="fas fa-undo"></i> Mark as Complete';
                    // attach click handler to call existing completeStage function if available
                    try {
                        checkBtn.addEventListener('click', function(ev){
                            try { if (typeof completeStage === 'function') completeStage(ev, templateNum, projectId); else requestToggleStage(templateNum, projectId); } catch(e) {}
                        });
                    } catch(e) {}
                    actions.appendChild(checkBtn);
                }
            } catch(e) { /* ignore recreate errors */ }

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
            // Try to find the button first
            const btn = document.querySelector('.complete-stage-btn[data-stage-number="' + stageNumber + '"]') || document.querySelector('button[data-stage-number="' + stageNumber + '"]');
            // Create label element
            const label = document.createElement('div');
            label.className = 'stage-status-label ' + (status === 'completed' ? 'completed' : 'incomplete');
            label.setAttribute('data-stage-number', stageNumber);
            label.setAttribute('aria-hidden', 'true');
            label.style.display = 'inline-block';
            label.style.padding = '10px 14px';
            label.style.borderRadius = '8px';
            label.style.fontWeight = '600';
            if (status === 'completed') {
                label.style.background = '#dff3e6';
                label.style.color = '#2f7a3a';
                label.innerHTML = '<i class="fas fa-check-circle" aria-hidden="true"></i> Completed';
            } else {
                label.style.background = '#f1f5f1';
                label.style.color = '#566a5a';
                label.innerHTML = 'Incomplete';
            }

            if (btn && btn.parentNode) {
                // Replace the button with the label
                btn.parentNode.replaceChild(label, btn);
            } else {
                // Insert into stage-actions area if possible
                const stageEl = document.querySelector('.workflow-stage[data-stage-number="' + stageNumber + '"]') || document.querySelector('.workflow-stage[data-stage-index="' + stageNumber + '"]');
                if (stageEl) {
                    const actions = stageEl.querySelector('.stage-actions');
                    if (actions) {
                        // remove existing buttons
                        actions.querySelectorAll('button.complete-stage-btn, button[data-stage-number]').forEach(n=>n.remove());
                        actions.appendChild(label);
                    }
                }
            }

            // Update stage card and tab classes
            let stageEl = null;
            if (btn) stageEl = btn.closest('.workflow-stage, .stage-card');
            if (!stageEl) stageEl = document.querySelector('.workflow-stage[data-stage-number="' + stageNumber + '"]') || document.querySelector('.workflow-stage[data-stage-index="' + stageNumber + '"]');
            if (stageEl) {
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
                                showToast('All materials obtained — attempting to complete Material Collection stage');
                                // Find the Material Collection stage button and invoke its template-numbered completion handler
                                setTimeout(()=>{
                                    try {
                                        const container = document.querySelector('.workflow-stages-container');
                                        if (container) {
                                            const btn = container.querySelector('button.complete-stage-btn[data-stage-number]');
                                            // Prefer the button whose title or nearby heading contains "Material"
                                            let target = null;
                                            const candidateButtons = container.querySelectorAll('button.complete-stage-btn[data-stage-number]');
                                            candidateButtons.forEach(b => {
                                                if (target) return;
                                                const stageWrap = b.closest('.workflow-stage, .stage-card');
                                                if (stageWrap && /material/i.test(stageWrap.textContent || '')) target = b;
                                            });
                                            if (!target && candidateButtons.length) target = candidateButtons[0];
                                                        if (target) {
                                                            const tn = parseInt(target.getAttribute('data-stage-number'), 10);
                                                            if (!isNaN(tn)) {
                                                                // use requestToggleStage to ensure server toggling and UI update
                                                                (async function(){ try { await requestToggleStage(tn, projectId); } catch(e){} })();
                                                            } else location.reload();
                                            } else {
                                                location.reload();
                                            }
                                        } else {
                                            location.reload();
                                        }
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
                // If there is a next/prev material and it has no photo, restore upload button if needed
                if (nextMaterial && nextMaterial.classList.contains('material-item')) {
                    const meta = nextMaterial.querySelector('.mat-meta');
                    const hasPhoto = nextMaterial.querySelector('.material-photos .material-photo');
                    if (meta && !hasPhoto && !meta.querySelector('.upload-material-photo')) {
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
            if (!fd.has('action')) fd.append('action', 'add_material');
            if (!fd.has('project_id')) fd.append('project_id', projectId);
            const res = await fetch('update_project.php', { method: 'POST', body: fd, headers });
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
            // Ensure server sees the action key expected by update_project.php
            if (!fd.has('action')) fd.append('action', 'add_material');
            if (!fd.has('project_id')) fd.append('project_id', projectId);
            const headers = { 'X-Requested-With': 'XMLHttpRequest' };
            try {
                // Post to the API endpoint which handles material creation and returns JSON
                const res = await fetch('update_project.php', { method: 'POST', body: fd, headers });
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
                        li.innerHTML = `<div class="material-main"><span class="mat-name">${mat.material_name}</span><div class="mat-meta">${mat.quantity ? '<span class="mat-qty">' + mat.quantity + '</span>' : '<span class="mat-qty">0</span>'}</div></div>`;
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
                                    <button type="button" class="btn small upload-material-photo" data-material-id="${mat.material_id}" title="Upload photo" aria-label="Upload material photo"><i class="fas fa-camera"></i></button>
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
                                    await requestToggleStage(templateNum, <?= json_encode($project_id) ?>);
                                    try { markStageUncompletedUI(templateNum); } catch(e){}
                                } catch(e){ /* auto-uncomplete after modal add failed */ }
                            }
                        }
                    }
                } catch(e) { /* auto-uncomplete modal add check failed */ }
            } catch (err) { showToast('Network error', 'error'); }
        })();
    });

    // Initialize Material Collection completion state on load
    document.addEventListener('DOMContentLoaded', function(){ try{ refreshMaterialCollectionReqState(); }catch(e){} });

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
                    if (!btn) return;

                    const items = Array.from(materialsNode.querySelectorAll('.material-item'));
                    const total = items.length;
                    let have = 0;
                    items.forEach(li => {
                        const statusBadge = li.querySelector('.badge.obtained');
                        const photos = li.querySelector('.material-photos');
                        const hasPhoto = photos && photos.querySelector('.material-photo:not(.placeholder)');
                        if (statusBadge || hasPhoto) have++;
                    });

                    const reqOk = total === 0 ? true : (have >= total);
                    // Stage-scoped stricter checks: ensure every material in this materialsNode has an obtained badge and a non-placeholder photo
                    let scopedAllObtained = true;
                    let scopedAllPhotos = true;
                    try {
                        const itemsScoped = Array.from(materialsNode.querySelectorAll('.material-item'));
                        itemsScoped.forEach(li => {
                            if (!li.querySelector('.badge.obtained')) scopedAllObtained = false;
                            const photos = li.querySelector('.material-photos');
                            if (!(photos && photos.querySelector('.material-photo:not(.placeholder)'))) scopedAllPhotos = false;
                        });
                    } catch(e) { /* scoped check failed (silenced) */ }

                    // final decision: require both obtained and photos to be present for all materials, or fall back to original reqOk
                    const finalOk = (total === 0) ? true : (scopedAllObtained && scopedAllPhotos);
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

                    if (!finalOk && !btn.dataset.forceEnabled) {
                        // visual disabled (but keep clickable so JS can re-evaluate)
                        try { btn.setAttribute('aria-disabled', 'true'); } catch(e){}
                        try { btn.classList.add('is-disabled'); } catch(e){}
                        btn.title = 'Requirements not met for this stage';
                        btn.dataset.reqOk = '0';
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
                        try { btn.removeAttribute('aria-disabled'); } catch(e){}
                        try { btn.classList.remove('is-disabled'); } catch(e){}
                        btn.title = '';
                        btn.dataset.reqOk = '1';
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
        // Determine whether we're in the Preparation stage. Only Preparation should ask Before/After.
        let inPreparation = false;
        try {
            const stageEl = btn.closest('.workflow-stage, .stage-card') || document.querySelector('.workflow-stage.active') || document.querySelector('.stage-card.current');
            if (stageEl) {
                const sname = (stageEl.getAttribute('data-stage-name') || (stageEl.querySelector('.stage-title') && stageEl.querySelector('.stage-title').textContent) || '').toLowerCase();
                if (/prepar/.test(sname)) inPreparation = true;
            }
        } catch(e) { inPreparation = false; }

        const cleanUp = () => { /* noop for non-modal flow */ };

        if (inPreparation) {
            // show a small chooser to pick Before or After then open file picker
            const chooser = document.createElement('div');
            chooser.className = 'photo-type-chooser';
            chooser.style.position = 'fixed'; chooser.style.zIndex = 99999; chooser.style.left = '50%'; chooser.style.top = '50%'; chooser.style.transform = 'translate(-50%,-50%)'; chooser.style.background = '#fff'; chooser.style.padding = '12px'; chooser.style.boxShadow = '0 8px 24px rgba(0,0,0,0.2)'; chooser.style.borderRadius = '8px';
            chooser.innerHTML = `<div style="margin-bottom:8px;font-weight:600">Upload photo as</div>`;
            const beforeBtn = document.createElement('button'); beforeBtn.className='btn'; beforeBtn.textContent = 'Before';
            const afterBtn = document.createElement('button'); afterBtn.className='btn'; afterBtn.style.marginLeft='8px'; afterBtn.textContent = 'After';
            const cancelBtn = document.createElement('button'); cancelBtn.className='btn'; cancelBtn.style.marginLeft='8px'; cancelBtn.textContent = 'Cancel';
            chooser.appendChild(beforeBtn); chooser.appendChild(afterBtn); chooser.appendChild(cancelBtn);
            document.body.appendChild(chooser);

            const chooserClean = () => { try{ chooser.remove(); } catch(e){} };
            beforeBtn.addEventListener('click', function(){ chooserClean(); pickAndUpload('before'); });
            afterBtn.addEventListener('click', function(){ chooserClean(); pickAndUpload('after'); });
            cancelBtn.addEventListener('click', function(){ chooserClean(); });
        } else {
            // For Material Collection and other stages, skip Before/After and upload as 'before'
            pickAndUpload('before');
        }

        async function pickAndUpload(photoType){
            cleanUp();
            const input = document.createElement('input');
            input.type = 'file'; input.accept = 'image/*';
            input.onchange = async function(ev){
                const file = ev.target.files[0];
                if (!file) return;
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
                                div.innerHTML = `<img src="${src}" alt="Material photo"><button type="button" class="material-photo-delete" title="Delete photo"><i class="fas fa-trash"></i></button>${badgeHtml}`;
                                const img = div.querySelector('img'); if (img) img.addEventListener('click', ()=> openImageViewer(src));
                                photos.insertBefore(div, photos.firstChild);
                                // leave upload button so additional photos can be uploaded
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
                                            requestToggleStage(sn, projectId).then(r => { /* auto-complete response (silenced) */ }).catch(err => { /* auto-complete request failed (silenced) */ });
                                        } catch(e) { if (dbg) console.debug('[auto-complete] request threw', e); }
                                    } else if (dbg) console.debug('[auto-complete] stageNum parsed NaN', stageNum);
                                } else if (dbg) console.debug('[auto-complete] stageNum not found');
                            }
                                        } catch(e) { /* auto-complete after upload failed (silenced) */ }
                    } else {
                        showToast(json && json.message ? json.message : 'Upload failed', 'error');
                    }
                } catch (err) { showToast('Upload failed', 'error'); }
            };
            input.click();
        }
    });

    // Delegated handler for deleting a material photo (overlay delete button)
    document.addEventListener('click', async function(ev){
        const del = ev.target.closest('.material-photo-delete');
        if (!del) return;
        const wrapper = del.closest('.material-photo');
        if (!wrapper) return;
        const photoId = wrapper.dataset.photoId || null;
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
            return;
        }
        if (!confirm('Remove this photo?')) return;
        try {
            const fd = new FormData(); fd.append('photo_id', photoId);
            const res = await fetch('delete_material_photo.php', { method: 'POST', body: fd });
            const txt = await res.text();
            let json = null; try { json = txt ? JSON.parse(txt) : null; } catch(e){ alert('Delete failed'); return; }
                if (json && json.success) {
                    // Find the parent material row BEFORE removing the wrapper so we can restore controls reliably
                    const mat = document.querySelector('.material-item[data-material-id="' + (json.material_id || '') + '"]');
                    // use wrapper.closest since it points at the element to remove; fallback to del.closest
                    const parentMat = mat || (wrapper && wrapper.closest ? wrapper.closest('.material-item') : null) || (del && del.closest ? del.closest('.material-item') : null);
                    wrapper.remove();
                    showToast('Photo removed');
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
    });

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
                            if (!stageEl.classList.contains('completed') && !stageEl.querySelector('.stage-status-label.completed')) continue;

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
        // Toggle active classes on tabs and stages
        tabs.forEach(t => t.classList.toggle('active', parseInt(t.dataset.stageIndex,10) === idx));
        stages.forEach(s => s.classList.toggle('active', parseInt(s.getAttribute('data-stage-index'),10) === idx));
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
                    if (!empty($workflow_stages)) {
                        $seen = [];
                        $filtered = [];
                        foreach ($workflow_stages as $st) {
                            $name = trim(strtolower($st['name'] ?? ''));

                    // Replace the last stage name with "Share" (was Documentation)
                    if (!empty($workflow_stages) && is_array($workflow_stages)) {
                        $lastIndex = count($workflow_stages) - 1;
                        if ($lastIndex >= 0) {
                            // normalize keys which templates might use
                            if (isset($workflow_stages[$lastIndex]['stage_name'])) $workflow_stages[$lastIndex]['stage_name'] = 'Share';
                            if (isset($workflow_stages[$lastIndex]['name'])) $workflow_stages[$lastIndex]['name'] = 'Share';
                            // also ensure description (optional)
                            if (isset($workflow_stages[$lastIndex]['description'])) $workflow_stages[$lastIndex]['description'] = 'Share your project with others.';
                        }
                    }
                            if ($name === '') continue;
                            if (isset($seen[$name])) continue; // skip duplicates
                            $seen[$name] = true;
                            $filtered[] = $st;
                        }
                        // renumber sequentially starting at 1 for UI ordering, but keep original template_number
                        foreach ($filtered as $i => &$fs) { $fs['number'] = $i + 1; if (!isset($fs['template_number'])) $fs['template_number'] = $fs['number']; }
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
                        // If this is a Material Collection stage and DB shows completed, re-validate server-side
                        $stage_name_lower = strtolower($st['stage_name'] ?? $st['name'] ?? '');
                        if ($is_completed && stripos($stage_name_lower, 'material') !== false) {
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
                        if (empty($thumb) && stripos($st['stage_name'] ?? $st['name'] ?? '', 'material') !== false) {
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
                            if (stripos($stage_name_lower, 'material') !== false) $iconClass = 'fas fa-box-open';
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
                            <?php if (strtolower(trim($stage['name'] ?? '')) === 'material collection'): ?>
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

                                            // Additionally require stage-level photos (before & after) to be present
                                            // to match server-side complete_stage.php checks.
                                            try {
                                                $template_num_chk = isset($stage['template_number']) ? (int)$stage['template_number'] : (int)($stage['number'] ?? 0);
                                                if ($template_num_chk > 0) {
                                                    $ptypeStmt = $conn->prepare("SELECT COALESCE(photo_type,'other') AS photo_type, COUNT(*) AS c FROM stage_photos WHERE project_id = ? AND stage_number = ? GROUP BY photo_type");
                                                    if ($ptypeStmt) {
                                                        $ptypeStmt->bind_param('ii', $project_id, $template_num_chk);
                                                        $ptypeStmt->execute();
                                                        $pres = $ptypeStmt->get_result();
                                                        $haveTypes = [];
                                                        while ($r = $pres->fetch_assoc()) {
                                                            $pt = strtolower(trim($r['photo_type']));
                                                            if ($pt === '') $pt = 'other';
                                                            $haveTypes[$pt] = (int)$r['c'];
                                                        }
                                                        // require both before and after
                                                        if ((!isset($haveTypes['before']) || $haveTypes['before'] <= 0) || (!isset($haveTypes['after']) || $haveTypes['after'] <= 0)) {
                                                            $req_ok = false;
                                                        }
                                                    }
                                                }
                                            } catch (Exception $e) { /* ignore photo check errors - be conservative */ }
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
                                <?php $template_num = isset($stage['template_number']) ? (int)$stage['template_number'] : (int)$stage['number']; ?>
                                <?php if ($btn_disabled): ?>
                                    <button class="<?= $btn_classes ?> is-disabled" disabled aria-disabled="true" title="<?= htmlspecialchars($btn_title) ?>" data-req-ok="<?= $req_ok ? '1' : '0' ?>" data-stage-number="<?= $template_num ?>" style="pointer-events:none;cursor:not-allowed;opacity:0.8;">
                                <?php else: ?>
                                    <button class="<?= $btn_classes ?>" onclick="completeStage(event, <?= $template_num ?>, <?= $project_id ?>)" title="<?= htmlspecialchars($btn_title) ?>" data-req-ok="<?= $req_ok ? '1' : '0' ?>" data-stage-number="<?= $template_num ?>">
                                <?php endif; ?>
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

        btn.addEventListener('click', ()=> input.click());

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
                    try { if (typeof refreshMaterialCollectionReqState === 'function') refreshMaterialCollectionReqState(); } catch(e){}
                } else {
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
// End of completeStage function