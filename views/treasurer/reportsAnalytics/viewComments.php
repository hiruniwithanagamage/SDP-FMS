<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once "../../../config/database.php";

// Check if user is logged in and is a treasurer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'treasurer') {
    header("Location: ../../../loginProcess.php");
    exit();
}

$userID = $_SESSION['user_id'];
// Get the treasurer ID from the User table
$sql = "SELECT Treasurer_TreasurerID FROM User WHERE UserId = ?";
$stmt = prepare($sql);
$stmt->bind_param("s", $userID);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $treasurerID = $row['Treasurer_TreasurerID'];
} else {
    // If no treasurer ID is found, use the user ID as a fallback
    $treasurerID = $userID;
}

// Get current term from Static table
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE status ='active'";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

$currentTerm = getCurrentTerm();

// Get report year from URL parameter
$reportYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentTerm;
$reportID = isset($_GET['reportID']) ? $_GET['reportID'] : null;
$versionID = isset($_GET['versionID']) ? $_GET['versionID'] : null;

$message = '';
$alertType = '';

// Function to get comments for a specific report
function getReportComments($reportID, $versionID) {
    $sql = "SELECT * FROM ReportComments 
            WHERE ReportID = ? AND VersionID = ? 
            ORDER BY CommentDate DESC";
    $stmt = prepare($sql);
    $stmt->bind_param("ss", $reportID, $versionID);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get the latest report for a specific year
function getLatestReport($year, $treasurerID) {
    $sql = "SELECT * FROM FinancialReportVersions 
            WHERE Term = ? AND Treasurer_TreasurerID = ? 
            ORDER BY VersionID DESC LIMIT 1";
    $stmt = prepare($sql);
    $stmt->bind_param("is", $year, $treasurerID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// If reportID and versionID are not provided, get the latest report for the selected year
if (!$reportID || !$versionID) {
    $latestReport = getLatestReport($reportYear, $treasurerID);
    if ($latestReport) {
        $reportID = $latestReport['ReportID'];
        $versionID = $latestReport['VersionID'];
    }
}

// Search functionality
$searchType = isset($_GET['searchType']) ? $_GET['searchType'] : '';
$searchID = isset($_GET['searchID']) ? $_GET['searchID'] : '';
$searchResult = null;
$canEdit = false;

if ($searchType && $searchID) {
    // Define table and ID column based on search type
    $tableConfig = [
        'loan' => ['table' => 'Loan', 'idColumn' => 'LoanID', 'dateColumn' => 'Issued_Date'],
        'payment' => ['table' => 'Payment', 'idColumn' => 'PaymentID', 'dateColumn' => 'Date'],
        'membershipfee' => ['table' => 'MembershipFee', 'idColumn' => 'FeeID', 'dateColumn' => 'Date'],
        'deathwelfare' => ['table' => 'DeathWelfare', 'idColumn' => 'WelfareID', 'dateColumn' => 'Date'],
        'fine' => ['table' => 'Fine', 'idColumn' => 'FineID', 'dateColumn' => 'Date'],
        'expense' => ['table' => 'Expenses', 'idColumn' => 'ExpenseID', 'dateColumn' => 'Date']
    ];

    if (isset($tableConfig[$searchType])) {
        $config = $tableConfig[$searchType];
        
        // For expenses, add treasurer ID condition
        $whereTreasurerID = '';
        $params = [];
        
        if ($searchType === 'expense') {
            $sql = "SELECT * FROM {$config['table']} WHERE {$config['idColumn']} = ? AND Treasurer_TreasurerID = ?";
            $stmt = prepare($sql);
            $stmt->bind_param("ss", $searchID, $treasurerID);
        } else {
            $sql = "SELECT * FROM {$config['table']} WHERE {$config['idColumn']} = ?";
            $stmt = prepare($sql);
            $stmt->bind_param("s", $searchID);
        }
        
        $stmt->execute();
        $searchResult = $stmt->get_result();
        
        if ($searchResult->num_rows > 0) {
            $record = $searchResult->fetch_assoc();
            
            // Check if the record is from the current term
            if (isset($record[$config['dateColumn']])) {
                $recordYear = date('Y', strtotime($record[$config['dateColumn']]));
                $canEdit = ($recordYear == $currentTerm);
            }
            
            $searchResult = [$record];
        } else {
            $searchResult = [];
        }
    }
}

// Function to get related payment records
function getRelatedPaymentId($recordType, $recordId) {
    switch ($recordType) {
        case 'membershipfee':
            $sql = "SELECT PaymentID FROM MembershipFeePayment WHERE FeeID = ? LIMIT 1";
            break;
        case 'loan':
            $sql = "SELECT PaymentID FROM LoanPayment WHERE LoanID = ? LIMIT 1";
            break;
        case 'fine':
            $sql = "SELECT Payment_PaymentID FROM Fine WHERE FineID = ? LIMIT 1";
            break;
        default:
            return null;
    }
    
    $stmt = prepare($sql);
    $stmt->bind_param("s", $recordId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($recordType === 'fine') {
            return $row['Payment_PaymentID'];
        } else {
            return $row['PaymentID'];
        }
    }
    
    return null;
}

// Function to get related expense ID for death welfare
function getRelatedExpenseId($welfareId) {
    $sql = "SELECT Expense_ExpenseID FROM DeathWelfare WHERE WelfareID = ? LIMIT 1";
    $stmt = prepare($sql);
    $stmt->bind_param("s", $welfareId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['Expense_ExpenseID'];
    }
    
    return null;
}

// Handle record update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_record'])) {
    $updateType = $_POST['update_type'];
    $updateID = $_POST['update_id'];
    
    // Define updatable fields for each type
    $updatableFields = [
        'loan' => ['Amount', 'Reason', 'Status'],
        'payment' => ['Amount', 'Payment_Type', 'Method'],
        'membershipfee' => ['Amount', 'Type', 'IsPaid'],
        'deathwelfare' => ['Amount', 'Relationship', 'Status'],
        'fine' => ['Amount', 'Description', 'IsPaid'],
        'expense' => ['Amount', 'Category', 'Method', 'Description']
    ];
    
    if (isset($updatableFields[$updateType])) {
        $fields = $updatableFields[$updateType];
        $updates = [];
        $params = [];
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $updates[] = "$field = ?";
                $params[] = $_POST[$field];
            }
        }
        
        $success = true;
        $errorMessage = "";
        
        if (!empty($updates)) {
            $tableConfig = [
                'loan' => ['table' => 'Loan', 'idColumn' => 'LoanID'],
                'payment' => ['table' => 'Payment', 'idColumn' => 'PaymentID'],
                'membershipfee' => ['table' => 'MembershipFee', 'idColumn' => 'FeeID'],
                'deathwelfare' => ['table' => 'DeathWelfare', 'idColumn' => 'WelfareID'],
                'fine' => ['table' => 'Fine', 'idColumn' => 'FineID'],
                'expense' => ['table' => 'Expenses', 'idColumn' => 'ExpenseID']
            ];
            
            $config = $tableConfig[$updateType];
            $sql = "UPDATE {$config['table']} SET " . implode(", ", $updates) . " WHERE {$config['idColumn']} = ?";
            
            // For expenses, add treasurer ID condition
            if ($updateType === 'expense') {
                $sql .= " AND Treasurer_TreasurerID = ?";
            }
            
            $stmt = prepare($sql);
            
            // Add the ID parameter to the end of the params array
            $params[] = $updateID;
            
            // Add treasurer ID for expenses
            if ($updateType === 'expense') {
                $params[] = $treasurerID;
            }
            
            // Create type string for bind_param
            $typeString = str_repeat('s', count($params));
            
            // Call bind_param with dynamic parameters
            $bindParams = array_merge([$typeString], $params);
            $bindParamsRef = [];
            
            foreach ($bindParams as $key => $value) {
                $bindParamsRef[$key] = &$bindParams[$key];
            }
            
            call_user_func_array([$stmt, 'bind_param'], $bindParamsRef);
            
            if (!$stmt->execute()) {
                $success = false;
                $errorMessage = "Error updating main record: " . $stmt->error;
            }
            
            // Only continue if the first update was successful
            if ($success && isset($_POST['Amount'])) {
                $newAmount = $_POST['Amount'];
                
                // Update related payment record if applicable
                if (in_array($updateType, ['membershipfee', 'loan', 'fine'])) {
                    $paymentId = getRelatedPaymentId($updateType, $updateID);
                    
                    if ($paymentId) {
                        $paymentSql = "UPDATE Payment SET Amount = ? WHERE PaymentID = ?";
                        $paymentStmt = prepare($paymentSql);
                        $paymentStmt->bind_param("ds", $newAmount, $paymentId);
                        
                        if (!$paymentStmt->execute()) {
                            $success = false;
                            $errorMessage = "Error updating payment record: " . $paymentStmt->error;
                        }
                    }
                }
                
                // Update related expense record for death welfare
                if ($success && $updateType === 'deathwelfare') {
                    $expenseId = getRelatedExpenseId($updateID);
                    
                    if ($expenseId) {
                        $expenseSql = "UPDATE Expenses SET Amount = ? WHERE ExpenseID = ?";
                        $expenseStmt = prepare($expenseSql);
                        $expenseStmt->bind_param("ds", $newAmount, $expenseId);
                        
                        if (!$expenseStmt->execute()) {
                            $success = false;
                            $errorMessage = "Error updating expense record: " . $expenseStmt->error;
                        }
                    }
                }
            }
            
            if ($success) {
                $message = ucfirst($updateType) . " record and related records updated successfully.";
                $alertType = "success";
                
                // Redirect to remove the form submission from URL
                header("Location: viewComments.php?year=$reportYear&reportID=$reportID&versionID=$versionID&searchType=$updateType&searchID=$updateID&success=1");
                exit();
            } else {
                $message = "Error: " . $errorMessage;
                $alertType = "danger";
            }
        }
    }
}

// Get comments for the report
$comments = null;
if ($reportID && $versionID) {
    $comments = getReportComments($reportID, $versionID);
}

// Get report details
$reportDetails = null;
if ($reportID && $versionID) {
    $sql = "SELECT * FROM FinancialReportVersions WHERE ReportID = ? AND VersionID = ?";
    $stmt = prepare($sql);
    $stmt->bind_param("ss", $reportID, $versionID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $reportDetails = $result->fetch_assoc();
    }
}

// Check for success message from URL
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "Record and related records updated successfully.";
    $alertType = "success";
}

// Function to display related records
function displayRelatedRecords($recordType, $recordId) {
    $relatedRecords = [];
    
    // Get related payment details
    if (in_array($recordType, ['membershipfee', 'loan', 'fine'])) {
        $paymentId = getRelatedPaymentId($recordType, $recordId);
        
        if ($paymentId) {
            $sql = "SELECT * FROM Payment WHERE PaymentID = ?";
            $stmt = prepare($sql);
            $stmt->bind_param("s", $paymentId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $relatedRecords['payment'] = $result->fetch_assoc();
            }
        }
    }
    
    // Get related expense details for death welfare
    if ($recordType === 'deathwelfare') {
        $expenseId = getRelatedExpenseId($recordId);
        
        if ($expenseId) {
            $sql = "SELECT * FROM Expenses WHERE ExpenseID = ?";
            $stmt = prepare($sql);
            $stmt->bind_param("s", $expenseId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $relatedRecords['expense'] = $result->fetch_assoc();
            }
        }
    }
    
    return $relatedRecords;
}

// Get related records for the current search
$relatedRecords = [];
if ($searchResult && !empty($searchResult) && $searchType && $searchID) {
    $relatedRecords = displayRelatedRecords($searchType, $searchID);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Comments - Treasurer Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/alert.css">
    <script src="../../../assets/js/alertHandler.js"></script>
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

        .container {
            min-height: 100vh;
            background: #f5f7fa;
            padding: 2rem;
        }

        .content {
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-card {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            margin-top: 35px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.5rem;
            color: #1e3c72;
            margin: 0;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-block;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #1e3c72;
            color: white;
        }

        .btn-primary:hover {
            background: #2a5298;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .comment-list {
            margin-bottom: 2rem;
        }

        .comment-item {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .comment-date {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .comment-text {
            margin-bottom: 0;
        }

        .search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .form-group {
            flex-grow: 1;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }

        table th, table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }

        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 2rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .edit-form {
            margin-top: 2rem;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .form-actions {
            margin-top: 1.5rem;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include '../../templates/navbar-treasurer.php'; ?>

        <div class="content">
            <div class="welcome-card">
                <h1>View Comments & Search Records</h1>
                <div>
                    <a href="financialReports.php?year=<?php echo $reportYear; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Reports
                    </a>
                </div>
            </div>

            <?php if($message): ?>
                <div class="alert alert-<?php echo $alertType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Report Details</h2>
                </div>
                
                <?php if($reportDetails): ?>
                <div style="margin-bottom: 2rem;">
                    <p><strong>Report ID:</strong> <?php echo $reportDetails['ReportID']; ?></p>
                    <p><strong>Version:</strong> <?php echo $reportDetails['VersionID']; ?></p>
                    <p><strong>Term:</strong> <?php echo $reportDetails['Term']; ?></p>
                    <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($reportDetails['Date'])); ?></p>
                    <p><strong>Status:</strong> <?php echo ucfirst($reportDetails['Status']); ?></p>
                    <p><strong>Comments:</strong> <?php echo $reportDetails['Comments'] ?? 'None'; ?></p>
                </div>
                <?php else: ?>
                <p>No report details available.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Auditor Comments</h2>
                </div>
                
                <div class="comment-list">
                    <?php if($comments && $comments->num_rows > 0): ?>
                        <?php while($comment = $comments->fetch_assoc()): ?>
                            <div class="comment-item">
                                <div class="comment-date">
                                    <?php echo date('F j, Y, g:i a', strtotime($comment['CommentDate'])); ?>
                                </div>
                                <p class="comment-text"><?php echo nl2br(htmlspecialchars($comment['Comment'])); ?></p>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p>No comments available for this report.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Search Records</h2>
                </div>
                
                <form action="viewComments.php" method="GET" class="search-form">
                    <input type="hidden" name="year" value="<?php echo $reportYear; ?>">
                    <input type="hidden" name="reportID" value="<?php echo $reportID; ?>">
                    <input type="hidden" name="versionID" value="<?php echo $versionID; ?>">
                    
                    <div class="form-group">
                        <select name="searchType" class="form-control" required>
                            <option value="">Select Record Type</option>
                            <option value="loan" <?php echo ($searchType === 'loan') ? 'selected' : ''; ?>>Loan</option>
                            <option value="payment" <?php echo ($searchType === 'payment') ? 'selected' : ''; ?>>Payment</option>
                            <option value="membershipfee" <?php echo ($searchType === 'membershipfee') ? 'selected' : ''; ?>>Membership Fee</option>
                            <option value="deathwelfare" <?php echo ($searchType === 'deathwelfare') ? 'selected' : ''; ?>>Death Welfare</option>
                            <option value="fine" <?php echo ($searchType === 'fine') ? 'selected' : ''; ?>>Fine</option>
                            <option value="expense" <?php echo ($searchType === 'expense') ? 'selected' : ''; ?>>Expense</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <input type="text" name="searchID" class="form-control" placeholder="Enter ID" value="<?php echo htmlspecialchars($searchID); ?>" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
                
                <?php if($searchResult): ?>
                    <div class="search-results">
                        <h3>Search Results</h3>
                        
                        <?php if(empty($searchResult)): ?>
                            <p>No records found.</p>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <?php foreach(array_keys($searchResult[0]) as $column): ?>
                                            <th><?php echo htmlspecialchars($column); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($searchResult as $record): ?>
                                        <tr>
                                            <?php foreach($record as $value): ?>
                                                <td><?php echo htmlspecialchars($value); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <?php if($canEdit): ?>
                                <div class="edit-form">
                                    <h3>Edit Record</h3>
                                    <form method="POST" action="">
                                        <input type="hidden" name="update_type" value="<?php echo $searchType; ?>">
                                        <input type="hidden" name="update_id" value="<?php echo $searchID; ?>">
                                        
                                        <div class="form-row">
                                            <?php 
                                            $record = $searchResult[0];
                                            
                                            // Define editable fields based on record type
                                            $editableFields = [];
                                            
                                            switch($searchType) {
                                                case 'loan':
                                                    $editableFields = [
                                                        'Amount' => ['type' => 'number', 'step' => '0.01'],
                                                        'Reason' => ['type' => 'text'],
                                                        'Status' => ['type' => 'select', 'options' => ['pending', 'approved', 'rejected']]
                                                    ];
                                                    break;
                                                case 'payment':
                                                    $editableFields = [
                                                        'Amount' => ['type' => 'number', 'step' => '0.01'],
                                                        'Payment_Type' => ['type' => 'text'],
                                                        'Method' => ['type' => 'text']
                                                    ];
                                                    break;
                                                case 'membershipfee':
                                                    $editableFields = [
                                                        'Amount' => ['type' => 'number', 'step' => '0.01'],
                                                        'Type' => ['type' => 'text'],
                                                        'IsPaid' => ['type' => 'select', 'options' => ['Yes', 'No']]
                                                    ];
                                                    break;
                                                case 'deathwelfare':
                                                    $editableFields = [
                                                        'Amount' => ['type' => 'number', 'step' => '0.01'],
                                                        'Relationship' => ['type' => 'text'],
                                                        'Status' => ['type' => 'select', 'options' => ['pending', 'approved', 'rejected']]
                                                    ];
                                                    break;
                                                case 'fine':
                                                    $editableFields = [
                                                        'Amount' => ['type' => 'number', 'step' => '0.01'],
                                                        'Description' => ['type' => 'select', 'options' => ['late', 'absent', 'violation']],
                                                        'IsPaid' => ['type' => 'select', 'options' => ['Yes', 'No']]
                                                    ];
                                                    break;
                                                case 'expense':
                                                    $editableFields = [
                                                        'Amount' => ['type' => 'number', 'step' => '0.01'],
                                                        'Category' => ['type' => 'text'],
                                                        'Method' => ['type' => 'text'],
                                                        'Description' => ['type' => 'text']
                                                    ];
                                                    break;
                                            }
                                            
                                            foreach($editableFields as $field => $config):
                                                if(isset($record[$field])):
                                            ?>
                                                <div class="form-group">
                                                    <label class="form-label"><?php echo htmlspecialchars($field); ?></label>
                                                    
                                                    <?php if($config['type'] === 'select'): ?>
                                                        <select name="<?php echo $field; ?>" class="form-control">
                                                            <?php foreach($config['options'] as $option): ?>
                                                                <option value="<?php echo $option; ?>" <?php echo ($record[$field] == $option) ? 'selected' : ''; ?>>
                                                                    <?php echo ucfirst($option); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php else: ?>
                                                        <input 
                                                            type="<?php echo $config['type']; ?>" 
                                                            name="<?php echo $field; ?>" 
                                                            value="<?php echo htmlspecialchars($record[$field]); ?>" 
                                                            class="form-control"
                                                            <?php if(isset($config['step'])): ?>
                                                                step="<?php echo $config['step']; ?>"
                                                            <?php endif; ?>
                                                        >
                                                    <?php endif; ?>
                                                </div>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                        </div>
                                        
                                        <div class="form-actions">
                                            <button type="submit" name="update_record" class="btn btn-primary">
                                                <i class="fas fa-save"></i> Update Record
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info" style="margin-top: 1rem;">
                                    Note: You can only edit records from the current term (<?php echo $currentTerm; ?>).
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php include '../../templates/footer.php'; ?>
    </div>

    <script>
        // Show success message and hide after 3 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alertSuccessElements = document.querySelectorAll('.alert-success');
            if (alertSuccessElements.length > 0) {
                setTimeout(function() {
                    alertSuccessElements.forEach(function(alert) {
                        alert.style.display = 'none';
                    });
                }, 3000);
            }
        });
    </script>
</body>
</html>