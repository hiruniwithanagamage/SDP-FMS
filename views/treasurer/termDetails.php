<?php
session_start();
require_once "../../config/database.php";

// Check for success message
$successMessage = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : null;

// Clear the session message after retrieving it
if($successMessage) {
    unset($_SESSION['success_message']);
}

// Fetch all terms
$query = "SELECT * FROM Static ORDER BY year DESC";
$result = Database::search($query);

// Handle Update
if(isset($_POST['update'])) {
    $id = $_POST['term_id'];
    $year = $_POST['year'];
    $monthly_fee = $_POST['monthly_fee'];
    $registration_fee = $_POST['registration_fee'];
    $death_welfare = $_POST['death_welfare'];
    $late_fine = $_POST['late_fine'];
    $absent_fine = $_POST['absent_fine'];
    $rules_violation_fine = $_POST['rules_violation_fine'];
    $interest = $_POST['interest'];
    $max_loan_limit = $_POST['max_loan_limit'];

    $updateQuery = "UPDATE Static SET 
                   year = '$year',
                   monthly_fee = '$monthly_fee',
                   registration_fee = '$registration_fee',
                   death_welfare = '$death_welfare',
                   late_fine = '$late_fine',
                   absent_fine = '$absent_fine',
                   rules_violation_fine = '$rules_violation_fine',
                   interest = '$interest',
                   max_loan_limit = '$max_loan_limit'
                   WHERE id = '$id'";
    
    try {
        Database::iud($updateQuery);
        $_SESSION['success_message'] = "Term updated successfully!";
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error updating term: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle Delete
if(isset($_POST['delete'])) {
    $id = $_POST['term_id'];
    
    $deleteQuery = "DELETE FROM Static WHERE id = '$id'";
    
    try {
        Database::iud($deleteQuery);
        $_SESSION['success_message'] = "Term deleted successfully!";
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Cannot delete this term. It may have associated records.";
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
    <title>Term Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminActorDetails.css">
    <style>
    .alert {
        padding: 1rem;
        margin-bottom: 1rem;
        border-radius: 4px;
        opacity: 1;
        transition: opacity 0.5s ease-in-out;
    }

    .alert.fade-out {
        opacity: 0;
        transform: translateY(-20px);
    }

    .alert-success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .alert-danger {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
</style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-treasurer.php'; ?>
        <div class="container">
            <div class="header-section">
                <h1>Term Details</h1>
                <a href="addNewTerm.php" class="add-btn">
                    <i class="fas fa-plus"></i> Add New Term
                </a>
            </div>
            <?php if($successMessage): ?>
                <div id="success-alert" class="alert alert-success">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['error_message'])): ?>
                <div id="error-alert" class="alert alert-danger">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="treasurer-table">
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Monthly Fee</th>
                            <th>Registration Fee</th>
                            <th>Death Welfare</th>
                            <th>Late Fine</th>
                            <th>Absent Fine</th>
                            <th>Rules Fine</th>
                            <th>Interest</th>
                            <th>Loan Limit</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['year']); ?></td>
                            <td>Rs. <?php echo htmlspecialchars(number_format($row['monthly_fee'], 2)); ?></td>
                            <td>Rs. <?php echo htmlspecialchars(number_format($row['registration_fee'], 2)); ?></td>
                            <td>Rs. <?php echo htmlspecialchars(number_format($row['death_welfare'], 2)); ?></td>
                            <td>Rs. <?php echo htmlspecialchars(number_format($row['late_fine'], 2)); ?></td>
                            <td>Rs. <?php echo htmlspecialchars(number_format($row['absent_fine'], 2)); ?></td>
                            <td>Rs. <?php echo htmlspecialchars(number_format($row['rules_violation_fine'], 2)); ?></td>
                            <td><?php echo htmlspecialchars($row['interest']); ?>%</td>
                            <td>Rs. <?php echo htmlspecialchars(number_format($row['max_loan_limit'], 2)); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn delete-btn" onclick="openDeleteModal(<?php echo $row['id']; ?>)">
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
                <span class="close" onclick="closeEditModal()">&times;</span>
                <h2>Edit Term</h2>
                <form method="POST">
                    <input type="hidden" id="edit_term_id" name="term_id">
                    <div class="form-group">
                        <label for="edit_year">Year</label>
                        <input type="number" id="edit_year" name="year" required min="2024" max="2100">
                    </div>
                    <div class="form-group">
                        <label for="edit_monthly_fee">Monthly Fee (Rs.)</label>
                        <input type="number" id="edit_monthly_fee" name="monthly_fee" required step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="edit_registration_fee">Registration Fee (Rs.)</label>
                        <input type="number" id="edit_registration_fee" name="registration_fee" required step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="edit_death_welfare">Death Welfare Amount (Rs.)</label>
                        <input type="number" id="edit_death_welfare" name="death_welfare" required step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="edit_late_fine">Late Fine (Rs.)</label>
                        <input type="number" id="edit_late_fine" name="late_fine" required step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="edit_absent_fine">Absent Fine (Rs.)</label>
                        <input type="number" id="edit_absent_fine" name="absent_fine" required step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="edit_rules_violation_fine">Rules Violation Fine (Rs.)</label>
                        <input type="number" id="edit_rules_violation_fine" name="rules_violation_fine" required step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="edit_interest">Interest Rate (%)</label>
                        <input type="number" id="edit_interest" name="interest" required step="0.01" max="100">
                    </div>
                    <div class="form-group">
                        <label for="edit_max_loan_limit">Maximum Loan Limit (Rs.)</label>
                        <input type="number" id="edit_max_loan_limit" name="max_loan_limit" required step="0.01">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="cancel-btn" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" name="update" class="save-btn">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="delete-modal">
            <div class="delete-modal-content">
                <h2>Confirm Delete</h2>
                <p>Are you sure you want to delete this term? This action cannot be undone.</p>
                <form method="POST" id="deleteForm">
                    <input type="hidden" id="delete_term_id" name="term_id">
                    <div class="delete-modal-buttons">
                        <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" name="delete" class="confirm-delete-btn">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
// Add this at the beginning of your script section
document.addEventListener('DOMContentLoaded', function() {
    // Function to handle alert fadeout and removal
    function fadeOutAlert(alertElement) {
        if (alertElement) {
            // Start fade out after 2 seconds
            setTimeout(() => {
                alertElement.classList.add('fade-out');
            }, 2000);

            // Remove the element after fade animation
            setTimeout(() => {
                alertElement.remove();
            }, 2500);
        }
    }

    // Handle any success alerts
    const successAlert = document.getElementById('success-alert');
    if (successAlert) {
        fadeOutAlert(successAlert);
    }

    // Handle any error alerts
    const errorAlert = document.getElementById('error-alert');
    if (errorAlert) {
        fadeOutAlert(errorAlert);
    }
});

    // Modal handling functions

    function openEditModal(term) {
        document.getElementById('editModal').style.display = 'block';
        document.getElementById('edit_term_id').value = term.id;
        document.getElementById('edit_year').value = term.year;
        document.getElementById('edit_monthly_fee').value = term.monthly_fee;
        document.getElementById('edit_registration_fee').value = term.registration_fee;
        document.getElementById('edit_death_welfare').value = term.death_welfare;
        document.getElementById('edit_late_fine').value = term.late_fine;
        document.getElementById('edit_absent_fine').value = term.absent_fine;
        document.getElementById('edit_rules_violation_fine').value = term.rules_violation_fine;
        document.getElementById('edit_interest').value = term.interest;
        document.getElementById('edit_max_loan_limit').value = term.max_loan_limit;
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    function openDeleteModal(id) {
        document.getElementById('deleteModal').style.display = 'block';
        document.getElementById('delete_term_id').value = id;
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        const editModal = document.getElementById('editModal');
        const deleteModal = document.getElementById('deleteModal');

        if (event.target == editModal) {
            closeEditModal();
        }
        if (event.target == deleteModal) {
            closeDeleteModal();
        }
    }
</script>
</body>
</html>