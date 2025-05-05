<?php
session_start();
require_once "../../../config/database.php";

// Check if payment ID is provided
if(!isset($_GET['id'])) {
    echo "Payment ID is required.";
    exit;
}

$paymentID = $_GET['id'];
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';

// Function to get payment details
function getPaymentDetails($paymentID) {
    $sql = "SELECT 
            p.*,
            m.Name as MemberName,
            m.MemberID
        FROM Payment p
        JOIN Member m ON p.Member_MemberID = m.MemberID
        WHERE p.PaymentID = ?";
    
    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $paymentID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Function to get linked membership fees
function getLinkedMembershipFees($paymentID) {
    $sql = "SELECT 
            mf.FeeID,
            mf.Type as Month,
            mf.Term as Year,
            mf.Amount,
            mf.IsPaid
        FROM MembershipFee mf
        JOIN MembershipFeePayment mfp ON mf.FeeID = mfp.FeeID
        WHERE mfp.PaymentID = ?";
    
    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $paymentID);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get linked fines
function getLinkedFines($paymentID) {
    $sql = "SELECT 
            f.FineID,
            f.Description,
            f.Amount,
            f.Date as FineDate,
            f.IsPaid
        FROM Fine f
        JOIN FinePayment fp ON f.FineID = fp.FineID
        WHERE fp.PaymentID = ?";
    
    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $paymentID);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get payment history
function getPaymentHistory($memberID, $paymentID) {
    $sql = "SELECT 
            p.PaymentID,
            p.Payment_Type,
            p.Amount,
            p.Date,
            p.Method
        FROM Payment p
        WHERE p.Member_MemberID = ? AND p.PaymentID != ?
        ORDER BY p.Date DESC
        LIMIT 5";
    
    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $memberID, $paymentID);
    $stmt->execute();
    return $stmt->get_result();
}

// Get data
$payment = getPaymentDetails($paymentID);

if(!$payment) {
    echo "Payment not found.";
    exit;
}

$membershipFees = getLinkedMembershipFees($paymentID);
$fines = getLinkedFines($paymentID);
$paymentHistory = getPaymentHistory($payment['Member_MemberID'], $paymentID);

// Format payment date
$paymentDate = date('Y-m-d', strtotime($payment['Date']));

// Defining fee types based on your database structure
$feeTypes = [
    'monthly' => 'Monthly Fee',
    'registration' => 'Registration Fee',
    'other' => 'Other'
];

// Define fine descriptions from ENUM in database
$fineDescriptions = [
    'late' => 'Late Fee',
    'absent' => 'Absence Fee',
    'violation' => 'Rules Violation'
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Details - <?php echo $payment['PaymentID']; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <?php if(!$isPopup): ?>
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <?php endif; ?>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f9f9f9;
            color: #333;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .payment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .payment-id {
            font-size: 24px;
            font-weight: bold;
            color: #1e3c72;
        }
        
        .payment-status {
            font-size: 16px;
            padding: 6px 12px;
            border-radius: 20px;
            background-color: #c2f1cd;
            color: #10b981;
            display: inline-block;
        }
        
        .payment-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .detail-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #1e3c72;
        }
        
        .detail-label {
            font-size: 14px;
            color: #71717a;
            margin-bottom: 5px;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }
        
        .section-title {
            font-size: 20px;
            color: #1e3c72;
            margin-top: 30px;
            margin-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        th {
            background-color: #f3f4f6;
            font-weight: 600;
            color: #1f2937;
        }
        
        tbody tr:hover {
            background-color: #f9fafb;
        }
        
        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            margin-top: 20px;
            background-color: #1e3c72;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        
        .back-btn:hover {
            background-color: #15294d;
        }
        
        .print-btn {
            display: inline-block;
            padding: 10px 20px;
            margin-left: 10px;
            background-color: #10b981;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        
        .print-btn:hover {
            background-color: #0d9669;
        }
        
        .empty-message {
            text-align: center;
            padding: 20px;
            color: #6b7280;
            font-style: italic;
            background-color: #f9fafb;
            border-radius: 5px;
        }
        
        .status-paid {
            color: #10b981;
            background-color: #d1fae5;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .status-unpaid {
            color: #ef4444;
            background-color: #fee2e2;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="payment-header">
            <div class="payment-id">Payment #<?php echo $payment['PaymentID']; ?></div>
            <div class="payment-date">Date: <?php echo $paymentDate; ?></div>
        </div>
        
        <div class="payment-details">
            <div class="detail-card">
                <div class="detail-label">Member</div>
                <div class="detail-value"><?php echo htmlspecialchars($payment['MemberName']); ?> (<?php echo htmlspecialchars($payment['MemberID']); ?>)</div>
            </div>
            
            <div class="detail-card">
                <div class="detail-label">Amount</div>
                <div class="detail-value">Rs. <?php echo number_format($payment['Amount'], 2); ?></div>
            </div>
            
            <div class="detail-card">
                <div class="detail-label">Payment Type</div>
                <div class="detail-value"><?php echo htmlspecialchars($payment['Payment_Type']); ?></div>
            </div>
            
            <div class="detail-card">
                <div class="detail-label">Payment Method</div>
                <div class="detail-value"><?php echo htmlspecialchars($payment['Method']); ?></div>
            </div>
            
            <div class="detail-card">
                <div class="detail-label">Term</div>
                <div class="detail-value"><?php echo htmlspecialchars($payment['Term']); ?></div>
            </div>
            
            <?php if(isset($payment['status']) && !empty($payment['status'])): ?>
            <div class="detail-card">
                <div class="detail-label">Payment Status</div>
                <div class="detail-value"><?php echo ucfirst(htmlspecialchars($payment['status'])); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($payment['Notes'])): ?>
            <div class="detail-card">
                <div class="detail-label">Notes</div>
                <div class="detail-value"><?php echo nl2br(htmlspecialchars($payment['Notes'])); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if($membershipFees->num_rows > 0): ?>
        <h3 class="section-title">Linked Membership Fees</h3>
        <table>
            <thead>
                <tr>
                    <th>Fee ID</th>
                    <th>Fee Type</th>
                    <th>Term/Year</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while($fee = $membershipFees->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($fee['FeeID']); ?></td>
                    <td><?php echo isset($feeTypes[$fee['Month']]) ? $feeTypes[$fee['Month']] : $fee['Month']; ?></td>
                    <td><?php echo $fee['Year']; ?></td>
                    <td>Rs. <?php echo number_format($fee['Amount'], 2); ?></td>
                    <td>
                        <span class="<?php echo $fee['IsPaid'] == 'Yes' ? 'status-paid' : 'status-unpaid'; ?>">
                            <?php echo $fee['IsPaid'] == 'Yes' ? 'Paid' : 'Unpaid'; ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php if($fines->num_rows > 0): ?>
        <h3 class="section-title">Linked Fines</h3>
        <table>
            <thead>
                <tr>
                    <th>Fine ID</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while($fine = $fines->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($fine['FineID']); ?></td>
                    <td><?php echo isset($fineDescriptions[$fine['Description']]) ? $fineDescriptions[$fine['Description']] : $fine['Description']; ?></td>
                    <td>Rs. <?php echo number_format($fine['Amount'], 2); ?></td>
                    <td><?php echo date('Y-m-d', strtotime($fine['FineDate'])); ?></td>
                    <td>
                        <span class="<?php echo $fine['IsPaid'] == 'Yes' ? 'status-paid' : 'status-unpaid'; ?>">
                            <?php echo $fine['IsPaid'] == 'Yes' ? 'Paid' : 'Unpaid'; ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <h3 class="section-title">Recent Payment History</h3>
        <?php if($paymentHistory->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Payment ID</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Method</th>
                </tr>
            </thead>
            <tbody>
                <?php while($historyPayment = $paymentHistory->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($historyPayment['PaymentID']); ?></td>
                    <td><?php echo htmlspecialchars($historyPayment['Payment_Type']); ?></td>
                    <td>Rs. <?php echo number_format($historyPayment['Amount'], 2); ?></td>
                    <td><?php echo date('Y-m-d', strtotime($historyPayment['Date'])); ?></td>
                    <td><?php echo htmlspecialchars($historyPayment['Method']); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-message">No recent payment history found for this member.</div>
        <?php endif; ?>
        
        <?php if(!$isPopup): ?>
        <div class="action-buttons">
            <a href="payment.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Payments</a>
            <a href="../payments/payment_receipt.php?payment_id=<?php echo $payment['PaymentID']; ?>" class="print-btn" target="_blank"><i class="fas fa-print"></i> Print Receipt</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>