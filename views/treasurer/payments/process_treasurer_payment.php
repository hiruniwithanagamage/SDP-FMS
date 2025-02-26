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
    Database::iud("START TRANSACTION");

    // Generate payment ID
    $paymentId = generatePaymentId();

    // Get fee structure for the year
    $query = "SELECT * FROM Static WHERE year = $year";
    $result = Database::search($query);
    if ($result->num_rows === 0) {
        throw new Exception("Fee structure not found for selected year");
    }
    $feeStructure = $result->fetch_assoc();

    // First, always insert the payment record
    $paymentQuery = "INSERT INTO Payment (
        PaymentID, 
        Payment_Type, 
        Method, 
        Amount, 
        Date, 
        Term, 
        Member_MemberID
    ) VALUES (
        '$paymentId',
        '$paymentType',
        '$method',
        $amount,
        '$date',
        $year,
        '$memberId'
    )";
    Database::iud($paymentQuery);

    // Process different payment types
    switch($paymentType) {
        case 'registration':
            // Validate maximum payment amount
            $regFeePaidQuery = "SELECT COALESCE(SUM(P.Amount), 0) as total_paid
                                FROM MembershipFee MF
                                JOIN MembershipFeePayment MFP ON MF.FeeID = MFP.FeeID
                                JOIN Payment P ON MFP.PaymentID = P.PaymentID
                                WHERE MF.Member_MemberID = '$memberId'
                                AND MF.Type = 'registration'
                                AND MF.Term = $year";
            $regFeePaidResult = Database::search($regFeePaidQuery);
            $regFeePaid = $regFeePaidResult->fetch_assoc()['total_paid'];
            $remainingRegFee = $feeStructure['registration_fee'] - $regFeePaid;

            if ($amount > $remainingRegFee) {
                throw new Exception("Payment amount cannot exceed remaining registration fee");
            }

            // Create or find existing membership fee record
            $existingFeeQuery = "SELECT FeeID FROM MembershipFee 
                                WHERE Member_MemberID = '$memberId' 
                                AND Term = $year 
                                AND Type = 'registration'";
            $existingFeeResult = Database::search($existingFeeQuery);
            
            if ($existingFeeResult->num_rows === 0) {
                $feeId = generateFeeId();
                $feeQuery = "INSERT INTO MembershipFee (
                    FeeID, 
                    Amount, 
                    Date, 
                    Term, 
                    Type, 
                    Member_MemberID, 
                    IsPaid
                ) VALUES (
                    '$feeId', 
                    " . $feeStructure['registration_fee'] . ", 
                    '$date', 
                    $year, 
                    'registration', 
                    '$memberId', 
                    'No'
                )";
                Database::iud($feeQuery);
            } else {
                $feeId = $existingFeeResult->fetch_assoc()['FeeID'];
            }

            // Link payment to membership fee
            $linkQuery = "INSERT INTO MembershipFeePayment (FeeID, PaymentID) 
                        VALUES ('$feeId', '$paymentId')";
            Database::iud($linkQuery);

            // Check if registration fee is fully paid
            $newTotalPaid = $regFeePaid + $amount;
            if ($newTotalPaid >= $feeStructure['registration_fee']) {
                // Update membership fee status
                $updateFeeQuery = "UPDATE MembershipFee 
                                SET IsPaid = 'Yes' 
                                WHERE FeeID = '$feeId'";
                Database::iud($updateFeeQuery);

                // Update member status
                $updateMemberQuery = "UPDATE Member 
                                    SET Status = 'Full Member' 
                                    WHERE MemberID = '$memberId'";
                Database::iud($updateMemberQuery);
            }
            break;

        case 'monthly':
            if (!isset($_POST['selected_months']) || empty($_POST['selected_months'])) {
                throw new Exception("No months selected for monthly fee");
            }

            $selectedMonths = $_POST['selected_months'];
            $expectedAmount = count($selectedMonths) * $feeStructure['monthly_fee'];
            
            if ($amount != $expectedAmount) {
                throw new Exception("Invalid monthly fee amount");
            }

            // Process each selected month
            foreach ($selectedMonths as $month) {
                $feeId = generateFeeId();
                $monthDate = date('Y-m-d', strtotime("$year-$month-01"));
                
                // Insert monthly fee
                $monthlyFeeQuery = "INSERT INTO MembershipFee (
                    FeeID, 
                    Amount, 
                    Date, 
                    Term, 
                    Type, 
                    Member_MemberID, 
                    IsPaid
                ) VALUES (
                    '$feeId', 
                    " . $feeStructure['monthly_fee'] . ", 
                    '$monthDate', 
                    $year, 
                    'monthly', 
                    '$memberId', 
                    'Yes'
                )";
                Database::iud($monthlyFeeQuery);

                // Link payment to monthly fee
                $linkQuery = "INSERT INTO MembershipFeePayment (FeeID, PaymentID) 
                            VALUES ('$feeId', '$paymentId')";
                Database::iud($linkQuery);
            }
            break;

        case 'fine':
            if (!isset($_POST['fine_id'])) {
                throw new Exception("Fine ID is required");
            }

            $fineId = $_POST['fine_id'];
            
            // Validate fine payment
            $fineQuery = "SELECT Amount FROM Fine 
                         WHERE FineID = '$fineId' 
                         AND Member_MemberID = '$memberId' 
                         AND IsPaid = 'No'";
            $result = Database::search($fineQuery);
            
            if ($result->num_rows === 0) {
                throw new Exception("Invalid fine payment");
            }

            $fineData = $result->fetch_assoc();
            if ($amount != $fineData['Amount']) {
                throw new Exception("Invalid fine amount");
            }

            // Update fine record
            $updateFineQuery = "UPDATE Fine SET 
                               IsPaid = 'Yes',
                               Payment_PaymentID = '$paymentId' 
                               WHERE FineID = '$fineId'";
            Database::iud($updateFineQuery);
            break;

        case 'loan':
            if (!isset($_POST['loan_id'])) {
                throw new Exception("Loan ID is required");
            }

            $loanId = $_POST['loan_id'];

            // Validate loan payment
            $loanQuery = "SELECT Amount, Remain_Loan, Remain_Interest FROM Loan 
                         WHERE LoanID = '$loanId' 
                         AND Member_MemberID = '$memberId' 
                         AND Status = 'approved'";
            $result = Database::search($loanQuery);
            
            if ($result->num_rows === 0) {
                throw new Exception("Invalid loan payment");
            }

            $loanData = $result->fetch_assoc();
            $totalRemaining = $loanData['Remain_Loan'] + $loanData['Remain_Interest'];
            
            if ($amount <= 0 || $amount > $totalRemaining) {
                throw new Exception("Invalid payment amount");
            }

            // Calculate interest and principal portions
            $interestPayment = min($amount, $loanData['Remain_Interest']);
            $principalPayment = $amount - $interestPayment;

            // Update loan record
            $updateLoanQuery = "UPDATE Loan SET 
                               Paid_Loan = Paid_Loan + $principalPayment,
                               Remain_Loan = Remain_Loan - $principalPayment,
                               Paid_Interest = Paid_Interest + $interestPayment,
                               Remain_Interest = Remain_Interest - $interestPayment
                               WHERE LoanID = '$loanId'";
            Database::iud($updateLoanQuery);

            // Insert into LoanPayment table
            $loanPaymentQuery = "INSERT INTO LoanPayment (LoanID, PaymentID) 
                                VALUES ('$loanId', '$paymentId')";
            Database::iud($loanPaymentQuery);
            break;

        default:
            throw new Exception("Invalid payment type");
    }

    // Commit transaction
    Database::iud("COMMIT");
    
    // Store payment ID in session for receipt
    $_SESSION['last_payment_id'] = $paymentId;
    $_SESSION['success_message'] = "Payment processed successfully";
    
    // Redirect to receipt page
    header('Location: receipt.php');
    exit();

} catch (Exception $e) {
    // Rollback transaction on error
    Database::iud("ROLLBACK");
    $_SESSION['error_message'] = "Error processing payment: " . $e->getMessage();
    header('Location: treasurerPayment.php');
    exit();
}

// The rest of the helper functions remain the same as in your original script


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
        // Set the isolation level before starting the transaction
        Database::iud("SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        
        // Now start the transaction
        Database::iud("START TRANSACTION");
        
        // Get the highest ID with a lock
        $query = "SELECT CAST(SUBSTRING(PaymentID, 4) AS UNSIGNED) as max_num 
                 FROM Payment 
                 WHERE PaymentID LIKE 'PAY%'
                 ORDER BY PaymentID DESC 
                 LIMIT 1 FOR UPDATE";
                 
        $result = Database::search($query);
        
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
        $verifyQuery = "SELECT COUNT(*) as count FROM Payment WHERE PaymentID = '$newId'";
        $verifyResult = Database::search($verifyQuery);
        
        if ($verifyResult->fetch_assoc()['count'] > 0) {
            Database::iud("ROLLBACK");
            throw new Exception("Generated ID already exists: " . $newId);
        }
        
        // Commit the transaction
        Database::iud("COMMIT");
        
        return $newId;
        
    } catch (Exception $e) {
        Database::iud("ROLLBACK");
        throw new Exception("Error generating payment ID: " . $e->getMessage());
    }
}

function generateFeeId() {
    $query = "SELECT FeeID FROM MembershipFee ORDER BY FeeID DESC LIMIT 1";
    $result = Database::search($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastId = $row['FeeID'];
        $numericPart = intval(substr($lastId, 3)) + 1;
        return "FEE" . str_pad($numericPart, 3, '0', STR_PAD_LEFT);
    }
    
    return "FEE001";
}

function checkDuplicatePayment($memberId, $paymentType, $year) {
    $query = "SELECT COUNT(*) as count FROM Payment 
              WHERE Member_MemberID = '$memberId' 
              AND Payment_Type = '$paymentType' 
              AND Term = $year";
    
    $result = Database::search($query);
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        throw new Exception("Payment already exists for this period");
    }
}
?>