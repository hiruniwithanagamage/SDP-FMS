<?php
session_start();
require_once "../../../config/database.php";

// Get current term/year
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get payments for all members with pagination
function getMemberPayments($year, $month = null, $memberID = null, $page = 1, $recordsPerPage = 10) {
    $whereConditions = ["YEAR(p.Date) = $year"];
    
    if ($month !== null && $month > 0) {
        $whereConditions[] = "MONTH(p.Date) = $month";
    }
    
    if ($memberID !== null && $memberID !== '') {
        $whereConditions[] = "m.MemberID = '$memberID'";
    }
    
    $whereClause = implode(" AND ", $whereConditions);
    
    // Calculate offset for pagination
    $offset = ($page - 1) * $recordsPerPage;
    
    $sql = "SELECT 
            p.PaymentID,
            m.MemberID,
            m.Name,
            p.Payment_Type,
            p.Method,
            p.Amount,
            p.Date,
            p.Term,
            p.Status
        FROM Payment p
        JOIN Member m ON p.Member_MemberID = m.MemberID
        WHERE $whereClause
        ORDER BY p.PaymentID ASC
        LIMIT $offset, $recordsPerPage";
    
    return search($sql);
}

// Enhanced function to search across all payments with search term
function searchMemberPayments($year, $month = null, $memberID = null, $searchTerm = null, $page = 1, $recordsPerPage = 10) {
    $whereConditions = ["YEAR(p.Date) = $year"];
    
    if ($month !== null && $month > 0) {
        $whereConditions[] = "MONTH(p.Date) = $month";
    }
    
    if ($memberID !== null && $memberID !== '') {
        $whereConditions[] = "m.MemberID = '$memberID'";
    }
    
    // Add search term condition
    if ($searchTerm !== null && $searchTerm !== '') {
        // Escape search term for SQL injection prevention
        $conn = getConnection();
        $searchTerm = $conn->real_escape_string($searchTerm);
        
        // Add search conditions
        $whereConditions[] = "(
            p.PaymentID LIKE '%$searchTerm%' OR 
            m.MemberID LIKE '%$searchTerm%' OR 
            m.Name LIKE '%$searchTerm%'
        )";
    }
    
    $whereClause = implode(" AND ", $whereConditions);
    
    // Calculate offset for pagination
    $offset = ($page - 1) * $recordsPerPage;
    
    $sql = "SELECT 
            p.PaymentID,
            m.MemberID,
            m.Name,
            p.Payment_Type,
            p.Method,
            p.Amount,
            p.Date,
            p.Term,
            p.Status
        FROM Payment p
        JOIN Member m ON p.Member_MemberID = m.MemberID
        WHERE $whereClause
        ORDER BY p.PaymentID ASC
        LIMIT $offset, $recordsPerPage";
    
    return search($sql);
}

// Get total count of records for pagination
function getTotalPayments($year, $month = null, $memberID = null) {
    $whereConditions = ["YEAR(p.Date) = $year"];
    
    if ($month !== null && $month > 0) {
        $whereConditions[] = "MONTH(p.Date) = $month";
    }
    
    if ($memberID !== null && $memberID !== '') {
        $whereConditions[] = "m.MemberID = '$memberID'";
    }
    
    $whereClause = implode(" AND ", $whereConditions);
    
    $sql = "SELECT 
            COUNT(*) as total
        FROM Payment p
        JOIN Member m ON p.Member_MemberID = m.MemberID
        WHERE $whereClause";
    
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['total'];
}

// Enhanced function to count total search results
function getTotalSearchPayments($year, $month = null, $memberID = null, $searchTerm = null) {
    $whereConditions = ["YEAR(p.Date) = $year"];
    
    if ($month !== null && $month > 0) {
        $whereConditions[] = "MONTH(p.Date) = $month";
    }
    
    if ($memberID !== null && $memberID !== '') {
        $whereConditions[] = "m.MemberID = '$memberID'";
    }
    
    // Add search term condition
    if ($searchTerm !== null && $searchTerm !== '') {
        // Escape search term for SQL injection prevention
        $conn = getConnection();
        $searchTerm = $conn->real_escape_string($searchTerm);
        
        // Add search conditions
        $whereConditions[] = "(
            p.PaymentID LIKE '%$searchTerm%' OR 
            m.MemberID LIKE '%$searchTerm%' OR 
            m.Name LIKE '%$searchTerm%'
        )";
    }
    
    $whereClause = implode(" AND ", $whereConditions);
    
    $sql = "SELECT 
            COUNT(*) as total
        FROM Payment p
        JOIN Member m ON p.Member_MemberID = m.MemberID
        WHERE $whereClause";
    
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['total'];
}

// Get payment summary for the period
function getPaymentSummary($year, $month = null, $memberID = null) {
    $whereConditions = ["YEAR(Date) = $year"];
    
    if ($month !== null && $month > 0) {
        $whereConditions[] = "MONTH(Date) = $month";
    }
    
    if ($memberID !== null && $memberID !== '') {
        $whereConditions[] = "Member_MemberID = '$memberID'";
    }
    
    $whereClause = implode(" AND ", $whereConditions);
    
    $sql = "SELECT 
            COUNT(*) as total_payments,
            SUM(Amount) as total_amount,
            COUNT(DISTINCT Member_MemberID) as unique_members,
            COUNT(CASE WHEN Payment_Type = 'Fine' THEN 1 END) as fine_payments,
            SUM(CASE WHEN Payment_Type = 'Loan' THEN Amount ELSE 0 END) as loan_amount,
            SUM(CASE WHEN Payment_Type = 'registration' THEN Amount ELSE 0 END) as registration_fee_amount,
            SUM(CASE WHEN Payment_Type = 'monthly' THEN Amount ELSE 0 END) as monthly_fee_amount,
            SUM(CASE WHEN Payment_Type = 'Fine' THEN Amount ELSE 0 END) as fine_amount
        FROM Payment
        WHERE $whereClause";
    
    return search($sql);
}

// Get monthly payment statistics
function getMonthlyPaymentStats($year) {
    $sql = "SELECT 
            MONTH(Date) as month,
            COUNT(*) as payment_count,
            SUM(Amount) as total_amount,
            COUNT(DISTINCT Member_MemberID) as unique_members
        FROM Payment
        WHERE YEAR(Date) = $year
        GROUP BY MONTH(Date)
        ORDER BY month";
    
    return search($sql);
}

// Get all members for dropdown
function getAllMembers() {
    $sql = "SELECT MemberID, Name FROM Member ORDER BY Name";
    return search($sql);
}

// Function to check if financial report for a specific term is approved
function isReportApproved($year) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT Status 
        FROM FinancialReportVersions 
        WHERE Term = ? 
        ORDER BY Date DESC 
        LIMIT 1
    ");
    
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return ($row['Status'] === 'approved');
    }
    
    return false; // If no report exists, it's not approved
}

// Get all available terms/years
function getAllTerms() {
    $sql = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
    return search($sql);
}

// Handle Delete Payment Logic with new conditions
if(isset($_POST['delete_payment'])) {
    $paymentId = $_POST['payment_id'];
    $currentYear = isset($_GET['year']) ? $_GET['year'] : (isset($_POST['year']) ? $_POST['year'] : getCurrentTerm());
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // First get all payment details to determine status and type
        $getPaymentQuery = "SELECT * FROM Payment WHERE PaymentID = ?";
        $stmt = $conn->prepare($getPaymentQuery);
        $stmt->bind_param("s", $paymentId);
        $stmt->execute();
        $paymentResult = $stmt->get_result();
        
        if ($paymentResult->num_rows == 0) {
            throw new Exception("Payment not found");
        }
        
        $paymentData = $paymentResult->fetch_assoc();
        $paymentStatus = $paymentData['Status'] ?? ''; 
        $paymentMethod = $paymentData['Method'] ?? '';
        $paymentType = $paymentData['Payment_Type'] ?? '';
        $paymentAmount = $paymentData['Amount'] ?? 0;
        $memberID = $paymentData['Member_MemberID'] ?? '';
        
        // CONDITION 1: Check if status is 'edited', if so, cannot delete
        if ($paymentStatus == 'edited') {
            throw new Exception("Payments with 'edited' status cannot be deleted");
        }
        
        // CONDITION 2: If status is 'self' and method is 'online', cannot delete
        if ($paymentStatus == 'self' && $paymentMethod == 'online') {
            throw new Exception("Online payments cannot be deleted");
        }
        
        // Valid cases: status = 'self' with method = 'cash' or 'transfer', or status = 'treasurer'
        // Get current treasurer ID
        $treasurerQuery = "SELECT TreasurerID FROM Treasurer WHERE isActive = 1 LIMIT 1";
        $treasurerResult = search($treasurerQuery);
        $treasurerRow = $treasurerResult->fetch_assoc();
        $treasurerId = $treasurerRow['TreasurerID'] ?? '';
        
        if (empty($treasurerId)) {
            throw new Exception("Cannot find active treasurer to create adjustment entry");
        }
        
        // CONDITION 3: Handle specific payment types
        if ($paymentType == 'loan') {
            // For loan payments, delete from LoanPayment table and update Loan table
            $loanQuery = "SELECT * FROM LoanPayment WHERE PaymentID = ?";
            $stmt = $conn->prepare($loanQuery);
            $stmt->bind_param("s", $paymentId);
            $stmt->execute();
            $loanResult = $stmt->get_result();
            
            if ($loanResult->num_rows > 0) {
                $loanRow = $loanResult->fetch_assoc();
                $loanId = $loanRow['LoanID'];
                
                // Get current loan details
                $getLoanQuery = "SELECT * FROM Loan WHERE LoanID = ?";
                $stmt = $conn->prepare($getLoanQuery);
                $stmt->bind_param("s", $loanId);
                $stmt->execute();
                $currentLoanResult = $stmt->get_result();
                $currentLoan = $currentLoanResult->fetch_assoc();
                
                // Get remaining details after applying payment
                $currentPaidLoan = $currentLoan['Paid_Loan'] ?? 0;
                $currentPaidInterest = $currentLoan['Paid_Interest'] ?? 0;
                $currentRemainLoan = $currentLoan['Remain_Loan'] ?? 0;
                $currentRemainInterest = $currentLoan['Remain_Interest'] ?? 0;
                
                // When originally processing a payment, interest is paid first, then principal
                // So when reversing, we need to first restore the principal, then the interest
                
                // First, we determine how much was applied to interest and how much to principal
                // Since Remain_Interest was likely modified after the payment, we need to calculate
                // backwards based on original payment amount
                
                // We know the total payment amount, and need to reverse it properly
                $interestPayment = 0;
                $principalPayment = 0;
                
                // Check if paid interest can be reduced by full payment amount
                if ($currentPaidInterest >= $paymentAmount) {
                    // The entire payment went to interest
                    $interestPayment = $paymentAmount;
                    $principalPayment = 0;
                } else {
                    // Part went to interest, part to principal
                    $interestPayment = $currentPaidInterest;
                    $principalPayment = $paymentAmount - $interestPayment;
                    
                    // Make sure we don't reduce Paid_Loan below 0
                    $principalPayment = min($principalPayment, $currentPaidLoan);
                }
                
                // Calculate new values after reversal
                $newPaidLoan = $currentPaidLoan - $principalPayment;
                $newRemainLoan = $currentRemainLoan + $principalPayment;
                $newPaidInterest = $currentPaidInterest - $interestPayment;
                $newRemainInterest = $currentRemainInterest + $interestPayment;
                
                // Remove loan payment link
                $deleteLoanPaymentQuery = "DELETE FROM LoanPayment WHERE PaymentID = ?";
                $stmt = $conn->prepare($deleteLoanPaymentQuery);
                $stmt->bind_param("s", $paymentId);
                $stmt->execute();
                
                // Update loan with all the new values
                $updateLoanQuery = "UPDATE Loan 
                                  SET Paid_Loan = ?, 
                                      Remain_Loan = ?,
                                      Paid_Interest = ?,
                                      Remain_Interest = ?
                                  WHERE LoanID = ?";
                $stmt = $conn->prepare($updateLoanQuery);
                $stmt->bind_param("dddds", $newPaidLoan, $newRemainLoan, $newPaidInterest, $newRemainInterest, $loanId);
                $stmt->execute();
            }
        } else if ($paymentType == 'registration' || $paymentType == 'monthly') {
            // For membership fees (registration or monthly)
            // Get all membership fees linked to this payment
            $feesQuery = "SELECT * FROM MembershipFeePayment WHERE PaymentID = ?";
            $stmt = $conn->prepare($feesQuery);
            $stmt->bind_param("s", $paymentId);
            $stmt->execute();
            $feesResult = $stmt->get_result();
            
            // Collect all fee IDs to be processed
            $feeIds = [];
            while($feeRow = $feesResult->fetch_assoc()) {
                $feeIds[] = $feeRow['FeeID'];
            }
            
            // Remove the membership fee payment links
            $deleteFeePaymentsQuery = "DELETE FROM MembershipFeePayment WHERE PaymentID = ?";
            $stmt = $conn->prepare($deleteFeePaymentsQuery);
            $stmt->bind_param("s", $paymentId);
            $stmt->execute();
            
            // Delete each associated membership fee
            foreach($feeIds as $feeId) {
                $deleteFeeQuery = "DELETE FROM MembershipFee WHERE FeeID = ?";
                $stmt = $conn->prepare($deleteFeeQuery);
                $stmt->bind_param("s", $feeId);
                $stmt->execute();
            }
        } else if ($paymentType == 'Fine') {
            // For fine payments, delete from FinePayment table and update Fine table
            $fineQuery = "SELECT * FROM FinePayment WHERE PaymentID = ?";
            $stmt = $conn->prepare($fineQuery);
            $stmt->bind_param("s", $paymentId);
            $stmt->execute();
            $fineResult = $stmt->get_result();
            
            // Get all fine IDs linked to this payment
            $fineIds = [];
            while($fineRow = $fineResult->fetch_assoc()) {
                $fineIds[] = $fineRow['FineID'];
            }
            
            // Remove the fine payment links
            $deleteFinePaymentsQuery = "DELETE FROM FinePayment WHERE PaymentID = ?";
            $stmt = $conn->prepare($deleteFinePaymentsQuery);
            $stmt->bind_param("s", $paymentId);
            $stmt->execute();
            
            // Update all related fines to unpaid
            foreach($fineIds as $fineId) {
                $updateFineQuery = "UPDATE Fine SET IsPaid = 'No' WHERE FineID = ?";
                $stmt = $conn->prepare($updateFineQuery);
                $stmt->bind_param("s", $fineId);
                $stmt->execute();
            }
        }
        
        // Finally, delete the payment record
        $deleteQuery = "DELETE FROM Payment WHERE PaymentID = ?";
        $stmt = $conn->prepare($deleteQuery);
        $stmt->bind_param("s", $paymentId);
        $stmt->execute();
        
        // Log the deletion
        $logQuery = "INSERT INTO ChangeLog (RecordType, RecordID, TreasurerID, OldValues, NewValues, ChangeDetails, MemberID, Status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $recordType = "Payment";
        $memberId = $_SESSION['member_id'] ?? 'unknown';
        $oldValues = json_encode($paymentData);
        $newValues = "{}";
        $changeDetails = "Deleted payment #$paymentId ($paymentType) for Member #$memberID";
        $Status = "Not Read";
        
        $stmt = $conn->prepare($logQuery);
        $stmt->bind_param("ssssssss", $recordType, $paymentId, $treasurerId, $oldValues, $newValues, $changeDetails, $memberId, $Status);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Payment #$paymentId was successfully deleted and appropriate adjustments were made.";
    } catch(Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting payment: " . $e->getMessage();
    }
    
    // Redirect back to payment page
    header("Location: payment.php?year=" . $currentYear . "&month=" . (isset($_GET['month']) ? $_GET['month'] : 0) . "&page=" . (isset($_GET['page']) ? $_GET['page'] : 1));
    exit();
}

// Handle current filter selections
$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : 0; // 0 means all months
$selectedMemberID = isset($_GET['member']) ? $_GET['member'] : '';
$searchTerm = isset($_GET['search']) ? $_GET['search'] : ''; // Add this line for search term

// Pagination variables
$recordsPerPage = 10;
$currentPage = isset($_GET['page']) ? intval($_GET['page']) : 1;

// Get total records and calculate total pages based on search
if (empty($searchTerm)) {
    // Regular pagination without search
    $totalRecords = getTotalPayments($selectedYear, $selectedMonth, $selectedMemberID);
    $memberPayments = getMemberPayments($selectedYear, $selectedMonth, $selectedMemberID, $currentPage, $recordsPerPage);
} else {
    // Search with pagination
    $totalRecords = getTotalSearchPayments($selectedYear, $selectedMonth, $selectedMemberID, $searchTerm);
    $memberPayments = searchMemberPayments($selectedYear, $selectedMonth, $selectedMemberID, $searchTerm, $currentPage, $recordsPerPage);
}

$totalPages = ceil($totalRecords / $recordsPerPage);

// Ensure current page is valid
if ($currentPage < 1) {
    $currentPage = 1;
} elseif ($currentPage > $totalPages && $totalPages > 0) {
    $currentPage = $totalPages;
}

// Get other data based on filters
$paymentSummary = getPaymentSummary($selectedYear, $selectedMonth, $selectedMemberID);
$monthlyStats = getMonthlyPaymentStats($selectedYear);
$allMembers = getAllMembers();
$allTerms = getAllTerms();
$isReportApproved = isReportApproved($selectedYear);

$months = [
    0 => 'All Months',
    1 => 'January', 2 => 'February', 3 => 'March', 
    4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September',
    10 => 'October', 11 => 'November', 12 => 'December'
];

$paymentTypes = [
    'All Types' => '',
    'Loan' => 'Loan',
    'Membership Fee' => 'Membership Fee',
    'Fine' => 'Fine'
];

$methodTypes = [
    'All Methods' => '',
    'Cash' => 'Cash',
    'Bank Transfer' => 'Bank Transfer',
    'Card' => 'Card',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
    <link rel="stylesheet" href="../../../assets/css/alert.css">
    <script src="../../../assets/js/alertHandler.js"></script>
    <style>
    /* Delete Modal Styles */
    .delete-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        padding: 20px;
        overflow-y: auto;
    }

    .delete-modal-content {
        background-color: white;
        margin: 10% auto;
        padding: 2rem;
        width: 90%;
        max-width: 500px;
        border-radius: 12px;
        position: relative;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        text-align: center;
    }

    .delete-modal-content h2 {
        color: #e53935;
        margin-bottom: 1rem;
    }

    .delete-modal-buttons {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 2rem;
    }

    .confirm-delete-btn {
        padding: 0.8rem 1.8rem;
        border: none;
        border-radius: 6px;
        font-size: 1rem;
        cursor: pointer;
        background-color: #e53935;
        color: white;
        transition: background-color 0.3s;
    }

    .confirm-delete-btn:hover {
        background-color: #c62828;
    }

    .cancel-btn {
        padding: 0.8rem 1.8rem;
        border: none;
        border-radius: 6px;
        font-size: 1rem;
        cursor: pointer;
        background-color: #e0e0e0;
        color: #333;
        transition: background-color 0.3s;
    }

    .cancel-btn:hover {
        background-color: #d0d0d0;
    }
    .month-filter-container {
        display: flex;
        gap: 15px;
        align-items: center;
    }

    .month-filter-select {
        padding: 8px 15px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background-color: white;
        color: #333;
        font-size: 14px;
        cursor: pointer;
    }

    .month-filter-select:focus {
        outline: none;
        border-color: #1e3c72;
        box-shadow: 0 0 0 2px rgba(30, 60, 114, 0.2);
    }

    /* Edit Payment Modal */
    #editPaymentModal, #paymentModal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        overflow: auto;
    }

    #editPaymentModal .modal-content, #paymentModal .modal-content {
        background-color: white;
        margin: 5% auto;
        padding: 20px;
        width: 90%;
        max-width: 900px;
        height: 80%;
        border-radius: 8px;
        position: relative;
    }

    #editPaymentModal .close, #paymentModal .close {
        position: absolute;
        right: 20px;
        top: 10px;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    .modal-iframe {
        width: 100%;
        height: 100%;
        border: none;
    }

    .alert-info {
        background-color: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
    }

    .status-yes {
        background-color: #c2f1cd;
        color: rgb(25, 151, 10);
    }
    .status-no {
        background-color: #e2bcc0;
        color: rgb(234, 59, 59);
    }

    /* Pagination styles */
    .pagination {
        display: flex;
        justify-content: center;
        margin: 20px 0;
        flex-wrap: wrap;
    }
    
    .pagination-info {
        text-align: center;
        margin-bottom: 10px;
        color: #555;
        font-size: 0.9rem;
        width: 100%;
    }
    
    .pagination button {
        padding: 8px 16px;
        margin: 0 4px;
        background-color: #f8f8f8;
        border: 1px solid #ddd;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .pagination button:hover {
        background-color: #e0e0e0;
    }
    
    .pagination button.active {
        background-color: #4a6eb5;
        color: white;
        border-color: #4a6eb5;
    }
    
    .pagination button.disabled {
        color: #aaa;
        cursor: not-allowed;
    }
    
    .pagination button.disabled:hover {
        background-color: #f8f8f8;
    }
    
    /* Search notification styles */
    .search-notification {
        margin: 10px 0;
    }
    
    .clear-search-link {
        margin-left: 15px;
        color: #0c5460;
        text-decoration: none;
    }
    
    .clear-search-link:hover {
        text-decoration: underline;
    }
    
    .search-btn {
        background-color: #1e3c72;
        color: white;
        border: none;
        padding: 8px 12px;
        border-radius: 4px;
        cursor: pointer;
        margin-left: 5px;
    }
    
    .search-btn:hover {
        background-color: #15294e;
    }
</style>
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Payment Management</h1>
            <div class="filter-container">
                <select class="filter-select" id="yearSelect" onchange="updateFilters()">
                    <?php while($term = $allTerms->fetch_assoc()): ?>
                        <option value="<?php echo $term['year']; ?>" <?php echo $term['year'] == $selectedYear ? 'selected' : ''; ?>>
                            Year <?php echo $term['year']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>

        <!-- Generate alert -->
        <div class="alerts-container">
            <?php if(isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
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
        </div>

        <div id="stats-section" class="stats-cards">
            <?php
            $stats = $paymentSummary->fetch_assoc();
            ?>
            <div class="stat-card">
                <i class="fas fa-money-bill-wave"></i>
                <div class="stat-number"><?php echo $stats['total_payments'] ?? 0; ?></div>
                <div class="stat-label">Total Payments</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-<i class="fas fa-hand-holding-usd"></i>
                <div class="stat-number">Rs. <?php echo number_format($stats['total_amount'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Amount</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-chart-pie"></i>
                <div style="color:#1e3c72; font-weight:bold;" class="stat-label">Breakdown</div>
                <div class="stat-number-small">
                    Loan: Rs. <?php echo number_format($stats['loan_amount'] ?? 0, 2); ?><br>
                    Registration Fees: Rs. <?php echo number_format($stats['registration_fee_amount'] ?? 0, 2); ?><br>
                    Monthly Fees: Rs. <?php echo number_format($stats['monthly_fee_amount'] ?? 0, 2); ?><br>
                    Fines: Rs. <?php echo number_format($stats['fine_amount'] ?? 0, 2); ?>
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('payments')">Payment List</button>
            <button class="tab" onclick="showTab('monthly')">Monthly Summary</button>
            <div class="month-filter-container">
                <select class="month-filter-select" id="monthSelect" onchange="updateFilters()">
                    <?php foreach($months as $num => $name): ?>
                        <option value="<?php echo $num; ?>" <?php echo $num == $selectedMonth ? 'selected' : ''; ?>>
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filters">
                <div class="search-container">
                    <form id="searchForm" action="" method="GET">
                        <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                        <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                        <input type="hidden" name="page" value="1">
                        <input type="text" id="searchInput" name="search" placeholder="Search by ID, Name, or Payment ID..." 
                               class="search-input" value="<?php echo htmlspecialchars($searchTerm); ?>">
                        <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
                        <?php if (!empty($searchTerm)): ?>
                            <button type="button" onclick="clearSearch()" class="clear-btn"><i class="fas fa-times"></i></button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- Search notification -->
        <?php if (!empty($searchTerm)): ?>
        <div class="search-notification">
            <div class="alert alert-info">
                Showing search results for: <strong><?php echo htmlspecialchars($searchTerm); ?></strong>
                <a href="?year=<?php echo $selectedYear; ?>&month=<?php echo $selectedMonth; ?>" class="clear-search-link">
                    <i class="fas fa-times"></i> Clear search
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div id="payments-view">
            <div class="table-container" style="max-height: 900px;">
                <table id="paymentsTable">
                    <thead>
                        <tr>
                            <th>Payment ID</th>
                            <th>Name</th>
                            <th>Payment Type</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($memberPayments && $memberPayments->num_rows > 0):
                            while($row = $memberPayments->fetch_assoc()): 
                        ?>
                        <tr data-payment-type="<?php echo htmlspecialchars($row['Payment_Type']); ?>" data-method="<?php echo htmlspecialchars($row['Method']); ?>">
                            <td><?php echo htmlspecialchars($row['PaymentID']); ?></td>
                            <td><?php echo htmlspecialchars($row['Name']); ?></td>
                            <td><?php echo htmlspecialchars($row['Payment_Type']); ?></td>
                            <td><?php echo htmlspecialchars($row['Method']); ?></td>
                            <td>Rs. <?php echo number_format($row['Amount'], 2); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($row['Date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['Status']); ?></td>
                            <td class="actions">
                                <button onclick="viewPayment('<?php echo $row['PaymentID']; ?>')" class="action-btn small">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick="printPaymentReceipt('<?php echo $row['PaymentID']; ?>')" class="action-btn small">
                                        <i class="fas fa-print"></i>
                                    </button>
                                <?php if (!$isReportApproved): ?>
                                    <button onclick="editPayment('<?php echo $row['PaymentID']; ?>')" class="action-btn small">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="openDeleteModal('<?php echo $row['PaymentID']; ?>')" class="action-btn small">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <button onclick="showReportMessage()" class="action-btn small info-btn" title="Report approved">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php 
                            endwhile; 
                        else: 
                        ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 20px;">No payment records found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($totalPages > 0): ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo ($currentPage-1)*$recordsPerPage+1; ?> to 
                        <?php echo min($currentPage*$recordsPerPage, $totalRecords); ?> of 
                        <?php echo $totalRecords; ?> records
                    </div>
                    
                    <!-- First and Previous buttons -->
                    <button onclick="goToPage(1)" 
                            <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                            <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                        <i class="fas fa-angle-double-left"></i>
                    </button>
                    <button onclick="goToPage(<?php echo $currentPage-1; ?>)" 
                            <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                            <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                        <i class="fas fa-angle-left"></i>
                    </button>
                    
                    <!-- Page numbers -->
                    <?php
                    // Calculate range of page numbers to show
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);
                    
                    // Ensure we always show at least 5 pages when possible
                    if ($endPage - $startPage + 1 < 5 && $totalPages >= 5) {
                        if ($startPage == 1) {
                            $endPage = min(5, $totalPages);
                        } elseif ($endPage == $totalPages) {
                            $startPage = max(1, $totalPages - 4);
                        }
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <button onclick="goToPage(<?php echo $i; ?>)" 
                                class="<?php echo $i == $currentPage ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </button>
                    <?php endfor; ?>
                    
                    <!-- Next and Last buttons -->
                    <button onclick="goToPage(<?php echo $currentPage+1; ?>)" 
                            <?php echo $currentPage == $totalPages ? 'class="disabled"' : ''; ?> 
                            <?php echo $currentPage == $totalPages ? 'disabled' : ''; ?>>
                        <i class="fas fa-angle-right"></i>
                    </button>
                    <button onclick="goToPage(<?php echo $totalPages; ?>)" 
                            <?php echo $currentPage == $totalPages ? 'class="disabled"' : ''; ?> 
                            <?php echo $currentPage == $totalPages ? 'disabled' : ''; ?>>
                        <i class="fas fa-angle-double-right"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="monthly-view" style="display: none;">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Payment Count</th>
                            <th>Total Amount</th>
                            <th>Unique Members</th>
                            <!-- <th>Actions</th> -->
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $monthlyStats->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $months[$row['month']]; ?></td>
                            <td><?php echo $row['payment_count']; ?></td>
                            <td>Rs. <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td><?php echo $row['unique_members']; ?></td>
                            <!-- <td class="actions">
                                <button onclick="viewMonthDetails(<?php echo $row['month']; ?>)" class="action-btn small">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button onclick="exportMonthReport(<?php echo $row['month']; ?>)" class="action-btn small">
                                    <i class="fas fa-download"></i> Export
                                </button>
                            </td> -->
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php include '../../templates/footer.php'; ?>
    </div>

    <!-- Payment Details Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closePaymentModal()">&times;</span>
            <iframe id="paymentFrame" class="modal-iframe"></iframe>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this payment record? This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" id="delete_payment_id" name="payment_id">
                <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                <input type="hidden" name="page" value="<?php echo $currentPage; ?>">
                <div class="delete-modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete_payment" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Payment Modal -->
    <div id="editPaymentModal" class="modal">
        <div class="modal-content" style="max-width: 90%; height: 90%;">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <iframe id="editPaymentFrame" style="width: 100%; height: 90%; border: none;"></iframe>
        </div>
    </div>

    <script>
        // Pagination function
        function goToPage(page) {
            const year = document.getElementById('yearSelect').value;
            const month = document.getElementById('monthSelect').value;
            const searchParam = '<?php echo !empty($searchTerm) ? "&search=".urlencode($searchTerm) : ""; ?>';
            
            window.location.href = `payment.php?year=${year}&month=${month}&page=${page}${searchParam}`;
        }
        
        // Update filters
        function updateFilters() {
            const year = document.getElementById('yearSelect').value;
            const month = document.getElementById('monthSelect').value;
            const searchParam = '<?php echo !empty($searchTerm) ? "&search=".urlencode($searchTerm) : ""; ?>';
            
            window.location.href = `payment.php?year=${year}&month=${month}&page=1${searchParam}`;
        }

        // Clear search functionality
        function clearSearch() {
            const year = document.getElementById('yearSelect').value;
            const month = document.getElementById('monthSelect').value;
            
            window.location.href = `payment.php?year=${year}&month=${month}&page=1`;
        }

        // Switch between tabs
        function showTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById('payments-view').style.display = 'none';
            document.getElementById('monthly-view').style.display = 'none';
            
            if (tab === 'payments') {
                document.getElementById('payments-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'payments\')"]').classList.add('active');
            } else {
                document.getElementById('monthly-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'monthly\')"]').classList.add('active');
            }
        }

        // Print payment receipt
        function printPaymentReceipt(paymentID) {
            window.location.href = `../payments/payment_receipt.php?payment_id=${paymentID}`;
        }

        // View payment details with modal
        function viewPayment(paymentID) {
            // Set the iframe source to your viewPayment.php page
            document.getElementById('paymentFrame').src = `viewPayment.php?id=${paymentID}&popup=true`;
            
            // Show the modal
            document.getElementById('paymentModal').style.display = 'block';
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }

        // Edit payment with modal
        function editPayment(paymentID) {
            // Set the iframe source to your editPayment.php page
            document.getElementById('editPaymentFrame').src = `editPayment.php?id=${paymentID}&popup=true`;
            
            // Show the modal
            document.getElementById('editPaymentModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editPaymentModal').style.display = 'none';
    
            // After closing, refresh the payment list to see any changes
            const urlParams = new URLSearchParams(window.location.search);
            const year = urlParams.get('year') || document.getElementById('yearSelect').value;
            const month = urlParams.get('month') || document.getElementById('monthSelect').value;
            const page = urlParams.get('page') || 1;
            const search = urlParams.get('search') || '';
            
            // Redirect to the same page with parameters
            let url = `payment.php?year=${year}&month=${month}&page=${page}`;
            if (search) {
                url += `&search=${encodeURIComponent(search)}`;
            }
            window.location.href = url;
        }

        function openDeleteModal(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_payment_id').value = id;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // View month details
        function viewMonthDetails(month) {
            const year = document.getElementById('yearSelect').value;
            window.location.href = `?year=${year}&month=${month}&page=1`;
        }

        // Export month report
        function exportMonthReport(month) {
            const year = document.getElementById('yearSelect').value;
            window.location.href = `exportPaymentReport.php?year=${year}&month=${month}`;
        }

        // Update window onclick handler to work with all modals
        window.onclick = function(event) {
            const paymentModal = document.getElementById('paymentModal');
            const deleteModal = document.getElementById('deleteModal');
            const editModal = document.getElementById('editPaymentModal');
            
            if (event.target == paymentModal) {
                closePaymentModal();
            }
            
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
            
            if (event.target == editModal) {
                closeEditModal();
            }
        };

        // Function to create and show alerts programmatically
        function showAlert(type, message) {
            const alertsContainer = document.querySelector('.alerts-container');
            
            // Create alert element
            const alertDiv = document.createElement('div');
            alertDiv.className = type === 'success' ? 'alert alert-success'  : 
                                type === 'info' ? 'alert alert-info' : 'alert alert-danger';
            alertDiv.textContent = message;
            
            // Clear previous alerts
            alertsContainer.innerHTML = '';
            
            // Add new alert
            alertsContainer.appendChild(alertDiv);
            
            // Scroll to top to see the alert
            window.scrollTo(0, 0);
            
            // Manually trigger the alert handler for this new alert
            const closeBtn = document.createElement('span');
            closeBtn.innerHTML = '&times;';
            closeBtn.className = 'alert-close';
            closeBtn.style.float = 'right';
            closeBtn.style.cursor = 'pointer';
            closeBtn.style.fontWeight = 'bold';
            closeBtn.style.fontSize = '20px';
            closeBtn.style.marginLeft = '15px';
            
            closeBtn.addEventListener('click', function() {
                alertDiv.style.display = 'none';
            });
            
            alertDiv.insertBefore(closeBtn, alertDiv.firstChild);
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                alertDiv.style.opacity = '0';
                setTimeout(function() {
                    alertDiv.style.display = 'none';
                }, 500);
            }, 5000);
        }

        function showReportMessage() {
            showAlert('info', 'This record cannot be modified as the financial report for this term has already been approved.');
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Check for stored alerts from the edit modal
            const alertType = localStorage.getItem('payment_alert_type');
            const alertMessage = localStorage.getItem('payment_alert_message');
            
            if (alertType && alertMessage) {
                // Display the alert
                showAlert(alertType, alertMessage);
                
                // Clear the stored alert data
                localStorage.removeItem('payment_alert_type');
                localStorage.removeItem('payment_alert_message');
            }
            
            // Add event listener for search form submission with Enter key
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        document.getElementById('searchForm').submit();
                    }
                });
            }
        });
    </script>
</body>
</html>