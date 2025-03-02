<?php
session_start();
require_once "../../config/database.php";

// Initialize variables
$error = null;
$memberData = null;
$memberStatus = "Unknown";
$totalDues = 0;
$loanDues = 0;
$registrationDue = 0;
$membershipDue = 0;
$unpaidFines = 0;
$recentActivity = [];
$loanHistory = [];
$membershipHistory = [];
$paymentHistory = [];
$fineHistory = [];
$availableYears = [];
$selectedYear = isset($_GET['year']) ? $_GET['year'] : date('Y'); // Default to current year

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
                $memberStatus = $memberData['Status'];
                $joinedDate = $memberData['Joined_Date'];
                
                // Get available years for filter
                $yearsQuery = "SELECT DISTINCT YEAR(Date) as year FROM Payment WHERE Member_MemberID = ? 
                              UNION 
                              SELECT DISTINCT YEAR(Issued_Date) as year FROM Loan WHERE Member_MemberID = ?
                              UNION
                              SELECT DISTINCT YEAR(Date) as year FROM MembershipFee WHERE Member_MemberID = ?
                              UNION
                              SELECT DISTINCT YEAR(Date) as year FROM Fine WHERE Member_MemberID = ?
                              ORDER BY year DESC";
                $yearsStmt = $conn->prepare($yearsQuery);
                $yearsStmt->bind_param("ssss", $memberID, $memberID, $memberID, $memberID);
                $yearsStmt->execute();
                $yearsResult = $yearsStmt->get_result();
                
                while ($yearRow = $yearsResult->fetch_assoc()) {
                    $availableYears[] = $yearRow['year'];
                }
                
                // If no records yet, at least include current year
                if (empty($availableYears)) {
                    $availableYears[] = date('Y');
                }
                
                // If selected year isn't in available years, default to the first available
                if (!in_array($selectedYear, $availableYears) && !empty($availableYears)) {
                    $selectedYear = $availableYears[0];
                }
                
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
                
                // Calculate membership fee status for the selected year
                $membershipYearQuery = "SELECT 
                                  COUNT(*) as total_months,
                                  SUM(CASE WHEN IsPaid = 'Yes' THEN 1 ELSE 0 END) as paid_months
                                  FROM MembershipFee 
                                  WHERE Member_MemberID = ? AND YEAR(Date) = ?";
                $membershipYearStmt = $conn->prepare($membershipYearQuery);
                $membershipYearStmt->bind_param("si", $memberID, $selectedYear);
                $membershipYearStmt->execute();
                $membershipYearResult = $membershipYearStmt->get_result();
                $membershipYearData = $membershipYearResult->fetch_assoc();
                
                $totalMonths = $membershipYearData['total_months'];
                $paidMonths = $membershipYearData['paid_months'];
                
                // Determine expected number of months to pay
                $joinYear = date('Y', strtotime($joinedDate));
                $joinMonth = date('m', strtotime($joinedDate));
                $currentYear = date('Y');
                $currentMonth = date('m');
                
                $expectedMonths = 12; // Default to full year
                
                // Adjust for join year
                if ($selectedYear == $joinYear) {
                    $expectedMonths = 13 - intval($joinMonth); // Months remaining in join year
                }
                
                // Adjust for current year if it's the selected year (don't expect future months)
                if ($selectedYear == $currentYear) {
                    $expectedMonths = min($expectedMonths, intval($currentMonth));
                }
                
                // For future years, expected months should be 0
                if ($selectedYear > $currentYear) {
                    $expectedMonths = 0;
                }
                
                // Calculate membership fee status
                $isMembershipUpToDate = ($paidMonths >= $expectedMonths);
                $missingMonths = max(0, $expectedMonths - $totalMonths);
                
                // Calculate total unpaid membership fees
                $membershipQuery = "SELECT 
                                  COALESCE(SUM(mf.Amount), 0) as total_membership_fees,
                                  COALESCE(SUM(CASE WHEN mf.IsPaid = 'Yes' THEN mf.Amount ELSE 0 END), 0) as paid_membership_fees
                                  FROM MembershipFee mf
                                  WHERE mf.Member_MemberID = ?";
                $membershipStmt = $conn->prepare($membershipQuery);
                $membershipStmt->bind_param("s", $memberID);
                $membershipStmt->execute();
                $membershipResult = $membershipStmt->get_result();
                $membershipData = $membershipResult->fetch_assoc();
                $membershipDue = $membershipData['total_membership_fees'] - $membershipData['paid_membership_fees'];
                
                // Add missing months' fees to the total due
                $membershipDue += ($missingMonths * $monthlyFee);
                
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
                
                // Get payment history for selected year
                $paymentQuery = "SELECT 
                                PaymentID, 
                                Payment_Type, 
                                Method, 
                                Amount, 
                                Date
                                FROM Payment 
                                WHERE Member_MemberID = ? 
                                AND YEAR(Date) = ?
                                ORDER BY Date DESC";
                $paymentStmt = $conn->prepare($paymentQuery);
                $paymentStmt->bind_param("si", $memberID, $selectedYear);
                $paymentStmt->execute();
                $paymentResult = $paymentStmt->get_result();
                
                while ($payment = $paymentResult->fetch_assoc()) {
                    $paymentHistory[] = $payment;
                }
                
                // Get loan history for selected year
                $loanHistoryQuery = "SELECT 
                                    LoanID, 
                                    Amount, 
                                    Term, 
                                    Issued_Date, 
                                    Due_Date, 
                                    Paid_Loan, 
                                    Paid_Interest, 
                                    Remain_Loan, 
                                    Remain_Interest, 
                                    Status 
                                    FROM Loan 
                                    WHERE Member_MemberID = ? 
                                    AND (YEAR(Issued_Date) = ? OR YEAR(Due_Date) = ?)
                                    ORDER BY Issued_Date DESC";
                $loanHistoryStmt = $conn->prepare($loanHistoryQuery);
                $loanHistoryStmt->bind_param("sii", $memberID, $selectedYear, $selectedYear);
                $loanHistoryStmt->execute();
                $loanHistoryResult = $loanHistoryStmt->get_result();
                
                while ($loan = $loanHistoryResult->fetch_assoc()) {
                    $loanHistory[] = $loan;
                }
                
                // Get membership fee history for selected year
                $membershipHistoryQuery = "SELECT 
                                          FeeID, 
                                          Amount, 
                                          Date, 
                                          Term, 
                                          Type, 
                                          IsPaid 
                                          FROM MembershipFee 
                                          WHERE Member_MemberID = ? 
                                          AND YEAR(Date) = ?
                                          ORDER BY Date DESC";
                $membershipHistoryStmt = $conn->prepare($membershipHistoryQuery);
                $membershipHistoryStmt->bind_param("si", $memberID, $selectedYear);
                $membershipHistoryStmt->execute();
                $membershipHistoryResult = $membershipHistoryStmt->get_result();
                
                while ($fee = $membershipHistoryResult->fetch_assoc()) {
                    $membershipHistory[] = $fee;
                }
                
                // Get fine history for selected year
                $fineHistoryQuery = "SELECT 
                                    FineID, 
                                    Amount, 
                                    Date, 
                                    Description, 
                                    IsPaid 
                                    FROM Fine 
                                    WHERE Member_MemberID = ? 
                                    AND YEAR(Date) = ?
                                    ORDER BY Date DESC";
                $fineHistoryStmt = $conn->prepare($fineHistoryQuery);
                $fineHistoryStmt->bind_param("si", $memberID, $selectedYear);
                $fineHistoryStmt->execute();
                $fineHistoryResult = $fineHistoryStmt->get_result();
                
                while ($fine = $fineHistoryResult->fetch_assoc()) {
                    $fineHistory[] = $fine;
                }
                
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

// Format member data for display
function formatMemberData($data) {
    if (!$data) return [];
    
    return [
        'name' => htmlspecialchars($data['Name']),
        'id' => htmlspecialchars($data['MemberID']),
        'nic' => htmlspecialchars($data['NIC']),
        'dob' => htmlspecialchars($data['DoB']),
        'address' => htmlspecialchars($data['Address']),
        'mobile' => htmlspecialchars($data['Mobile_Number']),
        'family_members' => htmlspecialchars($data['No_of_Family_Members']),
        'other_members' => htmlspecialchars($data['Other_Members']),
        'status' => htmlspecialchars($data['Status']),
        'joined_date' => htmlspecialchars($data['Joined_Date'])
    ];
}

$formattedMemberData = formatMemberData($memberData);

// Include the HTML template
include 'memberSummary_template.php';
?>