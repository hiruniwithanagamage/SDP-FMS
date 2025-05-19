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
$result = search($query);

// Get the active term for editing
$activeTermQuery = "SELECT * FROM Static WHERE status = 'active' LIMIT 1";
$activeTermResult = search($activeTermQuery);
$activeTerm = $activeTermResult->num_rows > 0 ? $activeTermResult->fetch_assoc() : null;

// Handle Update with prepared statements
if(isset($_POST['update'])) {
    $id = $_POST['term_id'];
    
    // Collect form data - year is NOT included as it's already set by admin
    $monthly_fee = $_POST['monthly_fee'];
    $registration_fee = $_POST['registration_fee'];
    $death_welfare = $_POST['death_welfare'];
    $late_fine = $_POST['late_fine'];
    $absent_fine = $_POST['absent_fine'];
    $rules_violation_fine = $_POST['rules_violation_fine'];
    $interest = $_POST['interest'];
    $max_loan_limit = $_POST['max_loan_limit'];

    // Prepare the SQL statement
    $updateQuery = "UPDATE Static SET 
                   monthly_fee = ?,
                   registration_fee = ?,
                   death_welfare = ?,
                   late_fine = ?,
                   absent_fine = ?,
                   rules_violation_fine = ?,
                   interest = ?,
                   max_loan_limit = ?
                   WHERE id = ? AND status = 'active'";
    
    try {
        // Using the prepare function from database.php
        $stmt = prepare($updateQuery);
        
        if($stmt) {
            // Bind parameters - "dddddddi" means 8 doubles (decimal numbers) and 1 integer
            $stmt->bind_param("dddddddds", 
                $monthly_fee, 
                $registration_fee, 
                $death_welfare, 
                $late_fine, 
                $absent_fine, 
                $rules_violation_fine, 
                $interest, 
                $max_loan_limit, 
                $id
            );
            
            // Execute the statement
            $stmt->execute();
            
            // Check if the update was successful
            if($stmt->affected_rows > 0) {
                $_SESSION['success_message'] = "Term details updated successfully!";
            } else {
                $_SESSION['error_message'] = "No changes were made or the term does not exist.";
            }
            
            // Close the statement
            $stmt->close();
        } else {
            $_SESSION['error_message'] = "Failed to prepare statement. Please check your query.";
        }
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error updating term details: " . $e->getMessage();
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
    <link rel="stylesheet" href="../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
    <style>
    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: bold;
    }
    
    .status-active {
        background-color: #d4edda;
        color: #155724;
    }
    
    .status-inactive {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    /* Edit Modal Styles */
    .modal {
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

    .modal-content {
        position: relative;
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        width: 80%;
        max-width: 600px;
        animation: modalFadeIn 0.3s;
    }

    @keyframes modalFadeIn {
        from {opacity: 0; transform: translateY(-50px);}
        to {opacity: 1; transform: translateY(0);}
    }

    .close {
        color: #aaa;
        position: absolute;
        top: 10px;
        right: 15px;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    .close:hover,
    .close:focus {
        color: black;
        text-decoration: none;
    }
    
    .form-group {
        margin-bottom: 15px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }
    
    .form-group input {
        width: 100%;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }
    
    .form-row {
        display: flex;
        gap: 15px;
        margin-bottom: 10px;
    }
    
    .form-row .form-group {
        flex: 1;
    }
    
    .readonly-field {
        background-color: #f5f5f5;
        cursor: not-allowed;
    }
    
    .form-footer {
        margin-top: 20px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-treasurer.php'; ?>
        <div class="container">
            <div class="header-section" style="border-bottom: 1px solid #ddd; color: #1a237e;">
                <h1>Term Details</h1>
                <?php if($activeTerm): ?>
                <button class="add-btn" onclick="openEditModal()">
                    <i class="fas fa-edit"></i> Edit Active Term Details
                </button>
                <?php endif; ?>
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
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Reset pointer to beginning
                        mysqli_data_seek($result, 0);
                        while($row = $result->fetch_assoc()): 
                        ?>
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
                                <?php 
                                    $status = isset($row['status']) ? $row['status'] : 'active'; // Default to active if not set
                                    $statusClass = $status === 'active' ? 'status-active' : 'status-inactive';
                                    $statusText = ucfirst($status);
                                ?>
                                <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Edit Modal -->
        <?php if($activeTerm): ?>
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeEditModal()">&times;</span>
                <h2>Edit Active Term Details</h2>
                <form method="POST">
                    <input type="hidden" name="term_id" value="<?php echo $activeTerm['id']; ?>">
                    
                    <!-- Year field (read-only) -->
                    <div class="form-group">
                        <label for="year">Year</label>
                        <input type="number" id="year" value="<?php echo htmlspecialchars($activeTerm['year']); ?>" class="readonly-field" readonly>
                        <small>Year cannot be modified as it was set by the admin</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="monthly_fee">Monthly Fee (Rs.)</label>
                            <input type="number" id="monthly_fee" name="monthly_fee" required step="0.01" min="0" value="<?php echo htmlspecialchars($activeTerm['monthly_fee']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="registration_fee">Registration Fee (Rs.)</label>
                            <input type="number" id="registration_fee" name="registration_fee" required step="0.01" min="0" value="<?php echo htmlspecialchars($activeTerm['registration_fee']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="death_welfare">Death Welfare Amount (Rs.)</label>
                            <input type="number" id="death_welfare" name="death_welfare" required step="0.01" min="0" value="<?php echo htmlspecialchars($activeTerm['death_welfare']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="late_fine">Late Fine (Rs.)</label>
                            <input type="number" id="late_fine" name="late_fine" required step="0.01" min="0" value="<?php echo htmlspecialchars($activeTerm['late_fine']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="absent_fine">Absent Fine (Rs.)</label>
                            <input type="number" id="absent_fine" name="absent_fine" required step="0.01" min="0" value="<?php echo htmlspecialchars($activeTerm['absent_fine']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="rules_violation_fine">Rules Violation Fine (Rs.)</label>
                            <input type="number" id="rules_violation_fine" name="rules_violation_fine" required step="0.01" min="0" value="<?php echo htmlspecialchars($activeTerm['rules_violation_fine']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="interest">Interest Rate (%)</label>
                            <input type="number" id="interest" name="interest" required step="0.01" min="0" max="100" value="<?php echo htmlspecialchars($activeTerm['interest']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="max_loan_limit">Maximum Loan Limit (Rs.)</label>
                            <input type="number" id="max_loan_limit" name="max_loan_limit" required step="0.01" min="0" value="<?php echo htmlspecialchars($activeTerm['max_loan_limit']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-footer">
                        <button type="button" class="cancel-btn" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" name="update" class="save-btn">Update Term</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // Modal handling functions
    function openEditModal() {
        document.getElementById('editModal').style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent scrolling while modal is open
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
        document.body.style.overflow = ''; // Re-enable scrolling
    }

    // Close modal when clicking outside of it
    window.onclick = function(event) {
        const modal = document.getElementById('editModal');
        if (event.target == modal) {
            closeEditModal();
        }
    }

    // Form validation
    const form = document.querySelector('#editModal form');
    if (form) {
        form.addEventListener('submit', function(event) {
            // Validate interest rate
            const interest = document.getElementById('interest').value;
            if (parseFloat(interest) > 100) {
                alert('Interest rate cannot exceed 100%');
                event.preventDefault();
                return false;
            }
        });
    }
    </script>
</body>
</html>