<?php
session_start();
require_once "../../config/database.php";

// Initialize variables
$error = null;
$loanDues = 0;
$registrationDue = 0;
$membershipDue = 0;
$unpaidFines = 0;
$totalDues = 0;
$memberData = null;

// Get database connection
$conn = getConnection();

// Check if user is logged in and has user data in session
if (isset($_SESSION['u'])) {
    $userData = $_SESSION['u'];
    
    // Check if the user is a member
    if (isset($userData['Member_MemberID'])) {
        $memberID = $userData['Member_MemberID'];
        
        try {
            // Get member details
            $query = "SELECT * FROM Member WHERE MemberID = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $memberID);
            $stmt->execute();
            $memberResult = $stmt->get_result();
            
            if ($memberResult && $memberResult->num_rows > 0) {
                $memberData = $memberResult->fetch_assoc();
                $joinedDate = $memberData['Joined_Date'];
                
                // Calculate loan dues
                $loanQuery = "SELECT COALESCE(SUM(Remain_Loan + Remain_Interest), 0) as loan_dues 
                             FROM Loan WHERE Member_MemberID = ? AND Status = 'approved'";
                $loanStmt = $conn->prepare($loanQuery);
                $loanStmt->bind_param("s", $memberID);
                $loanStmt->execute();
                $loanResult = $loanStmt->get_result();
                $loanData = $loanResult->fetch_assoc();
                $loanDues = $loanData['loan_dues'];
                
                // Get fee amounts from Static table
                $feeQuery = "SELECT monthly_fee, registration_fee FROM Static 
                            ORDER BY year DESC LIMIT 1";
                $feeStmt = $conn->prepare($feeQuery);
                $feeStmt->execute();
                $feeResult = $feeStmt->get_result();
                $feeData = $feeResult->fetch_assoc();
                $monthlyFee = $feeData['monthly_fee'];
                $registrationFee = $feeData['registration_fee'];
                
                // Check if registration fee is fully paid
                $regFeeQuery = "SELECT COALESCE(SUM(Amount), 0) as paid_registration 
                               FROM Payment 
                               WHERE Member_MemberID = ? AND Payment_Type = 'Registration'";
                $regFeeStmt = $conn->prepare($regFeeQuery);
                $regFeeStmt->bind_param("s", $memberID);
                $regFeeStmt->execute();
                $regFeeResult = $regFeeStmt->get_result();
                $regFeeData = $regFeeResult->fetch_assoc();
                $paidRegistration = $regFeeData['paid_registration'];
                $registrationDue = max(0, $registrationFee - $paidRegistration);
                
                // Calculate unpaid monthly fees
                $currentDate = new DateTime(); // Get current date
                $monthlyFee = $feeData['monthly_fee']; // Get the monthly fee amount from Static table

                // Find the last paid month for this member
                $lastPaidQuery = "SELECT MAX(Date) as last_paid_date 
                                FROM MembershipFee mf 
                                WHERE mf.Member_MemberID = ? AND mf.IsPaid = 'Yes'";
                $lastPaidStmt = $conn->prepare($lastPaidQuery);
                $lastPaidStmt->bind_param("s", $memberID);
                $lastPaidStmt->execute();
                $lastPaidResult = $lastPaidStmt->get_result();
                $lastPaidData = $lastPaidResult->fetch_assoc();

                if ($lastPaidData['last_paid_date']) {
                    // If they have paid before, calculate from the last paid month
                    $lastPaidDate = new DateTime($lastPaidData['last_paid_date']);
                    
                    // Get difference in months
                    $interval = $currentDate->diff($lastPaidDate);
                    $monthsDiff = ($interval->y * 12) + $interval->m;
                    
                    // If we're in a new month since last payment
                    if ($monthsDiff > 0) {
                        $membershipDue = $monthsDiff * $monthlyFee;
                    } else {
                        $membershipDue = 0; // No dues if current month is paid
                    }
                } else {
                    // If they have never paid, calculate from join date
                    $joinedDate = new DateTime($memberData['Joined_Date']);
                    
                    // Get difference in months
                    $interval = $currentDate->diff($joinedDate);
                    $monthsDiff = ($interval->y * 12) + $interval->m;
                    
                    // Include current month if we're past the day of the month they joined
                    if ($currentDate->format('d') > $joinedDate->format('d')) {
                        $monthsDiff += 1;
                    }
                    
                    $membershipDue = $monthsDiff * $monthlyFee;
                }

                // Format the dues for display
                $formattedMembershipDue = number_format($membershipDue, 2);
                
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
                $totalDues = $loanDues + $registrationDue + $membershipDue + $unpaidFines;
                
            } else {
                $error = "Member information not found";
            }
        } catch (Exception $e) {
            $error = "Error retrieving member information: " . $e->getMessage();
        }
    } else {
        $error = "Member information not found in session";
    }
} else {
    $error = "You must be logged in to access this page";
}

// Format the dues for display
$formattedLoanDues = number_format($loanDues, 2);
$formattedRegistrationDue = number_format($registrationDue, 2);
$formattedMembershipDue = number_format($membershipDue, 2);
$formattedUnpaidFines = number_format($unpaidFines, 2);
$formattedTotalDues = number_format($totalDues, 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Dues</title>
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
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .page-title {
            color: #1e3c72;
            margin-bottom: 2rem;
        }
        
        .dues-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .card-title {
            font-size: 1.2rem;
            color: #1e3c72;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .dues-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }
        
        .dues-table th, .dues-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .dues-table th {
            color: #666;
            font-weight: 600;
            background-color: #f8f9fa;
        }
        
        .dues-table tr:last-child {
            font-weight: bold;
            background-color: #f0f4ff;
        }
        
        .dues-table tr:last-child td {
            padding: 1.2rem 1rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: bold;
        }
        
        .status-badge-success {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .status-badge-warning {
            background-color: #ffedd5;
            color: #c2410c;
        }
        
        .status-badge-danger {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        
        .status-badge-info {
            background-color: #e0f2fe;
            color: #0369a1;
        }
        
        .pay-button {
            display: inline-block;
            background-color: #1e3c72;
            color: white;
            padding: 0.8rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        
        .pay-button:hover {
            background-color: #0d2b66;
            transform: translateY(-2px);
        }
        
        .pay-button i {
            margin-right: 0.5rem;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 1rem;
            color: #1e3c72;
            text-decoration: none;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .action-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
        }
        
        .no-dues {
            text-align: center;
            padding: 2rem;
            background-color: #dcfce7;
            border-radius: 10px;
            color: #166534;
        }
        
        .no-dues i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .no-dues h2 {
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="home-container" style="min-height: 100vh; background: #f5f7fa; padding: 2rem;">
        <?php include '../templates/navbar-member.php'; ?>
        
        <div class="container">
            <h1 class="page-title">Payment Dues</h1>
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php 
                    echo htmlspecialchars($_SESSION['success_message']);
                    unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($memberData): ?>
                <?php if ($totalDues > 0): ?>
                    <div class="dues-card">
                        <h2 class="card-title">
                            <i class="fas fa-money-bill-wave"></i> Outstanding Dues
                        </h2>
                        
                        <table class="dues-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($loanDues > 0): ?>
                                <tr>
                                    <td>Loan Balance</td>
                                    <td>Outstanding loan principal and interest</td>
                                    <td>Rs.<?php echo $formattedLoanDues; ?></td>
                                    <td>
                                        <span class="status-badge status-badge-danger">Due</span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php if ($registrationDue > 0): ?>
                                <tr>
                                    <td>Registration Fee</td>
                                    <td>Remaining registration fee to be paid</td>
                                    <td>Rs.<?php echo $formattedRegistrationDue; ?></td>
                                    <td>
                                        <span class="status-badge status-badge-warning">Incomplete</span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php if ($membershipDue > 0): ?>
                                <tr>
                                    <td>Membership Fee</td>
                                    <td>Outstanding monthly membership fees</td>
                                    <td>Rs.<?php echo $formattedMembershipDue; ?></td>
                                    <td>
                                        <span class="status-badge status-badge-info">Pending</span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php if ($unpaidFines > 0): ?>
                                <tr>
                                    <td>Fines</td>
                                    <td>Unpaid fines and penalties</td>
                                    <td>Rs.<?php echo $formattedUnpaidFines; ?></td>
                                    <td>
                                        <span class="status-badge status-badge-warning">Unpaid</span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                
                                <tr>
                                    <td>Total Outstanding</td>
                                    <td>Total amount due</td>
                                    <td>Rs.<?php echo $formattedTotalDues; ?></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="action-row">
                            <!-- <a href="index.php" class="back-link">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a> -->
                            
                            <a href="memberPayment.php" class="pay-button">
                                <i class="fas fa-credit-card"></i> Make Payment
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="dues-card no-dues">
                        <i class="fas fa-check-circle"></i>
                        <h2>No Outstanding Dues</h2>
                        <p>You have no pending payments at this time. Thank you for being up to date!</p>
                        
                        <div class="action-row" style="justify-content: center; margin-top: 2rem;">
                            <a href="index.php" class="back-link">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <?php include '../templates/footer.php'; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize alerts if alertHandler.js is available
            if (typeof initAlerts === 'function') {
                initAlerts();
            } else {
                // Fallback alert handler
                const alertElements = document.querySelectorAll('.alert');
                alertElements.forEach(function(alert) {
                    setTimeout(function() {
                        alert.style.transition = 'opacity 0.5s ease';
                        alert.style.opacity = '0';
                        
                        setTimeout(function() {
                            alert.remove();
                        }, 500);
                    }, 4000);
                });
            }
        });
    </script>
</body>
</html>