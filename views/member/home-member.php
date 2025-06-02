<?php
session_start();
require_once "../../config/database.php";

// Initialize variables with default values
$memberData = null;
$totalDues = 0;
$notifications = [];
$unreadCount = 0;

// Get database connection
$conn = getConnection();

// Check if user is logged in and has user data in session
if (isset($_SESSION['u'])) {
    $userData = $_SESSION['u'];
    
    // Check if the user is a member and has Member_MemberID
    if (isset($userData['Member_MemberID'])) {
        $memberID = $userData['Member_MemberID'];
        
        try {
            // Get member details using prepared statement
            $query = "SELECT * FROM Member WHERE MemberID = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $memberID);
            $stmt->execute();
            $memberResult = $stmt->get_result();
            
            if ($memberResult && $memberResult->num_rows > 0) {
                $memberData = $memberResult->fetch_assoc();
                
                // Calculate loan dues
                $loanDuesQuery = "SELECT COALESCE(SUM(Remain_Loan + Remain_Interest), 0) as loan_dues 
                                 FROM Loan WHERE Member_MemberID = ? AND Status = 'approved'";
                $loanStmt = $conn->prepare($loanDuesQuery);
                $loanStmt->bind_param("s", $memberID);
                $loanStmt->execute();
                $loanResult = $loanStmt->get_result();
                $loanData = $loanResult->fetch_assoc();
                $loanDues = $loanData['loan_dues'];
                
                // Get the current date information
                $currentDate = new DateTime(date('Y-m-d'));
                $currentYear = $currentDate->format('Y');
                $currentMonth = $currentDate->format('n'); // 1-12 for Jan-Dec

                // Get fee amounts from Static table
                $feeQuery = "SELECT monthly_fee, registration_fee FROM Static 
                            ORDER BY year DESC LIMIT 1";
                $feeStmt = $conn->prepare($feeQuery);
                $feeStmt->execute();
                $feeResult = $feeStmt->get_result();
                $feeData = $feeResult->fetch_assoc();
                $monthlyFee = $feeData['monthly_fee'];
                $registrationFee = $feeData['registration_fee'];

                // Calculate unpaid registration fee
                // Get the total paid registration fees from MembershipFee table
                $regPaidQuery = "SELECT COALESCE(SUM(Amount), 0) as paid_registration
                            FROM MembershipFee
                            WHERE Member_MemberID = ? AND Type = 'registration' AND IsPaid = 'Yes'";
                $regPaidStmt = $conn->prepare($regPaidQuery);
                $regPaidStmt->bind_param("s", $memberID);
                $regPaidStmt->execute();
                $regPaidResult = $regPaidStmt->get_result();
                $regPaidData = $regPaidResult->fetch_assoc();
                $paidRegistration = $regPaidData['paid_registration'];

                // Calculate registration fee due (total from Static - paid amount)
                $regFeeDue = max(0, $registrationFee - $paidRegistration);

                // Calculate monthly membership fees for current year only
                // Total expected monthly fees = monthly fee × current month number
                $totalExpectedMonthlyFees = $currentMonth * $monthlyFee;

                // Get total paid monthly fees for current year
                $monthlyPaidQuery = "SELECT COALESCE(SUM(Amount), 0) as paid_monthly
                                    FROM MembershipFee
                                    WHERE Member_MemberID = ? 
                                    AND Type = 'monthly' 
                                    AND IsPaid = 'Yes'
                                    AND Term = ?";
                $monthlyPaidStmt = $conn->prepare($monthlyPaidQuery);
                $monthlyPaidStmt->bind_param("ss", $memberID, $currentYear);
                $monthlyPaidStmt->execute();
                $monthlyPaidResult = $monthlyPaidStmt->get_result();
                $monthlyPaidData = $monthlyPaidResult->fetch_assoc();
                $paidMonthlyFees = $monthlyPaidData['paid_monthly'];

                // Calculate monthly fee due (expected - paid)
                $membershipDue = max(0, $totalExpectedMonthlyFees - $paidMonthlyFees);
                      
                // Calculate unpaid fines
                $fineQuery = "SELECT COALESCE(SUM(Amount), 0) as unpaid_fines 
                             FROM Fine 
                             WHERE Member_MemberID = ? AND IsPaid = 'No'";
                $fineStmt = $conn->prepare($fineQuery);
                $fineStmt->bind_param("s", $memberID);
                $fineStmt->execute();
                $fineResult = $fineStmt->get_result();
                $fineData = $fineResult->fetch_assoc();
                $unpaidFines = $fineData['unpaid_fines'];
                
                // Calculate total dues
                $totalDues = $loanDues + $regFeeDue + $membershipDue + $unpaidFines;
                
                // Store itemized dues for potential detailed display
                $duesBreakdown = [
                    'loan' => $loanDues,
                    'registration' => $regFeeDue,
                    'membership' => $membershipDue,
                    'fines' => $unpaidFines
                ];
                
                // First, get the total count of unread notifications
                $countQuery = "SELECT COUNT(*) as unread_count
                            FROM ChangeLog 
                            WHERE MemberID = ? AND Status = 'Not Read'";
                $countStmt = $conn->prepare($countQuery);
                $countStmt->bind_param("s", $memberID);
                $countStmt->execute();
                $countResult = $countStmt->get_result();
                $countData = $countResult->fetch_assoc();
                $unreadCount = $countData['unread_count'];

                // Then, get only the 5 most recent notifications for display
                $notificationQuery = "SELECT 
                                    LogID,
                                    RecordType,
                                    RecordID,
                                    ChangeDetails,
                                    DATE_FORMAT(ChangeDate, '%Y-%m-%d %H:%i') as FormattedDate
                                    FROM ChangeLog 
                                    WHERE MemberID = ? AND Status = 'Not Read' 
                                    ORDER BY ChangeDate DESC 
                                    LIMIT 5";
                $notificationStmt = $conn->prepare($notificationQuery);
                $notificationStmt->bind_param("s", $memberID);
                $notificationStmt->execute();
                $notificationResult = $notificationStmt->get_result();

                if ($notificationResult && $notificationResult->num_rows > 0) {
                    while ($row = $notificationResult->fetch_assoc()) {
                        $notifications[] = $row;
                    }
                }
            }
        } catch (Exception $e) {
            // Log error (in a production environment, use proper logging)
            error_log("Database error: " . $e->getMessage());
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

// Format member information
$memberInfo = array(
    'Name' => htmlspecialchars($memberData['Name']),
    'MemberID' => htmlspecialchars($memberData['MemberID']),
    'Mobile_Number' => htmlspecialchars($memberData['Mobile_Number']),
    'Address' => htmlspecialchars($memberData['Address']),
    'Status' => htmlspecialchars($memberData['Status']),
    'Joined_Date' => htmlspecialchars($memberData['Joined_Date']),
    'No_of_Family_Members' => htmlspecialchars($memberData['No_of_Family_Members'])
);

// Format the total dues for display
$formattedDues = number_format($totalDues, 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Member Home</title>
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   <link rel="stylesheet" href="../../assets/css/alert.css">
   <script src="../../assets/js/alertHandler.js"></script>
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

       .notification-item {
           display: flex;
           flex-direction: column;
           padding: 1rem;
           border-bottom: 1px solid #eee;
           cursor: pointer;
           transition: background-color 0.2s;
       }
       
       .notification-item:hover {
           background-color: #f5f7fa;
       }

       .notification-header {
           display: flex;
           justify-content: space-between;
           margin-bottom: 0.5rem;
       }

       .notification-title {
           font-weight: bold;
           color: #1e3c72;
       }

       .notification-date {
           color: #777;
           font-size: 0.9rem;
       }

       .notification-content {
           color: #333;
       }

       h1 {
           font-size: 1.8rem;
           margin: 0;
       }

       h2 {
           color: #1e3c72;
           margin-bottom: 1.5rem;
           display: flex;
           align-items: center;
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
        
        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 6px;
            opacity: 1;
            transition: opacity 0.5s ease;
        }
        
        .alert-info {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 6px;
            opacity: 1;
            transition: opacity 0.5s ease;
        }
        
        .notification-alert {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-alert-content {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .notification-alert-button {
            background-color: #1e40af;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background-color 0.2s;
        }
        
        .notification-alert-button:hover {
            background-color: #1e3a8a;
        }

        .fade-out {
            opacity: 0;
        }
        
        .status-active {
            color: #166534;
            font-weight: bold;
        }
        
        .status-pending {
            color: #b45309;
            font-weight: bold;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            width: 80%;
            max-width: 700px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
        }

        .report-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .report-option {
            background: #f5f7fa;
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .report-option:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }

        .report-option h3 {
            margin: 0.5rem 0;
            color: #1e3c72;
        }

        .report-option p {
            color: #555;
            margin-top: 0.5rem;
        }
        
        .notification-badge {
            background-color: #e53e3e;
            color: white;
            border-radius: 50%;
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }
        
        .notification-empty {
            padding: 2rem;
            text-align: center;
            color: #777;
        }
        
        .view-all-link {
            display: block;
            text-align: center;
            margin-top: 1rem;
            padding: 0.5rem;
            background-color: #f5f7fa;
            border-radius: 8px;
            color: #1e3c72;
            text-decoration: none;
            font-weight: bold;
        }
        
        .view-all-link:hover {
            background-color: #e5e7eb;
        }
        
        .unread-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background-color: #e53e3e;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
   </style>
</head>
<body>
   <div class="home-container">
   <?php include '../templates/navbar-member.php'; ?> 
       <div class="content">
           <div class="welcome-card">
               <h1>Welcome, <?php echo $memberInfo['Name']; ?></h1>
               <a href="payDues.php" class="dues-button">
                   <i class="fas fa-credit-card"></i>
                   Dues: Rs.<?php echo $formattedDues; ?>
               </a>
           </div>

           <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php 
                    echo htmlspecialchars($_SESSION['success_message']); 
                    unset($_SESSION['success_message']); // Clear the message after displaying
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if ($unreadCount > 0): ?>
                <div class="alert alert-info notification-alert">
                    <div class="notification-alert-content">
                        <i class="fas fa-bell"></i> You have <?php echo $unreadCount; ?> unread notification<?php echo $unreadCount > 1 ? 's' : ''; ?>
                    </div>
                    <a href="notifications.php" class="notification-alert-button">View All</a>
                </div>
            <?php endif; ?>

           <div class="action-cards">
               <div class="action-card" onclick="window.location.href='memberSummary.php'">
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
               <div class="action-card" id="viewReportsCard">
                   <i class="fas fa-chart-bar icon"></i>
                   <h3>View Reports</h3>
               </div>
           </div>

           <div class="info-grid">
               <div class="info-card member-details">
                   <h2>Member Information</h2>
                   <div class="info-item">
                       <span>ID:</span>
                       <span><?php echo $memberInfo['MemberID']; ?></span>
                   </div>
                   <div class="info-item">
                       <span>Contact:</span>
                       <span><?php echo $memberInfo['Mobile_Number']; ?></span>
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
                    <div class="info-item">
                        <span>Joined Date:</span>
                        <span><?php echo date('Y-m-d', strtotime($memberInfo['Joined_Date'])); ?></span>
                    </div>
                    <?php if ($duesBreakdown['membership'] > 0): ?>
                    <div class="info-item">
                        <span>Membership Fee Due:</span>
                        <span class="status-pending">Rs.<?php echo number_format($duesBreakdown['membership'], 2); ?></span>
                    </div>
                    <?php endif; ?>
               </div>

               <div class="info-card notifications">
                   <h2>
                       Notifications
                       <?php if ($unreadCount > 0): ?>
                       <span class="notification-badge"><?php echo $unreadCount; ?></span>
                       <?php endif; ?>
                   </h2>
                   <div class="notification-list">
                       <?php if (empty($notifications)): ?>
                           <div class="notification-empty">
                               <p>No new notifications</p>
                           </div>
                       <?php else: ?>
                           <?php foreach ($notifications as $notification): ?>
                           <div class="notification-item" onclick="window.location.href='notifications.php'">
                               <div class="notification-header">
                                   <div class="notification-title">
                                       <?php echo htmlspecialchars($notification['RecordType']); ?> Update
                                   </div>
                                   <div class="notification-date"><?php echo htmlspecialchars($notification['FormattedDate']); ?></div>
                               </div>
                               <div class="notification-content">
                                   <?php echo htmlspecialchars($notification['ChangeDetails']); ?>
                               </div>
                           </div>
                           <?php endforeach; ?>
                           <a href="notifications.php" class="view-all-link">View All Notifications</a>
                       <?php endif; ?>
                   </div>
               </div>
           </div>
       </div>
       <br><br>
       <?php include '../templates/footer.php'; ?>
   </div>

   <div id="reportModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2>Select Report Type</h2>
        <div class="report-options">
            <div class="report-option" onclick="window.location.href='../treasurer/financialManagement/viewLoan.php'">
                <i class="fas fa-file-invoice-dollar icon"></i>
                <h3>Loan Reports</h3>
                <p>View your loan history, current status, and payment schedule</p>
            </div>
            <div class="report-option" onclick="window.location.href='../../reports/yearEndReport.php'">
                <i class="fas fa-calendar-check icon"></i>
                <h3>Year-End Reports</h3>
                <p>Access annual financial summaries and statements</p>
            </div>
        </div>
    </div>
</div>

   <!-- Use the common alert handler script -->
   <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize alerts EXCEPT notification alerts
            const alertElements = document.querySelectorAll('.alert:not(.notification-alert)');
            alertElements.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 4000);
            });

            // Get the modal element
            var modal = document.getElementById("reportModal");

            // Update the action card to open the modal instead of direct navigation
            var viewReportsCard = document.getElementById("viewReportsCard");
            if (viewReportsCard) {
                // Remove the existing onclick handler
                viewReportsCard.onclick = function() {
                    modal.style.display = "block";
                };
            }
            
            // Get the <span> element that closes the modal
            var span = document.getElementsByClassName("close")[0];
            
            // When the user clicks on <span> (x), close the modal
            span.onclick = function() {
                modal.style.display = "none";
            }
            
            // When the user clicks anywhere outside of the modal, close it
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = "none";
                }
            }
        });
   </script>
</body>
</html>