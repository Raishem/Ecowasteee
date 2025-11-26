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
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/projects.css">
    <style>
        .profile-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background-color: white;
            border-radius: 8px;
                    
            background-color: #eee;
            margin: 8px 0;
        }

        .user-profile {
            position: relative;
            cursor: pointer;
        }

        .user-profile.active .dropdown-arrow {
            transform: rotate(180deg);
        }

        .filter-tab {
            cursor: pointer;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .filter-tab.active {
            background-color: #2e8b57;
            color: white;
        }

        .filter-tab:hover:not(.active) {
            background-color: #f0f7e8;
        }

        .view-details {
            background-color: #2e8b57;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .view-details:hover {
            background-color: #3cb371;
            transform: translateY(-1px);
        }
    </style>
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
                    <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
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
                                        <a href="project_details.php?id=<?php echo $project['project_id']; ?>" class="action-btn view-details" data-project-id="<?php echo $project['project_id']; ?>"><i class="fas fa-eye"></i> View Details</a>
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
                                <label for="stepTitle">Step Title</label>
                                <input type="text" id="stepTitle" class="edit-input" placeholder="e.g., Prepare materials">
                            </div>
                            <div class="form-group">
                                <label for="stepInstructions">Instructions</label>
                                <textarea id="stepInstructions" class="edit-input" placeholder="Describe the step in detail..."></textarea>
                            </div>
                            <div class="form-group">
                                <label for="stepPhotos">Add Photos (optional)</label>
                                <input type="file" id="stepPhotos" multiple accept="image/*" class="file-input">
                                <div class="photo-preview"></div>
                            </div>
                            <div class="edit-actions">
                                <button type="button" class="save-btn" onclick="saveProjectStep()">Add Step</button>
                                <button type="button" class="cancel-btn" onclick="hideAddStepForm()">Cancel</button>
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
    let currentProjectId = null;
    let currentProjectStatus = 'collecting';

        // User profile dropdown
        document.getElementById('userProfile').addEventListener('click', function() {
            this.classList.toggle('active');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const userProfile = document.getElementById('userProfile');
            if (!userProfile.contains(event.target)) {
                userProfile.classList.remove('active');
            }
        });

        // Function to handle viewing project details
        function viewProjectDetails(projectId) {
            currentProjectId = projectId;
            
            // Make an AJAX request to get project details
            fetch(`update_project.php?action=get_project_details&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update the project details section
                        document.getElementById('projectDetailTitle').textContent = data.project.project_name;
                        document.getElementById('projectDetailDate').textContent = 'Created: ' + new Date(data.project.created_at).toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric'
                        });
                        document.getElementById('projectDetailDescription').textContent = data.project.description;
                        
                        // Set the current status
                        currentProjectStatus = data.project.status || 'collecting';
                        const btn = document.getElementById('projectStatusBtn');
                        btn.setAttribute('data-status', currentProjectStatus);
                        updateProjectStatus(currentProjectStatus);
                        
                        // Update materials list
                        updateMaterialsList(data.materials);

                        // Update steps list
                        updateStepsList(data.steps || []);

                        // Switch views
                        document.getElementById('projectsListView').style.display = 'none';
                        document.getElementById('projectDetailsView').style.display = 'block';
                    } else {
                        alert('Error loading project details: ' + data.message);
                    }
                })
                        .catch(error => { btn.classList.remove('found'); btn.removeAttribute('disabled'); });
        }

        function updateMaterialsList(materials) {
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
                                <button class="check-btn" data-action="mark-found" data-material-id="${material.id}" title="Mark as obtained">
                                    <i class="fas fa-check"></i>
                                </button>
                            ` : ''}
                            <button class="delete-btn" data-action="delete-material" data-material-id="${material.id}" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>`;
                    materialsContainer.appendChild(materialDiv);
                });
                } else {
                    materialsContainer.innerHTML = '<p class="no-materials">No materials added yet.</p>';
                }
            }

        // Functions for managing materials
        function showAddMaterialForm() {
            document.getElementById('addMaterialForm').style.display = 'block';
        }

        function hideAddMaterialForm() {
            const form = document.getElementById('addMaterialForm');
            form.style.display = 'none';
            // Clear form inputs
            form.querySelector('#newMaterialName').value = '';
            form.querySelector('#newMaterialQuantity').value = '';
            form.querySelector('#newMaterialUnit').value = '';
        }

        function addNewMaterial() {
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

            fetch('update_project.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    hideAddMaterialForm();
                    // Refresh project details to show new material
                    viewProjectDetails(currentProjectId);
                } else {
                    alert('Error adding material: ' + data.message);
                }
            })
                .catch(error => { alert('Error adding material'); });
        }

        function deleteMaterial(materialId) {
            if (confirm('Are you sure you want to delete this material?')) {
                const formData = new FormData();
                formData.append('action', 'delete_material');
                formData.append('project_id', currentProjectId);
                formData.append('material_id', materialId);

                fetch('update_project.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Refresh project details to update materials list
                        viewProjectDetails(currentProjectId);
                    } else {
                        alert('Error deleting material: ' + data.message);
                    }
                })
                    .catch(error => { alert('Error deleting material'); });
            }
        }

        // Function to toggle title edit form
        function toggleEditTitle() {
            const titleElement = document.getElementById('projectDetailTitle').parentElement;
            const editForm = document.querySelector('.edit-title-form');
            const editInput = document.getElementById('editTitleInput');
            
            editInput.value = document.getElementById('projectDetailTitle').textContent;
            titleElement.style.display = 'none';
            editForm.style.display = 'block';
            editInput.focus();
        }

        // Function to cancel title editing
        function cancelEditTitle() {
            const titleElement = document.getElementById('projectDetailTitle').parentElement;
            const editForm = document.querySelector('.edit-title-form');
            
            titleElement.style.display = 'flex';
            editForm.style.display = 'none';
        }

        // Function to save project title
        function saveProjectTitle() {
            const newTitle = document.getElementById('editTitleInput').value.trim();
            if (!newTitle) {
                alert('Title cannot be empty');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_title');
            formData.append('project_id', currentProjectId);
            formData.append('title', newTitle);

            fetch('update_project.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('projectDetailTitle').textContent = newTitle;
                    cancelEditTitle();
                    // Update the project card in the list view
                    const projectCard = document.querySelector(`[data-project-id="${currentProjectId}"]`);
                    if (projectCard) {
                        projectCard.querySelector('h3').textContent = newTitle;
                    }
                } else {
                    alert('Error updating title: ' + data.message);
                }
            })
                    .catch(error => { alert('Error updating title'); });
        }

        // Function to toggle description edit form
        function toggleEditDescription() {
            const descElement = document.getElementById('projectDetailDescription');
            const editForm = document.querySelector('.edit-description-form');
            const editInput = document.getElementById('editDescriptionInput');
            
            editInput.value = descElement.textContent;
            descElement.style.display = 'none';
            editForm.style.display = 'block';
            editInput.focus();
        }

        // Function to cancel description editing
        function cancelEditDescription() {
            const descElement = document.getElementById('projectDetailDescription');
            const editForm = document.querySelector('.edit-description-form');
            
            descElement.style.display = 'block';
            editForm.style.display = 'none';
        }

        // Function to save project description
        function saveProjectDescription() {
            const newDescription = document.getElementById('editDescriptionInput').value.trim();
            if (!newDescription) {
                alert('Description cannot be empty');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_description');
            formData.append('project_id', currentProjectId);
            formData.append('description', newDescription);

            fetch('update_project.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('projectDetailDescription').textContent = newDescription;
                    cancelEditDescription();
                    // Update description in list view if visible
                    const projectCard = document.querySelector(`[data-project-id="${currentProjectId}"]`);
                    if (projectCard) {
                        projectCard.querySelector('.project-description').textContent = newDescription;
                    }
                } else {
                    alert('Error updating description: ' + data.message);
                }
            })
                .catch(error => { alert('Error updating description'); });
        }

        // Function to find material in browse section
        function findMaterial(materialName) {
            // Encode the material name for the URL
            const encodedMaterial = encodeURIComponent(materialName);
            // Redirect to browse page with search parameter
            window.location.href = `browse.php?search=${encodedMaterial}`;
        }

        // Function to mark material as found
        function markMaterialFound(materialId) {
            const formData = new FormData();
            formData.append('action', 'mark_material_found');
            formData.append('project_id', currentProjectId);
            formData.append('material_id', materialId);

            fetch('update_project.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    alert('Error updating material status: ' + data.message);
                    btn.classList.remove('found');
                    btn.removeAttribute('disabled');
                }
            })
                .catch(error => { btn.classList.remove('found'); btn.removeAttribute('disabled'); });
        }

        // Project Steps Functions
        function showAddStepForm() {
            document.getElementById('addStepForm').style.display = 'block';
        }

        function hideAddStepForm() {
            document.getElementById('addStepForm').style.display = 'none';
            document.getElementById('stepTitle').value = '';
            document.getElementById('stepInstructions').value = '';
            document.getElementById('stepPhotos').value = '';
            document.querySelector('.photo-preview').innerHTML = '';
        }

        function saveProjectStep() {
            const title = document.getElementById('stepTitle').value.trim();
            const instructions = document.getElementById('stepInstructions').value.trim();
            const photos = document.getElementById('stepPhotos').files;

            if (!title || !instructions) {
                alert('Please fill in all required fields');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'add_step');
            formData.append('project_id', currentProjectId);
            formData.append('title', title);
            formData.append('instructions', instructions);

            for (let i = 0; i < photos.length; i++) {
                formData.append('photos[]', photos[i]);
            }

            fetch('update_project.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    hideAddStepForm();
                    viewProjectDetails(currentProjectId);
                } else {
                    alert('Error adding step: ' + data.message);
                }
            })
            .catch(error => { alert('Error adding step'); });
        }

        function toggleProjectStatus() {
            const btn = document.getElementById('projectStatusBtn');
            const currentStatus = btn.getAttribute('data-status');
            let newStatus;

            switch (currentStatus) {
                case 'collecting':
                    newStatus = 'in_progress';
                    break;
                case 'in_progress':
                    newStatus = 'completed';
                    break;
                default:
                    newStatus = 'collecting';
            }

            if (!currentProjectId) {
                alert('Error: No project selected');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_project_status');
            formData.append('project_id', currentProjectId);
            formData.append('status', newStatus);

            fetch('update_project.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                    if (data.success) {
                    updateProjectStatus(newStatus);
                    if (newStatus === 'completed') {
                        // Do not auto-open the Share modal. Sharing is a manual action available
                        // from the Project Details page when the project is completed.
                        showToast('Project marked as completed — you can share it from the details page', 'success');
                    }
                } else {
                    alert('Error updating project status');
                }
            })
            .catch(error => { alert('Error updating project status'); });
        }

        function updateProjectStatus(status) {
            const steps = document.querySelectorAll('.progress-step');
            const statusMap = {
                'collecting': 0,
                'in_progress': 1,
                'completed': 2
            };

            steps.forEach((step, index) => {
                if (index <= statusMap[status]) {
                    step.classList.add('active');
                } else {
                    step.classList.remove('active');
                }
            });

            const btn = document.getElementById('projectStatusBtn');
            btn.setAttribute('data-status', status);
            btn.innerHTML = status === 'completed' 
                ? '<i class="fas fa-check-circle"></i> Completed'
                : '<i class="fas fa-flag"></i> ' + getNextStatusLabel(status);
        }

        function getNextStatusLabel(currentStatus) {
            switch (currentStatus) {
                case 'collecting':
                    return 'Start Project';
                case 'in_progress':
                    return 'Mark as Complete';
                default:
                    return 'Mark as Complete';
            }
        }

        

        function copyShareLink() {
            const url = document.querySelector('.copy-link').getAttribute('data-url');
            navigator.clipboard.writeText(url).then(() => {
                alert('Link copied to clipboard!');
            });
        }

        // Function to update steps list
        function updateStepsList(steps) {
            const stepsContainer = document.getElementById('projectSteps');
            stepsContainer.innerHTML = '';

            if (steps && steps.length > 0) {
                steps.forEach(step => {
                    const stepDiv = document.createElement('div');
                    stepDiv.className = 'step-item';
                    stepDiv.innerHTML = `
                        <div class="step-header">
                            <h4>Step ${step.step_number}: ${step.title}</h4>
                        </div>
                        <div class="step-content">
                            <p>${step.instructions}</p>
                            ${step.photos ? `
                                <div class="step-photos">
                                    ${step.photos.split(',').map(photo => `
                                        <img src="assets/uploads/${photo}" alt="Step photo">
                                    `).join('')}
                                </div>
                            ` : ''}
                        </div>
                    `;
                    stepsContainer.appendChild(stepDiv);
                });
            } else {
                stepsContainer.innerHTML = '<p class="no-steps">No steps added yet.</p>';
            }
        }

        // Function to go back to project list
        function showProjectsList() {
            document.getElementById('projectsListView').style.display = 'block';
            document.getElementById('projectDetailsView').style.display = 'none';
        }

        // Project filters functionality
        const filterTabsInit = document.querySelectorAll('.filter-tab');
        filterTabsInit.forEach(tab => {
            tab.addEventListener('click', () => {
                // Update active tab
                filterTabsInit.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');

                const filter = tab.getAttribute('data-filter');
                const projectCards = document.querySelectorAll('.project-card');

                projectCards.forEach(card => {
                    if (filter === 'all' || card.getAttribute('data-status') === filter) {
                        card.style.display = 'block';
                        setTimeout(() => {
                            card.style.opacity = '1';
                            card.style.transform = 'translateY(0)';
                        }, 10);
                    } else {
                        card.style.opacity = '0';
                        card.style.transform = 'translateY(20px)';
                        setTimeout(() => {
                            card.style.display = 'none';
                        }, 300);
                    }
                });
            });
        });

        // Add some CSS styles for material items
        // All styles have been moved to projects.css
    </script>
    <script>
        // User Profile Dropdown
        const userProfile = document.getElementById('userProfile');
        const profileDropdown = document.querySelector('.profile-dropdown');
        
        userProfile.addEventListener('click', function(e) {
            e.stopPropagation();
            userProfile.classList.toggle('active');
            profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
        });
        
        document.addEventListener('click', function(event) {
            if (!userProfile.contains(event.target)) {
                userProfile.classList.remove('active');
                profileDropdown.style.display = 'none';
            }
        });

        // Filter Tabs Functionality
        const filterTabs = document.querySelectorAll('.filter-tab');
        filterTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs
                filterTabs.forEach(t => t.classList.remove('active'));
                // Add active class to clicked tab
                this.classList.add('active');
                
                const filter = this.getAttribute('data-filter');
                const projectCards = document.querySelectorAll('.project-card');
                
                projectCards.forEach(card => {
                    if (filter === 'all' || card.getAttribute('data-status') === filter) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });

        // Delegated handlers for view-details and material actions
        document.addEventListener('click', function (e) {
            const vd = e.target.closest('.view-details');
            if (vd) {
                const pid = vd.dataset.projectId;
                if (pid) window.location.href = `project_details.php?id=${pid}`;
                return;
            }

            const markBtn = e.target.closest('[data-action="mark-found"]');
            if (markBtn) {
                const mid = markBtn.dataset.materialId;
                if (!mid || !currentProjectId) return;
                markMaterialFound(mid);
                return;
            }

            const delBtn = e.target.closest('[data-action="delete-material"]');
            if (delBtn) {
                const mid = delBtn.dataset.materialId;
                if (!mid || !currentProjectId) return;
                if (confirm('Are you sure you want to delete this material?')) {
                    deleteMaterial(mid);
                }
                return;
            }
        });

        // showToast helper used across this page
        function showToast(message, type = 'info', timeout = 3500) {
            let container = document.getElementById('toastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toastContainer';
                container.style.position = 'fixed';
                container.style.right = '20px';
                container.style.top = '20px';
                container.style.zIndex = 9999;
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.style.marginBottom = '8px';
            toast.style.padding = '10px 14px';
            toast.style.borderRadius = '8px';
            toast.style.boxShadow = '0 2px 10px rgba(0,0,0,0.08)';
            toast.style.color = '#fff';
            toast.style.display = 'flex';
            toast.style.alignItems = 'center';
            toast.style.gap = '10px';

            const icon = document.createElement('span');
            icon.className = 'toast-icon';
            icon.innerHTML = type === 'success' ? '✅' : type === 'error' ? '⚠️' : 'ℹ️';

            const text = document.createElement('span');
            text.textContent = message;

            const close = document.createElement('button');
            close.textContent = '✕';
            close.style.marginLeft = '8px';
            close.style.background = 'transparent';
            close.style.border = 'none';
            close.style.color = 'inherit';
            close.style.cursor = 'pointer';

            toast.appendChild(icon);
            toast.appendChild(text);
            toast.appendChild(close);

            if (type === 'success') toast.style.background = '#2e8b57';
            else if (type === 'error') toast.style.background = '#d9534f';
            else toast.style.background = '#333';

            container.appendChild(toast);

            const removeToast = () => toast.remove();
            close.addEventListener('click', removeToast);
            setTimeout(removeToast, timeout);
        }
    </script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const userProfile = document.querySelector('.user-profile');
    const dropdownMenu = document.querySelector('.dropdown-menu');

    if (userProfile && dropdownMenu) {
        userProfile.addEventListener('click', function(e) {
            e.preventDefault();
            dropdownMenu.classList.toggle('show');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!userProfile.contains(e.target)) {
                dropdownMenu.classList.remove('show');
            }
        });
    }
});
</script>
</body>
</html>