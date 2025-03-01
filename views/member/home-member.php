<?php
session_start();
require_once "../../config/database.php";

// Initialize variables with default values
$memberData = null;
$totalDues = 0;

// Check if user is logged in and has user data in session
if (isset($_SESSION['u'])) {
    $userData = $_SESSION['u'];
    
    // Check if the user is a member and has Member_MemberID
    if (isset($userData['Member_MemberID'])) {
        $memberID = $userData['Member_MemberID'];
        
        // Get member details
        $memberQuery = "SELECT * FROM Member WHERE MemberID = '" . $memberID . "'";
        $memberResult = search($memberQuery);

        // if ($memberResult) {
        //     echo "Query executed successfully<br>";
        //     echo "Number of rows: " . $memberResult->num_rows . "<br>";
            
        //     if ($memberResult->num_rows > 0) {
        //         $memberData = $memberResult->fetch_assoc();
        //         echo "<pre>Member Data from DB: ";
        //         print_r($memberData);
        //         echo "</pre>";
        //     } else {
        //         echo "No rows found for this member ID<br>";
        //     }
        // } else {
        //     echo "Query failed<br>";
        // }
    
        
        if ($memberResult && $memberResult->num_rows > 0) {
            $memberData = $memberResult->fetch_assoc();
            // Calculate dues
            $duesQuery = "SELECT 
                COALESCE(SUM(Remain_Loan + Remain_Interest), 0) as total_dues 
                FROM Loan 
                WHERE Member_MemberID = '" . $memberID . "'";
            $duesResult = search($duesQuery);
            $duesData = $duesResult->fetch_assoc();
            $totalDues = $duesData['total_dues'];
        }
    }
}

// If no member data found, set default values
if (!$memberData) {
    $memberData = array(
        'Name' => 'Guest',
        'MemberID' => 'N/A',
        'Mobile_Number' => 'N/A',
        'Address' => 'N/A',
        'Status' => 'N/A',
        'Joined_Date' => date('Y-m-d'),
        'No_of_Family_Members' => 'N/A'
    );
}


// add the memeber data into another array
$memberInfo = array(
    'Name' => $memberData['Name'],
    'MemberID' => $memberData['MemberID'],
    'Mobile_Number' => $memberData['Mobile_Number'],
    'Address' => $memberData['Address'],
    'Status' => $memberData['Status'],
    'Joined_Date' => $memberData['Joined_Date'],
    'No_of_Family_Members' => $memberData['No_of_Family_Members']
);
// Add this for debugging (commented out but useful for troubleshooting)
/*
echo "Session Data:<br>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";
*/
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <title>Member Home</title>
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
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
           margin-top: 30px;
           margin-bottom: 2rem;
           box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
       }

       .dues-button {
           background: rgba(255, 255, 255, 0.2);
           padding: 0.8rem 1.5rem;
           border-radius: 50px;
           font-weight: bold;
           cursor: pointer;
           transition: all 0.2s ease;
           text-decoration: none;
           color: white;
           display: flex;
           align-items: center;
           gap: 0.5rem;
       }

       .dues-button:hover {
           background: rgba(255, 255, 255, 0.3);
           transform: translateY(-2px);
       }

       .action-cards {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
           gap: 1.5rem;
           margin-bottom: 2rem;
       }

       .action-card {
           background: white;
           padding: 1.5rem;
           border-radius: 12px;
           text-align: center;
           cursor: pointer;
           transition: transform 0.2s;
           box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
       }

       .action-card:hover {
           transform: translateY(-5px);
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

       .transaction-item {
           display: grid;
           grid-template-columns: 1fr 1.5fr 1fr;
           padding: 1rem 0;
           border-bottom: 1px solid #eee;
       }

       .transaction-amount {
           text-align: right;
           font-weight: bold;
           color: #2a5298;
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

       .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 6px;
            opacity: 1;
            transition: opacity 0.5s ease;
        }

        .fade-out {
            opacity: 0;
        }
   </style>
</head>
<body>
   <div class="home-container">
   <?php include '../templates/navbar-member.php'; ?> 
       <div class="content">
           <div class="welcome-card">
               <h1>Welcome, <?php echo htmlspecialchars($memberData['Name']); ?></h1>
               <a href="pay_dues.php" class="dues-button">
                   <i class="fas fa-credit-card"></i>
                   Outstanding: $67
               </a>
           </div>

           <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php 
                    echo $_SESSION['success_message']; 
                    unset($_SESSION['success_message']); // Clear the message after displaying
                    ?>
                </div>
            <?php endif; ?>

           <div class="action-cards">
               <div class="action-card" onclick="window.location.href='view_summary.php'">
                   <i class="fas fa-file-alt icon"></i>
                   <h3>View Summary</h3>
               </div>
               <div class="action-card" onclick="window.location.href='applyLoan.php'">
                   <i class="fas fa-hand-holding-usd icon"></i>
                   <h3>Apply Loan</h3>
               </div>
               <div class="action-card" onclick="window.location.href='applyDeathWelfare.php'">
                   <i class="fas fa-heart icon"></i>
                   <h3>Apply Death Welfare</h3>
               </div>
               <div class="action-card" onclick="window.location.href='view_reports.php'">
                   <i class="fas fa-chart-bar icon"></i>
                   <h3>View Reports</h3>
               </div>
           </div>

           <div class="info-grid">
               <div class="info-card member-details">
                   <h2>Member Information</h2>
                   <div class="info-item">
                       <span>ID:</span>
                       <span><?php echo htmlspecialchars($memberInfo['MemberID']); ?></span>
                   </div>
                   <div class="info-item">
                       <span>Contact:</span>
                       <span><?php echo htmlspecialchars($memberInfo['Mobile_Number']); ?></span>
                   </div>
                   <div class="info-item">
                        <span>Membership Status:</span>
                        <span class="<?php echo ($memberInfo['Status'] === 'Full Member') ? 'status-active' : 'status-pending'; ?>">
                            <?php 
                            if($memberInfo['Status'] === 'Full Member') {
                                echo 'Full Membership';
                            } else {
                                echo 'Pending';
                            }
                            ?>
                        </span>
                    </div>
               </div>

               <div class="info-card transactions">
                   <h2>Recent Transactions</h2>
                   <div class="transaction-list">
                       <?php if (empty($transactions)): ?>
                           <p>No recent transactions found.</p>
                       <?php else: ?>
                           <?php foreach ($transactions as $t): ?>
                           <div class="transaction-item">
                               <div class="transaction-date"><?php echo htmlspecialchars(date('Y-m-d', strtotime($t['date']))); ?></div>
                               <div class="transaction-type"><?php echo htmlspecialchars($t['type']); ?></div>
                               <div class="transaction-amount">Rs.<?php echo htmlspecialchars($t['amount']); ?></div>
                           </div>
                           <?php endforeach; ?>
                       <?php endif; ?>
                   </div>
               </div>
           </div>
       </div>
       <br><br>
       <?php include '../templates/footer.php'; ?>
   </div>

   <script>
        document.addEventListener('DOMContentLoaded', function() {
            const alertElement = document.querySelector('.alert-success');
            if (alertElement) {
                setTimeout(function() {
                    // Add fade-out class
                    alertElement.style.transition = 'opacity 0.5s ease';
                    alertElement.style.opacity = '0';
                    
                    // Remove element after fade animation
                    setTimeout(function() {
                        alertElement.remove();
                    }, 500);
                }, 4000); // 5000ms = 5 seconds
            }
        });
</script>
</body>
</html>