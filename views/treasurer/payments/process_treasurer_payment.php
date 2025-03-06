<?php
session_start();
require_once "../../../config/database.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: treasurerPayment.php');
    exit();
}

try {
    // Validate inputs
    $required_fields = ['member_id', 'payment_type', 'amount', 'payment_method', 'year'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Sanitize and prepare input data
    $memberId = $_POST['member_id'];
    $paymentType = $_POST['payment_type'];
    $amount = floatval($_POST['amount']);
    $year = intval($_POST['year']);
    $date = date('Y-m-d');
    $method = 'onhand'; // Fixed payment method for treasurer
    $treasurerId = $_SESSION['treasurer_id'];

    // Start transaction
    $conn = getConnection();
    $conn->begin_transaction();

    // Generate payment ID
    $paymentId = generatePaymentId();

    // Get fee structure for the year
    $query = "SELECT * FROM Static WHERE year = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Fee structure not found for selected year");
    }
    $feeStructure = $result->fetch_assoc();
    $stmt->close();

    // First, always insert the payment record
    $paymentQuery = "INSERT INTO Payment (
        PaymentID, 
        Payment_Type, 
        Method, 
        Amount, 
        Date, 
        Term, 
        Member_MemberID
    ) VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($paymentQuery);
    $stmt->bind_param("sssdsss", $paymentId, $paymentType, $method, $amount, $date, $year, $memberId);
    $stmt->execute();
    $stmt->close();

    // Process different payment types
    switch($paymentType) {
        case 'registration':
            // Validate maximum payment amount
            $regFeePaidQuery = "SELECT COALESCE(SUM(P.Amount), 0) as total_paid
                                FROM MembershipFee MF
                                JOIN MembershipFeePayment MFP ON MF.FeeID = MFP.FeeID
                                JOIN Payment P ON MFP.PaymentID = P.PaymentID
                                WHERE MF.Member_MemberID = ?
                                AND MF.Type = 'registration'
                                AND MF.Term = ?";
            
            $stmt = $conn->prepare($regFeePaidQuery);
            $stmt->bind_param("si", $memberId, $year);
            $stmt->execute();
            $regFeePaidResult = $stmt->get_result();
            $regFeePaid = $regFeePaidResult->fetch_assoc()['total_paid'];
            $remainingRegFee = $feeStructure['registration_fee'] - $regFeePaid;
            $stmt->close();
        
            if ($amount > $remainingRegFee) {
                throw new Exception("Payment amount cannot exceed remaining registration fee");
            }
        
            // Always create a new registration fee record for the payment amount
            $feeId = generateFeeId();
            $feeQuery = "INSERT INTO MembershipFee (
                FeeID, 
                Amount, 
                Date, 
                Term, 
                Type, 
                Member_MemberID, 
                IsPaid
            ) VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $type = 'registration';
            $isPaid = 'Yes';
            
            $stmt = $conn->prepare($feeQuery);
            $stmt->bind_param("sdsisss", $feeId, $amount, $date, $year, $type, $memberId, $isPaid);
            $stmt->execute();
            $stmt->close();
        
            // Link payment to membership fee
            $linkQuery = "INSERT INTO MembershipFeePayment (FeeID, PaymentID) VALUES (?, ?)";
            $stmt = $conn->prepare($linkQuery);
            $stmt->bind_param("ss", $feeId, $paymentId);
            $stmt->execute();
            $stmt->close();
        
            // Check if registration fee is fully paid
            $newTotalPaid = $regFeePaid + $amount;
            if ($newTotalPaid >= $feeStructure['registration_fee']) {
                // Update member status
                $status = 'Full Member';
                $updateMemberQuery = "UPDATE Member SET Status = ? WHERE MemberID = ?";
                $stmt = $conn->prepare($updateMemberQuery);
                $stmt->bind_param("ss", $status, $memberId);
                $stmt->execute();
                $stmt->close();
            }
            break;

        case 'monthly':
            if (!isset($_POST['selected_months']) || empty($_POST['selected_months'])) {
                throw new Exception("No months selected for monthly fee");
            }

            $selectedMonths = $_POST['selected_months'];
            $expectedAmount = count($selectedMonths) * floatval($feeStructure['monthly_fee']);
            
            if ($amount != $expectedAmount) {
                throw new Exception("Invalid monthly fee amount");
            }

            // Process each selected month
            foreach ($selectedMonths as $month) {
                $feeId = generateFeeId();
                $monthDate = date('Y-m-d', strtotime("$year-$month-01"));
                
                $monthlyFeeAmount = floatval($feeStructure['monthly_fee']);
                $monthlyFeeQuery = "INSERT INTO MembershipFee (
                    FeeID, 
                    Amount, 
                    Date, 
                    Term, 
                    Type, 
                    Member_MemberID, 
                    IsPaid
                ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $type = 'monthly';
                $isPaid = 'Yes';
                
                $stmt = $conn->prepare($monthlyFeeQuery);
                $stmt->bind_param("sdsisss", $feeId, $monthlyFeeAmount, $monthDate, $year, $type, $memberId, $isPaid);
                $stmt->execute();
                $stmt->close();

                // Link payment to monthly fee
                $linkQuery = "INSERT INTO MembershipFeePayment (FeeID, PaymentID) VALUES (?, ?)";
                $stmt = $conn->prepare($linkQuery);
                $stmt->bind_param("ss", $feeId, $paymentId);
                $stmt->execute();
                $stmt->close();
            }
            break;

        case 'fine':
            if (!isset($_POST['fine_id'])) {
                throw new Exception("Fine ID is required");
            }

            $fineId = $_POST['fine_id'];
            
            // Validate fine payment
            $fineQuery = "SELECT Amount FROM Fine 
                         WHERE FineID = ? 
                         AND Member_MemberID = ? 
                         AND IsPaid = 'No'";
            
            $stmt = $conn->prepare($fineQuery);
            $stmt->bind_param("ss", $fineId, $memberId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Invalid fine payment");
            }

            $fineData = $result->fetch_assoc();
            $stmt->close();
            
            if ($amount != $fineData['Amount']) {
                throw new Exception("Invalid fine amount");
            }

            // Update fine record
            $isPaid = 'Yes';
            $updateFineQuery = "UPDATE Fine SET 
                               IsPaid = ?,
                               Payment_PaymentID = ? 
                               WHERE FineID = ?";
            
            $stmt = $conn->prepare($updateFineQuery);
            $stmt->bind_param("sss", $isPaid, $paymentId, $fineId);
            $stmt->execute();
            $stmt->close();
            break;

        case 'loan':
            if (!isset($_POST['loan_id'])) {
                throw new Exception("Loan ID is required");
            }

            $loanId = $_POST['loan_id'];

            // Validate loan payment
            $loanQuery = "SELECT Amount, Remain_Loan, Remain_Interest FROM Loan 
                         WHERE LoanID = ? 
                         AND Member_MemberID = ? 
                         AND Status = 'approved'";
            
            $stmt = $conn->prepare($loanQuery);
            $stmt->bind_param("ss", $loanId, $memberId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Invalid loan payment");
            }

            $loanData = $result->fetch_assoc();
            $stmt->close();
            
            $totalRemaining = $loanData['Remain_Loan'] + $loanData['Remain_Interest'];
            
            if ($amount <= 0 || $amount > $totalRemaining) {
                throw new Exception("Invalid payment amount");
            }

            // Calculate interest and principal portions
            $interestPayment = min($amount, $loanData['Remain_Interest']);
            $principalPayment = $amount - $interestPayment;

            // Update loan record
            $updateLoanQuery = "UPDATE Loan SET 
                               Paid_Loan = Paid_Loan + ?,
                               Remain_Loan = Remain_Loan - ?,
                               Paid_Interest = Paid_Interest + ?,
                               Remain_Interest = Remain_Interest - ?
                               WHERE LoanID = ?";
            
            $stmt = $conn->prepare($updateLoanQuery);
            $stmt->bind_param("dddds", $principalPayment, $principalPayment, $interestPayment, $interestPayment, $loanId);
            $stmt->execute();
            $stmt->close();

            // Insert into LoanPayment table
            $loanPaymentQuery = "INSERT INTO LoanPayment (LoanID, PaymentID) VALUES (?, ?)";
            $stmt = $conn->prepare($loanPaymentQuery);
            $stmt->bind_param("ss", $loanId, $paymentId);
            $stmt->execute();
            $stmt->close();
            break;

        default:
            throw new Exception("Invalid payment type");
    }

    // Commit transaction
    $conn->commit();
    
    // Store payment ID in session for receipt
    $_SESSION['last_payment_id'] = $paymentId;
    $_SESSION['success_message'] = "Payment processed successfully";
    
    // Redirect to receipt page
    header('Location: receipt.php');
    exit();

} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    $_SESSION['error_message'] = "Error processing payment: " . $e->getMessage();
    header('Location: treasurerPayment.php');
    exit();
}

// Helper Functions
function validateCardDetails($cardNumber, $expireDate, $cvv) {
    if (!preg_match('/^\d{16}$/', str_replace(' ', '', $cardNumber))) {
        throw new Exception("Invalid card number format");
    }

    if (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expireDate)) {
        throw new Exception("Invalid expiry date format");
    }

    list($month, $year) = explode('/', $expireDate);
    $expYear = '20' . $year;
    $currentYear = date('Y');
    $currentMonth = date('m');
    
    if ($expYear < $currentYear || 
        ($expYear == $currentYear && $month < $currentMonth)) {
        throw new Exception("Card has expired");
    }

    if (!preg_match('/^\d{3}$/', $cvv)) {
        throw new Exception("Invalid CVV format");
    }
}

function validateFileUpload($file) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload failed with error code: " . $file['error']);
    }

    if ($file['size'] > $maxSize) {
        throw new Exception("File size must be less than 5MB");
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception("Only JPG, PNG and GIF files are allowed");
    }
}

function generatePaymentId() {
    try {
        $conn = getConnection();
        $conn->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        
        // Get the highest ID with a lock
        $query = "SELECT CAST(SUBSTRING(PaymentID, 4) AS UNSIGNED) as max_num 
                 FROM Payment 
                 WHERE PaymentID LIKE 'PAY%'
                 ORDER BY PaymentID DESC 
                 LIMIT 1 FOR UPDATE";
        
        $result = $conn->query($query);
        
        // Determine the next number
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $nextNum = $row['max_num'] + 1;
        } else {
            $nextNum = 1;
        }
        
        // Generate the new ID
        $newId = "PAY" . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        
        // Verify it doesn't exist (double check)
        $verifyQuery = "SELECT COUNT(*) as count FROM Payment WHERE PaymentID = ?";
        $stmt = $conn->prepare($verifyQuery);
        $stmt->bind_param("s", $newId);
        $stmt->execute();
        $verifyResult = $stmt->get_result();
        
        if ($verifyResult->fetch_assoc()['count'] > 0) {
            $conn->rollback();
            throw new Exception("Generated ID already exists: " . $newId);
        }
        
        $stmt->close();
        
        // Commit the transaction
        $conn->commit();
        
        return $newId;
        
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        throw new Exception("Error generating payment ID: " . $e->getMessage());
    }
}

function generateFeeId() {
    $conn = getConnection();
    $query = "SELECT FeeID FROM MembershipFee ORDER BY FeeID DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastId = $row['FeeID'];
        $numericPart = intval(substr($lastId, 3)) + 1;
        return "FEE" . str_pad($numericPart, 3, '0', STR_PAD_LEFT);
    }
    
    return "FEE001";
}

function checkDuplicatePayment($memberId, $paymentType, $year) {
    $conn = getConnection();
    $query = "SELECT COUNT(*) as count FROM Payment 
              WHERE Member_MemberID = ? 
              AND Payment_Type = ?
              AND Term = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssi", $memberId, $paymentType, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['count'] > 0) {
        throw new Exception("Payment already exists for this period");
    }
}
?>