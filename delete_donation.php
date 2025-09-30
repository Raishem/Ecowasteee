<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = intval($_POST['id']);

    // Delete only if owned by logged-in user
    $stmt = $conn->prepare("DELETE FROM donations WHERE id = ? AND donor_id = ?");
    $stmt->bind_param("ii", $id, $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error"]);
    }
}
?>
