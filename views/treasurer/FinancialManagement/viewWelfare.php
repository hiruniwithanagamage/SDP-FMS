<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if ID parameter exists
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No welfare ID provided";
    header("Location: deathWelfare.php");
    exit();
}

$welfareID = $_GET['id'];

// Function to get welfare details
function getWelfareDetails($welfareID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            dw.WelfareID, 
            dw.Amount, 
            dw.Date, 
            dw.Term, 
            dw.Relationship, 
            dw.Status,
            dw.Member_MemberID,
            m.Name as MemberName,
            m.NIC as MemberNIC
        FROM DeathWelfare dw
        JOIN Member m ON dw.Member_MemberID = m.MemberID
        WHERE dw.WelfareID = ?
    ");
    
    $stmt->bind_param("s", $welfareID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Get welfare details
$welfare = getWelfareDetails($welfareID);
if (!$welfare) {
    $_SESSION['error_message'] = "Welfare record not found";
    header("Location: deathWelfare.php");
    exit();
}

// Get related expense if any
function getRelatedExpense($welfareID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            e.ExpenseID, 
            e.Amount, 
            e.Date, 
            e.Method, 
            e.Description,
            t.Name as TreasurerName
        FROM DeathWelfare dw
        LEFT JOIN Expenses e ON dw.Expense_ExpenseID = e.ExpenseID
        LEFT JOIN Treasurer t ON e.Treasurer_TreasurerID = t.TreasurerID
        WHERE dw.WelfareID = ? AND dw.Expense_ExpenseID IS NOT NULL
    ");
    
    $stmt->bind_param("s", $welfareID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

$expense = getRelatedExpense($welfareID);

// Format relationship for display
function formatRelationship($relationship) {
    switch($relationship) {
        case 'spouse':
            return 'Spouse';
        case 'parent':
            return 'Parent';
        case 'child':
            return 'Child';
        case 'sibling':
            return 'Sibling';
        case 'self':
            return 'Self';
        default:
            return ucfirst($relationship);
    }
}

// Format status class
function getStatusClass($status) {
    switch($status) {
        case 'approved':
            return 'status-approved';
        case 'pending':
            return 'status-pending';
        case 'rejected':
            return 'status-rejected';
        default:
            return 'status-none';
    }
}

$statusClass = getStatusClass($welfare['Status']);

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>View Welfare Claim</title>
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
            .welfare-detail-container {
                background-color: #fff;
                width: 100%;
                margin: 0 auto;
                display: flex;
                flex-direction: column;
                height: calc(100vh - 20px);
            }
            .welfare-detail-title {
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
            .welfare-details-section {
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
            .expense-block {
                margin-bottom: 8px;
                padding: 5px;
                background-color: #f0f4f9;
                border-radius: 4px;
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
        <title>View Welfare Claim</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
        <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
        <link rel="stylesheet" href="../../../assets/css/alert.css">
        <script src="../../../assets/js/alertHandler.js"></script>
        <style>
            .welfare-detail-container {
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 20px;
                max-width: 800px;
                margin: 20px auto;
            }
            .welfare-detail-title {
                color: #1e3c72;
                margin-bottom: 20px;
                text-align: center;
                font-size: 1.5rem;
                font-weight: bold;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .welfare-details-section {
                margin-bottom: 20px;
                padding: 15px;
                background-color: #f9f9f9;
                border-radius: 5px;
            }
            .section-title {
                font-weight: 600;
                margin-bottom: 15px;
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
                padding-right: 15px;
            }
            .right-info {
                flex: 1;
                padding-left: 15px;
                border-left: 1px solid #eee;
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
                flex: 2;
            }
            .full-width {
                width: 100%;
                margin-top: 15px;
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
            .expense-block {
                margin-bottom: 15px;
                padding: 10px;
                background-color: #f0f4f9;
                border-radius: 5px;
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
            <?php include '../../templates/navbar-treasurer.php'; ?>
            <div class="container">
                <div class="header-card">
                    <h1>View Welfare Claim</h1>
                    <a href="deathWelfare.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Welfare Claims
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

            <div class="welfare-detail-container">
                <h2 class="welfare-detail-title">Welfare Claim Details #<?php echo htmlspecialchars($welfareID); ?></h2>
                
                <div class="welfare-details-section">
                    <div class="top-info-container">
                        <div class="welfare-basic-info">
                            <div class="section-title">Claim Information</div>
                            <div class="grid-container">
                                <div class="left-info">
                                    <div class="detail-row">
                                        <div class="detail-label">Welfare ID:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($welfare['WelfareID']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Status:</div>
                                        <div class="detail-value">
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo ucfirst(htmlspecialchars($welfare['Status'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Member ID:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($welfare['Member_MemberID']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Member Name:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($welfare['MemberName']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">NIC:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($welfare['MemberNIC']); ?></div>
                                    </div>
                                </div>
                                
                                <div class="right-info">
                                    <div class="detail-row">
                                        <div class="detail-label">Term:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($welfare['Term']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Amount:</div>
                                        <div class="detail-value">Rs. <?php echo number_format($welfare['Amount'], 2); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Claim Date:</div>
                                        <div class="detail-value"><?php echo date('Y-m-d', strtotime($welfare['Date'])); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Relationship:</div>
                                        <div class="detail-value"><?php echo formatRelationship($welfare['Relationship']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if($welfare['Status'] == 'approved' && $expense): ?>
                <div class="welfare-details-section">
                    <div class="section-title">Expense Information</div>
                    <div class="expense-block">
                        <div class="detail-row">
                            <div class="detail-label">Expense ID:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($expense['ExpenseID']); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Amount:</div>
                            <div class="detail-value">Rs. <?php echo number_format($expense['Amount'], 2); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Date:</div>
                            <div class="detail-value"><?php echo date('Y-m-d', strtotime($expense['Date'])); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Method:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($expense['Method']); ?></div>
                        </div>
                        <?php if(!empty($expense['TreasurerName'])): ?>
                        <div class="detail-row">
                            <div class="detail-label">Authorized By:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($expense['TreasurerName']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if(!empty($expense['Description'])): ?>
                        <div class="detail-row">
                            <div class="detail-label">Description:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($expense['Description']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif($welfare['Status'] == 'approved'): ?>
                <div class="welfare-details-section">
                    <div class="section-title">Expense Information</div>
                    <p class="no-expense">No expense record is linked to this approved welfare claim.</p>
                </div>
                <?php endif; ?>
                
                <?php if (!$isPopup): ?>
                <div class="btn-container">
                    <a href="deathWelfare.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Welfare Claims
                    </a>
                    <a href="editWelfare.php?id=<?php echo $welfareID; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Welfare Claim
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
    function viewWelfare(welfareID) {
        // Set the iframe source to your viewWelfare.php page
        document.getElementById('welfareFrame').src = `viewWelfare.php?id=${welfareID}&popup=true`;
        
        // Show the modal
        document.getElementById('welfareModal').style.display = 'block';
    }

    function closeWelfareModal() {
        document.getElementById('welfareModal').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('welfareModal');
        if (event.target == modal) {
            closeWelfareModal();
        }
    };
</script>