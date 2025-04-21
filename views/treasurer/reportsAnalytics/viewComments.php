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
            
            $searchResult = $record;
        } else {
            $searchResult = null;
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

// Function to log changes to the ChangeLog table
function logChange($recordType, $recordId, $userId, $treasurerId, $oldValues, $newValues, $changeDetails, $reportId = null, $versionId = null) {
    // Convert arrays to JSON strings
    $oldValuesJson = json_encode($oldValues);
    $newValuesJson = json_encode($newValues);
    
    $sql = "INSERT INTO ChangeLog (
                RecordType, 
                RecordID, 
                UserID, 
                TreasurerID, 
                OldValues, 
                NewValues, 
                ChangeDetails, 
                ReportID, 
                VersionID
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = prepare($sql);
    $stmt->bind_param(
        "sssssssss",
        $recordType,
        $recordId,
        $userId,
        $treasurerId,
        $oldValuesJson,
        $newValuesJson,
        $changeDetails,
        $reportId,
        $versionId
    );
    
    return $stmt->execute();
}

// Handle record update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_record'])) {
    $updateType = $_POST['update_type'];
    $updateID = $_POST['update_id'];
    $changeDetails = $_POST['change_details'] ?? '';
    
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
        $oldValues = [];
        $newValues = [];
        
        // Get current record values before update (for change logging)
        $tableConfig = [
            'loan' => ['table' => 'Loan', 'idColumn' => 'LoanID'],
            'payment' => ['table' => 'Payment', 'idColumn' => 'PaymentID'],
            'membershipfee' => ['table' => 'MembershipFee', 'idColumn' => 'FeeID'],
            'deathwelfare' => ['table' => 'DeathWelfare', 'idColumn' => 'WelfareID'],
            'fine' => ['table' => 'Fine', 'idColumn' => 'FineID'],
            'expense' => ['table' => 'Expenses', 'idColumn' => 'ExpenseID']
        ];
        
        $config = $tableConfig[$updateType];
        $oldRecordSql = "SELECT * FROM {$config['table']} WHERE {$config['idColumn']} = ?";
        
        // For expenses, add treasurer ID condition
        if ($updateType === 'expense') {
            $oldRecordSql .= " AND Treasurer_TreasurerID = ?";
            $oldRecordStmt = prepare($oldRecordSql);
            $oldRecordStmt->bind_param("ss", $updateID, $treasurerID);
        } else {
            $oldRecordStmt = prepare($oldRecordSql);
            $oldRecordStmt->bind_param("s", $updateID);
        }
        
        $oldRecordStmt->execute();
        $oldRecordResult = $oldRecordStmt->get_result();
        
        if ($oldRecordResult->num_rows > 0) {
            $oldRecord = $oldRecordResult->fetch_assoc();
            
            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $updates[] = "$field = ?";
                    $newValue = $_POST[$field];
                    $params[] = $newValue;
                    
                    // Store old and new values for change log
                    $oldValues[$field] = $oldRecord[$field];
                    $newValues[$field] = $newValue;
                }
            }
            
            // For some record types, also update the date if provided
            if (isset($_POST['Date']) && in_array($updateType, ['payment', 'membershipfee', 'deathwelfare', 'fine', 'expense'])) {
                $updates[] = "Date = ?";
                $newDate = $_POST['Date'];
                $params[] = $newDate;
                $oldValues['Date'] = $oldRecord['Date'];
                $newValues['Date'] = $newDate;
            }
            
            // For loan, update the issued date if provided
            if (isset($_POST['Issued_Date']) && $updateType === 'loan') {
                $updates[] = "Issued_Date = ?";
                $newDate = $_POST['Issued_Date'];
                $params[] = $newDate;
                $oldValues['Issued_Date'] = $oldRecord['Issued_Date'];
                $newValues['Issued_Date'] = $newDate;
            }
            
            $success = true;
            $errorMessage = "";
            
            if (!empty($updates)) {
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
                
                // Log the changes to main record
                if ($success) {
                    $logSuccess = logChange(
                        $updateType,
                        $updateID,
                        $userID,
                        $treasurerID,
                        $oldValues,
                        $newValues,
                        $changeDetails,
                        $reportID,
                        $versionID
                    );
                    
                    if (!$logSuccess) {
                        // Not critical, just log to error log
                        error_log("Failed to log changes to ChangeLog table for $updateType ID: $updateID");
                    }
                }
                
                // Only continue if the first update was successful
                if ($success && isset($_POST['Amount'])) {
                    $newAmount = $_POST['Amount'];
                    
                    // Update related payment record if applicable
                    if (in_array($updateType, ['membershipfee', 'loan', 'fine'])) {
                        $paymentId = getRelatedPaymentId($updateType, $updateID);
                        
                        if ($paymentId) {
                            // Get old payment record for logging
                            $oldPaymentSql = "SELECT * FROM Payment WHERE PaymentID = ?";
                            $oldPaymentStmt = prepare($oldPaymentSql);
                            $oldPaymentStmt->bind_param("s", $paymentId);
                            $oldPaymentStmt->execute();
                            $oldPaymentResult = $oldPaymentStmt->get_result();
                            $oldPaymentRecord = $oldPaymentResult->fetch_assoc();
                            
                            $paymentSql = "UPDATE Payment SET Amount = ? WHERE PaymentID = ?";
                            $paymentStmt = prepare($paymentSql);
                            $paymentStmt->bind_param("ds", $newAmount, $paymentId);
                            
                            if (!$paymentStmt->execute()) {
                                $success = false;
                                $errorMessage = "Error updating payment record: " . $paymentStmt->error;
                            } else {
                                // Log the changes to related payment record
                                $paymentOldValues = ['Amount' => $oldPaymentRecord['Amount']];
                                $paymentNewValues = ['Amount' => $newAmount];
                                
                                logChange(
                                    'payment',
                                    $paymentId,
                                    $userID,
                                    $treasurerID,
                                    $paymentOldValues,
                                    $paymentNewValues,
                                    "Related payment update for $updateType ID: $updateID - " . $changeDetails,
                                    $reportID,
                                    $versionID
                                );
                            }
                            
                            // Update the date in Payment table if provided and this is a membership fee or fine
                            if ($success && isset($_POST['Date']) && in_array($updateType, ['membershipfee', 'fine'])) {
                                $newDate = $_POST['Date'];
                                $dateSql = "UPDATE Payment SET Date = ? WHERE PaymentID = ?";
                                $dateStmt = prepare($dateSql);
                                $dateStmt->bind_param("ss", $newDate, $paymentId);
                                
                                if (!$dateStmt->execute()) {
                                    $success = false;
                                    $errorMessage = "Error updating payment date: " . $dateStmt->error;
                                } else {
                                    // Log the date change
                                    $datePmentOldValues = ['Date' => $oldPaymentRecord['Date']];
                                    $datePaymentNewValues = ['Date' => $newDate];
                                    
                                    logChange(
                                        'payment',
                                        $paymentId,
                                        $userID,
                                        $treasurerID,
                                        $datePmentOldValues,
                                        $datePaymentNewValues,
                                        "Date update for related payment of $updateType ID: $updateID - " . $changeDetails,
                                        $reportID,
                                        $versionID
                                    );
                                }
                            }
                        }
                    }
                    
                    // Update related expense record for death welfare
                    if ($success && $updateType === 'deathwelfare') {
                        $expenseId = getRelatedExpenseId($updateID);
                        
                        if ($expenseId) {
                            // Get old expense record for logging
                            $oldExpenseSql = "SELECT * FROM Expenses WHERE ExpenseID = ?";
                            $oldExpenseStmt = prepare($oldExpenseSql);
                            $oldExpenseStmt->bind_param("s", $expenseId);
                            $oldExpenseStmt->execute();
                            $oldExpenseResult = $oldExpenseStmt->get_result();
                            $oldExpenseRecord = $oldExpenseResult->fetch_assoc();
                            
                            $expenseSql = "UPDATE Expenses SET Amount = ? WHERE ExpenseID = ?";
                            $expenseStmt = prepare($expenseSql);
                            $expenseStmt->bind_param("ds", $newAmount, $expenseId);
                            
                            if (!$expenseStmt->execute()) {
                                $success = false;
                                $errorMessage = "Error updating expense record: " . $expenseStmt->error;
                            } else {
                                // Log the changes to related expense record
                                $expenseOldValues = ['Amount' => $oldExpenseRecord['Amount']];
                                $expenseNewValues = ['Amount' => $newAmount];
                                
                                logChange(
                                    'expense',
                                    $expenseId,
                                    $userID,
                                    $treasurerID,
                                    $expenseOldValues,
                                    $expenseNewValues,
                                    "Related expense update for $updateType ID: $updateID - " . $changeDetails,
                                    $reportID,
                                    $versionID
                                );
                            }
                            
                            // Update the date in Expenses table if provided
                            if ($success && isset($_POST['Date'])) {
                                $newDate = $_POST['Date'];
                                $dateSql = "UPDATE Expenses SET Date = ? WHERE ExpenseID = ?";
                                $dateStmt = prepare($dateSql);
                                $dateStmt->bind_param("ss", $newDate, $expenseId);
                                
                                if (!$dateStmt->execute()) {
                                    $success = false;
                                    $errorMessage = "Error updating expense date: " . $dateStmt->error;
                                } else {
                                    // Log the date change
                                    $dateExpenseOldValues = ['Date' => $oldExpenseRecord['Date']];
                                    $dateExpenseNewValues = ['Date' => $newDate];
                                    
                                    logChange(
                                        'expense',
                                        $expenseId,
                                        $userID,
                                        $treasurerID,
                                        $dateExpenseOldValues,
                                        $dateExpenseNewValues,
                                        "Date update for related expense of $updateType ID: $updateID - " . $changeDetails,
                                        $reportID,
                                        $versionID
                                    );
                                }
                            }
                        }
                    }
                }
                
                if ($success) {
                    $message = ucfirst($updateType) . " record and related records updated successfully.";
                    $alertType = "success";
                    
                    // Redirect to remove the form submission from URL
                    header("Location: viewComments.php?year=$reportYear&reportID=$reportID&versionID=$versionID&searchType=$searchType&searchID=$searchID&success=1");
                    exit();
                } else {
                    $message = "Error: " . $errorMessage;
                    $alertType = "danger";
                }
            }
        } else {
            $message = "Error: Record not found for updating.";
            $alertType = "danger";
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

// Function to get human-readable title for each record type
function getRecordTypeTitle($type) {
    $titles = [
        'loan' => 'Loan',
        'payment' => 'Payment',
        'membershipfee' => 'Membership Fee',
        'deathwelfare' => 'Death Welfare',
        'fine' => 'Fine',
        'expense' => 'Expense'
    ];
    
    return $titles[$type] ?? ucfirst($type);
}

// Function to display related records
function getRelatedRecords($recordType, $recordId) {
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
if ($searchResult && $searchType && $searchID) {
    $relatedRecords = getRelatedRecords($searchType, $searchID);
}

// Function to get recent changes for a specific record
function getRecentChanges($recordType, $recordId, $limit = 5) {
    $sql = "SELECT * FROM ChangeLog 
            WHERE RecordType = ? AND RecordID = ? 
            ORDER BY ChangeDate DESC LIMIT ?";
    $stmt = prepare($sql);
    $stmt->bind_param("ssi", $recordType, $recordId, $limit);
    $stmt->execute();
    return $stmt->get_result();
}

// Get recent changes for the searched record
$recentChanges = null;
if ($searchResult && $searchType && $searchID) {
    $recentChanges = getRecentChanges($searchType, $searchID);
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

        /* Dashboard layout */
        .dashboard-layout {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .report-details-card, .comments-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .report-details-card {
            flex: 1;
            min-width: 300px;
        }

        .comments-card {
            flex: 1.5;
            min-width: 400px;
        }

        .guidance-message {
            background-color: #e7f3ff;
            border-left: 4px solid #1e88e5;
            padding: 0.8rem 1rem;
            margin-top: 1rem;
            margin-bottom: 2rem;
            border-radius: 4px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .guidance-message i {
            color: #1e88e5;
            font-size: 1.2rem;
        }

        .action-link {
            color: #1e3c72;
            font-weight: 600;
            text-decoration: none;
            position: relative;
            transition: all 0.2s ease;
        }

        .action-link:hover {
            color: #2a5298;
            text-decoration: underline;
        }

        .action-link:after {
            content: " →";
            opacity: 0;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }

        .action-link:hover:after {
            opacity: 1;
            transform: translateX(3px);
        }

        .report-info {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-badge.pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-badge.approved {
            background-color: #d4edda;
            color: #155724;
        }

        .status-badge.rejected {
            background-color: #f8d7da;
            color: #721c24;
        }

        .comment-item {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid #1e3c72;
        }

        .comment-header {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.8rem;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .comment-date, .comment-time {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .no-comments {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem;
            color: #6c757d;
        }

        .no-comments i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Modal styles */
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
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            width: 80%;
            max-width: 700px;
            animation: slideDown 0.3s;
            position: relative;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            right: 20px;
            top: 15px;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .modal h2 {
            margin-top: 0;
            margin-bottom: 1.5rem;
            color: #1e3c72;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }

        .modal-form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1rem;
        }

        .modal-form-group {
            flex: 1;
            min-width: 200px;
            margin-bottom: 1rem;
        }

        .modal-form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }

        .modal-form-control {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .modal-form-control:focus {
            border-color: #1e3c72;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(30, 60, 114, 0.25);
        }

        textarea.modal-form-control {
            min-height: 100px;
            resize: vertical;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }

        .cancel-btn {
            padding: 0.8rem 1.5rem;
            background-color: #f8f9fa;
            color: #6c757d;
            border: 1px solid #ddd;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .cancel-btn:hover {
            background-color: #e2e6ea;
        }

        .save-btn {
            padding: 0.8rem 1.5rem;
            background-color: #1e3c72;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .save-btn:hover {
            background-color: #2a5298;
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .search-result-message {
            margin-top: 2rem;
            padding: 1.5rem;
            background-color: #f8f9fa;
            border-radius: 5px;
            text-align: center;
            color: #6c757d;
        }
        
        /* Change history section */
        .change-history {
            margin-top: 2rem;
            border-top: 1px solid #e0e0e0;
            padding-top: 1.5rem;
        }
        
        .change-history h3 {
            color: #1e3c72;
            margin-bottom: 1rem;
        }
        
        .change-item {
            background-color: #f8f9fa;
            border-left: 3px solid #6c757d;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0 5px 5px 0;
        }
        
        .change-item:hover {
            background-color: #f1f3f5;
        }
        
        .change-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .change-details {
            margin-bottom: 0.8rem;
        }
        
        .change-values {
            font-size: 0.9rem;
            background-color: #fff;
            border: 1px solid #e0e0e0;
            padding: 0.8rem;
            border-radius: 5px;
        }
        
        .change-value-item {
            display: flex;
            margin-bottom: 0.3rem;
        }
        
        .changed-field {
            font-weight: 600;
            width: 150px;
        }
        
        .old-value {
            color: #dc3545;
            text-decoration: line-through;
            margin-right: 1rem;
        }
        
        .new-value {
            color: #28a745;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .dashboard-layout {
                flex-direction: column;
            }
            
            .report-details-card, .comments-card {
                width: 100%;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
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

            <div class="dashboard-layout">
                <div class="report-details-card">
                    <div class="card-header">
                        <h2 class="card-title">Report Details</h2>
                    </div>
                    
                    <?php if($reportDetails): ?>
                    <div class="report-info">
                        <p><strong>Report ID:</strong> <?php echo $reportDetails['ReportID']; ?></p>
                        <p><strong>Version:</strong> <?php echo $reportDetails['VersionID']; ?></p>
                        <p><strong>Term:</strong> <?php echo $reportDetails['Term']; ?></p>
                        <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($reportDetails['Date'])); ?></p>
                        <p><strong>Status:</strong> <span class="status-badge <?php echo strtolower($reportDetails['Status']); ?>"><?php echo ucfirst($reportDetails['Status']); ?></span></p>
                        <p><strong>Comments:</strong> <?php echo $reportDetails['Comments'] ?? 'None'; ?></p>
                    </div>
                    <?php else: ?>
                    <p>No report details available.</p>
                    <?php endif; ?>
                </div>

                <div class="comments-card">
                    <div class="card-header">
                        <h2 class="card-title">Auditor Comments</h2>
                    </div>

                    <div class="guidance-message">
                        <i class="fas fa-info-circle"></i> 
                        To make changes based on comments, <a href="#search-section" class="action-link">use the search tool below</a> to find and edit relevant records.
                    </div>
                    
                    <div class="comment-list">
                        <?php if($comments && $comments->num_rows > 0): ?>
                            <?php while($comment = $comments->fetch_assoc()): ?>
                                <div class="comment-item">
                                    <div class="comment-header">
                                        <span class="comment-date">
                                            <i class="far fa-calendar-alt"></i> <?php echo date('F j, Y', strtotime($comment['CommentDate'])); ?>
                                        </span>
                                        <span class="comment-time">
                                            <i class="far fa-clock"></i> <?php echo date('g:i a', strtotime($comment['CommentDate'])); ?>
                                        </span>
                                    </div>
                                    <p class="comment-text"><?php echo nl2br(htmlspecialchars($comment['Comment'])); ?></p>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="no-comments">
                                <i class="far fa-comment-dots"></i>
                                <p>No comments available for this report.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="search-section" class="card">
                <div class="card-header">
                    <h2 class="card-title">Search Records</h2>
                </div>
                
                <form action="viewComments.php#search-section" method="GET" class="search-form">
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
                    <button type="button" class="btn btn-secondary" onclick="clearSearch()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </form>
                
                <?php if($searchType && $searchID): ?>
                    <?php if($searchResult): ?>
                        <div class="search-result-message">
                            <h3><?php echo getRecordTypeTitle($searchType); ?> with ID: <?php echo htmlspecialchars($searchID); ?> found</h3>
                            <?php if($canEdit): ?>
                                <p>This record is from the current term (<?php echo $currentTerm; ?>) and can be edited.</p>
                                <button type="button" class="btn btn-primary" onclick="openEditModal()">
                                    <i class="fas fa-edit"></i> Edit Record
                                </button>
                            <?php else: ?>
                                <p>This record is from a previous term and cannot be edited.</p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($recentChanges && $recentChanges->num_rows > 0): ?>
                        <div class="change-history">
                            <h3>Recent Changes</h3>
                            <?php while ($change = $recentChanges->fetch_assoc()): ?>
                                <?php 
                                    $oldValues = json_decode($change['OldValues'], true);
                                    $newValues = json_decode($change['NewValues'], true);
                                ?>
                                <div class="change-item">
                                    <div class="change-header">
                                        <span>
                                            <i class="fas fa-history"></i> 
                                            <?php echo date('F j, Y g:i a', strtotime($change['ChangeDate'])); ?>
                                        </span>
                                        <span>
                                            <i class="fas fa-user"></i> Treasurer ID: <?php echo $change['TreasurerID']; ?>
                                        </span>
                                    </div>
                                    <div class="change-details">
                                        <?php echo htmlspecialchars($change['ChangeDetails']); ?>
                                    </div>
                                    <div class="change-values">
                                        <?php foreach ($oldValues as $field => $oldValue): ?>
                                            <?php if (isset($newValues[$field]) && $oldValue != $newValues[$field]): ?>
                                                <div class="change-value-item">
                                                    <span class="changed-field"><?php echo $field; ?>:</span>
                                                    <span class="old-value"><?php echo htmlspecialchars($oldValue); ?></span>
                                                    <span class="new-value"><?php echo htmlspecialchars($newValues[$field]); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="search-result-message">
                            <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: #dc3545; margin-bottom: 1rem;"></i>
                            <h3>No record found</h3>
                            <p>No <?php echo getRecordTypeTitle($searchType); ?> with ID: <?php echo htmlspecialchars($searchID); ?> was found.</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php include '../../templates/footer.php'; ?>
    </div>

    <!-- Edit Modal -->
    <?php if($searchResult && $searchType): ?>
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Edit <?php echo getRecordTypeTitle($searchType); ?> Details</h2>
            <form id="editForm" method="POST">
                <input type="hidden" name="update_type" value="<?php echo $searchType; ?>">
                <input type="hidden" name="update_id" value="<?php echo $searchID; ?>">
                
                <?php 
                // Define fields based on record type
                $fields = [];
                $dateField = '';
                
                switch($searchType) {
                    case 'loan':
                        $fields = [
                            'Amount' => ['type' => 'number', 'step' => '0.01', 'label' => 'Amount (Rs.)', 'value' => $searchResult['Amount'] ?? ''],
                            'Reason' => ['type' => 'text', 'label' => 'Reason', 'value' => $searchResult['Reason'] ?? ''],
                            'Status' => ['type' => 'select', 'label' => 'Status', 'value' => $searchResult['Status'] ?? '', 'options' => [
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected'
                            ]]
                        ];
                        $dateField = 'Issued_Date';
                        $dateValue = $searchResult['Issued_Date'] ?? '';
                        $dateLabel = 'Issued Date';
                        break;
                        
                    case 'payment':
                        $fields = [
                            'Amount' => ['type' => 'number', 'step' => '0.01', 'label' => 'Amount (Rs.)', 'value' => $searchResult['Amount'] ?? ''],
                            'Payment_Type' => ['type' => 'text', 'label' => 'Payment Type', 'value' => $searchResult['Payment_Type'] ?? ''],
                            'Method' => ['type' => 'text', 'label' => 'Method', 'value' => $searchResult['Method'] ?? '']
                        ];
                        $dateField = 'Date';
                        $dateValue = $searchResult['Date'] ?? '';
                        $dateLabel = 'Payment Date';
                        break;
                        
                    case 'membershipfee':
                        $fields = [
                            'Amount' => ['type' => 'number', 'step' => '0.01', 'label' => 'Amount (Rs.)', 'value' => $searchResult['Amount'] ?? ''],
                            'Type' => ['type' => 'text', 'label' => 'Fee Type', 'value' => $searchResult['Type'] ?? ''],
                            'IsPaid' => ['type' => 'select', 'label' => 'Payment Status', 'value' => $searchResult['IsPaid'] ?? '', 'options' => [
                                'Yes' => 'Paid',
                                'No' => 'Unpaid'
                            ]]
                        ];
                        $dateField = 'Date';
                        $dateValue = $searchResult['Date'] ?? '';
                        $dateLabel = 'Date';
                        break;
                        
                    case 'deathwelfare':
                        $fields = [
                            'Amount' => ['type' => 'number', 'step' => '0.01', 'label' => 'Amount (Rs.)', 'value' => $searchResult['Amount'] ?? ''],
                            'Relationship' => ['type' => 'text', 'label' => 'Relationship', 'value' => $searchResult['Relationship'] ?? ''],
                            'Status' => ['type' => 'select', 'label' => 'Status', 'value' => $searchResult['Status'] ?? '', 'options' => [
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected'
                            ]]
                        ];
                        $dateField = 'Date';
                        $dateValue = $searchResult['Date'] ?? '';
                        $dateLabel = 'Date';
                        break;
                        
                    case 'fine':
                        $fields = [
                            'Amount' => ['type' => 'number', 'step' => '0.01', 'label' => 'Amount (Rs.)', 'value' => $searchResult['Amount'] ?? ''],
                            'Description' => ['type' => 'select', 'label' => 'Description', 'value' => $searchResult['Description'] ?? '', 'options' => [
                                'late' => 'Late',
                                'absent' => 'Absent',
                                'violation' => 'Violation'
                            ]],
                            'IsPaid' => ['type' => 'select', 'label' => 'Payment Status', 'value' => $searchResult['IsPaid'] ?? '', 'options' => [
                                'Yes' => 'Paid',
                                'No' => 'Unpaid'
                            ]]
                        ];
                        $dateField = 'Date';
                        $dateValue = $searchResult['Date'] ?? '';
                        $dateLabel = 'Date';
                        break;
                        
                    case 'expense':
                        $fields = [
                            'Amount' => ['type' => 'number', 'step' => '0.01', 'label' => 'Amount (Rs.)', 'value' => $searchResult['Amount'] ?? ''],
                            'Category' => ['type' => 'text', 'label' => 'Category', 'value' => $searchResult['Category'] ?? ''],
                            'Method' => ['type' => 'text', 'label' => 'Method', 'value' => $searchResult['Method'] ?? ''],
                            'Description' => ['type' => 'text', 'label' => 'Description', 'value' => $searchResult['Description'] ?? '']
                        ];
                        $dateField = 'Date';
                        $dateValue = $searchResult['Date'] ?? '';
                        $dateLabel = 'Date';
                        break;
                }
                
                // Output form fields in two columns
                echo '<div class="modal-form-row">';
                $count = 0;
                foreach($fields as $fieldName => $fieldConfig) {
                    if($count > 0 && $count % 2 == 0) {
                        echo '</div><div class="modal-form-row">';
                    }
                    ?>
                    <div class="modal-form-group">
                        <label for="edit_<?php echo strtolower($fieldName); ?>" class="modal-form-label"><?php echo $fieldConfig['label']; ?></label>
                        
                        <?php if($fieldConfig['type'] === 'select'): ?>
                            <select id="edit_<?php echo strtolower($fieldName); ?>" name="<?php echo $fieldName; ?>" class="modal-form-control" required <?php echo !$canEdit ? 'disabled' : ''; ?>>
                                <?php foreach($fieldConfig['options'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($fieldConfig['value'] == $value) ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input 
                                type="<?php echo $fieldConfig['type']; ?>" 
                                id="edit_<?php echo strtolower($fieldName); ?>" 
                                name="<?php echo $fieldName; ?>" 
                                value="<?php echo htmlspecialchars($fieldConfig['value']); ?>" 
                                class="modal-form-control"
                                <?php if(isset($fieldConfig['step'])): ?>
                                    step="<?php echo $fieldConfig['step']; ?>"
                                <?php endif; ?>
                                required
                                <?php echo !$canEdit ? 'disabled' : ''; ?>
                            >
                        <?php endif; ?>
                    </div>
                    <?php
                    $count++;
                }
                echo '</div>';
                
                // Add date field if applicable
                if($dateField && $dateValue) {
                    ?>
                    <div class="modal-form-row">
                        <div class="modal-form-group">
                            <label for="edit_date" class="modal-form-label"><?php echo $dateLabel; ?></label>
                            <input 
                                type="date" 
                                id="edit_date" 
                                name="<?php echo $dateField; ?>" 
                                value="<?php echo date('Y-m-d', strtotime($dateValue)); ?>" 
                                class="modal-form-control"
                                required
                                <?php echo !$canEdit ? 'disabled' : ''; ?>
                            >
                        </div>
                    </div>
                    <?php
                }
                ?>
                
                <div class="modal-form-group">
                    <label for="edit_details" class="modal-form-label">Change Details/Notes (Required)</label>
                    <textarea 
                        id="edit_details" 
                        name="change_details" 
                        class="modal-form-control" 
                        placeholder="Please provide a reason for this change..." 
                        required
                        <?php echo !$canEdit ? 'disabled' : ''; ?>
                    ></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                    <?php if($canEdit): ?>
                        <button type="submit" name="update_record" class="save-btn">Save Changes</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Show success message and hide after 3 seconds
        // document.addEventListener('DOMContentLoaded', function() {
        //     const alertSuccessElements = document.querySelectorAll('.alert-success');
        //     if (alertSuccessElements.length > 0) {
        //         setTimeout(function() {
        //             alertSuccessElements.forEach(function(alert) {
        //                 alert.style.display = 'none';
        //             });
        //         }, 3000);
        //     }
        // });

        // Clear search function
        function clearSearch() {
            // Clear the form fields
            document.querySelector('select[name="searchType"]').value = '';
            document.querySelector('input[name="searchID"]').value = '';
            
            // Hide any existing search results
            const searchResults = document.querySelector('.search-result-message');
            if (searchResults) {
                searchResults.style.display = 'none';
            }
            
            // Hide any change history section
            const changeHistory = document.querySelector('.change-history');
            if (changeHistory) {
                changeHistory.style.display = 'none';
            }
        }
        
        // Modal functionality
        var modal = document.getElementById('editModal');
        
        function openEditModal() {
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden'; // Prevent scrolling when modal is open
            }
        }
        
        function closeModal() {
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto'; // Re-enable scrolling
            }
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target == modal) {
                closeModal();
            }
        }
        
        // Form validation enhancement
        document.getElementById('editForm')?.addEventListener('submit', function(e) {
            var detailsField = document.getElementById('edit_details');
            if (detailsField && detailsField.value.trim().length < 10) {
                e.preventDefault();
                alert('Please provide a detailed reason for the change (at least 10 characters).');
                detailsField.focus();
            }
        });
        
        <?php if($searchResult && $searchType && isset($_GET['open_modal']) && $_GET['open_modal'] == 1): ?>
        // Auto-open modal if requested via URL
        document.addEventListener('DOMContentLoaded', function() {
            openEditModal();
        });
        <?php endif; ?>
    </script>
</body>
</html>