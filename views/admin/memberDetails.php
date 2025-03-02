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

// Function to check if member has associated records
function memberHasAssociations($conn, $memberId) {
    // Check for loans
    $checkLoans = "SELECT COUNT(*) as count FROM Loan WHERE Member_MemberID = ?";
    $stmt = $conn->prepare($checkLoans);
    $stmt->bind_param("s", $memberId);
    $stmt->execute();
    $loanResult = $stmt->get_result();
    $hasLoans = $loanResult->fetch_assoc()['count'] > 0;
    
    // Check for payments
    $checkPayments = "SELECT COUNT(*) as count FROM Payment WHERE Member_MemberID = ?";
    $stmt = $conn->prepare($checkPayments);
    $stmt->bind_param("s", $memberId);
    $stmt->execute();
    $paymentResult = $stmt->get_result();
    $hasPayments = $paymentResult->fetch_assoc()['count'] > 0;
    
    return $hasLoans || $hasPayments;
}

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
            
            $fileName = $memberId . '_' . time() . '.' . pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
            $uploadPath = "../uploads/" . $fileName;
            
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
}

// Handle Delete
if(isset($_POST['delete'])) {
    $memberId = trim($_POST['member_id']);
    
    // Check for associated records
    if(memberHasAssociations($conn, $memberId)) {
        $deleteError = "Cannot delete this member. They have associated loans or payments.";
    } else {
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
    
    /* Button styling fix for mobile */
    .button-group {
        display: flex;
        gap: 1rem;
        margin-top: 2rem;
        flex-wrap: wrap;
        width: 100%;
    }

    .btn {
        padding: 1rem 2rem;
        border: none;
        border-radius: 6px;
        font-size: 1rem;
        cursor: pointer;
        font-weight: 500;
        text-align: center;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 1;
        max-width: 100%;
        box-sizing: border-box;
    }
    
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
    <div class="main-container" style="min-height: 100vh; background: #f5f7fa; padding: 2rem;">
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
                    ?>
                    <tr data-id="<?php echo htmlspecialchars($row['MemberID']); ?>" class="member-row" style="cursor: pointer;" onclick="viewMemberDetails('<?php echo htmlspecialchars($row['MemberID']); ?>')">
                        <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                        <td><?php echo htmlspecialchars($row['Name']); ?></td>
                        <td><?php echo htmlspecialchars($row['NIC']); ?></td>
                        <td><?php echo htmlspecialchars($row['Mobile_Number'] ?? '-'); ?></td>
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
                    <input type="text" id="edit_mobile" name="mobile" placeholder="07XXXXXXXX">
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
            document.getElementById('edit_status').value = status;
            
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
    </script>
</body>
</html>