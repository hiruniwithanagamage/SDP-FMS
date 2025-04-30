<?php
session_start();
require_once "../../../config/database.php";

// Get current term
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $stmt = prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get death welfare details for all members - Using prepared statements
function getMemberWelfare($term) {
    $sql = "SELECT 
            m.MemberID,
            m.Name,
            GROUP_CONCAT(
                CASE 
                    WHEN dw.Status = 'approved' 
                    THEN dw.WelfareID
                    ELSE NULL 
                END
            ) as welfare_ids,
            GROUP_CONCAT(
                CASE 
                    WHEN dw.Status = 'approved' 
                    THEN dw.Amount
                    ELSE NULL 
                END
            ) as amounts,
            GROUP_CONCAT(
                CASE 
                    WHEN dw.Status = 'approved' 
                    THEN dw.Date
                    ELSE NULL 
                END
            ) as dates,
            GROUP_CONCAT(
                CASE 
                    WHEN dw.Status = 'approved' 
                    THEN dw.Relationship
                    ELSE NULL 
                END
            ) as relationships,
            GROUP_CONCAT(
                CASE 
                    WHEN dw.Status = 'approved' 
                    THEN dw.Status
                    ELSE NULL 
                END
            ) as statuses
        FROM Member m
        LEFT JOIN DeathWelfare dw ON m.MemberID = dw.Member_MemberID 
            AND dw.Term = ?
        GROUP BY m.MemberID, m.Name
        ORDER BY m.Name";
    
    $stmt = prepare($sql);
    $stmt->bind_param("i", $term);
    $stmt->execute();
    return $stmt->get_result();
}

// Get all welfare claims for the term - Using prepared statements
function getWelfareClaims($term) {
    $sql = "SELECT 
            dw.WelfareID,
            dw.Amount,
            dw.Date,
            dw.Relationship,
            dw.Status,
            dw.Member_MemberID,
            m.Name
        FROM DeathWelfare dw
        INNER JOIN Member m ON dw.Member_MemberID = m.MemberID 
        WHERE dw.Term = ?
        ORDER BY dw.Date DESC";
    
    $stmt = prepare($sql);
    $stmt->bind_param("i", $term);
    $stmt->execute();
    return $stmt->get_result();
}

// Get welfare summary for the term - Using prepared statements
function getWelfareSummary($term) {
    $sql = "SELECT 
            COUNT(*) as total_claims,
            COUNT(CASE WHEN Status = 'approved' THEN 1 END) as approved_claims,
            COUNT(CASE WHEN Status = 'pending' THEN 1 END) as pending_claims,
            COUNT(CASE WHEN Status = 'rejected' THEN 1 END) as rejected_claims,
            (SELECT COALESCE(SUM(e.Amount), 0) 
             FROM Expenses e 
             INNER JOIN DeathWelfare dw ON e.ExpenseID = dw.Expense_ExpenseID 
             WHERE dw.Term = ? AND dw.Status = 'approved'
            ) as total_amount
        FROM DeathWelfare
        WHERE Term = ?";
    
    $stmt = prepare($sql);
    $stmt->bind_param("ii", $term, $term);
    $stmt->execute();
    return $stmt->get_result();
}

// Get monthly welfare statistics - Using prepared statements
function getMonthlyWelfareStats($term) {
    $sql = "SELECT 
            MONTH(Date) as month,
            COUNT(*) as claims_filed,
            COUNT(CASE WHEN Status = 'approved' THEN 1 END) as approved_claims,
            (SELECT COALESCE(SUM(e.Amount), 0) 
             FROM Expenses e 
             INNER JOIN DeathWelfare dw ON e.ExpenseID = dw.Expense_ExpenseID 
             WHERE MONTH(dw.Date) = MONTH(d.Date) AND dw.Term = ? AND dw.Status = 'approved'
            ) as total_amount
        FROM DeathWelfare d
        WHERE Term = ?
        GROUP BY MONTH(Date)
        ORDER BY month";
    
    $stmt = prepare($sql);
    $stmt->bind_param("ii", $term, $term);
    $stmt->execute();
    return $stmt->get_result();
}

// Get welfare amount from Static table - Using prepared statements
function getWelfareAmount() {
    $sql = "SELECT death_welfare FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $stmt = prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Get all available terms/years - Using prepared statements
function getAllTerms() {
    $sql = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
    $stmt = prepare($sql);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to check if financial report for a specific term is approved - Using prepared statements
function isReportApproved($term) {
    $sql = "SELECT Status 
            FROM FinancialReportVersions 
            WHERE Term = ? 
            ORDER BY Date DESC 
            LIMIT 1";
    
    $stmt = prepare($sql);
    $stmt->bind_param("i", $term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return ($row['Status'] === 'approved');
    }
    
    return false; // If no report exists, it's not approved
}

// Handle Delete Welfare Claim
if(isset($_POST['delete_welfare'])) {
    $welfareId = $_POST['welfare_id'];
    $currentTerm = isset($_GET['year']) ? $_GET['year'] : (isset($_POST['year']) ? $_POST['year'] : getCurrentTerm());
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // Check if this welfare has linked expenses
        $checkQuery = "SELECT * FROM DeathWelfare WHERE WelfareID = ? AND Expense_ExpenseID IS NOT NULL";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("s", $welfareId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // If there are expenses linked to this welfare claim, handle them
        if($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $expenseId = $row['Expense_ExpenseID'];
            
            // Delete the expense record
            $deleteExpenseQuery = "DELETE FROM Expenses WHERE ExpenseID = ?";
            $stmt = $conn->prepare($deleteExpenseQuery);
            $stmt->bind_param("s", $expenseId);
            $stmt->execute();
        }
        
        // Delete the welfare record
        $deleteWelfareQuery = "DELETE FROM DeathWelfare WHERE WelfareID = ?";
        $stmt = $conn->prepare($deleteWelfareQuery);
        $stmt->bind_param("s", $welfareId);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Welfare claim #$welfareId was successfully deleted.";
    } catch(Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting welfare claim: " . $e->getMessage();
    }
    
    // Redirect back to welfare page
    header("Location: deathWelfare.php?year=" . $currentTerm);
    exit();
}

// Set up page variables
$currentTerm = getCurrentTerm();
$selectedTerm = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$allTerms = getAllTerms();

$welfareStats = getWelfareSummary($selectedTerm);
$monthlyStats = getMonthlyWelfareStats($selectedTerm);
$welfareAmount = getWelfareAmount();

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 
    4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September',
    10 => 'October', 11 => 'November', 12 => 'December'
];

$isReportApproved = isReportApproved($selectedTerm);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Reports - Treasurer Dashboard</title>
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
        
        /* Loading indicator styles */
        .loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            display: none;
        }

        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border-left-color: #1e3c72;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include '../../templates/navbar-treasurer.php'; ?>
        
        <!-- Loading Indicator -->
        <div class="loading" id="loadingIndicator">
            <div class="spinner"></div>
        </div>

        <div class="content">
            <div class="welcome-card">
                <h1>Financial Reports</h1>
                <div class="filters">
                    <select class="filter-select" id="yearSelect">
                        <?php foreach($years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $year == $selectedYear ? 'selected' : ''; ?>>
                                Year <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <!-- <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a> -->
                </div>
            </div>

            <?php if($message): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if(isset($error)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
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
                        <?php elseif ($existingReport && $existingReport['Status'] === 'ongoing'): ?>
                            <form method="post">
                                <input type="hidden" name="term" value="<?php echo $selectedYear; ?>">
                                <button type="submit" name="generate_report" class="btn btn-primary">
                                    Submit Revised Report
                                </button>
                                <button type="button" name="view_comment" class="btn btn-primary" onclick="window.location.href='viewComments.php?term=<?php echo $selectedYear; ?>'">
                                    View Comment
                                </button>
                            </form>
                        <?php elseif ($existingReport && $existingReport['Status'] === 'approved'): ?>
                            <button class="btn btn-secondary" disabled>
                                Report Approved
                            </button>
                        <?php else: ?>
                            <form method="post" action="">
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
                    <tbody id="reportHistoryTableBody">
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
        
        <?php include '../../templates/footer.php'; ?>
    </div>

    <script>
    function updateFilters() {
        const year = document.getElementById('yearSelect').value;
        const loadingIndicator = document.getElementById('loadingIndicator');
        
        // Show loading indicator
        if (loadingIndicator) {
            loadingIndicator.style.display = 'flex';
        }
        
        // Redirect to the page with the selected year
        window.location.href = `financialReports.php?year=${year}`;
    }
    
    // Add event listener to the year select dropdown
    document.addEventListener('DOMContentLoaded', function() {
        const yearSelect = document.getElementById('yearSelect');
        if (yearSelect) {
            yearSelect.addEventListener('change', updateFilters);
        }
        
        // Handle form submissions
        // document.querySelectorAll('form').forEach(form => {
        //     form.addEventListener('submit', function(event) {
        //         event.preventDefault();
                
        //         const loadingIndicator = document.getElementById('loadingIndicator');
        //         if (loadingIndicator) {
        //             loadingIndicator.style.display = 'flex';
        //         }
                
        //         // Submit the form
        //         this.submit();
        //     });
        // });
    });
    </script>
</body>
</html>