<?php
session_start();
require_once "../config/database.php";

// Check if user is logged in and is a treasurer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'treasurer') {
    header("Location: ../loginProcess.php");
    exit();
}

$treasurerID = $_SESSION['user_id'];
$currentYear = date('Y');
$message = '';
$alertType = '';

// Get selected year from URL parameter or default to current term
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : null;

// Get current term from Static table
function getCurrentTerm() {
    $sql = "SELECT year FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

$currentTerm = getCurrentTerm();

// If no year is selected, default to the current term
if ($selectedYear === null) {
    $selectedYear = $currentTerm;
}

// Function to get total income for current term
function getTotalIncome($term) {
    $sql = "SELECT COALESCE(SUM(Amount), 0) as total FROM Payment WHERE YEAR(Date) = ?";
    $stmt = prepare($sql);
    $stmt->bind_param("i", $term);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return floatval($row['total']);
}

// Function to get total expenses for current term
function getTotalExpenses($term) {
    $sql = "SELECT COALESCE(SUM(Amount), 0) as total FROM Expenses WHERE YEAR(Date) = ?";
    $stmt = prepare($sql);
    $stmt->bind_param("i", $term);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return floatval($row['total']);
}

// Function to get previous year's balance
function getPreviousYearBalance($term) {
    $prevYear = $term - 1;
    $sql = "SELECT Net_Income as balance FROM FinancialReportVersions 
            WHERE Term = ? AND Status = 'approved' 
            ORDER BY ReportID DESC LIMIT 1";
    $stmt = prepare($sql);
    $stmt->bind_param("i", $prevYear);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return floatval($row['balance']);
    }
    
    // If no previous year report, calculate from payments and expenses
    $sql = "SELECT 
            (SELECT COALESCE(SUM(Amount), 0) FROM Payment WHERE YEAR(Date) <= ?) - 
            (SELECT COALESCE(SUM(Amount), 0) FROM Expenses WHERE YEAR(Date) <= ?) as balance";
    $stmt = prepare($sql);
    $stmt->bind_param("ii", $prevYear, $prevYear);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return floatval($row['balance']);
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

// Function to check if a report already exists for this term
function reportExists($term, $treasurerID) {
    $sql = "SELECT * FROM FinancialReportVersions 
            WHERE Term = ? AND Treasurer_TreasurerID = ? 
            ORDER BY VersionID DESC LIMIT 1";
    $stmt = prepare($sql);
    $stmt->bind_param("is", $term, $treasurerID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return false;
}

// Function to get all reports for this treasurer
function getReportHistory($treasurerID) {
    $sql = "SELECT * FROM FinancialReportVersions 
            WHERE Treasurer_TreasurerID = ? 
            ORDER BY Term DESC, VersionID DESC";
    $stmt = prepare($sql);
    $stmt->bind_param("s", $treasurerID);
    $stmt->execute();
    return $stmt->get_result();
}

// Process form submission to generate a new report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    $term = $_POST['term'];
    $previousYearBalance = getPreviousYearBalance($term);
    $totalIncome = getTotalIncome($term);
    $totalExpenses = getTotalExpenses($term);
    $netIncome = $previousYearBalance + $totalIncome - $totalExpenses;
    
    // Check if a report already exists for this term
    $existingReport = reportExists($term, $treasurerID);
    
    if ($existingReport) {
        $reportID = $existingReport['ReportID'];
        $newVersionID = intval($existingReport['VersionID']) + 1;
    } else {
        // Generate a new report ID
        $reportID = 'RPT' . date('Y') . rand(1000, 9999);
        $newVersionID = 1;
    }
    
    // Generate a new version
    $date = date('Y-m-d');
    $status = 'pending';
    
    $sql = "INSERT INTO FinancialReportVersions 
            (ReportID, VersionID, Term, Date, Previous_Year_Balance, 
            Total_Income, Total_Expenses, Net_Income, Status, Treasurer_TreasurerID) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = prepare($sql);
    $stmt->bind_param("sissdddss", $reportID, $newVersionID, $term, $date, 
                    $previousYearBalance, $totalIncome, $totalExpenses, 
                    $netIncome, $status, $treasurerID);
    
    if ($stmt->execute()) {
        $message = "Year-end financial report for $term has been generated and submitted for approval.";
        $alertType = "success";
    } else {
        $message = "Error generating report: " . $stmt->error;
        $alertType = "danger";
    }
}

// Calculate report data for the selected year
$previousYearBalance = getPreviousYearBalance($selectedYear);
$totalIncome = getTotalIncome($selectedYear);
$totalExpenses = getTotalExpenses($selectedYear);
$netIncome = $previousYearBalance + $totalIncome - $totalExpenses;

// Get income and expense breakdowns
$incomeBreakdown = getIncomeBreakdown($selectedYear);
$expenseBreakdown = getExpenseBreakdown($selectedYear);

// Check if a report already exists for this term
$existingReport = reportExists($selectedYear, $treasurerID);

// Check if this is the current treasurer's term (to control access to report generation)
$isCurrentTerm = ($selectedYear == $currentTerm);

// Get report history
$reportHistory = getReportHistory($treasurerID);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Reports - Treasurer Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 8px;
            color: white;
        }

        .alert-success {
            background-color: #28a745;
        }

        .alert-danger {
            background-color: #dc3545;
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

        .status-approved {
            background-color: #28a745;
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
    </style>
</head>
<body>
    <div class="container">
        <?php include '../views/templates/navbar-treasurer.php'; ?>

        <div class="content">
            <div class="page-header">
                <h1>Financial Reports</h1>
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <select class="filter-select form-control" id="yearSelect" onchange="window.location.href='financial_reports.php?year='+this.value" style="width: auto; padding: 0.6rem 1rem; border-radius: 5px; background-color: rgba(255, 255, 255, 0.2); color: white; border: 1px solid rgba(255, 255, 255, 0.3);">
                        <?php for($y = $currentTerm; $y >= $currentTerm - 2; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $selectedYear ? 'selected' : ''; ?>>
                                Year <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <!-- <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a> -->
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $alertType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Year-End Financial Report <?php echo $selectedYear; ?></h2>
                </div>

                <div class="report-summary">
                    <div class="summary-item">
                        <div class="summary-label">Previous Year Balance</div>
                        <div class="summary-value">Rs. <?php echo number_format($previousYearBalance, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Total Income</div>
                        <div class="summary-value">Rs. <?php echo number_format($totalIncome, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Total Expenses</div>
                        <div class="summary-value">Rs. <?php echo number_format($totalExpenses, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Net Income</div>
                        <div class="summary-value">Rs. <?php echo number_format($netIncome, 2); ?></div>
                    </div>
                </div>

                <div class="breakdown-section">
                    <div class="breakdown-card">
                        <h3 class="breakdown-title">Income Breakdown</h3>
                        <ul class="breakdown-list">
                            <?php if ($incomeBreakdown->num_rows > 0): ?>
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
                            <?php if ($expenseBreakdown->num_rows > 0): ?>
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

                <div class="report-actions">
                    <?php if ($isCurrentTerm): ?>
                        <?php if ($existingReport && $existingReport['Status'] === 'pending'): ?>
                            <button class="btn btn-secondary" disabled>
                                Report Pending Approval (Submitted on <?php echo date('M d, Y', strtotime($existingReport['Date'])); ?>)
                            </button>
                        <?php elseif ($existingReport && $existingReport['Status'] === 'rejected'): ?>
                            <form method="post">
                                <input type="hidden" name="term" value="<?php echo $selectedYear; ?>">
                                <button type="submit" name="generate_report" class="btn btn-primary">
                                    Submit Revised Report
                                </button>
                            </form>
                        <?php elseif ($existingReport && $existingReport['Status'] === 'approved'): ?>
                            <button class="btn btn-secondary" disabled>
                                Report Approved
                            </button>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="term" value="<?php echo $selectedYear; ?>">
                                <button type="submit" name="generate_report" class="btn btn-primary">
                                    Generate & Submit Report
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($existingReport): ?>
                            <div class="alert" style="background-color: #f8f9fa; color: #1e3c72; padding: 0.8rem; margin-bottom: 0; text-align: right;">
                                Report status: 
                                <span class="status-badge status-<?php echo strtolower($existingReport['Status']); ?>">
                                    <?php echo ucfirst($existingReport['Status']); ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <div class="alert" style="background-color: #f8f9fa; color: #1e3c72; padding: 0.8rem; margin-bottom: 0; text-align: right;">
                                No report has been generated for this term
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Report History</h2>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Term</th>
                            <th>Report ID</th>
                            <th>Version</th>
                            <th>Date</th>
                            <th>Total Income</th>
                            <th>Total Expenses</th>
                            <th>Net Income</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($reportHistory->num_rows > 0): ?>
                            <?php while ($report = $reportHistory->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $report['Term']; ?></td>
                                    <td><?php echo $report['ReportID']; ?></td>
                                    <td><?php echo $report['VersionID']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($report['Date'])); ?></td>
                                    <td>Rs. <?php echo number_format($report['Total_Income'], 2); ?></td>
                                    <td>Rs. <?php echo number_format($report['Total_Expenses'], 2); ?></td>
                                    <td>Rs. <?php echo number_format($report['Net_Income'], 2); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($report['Status']); ?>">
                                            <?php echo ucfirst($report['Status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center;">No report history available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php include '../views/templates/footer.php'; ?>
    </div>
</body>
</html>