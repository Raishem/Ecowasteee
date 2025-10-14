// Function to handle stage completion
async function completeStage(event, stageNumber, projectId) {
    const btn = event?.target?.closest('.complete-stage-btn') || event?.currentTarget;
    if (!btn || btn.disabled) return;
    
    try {
        btn.disabled = true;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        const response = await fetch('complete_stage.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `stage_number=${stageNumber}&project_id=${projectId}`
        });

        const data = await response.json();
        if (data.success) {
            if (data.action === 'completed') {
                btn.innerHTML = '<i class="fas fa-check"></i> Completed!';
                showToast('Stage completed successfully');
                setTimeout(() => window.location.reload(), 900);
            } else if (data.action === 'uncompleted') {
                btn.innerHTML = '<i class="fas fa-undo"></i> Mark as Complete';
                showToast('Stage marked as incomplete');
                setTimeout(() => window.location.reload(), 700);
            } else {
                // generic success
                showToast('Operation successful');
                setTimeout(() => window.location.reload(), 700);
            }
        } else {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            showToast(data.message || 'Could not complete stage', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        showToast('Network error while completing stage', 'error');
    }
}