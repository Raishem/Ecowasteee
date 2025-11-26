<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['first_name']) || !isset($_SESSION['last_name'])) {
        echo json_encode(["status" => "error", "message" => "Not logged in"]);
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $first_name = $_SESSION['first_name'];
    $last_name = $_SESSION['last_name'];
    $user_name = $first_name . " " . $last_name;
    $rating = intval($_POST['rating']);
    $feedback = trim($_POST['feedback']);

    

    if ($rating < 1 || $rating > 5 || empty($feedback)) {
        echo json_encode(["status" => "error", "message" => "Invalid input"]);
        exit();
    }


    $stmt = $conn->prepare("
    INSERT INTO feedback (user_id, user_name, rating, feedback_text, submitted_at) 
    VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("isis", $user_id, $user_name, $rating, $feedback);


    if (!$stmt->execute()) {
    error_log("Feedback insert error: " . $conn->error);
    echo json_encode(["status" => "error", "message" => $conn->error]);
    } else {
    echo json_encode(["status" => "success"]);
    }


    $stmt->close();
    $conn->close();
}
