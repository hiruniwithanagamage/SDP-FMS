<?php
session_start();
require_once "../../config/database.php";

// Get current term
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE status = 'active'";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Function to get total balance
function getTotalBalance() {
    $currentTerm = getCurrentTerm();
    $previousTerm = $currentTerm - 1;
    
    // First check if there's an approved financial report for the previous term
    $checkReportSql = "SELECT Net_Income FROM FinancialReportVersions 
                       WHERE Term = '$previousTerm' AND Status = 'approved'";
    $reportResult = search($checkReportSql);
    
    // If there's no approved report for previous term, don't subtract anything
    if ($reportResult->num_rows == 0) {
        $sql = "SELECT 
            (SELECT COALESCE(SUM(Amount), 0) FROM Payment WHERE Term = '$currentTerm') - 
            (SELECT COALESCE(SUM(Amount), 0) FROM Expenses WHERE Term = '$currentTerm') 
            as total_balance";
    } else {
        // If there is an approved report, include it in the calculation
        $sql = "SELECT 
            (SELECT COALESCE(Net_Income, 0) FROM FinancialReportVersions 
            WHERE Term = '$previousTerm' AND Status = 'approved') +
            (SELECT COALESCE(SUM(Amount), 0) FROM Payment WHERE Term = '$currentTerm') - 
            (SELECT COALESCE(SUM(Amount), 0) FROM Expenses WHERE Term = '$currentTerm')
            as total_balance";
    }
    
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['total_balance'] ?? 0;
}

// Function to get pending loans count
function getPendingLoansCount() {
    $sql = "SELECT COUNT(*) as count FROM Loan WHERE Status = 'pending'";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['count'] ?? 0;
}

// Function to get pending death welfare count
function getPendingWelfareCount() {
    $sql = "SELECT COUNT(*) as count FROM DeathWelfare WHERE Status = 'pending'";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['count'] ?? 0;
}

// Function to get recent transactions
function getRecentTransactions() {
    $sql = "SELECT 
        'Membership Fee' as type,
        p.Date as date,
        p.Amount as amount,
        m.Name as member_name
        FROM Payment p
        JOIN Member m ON p.Member_MemberID = m.MemberID
        WHERE p.Payment_Type = 'Membership Fee'
        UNION
        SELECT 
        'Fine' as type,
        f.Date as date,
        f.Amount as amount,
        m.Name as member_name
        FROM Fine f
        JOIN Member m ON f.Member_MemberID = m.MemberID
        UNION
        SELECT 
        'Loan' as type,
        l.Issued_Date as date,
        l.Amount as amount,
        m.Name as member_name
        FROM Loan l
        JOIN Member m ON l.Member_MemberID = m.MemberID
        WHERE l.Status = 'approved'
        ORDER BY date DESC
        LIMIT 3";
    
    return search($sql);
}

// Function to get payment status counts
function getPaymentStatusCounts() {
    $sql = "SELECT
        (SELECT COUNT(*) FROM MembershipFee WHERE IsPaid = 'No') as pending_membership,
        (SELECT COUNT(*) FROM Fine WHERE IsPaid = 'No') as outstanding_fines,
        (SELECT COUNT(*) FROM Loan WHERE Status = 'pending') as pending_loans";
    
    $result = search($sql);
    return $result->fetch_assoc();
}

// Fetch all the data
$totalBalance = getTotalBalance();
$pendingLoans = getPendingLoansCount();
$pendingWelfare = getPendingWelfareCount();
$recentTransactions = getRecentTransactions();
$paymentStatus = getPaymentStatusCounts();
$currentTerm = getCurrentTerm();
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <title>Treasurer Dashboard</title>
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

       .home-container {
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
           margin: 35px 0 2rem;
           box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
       }

       .status-badge {
           background: rgba(255, 255, 255, 0.2);
           padding: 0.8rem 1.5rem;
           border-radius: 50px;
           font-weight: bold;
           backdrop-filter: blur(5px);
       }

       /* Updated Statistics Grid Styles */
       .statistics-grid {
           display: flex;
           flex-wrap: nowrap;
           align-items: stretch;
           gap: 15px;
           margin-bottom: 30px;
       }

       .balance-card {
            flex: 1;
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

       .management-dropdown {
           display: flex;
           flex-direction: column;
           gap: 1rem;
           flex: 1;
       }

       .dropdown-container {
           background: white;
           padding: 1.2rem;
           border-radius: 12px;
           transition: all 0.3s ease;
           box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
           cursor: pointer;
       }

       .dropdown-header {
           display: flex;
           justify-content: space-between;
           align-items: center;
       }

       .dropdown-icon {
           font-size: 1.5em;
           color: #1e3c72;
       }

       .dropdown-title {
           font-size: 1.1rem;
           font-weight: 600;
           color: #1e3c72;
       }

       .dropdown-content {
           max-height: 0;
           overflow: hidden;
           transition: max-height 0.3s ease;
       }

       .dropdown-content.show {
           max-height: 500px;
           margin-top: 1rem;
       }

       .dropdown-item {
           padding: 0.8rem;
           border-bottom: 1px solid #eee;
           display: flex;
           align-items: center;
           gap: 0.5rem;
           transition: background-color 0.3s ease;
       }

       .dropdown-item:last-child {
           border-bottom: none;
       }

       .dropdown-item:hover {
           background-color: #f8f9fa;
       }

       .dropdown-item i {
           color: #1e3c72;
           width: 20px;
           text-align: center;
       }

       .status-cards {
           display: flex;
           flex-direction: column;
           gap: 1rem;
       }

       .status-card {
           background: white;
           padding: 1.2rem;
           border-radius: 12px;
           transition: all 0.3s ease;
           box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
           cursor: pointer;
           display: flex;
           align-items: center;
           gap: 1rem;
       }

       .status-card:hover {
           transform: translateY(-3px);
           box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
           background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
       }

       .status-icon {
           font-size: 2em;
           color: #1e3c72;
       }

       .status-content {
           flex: 1;
       }

       .status-card .stat-number {
           font-size: 1.8rem;
           margin: 0;
       }

       .status-card .stat-label {
           font-size: 0.85rem;
       }

       .action-buttons {
           display: grid;
           grid-template-columns: repeat(4, 1fr);
           gap: 1.5rem;
           margin-bottom: 2rem;
       }

       .action-btn {
           background: #1e3c72;
           color: white;
           padding: 1.2rem;
           border: none;
           border-radius: 8px;
           font-size: 1.1rem;
           cursor: pointer;
           transition: all 0.3s ease;
           display: flex;
           justify-content: center;
           align-items: center;
           gap: 0.5rem;
       }

       .action-btn:hover {
           background: #2a5298;
           transform: translateY(-2px);
           box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
       }

       .info-grid {
           display: grid;
           grid-template-columns: repeat(2, 1fr);
           gap: 2rem;
           margin-top: 2rem;
       }

       .info-card {
           background: white;
           padding: 1.5rem;
           border-radius: 12px;
           box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
       }

       .info-item {
           display: flex;
           justify-content: space-between;
           padding: 1rem;
           border-bottom: 1px solid #eee;
           transition: background-color 0.3s ease;
       }

       .info-item:hover {
           background-color: #f8f9fa;
       }

       .info-item:last-child {
           border-bottom: none;
       }

       .icon {
           font-size: 1.2em;
           margin-right: 0.5rem;
       }

       h1 {
           font-size: 1.8rem;
           margin: 0;
       }

       h2 {
           color: #1e3c72;
           margin-bottom: 1.5rem;
           font-size: 1.4rem;
       }

       .stat-number {
           font-size: 2.5rem;
           font-weight: bold;
           color: #1e3c72;
           margin: 0.5rem 0;
       }

       .stat-label {
           color: #666;
           font-size: 0.9rem;
           font-weight: 500;
           text-transform: uppercase;
           letter-spacing: 1px;
       }

       .rotate-icon {
           display: inline-block;
           transition: transform 0.3s ease;
       }

       .rotate-icon.active {
           transform: rotate(180deg);
       }

       .term-button {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .term-button:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .term-button i {
            font-size: 1.1em;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }
        
        .success-message .close-icon {
            cursor: pointer;
            color: #155724;
            font-size: 20px;
            background: none;
            border: none;
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
        }
        
        .success-message .close-icon:hover {
            color: #0b2e13;
        }

        /* Responsive styles */
        @media (max-width: 992px) {
           .statistics-grid {
                flex-wrap: wrap;
           }
           
           .action-buttons {
               grid-template-columns: repeat(2, 1fr);
           }
       }

       @media (max-width: 768px) {
           .action-buttons {
               grid-template-columns: 1fr;
           }
           
           .info-grid {
               grid-template-columns: 1fr;
           }
           
           .welcome-card {
               flex-direction: column;
               text-align: center;
               gap: 1rem;
           }
           
           .status-cards {
               flex-direction: column;
           }
           
           .term-button {
                width: 100%;
                justify-content: center;
            }
       }
   </style>
</head>
<body>
   <div class="home-container">
       <?php include '../templates/navbar-treasurer.php'; ?>
       <div class="content">

       <div class="container">
            <?php if(isset($_SESSION['success_message'])): ?>
                <div class="success-message" id="success-message">
                    <?php 
                        echo htmlspecialchars($_SESSION['success_message']);
                        unset($_SESSION['success_message']);
                    ?>
                    <button class="close-icon" onclick="closeSuccessMessage()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <div class="welcome-card">
                <h1>Welcome, Treasurer</h1>
                <a href="termDetails.php" class="term-button">
                    Term <?php echo htmlspecialchars($currentTerm); ?>
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>

            <!-- Action Buttons (Moved from management-buttons) -->
            <div class="action-buttons">
                <button class="action-btn" onclick="window.location.href='addFine.php'">
                    <i class="fas fa-plus icon"></i>
                    Add Fine
                </button>
                
                <button class="action-btn" onclick="window.location.href='addExpenses.php'">
                    <i class="fas fa-plus icon"></i>
                    Add Expenses
                </button>
            </div>

            <!-- Statistics Cards -->
            <div class="statistics-grid">
                <div class="balance-card">
                    <i class="fas fa-money-bill-wave icon"></i>
                    <div class="stat-number">Rs.<?php echo number_format($totalBalance, 2); ?></div>
                    <div class="stat-label">Total Balance</div>
                </div>
                
                <!-- Management Dropdowns (Moved from add-buttons) -->
                <div class="management-dropdown">
                    <div class="dropdown-container" onclick="toggleDropdown('financial-dropdown')">
                        <div class="dropdown-header">
                            <div class="dropdown-title">
                                <i class="fas fa-chart-pie dropdown-icon"></i>
                                Financial Management
                            </div>
                            <i class="fas fa-chevron-down rotate-icon" id="financial-icon"></i>
                        </div>
                        <div class="dropdown-content" id="financial-dropdown">
                            <div class="dropdown-item" onclick="window.location.href='financialManagement/membershipFee.php'">
                                <i class="fas fa-id-card"></i>
                                Membership Fee Management
                            </div>
                            <div class="dropdown-item" onclick="window.location.href='financialManagement/loan.php'">
                                <i class="fas fa-landmark"></i>
                                Loan Management
                            </div>
                            <div class="dropdown-item" onclick="window.location.href='financialManagement/deathWelfare.php'">
                                <i class="fas fa-heart"></i>
                                Death Welfare Management
                            </div>
                            <div class="dropdown-item" onclick="window.location.href='financialManagement/fine.php'">
                                <i class="fas fa-gavel"></i>
                                Fine Management
                            </div>
                            <div class="dropdown-item" onclick="window.location.href='financialManagement/payment.php'">
                                <i class="fas fa-money-bill-wave"></i>
                                Payment Management
                            </div>
                            <div class="dropdown-item" onclick="window.location.href='financialManagement/trackExpenses.php'">
                                <i class="fas fa-tags"></i>
                                Track Expenses
                            </div>
                        </div>
                    </div>
                    
                    <div class="dropdown-container" onclick="toggleDropdown('reports-dropdown')">
                        <div class="dropdown-header">
                            <div class="dropdown-title">
                                <i class="fas fa-chart-line dropdown-icon"></i>
                                Reports & Analytics
                            </div>
                            <i class="fas fa-chevron-down rotate-icon" id="reports-icon"></i>
                        </div>
                        <div class="dropdown-content" id="reports-dropdown">
                            <div class="dropdown-item" onclick="window.location.href='reportsAnalytics/financialReports.php'">
                                <i class="fas fa-chart-line"></i>
                                Year End Report
                            </div>
                            <div class="dropdown-item" onclick="window.location.href='reportsAnalytics/memberFinancialSummary.php'">
                                <i class="fas fa-users"></i>
                                Member Reports
                            </div>
                            <div class="dropdown-item" onclick="window.location.href='dashboard.php'">
                                <i class="fas fa-clipboard-check"></i>
                                Financial Reports
                            </div>
                        </div>
                    </div>
                </div>
               
                <div class="status-cards">
                    <div class="status-card" onclick="window.location.href='pendingLoans.php'">
                        <i class="fas fa-landmark status-icon"></i>
                        <div class="status-content">
                            <div class="stat-number"><?php echo $pendingLoans; ?></div>
                            <div class="stat-label">Pending Loans
                                <i class="fas fa-chevron-right"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="status-card" onclick="window.location.href='pendingWelfare.php'">
                        <i class="fas fa-heart status-icon"></i>
                        <div class="status-content">
                            <div class="stat-number"><?php echo $pendingWelfare; ?></div>
                            <div class="stat-label">Pending Death Welfare
                                <i class="fas fa-chevron-right"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Info Grid -->
            <div class="info-grid">
                <div class="info-card">
                    <h2>Recent Activities</h2>
                    <?php while($transaction = $recentTransactions->fetch_assoc()): ?>
                        <div class="info-item">
                            <span>
                                <i class="fas fa-circle" style="color: #1e3c72; font-size: 0.5em; margin-right: 10px;"></i>
                                <?php echo htmlspecialchars($transaction['type']); ?> - 
                                <?php echo htmlspecialchars($transaction['member_name']); ?>
                            </span>
                            <span><?php echo date('M d, Y', strtotime($transaction['date'])); ?></span>
                        </div>
                    <?php endwhile; ?>
                </div>

                <div class="info-card">
                    <h2>Payment Status</h2>
                    <div class="info-item">
                        <span>Pending Membership Fees</span>
                        <span class="status-badge" style="background: #1e3c72; font-size: 0.9em; color:white;">
                            <?php echo $paymentStatus['pending_membership']; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span>Outstanding Fines</span>
                        <span class="status-badge" style="background: #1e3c72; font-size: 0.9em; color:white;">
                            <?php echo $paymentStatus['outstanding_fines']; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span>Pending Loan Approvals</span>
                        <span class="status-badge" style="background: #1e3c72; font-size: 0.9em; color:white;">
                            <?php echo $paymentStatus['pending_loans']; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <br><br>
       </div>
       <?php include '../templates/footer.php'; ?>
   </div>

   <script>
        function toggleDropdown(dropdownId) {
            const dropdown = document.getElementById(dropdownId);
            const icon = dropdown.previousElementSibling.querySelector('.rotate-icon');
            
            // Close all other dropdowns
            const allDropdowns = document.querySelectorAll('.dropdown-content');
            const allIcons = document.querySelectorAll('.dropdown-header .rotate-icon');
            
            allDropdowns.forEach(function(item) {
                if (item.id !== dropdownId) {
                    item.classList.remove('show');
                }
            });
            
            allIcons.forEach(function(item) {
                if (item !== icon) {
                    item.classList.remove('active');
                }
            });
            
            // Toggle current dropdown
            dropdown.classList.toggle('show');
            icon.classList.toggle('active');
            
            // Prevent the click from bubbling up to parent elements
            event.stopPropagation();
        }

        function closeSuccessMessage() {
            const successMessage = document.getElementById('success-message');
            if (successMessage) {
                successMessage.style.display = 'none';
            }
        }

        // Optional: Auto-hide the success message after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const successMessage = document.getElementById('success-message');
            if (successMessage) {
                setTimeout(function() {
                    successMessage.style.display = 'none';
                }, 5000);
            }
        });
   </script>
</body>
</html>