<?php
    session_start();
    require_once "../../config/database.php";

    // Function to fetch current term securely
    function getCurrentTerm() {
        try {
            $stmt = prepare("SELECT year FROM Static WHERE status = 'active'");
            
            if (!$stmt) {
                error_log("Prepare failed: " . error);
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
            $stmt = prepare("SELECT COUNT(*) as total FROM FinancialReportVersions WHERE Status = 'pending' AND Term = ?");
            $stmt->bind_param("i", $currentTerm);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stats['pending'] = $row['total'];
            }
            $stmt->close();

            // Reviewed Reports
            $stmt = prepare("SELECT COUNT(*) as total FROM FinancialReportVersions WHERE Status = 'reviewed' AND Term = ?");
            $stmt->bind_param("i", $currentTerm);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stats['reviewed'] = $row['total'];
            }
            $stmt->close();

            // Approved Reports
            $stmt = prepare("SELECT COUNT(*) as total FROM FinancialReportVersions WHERE Status = 'approved' AND Term = ?");
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

    // Get current term
    $currentTerm = getCurrentTerm();

    // Fetch report statistics
    $reportStats = fetchReportStats($currentTerm);
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <title>Auditor Dashboard</title>
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   <style>
       :root {
           --primary-color: #1e3c72;
           --primary-light: #4e70aa;
           --secondary-color: #2a5298;
           --accent-color: #4caf50;
           --warning-color: #ff9800;
           --danger-color: #f44336;
           --light-color: #f5f7fa;
           --dark-color: #333;
           --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
           --hover-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
           --border-radius: 12px;
       }

       * {
           box-sizing: border-box;
           margin: 0;
           padding: 0;
       }

       body {
           font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
           background: var(--light-color);
           color: var(--dark-color);
           line-height: 1.6;
       }

       .home-container {
           min-height: 100vh;
           padding: 2rem;
           display: flex;
           flex-direction: column;
       }

       .content {
           max-width: 1200px;
           margin: 0 auto;
           width: 100%;
           flex: 1;
       }

       .welcome-card {
           background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
           color: white;
           padding: 2rem;
           border-radius: var(--border-radius);
           display: flex;
           justify-content: space-between;
           align-items: center;
           margin-top: 30px;
           margin-bottom: 2rem;
           box-shadow: var(--shadow);
       }

       .status-badge {
           background: rgba(255, 255, 255, 0.2);
           padding: 0.8rem 1.5rem;
           border-radius: 50px;
           font-weight: bold;
       }

       .dashboard-grid {
           display: grid;
           grid-template-areas: 
               "pending pending pending"
               "reviewed approved financial";
           grid-template-columns: repeat(3, 1fr);
           gap: 1.5rem;
           margin-bottom: 2rem;
       }
       
       .dashboard-grid .pending-card {
           grid-area: pending;
           margin-top: -5px;
       }
       
       .dashboard-grid .reviewed-card {
           grid-area: reviewed;
       }
       
       .dashboard-grid .approved-card {
           grid-area: approved;
       }
       
       .dashboard-grid .financial-card {
           grid-area: financial;
       }

       .dashboard-card {
           background: white;
           padding: 1rem;
           border-radius: var(--border-radius);
           box-shadow: var(--shadow);
           transition: transform 0.3s ease, box-shadow 0.3s ease;
           height: 100%;
           display: flex;
           flex-direction: column;
           align-items: center;
           justify-content: center;
           text-align: center;
       }

       .dashboard-card:hover {
           transform: translateY(-5px);
           box-shadow: var(--hover-shadow);
       }

       .card-icon {
           font-size: 2.5rem;
           color: var(--primary-color);
           margin-top: 1rem;
           margin-bottom: 1.5rem;
       }

       .card-title {
           font-size: 1.2rem;
           font-weight: 600;
           margin-bottom: 1rem;
           color: var(--primary-color);
       }

       .card-count {
           font-size: 2.5rem;
           font-weight: bold;
           margin-bottom: 1rem;
           color: var(--primary-color);
       }

       .card-message {
           margin-bottom: 1.5rem;
           line-height: 1.5;
       }

       .pending-button {
           display: inline-block;
           padding: 0.8rem 1.5rem;
           background-color: var(--primary-color);
           color: white;
           text-decoration: none;
           border-radius: 50px;
           font-weight: 600;
           transition: background-color 0.3s ease;
           border: none;
           cursor: pointer;
           width: 100%;
           max-width: 250px;
       }

       .pending-button:hover {
           background-color: var(--primary-light);
       }

       .pending-button.warning {
           background-color: var(--warning-color);
       }

       .pending-button.warning:hover {
           background-color: #e68a00;
       }

       .info-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
           gap: 1.5rem;
           margin-bottom: 2rem;
       }

       .info-card {
           background: white;
           padding: 1.5rem;
           border-radius: var(--border-radius);
           box-shadow: var(--shadow);
       }

       .info-card h2 {
           color: var(--primary-color);
           margin-bottom: 1.5rem;
           font-size: 1.5rem;
       }

       .info-item {
           display: flex;
           justify-content: space-between;
           padding: 0.8rem 0;
           border-bottom: 1px solid #eee;
       }

       .info-item:last-child {
           border-bottom: none;
       }

       h1 {
           font-size: 1.8rem;
           margin: 0;
       }

       @media (max-width: 768px) {
           .dashboard-grid {
               grid-template-areas: 
                   "pending"
                   "reviewed"
                   "approved"
                   "financial";
               grid-template-columns: 1fr;
           }

           .welcome-card {
               flex-direction: column;
               text-align: center;
               gap: 1rem;
           }
       }
   </style>
</head>
<body>
   <div class="home-container">
       <?php include '../templates/navbar-auditor.php'; ?>
       <div class="content">
           <div class="welcome-card">
               <h1>Welcome, Auditor</h1>
               <div class="status-badge">
                   Term <?php echo htmlspecialchars($currentTerm); ?>
               </div>
           </div>

           <!-- Main Dashboard Grid -->
           <div class="dashboard-grid">
               <!-- Pending Reports Card -->
               <div class="dashboard-card pending-card">
                   <div style="display: flex; align-items: center; gap: 2rem;">
                       <i class="fas fa-clipboard-check card-icon" style="font-size: 3.5rem; margin: 0;"></i>
                       <div style="text-align: left;">
                           <h3 class="card-title" style="font-size: 1.5rem; margin-bottom: 0.5rem;">Pending Reports</h3>
                           <?php if ($reportStats['pending'] > 0): ?>
                               <p class="card-message" style="margin-bottom: 0;">
                                   You have <strong><?php echo htmlspecialchars($reportStats['pending']); ?></strong> pending 
                                   report<?php echo $reportStats['pending'] > 1 ? 's' : ''; ?> that require your review.
                               </p>
                           <?php else: ?>
                               <p class="card-message" style="margin-bottom: 0;">No pending reports available at this time. All reports have been reviewed.</p>
                           <?php endif; ?>
                       </div>
                       <div style="margin-left: auto;">
                           <?php if ($reportStats['pending'] > 0): ?>
                               <a href="pendingReports.php?term=<?php echo urlencode($currentTerm); ?>" class="pending-button warning" style="white-space: nowrap;">
                                   <i class="fas fa-exclamation-circle"></i> View Pending Reports
                               </a>
                           <?php else: ?>
                               <button class="pending-button" disabled style="white-space: nowrap;">No Pending Reports</button>
                           <?php endif; ?>
                       </div>
                   </div>
               </div>

               <!-- Reviewed Reports Card -->
               <a href="reviewedReports.php?term=<?php echo urlencode($currentTerm); ?>" style="text-decoration: none; color: inherit;">
                   <div class="dashboard-card reviewed-card">
                       <i class="fas fa-eye card-icon"></i>
                       <h3 class="card-title">Reviewed Reports</h3>
                       <div class="card-count"><?php echo htmlspecialchars($reportStats['reviewed']); ?></div>
                   </div>
               </a>

               <!-- View Approved Reports Card -->
               <a href="../../reports/yearEndReport.php" style="text-decoration: none; color: inherit;">
                   <div class="dashboard-card approved-card">
                       <i class="fas fa-check-circle card-icon"></i>
                       <h3 class="card-title">All Approved Reports</h3>
                   </div>
               </a>

               <!-- View Financial Details Card -->
               <a href="../treasurer/dashboard.php?term=<?php echo urlencode($currentTerm); ?>" style="text-decoration: none; color: inherit;">
                   <div class="dashboard-card financial-card">
                       <i class="fas fa-chart-line card-icon"></i>
                       <h3 class="card-title">Financial Details</h3>
                   </div>
               </a>
           </div>

           <!-- Info Grid -->
           <!-- <div class="info-grid">
               <div class="info-card">
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

               <div class="info-card">
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
           </div> -->
       </div>
       <?php include '../templates/footer.php'; ?>
   </div>
</body>
</html>