<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if member is trying to view their own loan
$isMemberView = isset($_SESSION['role']) && $_SESSION['role'] == 'member';
$memberId = isset($_SESSION['member_id']) ? $_SESSION['member_id'] : null;

// Get loan ID from various sources
$loanID = null;

if ($isMemberView && !isset($_GET['id'])) {
    // Member is viewing without a specified loan ID - get their active loan
    try {
        $conn = getConnection();
        $query = "SELECT LoanID FROM Loan 
                  WHERE Member_MemberID = ? 
                  AND Status = 'approved' 
                  AND Remain_Loan > 0 
                  ORDER BY Issued_Date DESC 
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $memberId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $loanData = $result->fetch_assoc();
            $loanID = $loanData['LoanID'];
        } else {
            // No active loan found, check for any approved loan
            $query = "SELECT LoanID FROM Loan 
                      WHERE Member_MemberID = ? 
                      AND Status = 'approved' 
                      ORDER BY Issued_Date DESC 
                      LIMIT 1";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $memberId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $loanData = $result->fetch_assoc();
                $loanID = $loanData['LoanID'];
            } else {
                // Still no loan found, check for pending loans
                $query = "SELECT LoanID FROM Loan 
                          WHERE Member_MemberID = ? 
                          ORDER BY Issued_Date DESC 
                          LIMIT 1";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $memberId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $loanData = $result->fetch_assoc();
                    $loanID = $loanData['LoanID'];
                }
            }
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error retrieving loan data: " . $e->getMessage();
    }
    
    // If no loan found at all
    if (!$loanID) {
        $_SESSION['error_message'] = "No loan found for your account";
        if ($isMemberView) {
            header("Location: ../../member/home-member.php");
        } else {
            header("Location: loan.php");
        }
        exit();
    }
} else {
    // Normal loan ID from GET parameter
    $loanID = isset($_GET['id']) ? $_GET['id'] : null;
}

// Check if ID parameter exists
if (!$loanID) {
    $_SESSION['error_message'] = "No loan ID provided";
    if ($isMemberView) {
        header("Location: ../../member/home-member.php");
    } else {
        header("Location: loan.php");
    }
    exit();
}

// Function to get loan details
function getLoanDetails($loanID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            l.LoanID, l.Amount, l.Term, l.Reason, l.Issued_Date, l.Due_Date, 
            l.Paid_Loan, l.Remain_Loan, l.Paid_Interest, l.Remain_Interest,
            l.Status, l.Member_MemberID, m.Name as MemberName
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

// Security check for member access
$loan = getLoanDetails($loanID);
if (!$loan) {
    $_SESSION['error_message'] = "Loan not found";
    if ($isMemberView) {
        header("Location: ../../member/home-member.php");
    } else {
        header("Location: loan.php");
    }
    exit();
}

// If member is viewing, verify they can only see their own loans
if ($isMemberView && $loan['Member_MemberID'] !== $memberId) {
    $_SESSION['error_message'] = "You are not authorized to view this loan";
    header("Location: ../member/home-member.php");
    exit();
}

// Rest of the original viewLoan.php code follows...
// Function to get guarantors for a loan
function getLoanGuarantors($loanID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT g.GuarantorID, g.Name, g.MemberID, m.Name as MemberName
        FROM Guarantor g
        LEFT JOIN Member m ON g.MemberID = m.MemberID
        WHERE g.Loan_LoanID = ?
    ");
    
    $stmt->bind_param("s", $loanID);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get loan payment history
function getLoanPayments($loanID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT p.PaymentID, p.Amount, p.Date, p.Method, p.status
        FROM Payment p
        JOIN LoanPayment lp ON p.PaymentID = lp.PaymentID
        WHERE lp.LoanID = ?
        ORDER BY p.Date DESC
    ");
    
    $stmt->bind_param("s", $loanID);
    $stmt->execute();
    return $stmt->get_result();
}

// Get guarantors
$guarantors = getLoanGuarantors($loanID);

// Get payment history
$payments = getLoanPayments($loanID);

// Calculate progress percentages
$totalAmount = $loan['Amount'] + $loan['Paid_Interest'] + $loan['Remain_Interest'];
$paidAmount = $loan['Paid_Loan'] + $loan['Paid_Interest'];
$paymentProgress = ($totalAmount > 0) ? ($paidAmount / $totalAmount) * 100 : 0;

// Get interest rate from static table
function getInterestRate() {
    $sql = "SELECT interest FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['interest'] ?? 0;
}

$interestRate = getInterestRate();

// Format the status with appropriate class
function getStatusClass($status) {
    switch($status) {
        case 'approved': return 'status-approved';
        case 'pending': return 'status-pending';
        case 'rejected': return 'status-rejected';
        default: return 'status-none';
    }
}

$statusClass = getStatusClass($loan['Status']);

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>View Loan</title>
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
                overflow: hidden;
            }
            .container { 
                padding: 5px; 
                height: 100vh;
                overflow: auto;
            }
            .header-card { 
                display: none; 
            }
            .main-container { 
                padding: 0; 
            }
            .loan-detail-container {
                background-color: #fff;
                width: 100%;
                margin: 0 auto;
                display: flex;
                flex-direction: column;
                height: calc(100vh - 20px);
            }
            .loan-detail-title {
                color: #1e3c72;
                margin-bottom: 10px;
                text-align: center;
                font-size: 1.2rem;
                font-weight: bold;
                padding: 5px 0;
                border-bottom: 1px solid #eee;
            }
            .top-info-container {
                display: flex;
                flex-direction: column;
            }
            .grid-container {
                display: flex;
                width: 100%;
            }
            .left-info {
                flex: 1;
                padding-right: 10px;
            }
            .right-info {
                flex: 1;
                padding-left: 10px;
                border-left: 1px solid #eee;
            }
            .loan-details-section {
                margin-bottom: 10px;
                padding: 10px;
                background-color: #f9f9f9;
                border-radius: 5px;
            }
            .section-title {
                font-weight: 600;
                margin-bottom: 5px;
                color: #1e3c72;
                font-size: 1rem;
                border-bottom: 1px solid #ddd;
                padding-bottom: 5px;
            }
            .sub-section-title {
                font-weight: 600;
                margin-bottom: 5px;
                color: #1e3c72;
                font-size: 0.9rem;
            }
            .guarantor-block {
                margin-bottom: 8px;
                padding: 5px;
                background-color: #f0f4f9;
                border-radius: 4px;
            }
            .detail-row {
                display: flex;
                margin-bottom: 5px;
            }
            .detail-label {
                flex: 1;
                font-weight: 600;
                color: #333;
                font-size: 0.85rem;
            }
            .detail-value {
                flex: 1.5;
                font-size: 0.85rem;
            }
            .full-width {
                width: 100%;
                margin-top: 8px;
            }
            .payment-info-container {
                width: 100%;
            }
            .payment-details {
                width: 100%;
                display: flex;
                flex-direction: column;
            }
            .payment-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 5px;
            }
            .payment-item {
                flex: 1;
                padding: 0 5px;
            }
            .alert {
                padding: 8px 10px;
                margin-bottom: 10px;
                border-radius: 4px;
                font-size: 0.9rem;
            }
            .progress-container {
                width: 100%;
                background-color: #e0e0e0;
                border-radius: 5px;
                margin: 5px 0;
            }
            .progress-bar {
                height: 15px;
                background-color: #4CAF50;
                border-radius: 5px;
                text-align: center;
                color: white;
                font-weight: bold;
                font-size: 0.7rem;
                line-height: 15px;
            }
            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 0.75rem;
                font-weight: bold;
            }
            .status-approved {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-pending {
                background-color: #fff8e8;
                color: #f6a609;
            }
            .status-rejected {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
            .table-container {
                margin-top: 5px;
                overflow-x: auto;
                max-height: 130px;
                overflow-y: auto;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.85rem;
            }
            th, td {
                padding: 6px 8px;
                text-align: left;
                border-bottom: 1px solid #e0e0e0;
            }
            th {
                background-color: #f5f5f5;
                font-weight: 600;
                color: #333;
                position: sticky;
                top: 0;
                z-index: 10;
            }
            tr:hover {
                background-color: #f9f9f9;
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
        <title>View Loan</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
        <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
        <link rel="stylesheet" href="../../../assets/css/alert.css">
        <script src="../../../assets/js/alertHandler.js"></script>
        <style>
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        background-color: #f5f7fa;
    }
    .container {
        /* padding: 20px; */
    }
    .loan-detail-container {
        background-color: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 20px;
        max-width: 1000px;
        margin: 20px auto;
    }
    .loan-detail-title {
        color: #1e3c72;
        margin-bottom: 20px;
        text-align: center;
        font-size: 1.5rem;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }
    .top-info-container {
        display: flex;
        flex-direction: column;
    }
    .grid-container {
        display: flex;
        width: 100%;
    }
    .left-info {
        flex: 1;
        padding-right: 20px;
    }
    .right-info {
        flex: 1;
        padding-left: 20px;
        border-left: 1px solid #eee;
    }
    .loan-details-section {
        margin-bottom: 20px;
        padding: 15px;
        background-color: #f9f9f9;
        border-radius: 5px;
    }
    .section-title {
        font-weight: 600;
        margin-bottom: 10px;
        color: #1e3c72;
        font-size: 1.2rem;
        border-bottom: 1px solid #ddd;
        padding-bottom: 8px;
    }
    .sub-section-title {
        font-weight: 600;
        margin-bottom: 10px;
        color: #1e3c72;
        font-size: 1.1rem;
    }
    .guarantor-block {
        margin-bottom: 15px;
        padding: 10px;
        background-color: #f0f4f9;
        border-radius: 5px;
    }
    .detail-row {
        display: flex;
        margin-bottom: 8px;
    }
    .detail-label {
        flex: 1;
        font-weight: 600;
        color: #333;
    }
    .detail-value {
        flex: 1.5;
    }
    .full-width {
        width: 100%;
        margin-top: 15px;
    }
    .payment-info-container {
        width: 100%;
    }
    .payment-details {
        width: 100%;
        display: flex;
        flex-direction: column;
    }
    .payment-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
    }
    .payment-item {
        flex: 1;
        padding: 0 10px;
    }
    .alert {
        padding: 10px 15px;
        margin-bottom: 15px;
        border-radius: 4px;
    }
    .progress-container {
        width: 100%;
        background-color: #e0e0e0;
        border-radius: 5px;
        margin: 10px 0;
    }
    .progress-bar {
        height: 20px;
        background-color: #4CAF50;
        border-radius: 5px;
        text-align: center;
        color: white;
        font-weight: bold;
        font-size: 0.8rem;
        line-height: 20px;
    }
    .status-badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: bold;
    }
    .status-approved {
        background-color: #c2f1cd;
        color: rgb(25, 151, 10);
    }
    .status-pending {
        background-color: #fff8e8;
        color: #f6a609;
    }
    .status-rejected {
        background-color: #e2bcc0;
        color: rgb(234, 59, 59);
    }
    .table-container {
        margin-top: 10px;
        overflow-x: auto;
        max-height: 300px;
        overflow-y: auto;
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
        padding: 10px 15px;
        text-align: left;
        border-bottom: 1px solid #e0e0e0;
    }
    th {
        background-color: #f5f5f5;
        font-weight: 600;
        color: #333;
        position: sticky;
        top: 0;
        z-index: 10;
    }
    tr:hover {
        background-color: #f9f9f9;
    }
    .btn-container {
        display: flex;
        justify-content: space-between;
        margin-top: 20px;
    }
    .btn {
        padding: 8px 15px;
        border: none;
        border-radius: 4px;
        font-size: 14px;
        cursor: pointer;
        transition: background-color 0.3s;
        text-decoration: none;
        display: inline-block;
    }
    .btn-primary {
        background-color: #1e3c72;
        color: white;
    }
    .btn-primary:hover {
        background-color: #16305c;
    }
    .btn-secondary {
        background-color: #6c757d;
        color: white;
    }
    .btn-secondary:hover {
        background-color: #5a6268;
    }
    .flex-container {
        display: flex;
        flex: 1;
        overflow: hidden;
    }
    .left-column {
        flex: 1;
        padding-right: 10px;
        overflow-y: auto;
    }
    .right-column {
        flex: 1;
        padding-left: 10px;
        overflow-y: auto;
    }
    .tables-section {
        flex: 1;
        overflow-y: auto;
    }
</style>
    </head>
    <body>
        <div class="main-container">
            <?php if ($isMemberView): ?>
                <?php include '../../templates/navbar-member.php'; ?>
            <?php else: ?>
                <?php include '../../templates/navbar-treasurer.php'; ?>
            <?php endif; ?>

            <div class="container">
                <div class="header-card">
                    <h1>View Loan</h1>
                    <?php if ($isMemberView): ?>
                    <a href="../../member/home-member.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="loan.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Loans
                    </a>
                <?php endif; ?>
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

            <div class="loan-detail-container">
                <h2 class="loan-detail-title">Loan Details #<?php echo htmlspecialchars($loanID); ?></h2>
                
                <div class="loan-details-section">
                    <div class="top-info-container">
                        <div class="loan-basic-info">
                            <div class="section-title">Loan Information</div>
                            <div class="grid-container">
                                <div class="left-info">
                                    <div class="detail-row">
                                        <div class="detail-label">Loan ID:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($loan['LoanID']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Status:</div>
                                        <div class="detail-value">
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo ucfirst(htmlspecialchars($loan['Status'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Member ID:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($loan['Member_MemberID']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Member Name:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($loan['MemberName']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Amount:</div>
                                        <div class="detail-value">Rs. <?php echo number_format($loan['Amount'], 2); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Interest Rate:</div>
                                        <div class="detail-value"><?php echo number_format($interestRate, 2); ?>%</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Term:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($loan['Term']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Issue Date:</div>
                                        <div class="detail-value"><?php echo date('Y-m-d', strtotime($loan['Issued_Date'])); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Due Date:</div>
                                        <div class="detail-value"><?php echo date('Y-m-d', strtotime($loan['Due_Date'])); ?></div>
                                    </div>
                                </div>
                                
                                <div class="right-info">
                                    <?php if($guarantors->num_rows > 0): ?>
                                    <div class="guarantor-info">
                                        <div class="sub-section-title">Guarantors</div>
                                        <?php while($guarantor = $guarantors->fetch_assoc()): ?>
                                        <div class="guarantor-block">
                                            <div class="detail-row">
                                                <div class="detail-label">Guarantor ID:</div>
                                                <div class="detail-value"><?php echo htmlspecialchars($guarantor['GuarantorID']); ?></div>
                                            </div>
                                            <div class="detail-row">
                                                <div class="detail-label">Guarantor Name:</div>
                                                <div class="detail-value"><?php echo htmlspecialchars($guarantor['Name']); ?></div>
                                            </div>
                                            <div class="detail-row">
                                                <div class="detail-label">Member ID:</div>
                                                <div class="detail-value"><?php echo htmlspecialchars($guarantor['MemberID']); ?></div>
                                            </div>
                                        </div>
                                        <?php endwhile; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="detail-row full-width">
                                <div class="detail-label">Reason:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($loan['Reason']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if($loan['Status'] != 'pending'): ?>
                <div class="loan-details-section">
                    <div class="section-title">Payment Status</div>
                    <div class="payment-info-container">
                        <div class="payment-details">
                            <div class="payment-row">
                                <div class="payment-item">
                                    <div class="detail-label">Loan Amount:</div>
                                    <div class="detail-value">Rs. <?php echo number_format($loan['Amount'], 2); ?></div>
                                </div>
                                <div class="payment-item">
                                    <div class="detail-label">Paid Amount:</div>
                                    <div class="detail-value">Rs. <?php echo number_format($loan['Paid_Loan'], 2); ?></div>
                                </div>
                                <div class="payment-item">
                                    <div class="detail-label">Remaining Loan:</div>
                                    <div class="detail-value">Rs. <?php echo number_format($loan['Remain_Loan'], 2); ?></div>
                                </div>
                            </div>
                            <div class="payment-row">
                                <div class="payment-item">
                                    <div class="detail-label">Interest Paid:</div>
                                    <div class="detail-value">Rs. <?php echo number_format($loan['Paid_Interest'], 2); ?></div>
                                </div>
                                <div class="payment-item">
                                    <div class="detail-label">Remaining Interest:</div>
                                    <div class="detail-value">Rs. <?php echo number_format($loan['Remain_Interest'], 2); ?></div>
                                </div>
                                <div class="payment-item">
                                    <div class="detail-label">Total Remaining:</div>
                                    <div class="detail-value">Rs. <?php echo number_format($loan['Remain_Loan'] + $loan['Remain_Interest'], 2); ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="progress-container">
                            <div class="progress-bar" style="width: <?php echo min(100, $paymentProgress); ?>%">
                                <?php echo number_format($paymentProgress, 1); ?>%
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if($payments->num_rows > 0): ?>
                <div class="loan-details-section">
                    <div class="section-title">Payment History</div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Payment ID</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($payment = $payments->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['PaymentID']); ?></td>
                                    <td>Rs. <?php echo number_format($payment['Amount'], 2); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($payment['Date'])); ?></td>
                                    <td><?php echo htmlspecialchars($payment['Method']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['status']); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!$isPopup): ?>
                <div class="btn-container">
                    <?php if ($isMemberView): ?>
                        <a href="../../member/home-member.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    <?php else: ?>
                        <a href="loan.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Loans
                        </a>
                        <a href="editLoan.php?id=<?php echo $loanID; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Loan
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

<?php if ($isPopup): ?>
    </div>
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
    function viewLoan(loanID) {
    // Set the iframe source to your viewLoan.php page
    document.getElementById('loanFrame').src = `viewLoan.php?id=${loanID}&popup=true`;
    
    // Show the modal
    document.getElementById('loanModal').style.display = 'block';
}

function closeLoanModal() {
    document.getElementById('loanModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('loanModal');
    if (event.target == modal) {
        closeLoanModal();
    }
};
</script>