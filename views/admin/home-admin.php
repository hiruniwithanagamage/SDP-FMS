<?php
    session_start();
    require_once "../../config/database.php";

    // Get total members count using prepared statement
    $totalMembers = 0;
    try {
        $memberCountQuery = "SELECT COUNT(*) as total FROM Member";
        $stmt = prepare($memberCountQuery);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $totalMembers = $result->fetch_assoc()['total'];
        }
        $stmt->close();
    } catch(Exception $e) {
        // Log error securely
        error_log("Error getting member count: " . $e->getMessage());
        // Avoid exposing error details to user
        $totalMembers = 0;
    }

    $memberName = "Guest";

    // Safely retrieve admin name using prepared statement
    if (isset($_SESSION['Admin_AdminID'])) {
        try {
            $memberQuery = "SELECT Name FROM Admin WHERE AdminID = ?";
            $stmt = getConnection()->prepare($memberQuery);
            $stmt->bind_param("i", $_SESSION['Admin_AdminID']);
            $stmt->execute();
            $memberResult = $stmt->get_result();
            
            if ($memberResult && $memberResult->num_rows > 0) {
                $memberData = $memberResult->fetch_assoc();
                $memberName = htmlspecialchars($memberData['Name'], ENT_QUOTES, 'UTF-8');
            }
            $stmt->close();
        } catch(Exception $e) {
            error_log("Error retrieving admin name: " . $e->getMessage());
        }
    }

    function getCurrentTerm() {
        $sql = "SELECT year FROM Static ORDER BY year DESC LIMIT 1";
        $result = search($sql);
        $row = $result->fetch_assoc();
        return $row['year'] ?? date('Y');
    }

    $currentTerm = getCurrentTerm();
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <title>Admin Dashboard</title>
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   <style>
/* Base Layout */
.home-container {
    min-height: 100vh;
    background: #f5f7fa;
    padding: 2rem;
}

.content {
    max-width: 1200px;
    margin: 0 auto;
}

/* Typography */
h1 {
    font-size: 1.8rem;
    margin: 0;
}

h2 {
    color: #1e3c72;
    margin-bottom: 1.5rem;
}

h3 {
    color: #1e3c72;
    margin-top: 0.5rem;
}

/* Welcome Section */
.welcome-card {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: white;
    padding: 2rem;
    border-radius: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 15px 0 2rem;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.status-badge {
    background: rgba(255, 255, 255, 0.2);
    padding: 0.8rem 1.5rem;
    border-radius: 50px;
    font-weight: bold;
    color: white;
    text-decoration: none;
}

/* Statistics Section */
.statistics-grid {
    margin: 0 auto 2rem;
    max-width: 850px;
    display: flex;
    gap: 1.5rem;
    justify-content: center;
}

.statistics-card {
    flex: 1;
    padding: 2rem;
    border-radius: 12px;
    text-align: center;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

/* View-only statistics card */
.statistics-card.view-only {
    background: linear-gradient(145deg, #ffffff, #f0f0f0);
    border: 1px solid #e4e7eb;
    cursor: default;
}

.statistics-card.view-only:hover {
    transform: none;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

/* Interactive statistics card */
.statistics-card.interactive {
    background: white;
    cursor: pointer;
    border: 2px solid #1e3c72;
    position: relative;
}

.statistics-card.interactive:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

/* Common card elements */
.icon {
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
    letter-spacing: 1px;
}

/* Management Button */
.manage-users-btn {
    background: #1e3c72;
    color: white;
    padding: 1rem 2rem;
    border: none;
    border-radius: 8px;
    font-size: 1.1rem;
    cursor: pointer;
    transition: background-color 0.3s;
    max-width: 850px;
    width: 100%;
    margin: 1rem auto;
    display: block;
}

.manage-users-btn:hover {
    background: #2a5298;
}

/* Action Cards Grid */
.action-cards {
    display: flex;
    flex-wrap: nowrap;  /* Prevents wrapping to next line */
    gap: 1rem;
    margin: 2rem auto;
    max-width: 1200px;
    opacity: 0;
    height: 0;
    /* overflow: hidden; */
    transition: all 0.5s ease;
    overflow-x: auto;  /* Allows horizontal scrolling if needed */
    padding: 0.5rem;   /* Adds space for the shadow effect */
}

.action-cards.show {
    opacity: 1;
    height: auto;
}

.action-card {
    flex: 1;
    min-width: 200px; 
    background: white;
    padding: 1.5rem;
    border-radius: 12px;
    text-align: center;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.action-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

/* Info Grid Section */
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

/* Icon Animations */
.rotate-icon {
    display: inline-block;
    transition: transform 0.3s ease;
    margin-left: 8px;
}

.rotate-icon.active {
    transform: rotate(180deg);
}

/* Responsive Design */
@media (max-width: 768px) {
    .statistics-grid {
        flex-direction: column;
    }

    .manage-users-btn {
        width: 100%;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }
}</style>
</head>
<body> 
   <div class="home-container">
   <?php include '../templates/navbar-admin.php'; ?> 
       <div class="content">
           <div class="welcome-card">
                <h1>Welcome, <?php echo htmlspecialchars($memberName); ?></h1>
                <a href="addTerm.php" class="status-badge">
                    Term <?php echo htmlspecialchars($currentTerm); ?>
                    <i class="fas fa-chevron-right"></i>
                </a>
           </div>

           <!-- Quick Statistics -->
    <div class="statistics-grid">
        <div class="statistics-card view-only">
            <i class="fas fa-users icon"></i>
            <div class="stat-number"><?php echo $totalMembers; ?></div>
            <div class="stat-label" style="font-weight: bold;">Total Members</div>
        </div>
        <div class="statistics-card interactive" onclick="window.location.href='userDetails.php'">
            <i class="fas fa-users-cog icon"></i>
            <h3>Manage Users</h3>
            <div class="stat-label">Click to View All Users</div>
        </div>
    </div>

            <!-- Action Cards (Initially Hidden) -->
            <div class="action-cards show" id="actionCards">
                <div class="action-card" onclick="window.location.href='memberDetails.php'">
                    <i class="fas fa-user-plus icon"></i>
                    <h3>Manage Members</h3>
                </div>
                <div class="action-card" onclick="window.location.href='treasurerDetails.php'">
                    <i class="fas fa-user-tie icon"></i>
                    <h3>Manage Treasurers</h3>
                </div>
                <div class="action-card" onclick="window.location.href='auditorDetails.php'">
                    <i class="fas fa-user-shield icon"></i>
                    <h3>Manage Auditors</h3>
                </div>
                <div class="action-card" onclick="window.location.href='adminDetails.php'">
                    <i class="fas fa-user-cog icon"></i>
                    <h3>Manage Admins</h3>
                </div>
            </div>

           <!-- Info Grid -->
           <div class="info-grid">
               <!-- Keep existing info cards -->
               <div class="info-card recent-activities">
                   <h2>Recent Activities</h2>
                   <div class="info-item">
                       <span>New Member Added</span>
                       <span>Today, 10:30 AM</span>
                   </div>
                   <div class="info-item">
                       <span>Treasurer Updated</span>
                       <span>Yesterday</span>
                   </div>
                   <div class="info-item">
                       <span>System Backup</span>
                       <span>2 days ago</span>
                   </div>
               </div>

               <div class="info-card system-stats">
                   <h2>System Status</h2>
                   <div class="info-item">
                       <span>System Version:</span>
                       <span>1.0.0</span>
                   </div>
                   <div class="info-item">
                       <span>Last Backup:</span>
                       <span>2024-02-10</span>
                   </div>
                   <div class="info-item">
                       <span>Database Status:</span>
                       <span>Healthy</span>
                   </div>
               </div>
           </div>
       </div>
       <br><br>
       <?php include '../templates/footer.php'; ?>
   </div>
</body>
</html>