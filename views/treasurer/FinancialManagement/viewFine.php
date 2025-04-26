<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if ID parameter exists
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No fine ID provided";
    header("Location: fine.php");
    exit();
}

$fineID = $_GET['id'];

// Function to get fine details
function getFineDetails($fineID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            f.FineID, f.Amount, f.Date, f.Description, f.IsPaid,
            f.Member_MemberID, f.Payment_PaymentID,
            m.Name as MemberName
        FROM Fine f
        JOIN Member m ON f.Member_MemberID = m.MemberID
        WHERE f.FineID = ?
    ");
    
    $stmt->bind_param("s", $fineID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Function to get fine payment details using Payment_PaymentID
function getFinePayment($paymentID) {
    if (!$paymentID) {
        return null;
    }
    
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT p.PaymentID, p.Amount, p.Date, p.Method, p.status, p.Notes
        FROM Payment p
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

// Get fine details
$fine = getFineDetails($fineID);
if (!$fine) {
    $_SESSION['error_message'] = "Fine not found";
    header("Location: fine.php");
    exit();
}

// Get payment details if available
$payment = null;
if ($fine['Payment_PaymentID']) {
    $payment = getFinePayment($fine['Payment_PaymentID']);
}

// Format the status with appropriate class
function getStatusClass($status) {
    switch($status) {
        case 'Yes': return 'status-yes';
        case 'No': return 'status-no';
        default: return 'status-none';
    }
}

$statusClass = getStatusClass($fine['IsPaid']);

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>View Fine</title>
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
            .fine-detail-container {
                background-color: #fff;
                width: 100%;
                margin: 0 auto;
                display: flex;
                flex-direction: column;
                height: calc(100vh - 20px);
            }
            .fine-detail-title {
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
            .fine-details-section {
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
            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 0.75rem;
                font-weight: bold;
            }
            .status-yes {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-no {
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
        <title>View Fine</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
        <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
        <link rel="stylesheet" href="../../../assets/css/alert.css">
        <script src="../../../assets/js/alertHandler.js"></script>
        <style>
            .fine-detail-container {
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 20px;
                max-width: 900px;
                margin: 20px auto;
            }
            .fine-detail-title {
                color: #1e3c72;
                margin-bottom: 20px;
                text-align: center;
            }
            .fine-details-section {
                margin-bottom: 20px;
                padding: 15px;
                background-color: #f9f9f9;
                border-radius: 5px;
            }
            .section-title {
                font-weight: 600;
                margin-bottom: 15px;
                color: #1e3c72;
                font-size: 1.1rem;
                border-bottom: 1px solid #ddd;
                padding-bottom: 8px;
            }
            .grid-container {
                display: flex;
                gap: 20px;
            }
            .left-info, .right-info {
                flex: 1;
            }
            .detail-row {
                display: flex;
                margin-bottom: 10px;
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
            }
            .status-badge {
                display: inline-block;
                padding: 5px 10px;
                border-radius: 15px;
                font-size: 0.8rem;
                font-weight: bold;
            }
            .status-yes {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-no {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
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
            .btn-container {
                display: flex;
                justify-content: space-between;
                margin-top: 20px;
            }
            .table-container {
                margin-top: 15px;
                overflow-x: auto;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th, td {
                padding: 10px;
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
        <div class="main-container">
            <?php include '../../templates/navbar-treasurer.php'; ?>
            <div class="container">
                <div class="header-card">
                    <h1>View Fine</h1>
                    <a href="fine.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Fines
                    </a>
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

            <div class="fine-detail-container">
                <h2 class="fine-detail-title">Fine Details #<?php echo htmlspecialchars($fineID); ?></h2>
                
                <div class="fine-details-section">
                    <div class="top-info-container">
                        <div class="fine-basic-info">
                            <div class="section-title">Fine Information</div>
                            <div class="grid-container">
                                <div class="left-info">
                                    <div class="detail-row">
                                        <div class="detail-label">Fine ID:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($fine['FineID']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Status:</div>
                                        <div class="detail-value">
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo $fine['IsPaid'] == 'Yes' ? 'Paid' : 'Unpaid'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Member ID:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($fine['Member_MemberID']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Member Name:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($fine['MemberName']); ?></div>
                                    </div>
                                </div>
                                
                                <div class="right-info">
                                    <div class="detail-row">
                                        <div class="detail-label">Amount:</div>
                                        <div class="detail-value">Rs. <?php echo number_format($fine['Amount'], 2); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Date:</div>
                                        <div class="detail-value"><?php echo date('Y-m-d', strtotime($fine['Date'])); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Fine Type:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars(ucfirst($fine['Description'])); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if($payment): ?>
                <div class="fine-details-section">
                    <div class="section-title">Payment Information</div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Payment ID</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <?php if (!empty($payment['Notes'])): ?>
                                    <th>Notes</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['PaymentID']); ?></td>
                                    <td>Rs. <?php echo number_format($payment['Amount'], 2); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($payment['Date'])); ?></td>
                                    <td><?php echo htmlspecialchars($payment['Method']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['status']); ?></td>
                                    <?php if (!empty($payment['Notes'])): ?>
                                    <td><?php echo htmlspecialchars($payment['Notes']); ?></td>
                                    <?php endif; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!$isPopup): ?>
                <div class="btn-container">
                    <a href="fine.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Fines
                    </a>
                    <a href="editFine.php?id=<?php echo $fineID; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Fine
                    </a>
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
    function viewFine(fineID) {
    // Set the iframe source to your viewFine.php page
    document.getElementById('fineFrame').src = `viewFine.php?id=${fineID}&popup=true`;
    
    // Show the modal
    document.getElementById('fineModal').style.display = 'block';
}

function closeFineModal() {
    document.getElementById('fineModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('fineModal');
    if (event.target == modal) {
        closeFineModal();
    }
};
</script>