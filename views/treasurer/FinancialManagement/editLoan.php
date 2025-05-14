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

/**
 * Function to generate a unique payment ID
 * @param string $term The term year for the payment
 * @return string The generated payment ID
 */
function generatePaymentID($term = null) {
    $conn = getConnection();
    
    // Get current year if term is not provided
    if (empty($term)) {
        $term = date('Y');
    }
    
    // Extract the last 2 digits of the term
    $shortTerm = substr((string)$term, -2);
    
    // Find the highest sequence number for the current term
    $stmt = $conn->prepare("
        SELECT MAX(CAST(SUBSTRING(PaymentID, 6) AS UNSIGNED)) as max_seq 
        FROM Payment 
        WHERE PaymentID LIKE 'PAY{$shortTerm}%'
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $nextSeq = 1; // Default starting value
    if ($row && $row['max_seq']) {
        $nextSeq = $row['max_seq'] + 1;
    }
    
    // Format: PAY followed by last 2 digits of term and sequence number
    // Use leading zeros for numbers 1-9, no leading zeros after 10
    if ($nextSeq < 10) {
        return 'PAY' . $shortTerm . '0' . $nextSeq;
    } else {
        return 'PAY' . $shortTerm . $nextSeq;
    }
}

/**
 * Function to generate a unique expense ID
 * @param string $term The term year for the expense
 * @return string The generated expense ID
 */
function generateExpenseID($term = null) {
    $conn = getConnection();
    
    // Get current year if term is not provided
    if (empty($term)) {
        $term = date('Y');
    }
    
    // Extract the last 2 digits of the term
    $shortTerm = substr((string)$term, -2);
    
    // Find the highest sequence number for the current term
    $stmt = $conn->prepare("
        SELECT MAX(CAST(SUBSTRING(ExpenseID, 6) AS UNSIGNED)) as max_seq 
        FROM Expenses 
        WHERE ExpenseID LIKE 'EXP{$shortTerm}%'
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $nextSeq = 1; // Default starting value
    if ($row && $row['max_seq']) {
        $nextSeq = $row['max_seq'] + 1;
    }

    // Format: EXP followed by last 2 digits of term and sequence number
    // Use leading zeros for numbers 1-9, no leading zeros after 10
    if ($nextSeq < 10) {
        return 'EXP' . $shortTerm . '0' . $nextSeq;
    } else {
        return 'EXP' . $shortTerm . $nextSeq;
    }
}

/**
 * Function to get active treasurer ID
 * @return string|null The active treasurer ID or null if not found
 */
function getActiveTreasurer() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT TreasurerID FROM Treasurer WHERE isActive = 1 LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        return null;
    }
    $row = $result->fetch_assoc();
    return $row['TreasurerID'];
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
$activeTreasurer = getActiveTreasurer();

// Check if treasurer exists
if (!$activeTreasurer) {
    $_SESSION['error_message'] = "No active treasurer found. Please set an active treasurer first.";
    if (!$isPopup) {
        header("Location: loan.php");
        exit();
    }
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $memberID = $_POST['member_id'];
    $amount = floatval($_POST['amount']);
    $term = intval($_POST['term']);
    $reason = $_POST['reason'];
    $issuedDate = $_POST['issued_date'];
    $dueDate = $_POST['due_date'];
    $status = $_POST['status'];
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // VALIDATION 1: Member cannot be changed
        if ($memberID !== $loan['Member_MemberID']) {
            throw new Exception("Member cannot be changed for an existing loan");
        }
        
        // VALIDATION 2: Term cannot be changed
        if ($term !== intval($loan['Term'])) {
            throw new Exception("Term cannot be changed for an existing loan");
        }
        
        // VALIDATION 3: Check if date is not in the future
        $currentDate = date('Y-m-d');
        if ($issuedDate > $currentDate) {
            throw new Exception("Issue date cannot be in the future");
        }
        
        // VALIDATION 4: Loan amount must be between 500 and max_loan_limit
        $minLoanAmount = 500;
        $maxLoanAmount = $loanSettings['max_loan_limit'];
        
        if ($amount < $minLoanAmount) {
            throw new Exception("Loan amount cannot be less than Rs. " . number_format($minLoanAmount, 2));
        }
        
        if ($amount > $maxLoanAmount) {
            throw new Exception("Loan amount cannot exceed the maximum limit of Rs. " . number_format($maxLoanAmount, 2));
        }
        
        // VALIDATION 5: Due date cannot be changed
        if ($dueDate !== date('Y-m-d', strtotime($loan['Due_Date']))) {
            // Calculate correct due date based on issue date (1 year duration)
            $correctDueDate = date('Y-m-d', strtotime($issuedDate . ' + 1 year'));
            if ($dueDate !== $correctDueDate) {
                throw new Exception("Due date cannot be manually changed. It is automatically set to 1 year after issue date.");
            }
        }
        
        // VALIDATION 6: Check if status allows amount change
        $oldStatus = $loan['Status'];
        $oldAmount = floatval($loan['Amount']);
        $oldIssuedDate = $loan['Issued_Date'];
        
        // If loan is approved and has payments, amount cannot be changed
        if ($oldStatus === 'approved' && floatval($loan['Paid_Loan']) > 0 && $amount !== $oldAmount) {
            throw new Exception("Loan amount cannot be changed after payments have been made");
        }
        
        // Get current interest rate from Static table
        $interestRate = $loanSettings['interest'] / 100; // Convert percentage to decimal
        
        // Calculate new due date if issue date has changed
        if ($issuedDate !== date('Y-m-d', strtotime($oldIssuedDate))) {
            $dueDate = date('Y-m-d', strtotime($issuedDate . ' + 1 year'));
        }
        
        // Initialize variables for financial tracking
        $paidLoan = $loan['Paid_Loan'];
        $remainLoan = $loan['Remain_Loan'];
        $paidInterest = $loan['Paid_Interest'];
        $remainInterest = $loan['Remain_Interest'];
        
        // Handle status changes
        if ($oldStatus !== $status) {
            // Case 1: Changing from pending to approved
            if ($oldStatus === 'pending' && $status === 'approved') {
                // Set up initial values for approved loan
                $paidLoan = 0;
                $remainLoan = $amount;
                $paidInterest = 0;
                $remainInterest = $amount * $interestRate;
                
                // Add as an expense when loan is approved
                $expenseID = generateExpenseID($term);
                $description = "Loan approval - ID: $loanID";
                
                $expenseStmt = $conn->prepare("
                    INSERT INTO Expenses (
                        ExpenseID, Category, Method, Amount, Date, Term, 
                        Description, Treasurer_TreasurerID
                    ) VALUES (?, 'Loan', 'System', ?, ?, ?, ?, ?)
                ");
                
                $expenseStmt->bind_param("sdssss", 
                    $expenseID,
                    $amount,
                    $currentDate,
                    $term,
                    $description,
                    $activeTreasurer
                );
                
                if (!$expenseStmt->execute()) {
                    throw new Exception("Failed to create expense record: " . $conn->error);
                }
                
                // Update the loan with the expense ID
                $updateExpenseStmt = $conn->prepare("
                    UPDATE Loan SET Expenses_ExpenseID = ? WHERE LoanID = ?
                ");
                
                $updateExpenseStmt->bind_param("ss", $expenseID, $loanID);
                
                if (!$updateExpenseStmt->execute()) {
                    throw new Exception("Failed to link expense to loan: " . $conn->error);
                }
            }
            // Case 2: Changing from approved to pending/rejected
            else if ($oldStatus === 'approved' && ($status === 'pending' || $status === 'rejected')) {
                // Check if loan has been paid
                if (floatval($loan['Paid_Loan']) > 0) {
                    throw new Exception("Cannot change status from approved to " . ucfirst($status) . " after payments have been made");
                }
                
                // If changing to pending/rejected, create a payment to reverse the loan amount
                $paymentID = generatePaymentID($term);
                $notes = $status === 'pending' ? "Loan status changed from approved to pending" : "Loan rejected";
                
                $paymentStmt = $conn->prepare("
                    INSERT INTO Payment (
                        PaymentID, Payment_Type, Method, Amount, Date, Term,
                        Member_MemberID, status, Notes
                    ) VALUES (?, 'Loan Return', 'system', ?, ?, ?, ?, 'cash', ?)
                ");
                
                $paymentStmt->bind_param("sdssss", 
                    $paymentID,
                    $oldAmount,
                    $currentDate,
                    $term,
                    $memberID,
                    $notes
                );
                
                if (!$paymentStmt->execute()) {
                    throw new Exception("Failed to create payment record: " . $conn->error);
                }
                
                // Add entry to LoanPayment junction table
                $junctionStmt = $conn->prepare("
                    INSERT INTO LoanPayment (LoanID, PaymentID)
                    VALUES (?, ?)
                ");
                
                $junctionStmt->bind_param("ss", 
                    $loanID,
                    $paymentID
                );
                
                if (!$junctionStmt->execute()) {
                    throw new Exception("Failed to create loan-payment relationship: " . $conn->error);
                }
                
                // Reset financial values
                $paidLoan = 0;
                $remainLoan = 0;
                $paidInterest = 0;
                $remainInterest = 0;
            }
            // Case 3: Changing from rejected to approved
            else if ($oldStatus === 'rejected' && $status === 'approved') {
                // Same as pending to approved
                $paidLoan = 0;
                $remainLoan = $amount;
                $paidInterest = 0;
                $remainInterest = $amount * $interestRate;
                
                // Add as an expense when loan is approved
                $expenseID = generateExpenseID($term);
                $description = "Loan approval - ID: $loanID";
                
                $expenseStmt = $conn->prepare("
                    INSERT INTO Expenses (
                        ExpenseID, Category, Method, Amount, Date, Term, 
                        Description, Treasurer_TreasurerID
                    ) VALUES (?, 'Loan', 'System', ?, ?, ?, ?, ?)
                ");
                
                $expenseStmt->bind_param("sdssss", 
                    $expenseID,
                    $amount,
                    $currentDate,
                    $term,
                    $description,
                    $activeTreasurer
                );
                
                if (!$expenseStmt->execute()) {
                    throw new Exception("Failed to create expense record: " . $conn->error);
                }
                
                // Update the loan with the expense ID
                $updateExpenseStmt = $conn->prepare("
                    UPDATE Loan SET Expenses_ExpenseID = ? WHERE LoanID = ?
                ");
                
                $updateExpenseStmt->bind_param("ss", $expenseID, $loanID);
                
                if (!$updateExpenseStmt->execute()) {
                    throw new Exception("Failed to link expense to loan: " . $conn->error);
                }
            }
        } else {
            // Status not changing
            if ($amount != $oldAmount) {
                // If status is approved, handle amount changes
                if ($status === 'approved') {
                    // Case 1: Amount decreased
                    if ($amount < $oldAmount) {
                        $amountDifference = $oldAmount - $amount;
                        
                        // Add as a payment
                        $paymentID = generatePaymentID($term);
                        $notes = "Loan amount reduced from Rs. " . number_format($oldAmount, 2) . " to Rs. " . number_format($amount, 2);
                        
                        $paymentStmt = $conn->prepare("
                            INSERT INTO Payment (
                                PaymentID, Payment_Type, Method, Amount, Date, Term,
                                Member_MemberID, status, Notes
                            ) VALUES (?, 'Loan Adjustment', 'system', ?, ?, ?, ?, 'cash', ?)
                        ");
                        
                        $paymentStmt->bind_param("sdssss", 
                            $paymentID,
                            $amountDifference,
                            $currentDate,
                            $term,
                            $memberID,
                            $notes
                        );
                        
                        if (!$paymentStmt->execute()) {
                            throw new Exception("Failed to create payment record: " . $conn->error);
                        }
                        
                        // Add entry to LoanPayment junction table
                        $junctionStmt = $conn->prepare("
                            INSERT INTO LoanPayment (LoanID, PaymentID)
                            VALUES (?, ?)
                        ");
                        
                        $junctionStmt->bind_param("ss", 
                            $loanID,
                            $paymentID
                        );
                        
                        if (!$junctionStmt->execute()) {
                            throw new Exception("Failed to create loan-payment relationship: " . $conn->error);
                        }
                        
                        // Adjust remaining loan amount and interest
                        $remainLoan = $amount;
                        $remainInterest = $amount * $interestRate;
                    }
                    // Case 2: Amount increased
                    else if ($amount > $oldAmount) {
                        $amountDifference = $amount - $oldAmount;
                        
                        // Add as an expense
                        $expenseID = generateExpenseID($term);
                        $description = "Loan amount increased from Rs. " . number_format($oldAmount, 2) . " to Rs. " . number_format($amount, 2);
                        
                        $expenseStmt = $conn->prepare("
                            INSERT INTO Expenses (
                                ExpenseID, Category, Method, Amount, Date, Term, 
                                Description, Treasurer_TreasurerID
                            ) VALUES (?, 'Loan Adjustment', 'System', ?, ?, ?, ?, ?)
                        ");
                        
                        $expenseStmt->bind_param("sdssss", 
                            $expenseID,
                            $amountDifference,
                            $currentDate,
                            $term,
                            $description,
                            $activeTreasurer
                        );
                        
                        if (!$expenseStmt->execute()) {
                            throw new Exception("Failed to create expense record: " . $conn->error);
                        }
                        
                        // Adjust remaining loan amount and interest
                        $remainLoan = $loan['Remain_Loan'] + $amountDifference;
                        $remainInterest = $loan['Remain_Interest'] + ($amountDifference * $interestRate);
                    }
                }
                // If status is pending or rejected, simply update the amount
                else {
                    $remainLoan = $amount;
                    $remainInterest = $amount * $interestRate;
                }
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
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        $_SESSION['error_message'] = "Error updating loan: " . $e->getMessage();
        error_log("Loan Update Error: " . $e->getMessage());
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
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="loan_id">Loan ID</label>
                            <input type="text" id="loan_id" class="form-control" value="<?php echo htmlspecialchars($loanID); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="member_id">Member</label>
                            <!-- Disabled dropdown to prevent changing member -->
                            <select id="member_id" name="member_id" class="form-control" required readonly disabled>
                                <?php while($member = $allMembers->fetch_assoc()): ?>
                                    <option value="<?php echo $member['MemberID']; ?>" <?php echo ($member['MemberID'] == $loan['Member_MemberID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['MemberID'] . ' - ' . $member['Name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <!-- Hidden field to ensure the member ID is submitted -->
                            <input type="hidden" name="member_id" value="<?php echo htmlspecialchars($loan['Member_MemberID']); ?>">
                            <small>Member cannot be changed after loan creation</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Loan Amount (Rs.)</label>
                            <input type="number" id="amount" name="amount" class="form-control" 
                                   value="<?php echo htmlspecialchars($loan['Amount']); ?>" 
                                   min="500" step="0.01" 
                                   max="<?php echo $loanSettings['max_loan_limit']; ?>" 
                                   required
                                   <?php echo ($loan['Status'] === 'approved' && floatval($loan['Paid_Loan']) > 0) ? 'readonly disabled' : ''; ?>>
                            <?php if ($loan['Status'] === 'approved' && floatval($loan['Paid_Loan']) > 0): ?>
                                <small>Amount cannot be changed after payments have been made</small>
                            <?php else: ?>
                                <small>Minimum: Rs. 500, Maximum: Rs. <?php echo number_format($loanSettings['max_loan_limit'], 2); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="term">Term</label>
                            <!-- Term cannot be changed - disabled field -->
                            <input type="number" id="term" name="term" class="form-control" 
                                   value="<?php echo htmlspecialchars($loan['Term']); ?>" 
                                   min="1" readonly disabled>
                            <!-- Hidden field to ensure the term is submitted -->
                            <input type="hidden" name="term" value="<?php echo htmlspecialchars($loan['Term']); ?>">
                            <small>Term cannot be changed after loan creation</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="issued_date">Issue Date</label>
                            <input type="date" id="issued_date" name="issued_date" 
                                   class="form-control" 
                                   value="<?php echo date('Y-m-d', strtotime($loan['Issued_Date'])); ?>" 
                                   max="<?php echo date('Y-m-d'); ?>" 
                                   required
                                   <?php echo ($loan['Status'] === 'approved' && floatval($loan['Paid_Loan']) > 0) ? 'readonly disabled' : ''; ?>>
                            <small>Date cannot be in the future</small>
                        </div>
                        <div class="form-group">
                            <label for="due_date">Due Date</label>
                            <!-- Due date is calculated automatically and cannot be changed -->
                            <input type="date" id="due_date" name="due_date" 
                                   class="form-control" 
                                   value="<?php echo date('Y-m-d', strtotime($loan['Due_Date'])); ?>" 
                                   readonly disabled>
                            <!-- Hidden field to store the due date, will be updated by JS when issue date changes -->
                            <input type="hidden" name="due_date" 
                                   id="hidden_due_date" 
                                   value="<?php echo date('Y-m-d', strtotime($loan['Due_Date'])); ?>">
                            <small>Due date is automatically set to 1 year after issue date</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control" required
                                    <?php echo ($loan['Status'] === 'approved' && floatval($loan['Paid_Loan']) > 0) ? 'disabled' : ''; ?>>
                                <?php foreach($loanStatus as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $loan['Status']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($loan['Status'] === 'approved' && floatval($loan['Paid_Loan']) > 0): ?>
                                <!-- Hidden field to ensure the status is submitted when the select is disabled -->
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($loan['Status']); ?>">
                                <small>Status cannot be changed after payments have been made</small>
                            <?php endif; ?>
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
    setTimeout(function() {
        window.parent.updateFilters(); // Refresh the parent page to show updated data
        window.parent.closeEditModal();
    }); // To delay the refresh and modal close to ensure alert is visible -> ,1000
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
        updateDueDate();
    });
    
    // Function to update due date based on issue date
    function updateDueDate() {
        const issuedDate = new Date(document.getElementById('issued_date').value);
        
        if (!isNaN(issuedDate.getTime())) {
            // Calculate new due date (1 year after issue date)
            const newDueDate = new Date(issuedDate);
            newDueDate.setFullYear(newDueDate.getFullYear() + 1);
            
            // Format the date as YYYY-MM-DD for the input
            const year = newDueDate.getFullYear();
            const month = String(newDueDate.getMonth() + 1).padStart(2, '0');
            const day = String(newDueDate.getDate()).padStart(2, '0');
            const formattedDate = `${year}-${month}-${day}`;
            
            // Update the hidden due date input
            document.getElementById('hidden_due_date').value = formattedDate;
            
            // Show the calculated date in the disabled visible field
            document.getElementById('due_date').value = formattedDate;
        }
    }

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('amount').value);
        const minAmount = 500;
        const maxAmount = <?php echo $loanSettings['max_loan_limit']; ?>;
        const status = document.getElementById('status').value;
        const oldStatus = '<?php echo $loan['Status']; ?>';
        const hasPaidAmount = <?php echo floatval($loan['Paid_Loan']) > 0 ? 'true' : 'false'; ?>;
        const issuedDate = document.getElementById('issued_date').value;
        const currentDate = new Date().toISOString().split('T')[0];
        
        // Validate amount
        if (isNaN(amount) || amount < minAmount) {
            e.preventDefault();
            alert('Please enter a valid amount of at least Rs. ' + minAmount.toFixed(2));
            return;
        }
        
        if (amount > maxAmount) {
            e.preventDefault();
            alert('Loan amount exceeds the maximum limit of Rs. ' + maxAmount.toFixed(2));
            return;
        }
        
        // Validate issue date
        if (issuedDate > currentDate) {
            e.preventDefault();
            alert('Issue date cannot be in the future.');
            return;
        }
        
        // Prevent status change if payments have been made
        if (hasPaidAmount && oldStatus === 'approved' && status !== 'approved') {
            e.preventDefault();
            alert('Cannot change status from approved after payments have been made.');
            return;
        }
    });
</script>