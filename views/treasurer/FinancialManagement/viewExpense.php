<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if ID parameter exists
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No expense ID provided";
    if (!$isPopup) {
        header("Location: trackExpenses.php");
        exit();
    }
}

$expenseID = $_GET['id'];

// Function to get expense details
function getExpenseDetails($expenseID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            e.ExpenseID, 
            e.Category, 
            e.Method, 
            e.Amount, 
            e.Date, 
            e.Term, 
            e.Description, 
            e.Image,
            e.Treasurer_TreasurerID,
            t.Name as TreasurerName
        FROM Expenses e
        JOIN Treasurer t ON e.Treasurer_TreasurerID = t.TreasurerID
        WHERE e.ExpenseID = ?
    ");
    
    $stmt->bind_param("s", $expenseID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Get expense details
$expense = getExpenseDetails($expenseID);
if (!$expense) {
    $_SESSION['error_message'] = "Expense not found";
    if (!$isPopup) {
        header("Location: trackExpenses.php");
        exit();
    }
}

// Check if the expense is linked to a Death Welfare record
function getLinkedDeathWelfare($expenseID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            dw.WelfareID,
            dw.Amount,
            dw.Date,
            dw.Term,
            dw.Relationship,
            dw.Status,
            m.MemberID,
            m.Name as MemberName
        FROM DeathWelfare dw
        JOIN Member m ON dw.Member_MemberID = m.MemberID
        WHERE dw.Expense_ExpenseID = ?
    ");
    
    $stmt->bind_param("s", $expenseID);
    $stmt->execute();
    return $stmt->get_result();
}

// Check if the expense is linked to a Loan record
function getLinkedLoan($expenseID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            l.LoanID,
            l.Amount,
            l.Term,
            l.Reason,
            l.Issued_Date,
            l.Due_Date,
            l.Status,
            m.MemberID,
            m.Name as MemberName
        FROM Loan l
        JOIN Member m ON l.Member_MemberID = m.MemberID
        WHERE l.Expenses_ExpenseID = ?
    ");
    
    $stmt->bind_param("s", $expenseID);
    $stmt->execute();
    return $stmt->get_result();
}

// Get linked Death Welfare data if exists
$linkedWelfare = getLinkedDeathWelfare($expenseID);

// Get linked Loan data if exists
$linkedLoan = getLinkedLoan($expenseID);

// Format the status with appropriate class for Death Welfare or Loan
function getStatusClass($status) {
    switch($status) {
        case 'approved': return 'status-approved';
        case 'pending': return 'status-pending';
        case 'rejected': return 'status-rejected';
        default: return 'status-none';
    }
}

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>View Expense</title>
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
            .expense-detail-container {
                background-color: #fff;
                width: 100%;
                margin: 0 auto;
                display: flex;
                flex-direction: column;
                height: calc(100vh - 20px);
            }
            .expense-detail-title {
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
            .expense-details-section {
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
            .welfare-block, .loan-block {
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
            .image-container {
                text-align: center;
                margin-top: 10px;
            }
            .receipt-image {
                max-width: 100%;
                max-height: 300px;
                border: 1px solid #ddd;
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
        <title>View Expense</title>
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
                padding: 20px;
            }
            .expense-detail-container {
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 20px;
                max-width: 1000px;
                margin: 20px auto;
            }
            .expense-detail-title {
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
            .expense-details-section {
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
            .welfare-block, .loan-block {
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
            .image-container {
                text-align: center;
                margin-top: 20px;
            }
            .receipt-image {
                max-width: 100%;
                max-height: 500px;
                border: 1px solid #ddd;
                border-radius: 5px;
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
                    <h1>View Expense</h1>
                    <a href="trackExpenses.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Expenses
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

            <div class="expense-detail-container">
                <h2 class="expense-detail-title">Expense Details #<?php echo htmlspecialchars($expenseID); ?></h2>
                
                <div class="expense-details-section">
                    <div class="top-info-container">
                        <div class="expense-basic-info">
                            <div class="section-title">Expense Information</div>
                            <div class="grid-container">
                                <div class="left-info">
                                    <div class="detail-row">
                                        <div class="detail-label">Expense ID:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($expense['ExpenseID']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Category:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($expense['Category']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Amount:</div>
                                        <div class="detail-value">Rs. <?php echo number_format($expense['Amount'], 2); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Payment Method:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($expense['Method']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Date:</div>
                                        <div class="detail-value"><?php echo date('Y-m-d', strtotime($expense['Date'])); ?></div>
                                    </div>
                                </div>
                                
                                <div class="right-info">
                                    <div class="detail-row">
                                        <div class="detail-label">Term:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($expense['Term']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Treasurer ID:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($expense['Treasurer_TreasurerID']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Treasurer Name:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($expense['TreasurerName']); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="detail-row full-width">
                                <div class="detail-label">Description:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($expense['Description'] ?? 'No description provided'); ?></div>
                            </div>
                            
                            <?php if (!empty($expense['Image'])): ?>
                            <div class="image-container">
                                <div class="section-title">Receipt Image</div>
                                <img src="../../<?php echo $expense['Image']; ?>" alt="Receipt" class="receipt-image">
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php if($linkedWelfare && $linkedWelfare->num_rows > 0): 
                    $welfare = $linkedWelfare->fetch_assoc();
                    $statusClass = getStatusClass($welfare['Status']);
                ?>
                <div class="expense-details-section">
                    <div class="section-title">Linked Death Welfare Information</div>
                    <div class="welfare-block">
                        <div class="detail-row">
                            <div class="detail-label">Welfare ID:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($welfare['WelfareID']); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Member ID:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($welfare['MemberID']); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Member Name:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($welfare['MemberName']); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Amount:</div>
                            <div class="detail-value">Rs. <?php echo number_format($welfare['Amount'], 2); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Date:</div>
                            <div class="detail-value"><?php echo date('Y-m-d', strtotime($welfare['Date'])); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Relationship:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($welfare['Relationship']); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value">
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo ucfirst(htmlspecialchars($welfare['Status'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if($linkedLoan && $linkedLoan->num_rows > 0): 
                    $loan = $linkedLoan->fetch_assoc();
                    $statusClass = getStatusClass($loan['Status']);
                ?>
                <div class="expense-details-section">
                    <div class="section-title">Linked Loan Information</div>
                    <div class="loan-block">
                        <div class="detail-row">
                            <div class="detail-label">Loan ID:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($loan['LoanID']); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Member ID:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($loan['MemberID']); ?></div>
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
                            <div class="detail-label">Term:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($loan['Term']); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Reason:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($loan['Reason']); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Issued Date:</div>
                            <div class="detail-value"><?php echo date('Y-m-d', strtotime($loan['Issued_Date'])); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Due Date:</div>
                            <div class="detail-value"><?php echo date('Y-m-d', strtotime($loan['Due_Date'])); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value">
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo ucfirst(htmlspecialchars($loan['Status'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!$isPopup): ?>
                <div class="btn-container">
                    <a href="trackExpenses.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Expenses
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
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('expenseModal');
        if (event.target == modal) {
            closeExpenseModal();
        }
    };
    
    function closeExpenseModal() {
        window.parent.closeExpenseModal();
    }
</script>