// Externalized materials handlers (safe, idempotent)
(function(){
    'use strict';
    try {
        function getProjectId(){
            if (window.ECW_DATA && window.ECW_DATA.projectId) return window.ECW_DATA.projectId;
            const mainEl = document.querySelector('main[data-project-id]');
            if (mainEl) return mainEl.dataset ? mainEl.dataset.projectId : mainEl.getAttribute('data-project-id');
            return (document.body && document.body.dataset && document.body.dataset.projectId) ? document.body.dataset.projectId : '';
        }

        async function processMaterialPhotoDelete(del){
            if (!del) return;
            if (del.dataset && del.dataset._processing === '1') return;
            try { if (del.dataset) del.dataset._processing = '1'; } catch(e){}
            try {
                const wrapper = del.closest && del.closest('.material-photo');
                if (!wrapper) return;
                const photoId = wrapper.dataset ? (wrapper.dataset.photoId || null) : wrapper.getAttribute('data-photo-id');
                if (!photoId) {
                    // client-only thumbnail
                    const parentMat = wrapper.closest && wrapper.closest('.material-item');
                    wrapper.remove();
                    try {
                        if (parentMat) {
                            const meta = parentMat.querySelector('.mat-meta');
                            const hasPhoto = parentMat.querySelector('.material-photos .material-photo:not(.placeholder)');
                            if (meta && !hasPhoto && !meta.querySelector('.upload-material-photo')) {
                                const btn = document.createElement('button');
                                btn.type = 'button'; btn.className = 'btn small upload-material-photo';
                                btn.setAttribute('data-material-id', parentMat.dataset ? parentMat.dataset.materialId || '' : '');
                                btn.title = 'Upload photo'; btn.setAttribute('aria-label', 'Upload material photo');
                                btn.innerHTML = '<i class="fas fa-camera"></i>';
                                meta.appendChild(btn);
                            }
                        }
                    } catch(e){}
                    try { document.dispatchEvent(new Event('materialPhotoChanged')); } catch(e){}
                    return;
                }

                if (!confirm('Remove this photo?')) return;
                const fd = new FormData(); fd.append('photo_id', photoId);
                let resTxt = null;
                try {
                    const res = await fetch('delete_material_photo.php', { method: 'POST', body: fd });
                    resTxt = await res.text();
                } catch(err) {
                    // try XHR fallback
                    try {
                        resTxt = await (function(url, formData){
                            return new Promise(function(resolve){
                                try{
                                    var xhr = new XMLHttpRequest(); xhr.open('POST', url, true);
                                    xhr.onreadystatechange = function(){ if (xhr.readyState !== 4) return; resolve(xhr.responseText || ''); };
                                    xhr.onerror = function(){ resolve(''); };
                                    xhr.send(formData);
                                } catch(e){ resolve(''); }
                            });
                        })('delete_material_photo.php', fd);
                    } catch(e){ resTxt = ''; }
                }

                let json = null;
                try { json = resTxt ? JSON.parse(resTxt) : null; } catch(e) { json = null; }
                if (json && json.success) {
                    try { wrapper.remove(); } catch(e){}
                    try { document.dispatchEvent(new Event('materialPhotoChanged')); } catch(e){}
                    try { if (window.showToast) showToast('Photo removed'); } catch(e){}
                } else {
                    try { alert((json && json.message) ? json.message : 'Delete failed'); } catch(e){}
                }
            } catch(e) { try { alert('Delete failed'); } catch(err){} }
            finally { try { if (del && del.dataset) delete del.dataset._processing; } catch(e){} }
        }

        // Only define if not already present to avoid clobbering
        try { if (typeof window.processMaterialPhotoDelete !== 'function') window.processMaterialPhotoDelete = processMaterialPhotoDelete; } catch(e){}

        // Delegated click handler for delete and upload buttons
        document.addEventListener('click', function(e){
            try {
                const del = e.target.closest && e.target.closest('.material-photo-delete');
                if (del) { try { processMaterialPhotoDelete(del); } catch(e){} return; }

                const up = e.target.closest && e.target.closest('.upload-material-photo');
                if (!up) return;
                const mid = up.dataset ? up.dataset.materialId : up.getAttribute('data-material-id');
                if (!mid) return;

                const input = document.createElement('input'); input.type = 'file'; input.accept = 'image/*';
                input.onchange = async function(ev){
                    const file = input.files && input.files[0]; if (!file) return;
                    const fd = new FormData(); fd.append('material_id', mid); fd.append('photo', file); fd.append('photo_type', 'before');
                    try {
                        const res = await fetch('upload_material_photo.php', { method: 'POST', body: fd });
                        const txt = await res.text();
                        let json = null; try { json = txt ? JSON.parse(txt) : null; } catch(e){ json = null; }
                        if (json && json.success) {
                            try {
                                let photos = document.querySelector('.material-photos[data-material-id="' + mid + '"]');
                                if (!photos) {
                                    const item = document.querySelector('.material-item[data-material-id="' + mid + '"]');
                                    if (item) {
                                        photos = document.createElement('div'); photos.className = 'material-photos'; photos.setAttribute('data-material-id', mid);
                                        const main = item.querySelector('.material-main'); if (main && main.parentNode) main.parentNode.insertBefore(photos, main.nextSibling); else item.appendChild(photos);
                                    }
                                }
                                if (photos) {
                                    const div = document.createElement('div'); div.className = 'material-photo'; div.setAttribute('data-photo-id', json.id || ''); div.setAttribute('data-photo-type', 'before');
                                    const src = (json.path && json.path.indexOf('assets/') === 0) ? json.path : ('assets/uploads/materials/' + (json.path || ''));
                                    div.innerHTML = '<img src="' + src + '" alt="Material photo"><button type="button" class="material-photo-delete" title="Delete photo"><i class="fas fa-trash"></i></button>';
                                    const img = div.querySelector('img'); if (img) img.addEventListener('click', function(){ try { if (typeof openImageViewer === 'function') openImageViewer(src); } catch(e){} });
                                    photos.insertBefore(div, photos.firstChild);
                                    try { const parentMat = photos.closest('.material-item'); if (parentMat) { const meta = parentMat.querySelector('.mat-meta'); const upBtn = meta && meta.querySelector('.upload-material-photo'); if (upBtn) upBtn.remove(); } } catch(e){}
                                }
                                try { document.dispatchEvent(new Event('materialPhotoChanged')); } catch(e){}
                            } catch(e){}
                        } else {
                            try { alert((json && json.message) ? json.message : 'Upload failed'); } catch(e){}
                        }
                    } catch(e) { try { alert('Upload failed'); } catch(err){} }
                };
                input.click();
            } catch(e){}
        }, false);

        // Minimal showAddMaterialModal if not present
        try {
            if (typeof window.showAddMaterialModal !== 'function') {
                window.showAddMaterialModal = function(){
                    try {
                        const name = prompt('Material name:'); if (!name) return;
                        let qty = prompt('Quantity (optional, leave blank for 1):', '1'); if (qty === null) return; qty = qty.trim() === '' ? '1' : qty.trim();
                        const pid = getProjectId();
                        const fd = new URLSearchParams(); fd.append('action','add_material'); fd.append('project_id', String(pid)); fd.append('material_name', name); fd.append('quantity', qty);
                        fetch('update_project.php', { method: 'POST', body: fd }).then(r=>r.text()).then(txt=>{
                            let j = null; try { j = txt ? JSON.parse(txt) : null; } catch(e){ j = null; }
                            if (j && j.success) {
                                    try { if (window.showToast) showToast('Material added', 'success'); } catch(e){}

                                    // Small helper: attempt to insert into DOM with retries. This covers
                                    // slow-to-render pages or cases where a Preparation stage needs
                                    // to be created first by ensurePreparationStageInDOM.
                                    const tryInsertWithRetries = function(mat, attemptsLeft, delayMs) {
                                        return new Promise(resolve => {
                                            try {
                                                // First try immediate insertion
                                                if (window.insertMaterialIntoDOM && insertMaterialIntoDOM(mat)) {
                                                    if (window.ensureThreeStageTabs) try { ensureThreeStageTabs(); } catch(e){}
                                                    return resolve(true);
                                                }

                                                // If we can create the Preparation stage, try that then re-attempt
                                                if (window.ensurePreparationStageInDOM) {
                                                    try { ensurePreparationStageInDOM(); } catch(_){}
                                                    if (window.insertMaterialIntoDOM && insertMaterialIntoDOM(mat)) {
                                                        if (window.ensureThreeStageTabs) try { ensureThreeStageTabs(); } catch(e){}
                                                        return resolve(true);
                                                    }
                                                }

                                                // If not successful and we still have attempts, schedule another try
                                                if (attemptsLeft > 0) {
                                                    setTimeout(function(){ resolve( tryInsertWithRetries(mat, attemptsLeft - 1, delayMs * 2) ); }, delayMs);
                                                } else {
                                                    resolve(false);
                                                }
                                            } catch(e) { resolve(false); }
                                        });
                                    };

                                    // Try up to 3 attempts: immediate, short and longer delay
                                    tryInsertWithRetries(j.material, 2, 120).then(success => {
                                        if (!success) {
                                            // Final fallback: ensure tabs exist (makes server-rendered reload more friendly)
                                            try { if (window.ensureThreeStageTabs) ensureThreeStageTabs(); } catch(e){}
                                            setTimeout(()=> location.reload(), 700);
                                        }
                                    }).catch(()=> setTimeout(()=> location.reload(), 700));
                            }
                            else { try { alert((j && j.message) ? j.message : 'Add failed'); } catch(e){} }
                        }).catch(()=>{ try{ alert('Add failed'); }catch(e){} });
                    } catch(e){}
                };
            }
        } catch(e){}

    } catch(e){}
})();
