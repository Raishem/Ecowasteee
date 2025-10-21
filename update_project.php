<?php
session_start();
require_once 'config.php';

// Always return JSON
header('Content-Type: application/json');

// API endpoint: always return JSON. Temporary debug wrapper removed.

if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }

$conn = getDBConnection();
if (!$conn) { echo json_encode(['success'=>false,'message'=>'DB connection failed']); exit; }

// (previously had a small diagnostic logger; removed at user's request)

function _get_int($k) { return filter_input(INPUT_POST, $k, FILTER_VALIDATE_INT); }
function _get_str($k) { return isset($_POST[$k]) ? trim($_POST[$k]) : null; }

// GET handlers
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_project_details') {
    $project_id = filter_input(INPUT_GET,'project_id',FILTER_VALIDATE_INT);
    if (!$project_id) { echo json_encode(['success'=>false,'message'=>'Invalid project id']); exit; }

    // Verify ownership and fetch project
    $stmt = $conn->prepare("SELECT * FROM projects WHERE project_id = ? AND user_id = ?");
    $stmt->bind_param('ii',$project_id,$_SESSION['user_id']); $stmt->execute(); $r = $stmt->get_result(); $proj = $r ? $r->fetch_assoc() : null;
    if (!$proj) { echo json_encode(['success'=>false,'message'=>'Project not found']); exit; }

    // materials
    $m = $conn->prepare('SELECT material_id, material_name, quantity, unit, status FROM project_materials WHERE project_id = ?');
    $m->bind_param('i',$project_id); $m->execute(); $mr = $m->get_result(); $materials = $mr ? $mr->fetch_all(MYSQLI_ASSOC) : [];

    // steps
    $s = $conn->prepare('SELECT ps.*, GROUP_CONCAT(sp.photo_path) as photos FROM project_steps ps LEFT JOIN step_photos sp ON ps.step_id = sp.step_id WHERE ps.project_id = ? GROUP BY ps.step_id ORDER BY ps.step_number');
    $s->bind_param('i',$project_id); $s->execute(); $sr = $s->get_result(); $steps = $sr ? $sr->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode(['success'=>true,'project'=>$proj,'materials'=>$materials,'steps'=>$steps]); exit;
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid method']); exit; }
if (!isset($_POST['action'])) { echo json_encode(['success'=>false,'message'=>'Missing parameters: action required']); exit; }

$action = $_POST['action'];
// For many actions we require project_id and owner verification. Only enforce per-action below.
// Retrieve project_id if present; we'll validate it for actions that need it.
$project_id = isset($_POST['project_id']) ? filter_input(INPUT_POST,'project_id',FILTER_VALIDATE_INT) : null;

// helper: require project_id and verify ownership for actions that need project context
function require_project_and_owner() {
    global $project_id, $conn;
    if (!$project_id) { echo json_encode(['success'=>false,'message'=>'Missing project_id']); exit; }
    $chk = $conn->prepare('SELECT user_id FROM projects WHERE project_id = ?'); $chk->bind_param('i',$project_id); $chk->execute(); $cres = $chk->get_result(); $prow = $cres ? $cres->fetch_assoc() : null;
    if (!$prow || $prow['user_id'] != $_SESSION['user_id']) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
}

try {
    // Enforce project ownership for these actions which require a project context
    $actionsRequireProject = ['add_material','remove_material','update_material_status','toggle_step','add_step','update_project_status','publish_shared_project','generate_share_link','update_title','update_description','delete_material','delete_material'];
    if (in_array($action, $actionsRequireProject, true)) {
        require_project_and_owner();
    }

    switch ($action) {
        case 'add_material':
            // Accept either 'name' or 'material_name' to be compatible with the dynamic modal
            $name = _get_str('name');
            if (!$name) $name = _get_str('material_name');
            $unit = _get_str('unit') ?: ''; // unit is optional in the modal
            $quantity = filter_input(INPUT_POST,'quantity',FILTER_VALIDATE_INT);
            if (!$name) {
                // Try to recover from alternate POST keys (some clients use material_name)
                foreach ($_POST as $k => $v) {
                    if (in_array($k, ['action','project_id','quantity','add_material','_'])) continue;
                    if (is_string($v) && trim($v) !== '') { $name = trim($v); break; }
                }
            }
            if (!$name) { echo json_encode(['success'=>false,'message'=>'Missing material data','received_keys'=>array_values(array_keys($_POST))]); exit; }
            $stmt = $conn->prepare('INSERT INTO project_materials (project_id, material_name, quantity, unit, created_at) VALUES (?, ?, ?, ?, NOW())');
            $qty = is_numeric($quantity) ? (int)$quantity : 0;
            // types: project_id (i), name (s), qty (i), unit (s)
            $stmt->bind_param('isis',$project_id,$name,$qty,$unit);
            $stmt->execute();
            $mid = $conn->insert_id;
            $f = $conn->prepare('SELECT material_id, material_name, quantity, unit, COALESCE(status,\'needed\') as status FROM project_materials WHERE material_id = ?');
            $f->bind_param('i',$mid); $f->execute(); $fres = $f->get_result(); $mat = $fres ? $fres->fetch_assoc() : null;

            // If a material was added, ensure any Material Collection stage marked completed is unset
            // This keeps server-state authoritative and avoids UI-only toggles getting out of sync.
            try {
                $unset = $conn->prepare(
                    "UPDATE project_stages ps
                        JOIN stage_templates st ON ps.stage_number = st.stage_number
                        SET ps.is_completed = 0, ps.completed_at = NULL
                        WHERE ps.project_id = ? AND ps.is_completed = 1 AND LOWER(st.stage_name) LIKE '%material%'"
                );
                $unset->bind_param('i', $project_id);
                $unset->execute();
            } catch (Exception $e) { /* ignore unset failures */ }

            echo json_encode(['success'=>true,'material_id'=>$mid,'material'=>$mat]); exit;

        case 'remove_material':
            $mid = filter_input(INPUT_POST,'material_id',FILTER_VALIDATE_INT); if (!$mid) { echo json_encode(['success'=>false,'message'=>'Invalid material id']); exit; }
            $d = $conn->prepare('DELETE FROM project_materials WHERE material_id = ? AND project_id = ?'); $d->bind_param('ii',$mid,$project_id); $d->execute();
            echo json_encode(['success'=>true,'material_id'=>$mid]); exit;

        case 'update_material_status':
            $mid = filter_input(INPUT_POST,'material_id',FILTER_VALIDATE_INT);
            $status = isset($_POST['status']) ? $_POST['status'] : null;
            $qty = isset($_POST['quantity']) ? (int)$_POST['quantity'] : null;
            if ($qty !== null) { $up = $conn->prepare('UPDATE project_materials SET quantity = GREATEST(quantity - ?, 0) WHERE material_id = ? AND project_id = ?'); $up->bind_param('iii',$qty,$mid,$project_id); $up->execute(); }
            if ($status !== null) { $u2 = $conn->prepare('UPDATE project_materials SET status = ? WHERE material_id = ? AND project_id = ?'); $u2->bind_param('sii',$status,$mid,$project_id); $u2->execute(); }
            $q = $conn->prepare('SELECT quantity, status FROM project_materials WHERE material_id = ? AND project_id = ?'); $q->bind_param('ii',$mid,$project_id); $q->execute(); $qr = $q->get_result(); $row = $qr ? $qr->fetch_assoc() : null;
            $current_qty = $row ? (int)$row['quantity'] : null; $current_status = $row ? $row['status'] : ($status ?: 'needed');
            if (!is_null($current_qty) && $current_qty <= 0 && $current_status !== 'obtained') { $up3 = $conn->prepare("UPDATE project_materials SET status = 'obtained' WHERE material_id = ? AND project_id = ?"); $up3->bind_param('ii',$mid,$project_id); $up3->execute(); $current_status = 'obtained'; }
            // check if all obtained
            $cstmt = $conn->prepare('SELECT COUNT(*) AS not_obtained FROM project_materials WHERE project_id = ? AND (status IS NULL OR status <> \'obtained\')'); $cstmt->bind_param('i',$project_id); $cstmt->execute(); $cres2 = $cstmt->get_result(); $crow = $cres2 ? $cres2->fetch_assoc() : null; $not_obtained = $crow ? (int)$crow['not_obtained'] : 0;
            $stage_completed = ($not_obtained === 0);
            echo json_encode(['success'=>true,'material_id'=>$mid,'status'=>$current_status,'quantity'=>$current_qty,'stage_completed'=>$stage_completed]); exit;

        case 'toggle_step':
            $step_id = filter_input(INPUT_POST,'step_id',FILTER_VALIDATE_INT); $set_done = isset($_POST['done']) ? (int)$_POST['done'] : 1;
            if (!$step_id) { echo json_encode(['success'=>false,'message'=>'Missing step id']); exit; }
            // ensure progress table exists
            $conn->query("CREATE TABLE IF NOT EXISTS project_step_progress (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, step_id INT NOT NULL, is_done TINYINT(1) DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $s = $conn->prepare('SELECT project_id, step_number FROM project_steps WHERE step_id = ?'); $s->bind_param('i',$step_id); $s->execute(); $sres = $s->get_result(); $sinfo = $sres ? $sres->fetch_assoc() : null; if (!$sinfo) { echo json_encode(['success'=>false,'message'=>'Step not found']); exit; }
            $projectIdFromStep = (int)$sinfo['project_id']; $stepNumber = (int)$sinfo['step_number']; if ($projectIdFromStep !== $project_id) { echo json_encode(['success'=>false,'message'=>'Step does not belong to this project']); exit; }
            if ($set_done && $stepNumber > 1) { $prev = $conn->prepare('SELECT psp.is_done FROM project_step_progress psp JOIN project_steps ps ON ps.step_id = psp.step_id WHERE ps.project_id = ? AND ps.step_number = ?'); $prev->bind_param('ii',$projectIdFromStep,$stepNumber-1); $prev->execute(); $prevR = $prev->get_result(); $prevRow = $prevR ? $prevR->fetch_assoc() : null; if (!$prevRow || !(int)$prevRow['is_done']) { echo json_encode(['success'=>false,'message'=>'Complete previous step first']); exit; } }
            $up = $conn->prepare('SELECT id FROM project_step_progress WHERE project_id = ? AND step_id = ?'); $up->bind_param('ii',$projectIdFromStep,$step_id); $up->execute(); $upR = $up->get_result(); $exists = $upR ? $upR->fetch_assoc() : null; if ($exists) { $u2 = $conn->prepare('UPDATE project_step_progress SET is_done = ? WHERE id = ?'); $u2->bind_param('ii',$set_done,$exists['id']); $u2->execute(); } else { $i2 = $conn->prepare('INSERT INTO project_step_progress (project_id, step_id, is_done) VALUES (?, ?, ?)'); $i2->bind_param('iii',$projectIdFromStep,$step_id,$set_done); $i2->execute(); }
            echo json_encode(['success'=>true]); exit;

        case 'add_step':
            $title = _get_str('title'); $instructions = _get_str('instructions');
            if (!$title) { echo json_encode(['success'=>false,'message'=>'Missing title']); exit; }
            $conn->begin_transaction();
            $snum = $conn->prepare('SELECT COALESCE(MAX(step_number),0)+1 as next_step FROM project_steps WHERE project_id = ?'); $snum->bind_param('i',$project_id); $snum->execute(); $snumR = $snum->get_result(); $snumRow = $snumR ? $snumR->fetch_assoc() : null; $next_step = $snumRow ? $snumRow['next_step'] : 1;
            $ins = $conn->prepare('INSERT INTO project_steps (project_id, step_number, title, instructions) VALUES (?, ?, ?, ?)'); $ins->bind_param('iiss',$project_id,$next_step,$title,$instructions); $ins->execute(); $step_id = $conn->insert_id;
            if (!empty($_FILES['photos']['name'][0])) {
                $upload_dir = 'assets/uploads/';
                foreach ($_FILES['photos']['tmp_name'] as $k=>$tmp) { $fn = uniqid().'_'.basename($_FILES['photos']['name'][$k]); $t = $upload_dir.$fn; if (move_uploaded_file($tmp,$t)) { $insP = $conn->prepare('INSERT INTO step_photos (step_id, photo_path) VALUES (?,?)'); $insP->bind_param('is',$step_id,$fn); $insP->execute(); } }
            }
            $conn->commit(); echo json_encode(['success'=>true,'step_id'=>$step_id]); exit;

        case 'update_project_status':
            $status = isset($_POST['status']) ? $_POST['status'] : null; if (!$status) { echo json_encode(['success'=>false,'message'=>'Missing status']); exit; }
            $allowed = ['collecting','in_progress','completed']; if (!in_array($status,$allowed)) { echo json_encode(['success'=>false,'message'=>'Invalid status']); exit; }
            $up = $conn->prepare("UPDATE projects SET status = ?, completion_date = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END, updated_at = NOW() WHERE project_id = ?"); $up->bind_param('ssi',$status,$status,$project_id); $up->execute(); echo json_encode(['success'=>true]); exit;

        case 'publish_shared_project':
            // simplified publish: verify completed and copy minimal data
            $conn->begin_transaction();
            $pstmt = $conn->prepare('SELECT project_name, description, cover_photo, tags, status FROM projects WHERE project_id = ?'); $pstmt->bind_param('i',$project_id); $pstmt->execute(); $pdr = $pstmt->get_result(); $pdata = $pdr ? $pdr->fetch_assoc() : null; if (!$pdata) { throw new Exception('Project not found'); }
            if (!isset($pdata['status']) || $pdata['status'] !== 'completed') { throw new Exception('Only completed projects can be shared'); }
            $ins = $conn->prepare("INSERT INTO shared_projects (project_id, user_id, title, description, cover_photo, tags, privacy, created_at) VALUES (?, ?, ?, ?, ?, ?, 'public', NOW())"); $ins->bind_param('iissss',$project_id,$_SESSION['user_id'],$pdata['project_name'],$pdata['description'],$pdata['cover_photo'],$pdata['tags']); $ins->execute(); $shared_id = $conn->insert_id;
            // copy materials
            $mstmt = $conn->prepare('SELECT material_name, quantity, unit, is_found FROM project_materials WHERE project_id = ?'); $mstmt->bind_param('i',$project_id); $mstmt->execute(); $mr = $mstmt->get_result(); while ($m = $mr->fetch_assoc()) { $extra = json_encode(['unit'=>$m['unit'],'is_found'=>(int)$m['is_found']]); $c = $conn->prepare('INSERT INTO shared_materials (shared_id, name, quantity, extra) VALUES (?, ?, ?, ?)'); $c->bind_param('isis',$shared_id,$m['material_name'],$m['quantity'],$extra); $c->execute(); }
            // copy steps
            $sstmt = $conn->prepare('SELECT step_id, step_number, title, instructions, IFNULL(is_done,0) as is_done FROM project_steps WHERE project_id = ? ORDER BY step_number'); $sstmt->bind_param('i',$project_id); $sstmt->execute(); $sr = $sstmt->get_result(); while ($srow = $sr->fetch_assoc()) { $insStep = $conn->prepare('INSERT INTO shared_steps (shared_id, step_number, title, instructions, is_done) VALUES (?, ?, ?, ?, ?)'); $insStep->bind_param('iissi',$shared_id,$srow['step_number'],$srow['title'],$srow['instructions'],$srow['is_done']); $insStep->execute(); $new_step_id = $conn->insert_id; $ph = $conn->prepare('SELECT photo_path FROM step_photos WHERE step_id = ?'); $ph->bind_param('i',$srow['step_id']); $ph->execute(); $phr = $ph->get_result(); while ($prow = $phr->fetch_assoc()) { $insP = $conn->prepare('INSERT INTO shared_step_photos (step_id, path) VALUES (?, ?)'); $insP->bind_param('is',$new_step_id,$prow['photo_path']); $insP->execute(); } }
            $a = $conn->prepare('INSERT INTO shared_activities (shared_id, user_id, activity_type, data, created_at) VALUES (?, ?, ?, ?, NOW())'); $at = 'publish'; $a->bind_param('iiss',$shared_id,$_SESSION['user_id'],$at,json_encode(['project_id'=>$project_id])); $a->execute(); $conn->commit();
            $share_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/shared_project.php?id='.$shared_id; echo json_encode(['success'=>true,'shared_id'=>$shared_id,'share_url'=>$share_url]); exit;

        case 'shared_activity':
            $shared_id = filter_input(INPUT_POST,'shared_id',FILTER_VALIDATE_INT); $atype = isset($_POST['activity_type']) ? $_POST['activity_type'] : null; if (!$shared_id || !$atype) { echo json_encode(['success'=>false,'message'=>'Missing parameters']); exit; }
            if ($atype === 'like') { $likeStmt = $conn->prepare('SELECT id FROM shared_likes WHERE shared_id = ? AND user_id = ?'); $likeStmt->bind_param('ii',$shared_id,$_SESSION['user_id']); $likeStmt->execute(); $lr = $likeStmt->get_result(); $exists = $lr ? $lr->fetch_assoc() : null; if ($exists) { $d = $conn->prepare('DELETE FROM shared_likes WHERE id = ?'); $d->bind_param('i',$exists['id']); $d->execute(); $liked = false; } else { $i = $conn->prepare('INSERT INTO shared_likes (shared_id, user_id) VALUES (?, ?)'); $i->bind_param('ii',$shared_id,$_SESSION['user_id']); $i->execute(); $liked = true; } $insAct = $conn->prepare('INSERT INTO shared_activities (shared_id, user_id, activity_type, data) VALUES (?, ?, ?, ?)'); $insAct->bind_param('iiss',$shared_id,$_SESSION['user_id'],$atype,json_encode(['liked'=>$liked])); $insAct->execute(); echo json_encode(['success'=>true,'liked'=>$liked]); exit; }

        case 'unpublish_shared_project':
            $shared_id = filter_input(INPUT_POST,'shared_id',FILTER_VALIDATE_INT); if (!$shared_id) { echo json_encode(['success'=>false,'message'=>'Missing shared id']); exit; }
            $s = $conn->prepare('SELECT user_id FROM shared_projects WHERE shared_id = ?'); $s->bind_param('i',$shared_id); $s->execute(); $sr = $s->get_result(); $owner = $sr ? $sr->fetch_assoc() : null; if (!$owner || $owner['user_id'] != $_SESSION['user_id']) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; } $d = $conn->prepare('DELETE FROM shared_projects WHERE shared_id = ?'); $d->bind_param('i',$shared_id); $d->execute(); echo json_encode(['success'=>true]); exit;

        case 'generate_share_link':
            $share_token = bin2hex(random_bytes(16)); $share_stmt = $conn->prepare('INSERT INTO project_shares (project_id, share_token, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE share_token = VALUES(share_token)'); $share_stmt->bind_param('is',$project_id,$share_token); $share_stmt->execute(); $share_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/shared_project.php?token='.$share_token; echo json_encode(['success'=>true,'share_url'=>$share_url]); exit;

        case 'update_title':
            $title = _get_str('title'); if (empty($title)) { echo json_encode(['success'=>false,'message'=>'Title required']); exit; } $u = $conn->prepare('UPDATE projects SET project_name = ? WHERE project_id = ?'); $u->bind_param('si',$title,$project_id); $u->execute(); echo json_encode(['success'=>true]); exit;

        case 'update_description':
            $desc = _get_str('description'); if (empty($desc)) { echo json_encode(['success'=>false,'message'=>'Description required']); exit; } $u = $conn->prepare('UPDATE projects SET description = ? WHERE project_id = ?'); $u->bind_param('si',$desc,$project_id); $u->execute(); echo json_encode(['success'=>true]); exit;

        case 'delete_material':
            $material_id = filter_input(INPUT_POST,'material_id',FILTER_VALIDATE_INT); if (!$material_id) { echo json_encode(['success'=>false,'message'=>'Invalid material id']); exit; } $d = $conn->prepare('DELETE FROM project_materials WHERE material_id = ? AND project_id = ?'); $d->bind_param('ii',$material_id,$project_id); $d->execute(); echo json_encode(['success'=>true,'material_id'=>$material_id]); exit;

        default:
            echo json_encode(['success'=>false,'message'=>'Invalid action']); exit;
    }
} catch (Exception $e) {
    // rollback if in transaction
    if ($conn && method_exists($conn,'rollback')) {
        // mysqli: use rollback only if in transaction -- best-effort
        @$conn->rollback();
    }
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}
