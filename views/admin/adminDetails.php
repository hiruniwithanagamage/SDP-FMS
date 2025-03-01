<?php
session_start();
require_once "../../config/database.php";

// Check for success message from previous page
$successMessage = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : null;
if($successMessage) {
    unset($_SESSION['success_message']);
}

// Handle Update
if(isset($_POST['update'])) {
    $adminId = $_POST['admin_id'];
    $name = $_POST['name'];
    $contactNumber = $_POST['contact_number'];
    
    try {
        // Get database connection for escaping strings
        $conn = getConnection();
        
        // Use escaped values in the query
        $updateQuery = "UPDATE Admin SET 
                       Name = '" . $conn->real_escape_string($name) . "',
                       Contact_Number = '" . $conn->real_escape_string($contactNumber) . "'
                       WHERE AdminID = '" . $conn->real_escape_string($adminId) . "'";
        
        iud($updateQuery);
        $_SESSION['success_message'] = "Admin updated successfully";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } catch(Exception $e) {
        $updateError = "Error updating admin: " . $e->getMessage();
    }
}

// Handle Delete
if(isset($_POST['delete'])) {
    $adminId = $_POST['admin_id'];
    
    try {
        // Get database connection for escaping strings
        $conn = getConnection();
        
        // Use escaped values in the query
        $deleteQuery = "DELETE FROM Admin WHERE AdminID = '" . $conn->real_escape_string($adminId) . "'";
        iud($deleteQuery);
        $_SESSION['success_message'] = "Admin deleted successfully";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } catch(Exception $e) {
        $deleteError = "Cannot delete this admin. They may have associated records.";
    }
}

// Fetch Admin Details
try {
    $query = "SELECT * FROM Admin";
    $result = search($query);
    $admins = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $admins[] = $row;
        }
    }
} catch(Exception $e) {
    $error = "Error fetching admin details: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminActorDetails.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-admin.php'; ?>
        
        <div class="container">
            <div class="header-section">
                <h1>Admin Details</h1>
                <a href="addAdmin.php" class="add-btn">
                    <i class="fas fa-plus"></i> Add New Admin
                </a>
            </div>

            <?php if($successMessage): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if(isset($error)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if(isset($updateError)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($updateError); ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($deleteError)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($deleteError); ?>
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
                        <?php if(empty($admins)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center;">No admin records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($admins as $admin): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($admin['AdminID']); ?></td>
                                    <td><?php echo htmlspecialchars($admin['Name']); ?></td>
                                    <td><?php echo htmlspecialchars($admin['Contact_Number']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit-btn" onclick="openEditModal(
                                                '<?php echo $admin['AdminID']; ?>',
                                                '<?php echo addslashes($admin['Name']); ?>',
                                                '<?php echo $admin['Contact_Number']; ?>'
                                            )">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="action-btn delete-btn" onclick="openDeleteModal('<?php echo $admin['AdminID']; ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Edit Admin</h2>
            <form id="editForm" method="POST" action="">
                <input type="hidden" id="edit_admin_id" name="admin_id">
                
                <div class="form-group">
                    <label for="edit_name">Name</label>
                    <input type="text" id="edit_name" name="name" required>
                </div>

                <div class="form-group">
                    <label for="edit_contact">Contact Number</label>
                    <input type="text" id="edit_contact" name="contact_number" required>
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

    <script>
        function openEditModal(id, name, contact) {
            document.getElementById('editModal').style.display = 'block';
            document.getElementById('edit_admin_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_contact').value = contact;
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
</body>
</html>