<?php
session_start();
require_once "../../config/database.php";

// Get database connection
$conn = getConnection();

// Check if user is logged in and is admin
if (!isset($_SESSION["u"]) || $_SESSION["role"] !== "admin") {
    header("Location: login.php");
    exit();
}

// Check for success message
$successMessage = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : null;
if($successMessage) {
    // Clear the message so it doesn't show again on refresh
    unset($_SESSION['success_message']);
}

// AJAX handler for getting member photo - responds to memberDetails.php?action=getPhoto&id=X
if(isset($_GET['action']) && $_GET['action'] === 'getPhoto' && isset($_GET['id'])) {
    $photoMemberId = trim($_GET['id']);
    
    // Fetch member's photo information
    $photoQuery = "SELECT Image FROM Member WHERE MemberID = ?";
    $photoStmt = $conn->prepare($photoQuery);
    $photoStmt->bind_param("s", $photoMemberId);
    $photoStmt->execute();
    $photoResult = $photoStmt->get_result();
    
    if ($photoResult->num_rows === 0) {
        echo json_encode(['hasPhoto' => false]);
        exit();
    }
    
    $photoData = $photoResult->fetch_assoc();
    $photoName = $photoData['Image'];
    
    if (empty($photoName)) {
        echo json_encode(['hasPhoto' => false]);
        exit();
    }
    
    // Check if the file exists on the server
    $uploadPath = "../../uploads/profilePictures/" . $photoName;
    $photoExists = file_exists($uploadPath);
    
    echo json_encode([
        'hasPhoto' => $photoExists,
        'photoName' => $photoExists ? $photoName : null,
        'fullPath' => $photoExists ? $uploadPath : null
    ]);
    exit();
}

// Handle Search
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchCondition = '';
$params = [];
$types = '';

// Fetch all members with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$recordsPerPage = 10;
$offset = ($page - 1) * $recordsPerPage;

// Build query based on search term
if(!empty($searchTerm)) {
    $searchCondition = " WHERE Name LIKE ? OR NIC LIKE ? OR MemberID LIKE ?";
    $searchParam = "%{$searchTerm}%";
    $params = [$searchParam, $searchParam, $searchParam];
    $types = 'sss';
}

// Get total number of records for pagination
if(empty($searchTerm)) {
    $totalRecordsQuery = "SELECT COUNT(*) as count FROM Member";
    $stmt = $conn->prepare($totalRecordsQuery);
    $stmt->execute();
} else {
    $totalRecordsQuery = "SELECT COUNT(*) as count FROM Member" . $searchCondition;
    $stmt = $conn->prepare($totalRecordsQuery);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
}

$totalResult = $stmt->get_result();
$totalRecords = $totalResult->fetch_assoc()['count'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Fetch members with pagination and search
$query = "SELECT * FROM Member" . $searchCondition . " ORDER BY MemberID ASC LIMIT ?, ?";
$stmt = $conn->prepare($query);

if(empty($searchTerm)) {
    $stmt->bind_param("ii", $offset, $recordsPerPage);
} else {
    $types .= 'ii';
    $params[] = $offset;
    $params[] = $recordsPerPage;
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Handle Update
if(isset($_POST['update'])) {
    $memberId = trim($_POST['member_id']);
    $name = trim($_POST['name']);
    $nic = trim($_POST['nic']);
    $dob = $_POST['dob'];
    $address = trim($_POST['address']);
    $mobile = !empty($_POST['mobile']) ? trim($_POST['mobile']) : null;
    
    // Convert empty values to NULL or 0 for numeric fields
    $familyMembers = empty($_POST['family_members']) ? 0 : (int)$_POST['family_members'];
    $otherMembers = empty($_POST['other_members']) ? 0 : (int)$_POST['other_members'];
    $status = $_POST['status'];
    
    // Validate inputs
    $errors = [];
    
    // Validate NIC (Sri Lanka format)
    if (!empty($nic)) {
        // Old format: 9 digits followed by V or X
        // New format: 12 digits
        if (!(preg_match('/^\d{9}[vVxX]$/', $nic) || preg_match('/^\d{12}$/', $nic))) {
            $errors[] = "Invalid NIC format. Must be 9 digits followed by V/X or 12 digits.";
        }
    }
    
    // Validate mobile number (Sri Lanka format)
    if (!empty($mobile)) {
        // Sri Lankan mobile numbers: 07XXXXXXXX or +947XXXXXXXX
        if (!preg_match('/^(07\d{8}|\+947\d{8})$/', $mobile)) {
            $errors[] = "Invalid mobile number format. Must be 07XXXXXXXX or +947XXXXXXXX.";
        }
    }
    
    // Validate numeric fields are not negative
    if ($familyMembers < 0) {
        $errors[] = "Number of family members cannot be negative.";
    }
    
    if ($otherMembers < 0) {
        $errors[] = "Number of other members cannot be negative.";
    }
    
    if (empty($errors)) {
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Update basic member info with prepared statement
            $updateQuery = "UPDATE Member SET 
                           Name = ?,
                           NIC = ?,
                           DoB = ?,
                           Address = ?,
                           Mobile_Number = ?,
                           No_of_Family_Members = ?,
                           Other_Members = ?,
                           Status = ?
                           WHERE MemberID = ?";
                           
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("ssssiisss", 
                $name, 
                $nic, 
                $dob, 
                $address, 
                $mobile, 
                $familyMembers, 
                $otherMembers, 
                $status, 
                $memberId
            );
            
            $stmt->execute();
            
            // Handle file upload if exists
            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === 0) {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
                $maxSize = 20 * 1024 * 1024; // 20MB
                
                if (!in_array($_FILES['profile_photo']['type'], $allowedTypes)) {
                    throw new Exception("Only JPG, JPEG & PNG files are allowed");
                } elseif ($_FILES['profile_photo']['size'] > $maxSize) {
                    throw new Exception("File size must be less than 20MB");
                }
                
                // Create directory if it doesn't exist
                $uploadDir = "../../uploads/profilePictures/";
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileName = $memberId . '_' . time() . '.' . pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
                $uploadPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadPath)) {
                    // Update member record with new image path
                    $updateImageQuery = "UPDATE Member SET Image = ? WHERE MemberID = ?";
                    $stmt = $conn->prepare($updateImageQuery);
                    $stmt->bind_param("ss", $fileName, $memberId);
                    $stmt->execute();
                } else {
                    throw new Exception("Failed to upload image");
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['success_message'] = "Member updated successfully";
            header("Location: " . $_SERVER['PHP_SELF'] . "?update=success" . ($searchTerm ? "&search=" . urlencode($searchTerm) : ""));
            exit();
        } catch(Exception $e) {
            // Rollback on error
            $conn->rollback();
            $updateError = "Error updating member: " . $e->getMessage();
        }
    } else {
        $updateError = implode("<br>", $errors);
    }
}

// Handle Delete - Modified to delete without checking associations
if(isset($_POST['delete'])) {
    $memberId = trim($_POST['member_id']);
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // First delete the user record
        $deleteUserQuery = "DELETE FROM User WHERE Member_MemberID = ?";
        $stmt = $conn->prepare($deleteUserQuery);
        $stmt->bind_param("s", $memberId);
        $stmt->execute();
        
        // Then delete the member
        $deleteMemberQuery = "DELETE FROM Member WHERE MemberID = ?";
        $stmt = $conn->prepare($deleteMemberQuery);
        $stmt->bind_param("s", $memberId);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Member deleted successfully";
        header("Location: " . $_SERVER['PHP_SELF'] . ($searchTerm ? "?search=" . urlencode($searchTerm) : ""));
        exit();
    } catch(Exception $e) {
        // Rollback on error
        $conn->rollback();
        $deleteError = "Error deleting member: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Members</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
    <script src="../../assets/js/memberDetails.js"></script>
    <style>
    /* Improved styling based on UserDetails.php */
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        background-color: #f5f7fa;
    }

    .main-container {
        min-height: 100vh; 
        background: #f5f7fa; 
        padding: 2rem;
    }

    .container {
        max-width: 1200px;
        margin: 2rem auto;
        padding: 2rem;
        background-color: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .header-section {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }

    h1 {
        color: #1a237e;
        margin: 0;
    }

    .search-section {
        display: flex;
        gap: 1rem;
        align-items: center;
    }

    .search-input {
        padding: 0.7rem;
        border: 2px solid #e0e0e0;
        border-radius: 4px;
        font-size: 0.9rem;
        width: 250px;
    }

    .btn {
        padding: 0.7rem 1.5rem;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 500;
        text-align: center;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        transition: background-color 0.3s;
    }

    .btn-primary {
        background-color: #1a237e;
        color: white;
    }

    .btn-primary:hover {
        background-color: #0d1757;
    }

    .member-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 2rem;
    }

    .member-table th {
        background-color: #f5f7fa;
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        color: #1a237e;
        border-bottom: 2px solid #e0e0e0;
    }

    .member-table td {
        padding: 1rem;
        border-bottom: 1px solid #e0e0e0;
    }

    .member-row:hover {
        background-color: #f5f7fa;
    }

    .status-badge {
        display: inline-block;
        padding: 0.3rem 0.8rem;
        font-size: 0.8rem;
        font-weight: 500;
        border-radius: 20px;
    }

    .status-active {
        background-color: #e8f5e9;
        color: #2e7d32;
    }

    .status-pending {
        background-color: #fff8e1;
        color: #ff8f00;
    }

    .status-left {
        background-color: #ffebee;
        color: #c62828;
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
    }

    .action-btn {
        padding: 0.5rem 1rem;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: background-color 0.3s;
    }

    .edit-btn {
        background-color: #e3f2fd;
        color: #0d47a1;
    }

    .edit-btn:hover {
        background-color: #bbdefb;
    }

    .delete-btn {
        background-color: #ffebee;
        color: #c62828;
    }

    .delete-btn:hover {
        background-color: #ffcdd2;
    }

    .pagination {
        display: flex;
        justify-content: center;
        gap: 0.5rem;
        margin-top: 2rem;
    }

    .pagination a {
        display: inline-block;
        padding: 0.5rem 1rem;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        text-decoration: none;
        color: #333;
        transition: all 0.3s;
    }

    .pagination a.active {
        background-color: #1a237e;
        color: white;
        border-color: #1a237e;
    }

    .pagination a:hover:not(.active) {
        background-color: #f5f7fa;
    }

    .member-details {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .member-photo {
        flex: 0 0 200px;
        border: 1px solid #ddd;
        padding: 10px;
        border-radius: 5px;
        text-align: center;
    }
    
    .member-info {
        flex: 1;
        min-width: 300px;
    }
    
    .detail-row {
        display: flex;
        margin-bottom: 10px;
        border-bottom: 1px solid #eee;
        padding-bottom: 5px;
    }
    
    .detail-label {
        flex: 0 0 150px;
        font-weight: bold;
    }
    
    .detail-value {
        flex: 1;
    }
    
    .no-photo {
        height: 150px;
        border: 1px dashed #ccc;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #999;
    }
    
    .loading {
        text-align: center;
        padding: 20px;
        color: #666;
    }

    /* Modal Styles */
    .modal, .delete-modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.4);
    }

    .modal-content, .delete-modal-content {
        background-color: #fefefe;
        margin: 10% auto;
        padding: 20px;
        border: 1px solid #888;
        border-radius: 8px;
        width: 50%;
        max-width: 600px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    .modal-content h2, .delete-modal-content h2 {
        color: #1a237e;
        margin-top: 0;
        margin-bottom: 1.5rem;
    }

    .close {
        color: #aaa;
        float: right;
        font-size: 1.5rem;
        font-weight: bold;
        cursor: pointer;
    }

    .close:hover {
        color: #1a237e;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: #333;
    }

    .form-group input, .form-group select {
        width: 100%;
        padding: 0.8rem;
        border: 2px solid #e0e0e0;
        border-radius: 4px;
        font-size: 1rem;
        transition: border-color 0.3s;
    }

    .form-group input:focus, .form-group select:focus {
        border-color: #1a237e;
        outline: none;
    }

    .form-group small, .hint-text {
        display: block;
        margin-top: 0.3rem;
        color: #666;
        font-size: 0.8rem;
    }

    .modal-footer {
        margin-top: 2rem;
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
    }

    /* Button styling fix for mobile */
    .button-group {
        display: flex;
        gap: 1rem;
        margin-top: 2rem;
        flex-wrap: wrap;
    }

    .save-btn {
        background-color: #1a237e;
        color: white;
        padding: 0.8rem 2rem;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.9rem;
        transition: background-color 0.3s;
    }

    .save-btn:hover {
        background-color: #0d1757;
    }

    .cancel-btn {
        background-color: #e0e0e0;
        color: #333;
        padding: 0.8rem 2rem;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.9rem;
        transition: background-color 0.3s;
    }

    .cancel-btn:hover {
        background-color: #bdbdbd;
    }

    .delete-modal-buttons {
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        margin-top: 2rem;
    }

    .confirm-delete-btn {
        background-color: #c62828;
        color: white;
        padding: 0.8rem 2rem;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.9rem;
        transition: background-color 0.3s;
    }

    .confirm-delete-btn:hover {
        background-color: #b71c1c;
    }

    .alert {
        padding: 1rem;
        border-radius: 4px;
        margin-bottom: 1.5rem;
    }

    .alert-success {
        background-color: #e8f5e9;
        color: #2e7d32;
        border: 1px solid #c8e6c9;
    }

    .alert-danger {
        background-color: #ffebee;
        color: #c62828;
        border: 1px solid #ffcdd2;
    }
    
    /* Responsive design */
    @media (max-width: 768px) {
        .modal-content, .delete-modal-content {
            width: 90%;
            margin: 5% auto;
        }
        
        .search-section {
            flex-direction: column;
            width: 100%;
        }
        
        .search-section form {
            width: 100%;
        }
        
        .search-input {
            flex: 1;
            width: 100%;
        }
        
        .button-group {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
        }
    }
    </style>
</head>
<body>
    <div class="main-container">
    <?php include '../templates/navbar-admin.php'; ?>
    <div class="container">
        <div class="header-section">
            <h1>Manage Members</h1>
            <div class="search-section">
                <form action="" method="GET" style="display: flex; gap: 1rem; flex: 1;">
                    <input type="text" name="search" placeholder="Search by Name, NIC or ID" 
                           class="search-input" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
                <a href="addMember.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Member
                </a>
            </div>
        </div>

        <?php if($successMessage): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if(isset($updateError) || isset($deleteError)): ?>
            <div class="alert alert-danger">
                <?php 
                    echo isset($updateError) ? htmlspecialchars($updateError) : '';
                    echo isset($deleteError) ? htmlspecialchars($deleteError) : '';
                ?>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="member-table">
                <thead>
                    <tr>
                        <th>Member ID</th>
                        <th>Name</th>
                        <th>NIC</th>
                        <th>Contact</th>
                        <th>Address</th>
                        <th>Joined Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if($result->num_rows > 0):
                        while($row = $result->fetch_assoc()): 
                            // Determine status class
                            $statusClass = '';
                            
                            if($row['Status'] === 'Full Member') {
                                $statusClass = 'active';
                            } elseif($row['Status'] === 'Pending') {
                                $statusClass = 'pending';
                            } elseif($row['Status'] === 'Left') {
                                $statusClass = 'left';
                            }
                    ?>
                    <tr data-id="<?php echo htmlspecialchars($row['MemberID']); ?>" class="member-row" style="cursor: pointer;" onclick="viewMemberDetails('<?php echo htmlspecialchars($row['MemberID']); ?>')">
                        <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                        <td><?php echo htmlspecialchars($row['Name']); ?></td>
                        <td><?php echo htmlspecialchars($row['NIC']); ?></td>
                        <td><?php echo htmlspecialchars($row['Mobile_Number'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($row['Address']); ?></td>
                        <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($row['Joined_Date']))); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $statusClass; ?>">
                                <?php echo $row['Status']; ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                            <button class="action-btn edit-btn" onclick="event.stopPropagation(); openEditModal(
                                '<?php echo htmlspecialchars($row['MemberID']); ?>', 
                                '<?php echo htmlspecialchars(addslashes($row['Name'])); ?>', 
                                '<?php echo htmlspecialchars($row['NIC']); ?>', 
                                '<?php echo htmlspecialchars($row['DoB']); ?>', 
                                '<?php echo htmlspecialchars(addslashes($row['Address'])); ?>', 
                                '<?php echo htmlspecialchars($row['Mobile_Number'] ?? ''); ?>', 
                                '<?php echo htmlspecialchars($row['No_of_Family_Members'] ?? 0); ?>', 
                                '<?php echo htmlspecialchars($row['Other_Members'] ?? 0); ?>', 
                                '<?php echo htmlspecialchars($row['Status']); ?>'
                            )">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                                <button class="action-btn delete-btn" 
                                        onclick="event.stopPropagation(); openDeleteModal('<?php echo htmlspecialchars($row['MemberID']); ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php 
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="8" style="text-align: center;">No members found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if(empty($searchTerm) && $totalPages > 1): ?>
        <div class="pagination">
            <?php for($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?>" 
                   class="<?php echo $page === $i ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- View Member Details Modal -->
    <div id="viewDetailsModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close" onclick="closeViewModal()">&times;</span>
            <h2>Member Details</h2>
            <div id="memberDetailsContent" class="member-details-container">
                <!-- Content will be loaded via AJAX -->
                <div class="loading">Loading details...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="cancel-btn" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Edit Member</h2>
            <form id="editForm" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . ($searchTerm ? "?search=" . urlencode($searchTerm) : "")); ?>" enctype="multipart/form-data">
                <input type="hidden" id="edit_member_id" name="member_id">
                
                <div class="form-group">
                    <label for="edit_name">Name</label>
                    <input type="text" id="edit_name" name="name" required>
                </div>

                <div class="form-group">
                    <label for="edit_nic">NIC</label>
                    <input type="text" id="edit_nic" name="nic" required pattern="^\d{9}[vVxX]$|^\d{12}$">
                    <small>Format: 9 digits + V/X or 12 digits (e.g., 123456789V or 123456789012)</small>
                </div>

                <div class="form-group">
                    <label for="edit_dob">Date of Birth</label>
                    <input type="date" id="edit_dob" name="dob" required>
                </div>

                <div class="form-group">
                    <label for="edit_address">Address</label>
                    <input type="text" id="edit_address" name="address" required>
                </div>

                <div class="form-group">
                    <label for="edit_mobile">Mobile Number</label>
                    <input type="text" id="edit_mobile" name="mobile" placeholder="07XXXXXXXX" pattern="^(07\d{8}|\+947\d{8})$">
                    <small>Format: 07XXXXXXXX or +947XXXXXXXX</small>
                </div>

                <div class="form-group">
                    <label for="edit_family_members">Number of Family Members</label>
                    <input type="number" id="edit_family_members" name="family_members" min="0" value="0">
                </div>

                <div class="form-group">
                    <label for="edit_other_members">Other Members</label>
                    <input type="number" id="edit_other_members" name="other_members" min="0" value="0">
                </div>

                <div class="form-group">
                    <label for="edit_status">Status</label>
                    <select id="edit_status" name="status" required>
                        <option value="Full Member">Full Member</option>
                        <option value="Pending">Pending</option>
                        <option value="Left">Left</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_profile_photo">Update Profile Photo</label>
                    <input type="file" id="edit_profile_photo" name="profile_photo" accept="image/jpeg,image/jpg,image/png">
                    <p class="hint-text">(jpeg / jpg / png, 20MB max)</p>
                    
                    <div id="current_photo_container" style="margin-top: 10px;">
                        <p id="photo_status_text">Current Photo: <span id="load_status">(Loading...)</span></p>
                        <div id="current_photo" style="margin-top: 5px;"></div>
                    </div>
                </div>

                <div class="modal-footer button-group">
                    <button type="submit" name="update" class="save-btn">Save Changes</button>
                    <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this member? This action cannot be undone.</p>
            <form method="POST" id="deleteForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . ($searchTerm ? "?search=" . urlencode($searchTerm) : "")); ?>">
                <input type="hidden" id="delete_member_id" name="member_id">
                <div class="delete-modal-buttons button-group">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>
    </div>

    <script>
        // Enhanced openEditModal function to handle escaped quotes properly
        function openEditModal(memberId, name, nic, dob, address, mobile, familyMembers, otherMembers, status) {
            console.log("Opening edit modal for member:", memberId);
            
            const modal = document.getElementById('editModal');
            modal.style.display = 'block';
            
            // Set form values
            document.getElementById('edit_member_id').value = memberId;
            document.getElementById('edit_name').value = name.replace(/\\'/g, "'").replace(/\\"/g, '"');
            document.getElementById('edit_nic').value = nic;
            document.getElementById('edit_dob').value = dob;
            document.getElementById('edit_address').value = address.replace(/\\'/g, "'").replace(/\\"/g, '"');
            document.getElementById('edit_mobile').value = mobile || '';
            document.getElementById('edit_family_members').value = familyMembers || 0;
            document.getElementById('edit_other_members').value = otherMembers || 0;
            
            // Set the correct status in the dropdown
            const statusSelect = document.getElementById('edit_status');
            for (let i = 0; i < statusSelect.options.length; i++) {
                if (statusSelect.options[i].value === status) {
                    statusSelect.selectedIndex = i;
                    break;
                }
            }
            
            // Client-side validation for numbers
            const familyMembersInput = document.getElementById('edit_family_members');
            const otherMembersInput = document.getElementById('edit_other_members');
            
            // Ensure non-negative values for numeric fields
            familyMembersInput.addEventListener('input', function() {
                if (this.value < 0) this.value = 0;
            });
            
            otherMembersInput.addEventListener('input', function() {
                if (this.value < 0) this.value = 0;
            });
            
            // NIC validation
            const nicInput = document.getElementById('edit_nic');
            nicInput.addEventListener('input', function() {
                const nicValue = this.value;
                const isOldFormat = /^\d{9}[vVxX]$/.test(nicValue);
                const isNewFormat = /^\d{12}$/.test(nicValue);
                
                if (nicValue && !(isOldFormat || isNewFormat)) {
                    this.setCustomValidity('Invalid NIC format. Must be 9 digits followed by V/X or 12 digits.');
                } else {
                    this.setCustomValidity('');
                }
            });
            
            // Mobile number validation
            const mobileInput = document.getElementById('edit_mobile');
            mobileInput.addEventListener('input', function() {
                const mobileValue = this.value;
                if (mobileValue && !/^(07\d{8}|\+947\d{8})$/.test(mobileValue)) {
                    this.setCustomValidity('Invalid mobile number format. Must be 07XXXXXXXX or +947XXXXXXXX.');
                } else {
                    this.setCustomValidity('');
                }
            });
            
            // Check if there's a current photo and display it
            fetch(`getMemberPhoto.php?id=${memberId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.hasPhoto) {
                        const photoContainer = document.getElementById('current_photo_container');
                        const photoElement = document.getElementById('current_photo');
                        
                        photoElement.innerHTML = `<img src="../uploads/${data.photoName}" alt="Current Profile Photo" style="max-width: 120px; max-height: 120px; border-radius: 5px;">`;
                        photoContainer.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error fetching photo information:', error);
                });
        }
            
            // Client-side validation for numbers
            const familyMembersInput = document.getElementById('edit_family_members');
            const otherMembersInput = document.getElementById('edit_other_members');
            
            // Ensure non-negative values for numeric fields
            familyMembersInput.addEventListener('input', function() {
                if (this.value < 0) this.value = 0;
            });
            
            otherMembersInput.addEventListener('input', function() {
                if (this.value < 0) this.value = 0;
            });
            
            // NIC validation
            const nicInput = document.getElementById('edit_nic');
            nicInput.addEventListener('input', function() {
                const nicValue = this.value;
                const isOldFormat = /^\d{9}[vVxX]$/.test(nicValue);
                const isNewFormat = /^\d{12}$/.test(nicValue);
                
                if (nicValue && !(isOldFormat || isNewFormat)) {
                    this.setCustomValidity('Invalid NIC format. Must be 9 digits followed by V/X or 12 digits.');
                } else {
                    this.setCustomValidity('');
                }
            });
            
            // Mobile number validation
            const mobileInput = document.getElementById('edit_mobile');
            mobileInput.addEventListener('input', function() {
                const mobileValue = this.value;
                if (mobileValue && !/^(07\d{8}|\+947\d{8})$/.test(mobileValue)) {
                    this.setCustomValidity('Invalid mobile number format. Must be 07XXXXXXXX or +947XXXXXXXX.');
                } else {
                    this.setCustomValidity('');
                }
            });
            
            // Check if there's a current photo
            // You would need to implement an AJAX call to fetch this information
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openDeleteModal(memberId) {
            console.log("Opening delete modal for member:", memberId);
            
            const modal = document.getElementById('deleteModal');
            modal.style.display = 'block';
            document.getElementById('delete_member_id').value = memberId;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        function closeViewModal() {
            document.getElementById('viewDetailsModal').style.display = 'none';
        }

        // Function to view member details
        function viewMemberDetails(memberId) {
            const modal = document.getElementById('viewDetailsModal');
            const contentContainer = document.getElementById('memberDetailsContent');
            
            // Show loading state
            contentContainer.innerHTML = '<div class="loading">Loading details...</div>';
            modal.style.display = 'block';
            
            // Here you would typically fetch member details via AJAX
            // Simulating content for demo purposes
            setTimeout(() => {
                // You would replace this with actual AJAX call
                fetchMemberDetails(memberId);
            }, 500);
        }
        
        // Function to fetch member details via AJAX
        function fetchMemberDetails(memberId) {
            // In a real implementation, this would be an AJAX call
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `getMemberDetails.php?id=${memberId}`, true);
            xhr.onload = function() {
                if (this.status === 200) {
                    document.getElementById('memberDetailsContent').innerHTML = this.responseText;
                    
                    // Check if photo display needs to be updated based on the response
                    const photoElement = document.querySelector('#memberDetailsContent img');
                    if (photoElement && photoElement.src.includes('noimage.png')) {
                        photoElement.parentElement.innerHTML = '<div class="no-photo">No Photo Available</div>';
                    }
                } else {
                    document.getElementById('memberDetailsContent').innerHTML = '<div class="error">Failed to load member details.</div>';
                }
            };
            xhr.onerror = function() {
                document.getElementById('memberDetailsContent').innerHTML = '<div class="error">Error connecting to server.</div>';
            };
            xhr.send();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const viewModal = document.getElementById('viewDetailsModal');
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target == viewModal) {
                closeViewModal();
            }
            
            if (event.target == editModal) {
                closeModal();
            }
            
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        }
        
        // Additional validation on form submission
        document.getElementById('editForm').addEventListener('submit', function(event) {
            const nicInput = document.getElementById('edit_nic');
            const mobileInput = document.getElementById('edit_mobile');
            const familyMembersInput = document.getElementById('edit_family_members');
            const otherMembersInput = document.getElementById('edit_other_members');
            
            let isValid = true;
            
            // Validate NIC format
            const nicValue = nicInput.value;
            const isOldFormat = /^\d{9}[vVxX]$/.test(nicValue);
            const isNewFormat = /^\d{12}$/.test(nicValue);
            
            if (!(isOldFormat || isNewFormat)) {
                alert('Invalid NIC format. Must be 9 digits followed by V/X or 12 digits.');
                isValid = false;
            }
            
            // Validate mobile number if provided
            const mobileValue = mobileInput.value;
            if (mobileValue && !/^(07\d{8}|\+947\d{8})$/.test(mobileValue)) {
                alert('Invalid mobile number format. Must be 07XXXXXXXX or +947XXXXXXXX.');
                isValid = false;
            }
            
            // Ensure non-negative values for numeric fields
            if (parseInt(familyMembersInput.value) < 0) {
                alert('Number of family members cannot be negative.');
                isValid = false;
            }
            
            if (parseInt(otherMembersInput.value) < 0) {
                alert('Number of other members cannot be negative.');
                isValid = false;
            }
            
            if (!isValid) {
                event.preventDefault();
            }
        });
    </script>
</body>
</html>