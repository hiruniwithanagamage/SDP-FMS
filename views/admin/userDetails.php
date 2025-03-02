<?php
session_start();
require_once "../../config/database.php";

// Get database connection
$conn = getConnection(); // Assuming this function exists in database.php

// Check for success message
$successMessage = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : null;

// Clear the session message after retrieving it
if($successMessage) {
    unset($_SESSION['success_message']);
}

// Default sorting
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'all';

// Build dynamic query based on sorting
$baseQuery = "SELECT u.*, 
              COALESCE(a.Name, '') AS AdminName, 
              COALESCE(au.Name, '') AS AuditorName, 
              COALESCE(t.Name, '') AS TreasurerName, 
              COALESCE(m.Name, '') AS MemberName,
              CASE 
                  WHEN u.Admin_AdminID IS NOT NULL THEN 'Admin'
                  WHEN u.Auditor_AuditorID IS NOT NULL THEN 'Auditor'
                  WHEN u.Treasurer_TreasurerID IS NOT NULL THEN 'Treasurer'
                  WHEN u.Member_MemberID IS NOT NULL THEN 'Member'
                  ELSE 'Unassigned'
              END AS UserRole
              FROM User u
              LEFT JOIN Admin a ON u.Admin_AdminID = a.AdminID
              LEFT JOIN Auditor au ON u.Auditor_AuditorID = au.AuditorID
              LEFT JOIN Treasurer t ON u.Treasurer_TreasurerID = t.TreasurerID
              LEFT JOIN Member m ON u.Member_MemberID = m.MemberID";

// Add WHERE clause for filtering
$whereClause = "";
if ($sortBy !== 'all') {
    switch($sortBy) {
        case 'admin':
            $whereClause = " WHERE u.Admin_AdminID IS NOT NULL";
            break;
        case 'auditor':
            $whereClause = " WHERE u.Auditor_AuditorID IS NOT NULL";
            break;
        case 'treasurer':
            $whereClause = " WHERE u.Treasurer_TreasurerID IS NOT NULL";
            break;
        case 'member':
            $whereClause = " WHERE u.Member_MemberID IS NOT NULL";
            break;
        case 'unassigned':
            $whereClause = " WHERE u.Admin_AdminID IS NULL 
                            AND u.Auditor_AuditorID IS NULL 
                            AND u.Treasurer_TreasurerID IS NULL 
                            AND u.Member_MemberID IS NULL";
            break;
    }
}

$baseQuery .= $whereClause . " ORDER BY u.UserId";

// Execute the query with prepared statement
$stmt = $conn->prepare($baseQuery);
$stmt->execute();
$result = $stmt->get_result();

// Function to fetch role options with prepared statements
function fetchRoleOptions($conn, $tableName, $idColumn, $nameColumn) {
    $query = "SELECT $idColumn, $nameColumn FROM $tableName";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->get_result();
}

// Fetch role options for the edit modal
$adminResult = fetchRoleOptions($conn, "Admin", "AdminID", "Name");
$auditorResult = fetchRoleOptions($conn, "Auditor", "AuditorID", "Name");
$treasurerResult = fetchRoleOptions($conn, "Treasurer", "TreasurerID", "Name");
$memberResult = fetchRoleOptions($conn, "Member", "MemberID", "Name");

// Handle Update
if(isset($_POST['update'])) {
    $userId = $_POST['user_id'];
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $roleId = $_POST['role_id'];
    
    // Set the appropriate role ID and null for others
    $adminId = ($role === 'admin') ? $roleId : null;
    $auditorId = ($role === 'auditor') ? $roleId : null;
    $treasurerId = ($role === 'treasurer') ? $roleId : null;
    $memberId = ($role === 'member') ? $roleId : null;

    // Validate email format
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } else {
        try {
            // Prepare the update statement
            $updateQuery = "UPDATE User SET 
                        Username = ?, 
                        Email = ?,
                        Admin_AdminID = ?,
                        Auditor_AuditorID = ?,
                        Treasurer_TreasurerID = ?,
                        Member_MemberID = ?
                        WHERE UserId = ?";
            
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("sssssss", 
                $username, 
                $email, 
                $adminId, 
                $auditorId, 
                $treasurerId, 
                $memberId,
                $userId
            );
            
            $stmt->execute();
            
            if($stmt->affected_rows > 0) {
                $_SESSION['success_message'] = "User updated successfully";
            } else {
                $_SESSION['success_message'] = "No changes were made";
            }
            
            header("Location: " . $_SERVER['PHP_SELF'] . ($sortBy !== 'all' ? "?sort=$sortBy" : ""));
            exit();
        } catch(Exception $e) {
            $error = "Error updating user: " . $e->getMessage();
        }
    }
}

// Handle Delete
if(isset($_POST['delete'])) {
    $userId = $_POST['user_id'];
    
    try {
        // Use prepared statement for deletion
        $deleteQuery = "DELETE FROM User WHERE UserId = ?";
        $stmt = $conn->prepare($deleteQuery);
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        
        if($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "User deleted successfully";
        } else {
            $deleteError = "User not found or already deleted";
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . ($sortBy !== 'all' ? "?sort=$sortBy" : ""));
        exit();
    } catch(Exception $e) {
        $deleteError = "Cannot delete this user. They may have associated records.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
    <script>
        // Role-specific options
        const roleOptions = {
            admin: [
                <?php 
                $adminResult->data_seek(0); // Reset result pointer
                while($admin = $adminResult->fetch_assoc()) {
                    echo "{id: '" . addslashes($admin['AdminID']) . "', name: '" . addslashes($admin['Name']) . "'},";
                }
                ?>
            ],
            auditor: [
                <?php 
                $auditorResult->data_seek(0); // Reset result pointer
                while($auditor = $auditorResult->fetch_assoc()) {
                    echo "{id: '" . addslashes($auditor['AuditorID']) . "', name: '" . addslashes($auditor['Name']) . "'},";
                }
                ?>
            ],
            treasurer: [
                <?php 
                $treasurerResult->data_seek(0); // Reset result pointer
                while($treasurer = $treasurerResult->fetch_assoc()) {
                    echo "{id: '" . addslashes($treasurer['TreasurerID']) . "', name: '" . addslashes($treasurer['Name']) . "'},";
                }
                ?>
            ],
            member: [
                <?php 
                $memberResult->data_seek(0); // Reset result pointer
                while($member = $memberResult->fetch_assoc()) {
                    echo "{id: '" . addslashes($member['MemberID']) . "', name: '" . addslashes($member['Name']) . "'},";
                }
                ?>
            ]
        };

        function updateRoleOptions() {
            const role = document.getElementById('edit_role').value;
            const roleIdSelect = document.getElementById('edit_role_id');
            
            // Clear existing options
            roleIdSelect.innerHTML = '';
            
            // Populate with new options
            if (roleOptions[role] && roleOptions[role].length > 0) {
                roleOptions[role].forEach(option => {
                    const optionElement = document.createElement('option');
                    optionElement.value = option.id;
                    optionElement.textContent = option.name;
                    roleIdSelect.appendChild(optionElement);
                });
            } else {
                // If no options are available for this role
                const optionElement = document.createElement('option');
                optionElement.value = "";
                optionElement.textContent = "No options available";
                roleIdSelect.appendChild(optionElement);
            }
        }

        function openEditModal(userId, username, email, role, roleId) {
            const modal = document.getElementById('editModal');
            modal.style.display = 'block';
            
            // Set form values
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_email').value = email;
            
            // Set role and trigger update of role options
            const roleSelect = document.getElementById('edit_role');
            roleSelect.value = role;
            updateRoleOptions();
            
            // Set associated role ID
            setTimeout(() => {
                const roleIdSelect = document.getElementById('edit_role_id');
                if (roleIdSelect.querySelector(`option[value="${roleId}"]`)) {
                    roleIdSelect.value = roleId;
                }
            }, 100); // Small delay to ensure options are loaded
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openDeleteModal(userId) {
            const modal = document.getElementById('deleteModal');
            modal.style.display = 'block';
            document.getElementById('delete_user_id').value = userId;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target == editModal) {
                closeModal();
            }
            
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        }

        // Initialize role options on page load
        document.addEventListener('DOMContentLoaded', updateRoleOptions);
    </script>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-admin.php'; ?>
        
        <div class="container">
            <div class="header-section">
                <h1>Manage Users</h1>
                <a href="addUser.php" class="add-btn">
                    <i class="fas fa-plus"></i> Add User
                </a>
            </div>

            <!-- Sorting Section -->
            <div class="filter-section">
                <form method="GET" action="">
                    <select name="sort" onchange="this.form.submit()" class="filter-input">
                        <option value="all" <?php echo $sortBy == 'all' ? 'selected' : ''; ?>>All Users</option>
                        <option value="admin" <?php echo $sortBy == 'admin' ? 'selected' : ''; ?>>Admins</option>
                        <option value="auditor" <?php echo $sortBy == 'auditor' ? 'selected' : ''; ?>>Auditors</option>
                        <option value="treasurer" <?php echo $sortBy == 'treasurer' ? 'selected' : ''; ?>>Treasurers</option>
                        <option value="member" <?php echo $sortBy == 'member' ? 'selected' : ''; ?>>Members</option>
                        <option value="unassigned" <?php echo $sortBy == 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                    </select>
                </form>
            </div>

            <?php if($successMessage): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if(isset($deleteError)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($deleteError); ?></div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="auditor-table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Associated Name</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($result->num_rows > 0) {
                            while($row = $result->fetch_assoc()): 
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['UserId']); ?></td>
                            <td><?php echo htmlspecialchars($row['Username']); ?></td>
                            <td><?php echo htmlspecialchars($row['Email']); ?></td>
                            <td>
                                <?php 
                                $role = $row['Admin_AdminID'] ? 'Admin' : 
                                        ($row['Auditor_AuditorID'] ? 'Auditor' : 
                                        ($row['Treasurer_TreasurerID'] ? 'Treasurer' : 
                                        ($row['Member_MemberID'] ? 'Member' : 'Unassigned')));
                                echo htmlspecialchars($role);
                                ?>
                            </td>
                            <td>
                                <?php 
                                $name = $row['AdminName'] ?: 
                                        ($row['AuditorName'] ?: 
                                        ($row['TreasurerName'] ?: 
                                        ($row['MemberName'] ?: 'N/A')));
                                echo htmlspecialchars($name);
                                ?>
                            </td>
                            <td>
                                <?php 
                                echo isset($row['last_login']) && $row['last_login'] ? 
                                    htmlspecialchars(date('Y-m-d H:i', strtotime($row['last_login']))) : 
                                    'Never';
                                ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick="openEditModal(
                                        '<?php echo htmlspecialchars($row['UserId']); ?>',
                                        '<?php echo htmlspecialchars(addslashes($row['Username'])); ?>',
                                        '<?php echo htmlspecialchars($row['Email']); ?>',
                                        '<?php echo $row['Admin_AdminID'] ? 'admin' : 
                                                 ($row['Auditor_AuditorID'] ? 'auditor' : 
                                                 ($row['Treasurer_TreasurerID'] ? 'treasurer' : 
                                                 ($row['Member_MemberID'] ? 'member' : ''))); ?>',
                                        '<?php echo htmlspecialchars($row['Admin_AdminID'] ?: 
                                                 ($row['Auditor_AuditorID'] ?: 
                                                 ($row['Treasurer_TreasurerID'] ?: 
                                                 ($row['Member_MemberID'] ?: '')))); ?>'
                                    )">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn delete-btn" onclick="openDeleteModal('<?php echo htmlspecialchars($row['UserId']); ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php 
                            endwhile; 
                        } else {
                        ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">No users found</td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Edit User</h2>
                <?php if(isset($updateError)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($updateError); ?></div>
                <?php endif; ?>
                <form id="editForm" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <input type="hidden" id="edit_user_id" name="user_id">
                    
                    <div class="form-group">
                        <label for="edit_username">Username</label>
                        <input type="text" id="edit_username" name="username" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_role">Role</label>
                        <select id="edit_role" name="role" required onchange="updateRoleOptions()">
                            <option value="admin">Admin</option>
                            <option value="auditor">Auditor</option>
                            <option value="treasurer">Treasurer</option>
                            <option value="member">Member</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit_role_id">Associated Name</label>
                        <select id="edit_role_id" name="role_id" required>
                            <!-- Options will be dynamically populated -->
                        </select>
                    </div>

                    <div class="modal-footer">
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
                <p>Are you sure you want to delete this user? This action cannot be undone.</p>
                <form method="POST" id="deleteForm">
                    <input type="hidden" id="delete_user_id" name="user_id">
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