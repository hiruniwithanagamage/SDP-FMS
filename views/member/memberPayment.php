<?php
session_start();
require_once "../../config/database.php";

// Verify member authentication
if (!isset($_SESSION['member_id'])) {
    header('Location: ../login.php');
    exit();
}

// Get current date and year
$currentDate = date('Y-m-d');
$currentYear = date('Y');

// Fetch static data for current year
$query = "SELECT * FROM Static WHERE year = $currentYear";
$result = search($query);
if ($result->num_rows === 0) {
    $query = "SELECT * FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($query);
}
$staticData = $result->fetch_assoc();

// Get member details
$memberId = $_SESSION['member_id'];
$query = "SELECT Name FROM Member WHERE MemberID = '$memberId'";
$result = search($query);
$memberData = $result->fetch_assoc();

// Get unpaid fees
$unpaidFeesQuery = "SELECT * FROM MembershipFee 
                    WHERE Member_MemberID = '$memberId' 
                    AND IsPaid = 'No' 
                    ORDER BY Date ASC";
$unpaidFeesResult = search($unpaidFeesQuery);

// Get unpaid fines
$unpaidFinesQuery = "SELECT * FROM Fine 
                     WHERE Member_MemberID = '$memberId' 
                     AND IsPaid = 'No' 
                     ORDER BY Date ASC";
$unpaidFinesResult = search($unpaidFinesQuery);

// Get active loans
$activeLoansQuery = "SELECT * FROM Loan 
                     WHERE Member_MemberID = '$memberId' 
                     AND Status = 'approved' 
                     AND Remain_Loan > 0
                     ORDER BY Issued_Date DESC";
$activeLoansResult = search($activeLoansQuery);

// Get total paid registration fee
$regFeePaidQuery = "SELECT COALESCE(SUM(P.Amount), 0) as total_paid
                    FROM MembershipFee MF
                    JOIN MembershipFeePayment MFP ON MF.FeeID = MFP.FeeID
                    JOIN Payment P ON MFP.PaymentID = P.PaymentID
                    WHERE MF.Member_MemberID = '$memberId'
                    AND MF.Type = 'registration'
                    AND MF.Term = $currentYear";
$regFeePaidResult = search($regFeePaidQuery);
$regFeePaid = $regFeePaidResult->fetch_assoc()['total_paid'];
$remainingRegFee = $staticData['registration_fee'] - $regFeePaid;

// Get member status
$memberStatusQuery = "SELECT Status FROM Member WHERE MemberID = '$memberId'";
$memberStatusResult = search($memberStatusQuery);
$memberStatus = $memberStatusResult->fetch_assoc()['Status'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Payment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/memberPayment.css">

</head>
<body>
    <div class="main-container">
    <?php include '../templates/navbar-member.php'; ?> 
        <div class="container" style="margin-top: 30px;">
            <h1>Payments</h1>
            
            <?php if(isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- Pending Payments Section -->
            <?php if($unpaidFeesResult->num_rows > 0 || $unpaidFinesResult->num_rows > 0): ?>
                <div class="pending-payments">
                    <h2>Pending Payments</h2>
                    <?php if($unpaidFeesResult->num_rows > 0): ?>
                        <div class="pending-fees">
                            <h3>Membership Fees</h3>
                            <ul>
                                <?php while($fee = $unpaidFeesResult->fetch_assoc()): ?>
                                    <li>
                                        <?php echo $fee['Type']; ?> Fee: 
                                        Rs. <?php echo number_format($fee['Amount'], 2); ?>
                                        (Due: <?php echo date('Y-m-d', strtotime($fee['Date'])); ?>)
                                    </li>
                                <?php endwhile; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if($unpaidFinesResult->num_rows > 0): ?>
                        <div class="pending-fines">
                            <h3>Fines</h3>
                            <ul>
                                <?php while($fine = $unpaidFinesResult->fetch_assoc()): ?>
                                    <li>
                                        <?php echo $fine['Description']; ?> Fine: 
                                        Rs. <?php echo number_format($fine['Amount'], 2); ?>
                                        (Date: <?php echo $fine['Date']; ?>)
                                    </li>
                                <?php endwhile; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <form id="paymentForm" action="payments/process_payment.php" method="POST" enctype="multipart/form-data">
                <!-- Member Information -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" value="<?php echo htmlspecialchars($memberData['Name']); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Member ID</label>
                        <input type="text" value="<?php echo htmlspecialchars($memberId); ?>" disabled>
                        <input type="hidden" name="member_id" value="<?php echo htmlspecialchars($memberId); ?>">
                    </div>
                </div>

                <!-- Date and Year -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="date" value="<?php echo $currentDate; ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Year</label>
                        <select name="year" id="yearSelect">
                            <?php
                            $yearQuery = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
                            $yearResult = search($yearQuery);
                            while($yearRow = $yearResult->fetch_assoc()): ?>
                                <option value="<?php echo $yearRow['year']; ?>" 
                                    <?php echo ($yearRow['year'] == $currentYear) ? 'selected' : ''; ?>>
                                    <?php echo $yearRow['year']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <!-- Payment Type Selection -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Type</label>
                        <select name="payment_type" id="paymentType">
                            <option value="">Select payment type</option>
                            <?php if($remainingRegFee > 0): ?>
                                <option value="registration">
                                    Registration Fee (Remaining: Rs. <?php echo number_format($remainingRegFee, 2); ?>)
                                </option>
                            <?php endif; ?>
                            <option value="monthly">Monthly Fee</option>
                            <?php if($activeLoansResult->num_rows > 0): ?>
                                <option value="loan">Loan Payment</option>
                            <?php endif; ?>
                            <?php if($unpaidFinesResult->num_rows > 0): ?>
                                <option value="fine">Fine Payment</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Registration Fee Section -->
                    <div class="form-group" id="registrationFeeContainer" style="display: none;">
                        <div class="fee-info">
                            <p>Total Registration Fee: Rs. <?php echo number_format($staticData['registration_fee'], 2); ?></p>
                            <p>Amount Paid: Rs. <?php echo number_format($regFeePaid, 2); ?></p>
                            <p>Remaining Amount: Rs. <?php echo number_format($remainingRegFee, 2); ?></p>
                            <?php if($memberStatus !== 'Full Member'): ?>
                                <p class="status-info">Your membership will be upgraded to Full Member once the registration fee is completely paid.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Fine Selection -->
                    <div class="form-group" id="fineTypeContainer" style="display: none;">
                        <label>Select Fine</label>
                        <select name="fine_id" id="fineSelect">
                            <option value="">Select a fine to pay</option>
                            <?php
                            $unpaidFinesResult->data_seek(0); // Reset pointer
                            while($fine = $unpaidFinesResult->fetch_assoc()): ?>
                                <option value="<?php echo $fine['FineID']; ?>" 
                                        data-amount="<?php echo $fine['Amount']; ?>">
                                    <?php echo $fine['Description']; ?> Fine - 
                                    Rs. <?php echo number_format($fine['Amount'], 2); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <!-- Loan Selection -->
                <?php if($activeLoansResult->num_rows > 0): ?>
                    <div class="form-group" id="loanDetailsContainer" style="display: none;">
                        <label>Select Loan</label>
                        <select name="loan_id" id="loanSelect">
                            <option value="">Select a loan</option>
                            <?php while($loan = $activeLoansResult->fetch_assoc()): ?>
                                <option value="<?php echo $loan['LoanID']; ?>" 
                                        data-principal="<?php echo $loan['Remain_Loan']; ?>"
                                        data-interest="<?php echo $loan['Remain_Interest']; ?>">
                                    Loan ID: <?php echo $loan['LoanID']; ?> 
                                    (Principal: Rs. <?php echo number_format($loan['Remain_Loan'], 2); ?>,
                                    Interest: Rs. <?php echo number_format($loan['Remain_Interest'], 2); ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Month Selection for Monthly Fee -->
                <div class="form-group" id="monthSelectionContainer" style="display: none;">
                    <label>Select Months</label>
                    <div class="months-grid">
                        <?php
                        $months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                 'July', 'August', 'September', 'October', 'November', 'December'];
                        foreach ($months as $index => $month) {
                            $monthNum = $index + 1;
                            $query = "SELECT COUNT(*) as paid FROM MembershipFee 
                                    WHERE Member_MemberID = '$memberId' 
                                    AND Term = $currentYear 
                                    AND MONTH(Date) = $monthNum 
                                    AND Type = 'monthly' 
                                    AND IsPaid = 'Yes'";
                            $paidResult = search($query);
                            $isPaid = $paidResult->fetch_assoc()['paid'] > 0;
                            
                            echo "<label class='month-checkbox " . ($isPaid ? 'paid' : '') . "'>
                                    <input type='checkbox' name='selected_months[]' value='" . $monthNum . "' 
                                        " . ($isPaid ? 'disabled checked' : '') . ">
                                    $month " . ($isPaid ? '(Paid)' : '') . "
                                  </label>";
                        }
                        ?>
                    </div>
                </div>

                <!-- Amount -->
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" name="amount" id="amount" step="0.01" readonly>
                    <small class="amount-hint" id="amountHint"></small>
                </div>

                <!-- Payment Method Selection -->
                <div class="payment-method-section">
                    <label>Select Payment Method</label>
                    <div class="payment-methods">
                        <label>
                            <input type="radio" name="payment_method" value="online">
                            Credit/Debit Card
                        </label>
                        <label>
                            <input type="radio" name="payment_method" value="transfer">
                            Bank Transfer
                        </label>
                    </div>

                    <!-- Credit Card Details -->
                    <div id="cardDetails" class="card-details-group" style="display: none;">
                        <div class="form-group card-number-group">
                            <label>Card Number</label>
                            <span class="card-icon">💳</span>
                            <input type="text" name="card_number" pattern="\d{16}" maxlength="16" 
                                   placeholder="1234 5678 9012 3456">
                        </div>
                        <div class="card-expiry-cvv">
                            <div class="form-group">
                                <label>Expire Date</label>
                                <input type="text" name="expire_date" placeholder="MM/YY" maxlength="5">
                            </div>
                            <div class="form-group">
                                <label>CVV</label>
                                <input type="text" name="cvv" pattern="\d{3}" maxlength="3" 
                                       placeholder="123">
                            </div>
                        </div>
                    </div>

                    <!-- Bank Transfer Details -->
                    <div id="bankTransfer" class="bank-transfer-group" style="display: none;">
                        <div class="bank-details">
                            <h3>Bank Account Details</h3>
                            <p><strong>Bank:</strong> BOC</p>
                            <p><strong>Account Name:</strong> Society Fund</p>
                            <p><strong>Account Number:</strong> 1234567890</p>
                            <p><strong>Branch:</strong> Main Branch</p>
                        </div>
                        
                        <div class="receipt-upload-group">
                            <label>Upload Payment Receipt</label>
                            <input type="file" name="receipt" accept="image/*" 
                                   placeholder="Click or drag and drop to upload receipt">
                            <p class="receipt-requirements">
                                Accepted formats: JPG, PNG, GIF (Max size: 5MB)
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Terms and Submit -->
                <div class="form-group">
                    <label class="checkbox-container">
                        <input type="checkbox" name="terms" required>
                        I agree to the Terms & Conditions
                    </label>
                </div>

                <div class="button-group">
                    <button type="submit" class="btn-submit">Submit Payment</button>
                    <button type="button" class="btn-cancel" onclick="window.location.href='home-member.php'">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Pass PHP data to JavaScript
        const staticData = <?php echo json_encode($staticData); ?>;
        const remainingRegFee = <?php echo $remainingRegFee; ?>;
        const memberStatus = "<?php echo $memberStatus; ?>";
    </script>
    <script src="../../assets/js/memberPayments.js"></script>
</body>
</html>