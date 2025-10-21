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
    // If the button is disabled (or aria-disabled), allow the click only when dataset indicates unmet requirements
    // so we can re-run a runtime verification. Otherwise bail out to avoid duplicate submissions.
    if (haveBtn) {
        const ariaDisabled = btn.getAttribute && btn.getAttribute('aria-disabled') === 'true';
        if (btn.disabled || ariaDisabled) {
            // If reqOk === '0' we want to re-run runtime check; otherwise bail unless the button text indicates it's "Completed" (user may want to toggle/uncomplete)
            if (!(btn.dataset && btn.dataset.reqOk === '0')) {
                const txt = (btn.textContent || '').toLowerCase();
                if (txt.indexOf('completed') === -1 && txt.indexOf('completed!') === -1) {
                    return;
                }
                // otherwise allow to continue (attempt toggle/uncomplete)
            }
        }
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
            } catch (e) { /* isStageSatisfied failed (silenced) */ return false; }
        }

        // If dataset indicates unmet requirements, re-run the runtime check before showing modal
    if (btn && btn.dataset && btn.dataset.reqOk === '0') {
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
                    try { if (btn) btn.dataset.reqOk = '1'; } catch(e){}
                    try { if (btn) btn.removeAttribute('aria-disabled'); } catch(e){}
                    try { if (btn) btn.classList.remove('is-disabled'); } catch(e){}
                }
            } catch (e) { /* runtime stage check failed (silenced) */ }
        }

        if (btn) btn.disabled = true;
    if (haveBtn) try { if (btn) btn.dataset.processing = '1'; } catch(e){}
    if (haveBtn) try { if (btn) btn.setAttribute('aria-busy','true'); } catch(e){}
        if (btn) btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        const response = await fetch('complete_stage.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `stage_number=${stageNumber}&project_id=${projectId}`
        });

    const data = await response.json().catch(()=>null);
    // debug: server response (silenced in production)
    const toast = (typeof window.showToast === 'function') ? window.showToast : (msg => alert(msg));

        if (data && data.success) {
                if (data.action === 'completed') {
                    if (btn) {
                        try {
                            const stageEl = btn.closest('.workflow-stage');
                            if (stageEl) {
                                stageEl.classList.remove('current');
                                stageEl.classList.add('completed');
                                const idx = parseInt(stageEl.getAttribute('data-stage-index'), 10);
                                const tab = document.querySelector('.stage-tab[data-stage-index="' + idx + '"]');
                                if (tab) { tab.classList.remove('active'); tab.classList.add('completed'); }

                                // move to next stage
                                let next = stageEl.nextElementSibling;
                                while (next && !next.classList.contains('workflow-stage')) next = next.nextElementSibling;
                                if (next) {
                                    next.classList.remove('locked');
                                    next.classList.add('current');
                                    const nextIdx = parseInt(next.getAttribute('data-stage-index'), 10);
                                    if (typeof showStageByIndex === 'function') showStageByIndex(nextIdx);
                                }
                            }
                        } catch (e) { /* non-fatal */ }
                    }
                    if (typeof renderStageStatusLabel === 'function') {
                        try { renderStageStatusLabel(stageNumber, 'completed'); } catch(e) { 
                            if (btn) {
                                btn.innerHTML = '<i class="fas fa-check-circle"></i> Completed';
                                btn.style.background = '#dff3e6';
                                btn.style.color = '#2f7a3a';
                                btn.classList.add('completed');
                            }
                        }
                    } else {
                        if (btn) {
                            btn.innerHTML = '<i class="fas fa-check-circle"></i> Completed';
                            btn.style.background = '#dff3e6';
                            btn.style.color = '#2f7a3a';
                            btn.classList.add('completed');
                        }
                    }
                    toast('Stage completed successfully', 'success');
                } else if (data.action === 'uncompleted') {
                    // Update UI: remove completed class and ensure this becomes the current stage
                    try {
                        if (typeof markStageUncompletedUI === 'function') {
                            try { markStageUncompletedUI(stageNumber); }
                            catch(e) { if (btn) btn.innerHTML = '<i class="fas fa-undo"></i> Mark as Complete'; }
                        } else {
                            if (btn) btn.innerHTML = '<i class="fas fa-undo"></i> Mark as Complete';
                        }
                        // Find the stage element by its data-stage-number or by stageNumber param
                        let stageEl = null;
                        try { stageEl = document.querySelector('.workflow-stage[data-stage-number="' + stageNumber + '"]'); } catch(e) { stageEl = null; }
                        if (!stageEl) {
                            try { stageEl = document.querySelector('.workflow-stage[data-stage-index="' + stageNumber + '"]'); } catch(e) { stageEl = null; }
                        }
                        if (!stageEl && btn) {
                            stageEl = btn.closest('.workflow-stage, .stage-card');
                        }
                        if (stageEl) {
                            stageEl.classList.remove('completed');
                            stageEl.classList.remove('current');
                            stageEl.classList.add('current');
                            // Update corresponding tab if present
                            const idx = stageEl.getAttribute('data-stage-number') || stageEl.getAttribute('data-stage-index');
                            if (idx) {
                                const tab = document.querySelector('.stage-tab[data-stage-number="' + idx + '"]') || document.querySelector('.stage-tab[data-stage-index="' + idx + '"]');
                                if (tab) { tab.classList.remove('completed'); tab.classList.add('active'); }
                            }
                        }
                        // remove explicit uncomplete button if present
                        try { const u = stageEl ? stageEl.querySelector('.uncomplete-stage-btn') : null; if (u) u.remove(); } catch(e){}
                    } catch(e) { /* uncomplete UI update failed (silenced) */ }
                    toast('Stage marked as incomplete', 'success');
                } else {
                    toast('Operation successful', 'success');
                }
        } else {
            // If server explicitly explains completion failed due to missing materials/photos,
            // treat the stage as incomplete in the UI (user just added material so it should be Incomplete)
            if (data && (data.reason === 'missing_materials' || data.reason === 'missing_after_photos' || data.reason === 'missing_stage_photos')) {
                try {
                    if (typeof renderStageStatusLabel === 'function') renderStageStatusLabel(stageNumber, 'incomplete');
                    else if (typeof markStageUncompletedUI === 'function') markStageUncompletedUI(stageNumber);
                    else if (btn) { btn.innerHTML = '<i class="fas fa-undo"></i> Mark as Complete'; }
                } catch(e){ if (btn) btn.innerHTML = originalHtml; }
                try { toast((data && data.message) ? data.message : 'Stage marked as incomplete', 'info'); } catch(e){}
            } else {
                if (btn) btn.disabled = false;
                if (haveBtn) try { if (btn) btn.dataset.processing = '0'; } catch(e){}
                if (haveBtn) try { if (btn) btn.removeAttribute('aria-busy'); } catch(e){}
                if (btn) btn.innerHTML = originalHtml;
                if (data && data.reason === 'missing_stage_photos') {
                    if (typeof window.showStagePhotoModal === 'function') window.showStagePhotoModal(stageNumber, data.missing || null, projectId);
                    toast('Please upload required stage photos', 'error');
                } else {
                    toast((data && data.message) ? data.message : 'Could not complete stage', 'error');
                }
            }
        }
    } catch (error) {
        /* error during completeStage (silenced) */
        if (btn) btn.disabled = false;
        if (haveBtn) try { if (btn) btn.dataset.processing = '0'; } catch(e){}
        if (haveBtn) try { if (btn) btn.removeAttribute('aria-busy'); } catch(e){}
        if (btn) btn.innerHTML = originalHtml;
        (typeof window.showToast === 'function') ? window.showToast('Network error while completing stage', 'error') : alert('Network error while completing stage');
    }
}