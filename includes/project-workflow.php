<?php
// Shared workflow rendering partial
// Expects (pre-computed) variables from caller:
//   $workflow_stages (array), $completed_stage_map (map idx => completed_at),
//   $current_stage_index (int), $progress_percent (int), $total_stages (int),
// Optional: $conn (mysqli) and $project_id for thumbnails/extra checks.

$workflow_stages = $workflow_stages ?? [];
$completed_stage_map = $completed_stage_map ?? [];
$current_stage_index = isset($current_stage_index) ? (int)$current_stage_index : 0;
$progress_percent = isset($progress_percent) ? (int)$progress_percent : 0;
$total_stages = isset($total_stages) ? (int)$total_stages : count($workflow_stages);
$stage_icons = $stage_icons ?? [1 => 'fa-lightbulb', 2 => 'fa-box', 3 => 'fa-hammer', 4 => 'fa-paint-roller', 5 => 'fa-star', 6 => 'fa-camera'];

?>

<div class="progress-indicator">
    <strong><?= htmlspecialchars($progress_percent) ?>%</strong>
    <?php if ($progress_percent === 100): ?>
        of stages completed.
    <?php else: ?>
        of stages completed. (<?= intval(array_sum(array_map(function($k){ return (int)$k; }, array_keys($completed_stage_map)))) ?> of <?= $total_stages ?>)
    <?php endif; ?>
</div>
<div class="progress-bar">
    <div class="progress-fill" style="width: <?= htmlspecialchars($progress_percent) ?>%;"></div>
</div>

<!-- Tabs: stage titles -->
<div class="stage-tabs">
    <?php foreach ($workflow_stages as $i => $st):
        $tn = isset($st['template_number']) ? (int)$st['template_number'] : (int)($st['number'] ?? ($i + 1));
        $is_completed = array_key_exists($i, $completed_stage_map);

        // allow pages to re-validate a Preparation/Material completion using DB if available
        $stage_name_lower = strtolower($st['stage_name'] ?? $st['name'] ?? '');
        if ($is_completed && isset($conn) && isset($project_id) && (stripos($stage_name_lower, 'material') !== false || stripos($stage_name_lower, 'prepar') !== false)) {
            try {
                $cstmt = $conn->prepare("SELECT COUNT(*) AS not_obtained FROM project_materials WHERE project_id = ? AND (status IS NULL OR LOWER(status) <> 'obtained')");
                if ($cstmt) {
                    $cstmt->bind_param('i', $project_id);
                    $cstmt->execute();
                    $cres = $cstmt->get_result()->fetch_assoc();
                    $not_obtained = $cres ? (int)$cres['not_obtained'] : 0;
                    if ($not_obtained > 0) {
                        $is_completed = false;
                    }
                }
            } catch (Exception $e) { /* ignore */ }
        }

        $is_current = !$is_completed && ($i === $current_stage_index);
        $is_locked = !$is_completed && ($i > $current_stage_index);
        $badgeClass = $is_completed ? 'completed' : ($is_current ? 'current' : ($is_locked ? 'locked' : 'incomplete'));

        // build thumbnail if possible
        $thumb = '';
        if (isset($conn) && isset($project_id)) {
            try {
                // stage photo
                $tp = $conn->prepare("SELECT photo_path FROM stage_photos WHERE project_id = ? AND stage_number = ? ORDER BY created_at DESC LIMIT 1");
                if ($tp) {
                    $tp->bind_param('ii', $project_id, $tn);
                    $tp->execute();
                    $tres = $tp->get_result()->fetch_assoc();
                    if ($tres && !empty($tres['photo_path'])) $thumb = 'assets/uploads/' . $tres['photo_path'];
                }
            } catch (Exception $e) { /* ignore */ }
            if (empty($thumb) && (stripos($stage_name_lower, 'material') !== false || stripos($stage_name_lower, 'prepar') !== false)) {
                try {
                    $mp = $conn->prepare("SELECT photo_path FROM material_photos WHERE project_id = ? AND material_id IN (SELECT material_id FROM project_materials WHERE project_id = ?) ORDER BY created_at DESC LIMIT 1");
                    if ($mp) {
                        $mp->bind_param('ii', $project_id, $project_id);
                        $mp->execute();
                        $mres = $mp->get_result()->fetch_assoc();
                        if ($mres && !empty($mres['photo_path'])) $thumb = 'assets/uploads/' . $mres['photo_path'];
                    }
                } catch (Exception $e) { /* ignore */ }
            }
        }

        $stage_name_lower = strtolower($st['stage_name'] ?? $st['name'] ?? '');
        $iconClass = 'fas fa-circle';
        if (stripos($stage_name_lower, 'material') !== false || stripos($stage_name_lower, 'prepar') !== false) $iconClass = 'fas fa-box-open';
        else if (stripos($stage_name_lower, 'prepar') !== false) $iconClass = 'fas fa-tools';
        else if (stripos($stage_name_lower, 'construct') !== false) $iconClass = 'fas fa-hard-hat';
        else if (stripos($stage_name_lower, 'finish') !== false) $iconClass = 'fas fa-paint-roller';
        else if (stripos($stage_name_lower, 'share') !== false) $iconClass = 'fas fa-share-alt';

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
        <!-- intentionally left blank for consistent layout -->
    </div>
</div>

<div class="workflow-stages-container stages-timeline">
    <?php foreach ($workflow_stages as $index => $stage):
        $is_completed = array_key_exists($index, $completed_stage_map);
        $stage_name_lower = strtolower($stage['name'] ?? $stage['stage_name'] ?? '');
        // if marked completed, be conservative for materials
        if ($is_completed && isset($conn) && isset($project_id) && stripos($stage_name_lower, 'material') !== false) {
            try {
                $cstmt2 = $conn->prepare("SELECT COUNT(*) AS not_obtained FROM project_materials WHERE project_id = ? AND (status IS NULL OR LOWER(status) <> 'obtained')");
                if ($cstmt2) {
                    $cstmt2->bind_param('i', $project_id);
                    $cstmt2->execute();
                    $cres2 = $cstmt2->get_result()->fetch_assoc();
                    $not_obtained2 = $cres2 ? (int)$cres2['not_obtained'] : 0;
                    if ($not_obtained2 > 0) {
                        $is_completed = false;
                    }
                }
            } catch (Exception $e) { /* ignore */ }
        }

        $is_current = !$is_completed && ($index === $current_stage_index);
        $is_locked = !$is_completed && ($index > $current_stage_index);
        if ($is_completed) $stage_class = 'completed'; elseif ($is_current) $stage_class = 'current'; elseif ($is_locked) $stage_class = 'locked'; else $stage_class = 'inactive';
        $activeClass = $is_current ? 'active' : '';
        $stageNumAttr = isset($stage['template_number']) && $stage['template_number'] ? (int)$stage['template_number'] : ($stage['number'] ?? ($index+1));
    ?>
        <div class="workflow-stage stage-card <?= $stage_class ?> <?= $activeClass ?>" data-stage-index="<?= $index ?>" data-stage-number="<?= htmlspecialchars($stageNumAttr) ?>">
            <?php $icon = $stage_icons[$stage['number']] ?? 'fa-circle'; ?>
            <i class="fas <?= $icon ?> stage-icon" aria-hidden="true"></i>
            <div class="stage-content">
                <div class="stage-header">
                    <div class="stage-info">
                        <h3 class="stage-title">
                            <?= htmlspecialchars($stage['name'] ?? $stage['stage_name']) ?>
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

                <?php if (isset($conn) && isset($project_id)):
                    // list stage photos (if any)
                    try {
                        $photos_stmt = $conn->prepare("SELECT photo_path FROM stage_photos WHERE project_id = ? AND stage_number = ? ORDER BY created_at DESC");
                        $photos_stmt->bind_param("ii", $project_id, $stageNumAttr);
                        $photos_stmt->execute();
                        $photos_result = $photos_stmt->get_result();
                        $stage_photos = $photos_result->fetch_all(MYSQLI_ASSOC);
                        $photo_count = count($stage_photos);
                    } catch (Exception $e) { $stage_photos = []; $photo_count = 0; }
                else:
                    $stage_photos = [];
                    $photo_count = 0;
                endif;

                if ($photo_count > 0): ?>
                <div class="stage-photos">
                    <?php foreach ($stage_photos as $photo): ?>
                        <img src="assets/uploads/<?= htmlspecialchars($photo['photo_path']) ?>" alt="Stage photo" onclick="openImageViewer('assets/uploads/<?= htmlspecialchars($photo['photo_path']) ?>')" class="stage-photo">
                    <?php endforeach; ?>
                    <div class="photo-count"><?= $photo_count ?> photo<?= $photo_count>1 ? 's' : '' ?></div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
// End of partial
?>
