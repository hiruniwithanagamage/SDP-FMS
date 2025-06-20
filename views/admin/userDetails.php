<?php
session_start();
require_once "../../config/database.php";

// Get database connection
$conn = getConnection(); 

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

// Handle Email Update
if(isset($_POST['update'])) {
    $userId = $_POST['user_id'];
    $email = trim($_POST['email']);
    
    // Validate inputs
    $errors = [];
    
    // Validate email format if provided
    if(empty($email)) {
        $errors[] = "Email is required";
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }

    // Additional email validation
    if(!empty($email)) {
        // Check email length
        if(strlen($email) > 100) {
            $errors[] = "Email address is too long (maximum 100 characters)";
        }
        
        // Check domain validity
        $domain = substr(strrchr($email, "@"), 1);
        if(!checkdnsrr($domain, "MX")) {
            $errors[] = "Email domain appears to be invalid";
        }
    }
    
    if(empty($errors)) {
        try {
            // Prepare the update statement - only update email field
            $updateQuery = "UPDATE User SET Email = ? WHERE UserId = ?";
            
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("ss", $email, $userId);
            
            $stmt->execute();
            
            if($stmt->affected_rows > 0) {
                $_SESSION['success_message'] = "Email updated successfully";
            } else {
                $_SESSION['success_message'] = "No changes were made";
            }
            
            header("Location: " . $_SERVER['PHP_SELF'] . ($sortBy !== 'all' ? "?sort=$sortBy" : ""));
            exit();
        } catch(Exception $e) {
            $error = "Error updating email: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="../../assets/js/alertHandler.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
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

        .add-btn {
            background-color: #1a237e;
            color: white;
            padding: 0.7rem 1.5rem;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.3s;
        }

        .add-btn:hover {
            background-color: #0d1757;
        }

        .filter-section {
            margin-bottom: 1.5rem;
        }

        .filter-input {
            padding: 0.7rem;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            width: 200px;
            font-size: 0.9rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .auditor-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
        }

        .auditor-table th {
            background-color: #f5f7fa;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #1a237e;
            border-bottom: 2px solid #e0e0e0;
        }

        .auditor-table td {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .auditor-table tr:hover {
            background-color: #f5f7fa;
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
            background-color: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            width: 80%;
            max-width: 500px;
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

        .form-group input {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            border-color: #1a237e;
            outline: none;
        }

        .form-group small {
            display: block;
            margin-top: 0.3rem;
            color: #666;
            font-size: 0.8rem;
        }

        .form-group .readonly-field {
            background-color: #f5f7fa;
            cursor: not-allowed;
        }

        .modal-footer {
            margin-top: 2rem;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .save-btn, .confirm-delete-btn {
            background-color: #1a237e;
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background-color 0.3s;
        }

        .save-btn:hover, .confirm-delete-btn:hover {
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
    </style>
    <script>
        function openEditModal(userId, username, email) {
            const modal = document.getElementById('editModal');
            modal.style.display = 'block';
            
            // Set form values
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_email').value = email;
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
                                        '<?php echo htmlspecialchars($row['Email']); ?>'
                                    )">
                                        <i class="fas fa-edit"></i> Edit Email
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

        <!-- Edit Email Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Edit User Email</h2>
                <?php if(isset($updateError)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($updateError); ?></div>
                <?php endif; ?>
                <form id="editForm" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . ($sortBy !== 'all' ? "?sort=$sortBy" : "")); ?>">
                    <input type="hidden" id="edit_user_id" name="user_id">
                    
                    <div class="form-group">
                        <label for="edit_username">Username</label>
                        <input type="text" id="edit_username" class="readonly-field" readonly>
                        <small>Username cannot be changed</small>
                    </div>

                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email" required>
                        <small>Enter a valid email address with proper domain</small>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" name="update" class="save-btn">Update Email</button>
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
                <form method="POST" id="deleteForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . ($sortBy !== 'all' ? "?sort=$sortBy" : "")); ?>">
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