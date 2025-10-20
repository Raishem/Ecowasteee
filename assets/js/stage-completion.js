// Function to handle stage completion
async function completeStage(event, stageNumber, projectId) {
    // Resolve the button from the event or by stageNumber (programmatic calls pass null event)
    let btn = null;
    try { btn = event?.target?.closest('.complete-stage-btn') || event?.currentTarget || null; } catch(e) { btn = null; }
    if (!btn) {
        btn = document.querySelector('.complete-stage-btn[data-stage-number="' + stageNumber + '"]');
    }
    // If button still not found, continue but note we won't be able to change its UI
    const haveBtn = !!btn;
    // If the button is disabled, allow the click to proceed only when the dataset indicates unmet requirements
    // (so we can re-run a runtime verification). Otherwise bail out to avoid duplicate submissions.
    if (haveBtn && btn.disabled) {
        if (!(btn.dataset && btn.dataset.reqOk === '0')) return;
        // otherwise allow flow to continue and runtime check to run below
    }

    // capture original HTML so we can restore on error (only if we have the button)
    let originalHtml = haveBtn ? btn.innerHTML : null;
        // prevent duplicate submissions
        if (haveBtn && btn.dataset && btn.dataset.processing === '1') return;

    try {
        // runtime check: re-evaluate stage requirements in case UI updates were missed
        async function isStageSatisfied(btnElement, stageNum) {
            try {
                // prefer the stage card closest to the button
                let stageCard = null;
                if (btnElement) stageCard = btnElement.closest('.stage-card, .workflow-stage');
                if (!stageCard) {
                    // find by data-stage-number attribute
                    const b = document.querySelector('.complete-stage-btn[data-stage-number="' + stageNum + '"]');
                    if (b) stageCard = b.closest('.stage-card, .workflow-stage');
                }
                if (!stageCard) return false;
                const materialsNode = stageCard.querySelector('.stage-materials');
                if (!materialsNode) return true; // nothing to satisfy
                const items = Array.from(materialsNode.querySelectorAll('.material-item'));
                if (items.length === 0) return true;
                // each item must have an obtained badge and a non-placeholder photo
                for (let li of items) {
                    if (!li.querySelector('.badge.obtained')) return false;
                    const photos = li.querySelector('.material-photos');
                    if (!(photos && photos.querySelector('.material-photo:not(.placeholder)'))) return false;
                }
                return true;
            } catch (e) { console.error('isStageSatisfied failed', e); return false; }
        }

        // If dataset indicates unmet requirements, re-run the runtime check before showing modal
        if (btn.dataset && btn.dataset.reqOk === '0') {
            try {
                const runtimeOk = await isStageSatisfied(btn, stageNumber);
                if (!runtimeOk) {
                    if (typeof window.showStagePhotoModal === 'function') {
                        window.showStagePhotoModal(stageNumber, null, projectId);
                    } else if (typeof window.showStagePhotoModalGlobal === 'function') {
                        window.showStagePhotoModalGlobal(stageNumber, null, projectId);
                    } else {
                        alert('Please upload required stage photos before completing this stage.');
                    }
                    return;
                } else {
                    // update dataset to indicate ok and continue
                    try { btn.dataset.reqOk = '1'; } catch(e){}
                    try { btn.removeAttribute('aria-disabled'); } catch(e){}
                    try { btn.classList.remove('is-disabled'); } catch(e){}
                }
            } catch (e) { console.error('runtime stage check failed', e); }
        }

        btn.disabled = true;
    if (haveBtn) try { btn.dataset.processing = '1'; } catch(e){}
    if (haveBtn) try { btn.setAttribute('aria-busy','true'); } catch(e){}
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        const response = await fetch('complete_stage.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `stage_number=${stageNumber}&project_id=${projectId}`
        });

    const data = await response.json().catch(()=>null);
    // DEBUG: log server response for troubleshooting
    try { console.debug('completeStage response', data); } catch(e){}
    const toast = (typeof window.showToast === 'function') ? window.showToast : (msg => alert(msg));

        if (data && data.success) {
            if (data.action === 'completed') {
                btn.innerHTML = '<i class="fas fa-check"></i> Completed!';
                toast('Stage completed successfully', 'success');
                // try to activate the next stage tab without full reload
                try {
                    // find current tab by data-stage-index or by matching stageNumber
                    const currentStageNum = String(stageNumber);
                    // find the stage tab element that corresponds to the current stage
                    let currentTab = document.querySelector('.stage-tab.active') || document.querySelector('.stage-tab[data-stage-index][data-stage-number="' + currentStageNum + '"]') || document.querySelector('.stage-tab[data-stage-index].active');
                    if (!currentTab) {
                        // attempt to find a tab whose data-stage-index equals the currentStageNum
                        currentTab = document.querySelector('.stage-tab[data-stage-index="' + currentStageNum + '"]');
                    }
                    if (currentTab) {
                        // find next sibling tab
                        let next = currentTab.nextElementSibling;
                        // if next is not a .stage-tab, search forward for the next .stage-tab
                        while (next && !next.classList.contains('stage-tab')) next = next.nextElementSibling;
                        if (next && next.classList.contains('stage-tab')) {
                            // trigger click to activate it (this will also scroll into view)
                            next.click();
                            return;
                        }
                    }
                } catch (e) { console.error('navigate to next stage failed', e); }
                // fallback: reload page to update UI
                setTimeout(() => window.location.reload(), 900);
            } else if (data.action === 'uncompleted') {
                btn.innerHTML = '<i class="fas fa-undo"></i> Mark as Complete';
                toast('Stage marked as incomplete', 'success');
                setTimeout(() => window.location.reload(), 700);
            } else {
                // generic success
                toast('Operation successful', 'success');
                setTimeout(() => window.location.reload(), 700);
            }
        } else {
            btn.disabled = false;
            if (haveBtn) try { btn.dataset.processing = '0'; } catch(e){}
            if (haveBtn) try { btn.removeAttribute('aria-busy'); } catch(e){}
            btn.innerHTML = originalHtml;
            if (data && data.reason === 'missing_stage_photos') {
                if (typeof window.showStagePhotoModal === 'function') window.showStagePhotoModal(stageNumber, data.missing || null, projectId);
                toast('Please upload required stage photos', 'error');
            } else {
                toast((data && data.message) ? data.message : 'Could not complete stage', 'error');
            }
        }
    } catch (error) {
        console.error('Error:', error);
        btn.disabled = false;
        if (haveBtn) try { btn.dataset.processing = '0'; } catch(e){}
        if (haveBtn) try { btn.removeAttribute('aria-busy'); } catch(e){}
        btn.innerHTML = originalHtml;
        (typeof window.showToast === 'function') ? window.showToast('Network error while completing stage', 'error') : alert('Network error while completing stage');
    }
}