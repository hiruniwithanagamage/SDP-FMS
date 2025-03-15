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

// Function to get pending reports
function getPendingReports() {
    $sql = "SELECT ReportID, VersionID, Status, Term FROM FinancialReportVersions 
            WHERE Status IN ('pending', 'ongoing', 'reviewed') 
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
$reportStatus = $currentReport ? $currentReport['Status'] : 'ongoing';

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
    header("Location: financialDetailsSimple.php?type=$itemType&message=$message");
    exit();
}

// Update report status
if (isset($_POST['updateReportStatus'])) {
    $decision = $_POST['decision'];
    $reportId = $_POST['reportId'];
    $versionId = $_POST['versionId'];
    
    $status = ($decision === 'approve') ? 'approved' : 'ongoing';
    
    // First get the auditor ID associated with this user
    $getAuditorSql = "SELECT Auditor_AuditorID FROM User WHERE UserId = ?";
    $getAuditorStmt = prepare($getAuditorSql);
    $getAuditorStmt->bind_param("s", $userID);
    $getAuditorStmt->execute();
    $getAuditorResult = $getAuditorStmt->get_result();
    
    if ($getAuditorResult->num_rows > 0) {
        $auditorRow = $getAuditorResult->fetch_assoc();
        $auditorID = $auditorRow['Auditor_AuditorID'];
        
        // Now update with the correct auditorID
        $sql = "UPDATE FinancialReportVersions SET Status = ?, Auditor_AuditorID = ? WHERE ReportID = ? AND VersionID = ?";
        $stmt = prepare($sql);
        $stmt->bind_param("ssss", $status, $auditorID, $reportId, $versionId);
        
        if ($stmt->execute()) {
            $message = "Report " . ($status === 'approved' ? 'approved' : 'sent back for changes') . " successfully";
        } else {
            $message = "Error updating report status: " . $stmt->error;
        }
    } else {
        $message = "Error: Could not find auditor information for this user";
    }
    
    // Redirect to avoid resubmission
    header("Location: financialDetailsSimple.php?type=$selectedType&message=$message");
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

// Function to get all membership fee details with search
function getMembershipFeeDetails($term, $searchQuery = '', $searchBy = 'id') {
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
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("is", $term, $searchParam);
    } else {
        $stmt->bind_param("i", $term);
    }
    
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get all loan details with search
function getLoanDetails($term, $searchQuery = '', $searchBy = 'id') {
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
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("is", $term, $searchParam);
    } else {
        $stmt->bind_param("i", $term);
    }
    
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get all fine details with search
function getFineDetails($term, $searchQuery = '', $searchBy = 'id') {
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
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("is", $term, $searchParam);
    } else {
        $stmt->bind_param("i", $term);
    }
    
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get all death welfare details with search
function getDeathWelfareDetails($term, $searchQuery = '', $searchBy = 'id') {
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
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("is", $term, $searchParam);
    } else {
        $stmt->bind_param("i", $term);
    }
    
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get all payment details with search
function getPaymentDetails($term, $searchQuery = '', $searchBy = 'id') {
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
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("is", $term, $searchParam);
    } else {
        $stmt->bind_param("i", $term);
    }
    
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get all expense details with search
function getExpenseDetails($term, $searchQuery = '', $searchBy = 'id') {
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
    
    $stmt = prepare($sql);
    
    if (!empty($searchQuery)) {
        $stmt->bind_param("is", $term, $searchParam);
    } else {
        $stmt->bind_param("i", $term);
    }
    
    $stmt->execute();
    return $stmt->get_result();
}

// Get data based on selected type
switch($selectedType) {
    case 'membership':
        $membershipFees = getMembershipFeeDetails($selectedTerm, $searchQuery, $searchBy);
        break;
    case 'loans':
        $loans = getLoanDetails($selectedTerm, $searchQuery, $searchBy);
        break;
    case 'fines':
        $fines = getFineDetails($selectedTerm, $searchQuery, $searchBy);
        break;
    case 'welfare':
        $welfares = getDeathWelfareDetails($selectedTerm, $searchQuery, $searchBy);
        break;
    case 'payments':
        $payments = getPaymentDetails($selectedTerm, $searchQuery, $searchBy);
        break;
    case 'expenses':
        $expenses = getExpenseDetails($selectedTerm, $searchQuery, $searchBy);
        break;
    default:
        $loans = getLoanDetails($selectedTerm, $searchQuery, $searchBy);
        $selectedType = 'loans';
        break;
}
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
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
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

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
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
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
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
            background-color: #17a2b8;
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

        .action-column {
            display: flex;
            gap: 0.5rem;
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
                    <a href="home-auditor.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>

            <?php if(isset($_GET['message'])): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($_GET['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Review status and button -->
            <div class="review-status">
                <span>Report Status: 
                    <span class="status-badge status-<?php echo strtolower($reportStatus); ?>">
                        <?php echo ucfirst($reportStatus); ?>
                    </span>
                </span>
                <button id="reviewBtn" class="btn btn-primary">
                    <i class="fas fa-check-circle"></i> Submit Review
                </button>
                <button id="viewCommentsBtn" class="btn btn-info">
                    <i class="fas fa-comments"></i> View Comments
                </button>
            </div>

            <!-- Navigation Tabs -->
            <div class="tabs">
                <a href="financialDetailsSimple.php?type=loans" class="tab <?php echo $selectedType === 'loans' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave"></i> Loans
                </a>
                <a href="financialDetailsSimple.php?type=membership" class="tab <?php echo $selectedType === 'membership' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Membership Fees
                </a>
                <a href="financialDetailsSimple.php?type=fines" class="tab <?php echo $selectedType === 'fines' ? 'active' : ''; ?>">
                    <i class="fas fa-gavel"></i> Fines
                </a>
                <a href="financialDetailsSimple.php?type=welfare" class="tab <?php echo $selectedType === 'welfare' ? 'active' : ''; ?>">
                    <i class="fas fa-hand-holding-heart"></i> Death Welfare
                </a>
                <a href="financialDetailsSimple.php?type=payments" class="tab <?php echo $selectedType === 'payments' ? 'active' : ''; ?>">
                    <i class="fas fa-credit-card"></i> Payments
                </a>
                <a href="financialDetailsSimple.php?type=expenses" class="tab <?php echo $selectedType === 'expenses' ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar"></i> Expenses
                </a>
            </div>

            <div class="card">
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
                <form method="GET" action="" class="search-box">
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
                        <a href="financialDetailsSimple.php?type=<?php echo $selectedType; ?>" class="btn btn-secondary">
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
                                    <th>Term</th>
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
                                            <td><?php echo htmlspecialchars($loan['Term']); ?> months</td>
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
                                    <th>Status</th>
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
                                            <td>
                                                <span class="status-badge <?php echo $fee['IsPaid'] === 'Yes' ? 'status-yes' : 'status-no'; ?>">
                                                    <?php echo $fee['IsPaid']; ?>
                                                </span>
                                            </td>
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
                                    <th>Status</th>
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
                                            <td><?php echo htmlspecialchars($expense['Description']); ?></td>
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
                    <button type="button" class="btn btn-secondary" id="closeModal">Cancel</button>
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
            <form method="POST" action="">
                <input type="hidden" name="reportId" value="<?php echo htmlspecialchars($reportId); ?>">
                <input type="hidden" name="versionId" value="<?php echo htmlspecialchars($versionId); ?>">
                
                <div class="form-group">
                    <label class="form-label">Please select your decision for Report <?php echo htmlspecialchars($reportId); ?> (Version <?php echo htmlspecialchars($versionId); ?>):</label>
                    <div style="margin-top: 10px;">
                        <input type="radio" id="makeChanges" name="decision" value="changes" checked>
                        <label for="makeChanges">Make Changes (Set status to 'Ongoing')</label>
                    </div>
                    <div style="margin-top: 10px;">
                        <input type="radio" id="approve" name="decision" value="approve">
                        <label for="approve">Approve (Set status to 'Approved')</label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="closeReviewModal">Cancel</button>
                    <button type="submit" name="updateReportStatus" class="btn btn-primary">Save Decision</button>
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
    </script>
</body>
</html>