<?php
session_start();
require_once 'config.php';

// Check login status
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get user data from database
try {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
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
    <link rel="stylesheet" href="assets/css/common-buttons.css">
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
                    <li><a href="projects.php" style="color: #2e8b57;"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
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
                // Fetch user's projects from database
                $stmt = $conn->prepare("
                    SELECT p.*, 
                           COUNT(DISTINCT m.material_id) as total_materials,
                           COUNT(DISTINCT CASE WHEN m.is_found = 1 THEN m.material_id END) as found_materials,
                           COUNT(DISTINCT ph.photo_id) as photo_count
                    FROM projects p 
                    LEFT JOIN project_materials m ON p.project_id = m.project_id
                    LEFT JOIN project_photos ph ON p.project_id = ph.project_id
                    WHERE p.user_id = ?
                    GROUP BY p.project_id
                    ORDER BY p.created_at DESC
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($projects)) {
                    foreach ($projects as $project) {
                        $progress = 0;
                        if (!empty($project['total_materials'])) {
                            $progress = round(($project['found_materials'] / $project['total_materials']) * 100);
                        }
                        
                        // Get the first photo if exists
                        $thumbnail = '';
                        try {
                            if ($project['photo_count'] > 0) {
                                $photoStmt = $conn->prepare("SELECT photo_url FROM project_photos WHERE project_id = ? LIMIT 1");
                                $photoStmt->execute([$project['project_id']]);
                                $photo = $photoStmt->fetch(PDO::FETCH_ASSOC);
                                if ($photo) {
                                    $thumbnail = $photo['photo_url'];
                                }
                            }
                        } catch (PDOException $e) {
                            error_log("Error fetching project photo: " . $e->getMessage());
                        }
                        ?>
                        <div class="project-card" data-project-id="<?= htmlspecialchars($project['project_id']) ?>" data-status="<?= htmlspecialchars($project['status']) ?>">
                            <div class="project-image">
                                <?php if ($thumbnail): ?>
                                    <img src="<?= htmlspecialchars($thumbnail) ?>" alt="Project thumbnail">
                                <?php else: ?>
                                    <div class="placeholder-image">
                                        <i class="fas fa-recycle"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="project-info">
                                <h3><?= htmlspecialchars($project['project_name']) ?></h3>
                                <p class="project-description"><?= htmlspecialchars($project['description']) ?></p>
                                <div class="project-stats">
                                    <div class="materials-progress">
                                        <span class="stat-label">Materials:</span>
                                        <span class="stat-value"><?= $project['found_materials'] ?>/<?= $project['total_materials'] ?></span>
                                        <div class="progress-bar">
                                            <div class="progress" style="width: <?= $progress ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="project-status <?= htmlspecialchars($project['status']) ?>">
                                        <i class="fas fa-circle"></i>
                                        <span><?= ucfirst(htmlspecialchars($project['status'])) ?></span>
                                    </div>
                                </div>
                                <div class="project-footer">
                                    <span class="project-date">Started: <?= date('M j, Y', strtotime($project['created_at'])) ?></span>
                                    <button class="view-details-btn" onclick="viewProjectDetails(<?= $project['project_id'] ?>)">
                                        View Details
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php
                        ?>
                        
                        // Get the first photo if exists
                        $thumbnail = '';
                        if ($project['photo_count'] > 0) {
                            try {
                                $photoStmt = $conn->prepare("SELECT photo_url FROM project_photos WHERE project_id = ? LIMIT 1");
                                $photoStmt->execute([$project['project_id']]);
                                $photo = $photoStmt->fetch(PDO::FETCH_ASSOC);
                                if ($photo) {
                                    $thumbnail = $photo['photo_url'];
                                }
                            } catch (PDOException $e) {
                                error_log("Error fetching photo: " . $e->getMessage());
                                $thumbnail = '';
                            }
                        }
                        ?>
                        <div class="project-card" data-project-id="<?= htmlspecialchars($project['project_id']) ?>" data-status="<?= htmlspecialchars($project['status']) ?>">
                            <div class="project-image">
                                <?php if ($thumbnail): ?>
                                    <img src="<?= htmlspecialchars($thumbnail) ?>" alt="Project thumbnail">
                                <?php else: ?>
                                    <div class="placeholder-image">
                                        <i class="fas fa-recycle"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="project-info">
                                <h3><?= htmlspecialchars($project['project_name']) ?></h3>
                                <p class="project-description"><?= htmlspecialchars($project['description']) ?></p>
                                <div class="project-stats">
                                    <div class="materials-progress">
                                        <span class="stat-label">Materials:</span>
                                        <span class="stat-value"><?= $project['found_materials'] ?>/<?= $project['total_materials'] ?></span>
                                        <div class="progress-bar">
                                            <div class="progress" style="width: <?= $progress ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="project-status <?= htmlspecialchars($project['status']) ?>">
                                        <i class="fas fa-circle"></i>
                                        <span><?= ucfirst(htmlspecialchars($project['status'])) ?></span>
                                    </div>
                                </div>
                                <div class="project-footer">
                                    <span class="project-date">Started: <?= date('M j, Y', strtotime($project['created_at'])) ?></span>
                                    <button class="view-details-btn" onclick="viewProjectDetails(<?= $project['project_id'] ?>)">
                                        View Details
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                } else {
                    ?>
                    <div class="empty-state">
                        <i class="fas fa-recycle"></i>
                        <p>No projects yet</p>
                    </div>
                    <?php
                }
            } catch (PDOException $e) {
                error_log("Error fetching projects: " . $e->getMessage());
                ?>
                <div class="error-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Error loading projects. Please try again later.</p>
                </div>
                <?php
            }
            ?>
            </div>
            
            <div class="action-buttons">
                <button class="action-btn">View Details</button>
                <button class="action-btn">Share</button>
            </div>

            <!-- Project Details View -->
            <div id="projectDetailsView" class="project-details-view" style="display: none;">
                <button class="back-btn" onclick="showProjectsList()">
                    <i class="fas fa-arrow-left"></i> Back to Projects
                </button>

                <div class="project-details-header">
                    <h3 id="projectDetailTitle">Project Title</h3>
                    <p id="projectDetailDate">Created: </p>
                </div>

                <div class="project-progress">
                    <div class="progress-steps">
                        <div class="progress-step" data-step="planning">
                            <i class="fas fa-pencil-alt"></i>
                            <span>Planning</span>
                        </div>
                        <div class="progress-step" data-step="collecting">
                            <i class="fas fa-boxes"></i>
                            <span>Collecting</span>
                        </div>
                        <div class="progress-step" data-step="in-progress">
                            <i class="fas fa-tools"></i>
                            <span>In Progress</span>
                        </div>
                        <div class="progress-step" data-step="completed">
                            <i class="fas fa-check-circle"></i>
                            <span>Completed</span>
                        </div>
                    </div>
                    <button id="projectStatusBtn" class="status-btn" onclick="updateProjectStatus()">
                        <i class="fas fa-flag"></i> Update Status
                    </button>
                </div>

                <div class="project-details-content">
                    <div class="project-description">
                        <h4>Description</h4>
                        <p id="projectDetailDescription"></p>
                    </div>

                    <div class="project-materials">
                        <div class="materials-header">
                            <h4>Materials Needed</h4>
                            <button class="add-material-btn" onclick="addMaterial()">
                                <i class="fas fa-plus"></i> Add Material
                            </button>
                        </div>
                        <div id="projectDetailMaterials" class="materials-list">
                            <!-- Materials will be dynamically added here -->
                        </div>
                    </div>

                    <div class="project-images">
                        <div class="images-header">
                            <h4>Project Photos</h4>
                            <button class="add-photo-btn" onclick="addProjectPhoto()">
                                <i class="fas fa-camera"></i> Add Photo
                            </button>
                        </div>
                        <div id="projectPhotos" class="photo-gallery">
                            <!-- Photos will be dynamically added here -->
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

        // Projects filter tabs
        const filterTabs = document.querySelectorAll('.filter-tab');
        filterTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                filterTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Here you would filter projects based on the data-filter attribute
                const filter = this.getAttribute('data-filter');
                console.log(`Filtering by: ${filter}`);
                // Implement your actual filtering logic here
            });
        });

        // Feedback system
        document.addEventListener('DOMContentLoaded', function() {
            const feedbackBtn = document.getElementById('feedbackBtn');
            const feedbackModal = document.getElementById('feedbackModal');
            const feedbackCloseBtn = document.getElementById('feedbackCloseBtn');
            const emojiOptions = document.querySelectorAll('.emoji-option');
            const feedbackForm = document.getElementById('feedbackForm');
            const thankYouMessage = document.getElementById('thankYouMessage');
            const feedbackSubmitBtn = document.getElementById('feedbackSubmitBtn');
            const spinner = document.getElementById('spinner');
            const ratingError = document.getElementById('ratingError');
            const textError = document.getElementById('textError');
            const feedbackText = document.getElementById('feedbackText');
            
            let selectedRating = 0;
            
            // Emoji selection
            emojiOptions.forEach(option => {
                option.addEventListener('click', () => {
                    emojiOptions.forEach(opt => opt.classList.remove('selected'));
                    option.classList.add('selected');
                    selectedRating = option.getAttribute('data-rating');
                    ratingError.style.display = 'none';
                });
            });
            
            // Form submission
            feedbackForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Validate form
                let isValid = true;
                
                if (selectedRating === 0) {
                    ratingError.style.display = 'block';
                    isValid = false;
                } else {
                    ratingError.style.display = 'none';
                }
                
                if (feedbackText.value.trim() === '') {
                    textError.style.display = 'block';
                    isValid = false;
                } else {
                    textError.style.display = 'none';
                }
                
                if (!isValid) return;
                
                // Show loading spinner
                feedbackSubmitBtn.disabled = true;
                spinner.style.display = 'block';
                
                // Simulate API call
                setTimeout(() => {
                    // Hide spinner
                    spinner.style.display = 'none';
                    
                    // Show thank you message
                    feedbackForm.style.display = 'none';
                    thankYouMessage.style.display = 'block';
                    
                    // Reset form after delay
                    setTimeout(() => {
                        feedbackModal.style.display = 'none';
                        feedbackForm.style.display = 'block';
                        thankYouMessage.style.display = 'none';
                        feedbackText.value = '';
                        emojiOptions.forEach(opt => opt.classList.remove('selected'));
                        selectedRating = 0;
                        feedbackSubmitBtn.disabled = false;
                    }, 3000);
                }, 1500);
            });
            
            // Open/close feedback modal
            feedbackBtn.addEventListener('click', () => {
                feedbackModal.style.display = 'flex';
            });
            
            feedbackCloseBtn.addEventListener('click', closeFeedbackModal);
            
            window.addEventListener('click', (event) => {
                if (event.target === feedbackModal) {
                    closeFeedbackModal();
                }
            });
            
            function closeFeedbackModal() {
                feedbackModal.style.display = 'none';
                feedbackForm.style.display = 'block';
                thankYouMessage.style.display = 'none';
                feedbackText.value = '';
                emojiOptions.forEach(opt => opt.classList.remove('selected'));
                selectedRating = 0;
                ratingError.style.display = 'none';
                textError.style.display = 'none';
                feedbackSubmitBtn.disabled = false;
                spinner.style.display = 'none';
            }
        });
    </script>
    <script>
        // Global variables
        let currentProjectId = null;
        let currentProjectStatus = 'planning';

        // Function to view project details
        function viewProjectDetails(projectId) {
            console.log('Viewing project details for ID:', projectId);
            if (!projectId) {
                console.error('No project ID provided');
                return;
            }

            fetch(`update_project.php?action=get_project_details&project_id=${projectId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        console.log('Project details loaded:', data);
                        // Store current project ID
                        currentProjectId = data.project.project_id;

                        // Update project details view
                        const titleElem = document.getElementById('projectDetailTitle');
                        const dateElem = document.getElementById('projectDetailDate');
                        const descElem = document.getElementById('projectDetailDescription');
                        
                        if (titleElem) titleElem.textContent = data.project.project_name;
                        if (dateElem) dateElem.textContent = 'Created: ' + new Date(data.project.created_at).toLocaleDateString();
                        if (descElem) descElem.textContent = data.project.description;
                        
                        // Update status
                        currentProjectStatus = data.project.status || 'planning';
                        updateProjectStatus(currentProjectStatus);
                        
                        // Update materials list if available
                        if (data.materials) {
                            updateMaterialsList(data.materials);
                        }

                        // Update photos if available
                        if (data.photos) {
                            updateProjectPhotos(data.photos);
                        }

                        // Hide projects list and show details view
                        document.querySelector('.projects-container').style.display = 'none';
                        document.querySelector('.projects-filter').style.display = 'none';
                        const projectDetailsView = document.getElementById('projectDetailsView');
                        
                        if (projectsListView) projectsListView.style.display = 'none';
                        if (projectDetailsView) projectDetailsView.style.display = 'block';
                    } else {
                        console.error('Error:', data.message);
                        alert('Error loading project details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading project details. Please try again.');
                });
        }

        // Function to update project status
        function updateProjectStatus(status) {
            console.log('Updating project status:', status);
            const steps = document.querySelectorAll('.progress-step');
            const statusMap = {
                'planning': 0,
                'collecting': 1,
                'in-progress': 2,
                'completed': 3
            };

            steps.forEach((step, index) => {
                if (index <= statusMap[status]) {
                    step.classList.add('active');
                } else {
                    step.classList.remove('active');
                }
            });

            const btn = document.getElementById('projectStatusBtn');
            if (btn) {
                btn.setAttribute('data-status', status);
                btn.innerHTML = status === 'completed' 
                    ? '<i class="fas fa-check-circle"></i> Completed'
                    : '<i class="fas fa-flag"></i> ' + getNextStatusLabel(status);
            }
        }

        // Function to update materials list
        function updateMaterialsList(materials) {
            console.log('Updating materials list:', materials);
            const materialsContainer = document.getElementById('projectDetailMaterials');
            if (!materialsContainer) return;
            
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
        }

        // Function to go back to projects list
        function showProjectsList() {
            console.log('Showing projects list');
            // Show projects list and filter
            document.querySelector('.projects-container').style.display = 'grid';
            document.querySelector('.projects-filter').style.display = 'flex';
            // Hide project details
            const projectDetailsView = document.getElementById('projectDetailsView');
            if (projectDetailsView) projectDetailsView.style.display = 'none';
            // Reset current project
            currentProjectId = null;
            currentProjectStatus = 'planning';
        }

        // Helper function for status labels
        function getNextStatusLabel(currentStatus) {
            switch (currentStatus) {
                case 'planning':
                    return 'Start Project';
                case 'in-progress':
                    return 'Mark as Complete';
                case 'completed':
                    return 'Completed';
                default:
                    return 'Update Status';
            }
        }

        // Initialize everything when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded - initializing event handlers');
            
            // Initialize filter tabs
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    console.log('Filter tab clicked');
                    const filter = this.getAttribute('data-filter');
                    console.log('Filter:', filter);
                    
                    // Update active tab
                    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Filter projects
                    document.querySelectorAll('.project-card').forEach(card => {
                        const cardStatus = card.getAttribute('data-status');
                        console.log('Processing card with status:', cardStatus);
                        
                        let shouldShow = filter === 'all';
                        if (!shouldShow) {
                            if (filter === 'in-progress' && (cardStatus === 'in-progress' || cardStatus === 'in_progress')) {
                                shouldShow = true;
                            } else if (filter === cardStatus) {
                                shouldShow = true;
                            }
                        }
                        card.style.display = shouldShow ? '' : 'none';
                        console.log('Card visibility:', shouldShow ? 'shown' : 'hidden');
                    });
                });
            });

            // Initialize user profile dropdown
            const userProfile = document.getElementById('userProfile');
            if (userProfile) {
                userProfile.addEventListener('click', function(e) {
                    e.stopPropagation();
                    this.classList.toggle('active');
                });

                document.addEventListener('click', function(e) {
                    if (!userProfile.contains(e.target)) {
                        userProfile.classList.remove('active');
                    }
                });
            }
        });

        // Material Management Functions
        function addMaterial() {
            const materialName = prompt('Enter material name:');
            if (!materialName) return;

            const materialUnit = prompt('Enter unit (optional):');
            
            if (!currentProjectId) {
                console.error('No project selected');
                return;
            }

            const data = {
                project_id: currentProjectId,
                name: materialName,
                unit: materialUnit || ''
            };

            fetch('update_project.php?action=add_material', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.materials) {
                        updateMaterialsList(data.materials);
                    }
                } else {
                    alert('Error adding material: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error adding material. Please try again.');
            });
        }

        function markMaterialFound(materialId) {
            if (!currentProjectId || !materialId) {
                console.error('Missing project ID or material ID');
                return;
            }

            fetch('update_project.php?action=mark_material_found', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    project_id: currentProjectId,
                    material_id: materialId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.materials) {
                        updateMaterialsList(data.materials);
                    }
                } else {
                    alert('Error updating material: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating material. Please try again.');
            });
        }

        function deleteMaterial(materialId) {
            if (!confirm('Are you sure you want to delete this material?')) return;

            if (!currentProjectId || !materialId) {
                console.error('Missing project ID or material ID');
                return;
            }

            fetch('update_project.php?action=delete_material', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    project_id: currentProjectId,
                    material_id: materialId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.materials) {
                        updateMaterialsList(data.materials);
                    }
                } else {
                    alert('Error deleting material: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting material. Please try again.');
            });
        }

        function addProjectPhoto() {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.onchange = function(event) {
                const file = event.target.files[0];
                if (!file) return;

                const formData = new FormData();
                formData.append('photo', file);
                formData.append('project_id', currentProjectId);

                fetch('update_project.php?action=add_photo', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.photos) {
                            updateProjectPhotos(data.photos);
                        }
                    } else {
                        alert('Error uploading photo: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error uploading photo. Please try again.');
                });
            };
            input.click();
        }

        function updateProjectPhotos(photos) {
            const gallery = document.getElementById('projectPhotos');
            if (!gallery) return;

            gallery.innerHTML = '';
            if (photos && photos.length > 0) {
                photos.forEach(photo => {
                    const photoDiv = document.createElement('div');
                    photoDiv.className = 'photo-item';
                    photoDiv.innerHTML = `
                        <img src="${photo.url}" alt="Project photo">
                        <button class="delete-photo-btn" onclick="deleteProjectPhoto(${photo.id})" title="Delete photo">
                            <i class="fas fa-trash"></i>
                        </button>`;
                    gallery.appendChild(photoDiv);
                });
            } else {
                gallery.innerHTML = '<p class="no-photos">No photos added yet.</p>';
            }
        }

        function deleteProjectPhoto(photoId) {
            if (!confirm('Are you sure you want to delete this photo?')) return;

            if (!currentProjectId || !photoId) {
                console.error('Missing project ID or photo ID');
                return;
            }

            fetch('update_project.php?action=delete_photo', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    project_id: currentProjectId,
                    photo_id: photoId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.photos) {
                        updateProjectPhotos(data.photos);
                    }
                } else {
                    alert('Error deleting photo: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting photo. Please try again.');
            });
        }
    </script>
</body>
</html>