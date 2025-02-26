<?php
session_start();
require_once "../../config/database.php";

// Function to get total balance
function getTotalBalance() {
    $sql = "SELECT 
        (SELECT COALESCE(SUM(Amount), 0) FROM Payment) - 
        (SELECT COALESCE(SUM(Amount), 0) FROM Expenses) as total_balance";
    $result = Database::search($sql);
    $row = $result->fetch_assoc();
    return $row['total_balance'] ?? 0;
}

// Function to get pending loans count
function getPendingLoansCount() {
    $sql = "SELECT COUNT(*) as count FROM Loan WHERE Status = 'pending'";
    $result = Database::search($sql);
    $row = $result->fetch_assoc();
    return $row['count'] ?? 0;
}

// Function to get pending death welfare count
function getPendingWelfareCount() {
    $sql = "SELECT COUNT(*) as count FROM DeathWelfare WHERE Status = 'pending'";
    $result = Database::search($sql);
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
    
    return Database::search($sql);
}

// Function to get payment status counts
function getPaymentStatusCounts() {
    $sql = "SELECT
        (SELECT COUNT(*) FROM MembershipFee WHERE IsPaid = 'No') as pending_membership,
        (SELECT COUNT(*) FROM Fine WHERE IsPaid = 'No') as outstanding_fines,
        (SELECT COUNT(*) FROM Loan WHERE Status = 'pending') as pending_loans";
    
    $result = Database::search($sql);
    return $result->fetch_assoc();
}

// Get current term
function getCurrentTerm() {
    $sql = "SELECT year FROM Static ORDER BY year DESC LIMIT 1";
    $result = Database::search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
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
           margin: 15px 0 2rem;
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

       /* Action Cards Styles */
       .action-cards {
           display: grid;
           grid-template-columns: repeat(3, 1fr);
           gap: 1.5rem;
           margin-bottom: 2rem;
       }

       .action-card {
           background: white;
           padding: 2rem;
           border-radius: 12px;
           text-align: center;
           cursor: pointer;
           transition: all 0.3s ease;
           box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
           display: flex;
           flex-direction: column;
           align-items: center;
           justify-content: center;
           min-height: 200px;
       }

       .action-card:hover {
           transform: translateY(-5px);
           box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
           background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
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

       .icon {
           font-size: 2.5em;
           color: #1e3c72;
           margin-bottom: 1rem;
           transition: transform 0.3s ease;
       }

       .action-card:hover .icon {
           transform: scale(1.1);
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

       .action-card h3 {
           color: #1e3c72;
           margin-top: 1rem;
           font-size: 1.2rem;
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

       .category-section {
            margin-bottom: 3rem;
        }

        .category-title {
            color: #1e3c72;
            font-size: 1.6rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e0e0e0;
        }

        .category-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1rem;
        }

        /* Update media queries */
        @media (max-width: 768px) {
            .category-cards {
                grid-template-columns: 1fr;
            }
        }

       @media (max-width: 1200px) {
           .action-cards {
               grid-template-columns: repeat(2, 1fr);
           }
       }

       @media (max-width: 992px) {
           .statistics-grid {
                flex-wrap: wrap;
           }
           
           .status-cards {
                flex: 1 1 45%;
           }
           
           .status-card {
               flex: 1;
           }

           .add-fine-btn {
                order: 4;
                width: auto;
                flex: 1 1 45%;
            }
       }

       @media (max-width: 768px) {
           .action-cards {
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

        .management-buttons {
    max-width: 1200px;
    margin: 2rem auto;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}

.management-btn {
    background: #1e3c72;
    color: white;
    padding: 1rem;
    border: none;
    border-radius: 8px;
    font-size: 1.1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    justify-content: space-between;
    align-items: center;
    height: 100%;
    min-height: 60px;
}

.management-btn:hover {
    background: #2a5298;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.management-btn.active {
    background: #2a5298;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.rotate-icon {
    display: inline-block;
    transition: transform 0.3s ease;
}

.rotate-icon.active {
    transform: rotate(180deg);
}

.category-section {
    display: none;
    opacity: 0;
    transition: opacity 0.3s ease;
    margin-top: 2rem;
}

.category-section.show {
    display: block;
    opacity: 1;
}

.add-fine-btn {
    text-align: center;
    padding: 2.5rem;
    background: #1e3c72;
    color: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    cursor: pointer;
    transition: all 0.3s ease;
}

.add-fine-btn:hover {
    background: #2a5298;
    transform: translateY(-3px);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
}

@media (max-width: 768px) {
    .management-buttons {
        grid-template-columns: 1fr;
    }
    
    .management-btn {
        width: 100%;
    }
}

        /* Update welcome-card styles */
        .welcome-card {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 30px 0;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        @media (max-width: 768px) {
            .welcome-card {
                flex-direction: column;
                text-align: center;
                gap: 1.5rem;
            }
            
            .term-button {
                width: 100%;
                justify-content: center;
            }
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

           <!-- Statistics Cards -->
           <div class="statistics-grid">
            <div class="balance-card">
                    <i class="fas fa-money-bill-wave icon"></i>
                    <div class="stat-number">Rs.<?php echo number_format($totalBalance, 2); ?></div>
                    <div class="stat-label">Total Balance</div>
                </div>

                <button onclick="window.location.href='addFine.php'" class="add-fine-btn">
                    <i class="fas fa-plus"></i>
                    Add Fine
                </button>
               
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

           <div class="management-buttons">
    <button class="management-btn" onclick="toggleSection('financial')">
        Financial Management
        <i class="fas fa-chevron-down rotate-icon" id="financial-icon"></i>
    </button>
    
    <button class="management-btn" onclick="toggleSection('expense')">
        Expense Management
        <i class="fas fa-chevron-down rotate-icon" id="expense-icon"></i>
    </button>
    
    <button class="management-btn" onclick="toggleSection('reports')">
        Reports & Analytics
        <i class="fas fa-chevron-down rotate-icon" id="reports-icon"></i>
    </button>
</div>

<div id="financial-section" class="category-section">
    <div class="category-cards">
        <div class="action-card" onclick="window.location.href='financialManagement/membershipFee.php'">
            <i class="fas fa-id-card icon"></i>
            <h3>Membership Fee Management</h3>
        </div>
        <div class="action-card" onclick="window.location.href='financialManagement/loan.php'">
            <i class="fas fa-landmark icon"></i>
            <h3>Loan Management</h3>
        </div>
        <div class="action-card" onclick="window.location.href='financialManagement/deathWelfare.php'">
            <i class="fas fa-heart icon"></i>
            <h3>Death Welfare Management</h3>
        </div>
        <div class="action-card" onclick="window.location.href='financialManagement/fine.php'">
            <i class="fas fa-gavel icon"></i>
            <h3>Fine Management</h3>
        </div>
    </div>
</div>

<div id="expense-section" class="category-section">
    <div class="category-cards">
        <div class="action-card" onclick="window.location.href='financialManagement/addExpenses.php'">
            <i class="fas fa-file-invoice-dollar icon"></i>
            <h3>Add Expenses</h3>
        </div>
        <div class="action-card" onclick="window.location.href='financialManagement/trackExpenses.php'">
            <i class="fas fa-tags icon"></i>
            <h3>Track Expenses</h3>
        </div>
    </div>
</div>

<div id="reports-section" class="category-section">
    <div class="category-cards">
        <div class="action-card" onclick="window.location.href='financial_reports.php'">
            <i class="fas fa-chart-line icon"></i>
            <h3>Financial Reports</h3>
        </div>
        <div class="action-card" onclick="window.location.href='member_reports.php'">
            <i class="fas fa-users icon"></i>
            <h3>Member Reports</h3>
        </div>
        <div class="action-card" onclick="window.location.href='audit_reports.php'">
            <i class="fas fa-clipboard-check icon"></i>
            <h3>Audit Reports</h3>
        </div>
    </div>
</div>
           <!-- Info Grid -->
           <div class="info-grid">
                <div class="info-card">
                    <h2>Recent Transactions</h2>
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
function toggleSection(sectionName) {
    const section = document.getElementById(`${sectionName}-section`);
    const icon = document.getElementById(`${sectionName}-icon`);
    const allSections = document.querySelectorAll('.category-section');
    const allIcons = document.querySelectorAll('.rotate-icon');
    
    // Close all other sections
    allSections.forEach(s => {
        if (s.id !== `${sectionName}-section`) {
            s.classList.remove('show');
        }
    });
    
    // Reset all icons
    allIcons.forEach(i => {
        if (i.id !== `${sectionName}-icon`) {
            i.classList.remove('active');
        }
    });
    
    // Toggle current section
    section.classList.toggle('show');
    icon.classList.toggle('active');
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