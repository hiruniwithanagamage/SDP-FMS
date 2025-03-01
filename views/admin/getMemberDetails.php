<?php
session_start();
require_once "../../config/database.php";

// Check if user is logged in and is admin
if (!isset($_SESSION["u"]) || $_SESSION["role"] !== "admin") {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Check if ID is provided
if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Member ID is required']);
    exit();
}

// Get member ID and sanitize it
$conn = getConnection();
$memberId = $conn->real_escape_string($_GET['id']);

// Fetch member details
$query = "SELECT * FROM Member WHERE MemberID = '$memberId'";
$result = search($query);

if ($result && $result->num_rows > 0) {
    // Return member details as JSON
    echo json_encode($result->fetch_assoc());
} else {
    echo json_encode(['error' => 'Member not found']);
}
?>