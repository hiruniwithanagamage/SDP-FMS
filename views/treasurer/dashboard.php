<?php
session_start();
require_once "../../config/database.php";

date_default_timezone_set('Asia/Colombo');

// Check if user is logged in and has treasurer OR auditor role
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'treasurer' && $_SESSION['role'] !== 'auditor')) {
    header("Location: ../login.php");
    exit();
}

// Determine if user is an auditor (for UI adjustments)
$isAuditor = ($_SESSION['role'] === 'auditor');

// Function to get total balance
function getTotalBalance($year) {
    $sql = "SELECT 
        (SELECT COALESCE(SUM(Amount), 0) FROM Payment WHERE Term = ?) - 
        (SELECT COALESCE(SUM(Amount), 0) FROM Expenses WHERE Term = ?) as total_balance";
    
    $stmt = prepare($sql);
    $stmt->bind_param("ii", $year, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['total_balance'] ?? 0;
}

// Function to get income vs expenses by month
function getIncomeVsExpensesByMonth($year) {
    $data = [];
    
    // Get monthly income
    $sql = "SELECT 
                MONTH(Date) as month,
                SUM(Amount) as amount
            FROM Payment
            WHERE Term = ?
            GROUP BY MONTH(Date)
            ORDER BY MONTH(Date)";
    
    $stmt = prepare($sql);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Initialize all months with 0
    for ($i = 1; $i <= 12; $i++) {
        $data['income'][$i] = 0;
        $data['expenses'][$i] = 0;
    }
    
    while ($row = $result->fetch_assoc()) {
        $data['income'][$row['month']] = $row['amount'];
    }
    
    // Get monthly expenses
    $sql = "SELECT 
                MONTH(Date) as month,
                SUM(Amount) as amount
            FROM Expenses
            WHERE Term = ?
            GROUP BY MONTH(Date)
            ORDER BY MONTH(Date)";
    
    $stmt = prepare($sql);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $data['expenses'][$row['month']] = $row['amount'];
    }
    
    return $data;
}

// Function to get membership fee statistics for monthly fees based on the current term
function getMembershipFeeStats($year) {
    // Get the current month (1-12)
    $currentMonth = (int)date('m');
    
    // Get the monthly fee amount from the static table for the current term
    $feeAmountSql = "SELECT monthly_fee FROM Static WHERE year = ? ";
    $feeStmt = prepare($feeAmountSql);
    $feeStmt->bind_param("i", $year);
    $feeStmt->execute();
    $feeResult = $feeStmt->get_result();
    $feeRow = $feeResult->fetch_assoc();
    $monthlyFeeAmount = $feeRow ? $feeRow['monthly_fee'] : 100.00; // Default if not found
    
    // SQL to get all members with their monthly fee payments for the current term
    $sql = "SELECT 
                m.MemberID,
                m.Name,
                m.Status,
                COUNT(DISTINCT mf.FeeID) as paid_months
            FROM 
                Member m
            LEFT JOIN 
                MembershipFee mf ON m.MemberID = mf.Member_MemberID 
                AND mf.Type = 'monthly' 
                AND mf.IsPaid = 'Yes'
                AND mf.Term = ?
            GROUP BY 
                m.MemberID";
    
    $stmt = prepare($sql);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Initialize stats counters
    $total_members = 0;
    $paid_fees = 0;
    $unpaid_fees = 0;
    $collected_amount = 0;
    $outstanding_amount = 0;
    
    while ($row = $result->fetch_assoc()) {
        $total_members++;
        
        // A member is fully paid if they've paid for all months up to the current month
        if ($row['paid_months'] >= $currentMonth) {
            $paid_fees++;
            $collected_amount += $monthlyFeeAmount * $row['paid_months'];
        } else {
            // This member has missed some payments
            $unpaid_fees++;
            $collected_amount += $monthlyFeeAmount * $row['paid_months'];
            $outstanding_amount += $monthlyFeeAmount * ($currentMonth - $row['paid_months']);
        }
    }
    
    return [
        'total_fees' => $total_members,
        'paid_fees' => $paid_fees,
        'unpaid_fees' => $unpaid_fees,
        'collected_amount' => $collected_amount,
        'outstanding_amount' => $outstanding_amount
    ];
}

// Function to get loan statistics
function getLoanStats($year) {
    $sql = "SELECT 
                COUNT(*) as total_loans,
                SUM(CASE WHEN Status = 'approved' THEN 1 ELSE 0 END) as approved_loans,
                SUM(CASE WHEN Status = 'pending' THEN 1 ELSE 0 END) as pending_loans,
                SUM(CASE WHEN Status = 'rejected' THEN 1 ELSE 0 END) as rejected_loans,
                SUM(CASE WHEN Status = 'approved' THEN Amount ELSE 0 END) as approved_amount,
                SUM(CASE WHEN Status = 'approved' THEN Paid_Loan ELSE 0 END) as paid_amount,
                SUM(CASE WHEN Status = 'approved' THEN Remain_Loan ELSE 0 END) as remaining_amount,
                SUM(CASE WHEN Status = 'approved' THEN Paid_Interest ELSE 0 END) as paid_interest,
                SUM(CASE WHEN Status = 'approved' THEN Remain_Interest ELSE 0 END) as remaining_interest
            FROM Loan
            WHERE YEAR(Issued_Date) = ? OR Status = 'pending'";
    
    $stmt = prepare($sql);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row;
    }
    
    return [
        'total_loans' => 0,
        'approved_loans' => 0,
        'pending_loans' => 0,
        'rejected_loans' => 0,
        'approved_amount' => 0,
        'paid_amount' => 0,
        'remaining_amount' => 0,
        'paid_interest' => 0,
        'remaining_interest' => 0
    ];
}

// Function to get expense categories
function getExpenseCategories($year) {
    $sql = "SELECT 
                Category,
                SUM(Amount) as amount
            FROM Expenses
            WHERE Term = ?
            GROUP BY Category
            ORDER BY amount DESC";
    
    $stmt = prepare($sql);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get payment methods
function getPaymentMethods($year) {
    $sql = "SELECT 
                Method as method,
                SUM(Amount) as amount
            FROM Payment
            WHERE Term = ?
            GROUP BY Method
            ORDER BY amount DESC";
    
    $stmt = prepare($sql);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get member statistics
function getMemberStats() {
    $sql = "SELECT 
                COUNT(*) as total_members,
                SUM(CASE WHEN Status = 'Full Member' THEN 1 ELSE 0 END) as active_members,
                SUM(CASE WHEN Status != 'Full Member' THEN 1 ELSE 0 END) as inactive_members,
                AVG(TIMESTAMPDIFF(YEAR, Joined_Date, CURDATE())) as avg_membership_years
            FROM Member";
    
    $result = search($sql);
    
    if ($row = $result->fetch_assoc()) {
        return $row;
    }
    
    return [
        'total_members' => 0,
        'active_members' => 0,
        'inactive_members' => 0,
        'avg_membership_years' => 0
    ];
}

// Get current active term
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE status = 'active'";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Function to get all available years from the static table
function getAllYears() {
    $sql = "SELECT year FROM Static ORDER BY year DESC";
    $result = search($sql);
    
    $years = array();
    while ($row = $result->fetch_assoc()) {
        $years[] = $row['year'];
    }
    
    return $years;
}

// Get selected year from URL parameter, or use current term
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : getCurrentTerm();

// Get data for all charts
$totalBalance = getTotalBalance($selectedYear);
$monthlyData = getIncomeVsExpensesByMonth($selectedYear);
$membershipStats = getMembershipFeeStats($selectedYear);
$loanStats = getLoanStats($selectedYear);
$expenseCategories = getExpenseCategories($selectedYear);
$paymentMethods = getPaymentMethods($selectedYear);
$memberStats = getMemberStats();

// Calculate percentage for membership fees
$membershipCollectionRate = 0;
if ($membershipStats['total_fees'] > 0) {
    $membershipCollectionRate = ($membershipStats['paid_fees'] / $membershipStats['total_fees']) * 100;
}

// Calculate percentage for loan repayment
$loanRepaymentRate = 0;
if (($loanStats['paid_amount'] + $loanStats['remaining_amount']) > 0) {
    $loanRepaymentRate = ($loanStats['paid_amount'] / ($loanStats['paid_amount'] + $loanStats['remaining_amount'])) * 100;
}

// Define chart colors (fixed array to avoid undefined variable)
$chartColors = [
    '#1e3c72', '#2a5298', '#3a67b8', '#4a7bd8', '#5a8ff8', 
    '#6aa3ff', '#7ab7ff', '#8acbff', '#9adfff', '#aaf3ff'
];

// Process expense categories data for charts
$categoryLabels = [];
$categoryAmounts = [];
$categoryColors = [];
$expenseCategories->data_seek(0);
$colorIndex = 0;
while ($category = $expenseCategories->fetch_assoc()) {
    $categoryLabels[] = $category['Category'];
    $categoryAmounts[] = $category['amount'];
    $categoryColors[] = $chartColors[$colorIndex % count($chartColors)];
    $colorIndex++;
}

// Process payment methods data for charts
$methodLabels = [];
$methodAmounts = [];
$paymentMethods->data_seek(0);
while ($method = $paymentMethods->fetch_assoc()) {
    $methodLabels[] = $method['method'];
    $methodAmounts[] = $method['amount'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Analytics Dashboard - FMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Chart.js for visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .dashboard-container {
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
            margin: 35px 0 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .year-selector {
            display: flex;
            align-items: center;
            gap: 5px;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 50px;
            backdrop-filter: blur(5px);
        }

        .year-selector select {
            background: transparent;
            color: white;
            border: none;
            padding: 5px;
            font-size: 1rem;
            outline: none;
        }

        .year-selector select option {
            background: #2a5298;
            color: white;
        }

        .year-dropdown {
            max-height: 200px; /* This will limit the height */
            overflow-y: auto;  /* This enables vertical scrolling */
        }

        /* Custom scrollbar for the dropdown */
        select.year-dropdown::-webkit-scrollbar {
            width: 8px;
        }
        
        select.year-dropdown::-webkit-scrollbar-track {
            background: #1e3c72;
        }
        
        select.year-dropdown::-webkit-scrollbar-thumb {
            background-color: rgba(255, 255, 255, 0.3);
            border-radius: 4px;
        }

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-icon {
            font-size: 1.8rem;
            color: #1e3c72;
            background: #f0f5ff;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #1e3c72;
            margin: 5px 0;
        }

        .stat-change {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
        }

        .stat-change.positive {
            color: #28a745;
        }

        .stat-change.negative {
            color: #dc3545;
        }

        .dashboard-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        @media (max-width: 992px) {
            .dashboard-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-stats {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                gap: 15px;
                padding: 1.5rem;
            }
            
            .dashboard-container {
                padding: 1rem;
            }
            
            .chart-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .chart-actions {
                width: 100%;
            }
        }

        .chart-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1e3c72;
        }

        .chart-actions {
            display: flex;
            gap: 10px;
        }

        .chart-action {
            background: #f0f5ff;
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            color: #1e3c72;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
        }

        .chart-action:hover {
            background: #e0ebff;
        }
        
        .chart-action:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        .progress-container {
            margin: 15px 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #1e3c72;
            border-radius: 5px;
        }

        .data-grid {
            margin-top: 15px;
        }

        .data-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .data-row:last-child {
            border-bottom: none;
        }

        .data-label {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .data-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .data-value {
            font-weight: 600;
        }

        .dashboard-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            color: #666;
            font-size: 0.9rem;
        }

        .refresh-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .refresh-button {
            background: transparent;
            border: none;
            color: #1e3c72;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .refresh-button:hover {
            text-decoration: underline;
        }
        
        /* Badge for auditor view */
        .role-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
        }
        
        /* No data message */
        .no-data-message {
            text-align: center;
            padding: 50px 0;
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php 
        // Include appropriate navbar based on role
        if ($isAuditor) {
            include '../templates/navbar-auditor.php';
        } else {
            include '../templates/navbar-treasurer.php';
        }
        ?>
        <div class="content">
            <div class="page-header">
                <h1>
                    Financial Analytics Dashboard
                    <?php if ($isAuditor): ?>
                    <span class="role-badge">Audit View</span>
                    <?php endif; ?>
                </h1>
                <form action="" method="GET" class="year-selector">
                    <span>Term:</span>
                    <select name="year" onchange="this.form.submit()" class="year-dropdown">
                        <?php
                        // Get all years from the static table
                        $availableYears = getAllYears();
                        
                        // If no years found, fall back to showing last 5 years
                        if (empty($availableYears)) {
                            $currentYear = (int)date('Y');
                            for ($i = 0; $i < 5; $i++) {
                                $year = $currentYear - $i;
                                $availableYears[] = $year;
                            }
                        }
                        
                        // Generate options for all available years
                        foreach ($availableYears as $year) {
                            $selected = ($year == $selectedYear) ? 'selected' : '';
                            echo "<option value=\"$year\" $selected>$year</option>";
                        }
                        ?>
                    </select>
                </form>
            </div>

            <!-- Stats Row -->
            <div class="dashboard-stats">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-label">Total Balance</div>
                    </div>
                    <div class="stat-value">Rs.<?php echo number_format($totalBalance, 2); ?></div>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> Current Term
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <div class="stat-label">Membership Collection</div>
                    </div>
                    <div class="stat-value"><?php echo number_format($membershipCollectionRate, 1); ?>%</div>
                    <div class="stat-change <?php echo $membershipCollectionRate >= 75 ? 'positive' : 'negative'; ?>">
                        <i class="fas fa-<?php echo $membershipCollectionRate >= 75 ? 'check' : 'exclamation-triangle'; ?>"></i>
                        <?php echo $membershipStats['paid_fees']; ?> of <?php echo $membershipStats['total_fees']; ?> paid
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-landmark"></i>
                        </div>
                        <div class="stat-label">Loan Repayment</div>
                    </div>
                    <div class="stat-value"><?php echo number_format($loanRepaymentRate, 1); ?>%</div>
                    <div class="stat-change <?php echo $loanRepaymentRate >= 60 ? 'positive' : 'negative'; ?>">
                        <i class="fas fa-<?php echo $loanRepaymentRate >= 60 ? 'check' : 'exclamation-triangle'; ?>"></i>
                        Rs.<?php echo number_format($loanStats['paid_amount'], 2); ?> paid
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-label">Members</div>
                    </div>
                    <div class="stat-value"><?php echo $memberStats['total_members']; ?></div>
                    <div class="stat-change positive">
                        <i class="fas fa-user-check"></i>
                        <?php echo $memberStats['active_members']; ?> active
                    </div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="dashboard-row">
                <!-- Income vs Expenses Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">Income vs Expenses (<?php echo $selectedYear; ?>)</div>
                        <div class="chart-actions">
                            <?php if (!$isAuditor): ?>
                            <button class="chart-action" onclick="downloadChart('incomeExpensesChart', 'Income_vs_Expenses')">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php else: ?>
                            <button class="chart-action" disabled title="Auditors can view but not download reports">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="incomeExpensesChart"></canvas>
                    </div>
                </div>

                <!-- Membership Fee Status -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">Membership Fee Status (<?php echo $selectedYear; ?>)</div>
                        <div class="chart-actions">
                            <?php if (!$isAuditor): ?>
                            <button class="chart-action" onclick="downloadChart('membershipChart', 'Membership_Fee_Status')">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php else: ?>
                            <button class="chart-action" disabled title="Auditors can view but not download reports">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="membershipChart"></canvas>
                    </div>
                    <div class="progress-container">
                        <div class="progress-label">
                            <span>Collection Progress</span>
                            <span><?php echo number_format($membershipCollectionRate, 1); ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $membershipCollectionRate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
            <div class="dashboard-row">
                <!-- Expense Categories -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">Expense Categories (<?php echo $selectedYear; ?>)</div>
                        <div class="chart-actions">
                            <?php if (!$isAuditor): ?>
                            <button class="chart-action" onclick="downloadChart('expenseCategoriesChart', 'Expense_Categories')">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php else: ?>
                            <button class="chart-action" disabled title="Auditors can view but not download reports">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="expenseCategoriesChart"></canvas>
                    </div>
                    <div class="data-grid">
                        <?php
                        // Reset pointer and check if there are categories
                        $expenseCategories->data_seek(0); 
                        if ($expenseCategories->num_rows > 0) {
                            $colorIndex = 0;
                            while ($category = $expenseCategories->fetch_assoc()) {
                                $color = $chartColors[$colorIndex % count($chartColors)];
                                echo '<div class="data-row">';
                                echo '<div class="data-label"><div class="data-color" style="background: ' . $color . '"></div>' . htmlspecialchars($category['Category']) . '</div>';
                                echo '<div class="data-value">Rs.' . number_format($category['amount'], 2) . '</div>';
                                echo '</div>';
                                $colorIndex++;
                            }
                        } else {
                            echo '<div class="data-row"><div class="data-label">No expense data for this term</div></div>';
                        }
                        ?>
                    </div>
                </div>

                <!-- Loan Status -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">Loan Status(<?php echo $selectedYear; ?>)</div>
                        <div class="chart-actions">
                            <?php if (!$isAuditor): ?>
                            <button class="chart-action" onclick="downloadChart('loanStatusChart', 'Loan_Status')">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php else: ?>
                            <button class="chart-action" disabled title="Auditors can view but not download reports">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="loanStatusChart"></canvas>
                    </div>
                    <div class="progress-container">
                        <div class="progress-label">
                            <span>Repayment Progress</span>
                            <span><?php echo number_format($loanRepaymentRate, 1); ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $loanRepaymentRate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 3 -->
            <div class="dashboard-row">
                <!-- Payment Methods -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">Payment Methods (<?php echo $selectedYear; ?>)</div>
                        <div class="chart-actions">
                            <?php if (!$isAuditor): ?>
                            <button class="chart-action" onclick="downloadChart('paymentMethodsChart', 'Payment_Methods')">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php else: ?>
                            <button class="chart-action" disabled title="Auditors can view but not download reports">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="paymentMethodsChart"></canvas>
                    </div>
                </div>

                <!-- Member Statistics -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">Member Statistics</div>
                        <div class="chart-actions">
                            <?php if (!$isAuditor): ?>
                            <button class="chart-action" onclick="downloadChart('memberStatsChart', 'Member_Statistics')">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php else: ?>
                            <button class="chart-action" disabled title="Auditors can view but not download reports">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="memberStatsChart"></canvas>
                    </div>
                    <div class="data-grid">
                        <div class="data-row">
                            <div class="data-label"><div class="data-color" style="background: #1e3c72"></div>Total Members</div>
                            <div class="data-value"><?php echo $memberStats['total_members']; ?></div>
                        </div>
                        <div class="data-row">
                            <div class="data-label"><div class="data-color" style="background: #2a5298"></div>Active Members</div>
                            <div class="data-value"><?php echo $memberStats['active_members']; ?></div>
                        </div>
                        <div class="data-row">
                            <div class="data-label"><div class="data-color" style="background: #3a67b8"></div>Inactive Members</div>
                            <div class="data-value"><?php echo $memberStats['inactive_members']; ?></div>
                        </div>
                        <div class="data-row">
                            <div class="data-label"><div class="data-color" style="background: #4a7bd8"></div>Avg. Membership Years</div>
                            <div class="data-value"><?php echo number_format($memberStats['avg_membership_years'], 1); ?> years</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Footer -->
            <div class="dashboard-footer">
                <div>Report generated for financial year <?php echo $selectedYear; ?></div>
                <div class="refresh-info">
                    Last refreshed: <?php echo date('M d, Y H:i'); ?>
                    <?php if (!$isAuditor): ?>
                    <button class="refresh-button" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php include '../templates/footer.php'; ?>
    </div>

    // Replace this with your main chart initialization script to fix the JSON parsing issue

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Chart configuration and initialization
    // Define Chart.js defaults
    Chart.defaults.font.family = "'Segoe UI', 'Helvetica Neue', 'Helvetica', 'Arial', sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#666';
    
    // Chart colors
    const colors = {
        blue: '#1e3c72',
        lightBlue: '#2a5298',
        green: '#28a745',
        red: '#dc3545',
        yellow: '#ffc107',
        purple: '#6f42c1',
        orange: '#fd7e14',
        cyan: '#17a2b8',
        gray: '#6c757d'
    };

    // Monthly labels
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    
    // Store chart instances
    const chartInstances = {};
    
    // Raw data objects from PHP (directly embedded to avoid JSON parsing errors)
    const chartData = {
        income: <?php echo json_encode(array_values($monthlyData['income'] ?? array_fill(0, 12, 0))); ?>,
        expenses: <?php echo json_encode(array_values($monthlyData['expenses'] ?? array_fill(0, 12, 0))); ?>,
        membershipStats: {
            paidFees: <?php echo $membershipStats['paid_fees'] ?? 0; ?>,
            unpaidFees: <?php echo $membershipStats['unpaid_fees'] ?? 0; ?>
        },
        expenseCategories: {
            labels: <?php echo json_encode($categoryLabels ?? []); ?>,
            amounts: <?php echo json_encode($categoryAmounts ?? []); ?>,
            colors: <?php echo json_encode($categoryColors ?? []); ?>
        },
        loanStats: {
            paidAmount: <?php echo $loanStats['paid_amount'] ?? 0; ?>,
            remainingAmount: <?php echo $loanStats['remaining_amount'] ?? 0; ?>,
            paidInterest: <?php echo $loanStats['paid_interest'] ?? 0; ?>,
            remainingInterest: <?php echo $loanStats['remaining_interest'] ?? 0; ?>
        },
        paymentMethods: {
            labels: <?php echo json_encode($methodLabels ?? []); ?>, 
            amounts: <?php echo json_encode($methodAmounts ?? []); ?>
        },
        memberStats: {
            activeMembers: <?php echo $memberStats['active_members'] ?? 0; ?>,
            inactiveMembers: <?php echo $memberStats['inactive_members'] ?? 0; ?>
        }
    };
    
    // Initialize all charts
    function initializeCharts() {
        try {
            // Clear any existing chart instances
            Object.values(chartInstances).forEach(chart => {
                if (chart) chart.destroy();
            });
            
            // Income vs Expenses Chart
            const incomeExpensesCtx = document.getElementById('incomeExpensesChart')?.getContext('2d');
            if (incomeExpensesCtx) {
                chartInstances.incomeExpenses = new Chart(incomeExpensesCtx, {
                    type: 'bar',
                    data: {
                        labels: months,
                        datasets: [
                            {
                                label: 'Income',
                                data: chartData.income,
                                backgroundColor: colors.green,
                                borderColor: colors.green,
                                borderWidth: 1
                            },
                            {
                                label: 'Expenses',
                                data: chartData.expenses,
                                backgroundColor: colors.red,
                                borderColor: colors.red,
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Amount (Rs.)'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'top'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': Rs.' + (context.raw || 0).toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Membership Fee Chart
            const membershipCtx = document.getElementById('membershipChart')?.getContext('2d');
            if (membershipCtx) {
                chartInstances.membership = new Chart(membershipCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Paid', 'Unpaid'],
                        datasets: [{
                            data: [
                                chartData.membershipStats.paidFees, 
                                chartData.membershipStats.unpaidFees
                            ],
                            backgroundColor: [colors.green, colors.red],
                            borderColor: ['#fff', '#fff'],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '65%',
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = chartData.membershipStats.paidFees + 
                                                    chartData.membershipStats.unpaidFees;
                                        const percentage = Math.round((context.raw / total) * 100) || 0;
                                        return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Expense Categories Chart
            const expenseCategoriesCtx = document.getElementById('expenseCategoriesChart')?.getContext('2d');
            if (expenseCategoriesCtx && chartData.expenseCategories.labels.length > 0) {
                chartInstances.expenseCategories = new Chart(expenseCategoriesCtx, {
                    type: 'pie',
                    data: {
                        labels: chartData.expenseCategories.labels,
                        datasets: [{
                            data: chartData.expenseCategories.amounts,
                            backgroundColor: chartData.expenseCategories.colors,
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + (b || 0), 0);
                                        const percentage = Math.round((context.raw / total) * 100) || 0;
                                        return context.label + ': Rs.' + (context.raw || 0).toLocaleString() + 
                                               ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            } else if (expenseCategoriesCtx) {
                // Show no data message
                const container = expenseCategoriesCtx.canvas.parentNode;
                container.innerHTML = `
                    <div class="chart-fallback">
                        <i class="fas fa-info-circle"></i>
                        <p>No expense data available for this period</p>
                    </div>
                `;
            }
            
            // Loan Status Chart
            const loanStatusCtx = document.getElementById('loanStatusChart')?.getContext('2d');
            if (loanStatusCtx) {
                chartInstances.loanStatus = new Chart(loanStatusCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Loan Principal', 'Interest'],
                        datasets: [
                            {
                                label: 'Paid',
                                data: [
                                    chartData.loanStats.paidAmount, 
                                    chartData.loanStats.paidInterest
                                ],
                                backgroundColor: colors.green,
                                borderColor: colors.green,
                                borderWidth: 1
                            },
                            {
                                label: 'Remaining',
                                data: [
                                    chartData.loanStats.remainingAmount, 
                                    chartData.loanStats.remainingInterest
                                ],
                                backgroundColor: colors.orange,
                                borderColor: colors.orange,
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                stacked: false
                            },
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Amount (Rs.)'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'top'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': Rs.' + (context.raw || 0).toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Payment Methods Chart
            const paymentMethodsCtx = document.getElementById('paymentMethodsChart')?.getContext('2d');
            if (paymentMethodsCtx && chartData.paymentMethods.labels.length > 0) {
                chartInstances.paymentMethods = new Chart(paymentMethodsCtx, {
                    type: 'doughnut',
                    data: {
                        labels: chartData.paymentMethods.labels,
                        datasets: [{
                            data: chartData.paymentMethods.amounts,
                            backgroundColor: [colors.blue, colors.purple, colors.cyan, colors.orange],
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '50%',
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + (b || 0), 0);
                                        const percentage = Math.round((context.raw / total) * 100) || 0;
                                        return context.label + ': Rs.' + (context.raw || 0).toLocaleString() + 
                                               ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            } else if (paymentMethodsCtx) {
                // Show no data message
                const container = paymentMethodsCtx.canvas.parentNode;
                container.innerHTML = `
                    <div class="chart-fallback">
                        <i class="fas fa-info-circle"></i>
                        <p>No payment method data available for this period</p>
                    </div>
                `;
            }
            
            // Member Stats Chart
            const memberStatsCtx = document.getElementById('memberStatsChart')?.getContext('2d');
            if (memberStatsCtx) {
                chartInstances.memberStats = new Chart(memberStatsCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Member Status'],
                        datasets: [
                            {
                                label: 'Active Members',
                                data: [chartData.memberStats.activeMembers],
                                backgroundColor: colors.blue,
                                borderColor: colors.blue,
                                borderWidth: 1
                            },
                            {
                                label: 'Inactive Members',
                                data: [chartData.memberStats.inactiveMembers],
                                backgroundColor: colors.gray,
                                borderColor: colors.gray,
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                stacked: true
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Members'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = chartData.memberStats.activeMembers + 
                                                     chartData.memberStats.inactiveMembers;
                                        const percentage = Math.round((context.raw / total) * 100) || 0;
                                        return context.dataset.label + ': ' + context.raw + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            console.log('All charts initialized successfully');
        } catch (error) {
            console.error('Error initializing charts:', error);
            alert('There was an error displaying the charts. Please try refreshing the page.');
        }
    }
    
    // Function to download chart as image
    window.downloadChart = function(chartId, filename) {
        // Check if user is auditor before allowing download
        const isAuditor = <?php echo $isAuditor ? 'true' : 'false'; ?>;
        
        if (isAuditor) {
            alert('Auditors do not have permission to download charts.');
            return;
        }
        
        const canvas = document.getElementById(chartId);
        if (!canvas) {
            console.error('Canvas element not found:', chartId);
            alert('Chart not found. Please try again.');
            return;
        }
        
        try {
            const image = canvas.toDataURL('image/png');
            
            // Create temporary link and trigger download
            const link = document.createElement('a');
            link.href = image;
            link.download = filename + '_<?php echo $selectedYear; ?>.png';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } catch (error) {
            console.error('Error downloading chart:', error);
            alert('There was an error downloading the chart. Please try again.');
        }
    };
    
    // Initialize charts on page load
    initializeCharts();
});
</script>
</body>
</html>