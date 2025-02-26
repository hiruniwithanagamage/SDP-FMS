<?php
    session_start();
    require_once "../../config/database.php";
    // require_once "../config/session_auth.php";

    // // Verify this is an auditor accessing the page
    // verifySession('auditor');
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
   </style>
</head>
<body>
   <div class="home-container">
       <?php include '../templates/navbar-admin.php'; ?>
       <div class="content">
           <div class="welcome-card">
               <h1>Welcome, Auditor</h1>
               <div class="status-badge">
                   Term 2025
               </div>
           </div>

           <!-- Main Actions -->
           <div class="statistics-grid">
               <div class="statistics-card" onclick="window.location.href='view_reports.php'">
                   <i class="fas fa-file-alt icon"></i>
                   <h3>View Reports</h3>
               </div>
               <div class="statistics-card" onclick="toggleFinancialDetails()">
                   <i class="fas fa-chart-line icon"></i>
                   <h3>View Financial Details</h3>
               </div>
           </div>

           <!-- Financial Details Grid (Hidden by default) -->
           <div class="financial-details-grid" id="financialDetailsGrid">
               <div class="financial-card">
                   <i class="fas fa-money-bill-wave icon"></i>
                   <h3>Membership Fee</h3>
                   <div class="stat-number">$5,000</div>
                   <div class="stat-label">Total Collected</div>
               </div>
               <div class="financial-card">
                   <i class="fas fa-hand-holding-usd icon"></i>
                   <h3>Loans</h3>
                   <div class="stat-number">$25,000</div>
                   <div class="stat-label">Outstanding</div>
               </div>
               <div class="financial-card">
                   <i class="fas fa-heart icon"></i>
                   <h3>Death Welfare</h3>
                   <div class="stat-number">$10,000</div>
                   <div class="stat-label">Fund Balance</div>
               </div>
               <div class="financial-card">
                   <i class="fas fa-exclamation-circle icon"></i>
                   <h3>Fines</h3>
                   <div class="stat-number">$500</div>
                   <div class="stat-label">Total Collected</div>
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
                       <span>2024-02-10</span>
                   </div>
                   <div class="info-item">
                       <span>Next Scheduled Audit:</span>
                       <span>2024-03-10</span>
                   </div>
                   <div class="info-item">
                       <span>Pending Reviews:</span>
                       <span>3</span>
                   </div>
               </div>
           </div>
       </div>
       <br><br>
       <?php include '../templates/footer.php'; ?>
   </div>

   <script>
   function toggleFinancialDetails() {
       const grid = document.getElementById('financialDetailsGrid');
       grid.classList.toggle('show');
   }
   </script>
</body>
</html>