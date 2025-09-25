<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['wasteType']) || empty($_POST['quantity']) || empty($_POST['description'])) {
        die('Error: All fields are required.');
    }

    $conn = getDBConnection();
    $item_name = htmlspecialchars($_POST['wasteType']);
    $quantity = (int) $_POST['quantity'];
    $category = htmlspecialchars($_POST['wasteType']);
    $description = htmlspecialchars($_POST['description']);
    $donor_id = $_SESSION['user_id'];
    $donated_at = date('Y-m-d H:i:s');
    $image_paths = []; // Array to store uploaded image paths

    // Handle multiple photo uploads
    if (isset($_FILES['photos']) && count($_FILES['photos']['name']) > 0) {
        $upload_dir = 'assets/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        foreach ($_FILES['photos']['name'] as $key => $file_name) {
            if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                $file_type = mime_content_type($_FILES['photos']['tmp_name'][$key]);
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];

                if (!in_array($file_type, $allowed_types)) {
                    die('Error: Only JPG, PNG, and GIF files are allowed.');
                }

                $unique_file_name = uniqid() . '_' . basename($file_name);
                $target_file = $upload_dir . $unique_file_name;

                if (move_uploaded_file($_FILES['photos']['tmp_name'][$key], $target_file)) {
                    $image_paths[] = $target_file; // Save the file path
                } else {
                    die('Failed to upload image: ' . $file_name);
                }
            }
        }
    }

    // Convert image paths array to JSON for storage
    $image_paths_json = json_encode($image_paths);

    // Insert donation into the database
    $stmt = $conn->prepare("INSERT INTO donations (item_name, quantity, category, donor_id, donated_at, status, image_path, description) VALUES (?, ?, ?, ?, ?, 'Available', ?, ?)");
    if (!$stmt) {
        die('Error: Failed to prepare statement.');
    }

    try {
        $stmt->execute([$item_name, $quantity, $category, $donor_id, $donated_at, $image_paths_json, $description]);
    } catch (PDOException $e) {
        die('Error: Failed to execute statement. ' . $e->getMessage());
    }

    header('Location: donations.php');
    exit();
} else {
    die('Invalid request method.');
}
?>