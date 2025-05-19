<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

date_default_timezone_set('Asia/Colombo');

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
            p.Status,
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

// Function to get Static values
function getStaticValues() {
    $sql = "SELECT * FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    return $result->fetch_assoc();
}

// Function to get MembershipFee payment details 
function getMembershipFeePayments($paymentID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT mfp.FeeID, mf.Amount, mf.Date, mf.Term, mf.Type, mfp.Details
        FROM MembershipFeePayment mfp
        JOIN MembershipFee mf ON mfp.FeeID = mf.FeeID
        WHERE mfp.PaymentID = ?
    ");
    
    $stmt->bind_param("s", $paymentID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $membershipFees = array();
    while ($row = $result->fetch_assoc()) {
        $membershipFees[] = $row;
    }
    
    return $membershipFees;
}

// Function to get Fine payment details
function getFinePayments($paymentID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT fp.FineID, f.Amount, f.Date, f.Term, f.Description
        FROM FinePayment fp
        JOIN Fine f ON fp.FineID = f.FineID
        WHERE fp.PaymentID = ?
    ");
    
    $stmt->bind_param("s", $paymentID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $fines = array();
    while ($row = $result->fetch_assoc()) {
        $fines[] = $row;
    }
    
    return $fines;
}

function generateFeeId() {
    try {
        $conn = getConnection();
        $conn->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        
        // Get current active year
        $currentYear = getCurrentTerm();
        $yearSuffix = substr($currentYear, -2); // Last two digits of year
        $feePrefix = "FEE" . $yearSuffix;
        
        // Get highest sequential number for the current year prefix
        $query = "SELECT MAX(CAST(SUBSTRING(FeeID, 6) AS UNSIGNED)) as max_num 
                 FROM MembershipFee 
                 WHERE FeeID LIKE '$feePrefix%'
                 FOR UPDATE";
        
        $result = $conn->query($query);
        
        // Determine the next number
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $nextNum = $row['max_num'] ? $row['max_num'] + 1 : 1;
        } else {
            $nextNum = 1;
        }
        
        // Ensure sequential numbers are always at least 2 digits
        $newId = $feePrefix . str_pad($nextNum, 2, '0', STR_PAD_LEFT);
        
        // Verify it doesn't exist (double check)
        $verifyQuery = "SELECT COUNT(*) as count FROM MembershipFee WHERE FeeID = ?";
        $stmt = $conn->prepare($verifyQuery);
        $stmt->bind_param("s", $newId);
        $stmt->execute();
        $verifyResult = $stmt->get_result();
        
        if ($verifyResult->fetch_assoc()['count'] > 0) {
            $conn->rollback();
            throw new Exception("Generated Fee ID already exists: " . $newId);
        }
        
        $stmt->close();
        
        // Commit the transaction
        $conn->commit();
        
        return $newId;
        
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        throw new Exception("Error generating fee ID: " . $e->getMessage());
    }
}

// Function to get Loan payment details
function getLoanPayments($paymentID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT lp.LoanID, l.Amount, l.Issued_Date, l.Paid_Loan, l.Remain_Loan, 
               l.Paid_Interest, l.Remain_Interest
        FROM LoanPayment lp
        JOIN Loan l ON lp.LoanID = l.LoanID
        WHERE lp.PaymentID = ?
    ");
    
    $stmt->bind_param("s", $paymentID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $loans = array();
    while ($row = $result->fetch_assoc()) {
        $loans[] = $row;
    }
    
    return $loans;
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
$staticValues = getStaticValues();

// Check if payment can be edited based on status
// FIXED: Modified the logic for editing permissions
$canEditAll = ($payment['Status'] === 'self'); // Only self payments can be fully edited
$canEditLimited = ($payment['Status'] === 'treasurer'); // Treasurer status has limited editing
$canEditNotes = ($payment['Status'] === 'self' || $payment['Status'] === 'treasurer'); // Both self and treasurer can edit notes

// Get associated membership fees or fines if applicable
$membershipFees = [];
$fines = [];
$loans = [];
if ($payment['Payment_Type'] === 'monthly' || $payment['Payment_Type'] === 'registration') {
    $membershipFees = getMembershipFeePayments($paymentID);
} else if ($payment['Payment_Type'] === 'fine') {
    $fines = getFinePayments($paymentID);
} else if ($payment['Payment_Type'] === 'loan') {
    $loans = getLoanPayments($paymentID);
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // If status is "edited", prevent all changes
    if ($payment['Status'] === 'edited') {
        $_SESSION['error_message'] = "This payment has already been edited and cannot be modified.";
        if (!$isPopup) {
            header("Location: payment.php");
            exit();
        }
    } 
    // If status is "treasurer", only allow specific fields to be changed
    else if ($payment['Status'] === 'treasurer') {
        // FIXED: Only allow amount, date, and notes to be edited for treasurer status
        $amount = floatval($_POST['amount'] ?? $payment['Amount']);
        $date = $_POST['date'] ?? $payment['Date'];
        $notes = $_POST['notes'] ?? $payment['Notes'];
        
        // Keep original values for these fields
        $memberID = $payment['Member_MemberID'];
        $paymentType = $payment['Payment_Type'];
        $method = $payment['Method'];
        $term = $payment['Term'];
        
        try {
            $conn = getConnection();
            $conn->begin_transaction();
            
            // Validate date - cannot be in the future
            $currentDate = date('Y-m-d');
            if ($date > $currentDate) {
                throw new Exception("Payment date cannot be in the future");
            }
            
            // Specific validations based on payment type
            if ($paymentType === 'monthly') {
                $monthlyFee = floatval($staticValues['monthly_fee']);
                
                // Amount should be multiples of monthly_fee
                if ($amount % $monthlyFee != 0) {
                    throw new Exception("Monthly payment amount must be a multiple of Rs. " . $monthlyFee);
                }
                
                $numMonths = $amount / $monthlyFee;
                $currentNumMonths = $payment['Amount'] / $monthlyFee;
                
                // Handle changes in monthly fee payments
                if ($amount != $payment['Amount']) {
                    // Get existing membership fees for this payment
                    $existingFees = getMembershipFeePayments($paymentID);
                    
                    if ($amount > $payment['Amount']) {
                        // Add new membership fees
                        $additionalMonths = $numMonths - $currentNumMonths;
                        
                        // Get the last month paid
                        $lastDate = null;
                        if (count($existingFees) > 0) {
                            usort($existingFees, function($a, $b) {
                                return strtotime($a['Date']) - strtotime($b['Date']);
                            });
                            $lastDate = end($existingFees)['Date'];
                        } else {
                            $lastDate = $date;
                        }
                        
                        // Add new membership fees for additional months
                        for ($i = 0; $i < $additionalMonths; $i++) {
                            // Generate new FeeID
                            try {
                                $newFeeID = generateFeeId();
                            } catch (Exception $e) {
                                // Handle error
                                throw new Exception("Failed to generate new Fee ID: " . $e->getMessage());
                            }
                            
                            // Calculate next month date
                            $nextMonthDate = date('Y-m-d', strtotime($lastDate . ' +' . ($i + 1) . ' month'));
                            
                            // Insert new membership fee
                            $stmt = $conn->prepare("
                                INSERT INTO MembershipFee 
                                (FeeID, Amount, Date, Term, Type, Member_MemberID, IsPaid) 
                                VALUES (?, ?, ?, ?, 'monthly', ?, 'Yes')
                            ");
                            $stmt->bind_param("sssis", $newFeeID, $monthlyFee, $nextMonthDate, $term, $memberID);
                            $stmt->execute();
                            
                            // Link to payment
                            $stmt = $conn->prepare("
                                INSERT INTO MembershipFeePayment 
                                (FeeID, PaymentID, Details) 
                                VALUES (?, ?, 'Added during payment edit')
                            ");
                            $stmt->bind_param("ss", $newFeeID, $paymentID);
                            $stmt->execute();
                        }
                    } else if ($amount < $payment['Amount'] && count($existingFees) > 1) {
                        // Remove membership fees
                        $feesToRemove = $currentNumMonths - $numMonths;
                        
                        // Sort fees by date (newest first)
                        usort($existingFees, function($a, $b) {
                            return strtotime($b['Date']) - strtotime($a['Date']); // Sort in descending order
                        });
                        
                        // Remove the newest fees
                        for ($i = 0; $i < $feesToRemove && $i < count($existingFees); $i++) {
                            $feeToRemove = $existingFees[$i]['FeeID'];
                            
                            // Remove from MembershipFeePayment
                            $stmt = $conn->prepare("DELETE FROM MembershipFeePayment WHERE FeeID = ? AND PaymentID = ?");
                            $stmt->bind_param("ss", $feeToRemove, $paymentID);
                            $stmt->execute();
                            
                            // Remove from MembershipFee
                            $stmt = $conn->prepare("DELETE FROM MembershipFee WHERE FeeID = ?");
                            $stmt->bind_param("s", $feeToRemove);
                            $stmt->execute();
                        }
                    } else if ($amount < $payment['Amount'] && count($existingFees) <= 1) {
                        throw new Exception("Cannot reduce monthly payment amount when only one month is paid");
                    }
                }
            } else if ($paymentType === 'registration') {
                $registrationFee = floatval($staticValues['registration_fee']);
                
                // Amount cannot be 0 and should not exceed registration fee
                if ($amount <= 0) {
                    throw new Exception("Registration fee amount cannot be zero");
                }
                if ($amount > $registrationFee) {
                    throw new Exception("Registration fee amount cannot exceed Rs. " . $registrationFee);
                }
                
                // Update membership fee details if amount changed
                if ($amount != $payment['Amount'] && count($membershipFees) > 0) {
                    foreach ($membershipFees as $fee) {
                        $stmt = $conn->prepare("
                            UPDATE MembershipFee 
                            SET Amount = ? 
                            WHERE FeeID = ?
                        ");
                        $stmt->bind_param("ds", $amount, $fee['FeeID']);
                        $stmt->execute();
                    }
                }
            } else if ($paymentType === 'fine') {
                // Cannot edit amount for fine payments
                if ($amount != $payment['Amount']) {
                    throw new Exception("Fine payment amount cannot be edited as it is fixed");
                }
            } else if ($paymentType === 'loan') {
                // Get the loan ID associated with this payment
                $loanID = null;
                $stmt = $conn->prepare("SELECT LoanID FROM LoanPayment WHERE PaymentID = ?");
                $stmt->bind_param("s", $paymentID);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $loanRow = $result->fetch_assoc();
                    $loanID = $loanRow['LoanID'];
                } else {
                    throw new Exception("No loan associated with this payment");
                }
                
                // Get current loan details
                $stmt = $conn->prepare("SELECT Amount, Paid_Loan, Remain_Loan, Paid_Interest, Remain_Interest FROM Loan 
                                    WHERE LoanID = ? AND Member_MemberID = ? AND Status = 'approved'");
                $stmt->bind_param("ss", $loanID, $memberID);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Invalid loan or loan not approved");
                }
                
                $loanData = $result->fetch_assoc();
                
                // Calculate the total remaining loan amount
                $totalRemaining = $loanData['Remain_Loan'] + $loanData['Remain_Interest'];
                
                // Store original payment amount for comparison
                $originalAmount = $payment['Amount'];
                
                // Validate new payment amount
                if ($amount <= 0) {
                    throw new Exception("Loan payment amount must be greater than zero");
                }
                
                if ($amount > $originalAmount + $totalRemaining) {
                    throw new Exception("Payment amount cannot exceed the remaining loan balance plus original payment");
                }
                
                // Calculate the difference in payment
                $amountDifference = $amount - $originalAmount;
                
                if ($amountDifference != 0) {
                    // If the amount is increased
                    if ($amountDifference > 0) {
                        // Calculate additional interest and principal portions
                        $additionalInterestPayment = min($amountDifference, $loanData['Remain_Interest']);
                        $additionalPrincipalPayment = $amountDifference - $additionalInterestPayment;
                        
                        // Update loan record with increased payment
                        $updateLoanQuery = "UPDATE Loan SET 
                                        Paid_Loan = Paid_Loan + ?,
                                        Remain_Loan = Remain_Loan - ?,
                                        Paid_Interest = Paid_Interest + ?,
                                        Remain_Interest = Remain_Interest - ?
                                        WHERE LoanID = ?";
                        
                        $stmt = $conn->prepare($updateLoanQuery);
                        $stmt->bind_param("dddds", $additionalPrincipalPayment, $additionalPrincipalPayment, 
                                        $additionalInterestPayment, $additionalInterestPayment, $loanID);
                        $stmt->execute();
                    } 
                    // If the amount is decreased
                    else {
                        $decreaseAmount = abs($amountDifference);
                        
                        // When reducing payment, we need to first revert principal, then interest
                        // (opposite of how payment is applied)
                        
                        // Get current values after original payment was applied
                        $currentPaidLoan = $loanData['Paid_Loan'];
                        $currentPaidInterest = $loanData['Paid_Interest'];
                        
                        // Calculate how much to take from principal vs interest
                        $principalDecrease = min($decreaseAmount, $currentPaidLoan);
                        $interestDecrease = $decreaseAmount - $principalDecrease;
                        
                        // Make sure we don't reduce below 0
                        $interestDecrease = min($interestDecrease, $currentPaidInterest);
                        
                        // Update loan record with decreased payment
                        $updateLoanQuery = "UPDATE Loan SET 
                                        Paid_Loan = Paid_Loan - ?,
                                        Remain_Loan = Remain_Loan + ?,
                                        Paid_Interest = Paid_Interest - ?,
                                        Remain_Interest = Remain_Interest + ?
                                        WHERE LoanID = ?";
                        
                        $stmt = $conn->prepare($updateLoanQuery);
                        $stmt->bind_param("dddds", $principalDecrease, $principalDecrease, 
                                        $interestDecrease, $interestDecrease, $loanID);
                        $stmt->execute();
                    }
                }
            }
                        
            // Update payment record - FIXED: Only update allowed fields for treasurer status
            $stmt = $conn->prepare("
                UPDATE Payment SET 
                    Amount = ?,
                    Date = ?,
                    Notes = ?
                WHERE PaymentID = ?
            ");
            
            $stmt->bind_param("dsss", $amount, $date, $notes, $paymentID);
            $stmt->execute();
            
            $conn->commit();
            
            // Set success message
            $_SESSION['success_message'] = "Payment #" . $paymentID . " successfully updated";
            
            // Handle redirection based on popup mode
            if (!$isPopup) {
                header("Location: payment.php");
                exit();
            }
        } catch (Exception $e) {
            if (isset($conn) && $conn instanceof mysqli) {
                $conn->rollback();
            }
            $_SESSION['error_message'] = "Error updating payment: " . $e->getMessage();
        }
    }
    // If status is "self", only allow notes to be changed
    else if ($payment['Status'] === 'self') {
        $notes = $_POST['notes'] ?? '';
        
        try {
            $conn = getConnection();
            
            // Update only notes
            $stmt = $conn->prepare("
                UPDATE Payment SET 
                    Notes = ?
                WHERE PaymentID = ?
            ");
            
            $stmt->bind_param("ss", $notes, $paymentID);
            $stmt->execute();
            
            // Set success message
            $_SESSION['success_message'] = "Payment notes successfully updated";
            
            // Handle redirection based on popup mode
            if (!$isPopup) {
                header("Location: payment.php");
                exit();
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating payment notes: " . $e->getMessage();
        }
    }
}

// Payment types
$paymentTypes = [
    'monthly' => 'Monthly Fee',
    'registration' => 'Registration Fee',
    'loan' => 'Loan Payment',
    'fine' => 'Fine Payment'
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
            .status-notice {
                background-color: #fff3cd;
                color: #856404;
                padding: 10px;
                border-radius: 4px;
                margin-bottom: 15px;
                border: 1px solid #ffeeba;
            }
            .payment-info {
                background-color: #e8f4ff;
                padding: 12px;
                border-radius: 5px;
                margin-bottom: 15px;
                font-size: 14px;
            }
            .payment-info-title {
                font-weight: 600;
                margin-bottom: 8px;
                color: #1e3c72;
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
            
            .status-notice {
                background-color: #fff3cd;
                color: #856404;
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 20px;
                border: 1px solid #ffeeba;
                font-weight: 500;
            }
            
            .payment-info {
                background-color: #e8f4ff;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
                font-size: 15px;
            }
            
            .payment-info-title {
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
                // Store in localStorage for persistence
                echo "<script>localStorage.setItem('success_message', '" . addslashes($_SESSION['success_message']) . "');</script>";
                unset($_SESSION['success_message']);
            ?>
        </div>
    <?php endif; ?>

    <?php if(isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger">
            <?php 
                echo $_SESSION['error_message'];
                // Store in localStorage for persistence
                echo "<script>localStorage.setItem('error_message', '" . addslashes($_SESSION['error_message']) . "');</script>";
                unset($_SESSION['error_message']);
            ?>
        </div>
    <?php endif; ?>
</div>

            <div class="form-container">
                <h2 class="form-title">Edit Payment #<?php echo htmlspecialchars($paymentID); ?></h2>
                
                <!-- Display status notice based on payment status -->
                <?php if ($payment['Status'] === 'edited'): ?>
                <div class="status-notice">
                    <i class="fas fa-exclamation-triangle"></i> This payment has already been edited and cannot be modified further.
                </div>
                <?php elseif ($payment['Status'] === 'self'): ?>
                <div class="status-notice">
                    <i class="fas fa-info-circle"></i> This payment was made by the member. Only the notes field can be edited.
                </div>
                <?php elseif ($payment['Status'] === 'treasurer'): ?>
                <div class="status-notice">
                    <i class="fas fa-info-circle"></i> This payment was made by a treasurer. Only the amount, date, and notes can be modified. Payment type, method, and term cannot be changed.
                </div>
                <?php endif; ?>
                
                <div class="member-info">
                    <div class="member-info-title">Current Member Information</div>
                    <p>Member ID: <?php echo htmlspecialchars($payment['Member_MemberID']); ?></p>
                    <p>Member Name: <?php echo htmlspecialchars($payment['MemberName']); ?></p>
                </div>
                
                <?php if ($payment['Payment_Type'] === 'monthly' && count($membershipFees) > 0): ?>
                <div class="payment-info">
                    <div class="payment-info-title">Monthly Fee Information</div>
                    <p><strong>Total Months Paid:</strong> <?php echo count($membershipFees); ?></p>
                    <p><strong>Monthly Fee Amount:</strong> Rs. <?php echo htmlspecialchars(number_format($staticValues['monthly_fee'], 2)); ?></p>
                    <p><strong>Current Total Amount:</strong> Rs. <?php echo htmlspecialchars(number_format($payment['Amount'], 2)); ?></p>
                    <p><i class="fas fa-info-circle"></i> Changing the amount will add or remove monthly fee entries.</p>
                </div>
                <?php elseif ($payment['Payment_Type'] === 'registration'): ?>
                <div class="payment-info">
                    <div class="payment-info-title">Registration Fee Information</div>
                    <p><strong>Maximum Registration Fee:</strong> Rs. <?php echo htmlspecialchars(number_format($staticValues['registration_fee'], 2)); ?></p>
                    <p><i class="fas fa-info-circle"></i> Registration fee cannot be zero and should not exceed the maximum.</p>
                </div>
                <?php elseif ($payment['Payment_Type'] === 'fine' && count($fines) > 0): ?>
                <div class="payment-info">
                    <div class="payment-info-title">Fine Information</div>
                    <?php foreach ($fines as $fine): ?>
                    <p><strong>Fine ID:</strong> <?php echo htmlspecialchars($fine['FineID']); ?></p>
                    <p><strong>Fine Type:</strong> <?php echo htmlspecialchars(ucfirst($fine['Description'])); ?></p>
                    <p><strong>Amount:</strong> Rs. <?php echo htmlspecialchars(number_format($fine['Amount'], 2)); ?></p>
                    <p><strong>Date:</strong> <?php echo htmlspecialchars($fine['Date']); ?></p>
                    <?php endforeach; ?>
                    <p><i class="fas fa-exclamation-circle"></i> Fine payment amounts cannot be edited as they are fixed values.</p>
                </div>
                <?php elseif ($payment['Payment_Type'] === 'loan' && count($loans) > 0): ?>
                <div class="payment-info">
                    <div class="payment-info-title">Loan Payment Information</div>
                    <?php foreach ($loans as $loan): ?>
                    <p><strong>Loan ID:</strong> <?php echo htmlspecialchars($loan['LoanID']); ?></p>
                    <p><strong>Original Loan Amount:</strong> Rs. <?php echo htmlspecialchars(number_format($loan['Amount'], 2)); ?></p>
                    <p><strong>Paid Principal:</strong> Rs. <?php echo htmlspecialchars(number_format($loan['Paid_Loan'], 2)); ?></p>
                    <p><strong>Remaining Principal:</strong> Rs. <?php echo htmlspecialchars(number_format($loan['Remain_Loan'], 2)); ?></p>
                    <p><strong>Paid Interest:</strong> Rs. <?php echo htmlspecialchars(number_format($loan['Paid_Interest'], 2)); ?></p>
                    <p><strong>Remaining Interest:</strong> Rs. <?php echo htmlspecialchars(number_format($loan['Remain_Interest'], 2)); ?></p>
                    <p><strong>Total Remaining:</strong> Rs. <?php echo htmlspecialchars(number_format($loan['Remain_Loan'] + $loan['Remain_Interest'], 2)); ?></p>
                    <?php endforeach; ?>
                    <p><i class="fas fa-info-circle"></i> Editing the payment amount will adjust the loan's paid and remaining amounts.</p>
                </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="payment_id">Payment ID</label>
                            <input type="text" id="payment_id" class="form-control" value="<?php echo htmlspecialchars($paymentID); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="member_id">Member</label>
                            <select id="member_id" name="member_id" class="form-control" required <?php echo !$canEditAll ? 'disabled' : ''; ?>>
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
                            <select id="payment_type" name="payment_type" class="form-control" required <?php echo !$canEditAll ? 'disabled' : ''; ?>>
                                <?php foreach($paymentTypes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $payment['Payment_Type']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="method">Payment Method</label>
                            <select id="method" name="method" class="form-control" required <?php echo !$canEditAll ? 'disabled' : ''; ?>>
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
                            <input type="number" id="amount" name="amount" class="form-control" 
                                value="<?php echo htmlspecialchars($payment['Amount']); ?>" 
                                min="0" step="0.01" required 
                                <?php echo (($payment['Status'] === 'edited') || 
                                            ($payment['Status'] === 'self') || 
                                            ($payment['Payment_Type'] === 'fine')) ? 'disabled' : ''; ?>>
                            <?php if ($payment['Payment_Type'] === 'monthly' && ($canEditAll || $canEditLimited)): ?>
                            <small class="form-text text-muted">
                                Amount must be a multiple of Rs. <?php echo number_format($staticValues['monthly_fee'], 2); ?>
                            </small>
                            <?php elseif ($payment['Payment_Type'] === 'registration' && ($canEditAll || $canEditLimited)): ?>
                            <small class="form-text text-muted">
                                Amount must be greater than 0 and not exceed Rs. <?php echo number_format($staticValues['registration_fee'], 2); ?>
                            </small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="date">Payment Date</label>
                            <input type="date" id="date" name="date" class="form-control" 
                                value="<?php echo htmlspecialchars($payment['Date']); ?>" 
                                required 
                                <?php echo (($payment['Status'] === 'edited') || 
                                            ($payment['Status'] === 'self')) ? 'disabled' : ''; ?> 
                                max="<?php echo date('Y-m-d'); ?>">
                            <small class="form-text text-muted">Date cannot be in the future</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="term">Term/Year</label>
                            <input type="number" id="term" name="term" class="form-control" value="<?php echo htmlspecialchars($payment['Term']); ?>" required <?php echo !$canEditAll ? 'disabled' : ''; ?>>
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control" disabled>
                                <?php foreach($paymentStatus as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $payment['Status']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <!-- Keep the original status value when form is submitted -->
                            <?php if ($canEditAll): ?>
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($payment['Status']); ?>">
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes/Additional Information</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3" <?php echo ($payment['Status'] === 'edited') ? 'disabled' : ''; ?>><?php echo htmlspecialchars($payment['Notes'] ?? ''); ?></textarea>
                    </div>

                    <?php if ($payment['Payment_Type'] === 'loan' && count($loans) > 0): ?>
                    <input type="hidden" name="loan_id" value="<?php echo htmlspecialchars($loans[0]['LoanID']); ?>">
                    <?php endif; ?>

                    <div class="btn-container">
                        <?php if ($isPopup): ?>
                            <button type="button" class="btn-secondary" onclick="window.parent.closeEditModal()">Cancel</button>
                        <?php else: ?>
                            <a href="payment.php" class="btn-secondary">Cancel</a>
                        <?php endif; ?>
                        
                        <?php if ($payment['Status'] !== 'edited'): ?>
                            <button type="submit" class="btn btn-primary">
                                <?php 
                                    if ($payment['Status'] === 'self') {
                                        echo 'Update Notes';
                                    } elseif ($payment['Status'] === 'treasurer') {
                                        echo 'Update Limited Fields';
                                    } else {
                                        echo 'Update Payment';
                                    }
                                ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if ($isPopup): ?>
                </div>
                
                <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_SESSION['error_message'])): ?>
                <script>
                    // Store success message in localStorage instead of showing it immediately
                    localStorage.setItem('payment_alert_type', 'success');
                    localStorage.setItem('payment_alert_message', 'Payment #<?php echo $paymentID; ?> successfully updated');
                    window.parent.closeEditModal();
                </script>
                <?php elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['error_message'])): ?>
                <script>
                    // Store error message in localStorage
                    localStorage.setItem('payment_alert_type', 'error');
                    localStorage.setItem('payment_alert_message', '<?php echo addslashes($_SESSION['error_message']); ?>');
                    // Don't close the modal if there's an error
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
            // Form validation
            document.querySelector('form').addEventListener('submit', function(e) {
                <?php if ($payment['Status'] === 'edited'): ?>
                    e.preventDefault();
                    alert('This payment has already been edited and cannot be modified further.');
                    return;
                <?php elseif ($payment['Status'] === 'self'): ?>
                    // Only validate notes for self payments
                    return;
                <?php else: ?>
                const amount = parseFloat(document.getElementById('amount').value);
                const today = new Date();
                today.setHours(0, 0, 0, 0); // Reset time part for proper comparison
                
                // Get input date and ensure it's interpreted as local date
                const dateInput = document.getElementById('date').value;
                const dateParts = dateInput.split('-');
                // Create date using year, month (0-based), day
                const date = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);
                date.setHours(0, 0, 0, 0);
                
                // Now compare the dates without time components
                if (date > today) {
                    e.preventDefault();
                    alert('Payment date cannot be in the future.');
                    return;
                }
                
                if (isNaN(amount) || amount <= 0) {
                    e.preventDefault();
                    alert('Please enter a valid amount greater than zero.');
                    return;
                }
                
                // Get original values for validation
                const paymentType = '<?php echo $payment['Payment_Type']; ?>';
                
                // Specific validations based on payment type
                if (paymentType === 'monthly') {
                    const monthlyFee = <?php echo floatval($staticValues['monthly_fee']); ?>;
                    if (amount % monthlyFee !== 0) {
                        e.preventDefault();
                        alert('Monthly payment amount must be a multiple of Rs. ' + monthlyFee.toFixed(2));
                        return;
                    }
                } else if (paymentType === 'registration') {
                    const registrationFee = <?php echo floatval($staticValues['registration_fee']); ?>;
                    if (amount <= 0) {
                        e.preventDefault();
                        alert('Registration fee amount cannot be zero.');
                        return;
                    }
                    if (amount > registrationFee) {
                        e.preventDefault();
                        alert('Registration fee amount cannot exceed Rs. ' + registrationFee.toFixed(2));
                        return;
                    }
                } else if (paymentType === 'fine') {
                    const originalAmount = <?php echo floatval($payment['Amount']); ?>;
                    if (amount !== originalAmount) {
                        e.preventDefault();
                        alert('Fine payment amount cannot be modified.');
                        return;
                    }
                } else if (paymentType === 'loan') {
                    // Get current values from the displayed loan info
                    const remainingLoan = parseFloat('<?php echo isset($loans[0]["Remain_Loan"]) ? $loans[0]["Remain_Loan"] : 0; ?>');
                    const remainingInterest = parseFloat('<?php echo isset($loans[0]["Remain_Interest"]) ? $loans[0]["Remain_Interest"] : 0; ?>');
                    const originalAmount = parseFloat('<?php echo $payment["Amount"]; ?>');
                    const totalRemaining = remainingLoan + remainingInterest;
                    
                    if (amount <= 0) {
                        e.preventDefault();
                        alert('Loan payment amount must be greater than zero.');
                        return;
                    }
                    
                    if (amount > originalAmount + totalRemaining) {
                        e.preventDefault();
                        alert('Payment amount cannot exceed the remaining loan balance plus original payment (Rs. ' + 
                            (originalAmount + totalRemaining).toFixed(2) + ')');
                        return;
                    }
                }
                <?php endif; ?>
            });

            // Add payment type change listener
            document.getElementById('payment_type')?.addEventListener('change', function() {
                const paymentType = this.value;
                const amountField = document.getElementById('amount');
                const amountHelp = amountField.nextElementSibling;
                
                if (paymentType === 'monthly') {
                    amountHelp.innerHTML = 'Amount must be a multiple of Rs. <?php echo number_format($staticValues['monthly_fee'], 2); ?>';
                    amountField.disabled = <?php echo !$canEditAll ? 'true' : 'false'; ?>;
                } else if (paymentType === 'registration') {
                    amountHelp.innerHTML = 'Amount must be greater than 0 and not exceed Rs. <?php echo number_format($staticValues['registration_fee'], 2); ?>';
                    amountField.disabled = <?php echo !$canEditAll ? 'true' : 'false'; ?>;
                } else if (paymentType === 'fine') {
                    amountHelp.innerHTML = 'Fine payment amounts cannot be modified.';
                    amountField.disabled = true;
                } else if (paymentType === 'loan') {
                    amountHelp.innerHTML = 'Amount must be greater than 0 and cannot exceed the remaining loan balance plus current payment.';
                    amountField.disabled = <?php echo !$canEditAll ? 'true' : 'false'; ?>;
                } else {
                    amountHelp.innerHTML = '';
                    amountField.disabled = <?php echo !$canEditAll ? 'true' : 'false'; ?>;
                }
            });

            // NEW CODE: Check for stored alerts on page load
            document.addEventListener('DOMContentLoaded', function() {
                // Check for saved success message
                let successMsg = localStorage.getItem('success_message');
                if (successMsg) {
                    // Create a success alert
                    let alertContainer = document.querySelector('.alerts-container');
                    let alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-success';
                    alertDiv.textContent = successMsg;
                    
                    // Add to the page
                    alertContainer.appendChild(alertDiv);
                    
                    // Remove from storage after showing
                    localStorage.removeItem('success_message');
                }
                
                // Check for saved error message
                let errorMsg = localStorage.getItem('error_message');
                if (errorMsg) {
                    // Create an error alert
                    let alertContainer = document.querySelector('.alerts-container');
                    let alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-danger';
                    alertDiv.textContent = errorMsg;
                    
                    // Add to the page
                    alertContainer.appendChild(alertDiv);
                    
                    // Remove from storage after showing
                    localStorage.removeItem('error_message');
                }
            });
        </script>