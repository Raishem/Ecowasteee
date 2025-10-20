<?php
header('Content-Type: application/json');
session_start();
require_once 'config.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$stage_number = isset($_POST['stage_number']) ? (int)$_POST['stage_number'] : 0;
// stage_number expected as template number
$stage_number = isset($_POST['stage_number']) ? (int)$_POST['stage_number'] : 0;

if (!$project_id || !$stage_number) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    $conn = getDBConnection();
    
    // Verify project belongs to user
    $check_stmt = $conn->prepare("SELECT project_id FROM projects WHERE project_id = ? AND user_id = ?");
    $check_stmt->bind_param("ii", $project_id, $_SESSION['user_id']);
    $check_stmt->execute();
    if (!$check_stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit;
    }
    
    // Check if previous stages are completed
    $prev_check = $conn->prepare("
        SELECT COUNT(*) as incomplete
        FROM (
            SELECT stage_number 
            FROM stage_templates 
            WHERE stage_number < ?
        ) needed_stages
        LEFT JOIN project_stages ps ON 
            ps.project_id = ? AND 
            ps.stage_number = needed_stages.stage_number AND 
            ps.is_completed = 1
        WHERE ps.project_id IS NULL
    ");

        // Determine if stage is already completed
        $check_comp = $conn->prepare("SELECT is_completed FROM project_stages WHERE project_id = ? AND stage_number = ? LIMIT 1");
        $check_comp->bind_param("ii", $project_id, $stage_number);
        $check_comp->execute();
        $r = $check_comp->get_result()->fetch_assoc();
        $already_completed = ($r && (int)$r['is_completed'] === 1);

        // Helper: fetch stage name from template so we can apply stage-specific requirements
        $stage_name = '';
        try {
            $name_stmt = $conn->prepare("SELECT stage_name FROM stage_templates WHERE stage_number = ? LIMIT 1");
            $name_stmt->bind_param("i", $stage_number);
            $name_stmt->execute();
            $name_res = $name_stmt->get_result()->fetch_assoc();
            if ($name_res && !empty($name_res['stage_name'])) {
                $stage_name = strtolower($name_res['stage_name']);
            } else {
                // fallback: read any existing project_stages entry for a name
                $ps = $conn->prepare("SELECT stage_name FROM project_stages WHERE project_id = ? AND stage_number = ? LIMIT 1");
                $ps->bind_param("ii", $project_id, $stage_number);
                $ps->execute();
                $prs = $ps->get_result()->fetch_assoc();
                if ($prs && !empty($prs['stage_name'])) $stage_name = strtolower($prs['stage_name']);
            }
        } catch (Exception $e) {
            // ignore and continue with empty stage_name
            $stage_name = '';
        }

        // If attempting to complete (i.e., not already completed), enforce requirements
        if (!$already_completed) {
            // Ensure previous stages are completed (can't progress forward if earlier stages incomplete)
            $prev_check = $conn->prepare(
                "SELECT COUNT(*) as incomplete FROM (SELECT stage_number FROM stage_templates WHERE stage_number < ?) needed_stages LEFT JOIN project_stages ps ON ps.project_id = ? AND ps.stage_number = needed_stages.stage_number AND ps.is_completed = 1 WHERE ps.project_id IS NULL"
            );
            $prev_check->bind_param("ii", $stage_number, $project_id);
            $prev_check->execute();
            $result = $prev_check->get_result();
            $incomplete = $result->fetch_assoc()['incomplete'];
            if ($incomplete > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot complete this stage until all previous stages are completed']);
                exit;
            }

            // Determine whether this template corresponds to a Material Collection stage.
            // First check the fetched stage_name, then as a fallback scan templates for any stage with 'material' in the name
            $isMaterialStage = ($stage_name !== '' && strpos($stage_name, 'material') !== false);
            if (!$isMaterialStage) {
                try {
                    $scan = $conn->prepare("SELECT stage_number, stage_name FROM stage_templates WHERE LOWER(stage_name) LIKE '%material%'");
                    $scan->execute();
                    $sres = $scan->get_result();
                    $materialTemplateNumbers = [];
                    while ($sr = $sres->fetch_assoc()) {
                        $materialTemplateNumbers[] = (int)$sr['stage_number'];
                    }
                    if (in_array($stage_number, $materialTemplateNumbers, true)) $isMaterialStage = true;
                } catch (Exception $e) {
                    // ignore scan errors
                }
            }

            // Example requirement: for material collection step, require every material to be obtained OR have at least one photo
            if ($isMaterialStage) {
                    // Server-side compatibility: first verify per-material requirements (each material must be obtained OR have a material photo).
                    try {
                        $totStmt = $conn->prepare("SELECT COUNT(*) AS tot FROM project_materials WHERE project_id = ?");
                        $totStmt->bind_param('i', $project_id);
                        $totStmt->execute();
                        $totRes = $totStmt->get_result()->fetch_assoc();
                        $tot = isset($totRes['tot']) ? (int)$totRes['tot'] : 0;

                        if ($tot > 0) {
                            $haveStmt = $conn->prepare("SELECT COUNT(*) AS have FROM project_materials pm WHERE pm.project_id = ? AND (LOWER(pm.status) = 'obtained' OR EXISTS(SELECT 1 FROM material_photos mp WHERE mp.material_id = pm.material_id LIMIT 1))");
                            $haveStmt->bind_param('i', $project_id);
                            $haveStmt->execute();
                            $haveRes = $haveStmt->get_result()->fetch_assoc();
                            $have = isset($haveRes['have']) ? (int)$haveRes['have'] : 0;
                            if ($have < $tot) {
                                // Not all materials obtained/have a photo — return a clear reason so client can show which
                                echo json_encode([
                                    'success' => false,
                                    'message' => 'Cannot complete Material Collection: some materials are missing (not obtained or missing photo)',
                                    'reason' => 'missing_materials',
                                    'total' => $tot,
                                    'have' => $have
                                ]);
                                exit;
                            }
                        }
                    } catch (Exception $e) {
                        // ignore and continue to fallback checks below
                    }

                // Per-material requirement satisfied — allow completion. No additional stage-level before/after photos required.
            }

            // All checks passed — mark as completed
            $stmt = $conn->prepare(
                "INSERT INTO project_stages (project_id, stage_number, stage_name, is_completed, completed_at) SELECT ?, stage_number, stage_name, 1, NOW() FROM stage_templates WHERE stage_number = ? ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = NOW()"
            );
            $stmt->bind_param("ii", $project_id, $stage_number);
            $stmt->execute();

            echo json_encode(['success' => true, 'action' => 'completed']);
            exit;
        }

        // If already completed, allow toggling back to not completed provided no later stages are completed
        // Check if any later stages are completed
        $later_check = $conn->prepare("SELECT COUNT(*) as later_completed FROM project_stages WHERE project_id = ? AND stage_number > ? AND is_completed = 1");
        $later_check->bind_param("ii", $project_id, $stage_number);
        $later_check->execute();
        $later_completed = (int)$later_check->get_result()->fetch_assoc()['later_completed'];
        if ($later_completed > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot mark this stage as incomplete while later stages are completed. Uncomplete later stages first.']);
            exit;
        }

        // Proceed to unset completion
        $unset = $conn->prepare("UPDATE project_stages SET is_completed = 0, completed_at = NULL WHERE project_id = ? AND stage_number = ?");
        $unset->bind_param("ii", $project_id, $stage_number);
        $unset->execute();

        echo json_encode(['success' => true, 'action' => 'uncompleted']);
            exit; // ensure we do not fall through to the completion path below

    // Insert or update stage completion
        // No further action: successful branches above already returned.
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>