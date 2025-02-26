<?php
    session_start();
    require_once "../../config/database.php";

    // Function to fetch report statistics based on FinancialReportVersions table
    function fetchReportStats() {
        $stats = [
            'pending' => 0,
            'reviewed' => 0,
            'approved' => 0
        ];

        try {
            // Get current term from Static table
            $termSql = "SELECT year FROM Static ORDER BY year DESC LIMIT 1";
            $termResult = Database::search($termSql);
            $currentTerm = $termResult ? $termResult->fetch_assoc()['year'] : date('Y');

            // Pending Reports Query
            $pendingSql = "SELECT COUNT(*) as total FROM FinancialReportVersions WHERE Status = 'pending' AND Term = $currentTerm";
            $pendingResult = Database::search($pendingSql);
            if ($pendingResult && $pendingResult->num_rows > 0) {
                $row = $pendingResult->fetch_assoc();
                $stats['pending'] = $row['total'];
            }

            // Reviewed Reports Query
            $reviewedSql = "SELECT COUNT(*) as total FROM FinancialReportVersions WHERE Status = 'reviewed' AND Term = $currentTerm";
            $reviewedResult = Database::search($reviewedSql);
            if ($reviewedResult && $reviewedResult->num_rows > 0) {
                $row = $reviewedResult->fetch_assoc();
                $stats['reviewed'] = $row['total'];
            }

            // Approved Reports Query
            $approvedSql = "SELECT COUNT(*) as total FROM FinancialReportVersions WHERE Status = 'approved' AND Term = $currentTerm";
            $approvedResult = Database::search($approvedSql);
            if ($approvedResult && $approvedResult->num_rows > 0) {
                $row = $approvedResult->fetch_assoc();
                $stats['approved'] = $row['total'];
            }
        } catch (Exception $e) {
            // Log error or handle as needed
            error_log("Error fetching report statistics: " . $e->getMessage());
        }

        return $stats;
    }

    // Fetch report statistics
    $reportStats = fetchReportStats();

    // Get current term
    $sql = "SELECT year FROM Static ORDER BY year DESC LIMIT 1";
    $result = Database::search($sql);
    $currentTerm = "2025"; // Default fallback
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $currentTerm = $row['year'];
    }

    // Fetch financial statistics for the current term
    function fetchFinancialStats($term) {
        $stats = [
            'membership_fee' => 0,
            'loans' => 0,
            'death_welfare' => 0,
            'fines' => 0
        ];

        try {
            // Membership Fee
            $membershipFeeSql = "SELECT SUM(Amount) as total FROM MembershipFee WHERE Term = $term AND IsPaid = 'Yes'";
            $membershipFeeResult = Database::search($membershipFeeSql);
            if ($membershipFeeResult && $membershipFeeResult->num_rows > 0) {
                $row = $membershipFeeResult->fetch_assoc();
                $stats['membership_fee'] = $row['total'] ?? 0;
            }

            // Loans
            $loanSql = "SELECT SUM(Amount) as total FROM Loan WHERE Term = $term AND Status = 'approved'";
            $loanResult = Database::search($loanSql);
            if ($loanResult && $loanResult->num_rows > 0) {
                $row = $loanResult->fetch_assoc();
                $stats['loans'] = $row['total'] ?? 0;
            }

            // Death Welfare
            $deathWelfareSql = "SELECT SUM(Amount) as total FROM DeathWelfare WHERE Term = $term AND Status = 'approved'";
            $deathWelfareResult = Database::search($deathWelfareSql);
            if ($deathWelfareResult && $deathWelfareResult->num_rows > 0) {
                $row = $deathWelfareResult->fetch_assoc();
                $stats['death_welfare'] = $row['total'] ?? 0;
            }

            // Fines
            $finesSql = "SELECT SUM(Amount) as total FROM Fine WHERE Term = $term AND IsPaid = 'Yes'";
            $finesResult = Database::search($finesSql);
            if ($finesResult && $finesResult->num_rows > 0) {
                $row = $finesResult->fetch_assoc();
                $stats['fines'] = $row['total'] ?? 0;
            }
        } catch (Exception $e) {
            // Log error or handle as needed
            error_log("Error fetching financial statistics: " . $e->getMessage());
        }

        return $stats;
    }

    // Fetch financial statistics for the current term
    $financialStats = fetchFinancialStats($currentTerm);
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <title>Auditor Dashboard</title>
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   <style>
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
           margin-top: 30px;
           margin-bottom: 2rem;
           box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
       }

       .status-badge {
           background: rgba(255, 255, 255, 0.2);
           padding: 0.8rem 1.5rem;
           border-radius: 50px;
           font-weight: bold;
       }

       .statistics-grid {
           margin: 0 auto 2rem auto;
           max-width: 850px;
           display: flex;
           gap: 1.5rem;
           justify-content: center;
       }

       .statistics-card {
           flex: 1;
           background: white;
           padding: 2rem;
           border-radius: 12px;
           text-align: center;
           transition: transform 0.2s, box-shadow 0.2s;
           box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
           cursor: pointer;
       }

       .statistics-card:hover {
           transform: translateY(-5px);
           box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
       }

       .statistics-card .icon {
           font-size: 2em;
           color: #1e3c72;
           margin-bottom: 1rem;
       }

       .stat-number {
           font-size: 2.5rem;
           font-weight: bold;
           color: #1e3c72;
           margin: 0.5rem 0;
       }

       .stat-label {
           color: #1e3c72;
           font-size: 1rem;
           font-weight: 500;
           text-transform: uppercase;
           letter-spacing: 1px;
       }

       .financial-details-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
           gap: 1.5rem;
           margin-bottom: 2rem;
           display: none; /* Hidden by default */
       }

       .financial-card {
           background: white;
           padding: 1.5rem;
           border-radius: 12px;
           text-align: center;
           transition: transform 0.2s, box-shadow 0.2s;
           box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
       }

       .financial-card:hover {
           transform: translateY(-5px);
           box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
       }

       .info-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
           gap: 1.5rem;
       }

       .info-card {
           background: white;
           padding: 1.5rem;
           border-radius: 12px;
           box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
       }

       .info-item {
           display: flex;
           justify-content: space-between;
           padding: 0.8rem 0;
           border-bottom: 1px solid #eee;
       }

       h1 {
           font-size: 1.8rem;
           margin: 0;
       }

       h2 {
           color: #1e3c72;
           margin-bottom: 1.5rem;
       }
       
       .icon {
           font-size: 2em;
           color: #1e3c72;
           margin-bottom: 1rem;
       }

       .statistics-card h3 {
           color: #1e3c72;
       }

       .show {
           display: grid !important;
       }
       .reports-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
           gap: 1.5rem;
           margin-bottom: 2rem;
       }

       .report-card {
           background: white;
           padding: 1.5rem;
           border-radius: 12px;
           text-align: center;
           transition: transform 0.2s, box-shadow 0.2s;
           box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
           cursor: pointer;
       }

       .report-card:hover {
           transform: translateY(-5px);
           box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
       }

       .report-card .report-icon {
           font-size: 2em;
           margin-bottom: 1rem;
       }

       .report-card.pending .report-icon {
           color: #ff9800;
       }

       .report-card.reviewed .report-icon {
           color: #2196f3;
       }

       .report-card.approved .report-icon {
           color: #4caf50;
       }

       .report-card .report-number {
           font-size: 2.5rem;
           font-weight: bold;
           margin: 0.5rem 0;
       }

       .report-card .report-label {
           text-transform: uppercase;
           letter-spacing: 1px;
           font-weight: 500;
       }
   </style>
</head>
<body>
   <div class="home-container">
       <?php include '../templates/navbar-admin.php'; ?>
       <div class="content">
           <div class="welcome-card">
               <h1>Welcome, Auditor</h1>
               <div class="status-badge">
                   Term <?php echo $currentTerm; ?>
               </div>
           </div>

           <!-- Reports Grid -->
            <div class="reports-grid">
                <div class="report-card pending" onclick="window.location.href='pending_reports.php?term=<?php echo $currentTerm; ?>'">
                    <i class="fas fa-clock report-icon" style="color: #1e3c72;"></i>
                    <div class="report-number"><?php echo $reportStats['pending']; ?></div>
                    <div class="report-label">Pending Reports</div>
                </div>
                <div class="report-card reviewed" onclick="window.location.href='reviewed_reports.php?term=<?php echo $currentTerm; ?>'">
                    <i class="fas fa-eye report-icon" style="color: #1e3c72;"></i>
                    <div class="report-number"><?php echo $reportStats['reviewed']; ?></div>
                    <div class="report-label">Reviewed Reports</div>
                </div>
                <div class="report-card approved" onclick="window.location.href='approved_reports.php?term=<?php echo $currentTerm; ?>'">
                    <i class="fas fa-check-circle report-icon" style="color: #1e3c72;"></i>
                    <div class="report-number"><?php echo $reportStats['approved']; ?></div>
                    <div class="report-label">Approved Reports</div>
                </div>
            </div>

           <!-- Main Actions -->
           <div class="statistics-grid">
               <div class="statistics-card" onclick="window.location.href='financialDetails.php?term=<?php echo $currentTerm; ?>'">
                   <i class="fas fa-file-alt icon"></i>
                   <h3>View Reports</h3>
               </div>
               <div class="statistics-card" onclick="window.location.href='financialDetails.php?term=<?php echo $currentTerm; ?>'">
                   <i class="fas fa-chart-line icon"></i>
                   <h3>View Financial Details</h3>
               </div>
           </div>

           <!-- Financial Details Grid
           <div class="financial-details-grid" id="financialDetailsGrid">
               <div class="financial-card">
                   <i class="fas fa-money-bill-wave icon"></i>
                   <h3>Membership Fee</h3>
                   <div class="stat-number">Rs. <?php echo number_format($financialStats['membership_fee'], 2); ?></div>
                   <div class="stat-label">Total Collected</div>
               </div>
               <div class="financial-card">
                   <i class="fas fa-hand-holding-usd icon"></i>
                   <h3>Loans</h3>
                   <div class="stat-number">Rs. <?php echo number_format($financialStats['loans'], 2); ?></div>
                   <div class="stat-label">Total Approved</div>
               </div>
               <div class="financial-card">
                   <i class="fas fa-heart icon"></i>
                   <h3>Death Welfare</h3>
                   <div class="stat-number">Rs. <?php echo number_format($financialStats['death_welfare'], 2); ?></div>
                   <div class="stat-label">Total Approved</div>
               </div>
               <div class="financial-card">
                   <i class="fas fa-exclamation-circle icon"></i>
                   <h3>Fines</h3>
                   <div class="stat-number">Rs. <?php echo number_format($financialStats['fines'], 2); ?></div>
                   <div class="stat-label">Total Collected</div>
               </div>
           </div> -->

           <!-- Info Grid -->
           <div class="info-grid">
               <div class="info-card recent-activities">
                   <h2>Recent Audit Activities</h2>
                   <div class="info-item">
                       <span>Monthly Report Review</span>
                       <span>Today, 10:30 AM</span>
                   </div>
                   <div class="info-item">
                       <span>Financial Statement Check</span>
                       <span>Yesterday</span>
                   </div>
                   <div class="info-item">
                       <span>Compliance Verification</span>
                       <span>2 days ago</span>
                   </div>
               </div>

               <div class="info-card system-stats">
                   <h2>Audit Status</h2>
                   <div class="info-item">
                       <span>Last Audit:</span>
                       <span><?php echo $currentTerm; ?>-02-10</span>
                   </div>
                   <div class="info-item">
                       <span>Next Scheduled Audit:</span>
                       <span><?php echo $currentTerm; ?>-03-10</span>
                   </div>
                   <div class="info-item">
                       <span>Pending Reviews:</span>
                       <span><?php echo $reportStats['pending']; ?></span>
                   </div>
               </div>
           </div>
       </div>
       <br><br>
       <?php include '../templates/footer.php'; ?>
   </div>

   <script>
//    function toggleFinancialDetails() {
//        const grid = document.getElementById('financialDetailsGrid');
//        grid.classList.toggle('show');
//    }
   </script>
</body>
</html>