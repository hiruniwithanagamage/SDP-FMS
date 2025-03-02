<?php
    session_start();
    require_once "../../config/database.php";

    // Function to fetch current term securely
    function getCurrentTerm() {
        try {
            $conn = getConnection();
            $stmt = $conn->prepare("SELECT year FROM Static ORDER BY year DESC LIMIT 1");
            
            if (!$stmt) {
                error_log("Prepare failed: " . $conn->error);
                return date('Y'); // Fallback to current year
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                return $row['year'];
            }
            
            $stmt->close();
            return date('Y'); // Fallback to current year
        } catch (Exception $e) {
            error_log("Error fetching current term: " . $e->getMessage());
            return date('Y');
        }
    }

    // Function to fetch report statistics securely
    function fetchReportStats($currentTerm) {
        $stats = [
            'pending' => 0,
            'reviewed' => 0,
            'approved' => 0
        ];

        try {
            $conn = getConnection();
            
            // Pending Reports
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM FinancialReportVersions WHERE Status = 'pending' AND Term = ?");
            $stmt->bind_param("i", $currentTerm);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stats['pending'] = $row['total'];
            }
            $stmt->close();

            // Reviewed Reports
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM FinancialReportVersions WHERE Status = 'reviewed' AND Term = ?");
            $stmt->bind_param("i", $currentTerm);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stats['reviewed'] = $row['total'];
            }
            $stmt->close();

            // Approved Reports
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM FinancialReportVersions WHERE Status = 'approved' AND Term = ?");
            $stmt->bind_param("i", $currentTerm);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stats['approved'] = $row['total'];
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error fetching report statistics: " . $e->getMessage());
        }

        return $stats;
    }

    // Function to fetch financial statistics securely
    function fetchFinancialStats($term) {
        $stats = [
            'membership_fee' => 0,
            'loans' => 0,
            'death_welfare' => 0,
            'fines' => 0
        ];

        try {
            $conn = getConnection();

            // Membership Fee
            $stmt = $conn->prepare("SELECT COALESCE(SUM(Amount), 0) as total FROM MembershipFee WHERE Term = ? AND IsPaid = 'Yes'");
            $stmt->bind_param("i", $term);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stats['membership_fee'] = $row['total'];
            }
            $stmt->close();

            // Loans
            $stmt = $conn->prepare("SELECT COALESCE(SUM(Amount), 0) as total FROM Loan WHERE Term = ? AND Status = 'approved'");
            $stmt->bind_param("i", $term);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stats['loans'] = $row['total'];
            }
            $stmt->close();

            // Death Welfare
            $stmt = $conn->prepare("SELECT COALESCE(SUM(Amount), 0) as total FROM DeathWelfare WHERE Term = ? AND Status = 'approved'");
            $stmt->bind_param("i", $term);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stats['death_welfare'] = $row['total'];
            }
            $stmt->close();

            // Fines
            $stmt = $conn->prepare("SELECT COALESCE(SUM(Amount), 0) as total FROM Fine WHERE Term = ? AND IsPaid = 'Yes'");
            $stmt->bind_param("i", $term);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stats['fines'] = $row['total'];
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error fetching financial statistics: " . $e->getMessage());
        }

        return $stats;
    }

    // Get current term
    $currentTerm = getCurrentTerm();

    // Fetch report statistics
    $reportStats = fetchReportStats($currentTerm);

    // Fetch financial statistics
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
                   Term <?php echo htmlspecialchars($currentTerm); ?>
               </div>
           </div>

           <!-- Reports Grid -->
            <div class="reports-grid">
                <div class="report-card pending" onclick="window.location.href='pending_reports.php?term=<?php echo urlencode($currentTerm); ?>'">
                    <i class="fas fa-clock report-icon" style="color: #1e3c72;"></i>
                    <div class="report-number"><?php echo htmlspecialchars($reportStats['pending']); ?></div>
                    <div class="report-label">Pending Reports</div>
                </div>
                <div class="report-card reviewed" onclick="window.location.href='reviewed_reports.php?term=<?php echo urlencode($currentTerm); ?>'">
                    <i class="fas fa-eye report-icon" style="color: #1e3c72;"></i>
                    <div class="report-number"><?php echo htmlspecialchars($reportStats['reviewed']); ?></div>
                    <div class="report-label">Reviewed Reports</div>
                </div>
                <div class="report-card approved" onclick="window.location.href='approved_reports.php?term=<?php echo urlencode($currentTerm); ?>'">
                    <i class="fas fa-check-circle report-icon" style="color: #1e3c72;"></i>
                    <div class="report-number"><?php echo htmlspecialchars($reportStats['approved']); ?></div>
                    <div class="report-label">Approved Reports</div>
                </div>
            </div>

           <!-- Main Actions -->
           <div class="statistics-grid">
               <div class="statistics-card" onclick="window.location.href='financialDetails.php?term=<?php echo urlencode($currentTerm); ?>'">
                   <i class="fas fa-file-alt icon"></i>
                   <h3>View Reports</h3>
               </div>
               <div class="statistics-card" onclick="window.location.href='financialDetails.php?term=<?php echo urlencode($currentTerm); ?>'">
                   <i class="fas fa-chart-line icon"></i>
                   <h3>View Financial Details</h3>
               </div>
           </div>

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
                       <span><?php echo htmlspecialchars($currentTerm . '-02-10'); ?></span>
                   </div>
                   <div class="info-item">
                       <span>Next Scheduled Audit:</span>
                       <span><?php echo htmlspecialchars($currentTerm . '-03-10'); ?></span>
                   </div>
                   <div class="info-item">
                       <span>Pending Reviews:</span>
                       <span><?php echo htmlspecialchars($reportStats['pending']); ?></span>
                   </div>
               </div>
           </div>
       </div>
       <br><br>
       <?php include '../templates/footer.php'; ?>
   </div>
</body>
</html>