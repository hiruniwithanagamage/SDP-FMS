<?php
session_start();
require_once "../../../config/database.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: treasurerPayment.php');
    exit();
}

// Function to get the current active year from Static table
function getCurrentActiveYear() {
    $conn = getConnection();
    
    // Query to get the active year from the Static table
    $query = "SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['year'];
    }
    
    // Fallback to the most recent year if no active record
    $query = "SELECT year FROM Static ORDER BY year DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['year'];
    }
    
    // If no records in Static table, return current year as last resort
    return date('Y');
}

// Function to generate a unique payment ID
function generatePaymentId() {
    try {
        $conn = getConnection();
        $conn->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        
        // Get current active year
        $currentYear = getCurrentActiveYear();
        $yearSuffix = substr($currentYear, -2); // Last two digits of year
        $paymentPrefix = "PAY" . $yearSuffix;
        
        // Get highest sequential number for the current year prefix
        $query = "SELECT MAX(CAST(SUBSTRING(PaymentID, 6) AS UNSIGNED)) as max_num 
                 FROM Payment 
                 WHERE PaymentID LIKE '$paymentPrefix%'
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
        $newId = $paymentPrefix . str_pad($nextNum, 2, '0', STR_PAD_LEFT);
        
        // Verify it doesn't exist (double check)
        $verifyQuery = "SELECT COUNT(*) as count FROM Payment WHERE PaymentID = ?";
        $stmt = $conn->prepare($verifyQuery);
        $stmt->bind_param("s", $newId);
        $stmt->execute();
        $verifyResult = $stmt->get_result();
        
        if ($verifyResult->fetch_assoc()['count'] > 0) {
            $conn->rollback();
            throw new Exception("Generated Payment ID already exists: " . $newId);
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

// generateFeeId function
function generateFeeId() {
    try {
        $conn = getConnection();
        $conn->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        
        // Get current active year
        $currentYear = getCurrentActiveYear();
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
    $method = 'cash'; // Fixed payment method for treasurer
    $treasurerId = $_SESSION['treasurer_id'];

    // Start transaction
    $conn = getConnection();
    $conn->begin_transaction();

    // Generate payment ID using our new function
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
        Member_MemberID,
        Status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $status = 'treasurer'; // Set status to 'treasurer' for treasurer-processed payments
    
    $stmt = $conn->prepare($paymentQuery);
    $stmt->bind_param("sssdsiss", $paymentId, $paymentType, $method, $amount, $date, $year, $memberId, $status);
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
            
            // Insert into FinePayment table
            $finePaymentQuery = "INSERT INTO FinePayment (FineID, PaymentID) VALUES (?, ?)";
            $stmt = $conn->prepare($finePaymentQuery);
            $stmt->bind_param("ss", $fineId, $paymentId);
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

    // Log the payment in ChangeLog table
    $logQuery = "INSERT INTO ChangeLog (
        RecordType,
        RecordID,
        MemberID,  
        TreasurerID,
        OldValues,
        NewValues,
        ChangeDetails
    ) VALUES (?, ?, ?, ?, ?, ?, ?)";

    $recordType = 'Payment';
    // Use memberId directly instead of userId
    $memberIdForLog = $memberId; // Use the member_id from the payment
    $oldValues = json_encode(['status' => 'new']);
    $newValues = json_encode([
        'payment_id' => $paymentId,
        'payment_type' => $paymentType,
        'amount' => $amount,
        'member_id' => $memberId,
        'date' => $date,
        'year' => $year
    ]);
    $changeDetails = "Treasurer processed $paymentType payment of Rs. $amount";

    $stmt = $conn->prepare($logQuery);
    $stmt->bind_param("sssssss", $recordType, $paymentId, $memberIdForLog, $treasurerId, $oldValues, $newValues, $changeDetails);
    $stmt->execute();
    $stmt->close();

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
    header('Location: ../treasurerPayment.php');
    exit();
}
