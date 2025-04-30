<?php
session_start();
require_once "../../config/database.php";

// Check user role - this would come from your authentication system
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error_message'] = "Access denied. Please log in as an administrator.";
    header("Location: ../../index.php");
    exit();
}

// Handle term activation/deactivation
if (isset($_POST['activate'])) {
    $id = $_POST['term_id'];
    
    try {
        // Check current status of the term
        $checkStatusStmt = prepare("SELECT status FROM Static WHERE id = ?");
        $checkStatusStmt->bind_param("s", $id);
        $checkStatusStmt->execute();
        $result = $checkStatusStmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['status'] === 'active') {
            // If this is currently active and we're trying to deactivate it,
            // check if it's the only active term
            $countActiveStmt = prepare("SELECT COUNT(*) as active_count FROM Static WHERE status = 'active'");
            $countActiveStmt->execute();
            $countResult = $countActiveStmt->get_result();
            $countRow = $countResult->fetch_assoc();
            
            if ($countRow['active_count'] <= 1) {
                $_SESSION['error_message'] = "Cannot deactivate. At least one term must be active.";
            } else {
                // Deactivate the selected term
                $deactivateThisStmt = prepare("UPDATE Static SET status = 'inactive' WHERE id = ?");
                $deactivateThisStmt->bind_param("s", $id);
                $deactivateThisStmt->execute();
                $_SESSION['success_message'] = "Term deactivated successfully!";
            }
        } else {
            // First, deactivate all terms if we're activating a new one
            $deactivateStmt = prepare("UPDATE Static SET status = 'inactive' WHERE status = 'active'");
            $deactivateStmt->execute();
            
            // Then, activate the selected term
            $activateStmt = prepare("UPDATE Static SET status = 'active' WHERE id = ?");
            $activateStmt->bind_param("s", $id);
            $activateStmt->execute();
            
            $_SESSION['success_message'] = "Term activated successfully!";
        }
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error updating term status: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle Update
if(isset($_POST['update'])) {
    $id = $_POST['term_id'];
    $year = $_POST['year'];
    $status = $_POST['status'];
    
    try {
        // Check if the year already exists for another term
        $checkYearStmt = prepare("SELECT id FROM Static WHERE year = ? AND id != ?");
        $checkYearStmt->bind_param("is", $year, $id);
        $checkYearStmt->execute();
        $result = $checkYearStmt->get_result();
        
        if ($result->num_rows > 0) {
            $_SESSION['error_message'] = "A term for year $year already exists.";
        } else {
            $updateQuery = "UPDATE Static SET year = ?, status = ? WHERE id = ?";
            $stmt = prepare($updateQuery);
            $stmt->bind_param("iss", $year, $status, $id);
            $stmt->execute();
            
            // If setting this term to active, deactivate other terms
            if ($status === 'active') {
                $deactivateStmt = prepare("UPDATE Static SET status = 'inactive' WHERE id != ? AND status = 'active'");
                $deactivateStmt->bind_param("s", $id);
                $deactivateStmt->execute();
            }
            
            $_SESSION['success_message'] = "Term updated successfully!";
        }
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error updating term: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle Delete
if(isset($_POST['delete'])) {
    $id = $_POST['term_id'];
    
    try {
        // Check if there are related records in other tables
        // This is a simplified check - you might need to expand this to check all related tables
        $checkRelatedRecordsQuery = "SELECT COUNT(*) AS count FROM MembershipFee WHERE Term IN (SELECT year FROM Static WHERE id = ?)";
        $checkStmt = prepare($checkRelatedRecordsQuery);
        $checkStmt->bind_param("s", $id);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['count'] > 0) {
            $_SESSION['error_message'] = "Cannot delete this term. It has associated records.";
        } else {
            // Check if this is the active term and if it's the only term
            $checkActiveQuery = "SELECT status FROM Static WHERE id = ?";
            $activeStmt = prepare($checkActiveQuery);
            $activeStmt->bind_param("s", $id);
            $activeStmt->execute();
            $activeResult = $activeStmt->get_result();
            $activeRow = $activeResult->fetch_assoc();
            
            // Count the total number of terms
            $countTermsStmt = prepare("SELECT COUNT(*) as term_count FROM Static");
            $countTermsStmt->execute();
            $countResult = $countTermsStmt->get_result();
            $countRow = $countResult->fetch_assoc();
            
            if ($activeRow['status'] === 'active' && $countRow['term_count'] <= 1) {
                $_SESSION['error_message'] = "Cannot delete the only term in the system.";
            } else if ($activeRow['status'] === 'active') {
                $_SESSION['error_message'] = "Cannot delete the active term. Please activate another term first.";
            } else {
                $deleteQuery = "DELETE FROM Static WHERE id = ?";
                $deleteStmt = prepare($deleteQuery);
                $deleteStmt->bind_param("s", $id);
                $deleteStmt->execute();
                
                $_SESSION['success_message'] = "Term deleted successfully!";
            }
        }
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error deleting term: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle form submission for new term
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_term'])) {
    $year = $_POST['year'];
    $status = $_POST['status']; 
    
    $termId = "STAT" . $year;

    // Set default values for fields that will be updated by treasurer later
    $monthly_fee = 0;
    $registration_fee = 0;
    $death_welfare = 0;
    $late_fine = 0;
    $absent_fine = 0;
    $rules_violation_fine = 0;
    $interest = 0;
    $max_loan_limit = 0;
    
    // Check if the year already exists
    $checkYearStmt = prepare("SELECT id FROM Static WHERE year = ? OR id = ?");
    $checkYearStmt->bind_param("is", $year, $termId);
    $checkYearStmt->execute();
    $result = $checkYearStmt->get_result();
    
    if ($result->num_rows > 0) {
        $_SESSION['error_message'] = "A term for year $year already exists.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        // Using prepared statement to prevent SQL injection
        $stmt = prepare("INSERT INTO Static (id, year, monthly_fee, registration_fee, death_welfare, 
                        late_fine, absent_fine, rules_violation_fine, interest, max_loan_limit, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->bind_param("siddddddids", $termId, $year, $monthly_fee, $registration_fee, $death_welfare,
                       $late_fine, $absent_fine, $rules_violation_fine, $interest, $max_loan_limit, $status);
        
        try {
            if ($stmt->execute()) {
                // If setting this term to active, deactivate other terms
                if ($status === 'active') {
                    $deactivateStmt = prepare("UPDATE Static SET status = 'inactive' WHERE year != ? AND status = 'active'");
                    $deactivateStmt->bind_param("i", $year);
                    $deactivateStmt->execute();
                    $deactivateStmt->close();
                }
                
                $_SESSION['success_message'] = "New term added successfully! The treasurer can now add financial details.";
                $_SESSION['new_term_year'] = $year;  // Store the year for the modal
                $_SESSION['show_officers_modal'] = true;  // Flag to show the modal
            } else {
                throw new Exception("Database error: " . $stmt->error);
            }
        } catch(Exception $e) {
            $_SESSION['error_message'] = "Error adding new term: " . $e->getMessage();
        } finally {
            $stmt->close();
        }
    }
    $checkYearStmt->close();
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle redirection to add officers
if(isset($_POST['add_officers'])) {
    // Redirect to the add treasurer and auditor page
    header("Location: addOfficers.php");
    exit();
}

// Handle skipping the officers addition
if(isset($_POST['skip_officers'])) {
    // Set a thank you message
    $_SESSION['success_message'] = "Thank you! You can add treasurer and auditor later from the officers management page.";
    // Clear the session variables and stay on the same page
    unset($_SESSION['new_term_year']);
    unset($_SESSION['show_officers_modal']);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Get all terms for display
$terms = [];
$nextYear = date('Y'); // Default to current year

try {
    $termsQuery = "SELECT id, year, status FROM Static ORDER BY year DESC";
    $result = search($termsQuery);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $terms[] = $row;
        }
        
        // Find the next year based on existing years
        if (!empty($terms)) {
            // Get the highest year
            $highestYear = intval($terms[0]['year']);
            // Set next year as highest + 1
            $nextYear = $highestYear + 1;
        }
    }
} catch(Exception $e) {
    $_SESSION['error_message'] = "Error retrieving terms: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Terms</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminActorDetails.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
        }
 
        .main-container {
            min-height: 100vh;
            background: #f5f7fa;
            display: flex;
            flex-direction: column;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }
        
        .welcome-card {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 15px 0 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .page-title {
            font-size: 1.8rem;
            margin: 0;
        }
        
        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: white;
            background-color: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .back-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .form-container, .terms-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .section-title {
            color: #1e3c72;
            margin-top: 0;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 0.75rem;
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
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .form-group small {
            display: block;
            margin-top: 0.25rem;
            color: #6c757d;
        }
        
        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .cancel-btn, .save-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .cancel-btn {
            background-color: #f8f9fa;
            color: #333;
            border: 1px solid #ddd;
        }
        
        .save-btn {
            background-color: #1e3c72;
            color: white;
            border: none;
        }
        
        .cancel-btn:hover {
            background-color: #e9ecef;
        }
        
        .save-btn:hover {
            background-color: #2a5298;
        }
        
        .workflow-info {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
        }
        
        .term-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .term-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #eee;
        }
        
        .term-item:last-child {
            border-bottom: none;
        }
        
        .term-year {
            font-size: 1.1rem;
            font-weight: 500;
        }
        
        .term-status {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-right: 10px;
        }
        
        .status-active {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            padding: 0.35rem 0.65rem;
            font-size: 0.85rem;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .delete-btn {
            background-color: #e53e3e;
            color: white;
        }
        
        .activate-btn {
            background-color: #38a169;
            color: white;
        }
        
        .delete-btn:hover {
            background-color: #c53030;
        }
        
        .activate-btn:hover {
            background-color: #2f855a;
        }
        
        /* Modal Styles */
        .modal, .delete-modal, .officers-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        
        .modal-content, .delete-modal-content, .officers-modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 100%;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: #333;
        }
        
        .modal h2, .delete-modal h2, .officers-modal h2 {
            margin-top: 0;
            color: #1e3c72;
        }
        
        .modal-footer, .delete-modal-buttons, .officers-modal-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .confirm-delete-btn {
            background-color: #e53e3e;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            font-weight: 500;
            cursor: pointer;
        }
        
        .confirm-delete-btn:hover {
            background-color: #c53030;
        }
        
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
        
        /* Specific for Officers Modal */
        .add-officers-btn {
            background-color: #38a169;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            font-weight: 500;
            cursor: pointer;
        }
        
        .add-officers-btn:hover {
            background-color: #2f855a;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-admin.php'; ?>
        
        <div class="container">
            <div class="welcome-card">
                <h1 class="page-title">Manage Terms</h1>
                <a href="home-admin.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php if(isset($_SESSION['error_message'])): ?>
                <div id="error-alert" class="alert alert-danger">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['success_message'])): ?>
                <div id="success-alert" class="alert alert-success">
                    <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>
            
            <div class="content-grid">
                <div class="form-container">
                    <h2 class="section-title">Add New Term</h2>
                    
                    <div class="workflow-info">
                        <i class="fas fa-info-circle"></i> As an administrator, you're responsible for creating new terms and setting their status. After creating a term, the treasurer will add the financial details.
                    </div>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label for="year">Year</label>
                            <input type="number" id="year" name="year" required min="2024" max="2100" value="<?php echo $nextYear; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <small>Setting a term to "active" will automatically set all other terms to "inactive"</small>
                        </div>
                        
                        <div class="form-footer">
                            <a href="home-admin.php" class="cancel-btn">Cancel</a>
                            <button type="submit" name="create_term" class="save-btn">Create Term</button>
                        </div>
                    </form>
                </div>
                
                <div class="terms-container">
                    <h2 class="section-title">Existing Terms</h2>
                    
                    <?php if (empty($terms)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <p>No terms have been created yet. Use the form on the left to add your first term.</p>
                        </div>
                    <?php else: ?>
                        <ul class="term-list">
                            <?php foreach ($terms as $term): ?>
                                <li class="term-item">
                                    <div class="term-year">Year <?php echo htmlspecialchars($term['year']); ?></div>
                                        <div class="action-buttons">
                                            <span class="term-status <?php echo $term['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo ucfirst($term['status']); ?>
                                            </span>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="term_id" value="<?php echo $term['id']; ?>">
                                                <?php if($term['status'] === 'active'): ?>
                                                    <button type="submit" name="activate" class="action-btn activate-btn" style="background-color: #6c757d;">
                                                        <i class="fas fa-times-circle"></i> Deactivate
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" name="activate" class="action-btn activate-btn">
                                                        <i class="fas fa-check-circle"></i> Activate
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                            <button class="action-btn delete-btn" onclick="openDeleteModal(<?php echo $term['id']; ?>)">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="delete-modal">
            <div class="delete-modal-content">
                <h2>Confirm Delete</h2>
                <p>Are you sure you want to delete this term? This action cannot be undone and may affect related data.</p>
                <form method="POST" id="deleteForm">
                    <input type="hidden" id="delete_term_id" name="term_id">
                    <div class="delete-modal-buttons">
                        <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" name="delete" class="confirm-delete-btn">Delete</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Add Officers Modal -->
        <div id="officersModal" class="officers-modal">
            <div class="officers-modal-content">
                <h2>Add Officers for New Term</h2>
                <p>Would you like to add a treasurer and auditor for the new term (Year <?php echo isset($_SESSION['new_term_year']) ? $_SESSION['new_term_year'] : ''; ?>)?</p>
                <form method="POST">
                    <div class="officers-modal-buttons">
                        <button type="submit" name="skip_officers" class="cancel-btn">Skip for Now</button>
                        <button type="submit" name="add_officers" class="add-officers-btn">Yes, Add Officers</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php include '../templates/footer.php'; ?>
    </div>
    
    <script>
        // Modal handling functions
        function openEditModal(term) {
            document.getElementById('editModal').style.display = 'block';
            document.getElementById('edit_term_id').value = term.id;
            document.getElementById('edit_year').value = term.year;
            document.getElementById('edit_status').value = term.status;
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

        // Show the officers modal if needed
        <?php if(isset($_SESSION['show_officers_modal']) && $_SESSION['show_officers_modal']): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('officersModal').style.display = 'block';
            });
        <?php endif; ?>

        // Close modals when clicking outside
        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');
            const officersModal = document.getElementById('officersModal');

            if (event.target == editModal) {
                closeEditModal();
            }
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
            if (event.target == officersModal) {
                // We don't close the officers modal on outside click
                // as it's an important decision
            }
        }

        // Client-side validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const yearInput = document.getElementById('year');
            if (yearInput.value < 0) {
                yearInput.setCustomValidity('Year cannot be negative');
                e.preventDefault();
            } else {
                yearInput.setCustomValidity('');
            }
        });
    </script>
</body>
</html>