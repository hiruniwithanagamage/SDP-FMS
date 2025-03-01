<?php
session_start();
require_once "../../config/database.php";

// Verify treasurer authentication
if (!isset($_SESSION['treasurer_id'])) {
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

// Get treasurer details
$treasurerId = $_SESSION['treasurer_id'];
$query = "SELECT Name FROM Treasurer WHERE TreasurerID = '$treasurerId'";
$result = search($query);
$treasurerData = $result->fetch_assoc();

// Initialize variables
$selectedMemberId = '';
$memberData = null;
$unpaidFeesResult = null;
$unpaidFinesResult = null;
$activeLoansResult = null;
$regFeePaid = 0;
$remainingRegFee = 0;
$memberStatus = '';

// Check if member is selected via POST
if (isset($_POST['member_select']) && !empty($_POST['member_select'])) {
    $selectedMemberId = $_POST['member_select'];
    
    // Get member details
    $query = "SELECT Name, Status FROM Member WHERE MemberID = '$selectedMemberId'";
    $result = search($query);
    $memberData = $result->fetch_assoc();

    // Get unpaid fees
    $unpaidFeesQuery = "SELECT * FROM MembershipFee 
                        WHERE Member_MemberID = '$selectedMemberId' 
                        AND IsPaid = 'No' 
                        ORDER BY Date ASC";
    $unpaidFeesResult = search($unpaidFeesQuery);

    // Get unpaid fines
    $unpaidFinesQuery = "SELECT * FROM Fine 
                         WHERE Member_MemberID = '$selectedMemberId' 
                         AND IsPaid = 'No' 
                         ORDER BY Date ASC";
    $unpaidFinesResult = search($unpaidFinesQuery);

    // Get active loans
    $activeLoansQuery = "SELECT * FROM Loan 
                         WHERE Member_MemberID = '$selectedMemberId' 
                         AND Status = 'approved' 
                         AND Remain_Loan > 0
                         ORDER BY Issued_Date DESC";
    $activeLoansResult = search($activeLoansQuery);

    // Get total paid registration fee
    $regFeePaidQuery = "SELECT COALESCE(SUM(P.Amount), 0) as total_paid
                        FROM MembershipFee MF
                        JOIN MembershipFeePayment MFP ON MF.FeeID = MFP.FeeID
                        JOIN Payment P ON MFP.PaymentID = P.PaymentID
                        WHERE MF.Member_MemberID = '$selectedMemberId'
                        AND MF.Type = 'registration'
                        AND MF.Term = $currentYear";
    $regFeePaidResult = search($regFeePaidQuery);
    $regFeePaid = $regFeePaidResult->fetch_assoc()['total_paid'];
    $remainingRegFee = $staticData['registration_fee'] - $regFeePaid;

    $memberStatus = $memberData['Status'];
}

// Fetch all members for dropdown
$memberQuery = "SELECT MemberID, Name FROM Member ORDER BY Name";
$memberQueryResult = search($memberQuery);
$memberList = [];
while ($row = $memberQueryResult->fetch_assoc()) {
    $memberList[] = [
        'id' => $row['MemberID'],
        'text' => $row['Name']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Treasurer Payment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/memberPayment.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        .member-search {
            margin-bottom: 2rem;
        }
        .member-search select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
        }
        .member-info {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }
        .select2-container {
            width: 100% !important;
        }
        .select2-container .select2-selection--single {
            height: 38px;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
            padding-left: 12px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        .select2-dropdown {
            border: 2px solid #e0e0e0;
        }
        .select2-search__field {
            border: 1px solid #e0e0e0 !important;
            padding: 4px 8px !important;
        }
        .select2-results__option {
            padding: 8px 12px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-treasurer.php'; ?>
        <div class="container">
            <h1>Process Member Payment</h1>
            
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

            <!-- Member Selection Form -->
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" id="memberSelectForm">
                <div class="form-group member-search">
                    <label>Select Member</label>
                    <select id="member_select" name="member_select" class="member-select" required>
                        <option value="">Select or search for a member...</option>
                        <?php foreach ($memberList as $memberItem): ?>
                            <option value="<?php echo $memberItem['id']; ?>" 
                                <?php echo ($selectedMemberId == $memberItem['id']) ? 'selected' : ''; ?>>
                                <?php echo $memberItem['text']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($selectedMemberId && $memberData): ?>
            <!-- Payment Form -->
            <form id="paymentForm" action="./payments/process_treasurer_payment.php" method="POST">
                <input type="hidden" name="member_id" value="<?php echo $selectedMemberId; ?>">

                <!-- Member Info Display -->
                <div class="member-info">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" value="<?php echo htmlspecialchars($memberData['Name']); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label>Member ID</label>
                            <input type="text" value="<?php echo htmlspecialchars($selectedMemberId); ?>" disabled>
                        </div>
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
                            <?php if($activeLoansResult && $activeLoansResult->num_rows > 0): ?>
                                <option value="loan">Loan Payment</option>
                            <?php endif; ?>
                            <?php if($unpaidFinesResult && $unpaidFinesResult->num_rows > 0): ?>
                                <option value="fine">Fine Payment</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <!-- Dynamic content containers -->
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

                <!-- Month Selection Container -->
                <div id="monthSelectionContainer" style="display: none;">
                    <label>Select Months</label>
                    <div class="months-grid">
                        <?php
                        $months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                 'July', 'August', 'September', 'October', 'November', 'December'];
                        foreach ($months as $index => $month) {
                            $monthNum = $index + 1;
                            $query = "SELECT COUNT(*) as paid FROM MembershipFee 
                                    WHERE Member_MemberID = '$selectedMemberId' 
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

                <!-- Fine Selection -->
                <?php if($unpaidFinesResult && $unpaidFinesResult->num_rows > 0): ?>
                    <div class="form-group" id="fineTypeContainer" style="display: none;">
                        <label>Select Fine</label>
                        <select name="fine_id" id="fineSelect">
                            <option value="">Select a fine to pay</option>
                            <?php while($fine = $unpaidFinesResult->fetch_assoc()): ?>
                                <option value="<?php echo $fine['FineID']; ?>" 
                                        data-amount="<?php echo $fine['Amount']; ?>">
                                    <?php echo $fine['Description']; ?> Fine - 
                                    Rs. <?php echo number_format($fine['Amount'], 2); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Loan Selection -->
                <?php if($activeLoansResult && $activeLoansResult->num_rows > 0): ?>
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

                <!-- Amount -->
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" name="amount" id="amount" step="0.01" readonly>
                    <small class="amount-hint" id="amountHint"></small>
                </div>

                <!-- Payment Method -->
                <div class="payment-method-section">
                    <label>Payment Method</label>
                    <div class="payment-methods">
                        <label>
                            <input type="radio" name="payment_method" value="onhand" checked>
                            On Hand Payment
                        </label>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="button-group">
                    <button type="submit" class="btn-submit">Process Payment</button>
                    <button type="button" class="btn-cancel" onclick="window.location.href='home-treasurer.php'">Cancel</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const staticData = <?php echo json_encode($staticData); ?>;
    $(document).ready(function() {
        // Initialize Select2
        $('#member_select').select2({
            placeholder: 'Select or search for a member...',
            allowClear: true,
            width: '100%'
        }).on('change', function() {
            // Submit the form when a member is selected
            $('#memberSelectForm').submit();
        });
    });
    </script>
    <script src="../../assets/js/treasurerPayment.js"></script>
</body>
</html>