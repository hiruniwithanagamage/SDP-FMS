<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION["u"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

// Function to get the appropriate navbar based on user role
function getNavbarTemplate() {
    if (isset($_SESSION["role"])) {
        $role = $_SESSION["role"];
        $navbarPath = "../views/templates/navbar-{$role}.php";
        
        // Check if the role-specific navbar exists
        if (file_exists($navbarPath)) {
            return $navbarPath;
        }
    }
    
    // Default fallback navbar
    return "../views/templates/navbar-member.php";
}

// Function to get the appropriate back URL
function getBackUrl() {
    // If we have a stored referrer, use it
    if (isset($_SESSION['report_referrer']) && !empty($_SESSION['report_referrer'])) {
        return $_SESSION['report_referrer'];
    }
    
    // Otherwise, go to role-specific homepage
    if (isset($_SESSION["role"])) {
        return "../views/" . $_SESSION["role"] . "/home-" . $_SESSION["role"] . ".php";
    }
    
    // Fallback
    return "../index.php";
}

if (!isset($_SESSION['report_referrer']) || 
    (strpos($_SERVER['HTTP_REFERER'] ?? '', 'yearEndReport.php') === false)) {
    $_SESSION['report_referrer'] = $_SERVER['HTTP_REFERER'] ?? '../index.php';
}

// We'll use JavaScript history.back() for navigation, no need to track referrer in PHP

// Get available report years from database - only approved reports
function getAvailableYears() {
    $yearsQuery = "SELECT DISTINCT Term FROM FinancialReportVersions 
                   WHERE Status = 'approved'
                   ORDER BY Term DESC";
    $result = search($yearsQuery);
    
    $years = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $years[] = $row['Term'];
        }
    }
    
    return $years;
}

$availableYears = getAvailableYears();
$currentYear = isset($availableYears[0]) ? $availableYears[0] : date('Y');

// Get the report year (default to most recent available year if not specified)
$reportYear = isset($_GET['year']) ? intval($_GET['year']) : $currentYear;

// Function to get financial report data
function getFinancialReport($year) {
    // Query to get the latest approved financial report for the given year
    $reportQuery = "SELECT frv.*, 
                          a.Name as AuditorName,
                          t.Name as TreasurerName,
                          frv.Date as ReportDate
                   FROM FinancialReportVersions frv
                   LEFT JOIN Auditor a ON frv.Auditor_AuditorID = a.AuditorID
                   LEFT JOIN Treasurer t ON frv.Treasurer_TreasurerID = t.TreasurerID
                   WHERE frv.Term = ? 
                   AND frv.Status = 'approved'
                   ORDER BY frv.VersionID DESC 
                   LIMIT 1";
    
    $stmt = prepare($reportQuery);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // If no report is found for the selected year, return null
    if ($result->num_rows == 0) {
        return null;
    }
    
    $reportData = $result->fetch_assoc();
    
    // Get revenue details from Payment table
    $revenueQuery = "SELECT 
                      SUM(CASE WHEN Payment_Type = 'monthly' THEN Amount ELSE 0 END) as membership_fee,
                      SUM(CASE WHEN Payment_Type = 'registration' THEN Amount ELSE 0 END) as new_membership_fee,
                      SUM(CASE WHEN Payment_Type = 'loan' THEN Amount ELSE 0 END) as loan_revenue,
                      SUM(CASE WHEN Payment_Type = 'interest' THEN Amount ELSE 0 END) as interest_revenue,
                      SUM(CASE WHEN Payment_Type = 'fine' THEN Amount ELSE 0 END) as fine_revenue
                    FROM Payment
                    WHERE Term = ?";
    
    $stmtRevenue = prepare($revenueQuery);
    $stmtRevenue->bind_param("i", $year);
    $stmtRevenue->execute();
    $revenueResult = $stmtRevenue->get_result();
    $revenueData = $revenueResult->fetch_assoc();
    
    // Get expense details from Expenses table
    $expenseQuery = "SELECT 
                      SUM(CASE WHEN Category = 'death_welfare' THEN Amount ELSE 0 END) as death_welfare,
                      SUM(CASE WHEN Category = 'furniture' THEN Amount ELSE 0 END) as furniture_equipment,
                      SUM(CASE WHEN Category = 'maintenance' THEN Amount ELSE 0 END) as maintenance_repairs,
                      SUM(CASE WHEN Category NOT IN ('death_welfare', 'furniture', 'maintenance') THEN Amount ELSE 0 END) as other
                    FROM Expenses
                    WHERE Term = ?";
    
    $stmtExpense = prepare($expenseQuery);
    $stmtExpense->bind_param("i", $year);
    $stmtExpense->execute();
    $expenseResult = $stmtExpense->get_result();
    $expenseData = $expenseResult->fetch_assoc();
    
    // Get previous year's balance
    $previousBalance = $reportData['Previous_Year_Balance'] ?? 0;
    
    // Calculate totals
    $totalRevenue = $previousBalance + 
                   ($revenueData['membership_fee'] ?? 0) + 
                   ($revenueData['new_membership_fee'] ?? 0) + 
                   ($revenueData['loan_revenue'] ?? 0) + 
                   ($revenueData['interest_revenue'] ?? 0) + 
                   ($revenueData['fine_revenue'] ?? 0);
    
    $totalExpenses = ($expenseData['death_welfare'] ?? 0) + 
                    ($expenseData['furniture_equipment'] ?? 0) + 
                    ($expenseData['maintenance_repairs'] ?? 0) + 
                    ($expenseData['other'] ?? 0);
    
    $netIncome = $totalRevenue - $totalExpenses;
    
    // Format date and time
    $reportDate = new DateTime($reportData['ReportDate']);
    $formattedDate = $reportDate->format('d/m/Y');
    $formattedTime = $reportDate->format('g:iA');
    
    // Build the complete report
    $report = [
        'year' => $year,
        'total_revenue' => $totalRevenue,
        'total_expenses' => $totalExpenses,
        'net_income' => $netIncome,
        'revenue' => [
            'balance_on_hand' => $previousBalance,
            'membership_fee' => $revenueData['membership_fee'] ?? 0,
            'new_membership_fee' => $revenueData['new_membership_fee'] ?? 0,
            'loan_revenue' => $revenueData['loan_revenue'] ?? 0,
            'interest_revenue' => $revenueData['interest_revenue'] ?? 0,
            'fine_revenue' => $revenueData['fine_revenue'] ?? 0
        ],
        'expenses' => [
            'death_welfare' => $expenseData['death_welfare'] ?? 0,
            'furniture_equipment' => $expenseData['furniture_equipment'] ?? 0,
            'maintenance_repairs' => $expenseData['maintenance_repairs'] ?? 0,
            'other' => $expenseData['other'] ?? 0
        ],
        'notes' => $reportData['Comments'] ?? 'No audit notes available for this report.',
        'auditor' => $reportData['AuditorName'] ?? 'System Generated',
        'treasurer' => $reportData['TreasurerName'],
        'audit_date' => $formattedDate,
        'audit_time' => $formattedTime,
        'status' => $reportData['Status'],
        'report_id' => $reportData['ReportID'],
        'version_id' => $reportData['VersionID']
    ];
    
    return $report;
}

// Get the report data
$report = getFinancialReport($reportYear);

// Format numbers with commas for display
function formatCurrency($amount) {
    return number_format($amount, 2);
}

// Function to determine text color for amounts
function getAmountColor($amount) {
    if ($amount == 0) {
        return '';
    } else if ($amount < 0) {
        return 'text-red-500'; // For negative values (red in the example)
    }
    return '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Audit Report <?php echo $reportYear; ?> - Eksat Maranadhara Samithiya</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            /* font-family: 'Poppins', sans-serif; */
            font-family: Arial, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .home-container {
           min-height: 100vh;
           background: #f5f7fa;
           padding: 2rem;
        }
        
        .container {
            max-width: 1024px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .report-header {
            background-color: #d0e1f9;
            text-align: center;
            padding: 20px;
            border-radius: 10px 10px 0 0;
            margin-bottom: 20px;
        }
        
        .logo {
            width: 80px;
            margin: 0 auto 10px;
        }
        
        .society-name {
            font-size: 24px;
            font-weight: 600;
            margin-top: 10px;
        }
        
        .report-title-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .report-title {
            font-size: 28px;
            font-weight: 600;
            color: #333;
        }
        
        .year-selector {
            position: relative;
        }
        
        .year-select {
            background: #d0e1f9;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 20px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            text-align: center;
            width: 120px;
        }
        
        .year-select:focus {
            outline: none;
            box-shadow: 0 0 0 2px #4285f4;
        }
        
        .year-selector::after {
            content: "▼";
            font-size: 12px;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }
        
        .meta-info {
            margin-bottom: 20px;
            color: #666;
        }
        
        .action-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .btn-primary {
            background-color: #4285f4;
            color: white;
        }
        
        .btn-secondary {
            background-color: #f5913e;
            color: white;
        }
        
        .btn-back {
            background-color: #6c757d;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .summary-boxes {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .summary-box {
            flex: 1;
            min-width: 250px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
        }
        
        .box-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .box-value {
            font-size: 24px;
            font-weight: 700;
        }
        
        .box-value.expenses {
            color: #dc3545; /* Red */
        }
        
        .box-value.income {
            color: #28a745; /* Green */
        }
        
        .tables-container {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .table-section {
            flex: 1;
            min-width: 300px;
            background-color: #f0f7ff;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table-title {
            font-size: 18px;
            padding: 15px;
            background-color: #e0ecf9;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        .amount-col {
            text-align: right;
            font-weight: 500;
        }
        
        .total-row {
            border-top: 2px solid #ccc;
            font-weight: 600;
        }
        
        .text-red-500 {
            color: #dc3545;
        }
        
        .notes-section {
            margin-bottom: 30px;
            display: flex;
            gap: 20px;
        }
        
        .notes-box {
            flex: 2;
        }
        
        .auditor-box {
            flex: 1;
            text-align: right;
        }
        
        .notes-title, .auditor-title {
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .notes-content {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #4285f4;
        }
        
        .footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #666;
        }
        
        .society-address {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }
        
        .contact-info {
            text-align: right;
        }
        
        .no-report-container {
            text-align: center;
            padding: 100px 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
            margin: 50px auto;
            max-width: 600px;
        }
        
        .no-report-icon {
            font-size: 50px;
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        .no-report-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #343a40;
        }
        
        .no-report-message {
            font-size: 16px;
            color: #6c757d;
            margin-bottom: 30px;
        }
        
        .year-input-container {
            margin-top: 30px;
            margin-bottom: 20px;
        }
        
        .custom-year-input {
            padding: 10px 15px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            width: 100px;
            font-size: 16px;
            text-align: center;
        }

        .info-panel {
            max-width: 980px;
            margin: 0 auto;
            margin-top: 30px;
            margin-bottom: -30px;
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --hover-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .info-panel p {
            margin-bottom: 0.5rem;
        }

        
        @media print {
        /* Hide everything by default */
        body * {
            visibility: hidden;
        }
        
        /* Only show the report container and its children */
        #report-container, #report-container * {
            visibility: visible;
        }
        
        /* Position the report at the top of the page */
        #report-container {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
        }
        
        /* Hide specific elements we never want to print */
        .action-buttons, 
        .modern-nav,
        .year-selector,
        .info-panel,
        .no-report-container .btn {
            display: none !important;
        }
        
        /* Reset backgrounds to white */
        body, .home-container {
            background: white;
            padding: 0;
            margin: 0;
        }
        
        /* Make container fill page */
        .container {
            max-width: 100%;
            padding: 0;
            margin: 0;
        }
        
        /* Ensure colors print properly */
        .report-header {
            background-color: #f0f7ff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .table-section {
            background-color: #f0f7ff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .table-title {
            background-color: #e0ecf9 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
    </style>
</head>
<body>
    <div class="home-container">
    <?php include getNavbarTemplate(); ?>

    <div class="info-panel">
        <h2 style="color: #1e3c72;">Historical Financial Reports</h2>
        <p>Browse through previous years' financial statements by selecting a different year from the dropdown menu below.</p>
    </div>

    <div class="container" id="report-container">
        <div class="report-header">
            <img src="../assets/images/society_logo.png" alt="Society Logo" class="logo">
            <div class="society-name">එක්සත් මරණාධාර සමිතිය</div>
        </div>
        
        <?php if (empty($availableYears)): ?>
        
        <!-- No approved reports available -->
        <div class="no-report-container">
            <div class="no-report-icon">
                <i class="fas fa-file-alt"></i>
            </div>
            <h2 class="no-report-title">No Approved Reports Available</h2>
            <p class="no-report-message">There are currently no approved financial reports available. Reports will appear here once they have been reviewed and approved by the auditor.</p>
            
            <a href="<?php echo getBackUrl(); ?>" class="btn btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
        
        <?php elseif ($report === null): ?>
        
        <!-- No report for selected year -->
        <div class="no-report-container">
            <div class="no-report-icon">
                <i class="fas fa-search"></i>
            </div>
            <h2 class="no-report-title">No Approved Report for <?php echo $reportYear; ?></h2>
            <p class="no-report-message">There is no approved financial report available for the year <?php echo $reportYear; ?>. Please select another year from the list below.</p>
            
            <div class="year-input-container">
                <form method="get" action="yearEndReport.php" id="yearForm">
                    <select name="year" id="year" class="custom-year-input">
                        <?php foreach ($availableYears as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo ($year == $reportYear) ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> View Report
                    </button>
                </form>
            </div>
            
            <a href="<?php echo $_SESSION['report_referrer'] ?? '../index.php'; ?>" class="btn btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
        
        <?php else: ?>
        
        <!-- Display approved report -->
        <div class="report-title-container">
            <h1 class="report-title">Financial Audit Report</h1>
            <div class="year-selector">
                <form method="get" action="yearEndReport.php" id="yearForm">
                    <select name="year" id="year" onchange="document.getElementById('yearForm').submit();" class="year-select">
                        <?php foreach ($availableYears as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo ($year == $reportYear) ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>
        
        <div class="meta-info">
            <div>Generated By: <?php echo $report['treasurer']; ?></div>
            <div>Time: <?php echo $report['audit_time']; ?> Date: <?php echo $report['audit_date']; ?></div>
        </div>
        
        <div class="action-buttons">
            <a href="<?php echo $_SESSION['report_referrer'] ?? '../index.php'; ?>" class="btn btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button class="btn btn-primary" id="downloadBtn">
                <i class="fas fa-download"></i> Download
            </button>
            <button class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
        
        <div class="summary-boxes">
            <div class="summary-box">
                <div class="box-title">Total Revenue:</div>
                <div class="box-value revenue"><?php echo formatCurrency($report['total_revenue']); ?></div>
            </div>
            
            <div class="summary-box">
                <div class="box-title">Total Expenses:</div>
                <div class="box-value expenses"><?php echo formatCurrency($report['total_expenses']); ?></div>
            </div>
            
            <div class="summary-box" style="background-color: #d0e1f9;">
                <div class="box-title">Net Income:</div>
                <div class="box-value income"><?php echo formatCurrency($report['net_income']); ?></div>
            </div>
        </div>
        
        <div class="tables-container">
            <div class="table-section">
                <div class="table-title">Revenue</div>
                <table>
                    <tr>
                        <td>Balance on Hand</td>
                        <td class="amount-col"><?php echo formatCurrency($report['revenue']['balance_on_hand']); ?></td>
                    </tr>
                    <tr>
                        <td>Membership Fee</td>
                        <td class="amount-col"><?php echo formatCurrency($report['revenue']['membership_fee']); ?></td>
                    </tr>
                    <tr>
                        <td>New Membership Fee</td>
                        <td class="amount-col"><?php echo $report['revenue']['new_membership_fee'] > 0 ? formatCurrency($report['revenue']['new_membership_fee']) : '-'; ?></td>
                    </tr>
                    <tr>
                        <td>Loan Revenue</td>
                        <td class="amount-col"><?php echo formatCurrency($report['revenue']['loan_revenue']); ?></td>
                    </tr>
                    <tr>
                        <td>Interest Revenue</td>
                        <td class="amount-col <?php echo getAmountColor($report['revenue']['interest_revenue']); ?>"><?php echo formatCurrency($report['revenue']['interest_revenue']); ?></td>
                    </tr>
                    <tr>
                        <td>Fine Revenue</td>
                        <td class="amount-col"><?php echo $report['revenue']['fine_revenue'] > 0 ? formatCurrency($report['revenue']['fine_revenue']) : '-'; ?></td>
                    </tr>
                    <tr class="total-row">
                        <td>Total Revenue:</td>
                        <td class="amount-col <?php echo getAmountColor($report['total_revenue']); ?>"><?php echo formatCurrency($report['total_revenue']); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="table-section">
                <div class="table-title">Expenses</div>
                <table>
                    <tr>
                        <td>Death Welfare</td>
                        <td class="amount-col"><?php echo formatCurrency($report['expenses']['death_welfare']); ?></td>
                    </tr>
                    <tr>
                        <td>Furniture and Equipments</td>
                        <td class="amount-col"><?php echo formatCurrency($report['expenses']['furniture_equipment']); ?></td>
                    </tr>
                    <tr>
                        <td>Maintenance and Repairs</td>
                        <td class="amount-col"><?php echo $report['expenses']['maintenance_repairs'] > 0 ? formatCurrency($report['expenses']['maintenance_repairs']) : '-'; ?></td>
                    </tr>
                    <tr>
                        <td>Other</td>
                        <td class="amount-col"><?php echo $report['expenses']['other'] > 0 ? formatCurrency($report['expenses']['other']) : '-'; ?></td>
                    </tr>
                    <tr class="total-row">
                        <td>Total Expenses:</td>
                        <td class="amount-col"><?php echo formatCurrency($report['total_expenses']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="notes-section">
            <div class="notes-box">
                <div class="notes-title">Special Notes:</div>
                <div class="notes-content">
                    <?php echo htmlspecialchars($report['notes']); ?>
                </div>
            </div>
            
            <div class="auditor-box">
                <div class="auditor-title">Audited By:</div>
                <div><?php echo htmlspecialchars($report['auditor']); ?></div>
                <div>Time: <?php echo htmlspecialchars($report['audit_time']); ?> Date: <?php echo htmlspecialchars($report['audit_date']); ?></div>
            </div>
        </div>
        
        <hr>
        
        <div class="footer">
            <p>For any inquiry please contact authorized person</p>
            
            <div class="society-address">
                <div>
                    <div>එක්සත් මරණාධාර සමිතිය</div>
                    <div>Ekiriya,</div>
                    <div>Rikillagaskada.</div>
                </div>
                
                <div class="contact-info">
                    <div>Siril: 070 575 9757</div>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
        // Handle back button clicks using browser history
        // Handle back button clicks
document.querySelectorAll('.btn-back').forEach(function(button) {
    button.addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = "<?php echo getBackUrl(); ?>";
    });
});
        
        // PDF Download functionality
        <?php if ($report !== null): ?>
        document.getElementById('downloadBtn').addEventListener('click', function() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'mm', 'a4');
            
            // Hide buttons before capturing
            const buttons = document.querySelector('.action-buttons');
            const navbar = document.querySelector('.modern-nav');
            const yearSelector = document.querySelector('.year-selector');
            const infoPanel = document.querySelector('.info-panel'); 
            
            if (buttons) buttons.style.display = 'none';
            if (navbar) navbar.style.display = 'none';
            if (yearSelector) yearSelector.style.display = 'none';
            if (infoPanel) infoPanel.style.display = 'none';
            if (infoPanel) infoPanel.style.display = 'block';
            
            // Capture the page
            html2canvas(document.getElementById('report-container'), {
                scale: 2,
                useCORS: true,
                logging: true
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const imgWidth = 210; // A4 width in mm
                const pageHeight = 297; // A4 height in mm
                const imgHeight = canvas.height * imgWidth / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;
                
                doc.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
                
                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    doc.addPage();
                    doc.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }
                
                doc.save(`Financial_Audit_Report_${<?php echo $reportYear; ?>}.pdf`);
                
                // Restore buttons after download
                if (buttons) buttons.style.display = 'flex';
                if (navbar) navbar.style.display = 'block';
                if (yearSelector) yearSelector.style.display = 'block';
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>