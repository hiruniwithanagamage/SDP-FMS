<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if member is trying to view their own fee
$isMemberView = isset($_SESSION['role']) && $_SESSION['role'] == 'member';
$memberId = isset($_SESSION['member_id']) ? $_SESSION['member_id'] : null;

// Get fee ID from various sources
$feeID = null;

if ($isMemberView && !isset($_GET['id'])) {
    // Member is viewing without a specified fee ID - get their recent fee
    try {
        $conn = getConnection();
        $query = "SELECT FeeID FROM MembershipFee 
                  WHERE Member_MemberID = ? 
                  AND IsPaid = 'No' 
                  ORDER BY Date DESC 
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $memberId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $feeData = $result->fetch_assoc();
            $feeID = $feeData['FeeID'];
        } else {
            // No unpaid fee found, check for any fee
            $query = "SELECT FeeID FROM MembershipFee 
                      WHERE Member_MemberID = ? 
                      ORDER BY Date DESC 
                      LIMIT 1";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $memberId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $feeData = $result->fetch_assoc();
                $feeID = $feeData['FeeID'];
            }
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error retrieving fee data: " . $e->getMessage();
    }
    
    // If no fee found at all
    if (!$feeID) {
        $_SESSION['error_message'] = "No membership fee found for your account";
        if ($isMemberView) {
            header("Location: ../../member/home-member.php");
        } else {
            header("Location: membershipFee.php");
        }
        exit();
    }
} else {
    // Normal fee ID from GET parameter
    $feeID = isset($_GET['id']) ? $_GET['id'] : null;
}

// Check if ID parameter exists
if (!$feeID) {
    $_SESSION['error_message'] = "No membership fee ID provided";
    if ($isMemberView) {
        header("Location: ../../member/home-member.php");
    } else {
        header("Location: membershipFee.php");
    }
    exit();
}

// Function to get fee details
function getFeeDetails($feeID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            mf.FeeID, mf.Amount, mf.Date, mf.Term, mf.Type, mf.IsPaid,
            mf.Member_MemberID, m.Name as MemberName
        FROM MembershipFee mf
        JOIN Member m ON mf.Member_MemberID = m.MemberID
        WHERE mf.FeeID = ?
    ");
    
    $stmt->bind_param("s", $feeID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Security check for member access
$fee = getFeeDetails($feeID);
if (!$fee) {
    $_SESSION['error_message'] = "Membership fee not found";
    if ($isMemberView) {
        header("Location: ../../member/home-member.php");
    } else {
        header("Location: membershipFee.php");
    }
    exit();
}

// If member is viewing, verify they can only see their own fees
if ($isMemberView && $fee['Member_MemberID'] !== $memberId) {
    $_SESSION['error_message'] = "You are not authorized to view this fee";
    header("Location: ../member/home-member.php");
    exit();
}

// Function to get fee payment history
function getFeePayments($feeID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT p.PaymentID, p.Amount, p.Date, p.Method, p.status, mfp.Details
        FROM Payment p
        JOIN MembershipFeePayment mfp ON p.PaymentID = mfp.PaymentID
        WHERE mfp.FeeID = ?
        ORDER BY p.Date DESC
    ");
    
    $stmt->bind_param("s", $feeID);
    $stmt->execute();
    return $stmt->get_result();
}

// Get payment history
$payments = getFeePayments($feeID);

// Format payment status class
$statusClass = $fee['IsPaid'] === 'Yes' ? 'status-paid' : 'status-unpaid';
$statusText = $fee['IsPaid'] === 'Yes' ? 'Paid' : 'Unpaid';

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>View Membership Fee</title>
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
            .fee-detail-container {
                background-color: #fff;
                width: 100%;
                margin: 0 auto;
                display: flex;
                flex-direction: column;
                height: calc(100vh - 20px);
            }
            .fee-detail-title {
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
            .fee-details-section {
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
            .alert {
                padding: 8px 10px;
                margin-bottom: 10px;
                border-radius: 4px;
                font-size: 0.9rem;
            }
            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 0.75rem;
                font-weight: bold;
            }
            .status-paid {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-unpaid {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
            .table-container {
                margin-top: 5px;
                overflow-x: auto;
                max-height: 500px;
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
        <title>View Membership Fee</title>
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
    .fee-detail-container {
        background-color: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 20px;
        max-width: 1000px;
        margin: 20px auto;
    }
    .fee-detail-title {
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
    .fee-details-section {
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
    .alert {
        padding: 10px 15px;
        margin-bottom: 15px;
        border-radius: 4px;
    }
    .status-badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: bold;
    }
    .status-paid {
        background-color: #c2f1cd;
        color: rgb(25, 151, 10);
    }
    .status-unpaid {
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
                    <h1>View Membership Fee</h1>
                    <?php if ($isMemberView): ?>
                    <a href="../../member/home-member.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="membershipFee.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Membership Fees
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

            <div class="fee-detail-container">
                <h2 class="fee-detail-title">Membership Fee Details #<?php echo htmlspecialchars($feeID); ?></h2>
                
                <div class="fee-details-section">
                    <div class="top-info-container">
                        <div class="fee-basic-info">
                            <div class="section-title">Fee Information</div>
                            <div class="grid-container">
                                <div class="left-info">
                                    <div class="detail-row">
                                        <div class="detail-label">Fee ID:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($fee['FeeID']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Status:</div>
                                        <div class="detail-value">
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Member ID:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($fee['Member_MemberID']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Member Name:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($fee['MemberName']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Type:</div>
                                        <div class="detail-value"><?php echo ucfirst(htmlspecialchars($fee['Type'])); ?></div>
                                    </div>
                                </div>
                                
                                <div class="right-info">
                                    <div class="detail-row">
                                        <div class="detail-label">Amount:</div>
                                        <div class="detail-value">Rs. <?php echo number_format($fee['Amount'], 2); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Date:</div>
                                        <div class="detail-value"><?php echo date('Y-m-d', strtotime($fee['Date'])); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Term:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($fee['Term']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if($payments->num_rows > 0): ?>
                <div class="fee-details-section">
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
                                    <th>Details</th>
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
                                    <td><?php echo htmlspecialchars($payment['Details'] ?? ''); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="fee-details-section">
                    <div class="section-title">Payment History</div>
                    <p style="text-align: center; color: #666;">No payment records found for this fee.</p>
                </div>
                <?php endif; ?>
                
                <?php if (!$isPopup): ?>
                <div class="btn-container">
                    <?php if ($isMemberView): ?>
                        <a href="../../member/home-member.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    <?php else: ?>
                        <a href="membershipFee.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Membership Fees
                        </a>
                        <a href="editMembershipFee.php?id=<?php echo $feeID; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Fee
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
    function viewFee(feeID) {
    // Set the iframe source to your viewMembershipFee.php page
    document.getElementById('feeFrame').src = `viewMembershipFee.php?id=${feeID}&popup=true`;
    
    // Show the modal
    document.getElementById('feeModal').style.display = 'block';
}

function closeFeeModal() {
    document.getElementById('feeModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('feeModal');
    if (event.target == modal) {
        closeFeeModal();
    }
};
</script>