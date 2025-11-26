<?php
// ==========================================
// get_request_data.php
// Purpose: Fetch specific donation request info for editing
// Returns JSON to be loaded into the "Edit Request" modal
// ==========================================

require_once 'config.php';
header('Content-Type: application/json');

// STEP 1: Check if the 'id' parameter was provided
if (!isset($_GET['id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing request ID."
    ]);
    exit();
}

// STEP 2: Connect to database
$conn = getDBConnection();
$request_id = intval($_GET['id']); // Sanitize ID

// STEP 3: Prepare SQL to fetch request data
$stmt = $conn->prepare("
    SELECT request_id, quantity_claim, urgency_level 
    FROM donation_requests 
    WHERE request_id = ?
");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$result = $stmt->get_result();

// STEP 4: Check if request exists
if ($result->num_rows === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Request not found."
    ]);
    exit();
}

// STEP 5: Fetch request details
$data = $result->fetch_assoc();

// STEP 6: Send back JSON with a 'status' field your JS expects
echo json_encode([
    "status" => "success",
    "data" => $data
]);

// STEP 7: Close connections
$stmt->close();
$conn->close();
?>
