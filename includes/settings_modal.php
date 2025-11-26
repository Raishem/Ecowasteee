<!-- Settings Modal -->
<div class="settings-modal" id="settingsModal">
    <div class="settings-modal-content">
        <div class="settings-modal-header">
            <h3 class="settings-modal-title">
                <i class="fas fa-cog me-2"></i>Settings
            </h3>
            <button class="settings-close-btn" id="settingsCloseBtn">&times;</button>
        </div>
        <div class="settings-modal-body">
            <div class="settings-tabs">
                <button class="settings-tab active" data-tab="passwordTab">Change Password</button>
                <button class="settings-tab" data-tab="profileTab">Profile</button>
            </div>

            <!-- Change Password Tab -->
            <div class="settings-tab-content active" id="passwordTab">
                <form method="POST" action="change_password.php" id="passwordForm">
                    <div class="settings-form-group">
                        <label class="settings-form-label" for="current_password">Current Password</label>
                        <div class="password-input-container">
                            <input type="password" class="settings-form-input" id="current_password" name="current_password" required>
                            <button type="button" class="password-toggle" data-target="current_password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="settings-form-group">
                        <label class="settings-form-label" for="new_password">New Password</label>
                        <div class="password-input-container">
                            <input type="password" class="settings-form-input" id="new_password" name="new_password" required>
                            <button type="button" class="password-toggle" data-target="new_password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="password-strength"></div>
                        <small style="color: #666; font-size: 12px;">
                            Password must be at least 6 characters long.
                        </small>
                    </div>
                    
                    <div class="settings-form-group">
                        <label class="settings-form-label" for="confirm_password">Confirm New Password</label>
                        <div class="password-input-container">
                            <input type="password" class="settings-form-input" id="confirm_password" name="confirm_password" required>
                            <button type="button" class="password-toggle" data-target="confirm_password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback" id="password-match-feedback">
                            Passwords do not match.
                        </div>
                    </div>
                    
                    <div class="settings-form-actions">
                        <button type="button" class="settings-btn settings-btn-secondary" id="settingsCancelBtn">Cancel</button>
                        <button type="submit" class="settings-btn settings-btn-primary">
                            Change Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- Profile Tab -->
            <div class="settings-tab-content" id="profileTab">
                <p style="color: #666; text-align: center; padding: 20px;">
                    Profile settings coming soon...
                </p>
            </div>
        </div>
    </div>
</div>

<script>
// Settings Modal Functionality
document.addEventListener('DOMContentLoaded', function() {
    const settingsModal = document.getElementById('settingsModal');
    const settingsCloseBtn = document.getElementById('settingsCloseBtn');
    const settingsCancelBtn = document.getElementById('settingsCancelBtn');
    const settingsTabs = document.querySelectorAll('.settings-tab');
    
    // Open modal when Settings is clicked
    const settingsLink = document.getElementById('settingsLink');
    if (settingsLink) {
        settingsLink.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (settingsModal) {
                settingsModal.style.display = 'block';
            }
        });
    }
    
    // Close modal functions
    function closeSettingsModal() {
        if (settingsModal) {
            settingsModal.style.display = 'none';
        }
    }
    
    if (settingsCloseBtn) {
        settingsCloseBtn.addEventListener('click', closeSettingsModal);
    }
    
    if (settingsCancelBtn) {
        settingsCancelBtn.addEventListener('click', closeSettingsModal);
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(e) {
        if (e.target === settingsModal) {
            closeSettingsModal();
        }
    });
    
    // Tab functionality
    settingsTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            settingsTabs.forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.settings-tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            this.classList.add('active');
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
        });
    });
    
    // Password strength indicator
    const newPasswordInput = document.getElementById('new_password');
    if (newPasswordInput) {
        newPasswordInput.addEventListener('input', function() {
            checkPasswordStrength(this.value);
        });
    }
    
    // Password confirmation validation
    const confirmPasswordInput = document.getElementById('confirm_password');
    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            const feedback = document.getElementById('password-match-feedback');
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.style.borderColor = '#dc3545';
                if (feedback) feedback.style.display = 'block';
            } else {
                this.style.borderColor = '#ddd';
                if (feedback) feedback.style.display = 'none';
            }
        });
    }
    
    // Password toggle functionality
    const passwordToggles = document.querySelectorAll('.password-toggle');
    passwordToggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const passwordInput = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (passwordInput && icon) {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.className = 'fas fa-eye-slash';
                    this.classList.add('active');
                } else {
                    passwordInput.type = 'password';
                    icon.className = 'fas fa-eye';
                    this.classList.remove('active');
                }
            }
        });
    });
});

function checkPasswordStrength(password) {
    const strengthBar = document.getElementById('password-strength');
    if (!strengthBar) return;
    
    let strength = 0;
    
    if (password.length >= 6) strength++;
    if (password.length >= 8) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^A-Za-z0-9]/.test(password)) strength++;
    
    strengthBar.className = 'password-strength';
    if (password.length === 0) {
        strengthBar.style.width = '0';
    } else if (strength <= 2) {
        strengthBar.className += ' strength-weak';
    } else if (strength <= 4) {
        strengthBar.className += ' strength-medium';
    } else {
        strengthBar.className += ' strength-strong';
    }
}
</script>