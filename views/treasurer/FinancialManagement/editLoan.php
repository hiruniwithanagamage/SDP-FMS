<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if ID parameter exists
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No loan ID provided";
    header("Location: loan.php");
    exit();
}

$loanID = $_GET['id'];

// Function to get loan details
function getLoanDetails($loanID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            l.LoanID, 
            l.Amount, 
            l.Term, 
            l.Reason, 
            l.Issued_Date, 
            l.Due_Date, 
            l.Paid_Loan, 
            l.Remain_Loan, 
            l.Paid_Interest, 
            l.Remain_Interest,
            l.Status,
            l.Member_MemberID,
            m.Name as MemberName
        FROM Loan l
        JOIN Member m ON l.Member_MemberID = m.MemberID
        WHERE l.LoanID = ?
    ");
    
    $stmt->bind_param("s", $loanID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Function to get all members using prepared statement
function getAllMembers() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT MemberID, Name FROM Member ORDER BY Name");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result;
}

// Function to get loan settings using prepared statement
function getLoanSettings() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT interest, max_loan_limit FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get current term/year using prepared statement
function getCurrentTerm() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get loan details
$loan = getLoanDetails($loanID);
if (!$loan) {
    $_SESSION['error_message'] = "Loan not found";
    header("Location: loan.php");
    exit();
}

// Get all members for the dropdown
$allMembers = getAllMembers();
$currentTerm = getCurrentTerm();
$loanSettings = getLoanSettings();

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $memberID = $_POST['member_id'];
    $amount = $_POST['amount'];
    $term = $_POST['term'];
    $reason = $_POST['reason'];
    $issuedDate = $_POST['issued_date'];
    $dueDate = $_POST['due_date'];
    $status = $_POST['status'];
    
    // Calculate interest based on loan settings
    $interestRate = $loanSettings['interest'] / 100; // Convert percentage to decimal
    $interestAmount = $amount * $interestRate;
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // If loan status is changing, handle differently
        if ($loan['Status'] != $status) {
            if ($status == 'approved' && $loan['Status'] == 'pending') {
                // Loan is being approved - set up initial values
                $paidLoan = 0;
                $remainLoan = $amount;
                $paidInterest = 0;
                $remainInterest = $interestAmount;
            } else if ($status == 'rejected') {
                // Loan is being rejected - zero out all values
                $paidLoan = 0;
                $remainLoan = 0;
                $paidInterest = 0;
                $remainInterest = 0;
            } else {
                // Keep existing values
                $paidLoan = $loan['Paid_Loan'];
                $remainLoan = $loan['Remain_Loan'];
                $paidInterest = $loan['Paid_Interest'];
                $remainInterest = $loan['Remain_Interest'];
            }
        } else {
            // Status not changing
            if ($amount != $loan['Amount']) {
                // Amount is changing
                $amountDifference = $amount - $loan['Amount'];
                
                // Adjust remaining loan amount
                $remainLoan = $loan['Remain_Loan'] + $amountDifference;
                $paidLoan = $loan['Paid_Loan'];
                
                // Recalculate interest
                $remainInterest = $amount * $interestRate - $loan['Paid_Interest'];
                $paidInterest = $loan['Paid_Interest'];
            } else {
                // No amount change, keep values
                $paidLoan = $loan['Paid_Loan'];
                $remainLoan = $loan['Remain_Loan'];
                $paidInterest = $loan['Paid_Interest'];
                $remainInterest = $loan['Remain_Interest'];
            }
        }
        
        // Update loan
        $stmt = $conn->prepare("
            UPDATE Loan SET 
                Member_MemberID = ?,
                Amount = ?,
                Term = ?,
                Reason = ?,
                Issued_Date = ?,
                Due_Date = ?,
                Paid_Loan = ?,
                Remain_Loan = ?,
                Paid_Interest = ?,
                Remain_Interest = ?,
                Status = ?
            WHERE LoanID = ?
        ");
        
        $stmt->bind_param("sissssddddss", 
            $memberID, 
            $amount, 
            $term, 
            $reason, 
            $issuedDate, 
            $dueDate, 
            $paidLoan, 
            $remainLoan, 
            $paidInterest, 
            $remainInterest,
            $status,
            $loanID
        );
        
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success_message'] = "Loan #$loanID successfully updated";
        
        // Handle redirection based on popup mode after ALL database operations are complete
        if (!$isPopup) {
            header("Location: loan.php");
            exit();
        }
        // If it's popup mode, we'll continue rendering the page with a success message
        // and add JavaScript to refresh the parent later
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error updating loan: " . $e->getMessage();
    }
}

// Loan status options
$loanStatus = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected'
];

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Loan</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
        <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
        <link rel="stylesheet" href="../../../assets/css/alert.css">
        <style>
            body { 
                padding: 0; 
                margin: 0; 
                background: white; 
                font-family: Arial, sans-serif;
            }
            .container { 
                padding: 10px; 
            }
            .header-card { 
                display: none; 
            }
            .main-container { 
                padding: 0; 
            }
            .form-container {
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 20px;
                width: 100%;
                margin: 10px auto;
            }
            .form-title {
                color: #1e3c72;
                margin-bottom: 20px;
                text-align: center;
                font-size: 1.5rem;
            }
            .form-group {
                margin-bottom: 15px;
            }
            .form-group label {
                display: block;
                margin-bottom: 6px;
                font-weight: 600;
                color: #333;
            }
            .form-control {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
                transition: border-color 0.3s;
                box-sizing: border-box;
            }
            .form-control:disabled {
                background-color: #f5f5f5;
                cursor: not-allowed;
            }
            .form-control:focus {
                border-color: #1e3c72;
                outline: none;
                box-shadow: 0 0 0 2px rgba(30, 60, 114, 0.2);
            }
            .form-row {
                display: flex;
                gap: 15px;
            }
            .form-row .form-group {
                flex: 1;
            }
            .btn-container {
                display: flex;
                justify-content: flex-end;
                margin-top: 20px;
            }
            .btn {
                min-width: 120px;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                font-size: 14px;
                cursor: pointer;
                transition: background-color 0.3s;
            }
            .btn-primary {
                background-color: #1e3c72;
                color: white;
                height: 40px;
            }
            .btn-primary:hover {
                background-color: #16305c;
            }
            .btn-secondary {
                background-color: #e0e0e0;
                color: #333;
                margin-right: 30px;
                text-align: center;
                font-weight: bold;
                display: block;
            }

            .btn-secondary:hover {
                background-color: #5a6268;
            }
            .member-info {
                background-color: #f9f9f9;
                padding: 12px;
                border-radius: 5px;
                margin-bottom: 15px;
                font-size: 14px;
            }
            .member-info-title {
                font-weight: 600;
                margin-bottom: 8px;
                color: #1e3c72;
            }
            .alert {
                padding: 10px 15px;
                margin-bottom: 15px;
                border-radius: 4px;
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
            .status-pending {
                background-color: #fff8e8;
                color: #f6a609;
            }
            .status-approved {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-rejected {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
        </style>
    </head>
    <body>
        <div class="container">
<?php else: ?>
    <!-- Regular header for standalone page -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Loan</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
        <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
        <link rel="stylesheet" href="../../../assets/css/alert.css">
        <script src="../../../assets/js/alertHandler.js"></script>
        <style>
            .form-container {
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 30px;
                max-width: 800px;
                margin: 20px auto;
            }

            .form-title {
                color: #1e3c72;
                margin-bottom: 25px;
                text-align: center;
            }

            .form-group {
                margin-bottom: 20px;
            }

            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #333;
            }

            .form-control {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
                transition: border-color 0.3s;
            }

            .form-control:disabled {
                background-color: #f5f5f5;
                cursor: not-allowed;
            }

            .form-control:focus {
                border-color: #1e3c72;
                outline: none;
                box-shadow: 0 0 0 2px rgba(30, 60, 114, 0.2);
            }

            .form-row {
                display: flex;
                gap: 20px;
            }

            .form-row .form-group {
                flex: 1;
            }

            .btn-container {
                display: flex;
                justify-content: space-between;
                margin-top: 30px;
            }

            .btn {
                padding: 12px 24px;
                border: none;
                border-radius: 4px;
                font-size: 16px;
                cursor: pointer;
                transition: background-color 0.3s;
            }

            .btn-primary {
                background-color: #1e3c72;
                color: white;
            }

            .btn-primary:hover {
                background-color: #16305c;
            }

            .btn-secondary {
                background-color: #e0e0e0;
                color: #333;
            }

            .btn-secondary:hover {
                background-color: #5a6268;
            }

            .member-info {
                background-color: #f9f9f9;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
            }

            .member-info-title {
                font-weight: 600;
                margin-bottom: 10px;
                color: #1e3c72;
            }
            
            .status-badge {
                display: inline-block;
                padding: 5px 10px;
                border-radius: 15px;
                font-size: 0.8rem;
                font-weight: bold;
            }
            
            .status-pending {
                background-color: #fff8e8;
                color: #f6a609;
            }
            
            .status-approved {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            
            .status-rejected {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
        </style>
    </head>
    <body>
        <div class="main-container">
            <?php include '../../templates/navbar-treasurer.php'; ?>
            <div class="container">
                <div class="header-card">
                    <h1>Edit Loan</h1>
                    <a href="loan.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Loans
                    </a>
                </div>
<?php endif; ?>

            <!-- Generate alerts -->
            <div class="alerts-container">
                <?php if(isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success">
                        <?php 
                            echo $_SESSION['success_message'];
                            unset($_SESSION['success_message']);
                        ?>
                    </div>
                <?php endif; ?>

                <?php if(isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger">
                        <?php 
                            echo $_SESSION['error_message'];
                            unset($_SESSION['error_message']);
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-container">
                <h2 class="form-title">Edit Loan #<?php echo htmlspecialchars($loanID); ?></h2>
                
                <div class="member-info">
                    <div class="member-info-title">Current Loan Information</div>
                    <p>Member ID: <?php echo htmlspecialchars($loan['Member_MemberID']); ?></p>
                    <p>Member Name: <?php echo htmlspecialchars($loan['MemberName']); ?></p>
                    <p>Status: <span class="status-badge status-<?php echo $loan['Status']; ?>"><?php echo ucfirst($loan['Status']); ?></span></p>
                </div>

                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="loan_id">Loan ID</label>
                            <input type="text" id="loan_id" class="form-control" value="<?php echo htmlspecialchars($loanID); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="member_id">Member</label>
                            <select id="member_id" name="member_id" class="form-control" required>
                                <?php while($member = $allMembers->fetch_assoc()): ?>
                                    <option value="<?php echo $member['MemberID']; ?>" <?php echo ($member['MemberID'] == $loan['Member_MemberID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['MemberID'] . ' - ' . $member['Name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Loan Amount (Rs.)</label>
                            <input type="number" id="amount" name="amount" class="form-control" value="<?php echo htmlspecialchars($loan['Amount']); ?>" min="0" step="0.01" max="<?php echo $loanSettings['max_loan_limit']; ?>" required>
                            <small>Maximum loan limit: Rs. <?php echo number_format($loanSettings['max_loan_limit'], 2); ?></small>
                        </div>
                        <div class="form-group">
                            <label for="term">Term</label>
                            <input type="number" id="term" name="term" class="form-control" value="<?php echo htmlspecialchars($loan['Term']); ?>" min="1" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="issued_date">Issue Date</label>
                            <input type="date" id="issued_date" name="issued_date" class="form-control" value="<?php echo date('Y-m-d', strtotime($loan['Issued_Date'])); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="due_date">Due Date</label>
                            <input type="date" id="due_date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime($loan['Due_Date'])); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control" required>
                                <?php foreach($loanStatus as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $loan['Status']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="interest_rate">Interest Rate (%)</label>
                            <input type="text" id="interest_rate" class="form-control" value="<?php echo $loanSettings['interest']; ?>" disabled>
                            <small>Current interest rate as per system settings</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reason">Reason/Purpose</label>
                        <textarea id="reason" name="reason" class="form-control" rows="3" required><?php echo htmlspecialchars($loan['Reason']); ?></textarea>
                    </div>

                    <div class="btn-container">
                        <?php if ($isPopup): ?>
                            <button type="button" class="btn btn-secondary" onclick="window.parent.closeEditModal()">Cancel</button>
                        <?php else: ?>
                            <a href="loan.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Update Loan</button>
                    </div>
                </form>
            </div>

<?php if ($isPopup): ?>
    </div>
    
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_SESSION['error_message'])): ?>
<script>
    // If form was submitted successfully in popup mode, pass message to parent
    window.parent.showAlert('success', 'Loan #<?php echo $loanID; ?> successfully updated');
    window.parent.closeEditModal();
    // Don't reload the entire page as it will lose the alert
</script>
<?php elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['error_message'])): ?>
<script>
    // If form had errors, pass error message to parent
    window.parent.showAlert('error', '<?php echo addslashes($_SESSION['error_message']); ?>');
</script>
<?php unset($_SESSION['error_message']); ?>
<?php endif; ?>
    
</body>
</html>
<?php else: ?>
        </div>
        <?php include '../../templates/footer.php'; ?>
    </div>
</body>
</html>
<?php endif; ?>

<script>
    // Date validation to ensure due date is after issue date
    document.getElementById('issued_date').addEventListener('change', function() {
        const issuedDate = new Date(this.value);
        const dueDate = new Date(document.getElementById('due_date').value);
        
        if (dueDate <= issuedDate) {
            // Set due date to issue date + 1 month by default
            const newDueDate = new Date(issuedDate);
            newDueDate.setMonth(newDueDate.getMonth() + parseInt(document.getElementById('term').value));
            
            // Format the date as YYYY-MM-DD for the input
            const year = newDueDate.getFullYear();
            const month = String(newDueDate.getMonth() + 1).padStart(2, '0');
            const day = String(newDueDate.getDate()).padStart(2, '0');
            
            document.getElementById('due_date').value = `${year}-${month}-${day}`;
        }
    });
    
    document.getElementById('term').addEventListener('change', function() {
        updateDueDate();
    });
    
    function updateDueDate() {
        const issuedDate = new Date(document.getElementById('issued_date').value);
        const term = parseInt(document.getElementById('term').value) || 0;
        
        if (!isNaN(issuedDate.getTime()) && term > 0) {
            // Calculate new due date based on term
            const newDueDate = new Date(issuedDate);
            newDueDate.setMonth(newDueDate.getMonth() + term);
            
            // Format the date as YYYY-MM-DD for the input
            const year = newDueDate.getFullYear();
            const month = String(newDueDate.getMonth() + 1).padStart(2, '0');
            const day = String(newDueDate.getDate()).padStart(2, '0');
            
            document.getElementById('due_date').value = `${year}-${month}-${day}`;
        }
    }

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('amount').value);
        const maxLimit = <?php echo $loanSettings['max_loan_limit']; ?>;
        
        if (isNaN(amount) || amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid amount greater than zero.');
        } else if (amount > maxLimit) {
            e.preventDefault();
            alert('Loan amount exceeds the maximum limit of Rs. ' + maxLimit.toFixed(2));
        }
        
        const issuedDate = new Date(document.getElementById('issued_date').value);
        const dueDate = new Date(document.getElementById('due_date').value);
        
        if (dueDate <= issuedDate) {
            e.preventDefault();
            alert('Due date must be after issue date.');
        }
    });
</script>