<?php
session_start();
require_once "../../config/database.php";

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

// Fetch all members with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$recordsPerPage = 10;
$offset = ($page - 1) * $recordsPerPage;

$query = "SELECT * FROM Member ORDER BY MemberID ASC LIMIT $offset, $recordsPerPage";
$result = search($query);

// Get total number of records for pagination
$totalRecordsQuery = "SELECT COUNT(*) as count FROM Member";
$totalRecords = search($totalRecordsQuery)->fetch_assoc()['count'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Handle Update
if(isset($_POST['update'])) {
    $memberId = $_POST['member_id'];
    $name = $_POST['name'];
    $nic = $_POST['nic'];
    $dob = $_POST['dob'];
    $address = $_POST['address'];
    $mobile = !empty($_POST['mobile']) ? trim($_POST['mobile']) : null;
    
    // Convert empty values to NULL or 0 for numeric fields
    $familyMembers = empty($_POST['family_members']) ? 0 : (int)$_POST['family_members'];
    $otherMembers = empty($_POST['other_members']) ? 0 : (int)$_POST['other_members'];
    $status = $_POST['status'];
    
    // Get database connection for escaping strings
    $conn = getConnection();
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Update basic member info
        $updateQuery = "UPDATE Member SET 
                        Name = '" . $conn->real_escape_string($name) . "',
                        NIC = '" . $conn->real_escape_string($nic) . "',
                        DoB = '" . $conn->real_escape_string($dob) . "',
                        Address = '" . $conn->real_escape_string($address) . "',
                        Mobile_Number = " . ($mobile ? "'" . $conn->real_escape_string($mobile) . "'" : "NULL") . ",
                        No_of_Family_Members = " . (int)$familyMembers . ",
                        Other_Members = " . (int)$otherMembers . ",
                        Status = '" . $conn->real_escape_string($status) . "'
                        WHERE MemberID = '" . $conn->real_escape_string($memberId) . "'";
        
        iud($updateQuery);
        
        // Handle file upload if exists
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === 0) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
            $maxSize = 20 * 1024 * 1024; // 20MB
            
            if (!in_array($_FILES['profile_photo']['type'], $allowedTypes)) {
                throw new Exception("Only JPG, JPEG & PNG files are allowed");
            } elseif ($_FILES['profile_photo']['size'] > $maxSize) {
                throw new Exception("File size must be less than 20MB");
            }
            
            $fileName = $memberId . '_' . time() . '.' . pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
            $uploadPath = "../uploads/" . $fileName;
            
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadPath)) {
                // Update member record with new image path
                $updateImageQuery = "UPDATE Member SET Image = '" . $conn->real_escape_string($fileName) . "' 
                                     WHERE MemberID = '" . $conn->real_escape_string($memberId) . "'";
                iud($updateImageQuery);
            } else {
                throw new Exception("Failed to upload image");
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // $updateSuccess = "Member updated successfully!";
        $_SESSION['success_message'] = "Member updated successfully";
        header("Location: " . $_SERVER['PHP_SELF'] . "?update=success");
        exit();
    } catch(Exception $e) {
        // Rollback on error
        $conn->rollback();
        $updateError = "Error updating member: " . $e->getMessage();
    }
}

// Handle Delete
if(isset($_POST['delete'])) {
    $memberId = $_POST['member_id'];
    
    // First check for loans and payments
    $conn = getConnection();
    $checkLoans = "SELECT COUNT(*) as count FROM Loan WHERE Member_MemberID = '" . $conn->real_escape_string($memberId) . "'";
    $checkPayments = "SELECT COUNT(*) as count FROM Payment WHERE Member_MemberID = '" . $conn->real_escape_string($memberId) . "'";
    
    $loanResult = search($checkLoans);
    $paymentResult = search($checkPayments);
    
    $hasLoans = $loanResult->fetch_assoc()['count'] > 0;
    $hasPayments = $paymentResult->fetch_assoc()['count'] > 0;
    
    if($hasLoans || $hasPayments) {
        $deleteError = "Cannot delete this member. They have associated loans or payments.";
    } else {
        try {
            // Get database connection for transaction
            $conn = getConnection();
            
            // Start transaction
            $conn->begin_transaction();
            
            // First delete the user record
            $deleteUserQuery = "DELETE FROM User WHERE Member_MemberID = '$memberId'";
            iud($deleteUserQuery);
            
            // Then delete the member
            $deleteMemberQuery = "DELETE FROM Member WHERE MemberID = '$memberId'";
            iud($deleteMemberQuery);
            
            // Commit transaction
            $conn->commit();
            
            // $deleteSuccess = "Member deleted successfully!";
            $_SESSION['success_message'] = "Member deleted successfully";
            header("Location: " . $_SERVER['PHP_SELF']);
        } catch(Exception $e) {
            // Rollback on error
            $conn = getConnection();
            $conn->rollback();
            $deleteError = "Error deleting member: " . $e->getMessage();
        }
    }
}

// Handle Search
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
if($searchTerm) {
    // Get database connection for escaping search term
    $conn = getConnection();
    $escapedSearchTerm = $conn->real_escape_string($searchTerm);
    
    $query = "SELECT * FROM Member 
              WHERE Name LIKE '%$escapedSearchTerm%' 
              OR NIC LIKE '%$escapedSearchTerm%' 
              OR MemberID LIKE '%$escapedSearchTerm%'
              ORDER BY MemberID ASC";
    $result = search($query);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Members</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminActorDetails.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
    <script src="../../assets/js/memberDetails.js"></script>
    <style>
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
    
    .member-row:hover {
        background-color: #f5f5f5;
    }
</style>
</head>
<body>
    <div class="main-container" style="min-height: 100vh; background: #f5f7fa; padding: 2rem;">
    <?php include '../templates/navbar-admin.php'; ?>
    <div class="container">
        <div class="header-section">
            <h1>Manage Members</h1>
            <div class="search-section">
                <form action="" method="GET" style="display: flex; gap: 1rem;">
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
                    echo isset($updateError) ? $updateError : '';
                    echo isset($deleteError) ? $deleteError : '';
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
                    ?>
                    <tr data-id="<?php echo $row['MemberID']; ?>" class="member-row" style="cursor: pointer;" onclick="viewMemberDetails('<?php echo $row['MemberID']; ?>')">
                        <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                        <td><?php echo htmlspecialchars($row['Name']); ?></td>
                        <td><?php echo htmlspecialchars($row['NIC']); ?></td>
                        <td><?php echo htmlspecialchars($row['Mobile_Number']); ?></td>
                        <td><?php echo htmlspecialchars($row['Address']); ?></td>
                        <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($row['Joined_Date']))); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $row['Status'] === 'TRUE' ? 'active' : 'pending'; ?>">
                                <?php echo $row['Status'] === 'TRUE' ? 'Full Member' : 'Pending'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                            <button class="action-btn edit-btn" onclick="event.stopPropagation(); openEditModal(
                                '<?php echo $row['MemberID']; ?>', 
                                '<?php echo $row['Name']; ?>', 
                                '<?php echo $row['NIC']; ?>', 
                                '<?php echo $row['DoB']; ?>', 
                                '<?php echo $row['Address']; ?>', 
                                '<?php echo $row['Mobile_Number']; ?>', 
                                '<?php echo $row['No_of_Family_Members']; ?>', 
                                '<?php echo $row['Other_Members']; ?>', 
                                '<?php echo $row['Status']; ?>'
                            )">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                                <button class="action-btn delete-btn" 
                                        onclick="event.stopPropagation(); openDeleteModal('<?php echo $row['MemberID']; ?>')">
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

        <?php if(!$searchTerm): ?>
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
                <!-- <button type="button" class="edit-btn" onclick="openEditModalFromView()" style="background-color: #1a237e; color: white;">
                    <i class="fas fa-edit"></i> Edit Member
                </button> -->
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Edit Member</h2>
            <form id="editForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="edit_member_id" name="member_id">
                
                <div class="form-group">
                    <label for="edit_name">Name</label>
                    <input type="text" id="edit_name" name="name" required>
                </div>

                <div class="form-group">
                    <label for="edit_nic">NIC</label>
                    <input type="text" id="edit_nic" name="nic" required>
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
                    <input type="text" id="edit_mobile" name="mobile">
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
                        <option value="TRUE">Full Member</option>
                        <option value="FAIL">Pending</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_profile_photo">Update Profile Photo</label>
                    <input type="file" id="edit_profile_photo" name="profile_photo" accept="image/*">
                    <p class="hint-text">(jpeg / jpg / png, 20MB max)</p>
                    
                    <div id="current_photo_container" style="margin-top: 10px; display: none;">
                        <label>Current Photo:</label>
                        <div id="current_photo" style="margin-top: 5px;"></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="update" class="save-btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this member? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" id="delete_member_id" name="member_id">
                <div class="delete-modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>
    </div>
</body>
</html>