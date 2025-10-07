<!-- Reusable Share Modal Include -->
<div id="sharedModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="sharedModalTitle" style="display:none;">
    <div class="modal-content" role="document">
        <div class="modal-header">
            <h3 id="sharedModalTitle">Share Project</h3>
            <button class="close-modal" id="sharedModalClose" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p id="sharedModalIntro">Publish a snapshot of this completed project to the community feed.</p>
            <label for="sharedModalPrivacy">Privacy</label>
            <select id="sharedModalPrivacy">
                <option value="public">Public</option>
                <option value="private">Private</option>
            </select>

            <div id="sharedModalSummary" style="margin-top:12px;display:none;">
                <h4>Summary</h4>
                <div id="sharedModalMaterials"></div>
                <div id="sharedModalSteps" style="margin-top:8px;"></div>
            </div>
        </div>
        <div class="modal-actions">
            <button class="action-btn" id="sharedModalCancel">Cancel</button>
            <button class="action-btn check-btn" id="sharedModalReview">Review</button>
            <button class="action-btn check-btn" id="sharedModalPublish" style="display:none;">Publish</button>
        </div>
    </div>
</div>

<script>
// Shared modal API: initSharedModal({projectId, materials, steps}), openSharedModal(), closeSharedModal()
window.sharedModalAPI = (function(){
    const modal = document.getElementById('sharedModal');
    const closeBtn = document.getElementById('sharedModalClose');
    const cancelBtn = document.getElementById('sharedModalCancel');
    const reviewBtn = document.getElementById('sharedModalReview');
    const publishBtn = document.getElementById('sharedModalPublish');
    const privacy = document.getElementById('sharedModalPrivacy');
    const summary = document.getElementById('sharedModalSummary');
    const matDiv = document.getElementById('sharedModalMaterials');
    const stepDiv = document.getElementById('sharedModalSteps');
    let ctx = { projectId: null, materials: [], steps: [] };

    function init(opts){
        ctx = Object.assign(ctx, opts || {});
        // populate summary lists
        matDiv.innerHTML = '<strong>Materials:</strong> ' + (ctx.materials.length ? '<ul>' + ctx.materials.map(m=>`<li>${escapeHtml(m.name||m)}</li>`).join('') + '</ul>' : '<div>None</div>');
        stepDiv.innerHTML = '<strong>Steps:</strong> ' + (ctx.steps.length ? '<ol>' + ctx.steps.map(s=>`<li>${escapeHtml(s.title||s)}</li>`).join('') + '</ol>' : '<div>None</div>');
    }

    function open(){
        if (!ctx.projectId) return console.warn('sharedModal: not initialized');
        // Only allow open if project is completed: caller must ensure
        // reset UI
        summary.style.display = 'none';
        reviewBtn.style.display = '';
        publishBtn.style.display = 'none';
        modal.style.display = 'flex';
        privacy.focus();
        document.body.style.overflow = 'hidden';
    }

    function close(){
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    function doPublish(){
        publishBtn.disabled = true;
        const fd = new FormData();
        fd.append('action', 'publish_shared_project');
        fd.append('project_id', ctx.projectId);
        fd.append('privacy', privacy.value);
        fetch('update_project.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            publishBtn.disabled = false;
            if (res.success) {
                showToast('Project shared', 'success');
                close();
                if (res.share_url) window.open(res.share_url, '_blank');
            } else showToast(res.message || 'Failed to share', 'error');
        }).catch(err => { publishBtn.disabled=false; showToast('Network error', 'error'); console.error(err); });
    }

    // events
    closeBtn.addEventListener('click', close);
    cancelBtn.addEventListener('click', close);
    reviewBtn.addEventListener('click', function(){ summary.style.display='block'; reviewBtn.style.display='none'; publishBtn.style.display=''; publishBtn.focus(); });
    publishBtn.addEventListener('click', doPublish);
    document.addEventListener('keydown', function(e){ if (e.key==='Escape' && modal.style.display==='flex') close(); });

    function escapeHtml(s){ return String(s).replace(/[&<>'"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    return { init: init, open: open, close: close };
})();
</script>
