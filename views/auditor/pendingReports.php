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

// Get the auditor ID from the User table
$sql = "SELECT Auditor_AuditorID FROM User WHERE UserId = ?";
$stmt = prepare($sql);
$stmt->bind_param("s", $userID);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $auditorID = $row['Auditor_AuditorID'];
} else {
    // If no auditor ID is found, redirect to login
    header("Location: ../../loginProcess.php");
    exit();
}

$message = '';
$alertType = '';

// Get selected term from URL parameter or default to current term
$selectedTerm = isset($_GET['term']) ? (int)$_GET['term'] : null;

// Function to get all years from Static table
function getAllYears() {
    $sql = "SELECT year FROM Static ORDER BY year DESC";
    $result = search($sql);
    $years = [];
    while ($row = $result->fetch_assoc()) {
        $years[] = $row['year'];
    }
    return $years;
}

// Get current term from Static table
function getCurrentTerm() {
    $sql = "SELECT year FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

$years = getAllYears();
$currentTerm = getCurrentTerm();

// If no term is selected, default to the current term
if ($selectedTerm === null) {
    $selectedTerm = $currentTerm;
}

// Get auditor's term from the database
$sql = "SELECT Term FROM Auditor WHERE AuditorID = ?";
$stmt = prepare($sql);
$stmt->bind_param("s", $auditorID);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $auditorTerm = $row['Term'];
} else {
    $auditorTerm = $currentTerm; // Default to current term if not found
}

// Check if this is the auditor's assigned term
$isAuditorTerm = ($selectedTerm == $auditorTerm);

// Function to get pending reports
function getPendingReports($term) {
    $sql = "SELECT r.*, t.Name as TreasurerName 
            FROM FinancialReportVersions r
            JOIN Treasurer t ON r.Treasurer_TreasurerID = t.TreasurerID
            WHERE r.Status = 'pending' AND r.Term = ?
            ORDER BY r.Date DESC";
    $stmt = prepare($sql);
    $stmt->bind_param("i", $term);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get income breakdown by type
function getIncomeBreakdown($term) {
    $sql = "SELECT 
            Payment_Type as type,
            COALESCE(SUM(Amount), 0) as amount
            FROM Payment 
            WHERE YEAR(Date) = ?
            GROUP BY Payment_Type";
    $stmt = prepare($sql);
    $stmt->bind_param("i", $term);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get expense breakdown by category
function getExpenseBreakdown($term) {
    $sql = "SELECT 
            Category as type,
            COALESCE(SUM(Amount), 0) as amount
            FROM Expenses 
            WHERE YEAR(Date) = ?
            GROUP BY Category";
    $stmt = prepare($sql);
    $stmt->bind_param("i", $term);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get loan details
function getLoanDetails($term) {
    $sql = "SELECT l.*, m.Name as MemberName
            FROM Loan l
            JOIN Member m ON l.Member_MemberID = m.MemberID
            WHERE YEAR(l.Issued_Date) = ? AND l.Status = 'approved'
            ORDER BY l.Issued_Date DESC";
    $stmt = prepare($sql);
    $stmt->bind_param("i", $term);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to approve a report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_action'])) {
    $reportID = $_POST['report_id'];
    $versionID = $_POST['version_id'];
    $action = $_POST['report_action'];
    $comments = isset($_POST['comments']) ? $_POST['comments'] : '';
    
    // Validate that this auditor is assigned for this term
    if (!$isAuditorTerm) {
        $message = "You are not authorized to review reports for this term.";
        $alertType = "danger";
    } else {
        $status = ($action === 'approve') ? 'approved' : 'reviewed';
        
        $sql = "UPDATE FinancialReportVersions 
                SET Status = ?, Auditor_AuditorID = ?, Comments = ? 
                WHERE ReportID = ? AND VersionID = ?";
        $stmt = prepare($sql);
        $stmt->bind_param("ssssi", $status, $auditorID, $comments, $reportID, $versionID);
        
        if ($stmt->execute()) {
            $message = "Report has been " . ($action === 'approve' ? 'approved' : 'reviewed') . " successfully.";
            $alertType = "success";
        } else {
            $message = "Error updating report: " . $stmt->error;
            $alertType = "danger";
        }
    }
}

// Get detailed report data if specific report is selected
$reportDetail = null;
$incomeBreakdown = null;
$expenseBreakdown = null;
$loanDetails = null;
$selectedType = isset($_GET['type']) ? $_GET['type'] : null;

if (isset($_GET['report_id']) && isset($_GET['version_id'])) {
    $reportID = $_GET['report_id'];
    $versionID = $_GET['version_id'];
    
    $sql = "SELECT r.*, t.Name as TreasurerName 
            FROM FinancialReportVersions r
            JOIN Treasurer t ON r.Treasurer_TreasurerID = t.TreasurerID
            WHERE r.ReportID = ? AND r.VersionID = ?";
    $stmt = prepare($sql);
    $stmt->bind_param("si", $reportID, $versionID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $reportDetail = $result->fetch_assoc();
        $reportTerm = $reportDetail['Term'];
        
        $incomeBreakdown = getIncomeBreakdown($reportTerm);
        $expenseBreakdown = getExpenseBreakdown($reportTerm);
        
        // Get specific details if requested
        if ($selectedType == 'loans') {
            $loanDetails = getLoanDetails($reportTerm);
        }
    }
}

// Get all pending reports for the selected term
$pendingReports = getPendingReports($selectedTerm);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pending Financial Reports - Auditor Dashboard</title>
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

        .filters {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-left: auto;
            gap: 1rem;
        }

        .filter-select {
            padding: 0.5rem 1rem;
            border: 2px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-radius: 50px;
            cursor: pointer;
        }

        .filter-select option {
            background: #1e3c72;
            color: white;
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

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
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

        .report-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-item {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #1e3c72;
            margin: 0.5rem 0;
        }

        .summary-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .breakdown-section {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 768px) {
            .breakdown-section {
                grid-template-columns: 1fr;
            }
        }

        .breakdown-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .breakdown-title {
            color: #1e3c72;
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }

        .breakdown-list {
            list-style-type: none;
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .breakdown-item:last-child {
            border-bottom: none;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #ced4da;
            border-radius: 5px;
        }

        .report-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 2rem;
            gap: 1rem;
        }

        .detail-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .detail-tab {
            padding: 0.5rem 1rem;
            background: #f8f9fa;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
        }

        .detail-tab:hover, .detail-tab.active {
            background: #1e3c72;
            color: white;
        }

        .loan-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .loan-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
        }

        .loan-header {
            margin-bottom: 1rem;
            color: #1e3c72;
            font-weight: 600;
        }

        .loan-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .loan-label {
            font-weight: 500;
            color: #666;
        }

        .loan-value {
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include '../templates/navbar-auditor.php'; ?>
        
        <div class="content">
            <div class="page-header">
                <h1>Pending Financial Reports</h1>
                <div class="filters">
                    <select class="filter-select" id="termSelect">
                        <?php foreach($years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $year == $selectedTerm ? 'selected' : ''; ?>>
                                Term <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <?php if($message): ?>
                <div class="alert alert-<?php echo $alertType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if(!$isAuditorTerm): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> You are viewing reports for term <?php echo $selectedTerm; ?>. You can only review reports for your assigned term (<?php echo $auditorTerm; ?>).
                </div>
            <?php endif; ?>

            <?php if($reportDetail): ?>
                <!-- Report Detail View -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Financial Report <?php echo htmlspecialchars($reportDetail['ReportID']); ?> (Version <?php echo htmlspecialchars($reportDetail['VersionID']); ?>)</h2>
                        <a href="pending_reports.php?term=<?php echo $selectedTerm; ?>" class="btn btn-secondary">
                            <i class="fas fa-list"></i> Back to Reports List
                        </a>
                    </div>

                    <div class="detail-tabs">
                        <a href="pending_reports.php?term=<?php echo $selectedTerm; ?>&report_id=<?php echo $reportDetail['ReportID']; ?>&version_id=<?php echo $reportDetail['VersionID']; ?>" class="detail-tab <?php echo $selectedType === null ? 'active' : ''; ?>">
                            <i class="fas fa-file-alt"></i> Summary
                        </a>
                        <a href="pending_reports.php?term=<?php echo $selectedTerm; ?>&report_id=<?php echo $reportDetail['ReportID']; ?>&version_id=<?php echo $reportDetail['VersionID']; ?>&type=loans" class="detail-tab <?php echo $selectedType === 'loans' ? 'active' : ''; ?>">
                            <i class="fas fa-money-bill-wave"></i> Loans
                        </a>
                    </div>

                    <?php if($selectedType === 'loans'): ?>
                        <!-- Loans Detail View -->
                        <h3>Loan Details for Term <?php echo htmlspecialchars($reportDetail['Term']); ?></h3>
                        
                        <?php if($loanDetails && $loanDetails->num_rows > 0): ?>
                            <div class="loan-grid">
                                <?php while($loan = $loanDetails->fetch_assoc()): ?>
                                    <div class="loan-card">
                                        <div class="loan-header"><?php echo htmlspecialchars($loan['MemberName']); ?></div>
                                        <div class="loan-info">
                                            <span class="loan-label">Loan ID:</span>
                                            <span class="loan-value"><?php echo htmlspecialchars($loan['LoanID']); ?></span>
                                        </div>
                                        <div class="loan-info">
                                            <span class="loan-label">Amount:</span>
                                            <span class="loan-value">Rs. <?php echo number_format($loan['Amount'], 2); ?></span>
                                        </div>
                                        <div class="loan-info">
                                            <span class="loan-label">Term:</span>
                                            <span class="loan-value"><?php echo htmlspecialchars($loan['Term']); ?> months</span>
                                        </div>
                                        <div class="loan-info">
                                            <span class="loan-label">Issued Date:</span>
                                            <span class="loan-value"><?php echo date('M d, Y', strtotime($loan['Issued_Date'])); ?></span>
                                        </div>
                                        <div class="loan-info">
                                            <span class="loan-label">Due Date:</span>
                                            <span class="loan-value"><?php echo date('M d, Y', strtotime($loan['Due_Date'])); ?></span>
                                        </div>
                                        <div class="loan-info">
                                            <span class="loan-label">Reason:</span>
                                            <span class="loan-value"><?php echo htmlspecialchars($loan['Reason']); ?></span>
                                        </div>
                                        <div class="loan-info">
                                            <span class="loan-label">Paid Amount:</span>
                                            <span class="loan-value">Rs. <?php echo number_format($loan['Paid_Loan'], 2); ?></span>
                                        </div>
                                        <div class="loan-info">
                                            <span class="loan-label">Remaining:</span>
                                            <span class="loan-value">Rs. <?php echo number_format($loan['Remain_Loan'], 2); ?></span>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p>No loan data available for this term.</p>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- Summary View -->
                        <div class="report-summary">
                            <div class="summary-item">
                                <div class="summary-label">Previous Year Balance</div>
                                <div class="summary-value">Rs. <?php echo number_format($reportDetail['Previous_Year_Balance'], 2); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Total Income</div>
                                <div class="summary-value">Rs. <?php echo number_format($reportDetail['Total_Income'], 2); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Total Expenses</div>
                                <div class="summary-value">Rs. <?php echo number_format($reportDetail['Total_Expenses'], 2); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Net Income</div>
                                <div class="summary-value">Rs. <?php echo number_format($reportDetail['Net_Income'], 2); ?></div>
                            </div>
                        </div>

                        <div class="breakdown-section">
                            <div class="breakdown-card">
                                <h3 class="breakdown-title">Income Breakdown</h3>
                                <ul class="breakdown-list">
                                    <?php if ($incomeBreakdown && $incomeBreakdown->num_rows > 0): ?>
                                        <?php while ($row = $incomeBreakdown->fetch_assoc()): ?>
                                            <li class="breakdown-item">
                                                <span><?php echo htmlspecialchars($row['type']); ?></span>
                                                <span>Rs. <?php echo number_format($row['amount'], 2); ?></span>
                                            </li>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <li class="breakdown-item">
                                            <span>No income data available for this term</span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>

                            <div class="breakdown-card">
                                <h3 class="breakdown-title">Expense Breakdown</h3>
                                <ul class="breakdown-list">
                                    <?php if ($expenseBreakdown && $expenseBreakdown->num_rows > 0): ?>
                                        <?php while ($row = $expenseBreakdown->fetch_assoc()): ?>
                                            <li class="breakdown-item">
                                                <span><?php echo htmlspecialchars($row['type']); ?></span>
                                                <span>Rs. <?php echo number_format($row['amount'], 2); ?></span>
                                            </li>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <li class="breakdown-item">
                                            <span>No expense data available for this term</span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if($isAuditorTerm && $reportDetail['Status'] === 'pending'): ?>
                        <form method="post" action="" class="card">
                            <div class="card-header">
                                <h3 class="card-title">Review Decision</h3>
                            </div>
                            
                            <div class="form-group">
                                <label for="comments" class="form-label">Comments</label>
                                <textarea id="comments" name="comments" class="form-control" rows="4" placeholder="Enter your comments or feedback about this report..."></textarea>
                            </div>
                            
                            <input type="hidden" name="report_id" value="<?php echo $reportDetail['ReportID']; ?>">
                            <input type="hidden" name="version_id" value="<?php echo $reportDetail['VersionID']; ?>">
                            
                            <div class="report-actions">
                                <button type="submit" name="report_action" value="review" class="btn btn-warning">
                                    <i class="fas fa-comment"></i> Review with Comments
                                </button>
                                <button type="submit" name="report_action" value="approve" class="btn btn-success">
                                    <i class="fas fa-check"></i> Approve Report
                                </button>
                            </div>
                        </form>
                    <?php elseif($reportDetail['Status'] === 'pending'): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> You can only review reports for your assigned term.
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Reports List View -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Pending Financial Reports for Term <?php echo $selectedTerm; ?></h2>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Report ID</th>
                                <th>Version</th>
                                <th>Date</th>
                                <th>Treasurer</th>
                                <th>Total Income</th>
                                <th>Total Expenses</th>
                                <th>Net Income</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($pendingReports->num_rows > 0): ?>
                                <?php while ($report = $pendingReports->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $report['ReportID']; ?></td>
                                        <td><?php echo $report['VersionID']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($report['Date'])); ?></td>
                                        <td><?php echo $report['TreasurerName']; ?></td>
                                        <td>Rs. <?php echo number_format($report['Total_Income'], 2); ?></td>
                                        <td>Rs. <?php echo number_format($report['Total_Expenses'], 2); ?></td>
                                        <td>Rs. <?php echo number_format($report['Net_Income'], 2); ?></td>
                                        <td>
                                            <a href="financialDetailsSimple.php?term=<?php echo $selectedTerm; ?>&report_id=<?php echo $report['ReportID']; ?>&version_id=<?php echo $report['VersionID']; ?>" class="btn btn-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center;">No pending reports found for this term</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <?php include '../templates/footer.php'; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const termSelect = document.getElementById('termSelect');
        if (termSelect) {
            termSelect.addEventListener('change', function() {
                window.location.href = `pending_reports.php?term=${this.value}`;
            });
        }
    });
    </script>
</body>
</html>