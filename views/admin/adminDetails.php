<?php
session_start();
require_once "../../config/database.php";

// Check for success message
$successMessage = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : null;

// Clear the session message after retrieving it
if($successMessage) {
    unset($_SESSION['success_message']);
}

// Error message handling
$errorMessage = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : null;
if($errorMessage) {
    unset($_SESSION['error_message']);
}

// Get database connection
$conn = getConnection();

// Check if admin is used in User table
function isAdminInUse($conn, $adminId) {
    $query = "SELECT UserId FROM User WHERE Admin_AdminID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Check if name already exists for another admin
function checkDuplicateName($conn, $name, $adminId) {
    $query = "SELECT AdminID FROM Admin WHERE Name = ? AND AdminID != ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $name, $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Fetch all admins
$query = "SELECT * FROM Admin ORDER BY AdminID";
$result = search($query);

// Handle Update
if(isset($_POST['update'])) {
    $adminId = $_POST['admin_id'];
    $name = trim($_POST['name']);
    $contactNumber = trim($_POST['contact_number']);
    
    // Validate inputs
    $errors = [];
    
    if(empty($name)) $errors[] = "Name is required";
    if(empty($contactNumber)) $errors[] = "Contact Number is required";
    
    // Additional validation for Sri Lankan contact number
    // Sri Lankan mobile numbers can be in formats:
    // - 07XXXXXXXX (10 digits)
    // - +947XXXXXXXX (12 digits with +94 prefix)
    // - 947XXXXXXXX (11 digits with 94 prefix)
    
    // Remove any spaces or dashes that might be in the number
    $contactNumber = preg_replace('/[\s-]/', '', $contactNumber);
    
    if(preg_match('/^\+947\d{8}$/', $contactNumber)) {
        // Format: +947XXXXXXXX - valid
        // Convert to standard format without + for storage
        $contactNumber = substr($contactNumber, 1);
    } else if(preg_match('/^947\d{8}$/', $contactNumber)) {
        // Format: 947XXXXXXXX - valid, already in standard format
    } else if(preg_match('/^07\d{8}$/', $contactNumber)) {
        // Format: 07XXXXXXXX - valid
        // Convert to standard format with country code
        $contactNumber = '94' . substr($contactNumber, 1);
    } else {
        $errors[] = "Invalid Sri Lankan mobile number. Use format: 07XXXXXXXX, +947XXXXXXXX, or 947XXXXXXXX";
    }
    
    // Check for duplicate name
    if(empty($errors) && checkDuplicateName($conn, $name, $adminId)) {
        $errors[] = "An admin with this name already exists";
    }
    
    if(empty($errors)) {
        try {
            // Use prepared statement for update
            $updateQuery = "UPDATE Admin SET 
                          Name = ?,
                          Contact_Number = ?
                          WHERE AdminID = ?";
            
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("sss", $name, $contactNumber, $adminId);
            $stmt->execute();
            
            if($stmt->affected_rows > 0) {
                $_SESSION['success_message'] = "Admin updated successfully";
            } else {
                $_SESSION['success_message'] = "No changes were made";
            }
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch(Exception $e) {
            $_SESSION['error_message'] = "Error updating admin: " . $e->getMessage();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle Delete
if(isset($_POST['delete'])) {
    $adminId = trim($_POST['admin_id']);
    
    try {
        // Check if admin is used in User table
        if(isAdminInUse($conn, $adminId)) {
            $_SESSION['error_message'] = "Cannot delete this admin as they are associated with one or more users";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // Use prepared statement for deletion
        $deleteQuery = "DELETE FROM Admin WHERE AdminID = ?";
        $stmt = $conn->prepare($deleteQuery);
        $stmt->bind_param("s", $adminId);
        $stmt->execute();
        
        if($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Admin deleted successfully";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $_SESSION['error_message'] = "Admin not found or already deleted";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Cannot delete this admin: " . $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

        .alert-info {
            background-color: #e3f2fd;
            color: #0d47a1;
            border: 1px solid #bbdefb;
        }
    </style>
</head>
<body>
    <div class="main-container" style="min-height: 100vh; background: #f5f7fa; padding: 2rem;">
        <?php include '../templates/navbar-admin.php'; ?>
        
        <div class="container">
            <div class="header-section">
                <h1>Manage Admins</h1>
                <a href="addAdmin.php" class="add-btn">
                    <i class="fas fa-plus"></i> Add Admin
                </a>
            </div>

            <?php if($successMessage): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if($errorMessage): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="auditor-table">
                    <thead>
                        <tr>
                            <th>Admin ID</th>
                            <th>Name</th>
                            <th>Contact Number</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($result && $result->num_rows > 0) {
                            while($row = $result->fetch_assoc()): 
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['AdminID']); ?></td>
                            <td><?php echo htmlspecialchars($row['Name']); ?></td>
                            <td><?php echo htmlspecialchars($row['Contact_Number']); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick="openEditModal('<?php echo htmlspecialchars($row['AdminID']); ?>', '<?php echo htmlspecialchars(addslashes($row['Name'])); ?>', '<?php echo htmlspecialchars($row['Contact_Number']); ?>')">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn delete-btn" onclick="openDeleteModal('<?php echo htmlspecialchars($row['AdminID']); ?>')">
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
                            <td colspan="4" style="text-align: center;">No admins found</td>
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
                <h2>Edit Admin</h2>
                <form id="editForm" method="POST">
                    <input type="hidden" id="edit_admin_id" name="admin_id">
                    
                    <div class="form-group">
                        <label for="edit_name">Name</label>
                        <input type="text" id="edit_name" name="name" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_contact_number">Contact Number</label>
                        <input type="text" id="edit_contact_number" name="contact_number" required placeholder="07XXXXXXXX or +947XXXXXXXX">
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
                <p>Are you sure you want to delete this admin? This action cannot be undone.</p>
                <form method="POST" id="deleteForm">
                    <input type="hidden" id="delete_admin_id" name="admin_id">
                    <div class="delete-modal-buttons">
                        <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" name="delete" class="confirm-delete-btn">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openEditModal(id, name, contactNumber) {
        document.getElementById('editModal').style.display = 'block';
        document.getElementById('edit_admin_id').value = id;
        document.getElementById('edit_name').value = name.replace(/\\'/g, "'");
        document.getElementById('edit_contact_number').value = contactNumber;
    }

    function closeModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    function openDeleteModal(id) {
        document.getElementById('deleteModal').style.display = 'block';
        document.getElementById('delete_admin_id').value = id;
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

<script>
    // Auto-dismiss alerts after 5 seconds
    $(document).ready(function() {
        setTimeout(function() {
            $('.alert-success, .alert-danger').fadeOut('slow');
        }, 5000);
    });
</script>
</body>
</html>