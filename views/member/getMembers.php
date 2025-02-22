//getMembers.php
<?php
require_once '../../config/connection.php';

// Get search parameters
$search = $_GET['search'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$perPage = 10;
$offset = ($page - 1) * $perPage;

try {
    // Search query with pagination
    $searchTerm = "%{$search}%";
    $query = "SELECT MemberID, Name 
             FROM Member 
             WHERE Name LIKE '$searchTerm' 
             OR MemberID LIKE '$searchTerm' 
             LIMIT $perPage OFFSET $offset";

    $result = Database::search($query);
    
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }

    // Format results for Select2
    $results = [
        'items' => array_map(function($member) {
            return [
                'id' => $member['MemberID'],
                'text' => $member['Name'] . ' (' . $member['MemberID'] . ')'
            ];
        }, $members)
    ];

    // Set response headers
    header('Content-Type: application/json');
    echo json_encode($results);

} catch (Exception $e) {
    // Log error and return empty results
    error_log("Database error in get_members.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'items' => [],
        'error' => 'An error occurred while searching for members'
    ]);
}