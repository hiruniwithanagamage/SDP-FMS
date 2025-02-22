<?php
session_start();
require_once "../../config/database.php";

// Check if user is logged in and is admin
if (!isset($_SESSION["u"]) || $_SESSION["role"] !== "admin") {
    header("Location: login.php");
    exit();
}

// Fetch all members with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$recordsPerPage = 10;
$offset = ($page - 1) * $recordsPerPage;

$query = "SELECT * FROM Member ORDER BY MemberID ASC LIMIT $offset, $recordsPerPage";
$result = Database::search($query);

// Get total number of records for pagination
$totalRecordsQuery = "SELECT COUNT(*) as count FROM Member";
$totalRecords = Database::search($totalRecordsQuery)->fetch_assoc()['count'];
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
    
    // Modify the update query section to properly handle NULL values
    $updateQuery = "UPDATE Member SET 
                    Name = '" . Database::$connection->real_escape_string($name) . "',
                    NIC = '" . Database::$connection->real_escape_string($nic) . "',
                    DoB = '" . Database::$connection->real_escape_string($dob) . "',
                    Address = '" . Database::$connection->real_escape_string($address) . "',
                    Mobile_Number = " . ($mobile ? "'" . Database::$connection->real_escape_string($mobile) . "'" : "NULL") . ",
                    No_of_Family_Members = " . (int)$familyMembers . ",
                    Other_Members = " . (int)$otherMembers . ",
                    Status = '" . Database::$connection->real_escape_string($status) . "'
                    WHERE MemberID = '" . Database::$connection->real_escape_string($memberId) . "'";
    
    try {
        Database::iud($updateQuery);
        $updateSuccess = "Member updated successfully!";
        header("Location: " . $_SERVER['PHP_SELF'] . "?update=success");
        exit();
    } catch(Exception $e) {
        $updateError = "Error updating member: " . $e->getMessage();
    }
}

// Handle Delete
if(isset($_POST['delete'])) {
    $memberId = $_POST['member_id'];
    
    // First check for loans and payments
    $checkLoans = "SELECT COUNT(*) as count FROM Loan WHERE Member_MemberID = '$memberId'";
    $checkPayments = "SELECT COUNT(*) as count FROM Payment WHERE Member_MemberID = '$memberId'";
    
    $loanResult = Database::search($checkLoans);
    $paymentResult = Database::search($checkPayments);
    
    $hasLoans = $loanResult->fetch_assoc()['count'] > 0;
    $hasPayments = $paymentResult->fetch_assoc()['count'] > 0;
    
    if($hasLoans || $hasPayments) {
        $deleteError = "Cannot delete this member. They have associated loans or payments.";
    } else {
        try {
            // Start transaction
            Database::iud("START TRANSACTION");
            
            // First delete the user record
            $deleteUserQuery = "DELETE FROM User WHERE Member_MemberID = '$memberId'";
            Database::iud($deleteUserQuery);
            
            // Then delete the member
            $deleteMemberQuery = "DELETE FROM Member WHERE MemberID = '$memberId'";
            Database::iud($deleteMemberQuery);
            
            // Commit transaction
            Database::iud("COMMIT");
            
            $deleteSuccess = "Member deleted successfully!";
        } catch(Exception $e) {
            // Rollback on error
            Database::iud("ROLLBACK");
            $deleteError = "Error deleting member: " . $e->getMessage();
        }
    }
}

// Handle Search
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
if($searchTerm) {
    $query = "SELECT * FROM Member 
              WHERE Name LIKE '%$searchTerm%' 
              OR NIC LIKE '%$searchTerm%' 
              OR MemberID LIKE '%$searchTerm%'
              ORDER BY MemberID ASC";
    $result = Database::search($query);
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

        <?php if(isset($updateSuccess) || isset($deleteSuccess)): ?>
            <div class="alert alert-success">
                <?php 
                    echo isset($updateSuccess) ? $updateSuccess : '';
                    echo isset($deleteSuccess) ? $deleteSuccess : '';
                ?>
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
                    <tr>
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
                            <button class="action-btn edit-btn" onclick="openEditModal(
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
                                        onclick="openDeleteModal('<?php echo $row['MemberID']; ?>')">
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

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Edit Member</h2>
            <form id="editForm" method="POST">
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

    <script>
        function openEditModal(id, name, nic, dob, address, mobile, familyMembers, otherMembers, status) {
            document.getElementById('editModal').style.display = 'block';
            document.getElementById('edit_member_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_nic').value = nic;
            document.getElementById('edit_dob').value = dob;
            document.getElementById('edit_address').value = address;
            document.getElementById('edit_mobile').value = mobile;
            document.getElementById('edit_family_members').value = familyMembers;
            document.getElementById('edit_other_members').value = otherMembers;
            document.getElementById('edit_status').value = status;
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openDeleteModal(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_member_id').value = id;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal')) {
                closeModal();
            }
            if (event.target == document.getElementById('deleteModal')) {
                closeDeleteModal();
            }
        }
    </script>
</body>
</html>