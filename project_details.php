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
    <link rel="stylesheet" href="assets/css/project-details.css">
    <link rel="stylesheet" href="assets/css/project-details-modern-v2.css?v=5">
    <link rel="stylesheet" href="assets/css/project-description.css">
    <link rel="stylesheet" href="assets/css/project-materials.css?v=4">
    <script src="assets/js/stage-completion.js?v=1" defer></script>
    <style>
    .delete-btn {
        background: #dc3545;
        color: white;
        border: none;
        padding: 8px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
    }
    .delete-btn:hover {
        background: #c82333;
    }
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }
    .modal-content {
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        max-width: 400px;
        width: 90%;
        overflow: hidden;
    }
    /* Page-specific layout tweaks to match the other project pages */
    .main-content.project-details {
        max-width: 1100px;
        margin: 0 auto;
        padding: 24px;
        box-sizing: border-box;
    }
    .project-header.card {
        width: 100%;
    }
    .modal-header {
        padding: 20px;
        border-bottom: 1px solid #eee;
    }
    .modal-header h2 {
        margin: 0;
        font-size: 18px;
        color: #333;
    }
    .modal-body {
        padding: 20px;
    }
    .modal-body p {
        margin: 0 0 10px 0;
        color: #666;
        line-height: 1.5;
    }
    .modal-footer {
        padding: 15px 20px;
        border-top: 1px solid #eee;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }
    .modal-btn {
        padding: 8px 16px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
    }
    .modal-btn.secondary {
        background: #f0f0f0;
        color: #333;
    }
    .modal-btn.secondary:hover {
        background: #e0e0e0;
    }
    .modal-btn.danger {
        background: #dc3545;
        color: white;
    }
    .modal-btn.danger:hover {
        background: #c82333;
    }
    .modal-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    <!-- Ensure tabs appear consistently and match project screenshots -->
    <style>
        /* compact styling for the top 3 stage tabs (card-like) */
        .stage-tabs {
            display: flex;
            flex-direction: row;
            justify-content: center;
            gap: 14px;
            align-items: center;
            padding: 18px 18px 8px;
            background: transparent;
            margin: 12px 0 18px;
            width: 100%;
            box-sizing: border-box;
            flex-wrap: nowrap;
            overflow-x: auto;
        }
            display: flex;
            gap: 14px;
            align-items: stretch;
            padding: 18px 18px 8px;
            background: transparent;
            margin: 12px 0 18px;
        }
        .stage-tabs .stage-tab {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            flex-direction: column;
            width: 160px;
            background: #fff;
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 6px 18px rgba(16,24,40,0.06);
            border: 1px solid rgba(19,78,25,0.04);
            color: #0f1720;
            text-align: center;
            cursor: pointer;
            transition: transform .12s ease, box-shadow .12s ease;
            flex: 0 0 auto;
        }
            display: flex;
            align-items: center;
            gap: 12px;
            flex-direction: column;
            width: 160px;
            background: #fff;
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 6px 18px rgba(16,24,40,0.06);
            border: 1px solid rgba(19,78,25,0.04);
            color: #0f1720;
            text-align: center;
            cursor: pointer;
            transition: transform .12s ease, box-shadow .12s ease;
        }
        .stage-tabs .stage-tab.locked { opacity: .62; cursor: not-allowed; }
        .stage-tabs .stage-tab.active { transform: translateY(-4px); border-color: rgba(34,154,84,0.18); box-shadow: 0 10px 28px rgba(34,154,84,0.08); }
        .stage-tab .tab-icon { width: 46px; height: 46px; border-radius: 10px; display:flex; align-items:center; justify-content:center; background:#f4f6f5; margin-bottom:8px; color:#2a6d32; font-size:18px; }
        .stage-tab .tab-title { font-weight:600; font-size:14px; color:#0b3b20; display:block; margin-bottom:6px; }
        .stage-tab .tab-badge { font-size:12px; display:inline-block; padding:6px 8px; border-radius:999px; color:#33503a; background: #eef7ee; }
        .stage-tab .tab-badge.completed { background: #dff2df; color: #1f6a2c; font-weight:600; }
    </style>
    <script>
    // Small, resilient delete fallback: ensures the trash button can delete a project
    // even if later scripts fail to parse. Uses a native confirm() and a simple POST.
    // This will be overridden by the richer confirmDeleteProject() later if available.
    window.confirmDeleteProjectFallback = function(pid) {
        try {
            if (!pid) return;
            if (!confirm('Delete this project? This action cannot be undone.')) return;
            var fd = new FormData(); fd.append('project_id', pid);
            fetch('delete_project.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(res){ return res.json().catch(function(){ return null; }); })
                .then(function(data){
                    if (data && data.success) { window.location.href = 'projects.php'; }
                    else { alert((data && data.message) ? data.message : 'Failed to delete project'); }
                })
                .catch(function(){ alert('Network error while deleting project'); });
        } catch(e){ /* ignore */ }
    };

    // If the richer function isn't available by the time the user clicks, call fallback.
    document.addEventListener('click', function(e){
        try {
            var el = e.target && (e.target.closest ? e.target.closest('.delete-project') : null);
            if (!el) return;
            // If the main confirmDeleteProject is available, let it handle the click.
            if (typeof window.confirmDeleteProject === 'function') return;
            e.preventDefault();
            var pid = el.getAttribute('data-project-id') || el.getAttribute('data-project') || null;
            // try to read server-rendered PID fallback
            if (!pid) {
                try { pid = (window.ECW_DATA && window.ECW_DATA.projectId) ? window.ECW_DATA.projectId : null; } catch(e) { pid = null; }
            }
            if (!pid) {
                // Inspect inline onclick if present: confirmDeleteProject(123)
                var oc = el.getAttribute('onclick') || '';
                var m = oc.match(/confirmDeleteProject\((\d+)\)/);
                if (m) pid = m[1];
            }
            if (pid) window.confirmDeleteProjectFallback(pid);
        } catch(e){}
    }, true);

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
        <div class="header-right">
            <!-- Notifications temporarily hidden -->
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
        <button class="delete-project delete-btn" title="Delete project" onclick="confirmDeleteProject(<?= (int)$project_id ?>)"><i class="fas fa-trash"></i></button>
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
<!-- Workflow & Documentation preserved from original -->
<section class="workflow-section card">
    <h2 class="section-title"><i class="fas fa-tasks"></i> Project Workflow</h2>
    <?php
    // Force exactly three workflow tabs in the UI: Preparation, Construction, Share
    // If templates exist in DB for these names, preserve their template_number mapping so server-side
    // completion checks still work. Otherwise fall back to 1,2,3.
    $desired = [
        ['key' => 'preparation', 'label' => 'Preparation', 'description' => 'Collect materials, clean and sort materials, prepare workspace'],
        ['key' => 'construction', 'label' => 'Construction', 'description' => 'Build or transform materials into the finished item'],
        ['key' => 'share', 'label' => 'Share', 'description' => 'Share your project with the community']
    ];

    $available_templates = [];
    try {
        $tpl_stmt = $conn->prepare("SELECT stage_number, stage_name, description FROM stage_templates");
        if ($tpl_stmt) {
            $tpl_stmt->execute();
            $tres = $tpl_stmt->get_result();
            while ($r = $tres->fetch_assoc()) {
                $available_templates[] = $r;
            }
        }
    } catch (Exception $e) { /* ignore */ }

    $workflow_stages = [];
    foreach ($desired as $i => $d) {
        $foundTemplate = null;
        foreach ($available_templates as $t) {
            $name = strtolower($t['stage_name'] ?? '');
            if ($d['key'] === 'preparation' && (stripos($name, 'prepar') !== false || stripos($name, 'material') !== false)) { $foundTemplate = $t; break; }
            if ($d['key'] === 'construction' && stripos($name, 'construct') !== false) { $foundTemplate = $t; break; }
            if ($d['key'] === 'share' && stripos($name, 'share') !== false) { $foundTemplate = $t; break; }
        }
        $tplNum = null;
        $desc = $d['description'];
        if ($foundTemplate) { $tplNum = (int)$foundTemplate['stage_number']; if (!empty($foundTemplate['description'])) $desc = $foundTemplate['description']; }
        // Ensure we always have a stable display_name for the top tabs so the UI
        // consistently shows "Preparation / Construction / Share" regardless of
        // template names in the DB.
        $displayName = $d['label'];
        $workflow_stages[] = [ 'name' => $d['label'], 'display_name' => $displayName, 'description' => $desc, 'number' => $i + 1, 'template_number' => $tplNum ];
    }

    // Map template_number => workflow index
    $numToIndex = [];
    foreach ($workflow_stages as $i => $st) {
        $num = isset($st['template_number']) ? (int)$st['template_number'] : (isset($st['number']) ? (int)$st['number'] : ($i + 1));
        $numToIndex[$num] = $i;
    }

    // Get completed stages from DB
    $completed_stage_map = [];
    try {
        $stage_stmt = $conn->prepare("SELECT stage_number, MAX(completed_at) AS completed_at FROM project_stages WHERE project_id = ? GROUP BY stage_number");
        if ($stage_stmt) {
            $stage_stmt->bind_param('i', $project_id);
            $stage_stmt->execute();
            $stage_result = $stage_stmt->get_result();
            while ($s = $stage_result->fetch_assoc()) {
                $raw_num = (int)$s['stage_number'];
                if (!is_null($s['completed_at']) && isset($numToIndex[$raw_num])) {
                    $idx = $numToIndex[$raw_num];
                    $completed_stage_map[$idx] = $s['completed_at'];
                }
            }
        }
    } catch (Exception $e) { $completed_stage_map = []; }

    $total_stages = count($workflow_stages);
    $completed_stages = 0;
    for ($i = 0; $i < $total_stages; $i++) { if (array_key_exists($i, $completed_stage_map)) $completed_stages++; }
    $completed_stages = max(0, min($completed_stages, $total_stages));
    $progress_percent = $total_stages > 0 ? (int) round(($completed_stages / $total_stages) * 100) : 0;
    if ($total_stages === 0) $current_stage_index = 0; elseif ($completed_stages >= $total_stages) $current_stage_index = max(0, $total_stages - 1); else $current_stage_index = $completed_stages;
    ?>

    <div class="progress-indicator">
        <strong><?= $progress_percent ?>%</strong>
        <?php if ($progress_percent === 100): ?> of stages completed.<?php else: ?> of stages completed. (<?= $completed_stages ?> of <?= $total_stages ?>)<?php endif; ?>
    </div>
    <div class="progress-bar"><div class="progress-fill" style="width: <?= $progress_percent ?>%;"></div></div>

    <div class="stage-tabs">
        <?php foreach ($workflow_stages as $i => $st):
            $tn = isset($st['template_number']) ? (int)$st['template_number'] : (int)($st['number'] ?? $i + 1);
            $is_completed = array_key_exists($i, $completed_stage_map);
            // Treat the first workflow stage (Preparation) as the "materials" stage
            // so projects using different template names still show the tab & materials.
            $stage_name_lower = strtolower($st['name'] ?? $st['stage_name'] ?? '');
            $is_material_stage = ($i === 0) || (stripos($stage_name_lower, 'material') !== false) || (stripos($stage_name_lower, 'prepar') !== false);
            if ($is_completed && $is_material_stage) {
                try {
                    $cstmt = $conn->prepare("SELECT COUNT(*) AS not_obtained FROM project_materials WHERE project_id = ? AND (status IS NULL OR LOWER(status) <> 'obtained')");
                    $cstmt->bind_param('i', $project_id);
                    $cstmt->execute();
                    $cres = $cstmt->get_result()->fetch_assoc();
                    $not_obtained = $cres ? (int)$cres['not_obtained'] : 0;
                    if ($not_obtained > 0) $is_completed = false;
                } catch (Exception $e) { /* ignore */ }
            }
            $is_current = !$is_completed && ($i === $current_stage_index);
            $is_locked = !$is_completed && ($i > $current_stage_index);
            $badgeClass = $is_completed ? 'completed' : ($is_current ? 'current' : ($is_locked ? 'locked' : 'incomplete'));
        ?>
            <?php $stage_name_lower = strtolower($st['name'] ?? $st['stage_name'] ?? '');
                $iconClass = 'fas fa-circle';
                if (stripos($stage_name_lower, 'material') !== false) $iconClass = 'fas fa-box-open';
                else if (stripos($stage_name_lower, 'prepar') !== false) $iconClass = 'fas fa-tools';
                else if (stripos($stage_name_lower, 'construct') !== false) $iconClass = 'fas fa-hard-hat';
                else if (stripos($stage_name_lower, 'share') !== false) $iconClass = 'fas fa-share-alt';
            ?>
            <button class="stage-tab <?php echo ($i === $current_stage_index) ? 'active' : ''; ?> <?php echo $is_locked ? 'locked' : ''; ?>" data-stage-index="<?= $i ?>" data-stage-number="<?= $tn ?>" aria-label="<?= htmlspecialchars($st['name'] ?? 'Step') ?>">
                <span class="tab-icon"><i class="<?= $iconClass ?>" aria-hidden="true"></i></span>
                <span class="tab-meta">
                    <span class="tab-title"><?= htmlspecialchars($st['display_name'] ?? $st['name']) ?></span>
                    <span class="tab-badge <?= $badgeClass ?>"><?php echo $is_completed ? 'Completed' : ($is_current ? 'Current' : ($is_locked ? 'Locked' : 'Incomplete')) ?></span>
                </span>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="workflow-stages-container stages-timeline">
        <?php foreach ($workflow_stages as $index => $stage):
            $is_completed = array_key_exists($index, $completed_stage_map);
            $stage_name_lower = strtolower($stage['name'] ?? $stage['stage_name'] ?? '');
            $is_material_stage = ($index === 0) || (stripos($stage_name_lower, 'material') !== false) || (stripos($stage_name_lower, 'prepar') !== false);
            if ($is_completed && $is_material_stage) {
                try {
                    $cstmt2 = $conn->prepare("SELECT COUNT(*) AS not_obtained FROM project_materials WHERE project_id = ? AND (status IS NULL OR LOWER(status) <> 'obtained')");
                    $cstmt2->bind_param('i', $project_id);
                    $cstmt2->execute();
                    $cres2 = $cstmt2->get_result()->fetch_assoc();
                    $not_obtained2 = $cres2 ? (int)$cres2['not_obtained'] : 0;
                    if ($not_obtained2 > 0) $is_completed = false;
                } catch (Exception $e) { /* ignore */ }
            }
            $is_current = !$is_completed && ($index === $current_stage_index);
            $is_locked = !$is_completed && ($index > $current_stage_index);
            if ($is_completed) $stage_class = 'completed'; elseif ($is_current) $stage_class = 'current'; elseif ($index > $current_stage_index) $stage_class = 'locked'; else $stage_class = 'inactive';
        ?>
        <div class="workflow-stage stage-card <?= $stage_class ?> <?= $is_current ? 'active' : '' ?>" data-stage-index="<?= $index ?>">
            <i class="fas fa-circle stage-icon" aria-hidden="true"></i>
            <div class="stage-content">
                <div class="stage-header">
                    <div class="stage-info">
                        <h3 class="stage-title"><?= htmlspecialchars($stage['name']) ?> <?php if ($is_completed): ?><i class="fas fa-check-circle stage-check" title="Completed"></i><?php endif; ?></h3>
                        <?php if ($is_completed && isset($completed_stage_map[$index])): ?><div class="stage-completed-at">Completed: <?= date('M d, Y', strtotime($completed_stage_map[$index])) ?></div><?php endif; ?>
                        <div class="stage-desc"><?= nl2br(htmlspecialchars($stage['description'] ?? '')) ?></div>
                    </div>
                </div>

                <?php if ($index === 0 /* Preparation */ || strtolower(trim($stage['name'] ?? '')) === 'material collection'): ?>
                    <div class="stage-materials">
                        <h4>Materials Needed</h4>
                        <?php if (empty($materials)): ?>
                            <p class="empty-state">No materials listed.</p>
                        <?php else: ?>
                            <ul class="materials-list-stage">
                                <?php foreach ($materials as $m):
                                    $mid = (int)($m['material_id'] ?? $m['id'] ?? 0);
                                    $currentQty = isset($m['quantity']) ? (int)$m['quantity'] : 0;
                                    $currentStatus = strtolower($m['status'] ?? '');
                                    if ($currentQty <= 0) { $currentStatus = 'obtained'; }
                                    if ($currentStatus === '') $currentStatus = 'needed';
                                    // find latest material photo
                                    $hasPhoto = false; $firstPhotoRel = null; $firstPhotoId = null;
                                    try {
                                        $pp = $conn->prepare("SELECT id, photo_path FROM material_photos WHERE material_id = ? ORDER BY uploaded_at DESC LIMIT 1");
                                        if ($pp) { $pp->bind_param('i', $mid); $pp->execute(); $pres = $pp->get_result(); if ($prow = $pres->fetch_assoc()) { $hasPhoto = true; $firstPhotoRel = htmlspecialchars($prow['photo_path']); $firstPhotoId = (int)$prow['id']; } }
                                    } catch (Exception $e) {}
                                ?>
                                <li class="material-item<?= ($currentStatus !== 'needed') ? ' material-obtained' : '' ?>" data-material-id="<?= $mid ?>">
                                    <div class="material-main">
                                        <span class="mat-name"><?= htmlspecialchars($m['material_name'] ?? $m['name'] ?? '') ?></span>
                                        <div class="mat-meta">
                                            <?php if ($currentQty > 0): ?><span class="mat-qty"><?= htmlspecialchars($currentQty) ?></span><?php endif; ?>
                                            <?php if ($currentStatus !== 'needed' && $currentStatus !== ''): ?>
                                                <span class="badge obtained" aria-hidden="true"><i class="fas fa-check-circle"></i> Obtained</span>
                                                <?php if (!$hasPhoto): ?><button type="button" class="btn small upload-material-photo" data-material-id="<?= $mid ?>" title="Upload photo"><i class="fas fa-camera"></i></button><?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($currentStatus !== 'needed' && $currentStatus !== ''): ?>
                                        <div class="material-photos" data-material-id="<?= $mid ?>">
                                            <?php if ($hasPhoto) echo '<div class="material-photo" data-photo-id="' . $firstPhotoId . '"><img src="' . $firstPhotoRel . '" alt="Material photo"></div>'; else echo '<div class="material-photo placeholder">No photo</div>'; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="material-actions">
                                        <?php if ($currentStatus === 'needed' || $currentStatus === ''): ?>
                                            <a class="btn small find-donations-btn" href="browse.php?query=<?= urlencode($m['material_name'] ?? $m['name'] ?? '') ?>&from_project=<?= $project_id ?>">Find Donations</a>
                                            <form method="POST" class="inline-form" data-obtain-modal="1" style="display:inline-flex;align-items:center;">
                                                <input type="hidden" name="material_id" value="<?= $mid ?>">
                                                <input type="hidden" name="status" value="obtained">
                                                <button type="submit" name="update_material_status" class="btn small obtain-btn" title="Mark obtained"><i class="fas fa-check" aria-hidden="true"></i></button>
                                            </form>
                                            <form method="POST" class="inline-form" data-confirm="Are you sure you want to remove this material?">
                                                <input type="hidden" name="material_id" value="<?= $mid ?>">
                                                <button type="submit" name="remove_material" class="btn small danger"><i class="fas fa-trash" aria-hidden="true"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="stage-actions">
                    <?php if ($index === 0 /* Preparation */ || strtolower(trim($stage['name'] ?? '')) === 'material collection'): ?>
                        <button type="button" class="btn add-material-btn" onclick="showAddMaterialModal()"><i class="fas fa-plus"></i> Add Material</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

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
                        <div class="modal-actions" style="padding: 12px 16px; display: flex; justify-content: flex-end; gap: 8px;">
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
                            const m = String(qtyEl.textContent).match(/\d+/);
                            if (m) total = parseInt(m[0], 10);
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
            let ul = document.querySelector('.workflow-stage.active .materials-list-stage') || document.querySelector('.materials-list-stage') || document.querySelector('.materials-list') || document.querySelector('.stage-materials');
            if (ul && ul.tagName && ul.tagName.toLowerCase() !== 'ul') {
                const inner = ul.querySelector('ul.materials-list-stage') || ul.querySelector('.materials-list-stage');
                if (inner) ul = inner;
            }
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
                                        try { await requestToggleStage(templateNum, projectId); } catch(e) {}
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
                let ul = document.querySelector('.workflow-stage.active .materials-list-stage') || document.querySelector('.materials-list-stage') || document.querySelector('.materials-list') || document.querySelector('.stage-materials');
                if (ul && ul.tagName && ul.tagName.toLowerCase() !== 'ul') {
                    const inner = ul.querySelector('ul.materials-list-stage') || ul.querySelector('.materials-list-stage');
                    if (inner) ul = inner;
                }
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
                                    <form method="POST" class="inline-form" data-confirm="Are you sure you want to remove this material?" action="project_details.php?id=${projectId}">
                                        <input type="hidden" name="material_id" value="${mat.material_id}">
                                        <button type="submit" name="remove_material" class="btn small danger" aria-label="Delete material">
                                            <i class="fas fa-trash" aria-hidden="true"></i>
                                        </button>
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
                                    await requestToggleStage(templateNum, projectId);
                                    try { markStageUncompletedUI(templateNum); } catch(e){}
                                } catch(e){ /* auto-uncomplete after modal add failed */ }
                            }
                        }
                    }
                } catch(e) { /* auto-uncomplete modal add check failed */ }
    } catch (err) { showToast('Network error', 'error'); }
    });

    // ===== DELETE PROJECT HANDLERS =====
    function confirmDeleteProject(projectId) {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-exclamation-triangle" style="color: #dc3545; margin-right: 10px;"></i>Delete Project</h2>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this project?</p>
                    <p style="color: #999; font-size: 13px; margin-top: 15px;">This action cannot be undone. All project data, materials, and photos will be permanently removed.</p>
                </div>
                <div class="modal-footer">
                    <button class="modal-btn secondary" onclick="this.closest('.modal-overlay').remove();">Cancel</button>
                    <button class="modal-btn danger" id="confirmDeleteBtn">Delete Project</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.remove();
        });
        // Bind to the button inside this overlay explicitly to avoid clashes with
        // other elements using the same id on the page.
        const confirmBtn = overlay.querySelector('#confirmDeleteBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function onConfirmClick(ev) {
                ev.preventDefault();
                // disable the button immediately to avoid double-submits
                try { confirmBtn.disabled = true; } catch(e){}
                performDeleteProject(projectId, overlay);
            });
        }
    }

    function performDeleteProject(projectId, overlay) {
        // Prefer the button inside the overlay if available
        let confirmBtn = null;
        try { confirmBtn = overlay && overlay.querySelector ? overlay.querySelector('#confirmDeleteBtn') : document.getElementById('confirmDeleteBtn'); } catch(e) { confirmBtn = document.getElementById('confirmDeleteBtn'); }
        if (confirmBtn) {
            try { confirmBtn.disabled = true; } catch(e){}
            try { confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...'; } catch(e){}
        }
        const fd = new FormData();
        fd.append('project_id', projectId);
        fetch('delete_project.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json().catch(() => null))
        .then(data => {
            overlay.remove();
            if (data && data.success) {
                if (typeof showToast === 'function') showToast('✓ Project deleted successfully', 'success');
                setTimeout(() => window.location.href = 'projects.php', 1200);
            } else {
                if (typeof showToast === 'function') showToast('✗ ' + (data?.message || 'Failed to delete project'), 'error');
            }
        })
        .catch(err => {
            overlay.remove();
            if (typeof showToast === 'function') showToast('✗ Network error - please try again', 'error');
        });
    }

    // Wire up delete button on page load
    document.addEventListener('DOMContentLoaded', function() {
        const deleteBtn = document.querySelector('.delete-project');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const pid = <?= (int)$project_id ?>;
                if (typeof confirmDeleteProject === 'function') {
                    confirmDeleteProject(pid);
                }
            });
        }
    });
</script>
</body>
</html>
