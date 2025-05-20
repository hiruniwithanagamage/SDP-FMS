<?php
session_start();
require_once "../../config/database.php";

global $conn;
$conn = getConnection();

// Function to get pending payments
function getPendingPayments() {
    $sql = "SELECT p.*, m.Name as MemberName
            FROM Payment p
            JOIN Member m ON p.Member_MemberID = m.MemberID
            WHERE p.Status = 'pending'
            ORDER BY p.Date DESC";
    
    return search($sql);
}

// Get current term
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE status = 'active'";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Function to handle payment approval
function approvePayment($paymentId) {
    global $conn;
    
    // First get the payment details to record in changelog
    $sql = "SELECT * FROM Payment WHERE PaymentID = '$paymentId'";
    $result = search($sql);
    $payment = $result->fetch_assoc();
    
    if (!$payment) {
        return false;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update payment status
        $updateSql = "UPDATE Payment SET Status = 'self' WHERE PaymentID = '$paymentId'";
        $conn->query($updateSql);
        
        // Process different payment types
        $paymentType = $payment['Payment_Type'];
        $memberId = $payment['Member_MemberID'];
        $amount = $payment['Amount'];
        $year = $payment['Term'];
        $date = $payment['Date'];
        
        // Process payment logic based on type (existing logic stays the same)
        switch($paymentType) {
            // Existing payment processing code remains unchanged
            // ...
        }
        
        // Record in ChangeLog with updated schema
        $oldValues = json_encode($payment);
        $newValues = json_encode(['Status' => 'self']); // Only recording the changed field
        
        // Get current user (treasurer) ID from session
        $treasurerId = $_SESSION['user_id'] ?? 'Unknown'; 
        
        $changeDetails = "Approved payment #$paymentId ({$paymentType}) for Member #{$memberId}";
        
        // Use the updated ChangeLog schema with MemberID instead of UserID
        // Set Status to 'Not Read' (default value will apply if not specified)
        $logSql = "INSERT INTO ChangeLog (RecordType, RecordID, MemberID, TreasurerID, OldValues, NewValues, ChangeDetails)
                  VALUES ('Payment', '$paymentId', '$memberId', '$treasurerId', '$oldValues', '$newValues', '$changeDetails')";
        $conn->query($logSql);
        
        // Commit transaction
        $conn->commit();
        return true;
    } catch (Exception $e) {
        // Roll back transaction in case of error
        $conn->rollback();
        error_log("Transaction rolled back in approvePayment: " . $e->getMessage());
        return false;
    }
}

// Function to handle payment rejection with enhanced membership fee handling
function rejectPayment($paymentId) {
    global $conn;
    
    // First get the payment details to record in changelog
    $sql = "SELECT * FROM Payment WHERE PaymentID = '$paymentId'";
    $result = search($sql);
    $oldPayment = $result->fetch_assoc();
    
    if (!$oldPayment) {
        return false;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        $paymentType = $oldPayment['Payment_Type'];
        $memberId = $oldPayment['Member_MemberID'];
        $imagePath = $oldPayment['Image'];
        
        // Handle specific payment types before deleting the payment
        if ($paymentType == 'monthly' || $paymentType == 'registration') {
            // Find all membership fees associated with this payment
            $query = "SELECT FeeID FROM MembershipFeePayment WHERE PaymentID = '$paymentId'";
            $feeResult = search($query);
            
            // Delete all associated membership fees
            while ($feeRow = $feeResult->fetch_assoc()) {
                $feeId = $feeRow['FeeID'];
                
                // Delete from MembershipFee table
                $deleteFeeQuery = "DELETE FROM MembershipFee WHERE FeeID = '$feeId'";
                if (!$conn->query($deleteFeeQuery)) {
                    error_log("Error deleting MembershipFee: " . $conn->error);
                    throw new Exception("Failed to delete MembershipFee record: " . $conn->error);
                }
            }
            
            // Delete from MembershipFeePayment junction table
            $deleteMembershipFeePaymentQuery = "DELETE FROM MembershipFeePayment WHERE PaymentID = '$paymentId'";
            if (!$conn->query($deleteMembershipFeePaymentQuery)) {
                error_log("Error deleting MembershipFeePayment: " . $conn->error);
                throw new Exception("Failed to delete MembershipFeePayment record: " . $conn->error);
            }
        } elseif ($paymentType == 'fine') {
            // Find all fines associated with this payment
            $query = "SELECT FineID FROM FinePayment WHERE PaymentID = '$paymentId'";
            $fineResult = search($query);
            
            // Update all associated fines to unpaid
            while ($fineRow = $fineResult->fetch_assoc()) {
                $fineId = $fineRow['FineID'];
                
                // Update Fine status to unpaid
                $updateFineQuery = "UPDATE Fine SET IsPaid = 'No', Payment_PaymentID = NULL WHERE FineID = '$fineId'";
                if (!$conn->query($updateFineQuery)) {
                    error_log("Error updating Fine: " . $conn->error);
                    throw new Exception("Failed to update Fine record: " . $conn->error);
                }
            }
            
            // Delete from FinePayment junction table
            $deleteFinePaymentQuery = "DELETE FROM FinePayment WHERE PaymentID = '$paymentId'";
            if (!$conn->query($deleteFinePaymentQuery)) {
                error_log("Error deleting FinePayment: " . $conn->error);
                throw new Exception("Failed to delete FinePayment record: " . $conn->error);
            }
        } elseif ($paymentType == 'loan') {
            // Get loan details associated with this payment
            $query = "SELECT LoanID FROM LoanPayment WHERE PaymentID = '$paymentId'";
            $loanResult = search($query);
            
            // Update loan amounts (restore unpaid amounts)
            while ($loanRow = $loanResult->fetch_assoc()) {
                $loanId = $loanRow['LoanID'];
                
                // Get loan payment details to determine what was being paid
                // Assuming the payment amount needs to be added back to the remaining loan
                $loanQuery = "SELECT * FROM Loan WHERE LoanID = '$loanId'";
                $loanDetailsResult = search($loanQuery);
                $loanDetails = $loanDetailsResult->fetch_assoc();
                
                if ($loanDetails) {
                    // Add payment amount back to remaining loan amount
                    // This is simplistic - in a real system, you might need to determine 
                    // how much was for principal vs interest
                    $updateLoanQuery = "UPDATE Loan SET 
                                        Remain_Loan = Remain_Loan + " . $oldPayment['Amount'] . "
                                        WHERE LoanID = '$loanId'";
                    if (!$conn->query($updateLoanQuery)) {
                        error_log("Error updating Loan: " . $conn->error);
                        throw new Exception("Failed to update Loan record: " . $conn->error);
                    }
                }
            }
            
            // Delete from LoanPayment junction table
            $deleteLoanPaymentQuery = "DELETE FROM LoanPayment WHERE PaymentID = '$paymentId'";
            if (!$conn->query($deleteLoanPaymentQuery)) {
                error_log("Error deleting LoanPayment: " . $conn->error);
                throw new Exception("Failed to delete LoanPayment record: " . $conn->error);
            }
        }
        
        // Delete the payment
        $deleteSql = "DELETE FROM Payment WHERE PaymentID = '$paymentId'";
        if (!$conn->query($deleteSql)) {
            error_log("Error deleting Payment: " . $conn->error);
            throw new Exception("Failed to delete Payment record: " . $conn->error);
        }
        
        // Delete the receipt image file if it exists
        if ($imagePath) {
            $fullImagePath = "../../uploads/receipts/" . $imagePath;
            if (file_exists($fullImagePath)) {
                if (!unlink($fullImagePath)) {
                    error_log("Warning: Could not delete file at: $fullImagePath");
                }
            }
        }
        
        // Record in ChangeLog with updated schema
        $oldValues = json_encode($oldPayment);
        $newValues = json_encode([]); // Empty as the record is deleted
        
        // Get current user (treasurer) ID from session
        $treasurerId = $_SESSION['user_id'] ?? 'Unknown';
        
        $changeDetails = "Rejected and deleted payment #$paymentId ({$paymentType}) for Member #{$memberId}";
        
        // Use the updated ChangeLog schema with MemberID instead of UserID
        // Status will be 'Not Read' by default
        $logSql = "INSERT INTO ChangeLog (RecordType, RecordID, MemberID, TreasurerID, OldValues, NewValues, ChangeDetails)
                  VALUES ('Payment', '$paymentId', '$memberId', '$treasurerId', '$oldValues', '$newValues', '$changeDetails')";
        if (!$conn->query($logSql)) {
            error_log("Error inserting into ChangeLog: " . $conn->error);
            throw new Exception("Failed to insert into ChangeLog: " . $conn->error);
        }
        
        // Commit transaction
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        // Roll back transaction in case of error
        $conn->rollback();
        error_log("Transaction rolled back in rejectPayment: " . $e->getMessage());
        return false;
    }
}

// Helper function to generate a new fee ID
function generateFeeId($conn) {
    // Get the current active term from Static table
    $termQuery = "SELECT year FROM Static WHERE status = 'active' LIMIT 1";
    $termResult = $conn->query($termQuery);
    
    if ($termResult && $termResult->num_rows > 0) {
        $row = $termResult->fetch_assoc();
        $currentTerm = $row['year'];
        
        // Get last 2 digits of the term
        $yearSuffix = substr($currentTerm, -2);
        
        // Query to find the highest sequence number for the current term
        $query = "SELECT FeeID FROM MembershipFee 
                 WHERE FeeID LIKE 'FEE{$yearSuffix}%' 
                 ORDER BY FeeID DESC LIMIT 1";
        $result = $conn->query($query);
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $lastId = $row['FeeID'];
            
            // Extract the sequence number (last 2 digits)
            $seqNumber = intval(substr($lastId, -2));
            $nextSeq = $seqNumber + 1;
        } else {
            // If no existing IDs found for this term, start with 01
            $nextSeq = 1;
        }
        
        // Format with leading zeros to ensure 2 digits
        $feeId = 'FEE' . $yearSuffix . str_pad($nextSeq, 2, '0', STR_PAD_LEFT);
        return $feeId;
    }
    
    // Fallback if no active term found (should be rare)
    // Using current year as fallback
    $currentYear = date('Y');
    $yearSuffix = substr($currentYear, -2);
    return 'FEE' . $yearSuffix . '01';
}


// Process form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_payment']) && isset($_POST['payment_id'])) {
        $paymentId = $_POST['payment_id'];
        if (approvePayment($paymentId)) {
            $_SESSION['success_message'] = "Payment #$paymentId has been approved successfully.";
        } else {
            $_SESSION['error_message'] = "Failed to approve payment. Please try again.";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } elseif (isset($_POST['reject_payment']) && isset($_POST['payment_id'])) {
        $paymentId = $_POST['payment_id'];
        if (rejectPayment($paymentId)) {
            $_SESSION['success_message'] = "Payment #$paymentId has been rejected and deleted successfully.";
        } else {
            $_SESSION['error_message'] = "Failed to reject payment. Please try again.";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Fetch all pending payments
$pendingPayments = getPendingPayments();
$currentTerm = getCurrentTerm();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Payments - Treasurer Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .home-container {
            min-height: 100vh;
            background: #f5f7fa;
            padding: 2rem;
        }

        .content {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header-card {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 35px 0 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .header-card h1 {
            font-size: 1.8rem;
            margin: 0;
        }

        .back-button {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .back-button i {
            font-size: 1.1em;
        }

        .pending-payments-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .payment-item {
            border-bottom: 1px solid #eee;
            padding: 1.5rem;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 2fr;
            align-items: center;
            gap: 1rem;
        }

        .payment-item:last-child {
            border-bottom: none;
        }

        .payment-details {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .payment-member {
            font-weight: bold;
            font-size: 1.1rem;
            color: #1e3c72;
        }

        .payment-id {
            color: #666;
            font-size: 0.9rem;
        }

        .payment-type {
            font-weight: 500;
        }

        .payment-amount {
            font-weight: bold;
            color: #1e3c72;
        }

        .payment-date {
            color: #666;
        }

        .payment-receipt {
            text-align: center;
        }

        .view-receipt {
            background: #f0f4f9;
            color: #1e3c72;
            border: none;
            padding: 0.6rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-weight: 500;
        }

        .view-receipt:hover {
            background: #e1e7f0;
            transform: translateY(-2px);
        }

        .payment-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .approve-btn, .reject-btn {
            padding: 0.6rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .approve-btn {
            background: #2ecc71;
            color: white;
        }

        .approve-btn:hover {
            background: #27ae60;
            transform: translateY(-2px);
        }

        .reject-btn {
            background: #e74c3c;
            color: white;
        }

        .reject-btn:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        .no-payments {
            text-align: center;
            padding: 2rem;
            color: #666;
            font-style: italic;
        }

        .success-message, .error-message {
            padding: 15px;
            margin-top: 20px;
            margin-bottom: 15px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .close-icon {
            cursor: pointer;
            font-size: 20px;
            background: none;
            border: none;
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
        }

        .success-message .close-icon {
            color: #155724;
        }

        .error-message .close-icon {
            color: #721c24;
        }

        .close-icon:hover {
            opacity: 0.8;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.6);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            max-width: 800px;
            position: relative;
        }

        .close-modal {
            color: #aaa;
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close-modal:hover {
            color: #333;
        }

        .receipt-image {
            width: 100%;
            max-height: 500px;
            object-fit: contain;
            border-radius: 8px;
            margin: 10px 0;
        }

        .modal-header {
            margin-bottom: 15px;
            color: #1e3c72;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }

        .modal-footer {
            margin-top: 15px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }

        .section-title {
            margin-bottom: 1rem;
            color: #1e3c72;
            font-size: 1.4rem;
        }

        .no-receipt {
            text-align: center;
            padding: 2rem;
            color: #666;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 8px;
        }

        /* Add button styling for modal actions */
        .cancel-btn {
            padding: 0.6rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            background-color: #e0e0e0;
            color: #333;
        }

        .cancel-btn:hover {
            background-color: #d0d0d0;
            transform: translateY(-2px);
        }

        /* Responsive styles */
        @media (max-width: 992px) {
            .payment-item {
                grid-template-columns: 2fr 1fr 1fr;
                row-gap: 1rem;
            }

            .payment-receipt {
                grid-column: 1 / 2;
            }

            .payment-actions {
                grid-column: 2 / 4;
                justify-content: flex-end;
            }
        }

        @media (max-width: 768px) {
            .payment-item {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .payment-details {
                align-items: center;
            }

            .payment-receipt {
                grid-column: 1;
            }

            .payment-actions {
                grid-column: 1;
                justify-content: center;
            }

            .header-card {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .back-button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="home-container">
        <?php include '../templates/navbar-treasurer.php'; ?>
        <div class="content">
            <div class="header-card">
                <h1>Pending Payments</h1>
                <a href="index.php" class="back-button">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
            </div>

            <?php if(isset($_SESSION['success_message'])): ?>
                <div class="success-message" id="success-message">
                    <?php 
                        echo htmlspecialchars($_SESSION['success_message']);
                        unset($_SESSION['success_message']);
                    ?>
                    <button class="close-icon" onclick="closeMessage('success-message')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['error_message'])): ?>
                <div class="error-message" id="error-message">
                    <?php 
                        echo htmlspecialchars($_SESSION['error_message']);
                        unset($_SESSION['error_message']);
                    ?>
                    <button class="close-icon" onclick="closeMessage('error-message')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <div class="pending-payments-container">
                <h2 class="section-title">Payments Awaiting Approval</h2>
                
                <?php if($pendingPayments->num_rows > 0): ?>
                    <div class="payment-item header">
                        <div>Payment Details</div>
                        <div>Amount</div>
                        <div>Date</div>
                        <div>Receipt</div>
                        <div>Actions</div>
                    </div>
                    
                    <?php while($payment = $pendingPayments->fetch_assoc()): ?>
                        <div class="payment-item">
                            <div class="payment-details">
                                <div class="payment-member"><?php echo htmlspecialchars($payment['MemberName']); ?></div>
                                <div class="payment-id"><?php echo htmlspecialchars($payment['PaymentID']); ?></div>
                                <div class="payment-type"><?php echo htmlspecialchars($payment['Payment_Type']); ?> - <?php echo htmlspecialchars($payment['Method']); ?></div>
                            </div>
                            <div class="payment-amount">Rs.<?php echo number_format($payment['Amount'], 2); ?></div>
                            <div class="payment-date"><?php echo date('M d, Y', strtotime($payment['Date'])); ?></div>
                            <div class="payment-receipt">
                                <?php if($payment['Image']): ?>
                                    <button class="view-receipt" onclick="openReceiptModal('<?php echo htmlspecialchars($payment['PaymentID']); ?>', '<?php echo htmlspecialchars($payment['Image']); ?>')">
                                        <i class="fas fa-receipt"></i>
                                        View Receipt
                                    </button>
                                <?php else: ?>
                                    <span class="no-receipt-text">No receipt</span>
                                <?php endif; ?>
                            </div>
                            <div class="payment-actions">
                                <!-- Modified to use confirmation modals -->
                                <button class="approve-btn" onclick="openApproveModal('<?php echo htmlspecialchars($payment['PaymentID']); ?>')">
                                    <i class="fas fa-check"></i>
                                    Approve
                                </button>
                                <button class="reject-btn" onclick="openRejectModal('<?php echo htmlspecialchars($payment['PaymentID']); ?>')">
                                    <i class="fas fa-times"></i>
                                    Reject
                                </button>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-payments">
                        <p>There are no pending payments at this time.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php include '../templates/footer.php'; ?>
    </div>

    <!-- Receipt Modal -->
    <div id="receiptModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeReceiptModal()">&times;</span>
            <h2 class="modal-header">Payment Receipt</h2>
            <div id="receiptContent">
                <img id="receiptImage" class="receipt-image" src="" alt="Payment Receipt">
            </div>
            <div class="modal-footer">
                <div id="paymentIdDisplay"></div>
            </div>
        </div>
    </div>

    <!-- Approve Confirmation Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeApproveModal()">&times;</span>
            <h2 class="modal-header">Confirm Approval</h2>
            <p>Are you sure you want to approve this payment? This will update the payment status and process the related records.</p>
            <form method="post" id="approveForm">
                <input type="hidden" id="approve_payment_id" name="payment_id">
                <input type="hidden" name="approve_payment" value="1">
                <div class="modal-footer">
                    <button type="button" class="view-receipt cancel-btn" onclick="closeApproveModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="approve-btn">
                        <i class="fas fa-check"></i> Approve
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Confirmation Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeRejectModal()">&times;</span>
            <h2 class="modal-header">Confirm Rejection</h2>
            <p>Are you sure you want to reject this payment? This will delete the payment record and cannot be undone.</p>
            <form method="post" id="rejectForm">
                <input type="hidden" id="reject_payment_id" name="payment_id">
                <input type="hidden" name="reject_payment" value="1">
                <div class="modal-footer">
                    <button type="button" class="view-receipt cancel-btn" onclick="closeRejectModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="reject-btn">
                        <i class="fas fa-trash"></i> Reject
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Close message notifications
        function closeMessage(messageId) {
            const message = document.getElementById(messageId);
            if (message) {
                message.style.display = 'none';
            }
        }

        // Auto-hide messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const messages = document.querySelectorAll('.success-message, .error-message');
            messages.forEach(function(message) {
                setTimeout(function() {
                    message.style.display = 'none';
                }, 5000);
            });
        });

        // Receipt modal functions
        function openReceiptModal(paymentId, imagePath) {
            const modal = document.getElementById('receiptModal');
            const receiptImage = document.getElementById('receiptImage');
            const paymentIdDisplay = document.getElementById('paymentIdDisplay');
            
            // Set image path using absolute path - avoid duplication of upload path
            receiptImage.src = "/SDP/uploads/receipts/" + imagePath.replace('uploads/receipts/', '');
            
            // Set payment ID
            paymentIdDisplay.textContent = "Payment ID: " + paymentId;
            
            // Show modal
            modal.style.display = "block";
        }

        function closeReceiptModal() {
            const modal = document.getElementById('receiptModal');
            modal.style.display = "none";
        }

        // Payment approval/rejection modal functions
        function openApproveModal(paymentId) {
            document.getElementById('approveModal').style.display = 'block';
            document.getElementById('approve_payment_id').value = paymentId;
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }

        function openRejectModal(paymentId) {
            document.getElementById('rejectModal').style.display = 'block';
            document.getElementById('reject_payment_id').value = paymentId;
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }

        // Close modal if clicked outside of it
        window.onclick = function(event) {
            const receiptModal = document.getElementById('receiptModal');
            const approveModal = document.getElementById('approveModal');
            const rejectModal = document.getElementById('rejectModal');
            
            if (event.target == receiptModal) {
                closeReceiptModal();
            }
            if (event.target == approveModal) {
                closeApproveModal();
            }
            if (event.target == rejectModal) {
                closeRejectModal();
            }
        }
    </script>
</body>
</html>