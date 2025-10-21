<?php
session_start();
require_once 'config.php';

// Check login status
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get user data from database
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects | EcoWaste</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/projects.css">

</head>
<body>
    <header>
        <div class="logo-container">
            <div class="logo">
                <img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo">
            </div>
            <h1>EcoWaste</h1>
        </div>
        <div class="user-profile" id="userProfile">
        <div class="profile-pic">
        <?= strtoupper(substr(htmlspecialchars($_SESSION['first_name'] ?? 'User'), 0, 1)) ?>
    </div>
    <span class="profile-name"><?= htmlspecialchars($_SESSION['first_name'] ?? 'User') ?></span>
    <i class="fas fa-chevron-down dropdown-arrow"></i>
    <div class="profile-dropdown">
        <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
        <a href="#" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
        <div class="dropdown-divider"></div>
        <a href="logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>
    </header>
    
    <div class="container">
        <aside class="sidebar">
            <nav>
                <ul>
                    <li><a href="homepage.php"><i class="fas fa-home"></i>Home</a></li>
                    <li><a href="browse.php"><i class="fas fa-search"></i>Browse</a></li>
                    <li><a href="achievements.php"><i class="fas fa-star"></i>Achievements</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                    <li><a href="projects.php" class="active"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-hand-holding-heart"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <!-- Projects List View -->
            <div id="projectsListView">
                <div class="page-header">
                    <h2 class="page-title">My Recycling Projects</h2>
                    <a href="start_project.php" class="start-recycling-btn">Start Recycling</a>
                </div>
                
                <div class="projects-filter">
                    <div class="filter-tab active" data-filter="all">All Projects</div>
                    <div class="filter-tab" data-filter="in-progress">In Progress</div>
                    <div class="filter-tab" data-filter="completed">Completed</div>
                </div>
                
                <div class="projects-container">
                <?php
                try {
                    // Get projects for the current user
                    $projects_query = $conn->prepare("
                        SELECT p.*, 
                               COUNT(pm.material_name) as material_count,
                               GROUP_CONCAT(pm.material_name) as materials
                        FROM projects p
                        LEFT JOIN project_materials pm ON p.project_id = pm.project_id
                        WHERE p.user_id = ?
                        GROUP BY p.project_id
                        ORDER BY p.created_at DESC
                    ");
                    
                    $projects_query->bind_param("i", $_SESSION['user_id']);
                    $projects_query->execute();
                    $result = $projects_query->get_result();
                    $projects = [];
                    while ($row = $result->fetch_assoc()) {
                        $projects[] = $row;
                    }

                    if (empty($projects)) {
                        echo '<div class="empty-state">
                                <i class="fas fa-recycle"></i>
                                <p>No projects yet</p>
                            </div>';
                    } else {
                        foreach ($projects as $project) {
                            $materials = $project['materials'] ? explode(',', $project['materials']) : [];
                            ?>
                            <div class="project-card" data-status="in-progress" data-project-id="<?= $project['project_id'] ?>">
                                <div class="project-header">
                                    <h3><?= htmlspecialchars($project['project_name']) ?></h3>
                                    <span class="project-date">Created: <?= date('M j, Y', strtotime($project['created_at'])) ?></span>
                                </div>
                                <div class="project-description">
                                    <?= htmlspecialchars($project['description']) ?>
                                </div>
                                <div class="project-materials">
                                    <h4>Materials (<?= $project['material_count'] ?>):</h4>
                                    <ul>
                                        <?php 
                                        $displayed_materials = array_slice($materials, 0, 2);
                                        foreach ($displayed_materials as $material): ?>
                                            <li><?= htmlspecialchars($material) ?></li>
                                        <?php endforeach; ?>
                                        <?php if (count($materials) > 2): ?>
                                            <li class="more-materials">+<?= count($materials) - 2 ?> more</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <div class="project-actions">
                                    <button class="action-btn view-details" onclick="viewProjectDetails(<?= $project['project_id'] ?>)">
                                        <i class="fas fa-eye"></i> View Details
                                    </button>
                                </div>
                            </div>
                            <?php
                        }
                    }
                } catch (PDOException $e) {
                    echo '<div class="error-message">Error loading projects: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
                ?>
            </div>
            </div>

            <!-- Project Details View -->
            <div id="projectDetailsView">
                <div class="back-button-container">
                    <button class="back-button" onclick="showProjectsList()">
                        <i class="fas fa-arrow-left"></i> Back to Projects
                    </button>
                </div>
                <div class="project-details-content">
                    <div class="project-details-header">
                        <div class="title-section">
                            <div class="editable-title">
                                <h2 id="projectDetailTitle"></h2>
                                <button class="edit-btn" onclick="toggleEditTitle()">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                            <div class="edit-title-form">
                                <input type="text" id="editTitleInput" class="edit-input">
                                <div class="edit-actions">
                                    <button class="save-btn" onclick="saveProjectTitle()">Save</button>
                                    <button class="cancel-btn" onclick="cancelEditTitle()">Cancel</button>
                                </div>
                            </div>
                            <span id="projectDetailDate"></span>
                        </div>
                    </div>
                    <div class="project-details-section">
                        <div class="section-header">
                            <h3>Description</h3>
                            <button class="edit-btn" onclick="toggleEditDescription()">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                        <div class="editable-description">
                            <div id="projectDetailDescription"></div>
                            <div class="edit-description-form">
                                <textarea id="editDescriptionInput" class="edit-input"></textarea>
                                <div class="edit-actions">
                                    <button class="save-btn" onclick="saveProjectDescription()">Save</button>
                                    <button class="cancel-btn" onclick="cancelEditDescription()">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="project-status-section">
                        <div class="section-header">
                            <h3>Project Status</h3>
                            <div class="status-actions">
                                <button id="projectStatusBtn" class="status-btn" onclick="toggleProjectStatus()">
                                    <i class="fas fa-flag"></i> Mark as Complete
                                </button>
                            </div>
                        </div>
                        <div class="progress-tracker">
                            <div class="progress-step active">
                                <i class="fas fa-list"></i>
                                <span>Planning</span>
                            </div>
                            <div class="progress-step">
                                <i class="fas fa-box"></i>
                                <span>Materials Collection</span>
                            </div>
                            <div class="progress-step">
                                <i class="fas fa-tools"></i>
                                <span>In Progress</span>
                            </div>
                            <div class="progress-step">
                                <i class="fas fa-check-circle"></i>
                                <span>Completed</span>
                            </div>
                        </div>
                    </div>

                    <div class="project-details-materials">
                        <div class="section-header">
                            <h3>Materials</h3>
                            <button class="add-material-btn" onclick="showAddMaterialForm()">
                                <i class="fas fa-plus"></i> Add Material
                            </button>
                        </div>
                        <!-- Add Material Form -->
                        <div id="addMaterialForm" class="add-material-form">
                            <div class="form-row">
                                <input type="text" id="newMaterialName" class="edit-input" placeholder="Material name">
                                <input type="text" id="newMaterialUnit" class="edit-input" placeholder="Unit (e.g., pcs, kg)">
                            </div>
                            <div class="edit-actions">
                                <button class="save-btn" onclick="addNewMaterial()">Add</button>
                                <button class="cancel-btn" onclick="hideAddMaterialForm()">Cancel</button>
                            </div>
                        </div>
                        <div id="projectDetailMaterials" class="materials-list"></div>
                    </div>

                    <div class="project-steps-section">
                        <div class="section-header">
                            <h3>Project Steps</h3>
                            <button class="add-step-btn" onclick="showAddStepForm()">
                                <i class="fas fa-plus"></i> Add Step
                            </button>
                        </div>
                        <div id="projectSteps" class="steps-list"></div>
                        
                        <!-- Add Step Form -->
                        <div id="addStepForm" class="add-step-form" style="display: none;">
                            <div class="form-group">
                                <label>Step Title</label>
                                <input type="text" id="stepTitle" class="edit-input" placeholder="e.g., Prepare materials">
                            </div>
                            <div class="form-group">
                                <label>Instructions</label>
                                <textarea id="stepInstructions" class="edit-input" placeholder="Describe the step in detail..."></textarea>
                            </div>
                            <div class="form-group">
                                <label>Add Photos (optional)</label>
                                <input type="file" id="stepPhotos" multiple accept="image/*" class="file-input">
                                <div class="photo-preview"></div>
                            </div>
                            <div class="edit-actions">
                                <button class="save-btn" onclick="saveProjectStep()">Add Step</button>
                                <button class="cancel-btn" onclick="hideAddStepForm()">Cancel</button>
                            </div>
                        </div>
                    </div>

                    <!-- Share Project Modal -->
                    <div id="shareProjectModal" class="modal" style="display: none;">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h3>Share Your Project</h3>
                                <button class="close-btn" onclick="closeShareModal()">&times;</button>
                            </div>
                            <div class="modal-body">
                                <div class="share-options">
                                    <button class="share-btn facebook">
                                        <i class="fab fa-facebook"></i> Share on Facebook
                                    </button>
                                    <button class="share-btn twitter">
                                        <i class="fab fa-twitter"></i> Share on Twitter
                                    </button>
                                    <button class="share-btn copy-link">
                                        <i class="fas fa-link"></i> Copy Link
                                    </button>
                                </div>
                                <div class="share-preview">
                                    <h4>Project Preview</h4>
                                    <div class="preview-content">
                                        <!-- Preview content will be populated dynamically -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Feedback Button -->
    <div class="feedback-btn" id="feedbackBtn">💬</div>

    <!-- Feedback Modal -->
    <div class="feedback-modal" id="feedbackModal">
        <div class="feedback-content">
            <span class="feedback-close-btn" id="feedbackCloseBtn">&times;</span>
            <div class="feedback-form" id="feedbackForm">
                <h3>Share Your Feedback</h3>
                
                <div class="emoji-rating" id="emojiRating">
                    <div class="emoji-option" data-rating="1">
                        <span class="emoji">😞</span>
                        <span class="emoji-label">Very Sad</span>
                    </div>
                    <div class="emoji-option" data-rating="2">
                        <span class="emoji">😕</span>
                        <span class="emoji-label">Sad</span>
                    </div>
                    <div class="emoji-option" data-rating="3">
                        <span class="emoji">😐</span>
                        <span class="emoji-label">Neutral</span>
                    </div>
                    <div class="emoji-option" data-rating="4">
                        <span class="emoji">🙂</span>
                        <span class="emoji-label">Happy</span>
                    </div>
                    <div class="emoji-option" data-rating="5">
                        <span class="emoji">😍</span>
                        <span class="emoji-label">Very Happy</span>
                    </div>
                </div>
                <div class="error-message" id="ratingError">Please select a rating</div>
                
                <p class="feedback-detail">Please share in detail what we can improve more?</p>
                
                <textarea id="feedbackText" placeholder="Your feedback helps us make EcoWaste better..."></textarea>
                <div class="error-message" id="textError">Please provide your feedback</div>
                
                <button type="submit" class="feedback-submit-btn" id="feedbackSubmitBtn">
                    Submit Feedback
                    <div class="spinner" id="spinner"></div>
                </button>
            </div>
            
            <div class="thank-you-message" id="thankYouMessage">
                <span class="thank-you-emoji">🎉</span>
                <h3>Thank You!</h3>
                <p>We appreciate your feedback and will use it to improve EcoWaste.</p>
                <p>Your opinion matters to us!</p>
            </div>
        </div>
    </div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    let currentProjectId = null;
    let currentProjectStatus = 'planning';

    // ---------------- PROFILE DROPDOWN ----------------
    const userProfile = document.getElementById('userProfile');
    const profileDropdown = document.querySelector('.profile-dropdown');

    if (userProfile && profileDropdown) {
        userProfile.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.style.display =
                profileDropdown.style.display === 'block' ? 'none' : 'block';
        });

        document.addEventListener('click', function(event) {
            if (!userProfile.contains(event.target)) {
                profileDropdown.style.display = 'none';
            }
        });
    }

    // ---------------- VIEW PROJECT DETAILS ----------------
    window.viewProjectDetails = function (projectId) {
        window.location.href = `project_details.php?id=${projectId}`;
    };

    // ---------------- MATERIALS ----------------
    window.updateMaterialsList = function (materials) {
        const materialsContainer = document.getElementById('projectDetailMaterials');
        materialsContainer.innerHTML = '';

        if (materials && materials.length > 0) {
            materials.forEach(material => {
                const materialDiv = document.createElement('div');
                materialDiv.className = 'material-item';
                materialDiv.innerHTML = `
                    <div class="material-content">
                        <span class="material-name">${material.name}</span>
                        <span class="material-unit">${material.unit || ''}</span>
                        ${material.is_found ? '<i class="fas fa-check-circle material-check"></i>' : ''}
                    </div>
                    <div class="material-actions">
                        ${!material.is_found ? `
                            <button class="check-btn" onclick="markMaterialFound(${material.id})" title="Mark as obtained">
                                <i class="fas fa-check"></i>
                            </button>
                        ` : ''}
                        <button class="delete-btn" onclick="deleteMaterial(${material.id})" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>`;
                materialsContainer.appendChild(materialDiv);
            });
        } else {
            materialsContainer.innerHTML = '<p class="no-materials">No materials added yet.</p>';
        }
    };

    window.showAddMaterialForm = function () {
        document.getElementById('addMaterialForm').style.display = 'block';
    };

    window.hideAddMaterialForm = function () {
        const form = document.getElementById('addMaterialForm');
        form.style.display = 'none';
        form.querySelector('#newMaterialName').value = '';
        form.querySelector('#newMaterialUnit').value = '';
    };

    window.addNewMaterial = function () {
        const name = document.getElementById('newMaterialName').value.trim();
        const unit = document.getElementById('newMaterialUnit').value.trim();

        if (!name || !unit) {
            alert('Please fill in all material fields');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'add_material');
        formData.append('project_id', currentProjectId);
        formData.append('name', name);
        formData.append('unit', unit);

        fetch('update_project.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                hideAddMaterialForm();
                viewProjectDetails(currentProjectId);
            } else {
                alert('Error adding material: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error adding material');
        });
    };

    window.deleteMaterial = function (materialId) {
        if (!confirm('Are you sure you want to delete this material?')) return;

        const formData = new FormData();
        formData.append('action', 'delete_material');
        formData.append('project_id', currentProjectId);
        formData.append('material_id', materialId);

        fetch('update_project.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                viewProjectDetails(currentProjectId);
            } else {
                alert('Error deleting material: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error deleting material');
        });
    };

    window.markMaterialFound = function (materialId) {
        const formData = new FormData();
        formData.append('action', 'mark_material_found');
        formData.append('project_id', currentProjectId);
        formData.append('material_id', materialId);

        fetch('update_project.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('Error updating material status: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error updating material status');
        });
    };

    // ---------------- PROJECT STATUS ----------------
    window.toggleProjectStatus = function () {
        const btn = document.getElementById('projectStatusBtn');
        const currentStatus = btn.getAttribute('data-status');
        let newStatus;

        switch (currentStatus) {
            case 'planning': newStatus = 'collecting'; break;
            case 'collecting': newStatus = 'in_progress'; break;
            case 'in_progress': newStatus = 'completed'; break;
            default: newStatus = 'planning';
        }

        if (!currentProjectId) {
            alert('Error: No project selected');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_project_status');
        formData.append('project_id', currentProjectId);
        formData.append('status', newStatus);

        fetch('update_project.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                updateProjectStatus(newStatus);
                if (newStatus === 'completed') {
                    showShareModal();
                }
            } else {
                alert('Error updating project status');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error updating project status');
        });
    };

    function updateProjectStatus(status) {
        const steps = document.querySelectorAll('.progress-step');
        const statusMap = { 'planning': 0, 'collecting': 1, 'in_progress': 2, 'completed': 3 };

        steps.forEach((step, i) => {
            if (i <= statusMap[status]) step.classList.add('active');
            else step.classList.remove('active');
        });

        const btn = document.getElementById('projectStatusBtn');
        btn.setAttribute('data-status', status);
        btn.innerHTML = status === 'completed' 
            ? '<i class="fas fa-check-circle"></i> Completed'
            : '<i class="fas fa-flag"></i> ' + getNextStatusLabel(status);
    }

    function getNextStatusLabel(status) {
        switch (status) {
            case 'planning': return 'Start Collecting';
            case 'collecting': return 'Start Project';
            case 'in_progress': return 'Mark as Complete';
            default: return 'Mark as Complete';
        }
    }

    // ---------------- SHARE MODAL ----------------
    window.showShareModal = function () {
        const modal = document.getElementById('shareProjectModal');
        modal.style.display = 'flex';
        generateShareLink();
    };

    window.closeShareModal = function () {
        document.getElementById('shareProjectModal').style.display = 'none';
    };

    function generateShareLink() {
        const formData = new FormData();
        formData.append('action', 'generate_share_link');
        formData.append('project_id', currentProjectId);

        fetch('update_project.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.querySelector('.copy-link').setAttribute('data-url', data.share_url);
            }
        });
    }

    window.shareOnFacebook = function () {
        const url = document.querySelector('.copy-link').getAttribute('data-url');
        window.open(`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`, 'facebook-share', 'width=580,height=296');
    };

    window.shareOnTwitter = function () {
        const url = document.querySelector('.copy-link').getAttribute('data-url');
        const text = 'Check out my recycling project on EcoWaste!';
        window.open(`https://twitter.com/intent/tweet?text=${encodeURIComponent(text)}&url=${encodeURIComponent(url)}`, 'twitter-share', 'width=550,height=235');
    };

    window.copyShareLink = function () {
        const url = document.querySelector('.copy-link').getAttribute('data-url');
        navigator.clipboard.writeText(url).then(() => alert('Link copied to clipboard!'));
    };

    // ---------------- FILTER TABS ----------------
    const filterTabs = document.querySelectorAll('.filter-tab');
    filterTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            filterTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            const filter = this.getAttribute('data-filter');
            const projectCards = document.querySelectorAll('.project-card');

            projectCards.forEach(card => {
                card.style.display = (filter === 'all' || card.getAttribute('data-status') === filter)
                    ? 'block' : 'none';
            });
        });
    });

    // ---------------- FEEDBACK MODAL ----------------
    const feedbackBtn = document.getElementById("feedbackBtn");
    const feedbackModal = document.getElementById("feedbackModal");
    const feedbackCloseBtn = document.getElementById("feedbackCloseBtn");
    const emojiOptions = document.querySelectorAll(".emoji-option");
    const feedbackSubmitBtn = document.getElementById("feedbackSubmitBtn");
    const feedbackText = document.getElementById("feedbackText");
    const ratingError = document.getElementById("ratingError");
    const textError = document.getElementById("textError");
    const thankYouMessage = document.getElementById("thankYouMessage");
    const feedbackForm = document.getElementById("feedbackForm");
    const spinner = document.getElementById("spinner");

    let selectedRating = 0;

    if (feedbackBtn && feedbackModal) {
        feedbackBtn.addEventListener("click", () => {
            feedbackModal.style.display = "flex";
            feedbackForm.style.display = "block";
            thankYouMessage.style.display = "none";
        });

        feedbackCloseBtn.addEventListener("click", () => {
            feedbackModal.style.display = "none";
        });

        window.addEventListener("click", (e) => {
            if (e.target === feedbackModal) {
                feedbackModal.style.display = "none";
            }
        });

        emojiOptions.forEach(option => {
            option.addEventListener("click", () => {
                emojiOptions.forEach(o => o.classList.remove("selected"));
                option.classList.add("selected");
                selectedRating = option.getAttribute("data-rating");
                ratingError.style.display = "none";
            });
        });

        feedbackSubmitBtn.addEventListener("click", function (e) {
            e.preventDefault();

            let valid = true;
            if (selectedRating === 0) {
                ratingError.style.display = "block";
                valid = false;
            }
            if (feedbackText.value.trim() === "") {
                textError.style.display = "block";
                valid = false;
            } else {
                textError.style.display = "none";
            }
            if (!valid) return;

            spinner.style.display = "inline-block";
            feedbackSubmitBtn.disabled = true;

            fetch("feedback_process.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                credentials: "same-origin",
                body: `rating=${selectedRating}&feedback=${encodeURIComponent(feedbackText.value)}`
            })
            .then(res => res.json())
            .then(data => {
                spinner.style.display = "none";
                feedbackSubmitBtn.disabled = false;

                if (data.status === "success") {
                    feedbackForm.style.display = "none";
                    thankYouMessage.style.display = "block";

                    setTimeout(() => {
                        feedbackModal.style.display = "none";
                        feedbackForm.style.display = "block";
                        thankYouMessage.style.display = "none";
                        feedbackText.value = "";
                        selectedRating = 0;
                        emojiOptions.forEach(o => o.classList.remove("selected"));
                    }, 3000);
                } else {
                    alert(data.message || "Failed to submit feedback.");
                }
            })
            .catch(err => {
                spinner.style.display = "none";
                feedbackSubmitBtn.disabled = false;
                alert("Failed to submit feedback. Please try again.");
                console.error(err);
            });
        });
    }
});
</script>

</body>
</html>