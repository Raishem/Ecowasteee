<?php
require_once 'config.php';
header('Content-Type: application/json');

// Step 1: Validate ID
if (!isset($_GET['id'])) {
    echo json_encode(["status" => "error", "message" => "Missing request ID."]);
    exit();
}

$conn = getDBConnection();
$request_id = intval($_GET['id']);

// Step 2: Fetch full details about the request
$stmt = $conn->prepare("
    SELECT 
        dr.request_id,
        dr.quantity_claim,
        dr.urgency_level,
        dr.requested_at,
        dr.delivery_start,
        dr.delivery_end,
        dr.delivery_status,
        dr.status,
        d.category,
        d.subcategory,
        d.donation_id,
        d.image_path,
        u.first_name AS donor_first_name,
        u.last_name AS donor_last_name,
        p.project_name
    FROM donation_requests dr
    JOIN donations d ON dr.donation_id = d.donation_id
    JOIN users u ON d.donor_id = u.user_id
    LEFT JOIN projects p ON dr.project_id = p.project_id
    WHERE dr.request_id = ?
");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$result = $stmt->get_result();

// Step 3: Validate result
if ($result->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "Request not found."]);
    exit();
}

$row = $result->fetch_assoc();

// Step 4: Build data payload
$imagePath = !empty($row['image_path']) ? 'assets/uploads/' . basename($row['image_path']) : null;

// If you have a dedicated donation view (like `donation_view.php?id=`), use that link:
$postUrl = 'donation_view.php?id=' . $row['donation_id'];

$data = [
    "request_id" => $row['request_id'],
    "quantity_claim" => $row['quantity_claim'],
    "urgency_level" => $row['urgency_level'],
    "requested_at" => $row['requested_at'],
    "delivery_start" => $row['delivery_start'],
    "delivery_end" => $row['delivery_end'],
    "delivery_status" => $row['delivery_status'],
    "status" => $row['status'],
    "category" => $row['category'],
    "subcategory" => $row['subcategory'],
    "donation_id" => $row['donation_id'],
    "image_path" => $imagePath,
    "image_link" => $postUrl,
    "donor_name" => trim($row['donor_first_name'] . ' ' . $row['donor_last_name']),
    "project_name" => $row['project_name'] ?? '—'
];


// Step 5: Return JSON
echo json_encode(["status" => "success", "data" => $data]);

$stmt->close();
$conn->close();
?>
