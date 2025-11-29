<?php
// Shared materials UI partial
// Expects $materials (array), $project_id (int), optional $conn for photo checks

$materials = $materials ?? [];
$project_id = isset($project_id) ? (int)$project_id : 0;
$conn = $conn ?? null;

?>
<div class="materials-section">
    <div class="materials-header">
        <div class="materials-title-wrapper">
            <i class="fas fa-box"></i>
            <h2>Materials Needed</h2>
        </div>
        <button class="add-material" onclick="(typeof window.showAddMaterialModal === 'function' ? showAddMaterialModal() : (typeof window.showAddMaterialForm === 'function' ? showAddMaterialForm() : null))">
            <i class="fas fa-plus"></i>
            Add Material
        </button>
    </div>

    <div class="materials-list">
        <ul>
            <?php if (empty($materials)): ?>
                <li class="empty-state">No materials listed.</li>
            <?php else: ?>
                <?php foreach ($materials as $m): ?>
                    <?php $mid = (int)($m['material_id'] ?? $m['id'] ?? 0); ?>
                    <?php $currentQty = isset($m['quantity']) ? (int)$m['quantity'] : 0; ?>
                    <?php $currentStatus = strtolower($m['status'] ?? ''); if ($currentQty <= 0) { $currentStatus = 'obtained'; } if ($currentStatus === '') $currentStatus = 'needed'; ?>
                    <?php // find latest photo if DB connection available
                        $hasPhoto = false; $firstPhotoRel = null; $firstPhotoId = null;
                        if ($conn) {
                            try {
                                $pp = $conn->prepare("SELECT id, photo_path FROM material_photos WHERE material_id = ? ORDER BY uploaded_at DESC LIMIT 1");
                                if ($pp) { $pp->bind_param('i', $mid); $pp->execute(); $pres = $pp->get_result(); if ($prow = $pres->fetch_assoc()) { $hasPhoto = true; $firstPhotoRel = htmlspecialchars($prow['photo_path']); $firstPhotoId = (int)$prow['id']; } }
                            } catch (Exception $e) { /* ignore */ }
                        }
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
                                        <button type="button" class="btn small upload-material-photo" data-material-id="<?= $mid ?>" title="Upload photo" aria-label="Upload material photo"><i class="fas fa-camera"></i></button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($currentStatus !== 'needed' && $currentStatus !== ''): ?>
                            <div class="material-photos" data-material-id="<?= $mid ?>">
                                <?php if ($hasPhoto): ?>
                                    <div class="material-photo" data-photo-id="<?= $firstPhotoId ?>">
                                        <img src="<?= $firstPhotoRel ?>" alt="Material photo" onclick="openImageViewer('<?= $firstPhotoRel ?>')">
                                        <button type="button" class="material-photo-delete" title="Delete photo" onclick="(function(el){try{el.style.boxShadow='0 0 0 6px rgba(220,53,69,0.95)'; setTimeout(function(){try{el.style.boxShadow='';}catch(e){}},800);}catch(e){}; try{processMaterialPhotoDelete(el);}catch(e){}; })(this); return false;" onpointerdown="try{this.style.boxShadow='0 0 0 6px rgba(220,53,69,0.95)';}catch(e){}" onmousedown="try{this.style.boxShadow='0 0 0 6px rgba(220,53,69,0.95)';}catch(e){}" ontouchstart="try{this.style.boxShadow='0 0 0 6px rgba(220,53,69,0.95)';}catch(e){}"><i class="fas fa-trash"></i></button>
                                    </div>
                                <?php else: ?>
                                    <div class="material-photo placeholder" aria-hidden="true">No photo</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="material-actions">
                            <?php if ($currentStatus === 'needed' || $currentStatus === ''): ?>
                                <a class="btn small find-donations-btn" href="browse.php?query=<?= urlencode($m['material_name'] ?? $m['name'] ?? '') ?>&from_project=<?= $project_id ?>" title="Find donations for this material">Find Donations</a>

                                <form method="POST" class="inline-form" data-obtain-modal="1" style="display:inline-flex !important;align-items:center;margin:0;padding:0;">
                                    <input type="hidden" name="material_id" value="<?= $mid ?>">
                                    <input type="hidden" name="status" value="obtained">
                                    <button type="submit" name="update_material_status" class="btn small obtain-btn" title="Mark obtained" aria-label="Mark material obtained" style="display:inline-block !important;visibility:visible !important;"><i class="fas fa-check" aria-hidden="true"></i> Check</button>
                                </form>

                                <form method="POST" class="inline-form" data-confirm="Are you sure you want to remove this material?">
                                    <input type="hidden" name="material_id" value="<?= $mid ?>">
                                    <button type="submit" name="remove_material" class="btn small danger" title="Delete"><i class="fas fa-trash" aria-hidden="true"></i></button>
                                </form>
                            <?php else: ?>
                                <!-- Obtained: actions removed; upload rendered in .material-photos -->
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
</div>
