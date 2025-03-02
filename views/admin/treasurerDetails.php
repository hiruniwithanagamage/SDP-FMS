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

// Fetch all treasurers
$query = "SELECT * FROM Treasurer ORDER BY Term DESC";
$result = search($query);

// Handle Update
if(isset($_POST['update'])) {
    $treasurerId = $_POST['treasurer_id'];
    $name = $_POST['name'];
    $term = $_POST['term'];
    $isActive = $_POST['is_active'];

    // Add validation before updating
    if(empty($name) || !is_numeric($term) || ($isActive != '0' && $isActive != '1')) {
        $_SESSION['error_message'] = "Invalid input data";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    try {
        // Get connection
        $conn = getConnection();
        
        // Use prepared statement for update
        $stmt = $conn->prepare("UPDATE Treasurer SET Name = ?, Term = ?, isActive = ? WHERE TreasurerID = ?");
        $term = intval($term);
        $isActive = intval($isActive);
        $stmt->bind_param("siis", $name, $term, $isActive, $treasurerId);
        $stmt->execute();
        
        // Check if update was successful
        if($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Treasurer updated successfully";
        } else {
            $_SESSION['success_message'] = "No changes were made";
        }
        $stmt->close();
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error updating treasurer: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle Delete
if(isset($_POST['delete'])) {
    $treasurerId = $_POST['treasurer_id'];
    
    try {
        // Get connection
        $conn = getConnection();
        
        // Use prepared statement for delete
        $stmt = $conn->prepare("DELETE FROM Treasurer WHERE TreasurerID = ?");
        $stmt->bind_param("s", $treasurerId);
        $stmt->execute();
        
        // Check if delete was successful
        if($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Treasurer deleted successfully";
        } else {
            $_SESSION['error_message'] = "Treasurer not found";
        }
        $stmt->close();
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Cannot delete this treasurer. They may have associated records: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Treasurers</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
    <style>
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
    </style>
</head>
<body>
    <div class="main-container" style="min-height: 100vh; background: #f5f7fa; padding: 2rem;">
    <?php include '../templates/navbar-admin.php'; ?>
    <div class="container">
        <div class="header-section">
            <h1>Manage Treasurers</h1>
            <a href="addTreasurer.php" class="add-btn">
                <i class="fas fa-plus"></i> Add Treasurer
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
            <table class="treasurer-table">
                <thead>
                    <tr>
                        <th>Treasurer ID</th>
                        <th>Name</th>
                        <th>Term</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['TreasurerID']); ?></td>
                        <td><?php echo htmlspecialchars($row['Name']); ?></td>
                        <td><?php echo htmlspecialchars($row['Term']); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $row['isActive'] ? 'active' : 'inactive'; ?>">
                                <?php echo $row['isActive'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-btn edit-btn" onclick="openEditModal('<?php echo $row['TreasurerID']; ?>', '<?php echo $row['Name']; ?>', '<?php echo $row['Term']; ?>', '<?php echo $row['isActive']; ?>')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="action-btn delete-btn" onclick="openDeleteModal('<?php echo $row['TreasurerID']; ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Edit Treasurer</h2>
            <form id="editForm" method="POST">
                <input type="hidden" id="edit_treasurer_id" name="treasurer_id">
                
                <div class="form-group">
                    <label for="edit_name">Name</label>
                    <input type="text" id="edit_name" name="name" required>
                </div>

                <div class="form-group">
                    <label for="edit_term">Term</label>
                    <input type="number" id="edit_term" name="term" required>
                </div>

                <div class="form-group">
                    <label for="edit_status">Status</label>
                    <select id="edit_status" name="is_active" required>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
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
            <p>Are you sure you want to delete this treasurer? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" id="delete_treasurer_id" name="treasurer_id">
                <div class="delete-modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>
    </div>

    <script>
        function openEditModal(id, name, term, isActive) {
            document.getElementById('editModal').style.display = 'block';
            document.getElementById('edit_treasurer_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_term').value = term;
            document.getElementById('edit_status').value = isActive;
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openDeleteModal(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_treasurer_id').value = id;
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