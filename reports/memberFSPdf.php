<?php
session_start();
require_once "../config/database.php";

date_default_timezone_set('Asia/Colombo');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../loginProcess.php");
    exit();
}

// Get member ID from URL parameter
$memberID = isset($_GET['id']) ? $_GET['id'] : null;
$download = isset($_GET['download']) ? true : false;

if (!$memberID) {
    // Redirect based on user role
    if ($_SESSION['role'] === 'member') {
        header("Location: ../views/member/memberSummary.php");
    } else {
        header("Location: ../views/treasurer/memberFinancialSummary.php");
    }
    exit();
}

// Security check: Make sure user can only access their own data if they're a member
if ($_SESSION['role'] === 'member' && $_SESSION['member_id'] !== $memberID) {
    // Members can only view their own data
    header("Location: ../views/member/memberSummary.php");
    exit();
}

// Get current year
$currentYear = date('Y');

// Function to get member details
function getMemberDetails($memberID) {
    $sql = "SELECT * FROM Member WHERE MemberID = ?";
    $stmt = prepare($sql);
    $stmt->bind_param("s", $memberID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get loan status
function getLoanStatus($memberID) {
    $sql = "SELECT COUNT(*) as count, SUM(Remain_Loan) as balance 
            FROM Loan 
            WHERE Member_MemberID = ? AND Remain_Loan > 0";
    $stmt = prepare($sql);
    $stmt->bind_param("s", $memberID);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return [
        'hasLoan' => $row['count'] > 0,
        'balance' => $row['balance'] ?? 0
    ];
}

// Function to check death welfare status
function getDeathWelfareStatus($memberID) {
    $sql = "SELECT COUNT(*) as count 
            FROM DeathWelfare 
            WHERE Member_MemberID = ? AND Status = 'approved'";
    $stmt = prepare($sql);
    $stmt->bind_param("s", $memberID);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row['count'] > 0;
}

// Function to get membership status
function getMembershipStatus($memberID, $isRegistrationFeePaid) {
    // Member is active if their status is 'active' AND registration fee is fully paid
    $sql = "SELECT Status FROM Member WHERE MemberID = ?";
    $stmt = prepare($sql);
    $stmt->bind_param("s", $memberID);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    // Member is considered active only if their status is 'active' AND they've paid registration fee
    $isActive = ($row['Status'] === 'Full Member' && $isRegistrationFeePaid);
    
    return $isActive ? 'Full Member' : 'Pending';
}

// Function to get membership fee payments
function getMembershipFeePayments($memberID, $year) {
    // Get current month number (1-12)
    $currentMonth = date('n');
    
    // Get monthly fee amount from Static table
    $feeQuery = "SELECT monthly_fee FROM Static ORDER BY year DESC LIMIT 1";
    $feeResult = search($feeQuery);
    $feeRow = $feeResult->fetch_assoc();
    $monthlyFee = $feeRow['monthly_fee'];
    
    // Calculate total expected fee for the year up to current month
    $totalExpectedFee = $currentMonth * $monthlyFee;
    
    // Get the total amount paid for membership fees this year
    $sql = "SELECT COALESCE(SUM(mf.Amount), 0) as paid_amount
           FROM MembershipFee mf
           LEFT JOIN MembershipFeePayment mfp ON mf.FeeID = mfp.FeeID
           LEFT JOIN Payment p ON mfp.PaymentID = p.PaymentID
           WHERE mf.Member_MemberID = ? 
           AND mf.Term = ? 
           AND mf.Type = 'monthly'";
    $stmt = prepare($sql);
    $stmt->bind_param("si", $memberID, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $totalPaid = $row['paid_amount'];

    // Calculate how many months have been paid
    // Important: Use division with proper rounding to handle partial payments
    $paidMonths = round($totalPaid / $monthlyFee);
    
    // Calculate remaining months - ensure it doesn't go negative
    $remainingMonths = max(0, $currentMonth - $paidMonths);
    
    // Calculate the due amount
    $dueAmount = $remainingMonths * $monthlyFee;
    
    return [
        'monthlyFee' => $monthlyFee,
        'currentMonth' => $currentMonth,
        'paidMonths' => $paidMonths,
        'remainingMonths' => $remainingMonths,
        'total' => $totalExpectedFee,
        'paid' => $totalPaid,
        'due' => $dueAmount
    ];
}

// Function to get fine payments
function getFinePayments($memberID) {
    $sql = "SELECT f.Amount as fine_amount, 
                  CASE WHEN f.IsPaid = 'Yes' THEN f.Amount ELSE 0 END as paid_amount
           FROM Fine f
           WHERE f.Member_MemberID = ?";
    $stmt = prepare($sql);
    $stmt->bind_param("s", $memberID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $totalFine = 0;
    $totalPaid = 0;
    
    while ($row = $result->fetch_assoc()) {
        $totalFine += $row['fine_amount'];
        $totalPaid += $row['paid_amount'];
    }
    
    return [
        'total' => $totalFine,
        'paid' => $totalPaid,
        'due' => $totalFine - $totalPaid
    ];
}

// Function to get loan payments
function getLoanPayments($memberID) {
    $sql = "SELECT 
                l.Amount as loan_amount,
                l.Paid_Loan as paid_loan,
                l.Remain_Loan as remain_loan,
                l.Paid_Interest as paid_interest,
                l.Remain_Interest as remain_interest
            FROM Loan l
            WHERE l.Member_MemberID = ? AND l.Status = 'approved'";
    $stmt = prepare($sql);
    $stmt->bind_param("s", $memberID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $totalLoan = 0;
    $paidLoan = 0;
    $remainLoan = 0;
    $paidInterest = 0;
    $remainInterest = 0;
    
    while ($row = $result->fetch_assoc()) {
        $totalLoan += $row['loan_amount'];
        $paidLoan += $row['paid_loan'];
        $remainLoan += $row['remain_loan'];
        $paidInterest += $row['paid_interest'];
        $remainInterest += $row['remain_interest'];
    }
    
    return [
        'total' => $totalLoan,
        'paid' => $paidLoan,
        'due' => $remainLoan,
        'paidInterest' => $paidInterest,
        'dueInterest' => $remainInterest
    ];
}

// Function to get registration fee status
function getRegistrationFeeStatus($memberID) {
    $sql = "SELECT p.Amount 
            FROM Payment p 
            WHERE p.Member_MemberID = ? AND p.Payment_Type = 'registration'";
    $stmt = prepare($sql);
    $stmt->bind_param("s", $memberID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Get registration fee amount from Static table
    $regFeeQuery = "SELECT registration_fee FROM Static ORDER BY year DESC LIMIT 1";
    $regFeeResult = search($regFeeQuery);
    $regFeeRow = $regFeeResult->fetch_assoc();
    $requiredFee = $regFeeRow['registration_fee'];
    
    $totalPaid = 0;
    while ($row = $result->fetch_assoc()) {
        $totalPaid += $row['Amount'];
    }
    
    $dueAmount = $requiredFee - $totalPaid;
    $dueAmount = $dueAmount > 0 ? $dueAmount : 0;
    
    return [
        'required' => $requiredFee,
        'paid' => $totalPaid,
        'due' => $dueAmount,
        'isFullyPaid' => $totalPaid >= $requiredFee,
        'status' => $totalPaid >= $requiredFee ? 'Fully Paid' : 'Partially Paid'
    ];
}

// Get all the data we need
$memberDetails = getMemberDetails($memberID);

if (!$memberDetails) {
    header("Location: memberFinancialSummary.php");
    exit();
}

$registrationFee = getRegistrationFeeStatus($memberID);
$loanStatus = getLoanStatus($memberID);
$deathWelfare = getDeathWelfareStatus($memberID);
$membershipStatus = getMembershipStatus($memberID, $registrationFee['isFullyPaid']);
$membershipFee = getMembershipFeePayments($memberID, $currentYear);
$fines = getFinePayments($memberID);
$loans = getLoanPayments($memberID);

// Calculate total outstanding - now including registration fee due
$totalOutstanding = $membershipFee['due'] + $fines['due'] + $loans['due'] + $registrationFee['due'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Summary - <?php echo htmlspecialchars($memberDetails['Name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }

        .container {
            min-height: 100vh;
            background: #f5f7fa;
            /* padding: 2rem; */
        }

        .summary-page {
            background: white;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin-top: 30px;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }

        .logo {
            width: 80px;
            height: 80px;
            display: block;
            margin: 0 auto 10px;
        }

        h1 {
            margin: 10px 0;
            color: #1e3c72;
        }

        .info-section {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 20px;
            gap: 20px;
        }

        .info-card {
            flex: 1;
            min-width: 300px;
            background: #f0f7ff;
            padding: 15px;
            border-radius: 8px;
        }

        .info-title {
            font-size: 18px;
            color: #1e3c72;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #d0e0f7;
        }

        .info-row {
            display: flex;
            margin-bottom: 8px;
        }

        .info-label {
            width: 150px;
            font-weight: 600;
            color: #555;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-active {
            background-color: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }

        .status-paid {
            background-color: #d4edda;
            color: #155724;
        }

        .status-partial {
            background-color: #fff3cd;
            color: #856404;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table th, table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }

        .total-row td {
            font-weight: 700;
            border-top: 2px solid #dee2e6;
        }

        .footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #eee;
            font-size: 0.9rem;
            color: #666;
        }

        .print-actions {
            text-align: center;
            margin-top: 30px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .btn {
            padding: 10px 20px;
            background-color: #1e3c72;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-secondary {
            background-color: #6c757d;
        }

        @media print {
            .print-actions {
                display: none;
            }
            
            body {
                background: white;
                padding: 0;
            }
            
            .summary-page {
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
<div class="container">
        <!-- <?php include '../views/templates/navbar-treasurer.php'; ?> -->
    <div class="summary-page" id="summary-content">
        <div class="header">
            <img src="../assets/images/society_logo.png" alt="Logo" class="logo">
            <h1>Financial Summary</h1>
        </div>
        
        <div class="info-section">
            <div class="info-card">
                <h3 class="info-title">Member Information</h3>
                <div class="info-row">
                    <div class="info-label">Name:</div>
                    <div><?php echo htmlspecialchars($memberDetails['Name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Member ID:</div>
                    <div><?php echo htmlspecialchars($memberDetails['MemberID']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">NIC:</div>
                    <div><?php echo htmlspecialchars($memberDetails['NIC']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Mobile:</div>
                    <div><?php echo $memberDetails['Mobile_Number'] ? htmlspecialchars($memberDetails['Mobile_Number']) : '—'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Joined Date:</div>
                    <div><?php echo date('Y-m-d', strtotime($memberDetails['Joined_Date'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Term:</div>
                    <div><?php echo $currentYear; ?></div>
                </div>
            </div>
            
            <div class="info-card">
                <h3 class="info-title">Financial Overview</h3>
                <div class="info-row">
                    <div class="info-label">Status:</div>
                    <div>
                        <span class="status-badge <?php echo $membershipStatus === 'Full Member' ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $membershipStatus?>
                        </span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Loan Balance:</div>
                    <div>
                        <?php echo $loanStatus['balance'] > 0 ? 'Rs.' . number_format($loanStatus['balance'], 2) : 'No Active Loans'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Registration Fee:</div>
                    <div>
                        <span class="status-badge <?php echo $registrationFee['isFullyPaid'] ? 'status-paid' : 'status-partial'; ?>">
                            <?php echo $registrationFee['isFullyPaid'] ? 'Completed' : 'Rs. ' . number_format($registrationFee['due'], 2) . ' due'; ?>
                        </span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Membership Fees:</div>
                    <div>
                        <span class="status-badge <?php echo $membershipFee['due'] == 0 ? 'status-paid' : 'status-partial'; ?>">
                            <?php 
                            if ($membershipFee['due'] == 0) {
                                echo 'Up to date';
                            } else {
                                echo $membershipFee['remainingMonths'] . ' months pending';
                            }
                            ?>
                        </span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Unpaid Fines:</div>
                    <div>
                        <?php echo $fines['due'] > 0 ? 'Rs.' . number_format($fines['due'], 2) : 'None'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Total Outstanding:</div>
                    <div>
                        <strong>Rs.<?php echo number_format($totalOutstanding, 2); ?></strong>
                    </div>
                </div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Paid (Rs.)</th>
                    <th>Amount Due (Rs.)</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$registrationFee['isFullyPaid']): ?>
            <tr>
            <td>Registration Fee</td>
            <td><?php echo number_format($registrationFee['paid'], 2); ?></td>
            <td><?php echo number_format($registrationFee['due'], 2); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td>Membership Fee (Monthly)</td>
                <td>
                    <?php echo number_format($membershipFee['paid'], 2); ?>
                    <small>(<?php echo $membershipFee['paidMonths']; ?> months paid)</small>
                </td>
                <td>
                    <?php echo number_format($membershipFee['due'], 2); ?>
                    <?php if ($membershipFee['remainingMonths'] > 0): ?>
                        <small>(<?php echo $membershipFee['remainingMonths']; ?> months pending)</small>
                    <?php endif; ?>
                </td>
            </tr>
                <tr>
                    <td>Fine</td>
                    <td><?php echo number_format($fines['paid'], 2); ?></td>
                    <td><?php echo number_format($fines['due'], 2); ?></td>
                </tr>
                <tr>
                    <td>Loan</td>
                    <td><?php echo number_format($loans['paid'], 2); ?></td>
                    <td><?php echo number_format($loans['due'], 2); ?></td>
                </tr>
                <tr>
                    <td>Interest</td>
                    <td><?php echo number_format($loans['paidInterest'], 2); ?></td>
                    <td><?php echo number_format($loans['dueInterest'], 2); ?></td>
                </tr>
                <tr class="total-row">
                    <td>Total</td>
                    <td><?php echo number_format($membershipFee['paid'] + $fines['paid'] + $loans['paid'] + $loans['paidInterest'], 2); ?></td>
                    <td><?php echo number_format($totalOutstanding + $loans['dueInterest'], 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="footer">
            <p>Generated on <?php echo date('Y-m-d H:i:s'); ?> | Eksat Maranadhara Samithiya</p>
        </div>
    </div>
    
    <div class="print-actions">
        <button class="btn" onclick="window.print()">
            <i class="fas fa-print"></i> Print Summary
        </button>
        <button class="btn" id="downloadPdfBtn">
            <i class="fas fa-file-download"></i> Download PDF
        </button>
        <?php if ($_SESSION['role'] === 'member'): ?>
                <a href="../views/member/memberSummary.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            <?php else: ?>
                <a href="../views/treasurer/reportsAnalytics/memberFinancialSummary.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Member List
                </a>
            <?php endif; ?>
    </div>
    </div>
    
    <!-- PDF Generation Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    <script>
    document.getElementById('downloadPdfBtn').addEventListener('click', function() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        
        // Hide print actions before capturing
        document.querySelector('.print-actions').style.display = 'none';
        
        html2canvas(document.getElementById('summary-content'), {
            scale: 2, // Higher quality
            useCORS: true
        }).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            const imgProps = doc.getImageProperties(imgData);
            const pdfWidth = doc.internal.pageSize.getWidth();
            const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
            
            doc.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
            doc.save('Financial_Summary_<?php echo $memberID; ?>.pdf');
            
            // Restore print actions after PDF generation
            document.querySelector('.print-actions').style.display = 'flex';
        }).catch(error => {
            console.error('Error generating PDF:', error);
            alert('Failed to generate PDF. Please try again.');
            document.querySelector('.print-actions').style.display = 'flex';
        });
    });
    </script>
</body>
</html>