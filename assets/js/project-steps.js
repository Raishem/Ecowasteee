// Toggle step completion status
function toggleStep(stepId, projectId) {
    const stepElement = document.querySelector(`[data-step-id="${stepId}"]`);
    const actionBtn = stepElement.querySelector('.action-btn');
    
    fetch('tools/add_step_is_done.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `step_id=${stepId}&project_id=${projectId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            stepElement.classList.toggle('completed');
            actionBtn.classList.toggle('completed');
            const icon = actionBtn.querySelector('i');
            if (stepElement.classList.contains('completed')) {
                icon.className = 'fas fa-check-circle';
                actionBtn.textContent = 'Completed';
                actionBtn.prepend(icon);
            } else {
                icon.className = 'fas fa-circle';
                actionBtn.textContent = 'Mark Complete';
                actionBtn.prepend(icon);
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// Image viewer functionality
function openImageViewer(src) {
    const overlay = document.createElement('div');
    overlay.className = 'image-viewer-overlay';
    
    const img = document.createElement('img');
    img.src = src;
    img.className = 'image-viewer-content';
    
    overlay.appendChild(img);
    document.body.appendChild(overlay);
    
    overlay.addEventListener('click', () => {
        document.body.removeChild(overlay);
    });
}

// Share mode functionality
function initializeShareMode() {
    const shareBtn = document.getElementById('shareBtn');
    const container = document.querySelector('.container');
    const docsSteps = document.querySelector('.documentation-steps');
    
    if (shareBtn) {
        shareBtn.addEventListener('click', () => {
            container.classList.add('share-mode');
            docsSteps.classList.add('active');
            
            // Scroll to documentation section
            docsSteps.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        });
    }
    
    // Add close button for share mode
    const closeBtn = document.createElement('button');
    closeBtn.className = 'close-share-mode';
    closeBtn.innerHTML = '<i class="fas fa-times"></i>';
    docsSteps.appendChild(closeBtn);
    
    closeBtn.addEventListener('click', () => {
        container.classList.remove('share-mode');
        docsSteps.classList.remove('active');
    });
}

// Initialize when document is loaded
document.addEventListener('DOMContentLoaded', () => {
    initializeShareMode();
});