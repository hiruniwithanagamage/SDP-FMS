<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once "../../config/database.php";

// Check if user is logged in and is an auditor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'auditor') {
    header("Location: ../../loginProcess.php");
    exit();
}

$userID = $_SESSION['user_id'];

// Get selected type from URL parameter
$selectedType = isset($_GET['type']) ? $_GET['type'] : 'loans';

// Search parameters
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';
$searchBy = isset($_GET['searchBy']) ? $_GET['searchBy'] : 'id';

// Pagination parameters
$itemsPerPage = 10; // Number of items to display per page
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// Function to get pending reports
function getPendingReports() {
    $sql = "SELECT ReportID, VersionID, Status, Term FROM FinancialReportVersions 
            WHERE Status = 'pending'
            ORDER BY Date DESC";
    $result = search($sql);
    $reports = [];
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
    return $reports;
}

// Function to get current term from Static table
function getCurrentTerm() {
    $sql = "SELECT year FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get all pending reports and select the first one
$pendingReports = getPendingReports();
$currentReport = !empty($pendingReports) ? $pendingReports[0] : null;
$reportId = $currentReport ? $currentReport['ReportID'] : "REP" . getCurrentTerm();
$versionId = $currentReport ? $currentReport['VersionID'] : "V1";
$selectedTerm = $currentReport ? $currentReport['Term'] : getCurrentTerm();
$reportStatus = $currentReport ? $currentReport['Status'] : 'reviewed';

// Extract term from report ID helper function
function extractTermFromReportId($reportId) {
    return (int)substr($reportId, 3); // Extracts numbers after "REP"
}

// Add comment to the report
if (isset($_POST['addComment'])) {
    $itemId = $_POST['itemId'];
    $itemType = $_POST['itemType'];
    $comment = $_POST['comment'];
    $reportId = $_POST['reportId'];
    $versionId = $_POST['versionId'];
    
    // Create a formatted comment that includes the item information
    $formattedComment = "[" . strtoupper($itemType) . " ID: " . $itemId . "] " . $comment;
    
    $sql = "INSERT INTO ReportComments (ReportID, VersionID, Comment) VALUES (?, ?, ?)";
    $stmt = prepare($sql);
    $stmt->bind_param("sss", $reportId, $versionId, $formattedComment);
    
    if ($stmt->execute()) {
        $message = "Comment added successfully";
    } else {
        $message = "Error adding comment: " . $stmt->error;
    }
    
    // Redirect to avoid resubmission
    header("Location: reviewDetails.php?type=$itemType&message=$message");
    exit();
}

// to check for new transactions after report date
function checkForNewTransactions($reportId, $versionId, $selectedTerm) {
    // get the creation date of the current report version
    $getReportDateSql = "SELECT Date FROM FinancialReportVersions 
                         WHERE ReportID = ? AND VersionID = ?";
    $getReportDateStmt = prepare($getReportDateSql);
    $getReportDateStmt->bind_param("ss", $reportId, $versionId);
    $getReportDateStmt->execute();
    $reportDateResult = $getReportDateStmt->get_result();
    
    if ($reportDateResult->num_rows > 0) {
        $reportDateRow = $reportDateResult->fetch_assoc();
        $reportDate = $reportDateRow['Date'];
        
        // Check for new loans after report date
        $newLoansCount = checkNewRecords("Loan", "Issued_Date", $reportDate, $selectedTerm);
        
        // Check for new payments after report date
        $newPaymentsCount = checkNewRecords("Payment", "Date", $reportDate, $selectedTerm);
        
        // Check for new membership fees after report date
        $newFeesCount = checkNewRecords("MembershipFee", "Date", $reportDate, $selectedTerm);
        
        // Check for new fines after report date
        $newFinesCount = checkNewRecords("Fine", "Date", $reportDate, $selectedTerm);
        
        // Check for new welfare payments after report date
        $newWelfareCount = checkNewRecords("DeathWelfare", "Date", $reportDate, $selectedTerm);
        
        // Check for new expenses after report date
        $newExpensesCount = checkNewRecords("Expenses", "Date", $reportDate, $selectedTerm);
        
        // Total new transactions
        $totalNewTransactions = $newLoansCount + $newPaymentsCount + $newFeesCount + 
                               $newFinesCount + $newWelfareCount + $newExpensesCount;
        
        return [
            'hasNewTransactions' => $totalNewTransactions > 0,
            'totalNewTransactions' => $totalNewTransactions,
            'details' => [
                'loans' => $newLoansCount,
                'payments' => $newPaymentsCount,
                'fees' => $newFeesCount,
                'fines' => $newFinesCount,
                'welfare' => $newWelfareCount,
                'expenses' => $newExpensesCount
            ]
        ];
    }
    
    return ['hasNewTransactions' => false];
}

// Helper function to check for new records in a specific table
function checkNewRecords($tableName, $dateColumn, $reportDate, $term) {
    $sql = "SELECT COUNT(*) as count FROM $tableName 
            WHERE $dateColumn > ? AND Term = ?";
    $stmt = prepare($sql);
    $stmt->bind_param("si", $reportDate, $term);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

// Modify the updateReportStatus section to check for new transactions
if (isset($_POST['updateReportStatus'])) {
    $decision = $_POST['decision'];
    $reportId = $_POST['reportId'];
    $versionId = $_POST['versionId'];
    
    // Check for new transactions if approving the report
    if ($decision === 'approve') {
        $transactionCheck = checkForNewTransactions($reportId, $versionId, $selectedTerm);
        
        if ($transactionCheck['hasNewTransactions']) {
            // If there are new transactions, change the decision to "changes" (ongoing)
            $decision = 'changes';
            
            // Add an automated comment about new transactions
            $autoComment = "There are {$transactionCheck['totalNewTransactions']} new transactions since this report was created. ";
            $autoComment .= "Please request the treasurer to submit a new report with the updated data. ";
            $autoComment .= "Details: ";
            
            if ($transactionCheck['details']['loans'] > 0) {
                $autoComment .= "{$transactionCheck['details']['loans']} new loans, ";
            }
            if ($transactionCheck['details']['payments'] > 0) {
                $autoComment .= "{$transactionCheck['details']['payments']} new payments, ";
            }
            if ($transactionCheck['details']['fees'] > 0) {
                $autoComment .= "{$transactionCheck['details']['fees']} new membership fees, ";
            }
            if ($transactionCheck['details']['fines'] > 0) {
                $autoComment .= "{$transactionCheck['details']['fines']} new fines, ";
            }
            if ($transactionCheck['details']['welfare'] > 0) {
                $autoComment .= "{$transactionCheck['details']['welfare']} new welfare payments, ";
            }
            if ($transactionCheck['details']['expenses'] > 0) {
                $autoComment .= "{$transactionCheck['details']['expenses']} new expenses, ";
            }
            
            $autoComment = rtrim($autoComment, ", ") . ".";
            
            // Insert the automated comment
            $insertCommentSql = "INSERT INTO ReportComments (ReportID, VersionID, Comment) VALUES (?, ?, ?)";
            $insertCommentStmt = prepare($insertCommentSql);
            $insertCommentStmt->bind_param("sss", $reportId, $versionId, $autoComment);
            $insertCommentStmt->execute();
            
            // Set a flag to show a special message in the redirect
            $newTransactionsFlag = true;
        }
    }
    
    $status = ($decision === 'approve') ? 'approved' : 'reviewed';
    
    // First get the auditor ID associated with this user
    $getAuditorSql = "SELECT Auditor_AuditorID FROM User WHERE UserId = ?";
    $getAuditorStmt = prepare($getAuditorSql);
    $getAuditorStmt->bind_param("s", $userID);
    $getAuditorStmt->execute();
    $getAuditorResult = $getAuditorStmt->get_result();
    
    if ($getAuditorResult->num_rows > 0) {
        $auditorRow = $getAuditorResult->fetch_assoc();
        $auditorID = $auditorRow['Auditor_AuditorID'];
        
        // update with the correct auditorID
        $sql = "UPDATE FinancialReportVersions SET Status = ?, Auditor_AuditorID = ? WHERE ReportID = ? AND VersionID = ?";
        $stmt = prepare($sql);
        $stmt->bind_param("ssss", $status, $auditorID, $reportId, $versionId);
        
        if ($stmt->execute()) {
            // If the report is approved, update previous ongoing versions to reviewed
            if ($status === 'approved') {
                $message = "Report approved successfully";
            } else {
                if (isset($newTransactionsFlag) && $newTransactionsFlag) {
                    $message = "New transactions detected. Report sent back for updates.";
                } else {
                    $message = "Report sent back for changes";
                }
            }
        } else {
            $message = "Error updating report status: " . $stmt->error;
        }
    } else {
        $message = "Error: Could not find auditor information for this user";
    }
    
    // Redirect to avoid resubmission
    header("Location: reviewDetails.php?type=$selectedType&message=$message");
    exit();
}

// Function to get report comments
function getReportComments($reportId, $versionId) {
    $sql = "SELECT * FROM ReportComments 
            WHERE ReportID = ? AND VersionID = ?
            ORDER BY CommentDate DESC";
    $stmt = prepare($sql);
    $stmt->bind_param("ss", $reportId, $versionId);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get all membership fee details with search and pagination
function getMembershipFeeDetails($term, $searchQuery = '', $searchBy = 'id', $itemsPerPage = 10, $offset = 0) {
    $sql = "SELECT f.*, m.Name as MemberName, m.MemberID,
            CASE WHEN f.Type = 'registration' THEN 'Registration Fee' ELSE 'Monthly Fee' END as FeeType
            FROM MembershipFee f
            JOIN Member m ON f.Member_MemberID = m.MemberID
            WHERE f.Term = ?";
    
    // Add search conditions
    if (!empty($searchQuery)) {
        if ($searchBy === 'id') {
            $sql .= " AND f.FeeID LIKE ?";
            $searchParam = "%$searchQuery%";
        } else if ($searchBy === 'name') {
            $sql .= " AND m.Name LIKE ?";
            $searchParam = "%$searchQuery%";
        }
    }
    
    $sql .= " ORDER BY f.Date DESC";
    
    // Count total records before adding LIMIT
    $countSql = $sql;
    $countStmt = prepare($countSql);
    
    if (!empty($searchQuery)) {
        $countStmt->bind_param("is", $term, $searchParam);
    } else {
        $countStmt->bind_param("i", $term);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult->num_rows;
    
    // Add pagination LIMIT and OFFSET
    $sql .= " LIMIT ? OFFSET ?";
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("isii", $term, $searchParam, $itemsPerPage, $offset);
    } else {
        $stmt->bind_param("iii", $term, $itemsPerPage, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return [
        'records' => $result,
        'totalRecords' => $totalRecords
    ];
}

// Function to get all loan details with search and pagination
function getLoanDetails($term, $searchQuery = '', $searchBy = 'id', $itemsPerPage = 10, $offset = 0) {
    $sql = "SELECT l.*, m.Name as MemberName, m.MemberID
            FROM Loan l
            JOIN Member m ON l.Member_MemberID = m.MemberID
            WHERE l.Term = ?";
    
    // Add search conditions
    if (!empty($searchQuery)) {
        if ($searchBy === 'id') {
            $sql .= " AND l.LoanID LIKE ?";
            $searchParam = "%$searchQuery%";
        } else if ($searchBy === 'name') {
            $sql .= " AND m.Name LIKE ?";
            $searchParam = "%$searchQuery%";
        }
    }
    
    $sql .= " ORDER BY l.Issued_Date DESC";
    
    // Count total records before adding LIMIT
    $countSql = $sql;
    $countStmt = prepare($countSql);
    
    if (!empty($searchQuery)) {
        $countStmt->bind_param("is", $term, $searchParam);
    } else {
        $countStmt->bind_param("i", $term);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult->num_rows;
    
    // Add pagination LIMIT and OFFSET
    $sql .= " LIMIT ? OFFSET ?";
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("isii", $term, $searchParam, $itemsPerPage, $offset);
    } else {
        $stmt->bind_param("iii", $term, $itemsPerPage, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return [
        'records' => $result,
        'totalRecords' => $totalRecords
    ];
}

// Function to get all fine details with search and pagination
function getFineDetails($term, $searchQuery = '', $searchBy = 'id', $itemsPerPage = 10, $offset = 0) {
    $sql = "SELECT f.*, m.Name as MemberName, m.MemberID
            FROM Fine f
            JOIN Member m ON f.Member_MemberID = m.MemberID
            WHERE f.Term = ?";
    
    // Add search conditions
    if (!empty($searchQuery)) {
        if ($searchBy === 'id') {
            $sql .= " AND f.FineID LIKE ?";
            $searchParam = "%$searchQuery%";
        } else if ($searchBy === 'name') {
            $sql .= " AND m.Name LIKE ?";
            $searchParam = "%$searchQuery%";
        }
    }
    
    $sql .= " ORDER BY f.Date DESC";
    
    // Count total records before adding LIMIT
    $countSql = $sql;
    $countStmt = prepare($countSql);
    
    if (!empty($searchQuery)) {
        $countStmt->bind_param("is", $term, $searchParam);
    } else {
        $countStmt->bind_param("i", $term);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult->num_rows;
    
    // Add pagination LIMIT and OFFSET
    $sql .= " LIMIT ? OFFSET ?";
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("isii", $term, $searchParam, $itemsPerPage, $offset);
    } else {
        $stmt->bind_param("iii", $term, $itemsPerPage, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return [
        'records' => $result,
        'totalRecords' => $totalRecords
    ];
}

// Function to get all death welfare details with search and pagination
function getDeathWelfareDetails($term, $searchQuery = '', $searchBy = 'id', $itemsPerPage = 10, $offset = 0) {
    $sql = "SELECT w.*, m.Name as MemberName, m.MemberID
            FROM DeathWelfare w
            JOIN Member m ON w.Member_MemberID = m.MemberID
            WHERE w.Term = ?";
    
    // Add search conditions
    if (!empty($searchQuery)) {
        if ($searchBy === 'id') {
            $sql .= " AND w.WelfareID LIKE ?";
            $searchParam = "%$searchQuery%";
        } else if ($searchBy === 'name') {
            $sql .= " AND m.Name LIKE ?";
            $searchParam = "%$searchQuery%";
        }
    }
    
    $sql .= " ORDER BY w.Date DESC";
    
    // Count total records before adding LIMIT
    $countSql = $sql;
    $countStmt = prepare($countSql);
    
    if (!empty($searchQuery)) {
        $countStmt->bind_param("is", $term, $searchParam);
    } else {
        $countStmt->bind_param("i", $term);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult->num_rows;
    
    // Add pagination LIMIT and OFFSET
    $sql .= " LIMIT ? OFFSET ?";
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("isii", $term, $searchParam, $itemsPerPage, $offset);
    } else {
        $stmt->bind_param("iii", $term, $itemsPerPage, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return [
        'records' => $result,
        'totalRecords' => $totalRecords
    ];
}

// Function to get all payment details with search and pagination
function getPaymentDetails($term, $searchQuery = '', $searchBy = 'id', $itemsPerPage = 10, $offset = 0) {
    $sql = "SELECT p.*, m.Name as MemberName, m.MemberID
            FROM Payment p
            JOIN Member m ON p.Member_MemberID = m.MemberID
            WHERE p.Term = ?";
    
    // Add search conditions
    if (!empty($searchQuery)) {
        if ($searchBy === 'id') {
            $sql .= " AND p.PaymentID LIKE ?";
            $searchParam = "%$searchQuery%";
        } else if ($searchBy === 'name') {
            $sql .= " AND m.Name LIKE ?";
            $searchParam = "%$searchQuery%";
        }
    }
    
    $sql .= " ORDER BY p.Date DESC";
    
    // Count total records before adding LIMIT
    $countSql = $sql;
    $countStmt = prepare($countSql);
    
    if (!empty($searchQuery)) {
        $countStmt->bind_param("is", $term, $searchParam);
    } else {
        $countStmt->bind_param("i", $term);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult->num_rows;
    
    // Add pagination LIMIT and OFFSET
    $sql .= " LIMIT ? OFFSET ?";
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("isii", $term, $searchParam, $itemsPerPage, $offset);
    } else {
        $stmt->bind_param("iii", $term, $itemsPerPage, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return [
        'records' => $result,
        'totalRecords' => $totalRecords
    ];
}

// Function to get all expense details with search and pagination
function getExpenseDetails($term, $searchQuery = '', $searchBy = 'id', $itemsPerPage = 10, $offset = 0) {
    $sql = "SELECT e.*, t.Name as TreasurerName
            FROM Expenses e
            JOIN Treasurer t ON e.Treasurer_TreasurerID = t.TreasurerID
            WHERE e.Term = ?";
    
    // Add search conditions
    if (!empty($searchQuery)) {
        if ($searchBy === 'id') {
            $sql .= " AND e.ExpenseID LIKE ?";
            $searchParam = "%$searchQuery%";
        } else if ($searchBy === 'category') {
            $sql .= " AND e.Category LIKE ?";
            $searchParam = "%$searchQuery%";
        }
    }
    
    $sql .= " ORDER BY e.Date DESC";
    
    // Count total records before adding LIMIT
    $countSql = $sql;
    $countStmt = prepare($countSql);
    
    if (!empty($searchQuery)) {
        $countStmt->bind_param("is", $term, $searchParam);
    } else {
        $countStmt->bind_param("i", $term);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult->num_rows;
    
    // Add pagination LIMIT and OFFSET
    $sql .= " LIMIT ? OFFSET ?";
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("isii", $term, $searchParam, $itemsPerPage, $offset);
    } else {
        $stmt->bind_param("iii", $term, $itemsPerPage, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return [
        'records' => $result,
        'totalRecords' => $totalRecords
    ];
}

// Get data based on selected type with pagination
$totalRecords = 0;
switch($selectedType) {
    case 'membership':
        $result = getMembershipFeeDetails($selectedTerm, $searchQuery, $searchBy, $itemsPerPage, $offset);
        $membershipFees = $result['records'];
        $totalRecords = $result['totalRecords'];
        break;
    case 'loans':
        $result = getLoanDetails($selectedTerm, $searchQuery, $searchBy, $itemsPerPage, $offset);
        $loans = $result['records'];
        $totalRecords = $result['totalRecords'];
        break;
    case 'fines':
        $result = getFineDetails($selectedTerm, $searchQuery, $searchBy, $itemsPerPage, $offset);
        $fines = $result['records'];
        $totalRecords = $result['totalRecords'];
        break;
    case 'welfare':
        $result = getDeathWelfareDetails($selectedTerm, $searchQuery, $searchBy, $itemsPerPage, $offset);
        $welfares = $result['records'];
        $totalRecords = $result['totalRecords'];
        break;
    case 'payments':
        $result = getPaymentDetails($selectedTerm, $searchQuery, $searchBy, $itemsPerPage, $offset);
        $payments = $result['records'];
        $totalRecords = $result['totalRecords'];
        break;
    case 'expenses':
        $result = getExpenseDetails($selectedTerm, $searchQuery, $searchBy, $itemsPerPage, $offset);
        $expenses = $result['records'];
        $totalRecords = $result['totalRecords'];
        break;
    default:
        $result = getLoanDetails($selectedTerm, $searchQuery, $searchBy, $itemsPerPage, $offset);
        $loans = $result['records'];
        $totalRecords = $result['totalRecords'];
        $selectedType = 'loans';
        break;
}

// Calculate pagination values
$totalPages = ceil($totalRecords / $itemsPerPage);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Details - Auditor Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
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

        .page-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .report-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255, 255, 255, 0.15);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .report-id, .version-id {
            color: white;
            font-weight: 500;
        }

        .filters {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-left: auto;
            gap: 1rem;
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
            padding: 0.5rem 1rem;
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
        }

        .btn-cancel {
            background-color: #e0e0e0;
            color: #333;
        }

        .btn-cancel:hover {
            background:rgba(90, 98, 104, 0.26);
        }

        .btn-secondary {
            padding: 0.5rem 1rem;
            border: 2px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-radius: 50px;
            cursor: pointer;
        }

        .btn-secondary:hover {
            background:rgba(224, 224, 224, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-info:hover {
            background: #138496;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }

        table th, table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }

        .status-badge {
            padding: 0.2rem 1rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-pending {
            background-color: #ffc107;
            color: #212529;
        }

        .status-approved {
            background-color: #28a745;
            color: white;
        }

        .status-yes {
            background-color: #28a745;
            color: white;
        }

        .status-no {
            background-color: #dc3545;
            color: white;
        }

        .status-reviewed {
            background-color:rgb(216, 92, 175);
            color: white;
        }

        .status-rejected {
            background-color: #dc3545;
            color: white;
        }

        .status-ongoing {
            background-color: #6c757d;
            color: white;
        }

        .tabs {
            display: flex;
            margin-bottom: 2rem;
            border-bottom: 1px solid #e0e0e0;
            overflow-x: auto;
            white-space: nowrap;
        }
        .action-column {
    text-align: center !important;
    /* padding: 0.5rem !important; */
}
        .tab {
            padding: 1rem 1.5rem;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #6c757d;
            text-decoration: none;
        }

        .tab:hover {
            color: #1e3c72;
            border-color: rgba(30, 60, 114, 0.5);
        }

        .tab.active {
            color: #1e3c72;
            border-color: #1e3c72;
        }

        .btn-sm.comment-btn {
            padding: 0.3rem 0.6rem;
            display: inline-block;
            margin: 0 auto;
            /* white-space: nowrap; */
        }

        .action-column {
            display: flex;
            gap: 0.5rem;
            /* text-align: center !important;
            padding: 0.5rem !important; */
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 5px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
        }

        .responsive-table {
            overflow-x: auto;
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
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 50%;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1e3c72;
        }

        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #333;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
        }

        textarea.form-control {
            min-height: 100px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e0e0e0;
        }

        /* Search box styles */
        .search-box {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            gap: 10px;
        }

        .search-input {
            flex-grow: 1;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }

        .search-select {
            padding: 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }

        .search-btn {
            padding: 8px 16px;
            background-color: #1e3c72;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .search-btn:hover {
            background-color: #2a5298;
        }

        .review-status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .comments-container {
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 20px;
        }

        .comment-item {
            border-bottom: 1px solid #e0e0e0;
            padding: 10px 0;
            margin-bottom: 10px;
        }

        .comment-date {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 5px;
        }

        .comment-text {
            line-height: 1.4;
        }

        .comment-item-info {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab {
                padding: 0.5rem 1rem;
            }

            .modal-content {
                width: 90%;
            }
        }

        /* Add these styles to your existing <style> section */

.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid #e0e0e0;
}

.pagination-info {
    color: #6c757d;
    font-size: 0.9rem;
}

.pagination {
    display: flex;
    gap: 5px;
}

.page-link {
    padding: 6px 12px;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    color: #1e3c72;
    text-decoration: none;
    transition: all 0.2s ease;
}

.page-link:hover {
    background-color: #f8f9fa;
    border-color: #dee2e6;
}

.page-link.active {
    background-color: #1e3c72;
    color: white;
    border-color: #1e3c72;
}

@media (max-width: 768px) {
    .pagination-container {
        flex-direction: column;
        gap: 15px;
    }
    
    .pagination {
        justify-content: center;
        flex-wrap: wrap;
    }
}
    </style>
</head>
<body>
    <div class="container">
        <?php include '../templates/navbar-auditor.php'; ?>

        <div class="content">
            <div class="page-header">
                <h1>Financial Details</h1>
                <div class="filters">
                    <div class="report-info">
                        <span class="report-id">Report: <strong><?php echo htmlspecialchars($reportId ?? 'None'); ?></strong></span>
                        <span class="version-id">Version: <strong><?php echo htmlspecialchars($versionId ?? 'None'); ?></strong></span>
                        <span class="status-badge status-<?php echo strtolower($reportStatus); ?>">
                            <?php echo ucfirst($reportStatus); ?>
                        </span>
                    </div>
                    <a href="pendingReports.php?term=<?php echo $selectedTerm; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>

            <?php if(isset($_GET['message'])): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($_GET['message']); ?>
                </div>
            <?php endif; ?>

            <div class="review-status">
                <span>Report Status: 
                    <span class="status-badge status-<?php echo strtolower($reportStatus); ?>">
                        <?php echo ucfirst($reportStatus); ?>
                    </span>
                </span>
                
                <?php if ($reportStatus !== 'reviewed' && $reportStatus !== 'approved'): ?>
                    <button id="reviewBtn" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i> Submit Review
                    </button>
                <?php endif; ?>
                
                <button id="viewCommentsBtn" class="btn btn-info">
                    <i class="fas fa-comments"></i> View Comments
                </button>
            </div>

            <!-- Navigation Tabs -->
            <div class="tabs">
                <a href="reviewDetails.php?type=loans#table-container" class="tab <?php echo $selectedType === 'loans' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave"></i> Loans
                </a>
                <a href="reviewDetails.php?type=membership#table-container" class="tab <?php echo $selectedType === 'membership' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Membership Fees
                </a>
                <a href="reviewDetails.php?type=fines#table-container" class="tab <?php echo $selectedType === 'fines' ? 'active' : ''; ?>">
                    <i class="fas fa-gavel"></i> Fines
                </a>
                <a href="reviewDetails.php?type=welfare#table-container" class="tab <?php echo $selectedType === 'welfare' ? 'active' : ''; ?>">
                    <i class="fas fa-hand-holding-heart"></i> Death Welfare
                </a>
                <a href="reviewDetails.php?type=payments#table-container" class="tab <?php echo $selectedType === 'payments' ? 'active' : ''; ?>">
                    <i class="fas fa-credit-card"></i> Payments
                </a>
                <a href="reviewDetails.php?type=expenses#table-container" class="tab <?php echo $selectedType === 'expenses' ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar"></i> Expenses
                </a>
            </div>

            <div class="card" id="table-container">
                <div class="card-header">
                    <h2 class="card-title">
                        <?php 
                        switch($selectedType) {
                            case 'loans': echo 'Loans'; break;
                            case 'membership': echo 'Membership Fees'; break;
                            case 'fines': echo 'Fines'; break;
                            case 'welfare': echo 'Death Welfare'; break;
                            case 'payments': echo 'Payments'; break;
                            case 'expenses': echo 'Expenses'; break;
                        }
                        ?>
                    </h2>
                </div>

                <!-- Search Form -->
                <form method="GET" action="" action="reviewDetails.php#table-container" class="search-box">
                    <input type="hidden" name="type" value="<?php echo $selectedType; ?>">
                    
                    <input type="text" name="search" placeholder="Search..." class="search-input" value="<?php echo htmlspecialchars($searchQuery); ?>">
                    
                    <select name="searchBy" class="search-select">
                        <?php if ($selectedType == 'expenses'): ?>
                            <option value="id" <?php echo $searchBy === 'id' ? 'selected' : ''; ?>>ID</option>
                            <option value="category" <?php echo $searchBy === 'category' ? 'selected' : ''; ?>>Category</option>
                        <?php else: ?>
                            <option value="id" <?php echo $searchBy === 'id' ? 'selected' : ''; ?>>ID</option>
                            <option value="name" <?php echo $searchBy === 'name' ? 'selected' : ''; ?>>Member Name</option>
                        <?php endif; ?>
                    </select>
                    
                    <button type="submit" class="search-btn">
                        <i class="fas fa-search"></i> Search
                    </button>
                    
                    <?php if (!empty($searchQuery)): ?>
                        <a href="reviewDetails.php?type=<?php echo $selectedType; ?>#table-container" class="btn btn-cancel">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>

                <div class="responsive-table">
                    <?php if($selectedType === 'loans'): ?>
                        <!-- Loans Table -->
                        <table>
                            <thead>
                                <tr>
                                    <th>Loan ID</th>
                                    <th>Member</th>
                                    <th>Amount</th>
                                    <th>Issued Date</th>
                                    <th>Due Date</th>
                                    <th>Paid</th>
                                    <th>Remaining</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($loans && $loans->num_rows > 0): ?>
                                    <?php while ($loan = $loans->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($loan['LoanID']); ?></td>
                                            <td><?php echo htmlspecialchars($loan['MemberName']); ?></td>
                                            <td>Rs. <?php echo number_format($loan['Amount'], 2); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($loan['Issued_Date'])); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($loan['Due_Date'])); ?></td>
                                            <td>Rs. <?php echo number_format($loan['Paid_Loan'], 2); ?></td>
                                            <td>Rs. <?php echo number_format($loan['Remain_Loan'], 2); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower($loan['Status']); ?>">
                                                    <?php echo ucfirst($loan['Status']); ?>
                                                </span>
                                            </td>
                                            <td class="action-column">
                                                <button class="btn btn-info btn-sm comment-btn" data-id="<?php echo $loan['LoanID']; ?>" data-type="loans">
                                                    <i class="fas fa-comment"></i> Comment
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" style="text-align: center;">No loans found for this term</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                    <?php elseif($selectedType === 'membership'): ?>
                        <!-- Membership Fees Table -->
                        <table>
                            <thead>
                                <tr>
                                    <th>Fee ID</th>
                                    <th>Member</th>
                                    <th>Fee Type</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($membershipFees && $membershipFees->num_rows > 0): ?>
                                    <?php while ($fee = $membershipFees->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($fee['FeeID']); ?></td>
                                            <td><?php echo htmlspecialchars($fee['MemberName']); ?></td>
                                            <td><?php echo htmlspecialchars($fee['FeeType']); ?></td>
                                            <td>Rs. <?php echo number_format($fee['Amount'], 2); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($fee['Date'])); ?></td>
                                            <td class="action-column">
                                                <button class="btn btn-info btn-sm comment-btn" data-id="<?php echo $fee['FeeID']; ?>" data-type="membership">
                                                    <i class="fas fa-comment"></i> Comment
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;">No membership fees found for this term</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                    <?php elseif($selectedType === 'fines'): ?>
                        <!-- Fines Table -->
                        <table>
                            <thead>
                                <tr>
                                    <th>Fine ID</th>
                                    <th>Member</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Is Paid</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($fines && $fines->num_rows > 0): ?>
                                    <?php while ($fine = $fines->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($fine['FineID']); ?></td>
                                            <td><?php echo htmlspecialchars($fine['MemberName']); ?></td>
                                            <td><?php echo htmlspecialchars($fine['Description']); ?></td>
                                            <td>Rs. <?php echo number_format($fine['Amount'], 2); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($fine['Date'])); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $fine['IsPaid'] === 'Yes' ? 'status-yes' : 'status-no'; ?>">
                                                    <?php echo $fine['IsPaid']; ?>
                                                </span>
                                            </td>
                                            <td class="action-column">
                                                <button class="btn btn-info btn-sm comment-btn" data-id="<?php echo $fine['FineID']; ?>" data-type="fines">
                                                    <i class="fas fa-comment"></i> Comment
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;">No fines found for this term</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                    <?php elseif($selectedType === 'welfare'): ?>
                        <!-- Death Welfare Table -->
                        <table>
                            <thead>
                                <tr>
                                    <th>Welfare ID</th>
                                    <th>Member</th>
                                    <th>Relationship</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($welfares && $welfares->num_rows > 0): ?>
                                    <?php while ($welfare = $welfares->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($welfare['WelfareID']); ?></td>
                                            <td><?php echo htmlspecialchars($welfare['MemberName']); ?></td>
                                            <td><?php echo htmlspecialchars($welfare['Relationship']); ?></td>
                                            <td>Rs. <?php echo number_format($welfare['Amount'], 2); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($welfare['Date'])); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower($welfare['Status']); ?>">
                                                    <?php echo ucfirst($welfare['Status']); ?>
                                                </span>
                                            </td>
                                            <td class="action-column">
                                                <button class="btn btn-info btn-sm comment-btn" data-id="<?php echo $welfare['WelfareID']; ?>" data-type="welfare">
                                                    <i class="fas fa-comment"></i> Comment
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;">No death welfare payments found for this term</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        
                    <?php elseif($selectedType === 'payments'): ?>
                        <!-- Payments Table -->
                        <table>
                            <thead>
                                <tr>
                                    <th>Payment ID</th>
                                    <th>Member</th>
                                    <th>Payment Type</th>
                                    <th>Method</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($payments && $payments->num_rows > 0): ?>
                                    <?php while ($payment = $payments->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($payment['PaymentID']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['MemberName']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['Payment_Type']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['Method']); ?></td>
                                            <td>Rs. <?php echo number_format($payment['Amount'], 2); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($payment['Date'])); ?></td>
                                            <td class="action-column">
                                                <button class="btn btn-info btn-sm comment-btn" data-id="<?php echo $payment['PaymentID']; ?>" data-type="payments">
                                                    <i class="fas fa-comment"></i> Comment
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;">No payments found for this term</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        
                    <?php elseif($selectedType === 'expenses'): ?>
                        <!-- Expenses Table -->
                        <table>
                            <thead>
                                <tr>
                                    <th>Expense ID</th>
                                    <th>Category</th>
                                    <th>Method</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($expenses && $expenses->num_rows > 0): ?>
                                    <?php while ($expense = $expenses->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($expense['ExpenseID']); ?></td>
                                            <td><?php echo htmlspecialchars($expense['Category']); ?></td>
                                            <td><?php echo htmlspecialchars($expense['Method']); ?></td>
                                            <td>Rs. <?php echo number_format($expense['Amount'], 2); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($expense['Date'])); ?></td>
                                            <td><?php echo htmlspecialchars($expense['Description'] ?? ''); ?></td>
                                            <td class="action-column">
                                                <button class="btn btn-info btn-sm comment-btn" data-id="<?php echo $expense['ExpenseID']; ?>" data-type="expenses">
                                                    <i class="fas fa-comment"></i> Comment
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;">No expenses found for this term</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <!-- Modified Pagination Links with anchor -->
                    <div class="pagination-container">
                        <div class="pagination-info">
                            Showing <?php echo min(($currentPage - 1) * $itemsPerPage + 1, $totalRecords); ?> to 
                            <?php echo min($currentPage * $itemsPerPage, $totalRecords); ?> of 
                            <?php echo $totalRecords; ?> entries
                        </div>
                        
                        <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($currentPage > 1): ?>
                                <a class="page-link" href="?type=<?php echo $selectedType; ?>&search=<?php echo urlencode($searchQuery); ?>&searchBy=<?php echo $searchBy; ?>&page=1#table-container">First</a>
                                <a class="page-link" href="?type=<?php echo $selectedType; ?>&search=<?php echo urlencode($searchQuery); ?>&searchBy=<?php echo $searchBy; ?>&page=<?php echo $currentPage - 1; ?>#table-container">Previous</a>
                            <?php endif; ?>
                            
                            <?php
                            // Calculate the range of page numbers to display
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($startPage + 4, $totalPages);
                            
                            if ($endPage - $startPage < 4) {
                                $startPage = max(1, $endPage - 4);
                            }
                            
                            for ($i = $startPage; $i <= $endPage; $i++): 
                            ?>
                                <a class="page-link <?php echo $i == $currentPage ? 'active' : ''; ?>" 
                                href="?type=<?php echo $selectedType; ?>&search=<?php echo urlencode($searchQuery); ?>&searchBy=<?php echo $searchBy; ?>&page=<?php echo $i; ?>#table-container">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($currentPage < $totalPages): ?>
                                <a class="page-link" href="?type=<?php echo $selectedType; ?>&search=<?php echo urlencode($searchQuery); ?>&searchBy=<?php echo $searchBy; ?>&page=<?php echo $currentPage + 1; ?>#table-container">Next</a>
                                <a class="page-link" href="?type=<?php echo $selectedType; ?>&search=<?php echo urlencode($searchQuery); ?>&searchBy=<?php echo $searchBy; ?>&page=<?php echo $totalPages; ?>#table-container">Last</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php include '../templates/footer.php'; ?>
    </div>

    <!-- Comment Modal -->
    <div id="commentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add Comment</h3>
                <span class="close">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" id="itemId" name="itemId" value="">
                <input type="hidden" id="itemType" name="itemType" value="">
                <input type="hidden" name="reportId" value="<?php echo htmlspecialchars($reportId); ?>">
                <input type="hidden" name="versionId" value="<?php echo htmlspecialchars($versionId); ?>">
                
                <div class="form-group comment-item-info" id="commentItemInfo">
                    <strong>Adding comment for: </strong><span id="commentItemTypeDisplay"></span> ID: <span id="commentItemIdDisplay"></span>
                </div>
                
                <div class="form-group">
                    <label for="comment" class="form-label">Comment:</label>
                    <textarea name="comment" id="comment" class="form-control" required></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" id="closeModal">Cancel</button>
                    <button type="submit" name="addComment" class="btn btn-primary">Save Comment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Review Financial Report</h3>
                <span class="close">&times;</span>
            </div>
            <div id="transactionCheckResult" style="display: none; margin-bottom: 15px;"></div>
            <form method="POST" action="" id="reviewForm">
                <input type="hidden" name="reportId" value="<?php echo htmlspecialchars($reportId); ?>">
                <input type="hidden" name="versionId" value="<?php echo htmlspecialchars($versionId); ?>">
                
                <div class="form-group">
                    <label class="form-label">Please select your decision for Report <?php echo htmlspecialchars($reportId); ?> (Version <?php echo htmlspecialchars($versionId); ?>):</label>
                    <div style="margin-top: 10px;">
                        <input type="radio" id="makeChanges" name="decision" value="changes" checked>
                        <label for="makeChanges">Make Changes (Set status to 'Reviewed')</label>
                    </div>
                    <div style="margin-top: 10px;">
                        <input type="radio" id="approve" name="decision" value="approve">
                        <label for="approve">Approve (Set status to 'Approved')</label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" id="closeReviewModal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="checkTransactionsBtn">Check for New Transactions</button>
                    <button type="submit" name="updateReportStatus" class="btn btn-success">Save Decision</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Comments Viewer Modal -->
    <div id="commentsModal" class="modal">
        <div class="modal-content" style="width: 70%;">
            <div class="modal-header">
                <h3 class="modal-title">Report Comments</h3>
                <span class="close">&times;</span>
            </div>
            <div class="comments-container">
                <?php 
                if ($reportId && $versionId) {
                    $comments = getReportComments($reportId, $versionId);
                    if ($comments && $comments->num_rows > 0) {
                        while ($comment = $comments->fetch_assoc()) {
                            echo '<div class="comment-item">';
                            echo '<div class="comment-date">' . date('M d, Y H:i', strtotime($comment['CommentDate'])) . '</div>';
                            echo '<div class="comment-text">' . htmlspecialchars($comment['Comment']) . '</div>';
                            echo '</div>';
                        }
                    } else {
                        echo '<p>No comments found for this report.</p>';
                    }
                } else {
                    echo '<p>No active report to show comments for.</p>';
                }
                ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="closeCommentsModal">Close</button>
            </div>
        </div>
    </div>

    <script>
    // Modal functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Comment modal
        const commentModal = document.getElementById('commentModal');
        const commentBtns = document.querySelectorAll('.comment-btn');
        const closeCommentBtn = document.querySelector('#commentModal .close');
        const closeCommentBtnAlt = document.getElementById('closeModal');
        const itemIdInput = document.getElementById('itemId');
        const itemTypeInput = document.getElementById('itemType');
        
        // Review modal
        const reviewModal = document.getElementById('reviewModal');
        const reviewBtn = document.getElementById('reviewBtn');
        const closeReviewBtn = document.querySelector('#reviewModal .close');
        const closeReviewBtnAlt = document.getElementById('closeReviewModal');
        
        // Comments viewer modal
        const commentsModal = document.getElementById('commentsModal');
        const viewCommentsBtn = document.getElementById('viewCommentsBtn');
        const closeCommentsBtn = document.querySelector('#commentsModal .close');
        const closeCommentsBtnAlt = document.getElementById('closeCommentsModal');
        
        // Open comment modal
        commentBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const type = this.getAttribute('data-type');
                
                itemIdInput.value = id;
                itemTypeInput.value = type;
                
                // Update the display elements to show which item we're commenting on
                document.getElementById('commentItemIdDisplay').textContent = id;
                document.getElementById('commentItemTypeDisplay').textContent = 
                    type.charAt(0).toUpperCase() + type.slice(1).replace(/s$/, ''); // Capitalize and make singular
                
                commentModal.style.display = 'block';
            });
        });
        
        // Close comment modal
        closeCommentBtn.addEventListener('click', function() {
            commentModal.style.display = 'none';
        });
        
        if (closeCommentBtnAlt) {
            closeCommentBtnAlt.addEventListener('click', function() {
                commentModal.style.display = 'none';
            });
        }
        
        // Open review modal
        if (reviewBtn) {
            reviewBtn.addEventListener('click', function() {
                reviewModal.style.display = 'block';
            });
        }
        
        // Close review modal
        if (closeReviewBtn) {
            closeReviewBtn.addEventListener('click', function() {
                reviewModal.style.display = 'none';
            });
        }
        
        if (closeReviewBtnAlt) {
            closeReviewBtnAlt.addEventListener('click', function() {
                reviewModal.style.display = 'none';
            });
        }
        
        // Open comments modal
        if (viewCommentsBtn) {
            viewCommentsBtn.addEventListener('click', function() {
                commentsModal.style.display = 'block';
            });
        }
        
        // Close comments modal
        if (closeCommentsBtn) {
            closeCommentsBtn.addEventListener('click', function() {
                commentsModal.style.display = 'none';
            });
        }
        
        if (closeCommentsBtnAlt) {
            closeCommentsBtnAlt.addEventListener('click', function() {
                commentsModal.style.display = 'none';
            });
        }
        
        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target == commentModal) {
                commentModal.style.display = 'none';
            }
            
            if (event.target == reviewModal) {
                reviewModal.style.display = 'none';
            }
            
            if (event.target == commentsModal) {
                commentsModal.style.display = 'none';
            }
        });
    });

    // Function to scroll to the table container
    function scrollToTable() {
        // Check if the hash is present in the URL
        if (window.location.hash === '#table-container') {
            // Get the table container element
            const tableContainer = document.getElementById('table-container');
            
            if (tableContainer) {
                // Scroll to the table container with a slight offset for better visibility
                window.scrollTo({
                    top: tableContainer.offsetTop - 20,
                    behavior: 'smooth'
                });
            }
        }
    }

    // Run the function when the page loads
    window.addEventListener('load', scrollToTable);
    
    // Also run it when the hash changes (for browsers that don't refresh on hash change)
    window.addEventListener('hashchange', scrollToTable);
    
    // Modify the tab links to include the table container hash
    document.addEventListener('DOMContentLoaded', function() {
        const tabLinks = document.querySelectorAll('.tab');
        
        tabLinks.forEach(tab => {
            const href = tab.getAttribute('href');
            if (href && !href.includes('#')) {
                tab.setAttribute('href', href + '#table-container');
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
    // Get the check transactions button
    const checkTransactionsBtn = document.getElementById('checkTransactionsBtn');
    const transactionCheckResult = document.getElementById('transactionCheckResult');
    
    if (checkTransactionsBtn) {
        checkTransactionsBtn.addEventListener('click', function() {
            // Show loading indicator
            transactionCheckResult.innerHTML = '<div class="alert alert-info">Checking for new transactions...</div>';
            transactionCheckResult.style.display = 'block';
            
            // Get form data
            const reportId = document.querySelector('input[name="reportId"]').value;
            const versionId = document.querySelector('input[name="versionId"]').value;
            
            // Send AJAX request to check for new transactions
            fetch('checkTransactions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'reportId=' + encodeURIComponent(reportId) + '&versionId=' + encodeURIComponent(versionId) + '&term=<?php echo $selectedTerm; ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.hasNewTransactions) {
                    // Show warning about new transactions
                    transactionCheckResult.innerHTML = `
                        <div class="alert alert-warning">
                            <strong>Warning:</strong> There are ${data.totalNewTransactions} new transactions since this report was created.
                            <br>
                            <ul>
                                ${data.details.loans > 0 ? `<li>${data.details.loans} new loans</li>` : ''}
                                ${data.details.payments > 0 ? `<li>${data.details.payments} new payments</li>` : ''}
                                ${data.details.fees > 0 ? `<li>${data.details.fees} new membership fees</li>` : ''}
                                ${data.details.fines > 0 ? `<li>${data.details.fines} new fines</li>` : ''}
                                ${data.details.welfare > 0 ? `<li>${data.details.welfare} new welfare payments</li>` : ''}
                                ${data.details.expenses > 0 ? `<li>${data.details.expenses} new expenses</li>` : ''}
                            </ul>
                            <p>You should request the treasurer to submit a new report with the updated data.</p>
                            <p>If you proceed with "Approve", the system will automatically change it to "Make Changes" and add a comment.</p>
                        </div>
                    `;
                    
                    // Auto-select the "Make Changes" option
                    document.getElementById('makeChanges').checked = true;
                } else {
                    // Show that there are no new transactions
                    transactionCheckResult.innerHTML = '<div class="alert alert-success">No new transactions found since this report was created. You can proceed with your decision.</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                transactionCheckResult.innerHTML = '<div class="alert alert-danger">An error occurred while checking for new transactions. Please try again.</div>';
            });
        });
    }
});
    </script>
</body>
</html>