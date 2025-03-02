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

// Fetch all auditors with prepared statement
try {
    $query = "SELECT * FROM Auditor ORDER BY Term DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
} catch(Exception $e) {
    $error = "Error fetching auditors: " . $e->getMessage();
}

// Check if name already exists for another auditor
function checkDuplicateName($conn, $name, $auditorId) {
    $query = "SELECT AuditorID FROM Auditor WHERE Name = ? AND AuditorID != ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $name, $auditorId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Check if auditor is used in User table
function isAuditorInUse($conn, $auditorId) {
    $query = "SELECT UserId FROM User WHERE Auditor_AuditorID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $auditorId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Handle Update
if(isset($_POST['update'])) {
    $auditorId = trim($_POST['auditor_id']);
    $name = trim($_POST['name']);
    $term = trim($_POST['term']);
    $isActive = $_POST['is_active'];
    
    // Validate inputs
    $errors = [];
    
    if(empty($name)) $errors[] = "Name is required";
    if(empty($term)) $errors[] = "Term is required";
    if(!is_numeric($term)) $errors[] = "Term must be a number";
    
    // Check for duplicate name
    if(empty($errors) && checkDuplicateName($conn, $name, $auditorId)) {
        $errors[] = "An auditor with this name already exists";
    }
    
    if(empty($errors)) {
        try {
            // Use prepared statement for update
            $updateQuery = "UPDATE Auditor SET 
                          Name = ?,
                          Term = ?,
                          isActive = ?
                          WHERE AuditorID = ?";
            
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("siis", $name, $term, $isActive, $auditorId);
            $stmt->execute();
            
            if($stmt->affected_rows > 0) {
                $_SESSION['success_message'] = "Auditor updated successfully";
            } else {
                $_SESSION['success_message'] = "No changes were made";
            }
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch(Exception $e) {
            $updateError = "Error updating auditor: " . $e->getMessage();
        }
    } else {
        $updateError = implode("<br>", $errors);
    }
}

// Handle Delete
if(isset($_POST['delete'])) {
    $auditorId = trim($_POST['auditor_id']);
    
    try {
        // Check if auditor is used in User table
        if(isAuditorInUse($conn, $auditorId)) {
            throw new Exception("Cannot delete this auditor as they are associated with one or more users");
        }
        
        // Use prepared statement for deletion
        $deleteQuery = "DELETE FROM Auditor WHERE AuditorID = ?";
        $stmt = $conn->prepare($deleteQuery);
        $stmt->bind_param("s", $auditorId);
        $stmt->execute();
        
        if($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Auditor deleted successfully";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $deleteError = "Auditor not found or already deleted";
        }
    } catch(Exception $e) {
        $deleteError = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Auditors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
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
            <h1>Manage Auditors</h1>
            <a href="addAuditor.php" class="add-btn">
                <i class="fas fa-plus"></i> Add Auditor
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
            <div class="alert alert-danger"><?php echo htmlspecialchars($updateError); ?></div>
        <?php endif; ?>

        <?php if(isset($deleteError)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($deleteError); ?></div>
        <?php endif; ?>
        
        <div class="table-responsive">
            <table class="auditor-table">
                <thead>
                    <tr>
                        <th>Auditor ID</th>
                        <th>Name</th>
                        <th>Term</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(isset($result) && $result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['AuditorID']); ?></td>
                            <td><?php echo htmlspecialchars($row['Name']); ?></td>
                            <td><?php echo htmlspecialchars($row['Term']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $row['isActive'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $row['isActive'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick="openEditModal(
                                        '<?php echo htmlspecialchars($row['AuditorID']); ?>',
                                        '<?php echo htmlspecialchars(addslashes($row['Name'])); ?>',
                                        '<?php echo htmlspecialchars($row['Term']); ?>',
                                        '<?php echo htmlspecialchars($row['isActive']); ?>'
                                    )">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn delete-btn" onclick="openDeleteModal('<?php echo htmlspecialchars($row['AuditorID']); ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">No auditors found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Edit Auditor</h2>
            <form id="editForm" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <input type="hidden" id="edit_auditor_id" name="auditor_id">
                
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
            <p>Are you sure you want to delete this auditor? This action cannot be undone.</p>
            <form method="POST" id="deleteForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <input type="hidden" id="delete_auditor_id" name="auditor_id">
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
            document.getElementById('edit_auditor_id').value = id;
            document.getElementById('edit_name').value = name.replace(/\\'/g, "'");
            document.getElementById('edit_term').value = term;
            document.getElementById('edit_status').value = isActive;
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openDeleteModal(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_auditor_id').value = id;
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