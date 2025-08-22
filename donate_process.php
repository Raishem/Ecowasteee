<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$conn = getDBConnection();
$item_name = $_POST['wasteType'];
$quantity = $_POST['quantity'];
$category = $_POST['wasteType'];
$donor_id = $_SESSION['user_id'];
$donated_at = date('Y-m-d H:i:s');

// Handle photo upload if needed

$stmt = $conn->prepare("INSERT INTO donations (item_name, quantity, category, donor_id, donated_at, status) VALUES (?, ?, ?, ?, ?, 'Available')");
$stmt->bind_param("sisds", $item_name, $quantity, $category, $donor_id, $donated_at);
$stmt->execute();

header('Location: donations.php');
exit();
?>