<?php
session_start();
require_once 'config.php';

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$material_id = isset($_GET['material_id']) ? (int)$_GET['material_id'] : 0;
$success_message = '';
$error_message = '';

$conn = getDBConnection();
try {
    // Get material details
    $material_query = $conn->prepare("
        SELECT pm.*, p.project_name, p.user_id as project_owner_id
        FROM project_materials pm
        JOIN projects p ON pm.project_id = p.project_id
        WHERE pm.id = ?
    ");
    $material_query->execute([$material_id]);
    $material = $material_query->fetch(PDO::FETCH_ASSOC);

    if (!$material) {
        header('Location: projects.php');
        exit();
    }

    // Check if user owns this project
    $is_owner = ($material['project_owner_id'] == $user_id);

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['request_donation'])) {
            // Create donation request
            $conn->beginTransaction();
            try {
                // Update material status
                $update_status = $conn->prepare("
                    UPDATE project_materials 
                    SET status = 'requested' 
                    WHERE id = ? AND status = 'needed'
                ");
                $update_status->execute([$material_id]);

                // Create donation request
                $create_request = $conn->prepare("
                    INSERT INTO material_donation_requests (material_id, requester_id) 
                    VALUES (?, ?)
                ");
                $create_request->execute([$material_id, $user_id]);

                $conn->commit();
                $success_message = "Donation request created successfully!";
                
                // Redirect back to project details
                header("Location: project_details.php?id=" . $material['project_id'] . "&success=request_created");
                exit();
            } catch (Exception $e) {
                $conn->rollBack();
                $error_message = "Error creating request: " . $e->getMessage();
            }
        } elseif (isset($_POST['offer_donation'])) {
            $request_id = (int)$_POST['request_id'];
            $quantity = (int)$_POST['quantity'];
            $message = trim($_POST['message']);

            if ($quantity <= 0) {
                $error_message = "Please enter a valid quantity.";
            } else {
                // Create donation offer
                $create_offer = $conn->prepare("
                    INSERT INTO material_donations (request_id, donor_id, quantity, message)
                    VALUES (?, ?, ?, ?)
                ");
                $create_offer->execute([$request_id, $user_id, $quantity, $message]);
                $success_message = "Donation offered successfully!";
            }
        } elseif (isset($_POST['accept_donation'])) {
            $donation_id = (int)$_POST['donation_id'];
            
            $conn->beginTransaction();
            try {
                // Update donation status
                $update_donation = $conn->prepare("
                    UPDATE material_donations 
                    SET status = 'accepted' 
                    WHERE donation_id = ?
                ");
                $update_donation->execute([$donation_id]);

                // Update material status
                $update_material = $conn->prepare("
                    UPDATE project_materials 
                    SET status = 'donated' 
                    WHERE id = ?
                ");
                $update_material->execute([$material_id]);

                $conn->commit();
                $success_message = "Donation accepted!";
            } catch (Exception $e) {
                $conn->rollBack();
                $error_message = "Error accepting donation: " . $e->getMessage();
            }
        }
    }

    // Get active donation requests and offers
    $requests_query = $conn->prepare("
        SELECT r.*, u.username as requester_name
        FROM material_donation_requests r
        JOIN users u ON r.requester_id = u.user_id
        WHERE r.material_id = ? AND r.status IN ('pending', 'accepted')
    ");
    $requests_query->execute([$material_id]);
    $requests = $requests_query->fetchAll(PDO::FETCH_ASSOC);

    $donations_query = $conn->prepare("
        SELECT d.*, u.username as donor_name
        FROM material_donations d
        JOIN material_donation_requests r ON d.request_id = r.request_id
        JOIN users u ON d.donor_id = u.user_id
        WHERE r.material_id = ? AND d.status != 'cancelled'
    ");
    $donations_query->execute([$material_id]);
    $donations = $donations_query->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material Donation | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/homepage.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .donation-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .material-details {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .material-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .material-name {
            font-size: 1.5rem;
            color: #2e8b57;
            margin: 0;
        }

        .material-project {
            color: #666;
            font-size: 0.9rem;
        }

        .donation-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .donation-form {
            margin-top: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .donation-list {
            margin-top: 20px;
        }

        .donation-item {
            background: #f5f9f5;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }

        .donation-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .donor-info {
            font-weight: 500;
            color: #2e8b57;
        }

        .donation-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }

        .status-offered {
            background: #e3f2fd;
            color: #1976d2;
        }

        .status-accepted {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .donation-details {
            color: #555;
            font-size: 0.95rem;
            margin-bottom: 10px;
        }

        .donation-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.2s;
        }

        .btn-primary {
            background: #2e8b57;
            color: white;
        }

        .btn-primary:hover {
            background: #3cb371;
        }

        .btn-secondary {
            background: #f5f5f5;
            color: #333;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        .messages {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }

        .message {
            background: white;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .message-sender {
            font-weight: 500;
            color: #2e8b57;
            margin-bottom: 4px;
        }

        .message-time {
            font-size: 0.8rem;
            color: #666;
        }

        .success-message,
        .error-message {
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .success-message {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }

        .error-message {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }
    </style>
</head>
<body>
    <div class="donation-container">
        <?php if ($success_message): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="material-details">
            <div class="material-header">
                <div>
                    <h1 class="material-name"><?= htmlspecialchars($material['material_name']) ?></h1>
                    <div class="material-project">
                        From project: <?= htmlspecialchars($material['project_name']) ?>
                    </div>
                </div>
                <div class="material-quantity">
                    Quantity needed: <?= htmlspecialchars($material['quantity']) ?>
                </div>
            </div>
        </div>

        <?php if ($is_owner && $material['status'] === 'needed'): ?>
            <div class="donation-section">
                <h2>Request Donations</h2>
                <form method="POST" class="donation-form">
                    <p>Create a donation request for this material to let others know you need help.</p>
                    <button type="submit" name="request_donation" class="btn btn-primary">
                        <i class="fas fa-hand-holding-heart"></i> Request Donations
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!$is_owner && !empty($requests)): ?>
            <div class="donation-section">
                <h2>Offer to Help</h2>
                <form method="POST" class="donation-form">
                    <input type="hidden" name="request_id" value="<?= $requests[0]['request_id'] ?>">
                    
                    <div class="form-group">
                        <label for="quantity">Quantity you can donate:</label>
                        <input type="number" id="quantity" name="quantity" min="1" max="<?= $material['quantity'] ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="message">Message to requester:</label>
                        <textarea id="message" name="message" placeholder="Tell them about the material you can donate..."></textarea>
                    </div>

                    <button type="submit" name="offer_donation" class="btn btn-primary">
                        <i class="fas fa-gift"></i> Offer Donation
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!empty($donations)): ?>
            <div class="donation-section">
                <h2>Donation Offers</h2>
                <div class="donation-list">
                    <?php foreach ($donations as $donation): ?>
                        <div class="donation-item">
                            <div class="donation-header">
                                <span class="donor-info">
                                    <i class="fas fa-user"></i> <?= htmlspecialchars($donation['donor_name']) ?>
                                </span>
                                <span class="donation-status status-<?= $donation['status'] ?>">
                                    <?= ucfirst($donation['status']) ?>
                                </span>
                            </div>
                            <div class="donation-details">
                                <p>Quantity offered: <?= htmlspecialchars($donation['quantity']) ?></p>
                                <?php if ($donation['message']): ?>
                                    <p><?= htmlspecialchars($donation['message']) ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if ($is_owner && $donation['status'] === 'offered'): ?>
                                <div class="donation-actions">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="donation_id" value="<?= $donation['donation_id'] ?>">
                                        <button type="submit" name="accept_donation" class="btn btn-primary">
                                            <i class="fas fa-check"></i> Accept Offer
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Add any JavaScript for interactivity here
        document.addEventListener('DOMContentLoaded', function() {
            // Handle form submissions
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitButton = form.querySelector('button[type="submit"]');
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    }
                });
            });
        });
    </script>
</body>
</html>