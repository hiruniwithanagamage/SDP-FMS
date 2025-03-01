<?php
require_once "../../config/database.php";  // Changed from connection.php to database.php

// Get search parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

try {
    // Search query with pagination
    $searchTerm = "%" . Database::escapeString($search) . "%";  // Using proper escaping
    $query = "SELECT MemberID, Name 
             FROM Member 
             WHERE Name LIKE '$searchTerm' 
             OR MemberID LIKE '$searchTerm' 
             ORDER BY Name ASC 
             LIMIT $perPage OFFSET $offset";

    $result = search($query);
    
    if (!$result) {
        throw new Exception("Database query failed");
    }
    
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = [
            'id' => $row['MemberID'],
            'text' => $row['Name'] . ' (' . $row['MemberID'] . ')'
        ];
    }

    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total 
                   FROM Member 
                   WHERE Name LIKE '$searchTerm' 
                   OR MemberID LIKE '$searchTerm'";
    $countResult = search($countQuery);
    $totalCount = $countResult->fetch_assoc()['total'];

    // Format response for Select2
    $response = [
        'results' => $members,  // Changed from 'items' to 'results' for Select2
        'pagination' => [
            'more' => ($offset + $perPage) < $totalCount
        ]
    ];

    // Set response headers
    header('Content-Type: application/json');
    echo json_encode($response);

} catch (Exception $e) {
    // Log error and return empty results
    error_log("Database error in getMembers.php: " . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'results' => [],  // Changed from 'items' to 'results'
        'error' => 'An error occurred while searching for members',
        'message' => $e->getMessage()
    ]);
}
?>