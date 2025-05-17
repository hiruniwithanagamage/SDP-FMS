<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if ID parameter exists
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No payment ID provided";
    header("Location: payment.php");
    exit();
}

$paymentID = $_GET['id'];

// Function to get payment details
function getPaymentDetails($paymentID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            p.PaymentID, 
            p.Payment_Type, 
            p.Method, 
            p.Amount, 
            p.Date, 
            p.Term, 
            p.Notes, 
            p.status,
            p.Member_MemberID,
            m.Name as MemberName
        FROM Payment p
        JOIN Member m ON p.Member_MemberID = m.MemberID
        WHERE p.PaymentID = ?
    ");
    
    $stmt->bind_param("s", $paymentID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Function to get all members
function getAllMembers() {
    $sql = "SELECT MemberID, Name FROM Member ORDER BY Name";
    return search($sql);
}

// Function to get current term/year
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get payment details
$payment = getPaymentDetails($paymentID);
if (!$payment) {
    $_SESSION['error_message'] = "Payment not found";
    header("Location: payment.php");
    exit();
}

// Get all members for the dropdown
$allMembers = getAllMembers();
$currentTerm = getCurrentTerm();

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $memberID = $_POST['member_id'];
    $paymentType = $_POST['payment_type'];
    $method = $_POST['method'];
    $amount = $_POST['amount'];
    $date = $_POST['date'];
    $term = $_POST['term'] ?? $currentTerm;
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'];
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // Update payment
        $stmt = $conn->prepare("
            UPDATE Payment SET 
                Member_MemberID = ?,
                Payment_Type = ?,
                Method = ?,
                Amount = ?,
                Date = ?,
                Term = ?,
                Notes = ?,
                status = ?
            WHERE PaymentID = ?
        ");
        
        $stmt->bind_param("sssdsssss", 
            $memberID, 
            $paymentType, 
            $method, 
            $amount, 
            $date, 
            $term, 
            $notes,
            $status,
            $paymentID
        );
        
        $stmt->execute();
        
        // If it's a membership fee payment, we need to update related records
        if ($paymentType === 'monthly' || $paymentType === 'registration') {
            // Check if there are existing records in MembershipFeePayment
            $checkStmt = $conn->prepare("
                SELECT mfp.FeeID 
                FROM MembershipFeePayment mfp 
                WHERE mfp.PaymentID = ?
            ");
            $checkStmt->bind_param("s", $paymentID);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows > 0) {
                // Get the existing fee ID
                $row = $result->fetch_assoc();
                $feeID = $row['FeeID'];
                
                // Update the MembershipFee record
                $updateFeeStmt = $conn->prepare("
                    UPDATE MembershipFee 
                    SET Amount = ?, 
                        Date = ?, 
                        Term = ?, 
                        Type = ? 
                    WHERE FeeID = ?
                ");
                $updateFeeStmt->bind_param("dsiss", 
                    $amount, 
                    $date, 
                    $term, 
                    $paymentType, 
                    $feeID
                );
                $updateFeeStmt->execute();
                
                // Update the MembershipFeePayment record details if needed
                $updateMFPStmt = $conn->prepare("
                    UPDATE MembershipFeePayment 
                    SET Details = ? 
                    WHERE FeeID = ? AND PaymentID = ?
                ");
                $details = "Updated on " . date('Y-m-d H:i:s');
                $updateMFPStmt->bind_param("sss", 
                    $details, 
                    $feeID, 
                    $paymentID
                );
                $updateMFPStmt->execute();
            } else {
                // No existing records, create new ones
                // First create MembershipFee record
                $feeID = "FEE" . time() . rand(100, 999);
                $insertFeeStmt = $conn->prepare("
                    INSERT INTO MembershipFee (
                        FeeID, 
                        Amount, 
                        Date, 
                        Term, 
                        Type, 
                        Member_MemberID, 
                        IsPaid
                    ) VALUES (?, ?, ?, ?, ?, ?, 'Yes')
                ");
                $insertFeeStmt->bind_param("sdsisss", 
                    $feeID, 
                    $amount, 
                    $date, 
                    $term, 
                    $paymentType, 
                    $memberID
                );
                $insertFeeStmt->execute();
                
                // Then create MembershipFeePayment link record
                $insertMFPStmt = $conn->prepare("
                    INSERT INTO MembershipFeePayment (
                        FeeID, 
                        PaymentID,
                        Details
                    ) VALUES (?, ?, ?)
                ");
                $details = "Created on " . date('Y-m-d H:i:s');
                $insertMFPStmt->bind_param("sss", 
                    $feeID, 
                    $paymentID, 
                    $details
                );
                $insertMFPStmt->execute();
            }
        }
        
        // If it's a loan payment, update the loan table
        if ($paymentType === 'Loan') {
            // Check if there are existing records in LoanPayment
            $checkStmt = $conn->prepare("
                SELECT lp.LoanID 
                FROM LoanPayment lp 
                WHERE lp.PaymentID = ?
            ");
            $checkStmt->bind_param("s", $paymentID);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $loanID = $row['LoanID'];
                
                // Get the loan details to update remaining amounts
                $loanStmt = $conn->prepare("
                    SELECT 
                        Paid_Loan, 
                        Remain_Loan, 
                        Paid_Interest, 
                        Remain_Interest 
                    FROM Loan 
                    WHERE LoanID = ?
                ");
                $loanStmt->bind_param("s", $loanID);
                $loanStmt->execute();
                $loanResult = $loanStmt->get_result();
                $loanData = $loanResult->fetch_assoc();
                
                // Get the old payment amount to adjust loan amounts
                $oldAmountStmt = $conn->prepare("
                    SELECT Amount 
                    FROM Payment 
                    WHERE PaymentID = ?
                ");
                $oldAmountStmt->bind_param("s", $paymentID);
                $oldAmountStmt->execute();
                $oldAmountResult = $oldAmountStmt->get_result();
                $oldAmount = $oldAmountResult->fetch_assoc()['Amount'];
                
                // Calculate the difference in payment amount
                $amountDifference = $amount - $oldAmount;
                
                // Determine how to apply the difference to loan and interest
                // For simplicity, apply it first to interest, then to principal
                $interestPaid = $loanData['Paid_Interest'];
                $interestRemaining = $loanData['Remain_Interest'];
                $loanPaid = $loanData['Paid_Loan'];
                $loanRemaining = $loanData['Remain_Loan'];
                
                if ($amountDifference > 0) {
                    // Payment increased
                    if ($interestRemaining > 0) {
                        // Apply to interest first
                        $interestPayment = min($amountDifference, $interestRemaining);
                        $interestPaid += $interestPayment;
                        $interestRemaining -= $interestPayment;
                        $amountDifference -= $interestPayment;
                    }
                    
                    // If there's still a difference, apply it to the loan principal
                    if ($amountDifference > 0 && $loanRemaining > 0) {
                        $loanPayment = min($amountDifference, $loanRemaining);
                        $loanPaid += $loanPayment;
                        $loanRemaining -= $loanPayment;
                    }
                } else if ($amountDifference < 0) {
                    // Payment decreased
                    // Reverse logic - first adjust loan principal, then interest
                    $absAdjustment = abs($amountDifference);
                    
                    if ($loanPaid > 0) {
                        $loanAdjustment = min($absAdjustment, $loanPaid);
                        $loanPaid -= $loanAdjustment;
                        $loanRemaining += $loanAdjustment;
                        $absAdjustment -= $loanAdjustment;
                    }
                    
                    if ($absAdjustment > 0 && $interestPaid > 0) {
                        $interestAdjustment = min($absAdjustment, $interestPaid);
                        $interestPaid -= $interestAdjustment;
                        $interestRemaining += $interestAdjustment;
                    }
                }
                
                // Update the loan record with new values
                $updateLoanStmt = $conn->prepare("
                    UPDATE Loan 
                    SET 
                        Paid_Loan = ?, 
                        Remain_Loan = ?, 
                        Paid_Interest = ?, 
                        Remain_Interest = ? 
                    WHERE LoanID = ?
                ");
                $updateLoanStmt->bind_param("dddds", 
                    $loanPaid, 
                    $loanRemaining, 
                    $interestPaid, 
                    $interestRemaining, 
                    $loanID
                );
                $updateLoanStmt->execute();
            } else {
                // No existing loan payment record, create a new one
                // This is a special case, as we need to find a loan for this member first
                // Query to find active loans for this member
                $loanStmt = $conn->prepare("
                    SELECT 
                        LoanID, 
                        Remain_Loan, 
                        Remain_Interest 
                    FROM Loan 
                    WHERE Member_MemberID = ? AND Status = 'approved' AND (Remain_Loan > 0 OR Remain_Interest > 0)
                    ORDER BY Issued_Date ASC
                    LIMIT 1
                ");
                $loanStmt->bind_param("s", $memberID);
                $loanStmt->execute();
                $loanResult = $loanStmt->get_result();
                
                if ($loanResult->num_rows > 0) {
                    $loanData = $loanResult->fetch_assoc();
                    $loanID = $loanData['LoanID'];
                    $remainLoan = $loanData['Remain_Loan'];
                    $remainInterest = $loanData['Remain_Interest'];
                    
                    // Calculate how to apply the payment (first to interest, then to principal)
                    $interestPayment = min($amount, $remainInterest);
                    $paidInterest = $interestPayment;
                    $remainingInterest = $remainInterest - $interestPayment;
                    
                    // Apply remaining amount to loan principal
                    $remainingAmount = $amount - $interestPayment;
                    $loanPayment = min($remainingAmount, $remainLoan);
                    $paidLoan = $loanPayment;
                    $remainingLoan = $remainLoan - $loanPayment;
                    
                    // Update the loan record
                    $updateLoanStmt = $conn->prepare("
                        UPDATE Loan SET
                            Paid_Loan = Paid_Loan + ?,
                            Remain_Loan = ?,
                            Paid_Interest = Paid_Interest + ?,
                            Remain_Interest = ?
                        WHERE LoanID = ?
                    ");
                    $updateLoanStmt->bind_param("dddds", 
                        $paidLoan, 
                        $remainingLoan, 
                        $paidInterest, 
                        $remainingInterest, 
                        $loanID
                    );
                    $updateLoanStmt->execute();
                    
                    // Create LoanPayment record
                    $insertLPStmt = $conn->prepare("
                        INSERT INTO LoanPayment (
                            LoanID,
                            PaymentID
                        ) VALUES (?, ?)
                    ");
                    $insertLPStmt->bind_param("ss", $loanID, $paymentID);
                    $insertLPStmt->execute();
                } else {
                    // No active loans found for this member
                    throw new Exception("No active loans found for this member. Cannot create loan payment.");
                }
            }
        }
        
        // If it's a fine payment, update the Fine table
        if ($paymentType === 'Fine') {
            // Check if there's an existing fine record linked to this payment
            $fineStmt = $conn->prepare("
                SELECT FineID FROM Fine WHERE Payment_PaymentID = ?
            ");
            $fineStmt->bind_param("s", $paymentID);
            $fineStmt->execute();
            $fineResult = $fineStmt->get_result();
            
            if ($fineResult->num_rows > 0) {
                $row = $fineResult->fetch_assoc();
                $fineID = $row['FineID'];
                
                // Update the fine record
                $updateFineStmt = $conn->prepare("
                    UPDATE Fine 
                    SET Amount = ?, 
                        Date = ?,
                        Term = ? 
                    WHERE FineID = ?
                ");
                $updateFineStmt->bind_param("diss", 
                    $amount, 
                    $date, 
                    $term, 
                    $fineID
                );
                $updateFineStmt->execute();
            } else {
                // This is a payment for a fine that doesn't have an association yet
                // In a real system, you might want to prompt the user to select a specific fine
                // For now, we'll create a placeholder fine record
                $fineID = "FINE" . time() . rand(100, 999);
                
                // Default to 'late' if no specific type is known
                $fineType = 'late';
                
                $insertFineStmt = $conn->prepare("
                    INSERT INTO Fine (
                        FineID,
                        Amount,
                        Date,
                        Term,
                        Description,
                        Member_MemberID,
                        IsPaid,
                        Payment_PaymentID
                    ) VALUES (?, ?, ?, ?, ?, ?, 'Yes', ?)
                ");
                $insertFineStmt->bind_param("sdissss", 
                    $fineID, 
                    $amount, 
                    $date, 
                    $term, 
                    $fineType, 
                    $memberID, 
                    $paymentID
                );
                $insertFineStmt->execute();
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success_message'] = "Payment #$paymentID successfully updated";
        
        // Handle redirection based on popup mode after ALL database operations are complete
        if (!$isPopup) {
            header("Location: payment.php");
            exit();
        }
        // If it's popup mode, we'll continue rendering the page with a success message
        // and add JavaScript to refresh the parent later
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error updating payment: " . $e->getMessage();
    }
}

// Payment types
$paymentTypes = [
    'monthly' => 'Monthly Fee',
    'registration' => 'Registration Fee',
    'Loan' => 'Loan Payment',
    'Fine' => 'Fine Payment'
];

// Payment methods
$paymentMethods = [
    'cash' => 'Cash',
    'online' => 'Online',
    'transfer' => 'Bank Transfer'
];

// Payment status
$paymentStatus = [
    'self' => 'self',
    'treasurer' => 'treasurer',
    'edited' => 'edited'
];

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Payment</title>
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
                justify-content: space-between;
                margin-top: 20px;
            }
            .btn {
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
            }
            .btn-primary:hover {
                background-color: #16305c;
            }
            .btn-secondary {
                padding: 0.8rem 1.8rem;
                border: none;
                border-radius: 6px;
                font-size: 1rem;
                cursor: pointer;
                background-color: #e0e0e0;
                color: #333;
                transition: background-color 0.3s;
            }

            .btn-secondary:hover {
                background-color: #d0d0d0;
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
        <title>Edit Payment</title>
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
                padding: 0.8rem 1.8rem;
                border: none;
                border-radius: 6px;
                font-size: 1rem;
                cursor: pointer;
                background-color: #e0e0e0;
                color: #333;
                transition: background-color 0.3s;
            }

            .btn-secondary:hover {
                background-color: #d0d0d0;
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
        </style>
    </head>
    <body>
        <div class="main-container">
            <?php include '../../templates/navbar-treasurer.php'; ?>
            <div class="container">
                <div class="header-card">
                    <h1>Edit Payment</h1>
                    <a href="payment.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Payments
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
                <h2 class="form-title">Edit Payment #<?php echo htmlspecialchars($paymentID); ?></h2>
                
                <div class="member-info">
                    <div class="member-info-title">Current Member Information</div>
                    <p>Member ID: <?php echo htmlspecialchars($payment['Member_MemberID']); ?></p>
                    <p>Member Name: <?php echo htmlspecialchars($payment['MemberName']); ?></p>
                </div>

                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="payment_id">Payment ID</label>
                            <input type="text" id="payment_id" class="form-control" value="<?php echo htmlspecialchars($paymentID); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="member_id">Member</label>
                            <select id="member_id" name="member_id" class="form-control" required>
                                <?php while($member = $allMembers->fetch_assoc()): ?>
                                    <option value="<?php echo $member['MemberID']; ?>" <?php echo ($member['MemberID'] == $payment['Member_MemberID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['MemberID'] . ' - ' . $member['Name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="payment_type">Payment Type</label>
                            <select id="payment_type" name="payment_type" class="form-control" required>
                                <?php foreach($paymentTypes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $payment['Payment_Type']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="method">Payment Method</label>
                            <select id="method" name="method" class="form-control" required>
                                <?php foreach($paymentMethods as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $payment['Method']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Amount (Rs.)</label>
                            <input type="number" id="amount" name="amount" class="form-control" value="<?php echo htmlspecialchars($payment['Amount']); ?>" min="0" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label for="date">Payment Date</label>
                            <input type="date" id="date" name="date" class="form-control" value="<?php echo htmlspecialchars($payment['Date']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
    <div class="form-group">
        <label for="term">Term/Year</label>
        <input type="number" id="term" name="term" class="form-control" value="<?php echo htmlspecialchars($payment['Term']); ?>" required>
    </div>
    <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status" class="form-control" required>
            <?php foreach($paymentStatus as $value => $label): ?>
                <option value="<?php echo $value; ?>" <?php echo ($value == $payment['status']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="form-group">
    <label for="notes">Notes/Additional Information</label>
    <textarea id="notes" name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($payment['Notes'] ?? ''); ?></textarea>
</div>

<div class="btn-container">
    <?php if ($isPopup): ?>
        <button type="button" class="btn-secondary" onclick="window.parent.closeEditModal()">Cancel</button>
    <?php else: ?>
        <a href="payment.php" class="btn-secondary">Cancel</a>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Update Payment</button>
</div>
</form>
</div>

<?php if ($isPopup): ?>
    </div>
    
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_SESSION['error_message'])): ?>
    <script>
        // If form was submitted successfully in popup mode, pass message to parent
        window.parent.showAlert('success', 'Payment #<?php echo $paymentID; ?> successfully updated');
        window.parent.closeEditModal();
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
    // Payment type validation and dynamic fields
    document.getElementById('payment_type').addEventListener('change', function() {
        // You can add dynamic field changes based on payment type here
    });

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('amount').value);
        
        if (isNaN(amount) || amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid amount greater than zero.');
        }
    });
</script>