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

// Get membership fee details for all members with pagination and month filter
function getMemberFees($year, $type = null, $month = null, $page = 1, $recordsPerPage = 10) {
    // Calculate the starting point for the LIMIT clause
    $start = ($page - 1) * $recordsPerPage;
    
    $sql = "SELECT 
            m.MemberID,
            m.Name,
            mf.FeeID,
            mf.Amount,
            mf.Date,
            mf.Type,
            mf.IsPaid
        FROM Member m
        INNER JOIN MembershipFee mf ON m.MemberID = mf.Member_MemberID 
            AND Term = $year";
    
    // Add type filter if specified
    if ($type !== null && in_array($type, ['registration', 'monthly'])) {
        $sql .= " AND mf.Type = '$type'";
    }
    
    // Add month filter if specified
    if ($month !== null && $month > 0 && $month <= 12) {
        $sql .= " AND MONTH(mf.Date) = $month";
    }
    
    $sql .= " ORDER BY mf.FeeID LIMIT $start, $recordsPerPage";
    
    return search($sql);
}

// Get total count of records for pagination
function getTotalMemberFees($year, $type = null, $month = null) {
    $sql = "SELECT COUNT(*) as total 
        FROM Member m
        INNER JOIN MembershipFee mf ON m.MemberID = mf.Member_MemberID 
            AND Term = $year";
    
    // Add type filter if specified
    if ($type !== null && in_array($type, ['registration', 'monthly'])) {
        $sql .= " AND mf.Type = '$type'";
    }
    
    // Add month filter if specified
    if ($month !== null && $month > 0 && $month <= 12) {
        $sql .= " AND MONTH(mf.Date) = $month";
    }
    
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['total'];
}

// Get fee settings from Static table
function getFeeSettings() {
    $sql = "SELECT monthly_fee, registration_fee FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    return $result->fetch_assoc();
}

// Get all available terms/years
function getAllTerms() {
    $sql = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
    return search($sql);
}

// Handle Delete Fee
if(isset($_POST['delete_fee'])) {
    $feeId = $_POST['fee_id'];
    $currentYear = isset($_GET['year']) ? $_GET['year'] : (isset($_POST['year']) ? $_POST['year'] : getCurrentTerm());
    
    try {
        // Start transaction
        $GLOBALS['db_connection']->begin_transaction();
        
        // Get fee details first
        $feeQuery = "SELECT f.Member_MemberID, f.Amount, f.Term, f.Type, f.IsPaid, f.Date FROM MembershipFee f WHERE f.FeeID = ?";
        $stmt = prepare($feeQuery);
        $stmt->bind_param("s", $feeId);
        $stmt->execute();
        $feeResult = $stmt->get_result();
        
        if($feeResult->num_rows === 0) {
            throw new Exception("Fee record not found");
        }
        
        $feeDetails = $feeResult->fetch_assoc();
        $memberID = $feeDetails['Member_MemberID'];
        $amount = $feeDetails['Amount'];
        $term = $feeDetails['Term'];
        $type = $feeDetails['Type'];
        $isPaid = $feeDetails['IsPaid'];
        $feeDate = $feeDetails['Date'];
        
        // Get active treasurer
        $treasurerQuery = "SELECT TreasurerID FROM Treasurer WHERE isActive = 1 LIMIT 1";
        $stmt = prepare($treasurerQuery);
        $stmt->execute();
        $treasurerResult = $stmt->get_result();
        $activeTreasurer = $treasurerResult->fetch_assoc()['TreasurerID'];
        
        if (!$activeTreasurer) {
            throw new Exception("No active treasurer found. Please set an active treasurer first.");
        }
        
        // Only create an expense record if the fee was actually paid
        if ($isPaid === 'Yes') {
            // Function to generate a unique expense ID
            function generateExpenseID($term) {
                // Get current year if term is not provided
                if (empty($term)) {
                    $term = date('Y');
                }
                
                // Extract the last 2 digits of the term
                $shortTerm = substr((string)$term, -2);
                
                // Find the highest sequence number for the current term
                $stmt = prepare("
                    SELECT MAX(CAST(SUBSTRING(ExpenseID, 6) AS UNSIGNED)) as max_seq 
                    FROM Expenses 
                    WHERE ExpenseID LIKE 'EXP{$shortTerm}%'
                ");
                
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                
                $nextSeq = 1; // Default starting value
                if ($row && $row['max_seq']) {
                    $nextSeq = $row['max_seq'] + 1;
                }
                
                // Format: EXP followed by last 2 digits of term and sequence number
                // Use leading zeros for numbers 1-9, no leading zeros after 10
                if ($nextSeq < 10) {
                    return 'EXP' . $shortTerm . '0' . $nextSeq;
                } else {
                    return 'EXP' . $shortTerm . $nextSeq;
                }
            }
            
            // Create expense record for the deleted fee
            $expenseID = generateExpenseID($term);
            $currentDate = date('Y-m-d');
            $description = "Deleted " . ucfirst($type) . " Membership Fee";
            
            // Create an expense record
            $expenseStmt = prepare("
                INSERT INTO Expenses (
                    ExpenseID, Category, Method, Amount, Date, Term, 
                    Description, Treasurer_TreasurerID
                ) VALUES (?, 'Adjustment', 'System', ?, ?, ?, ?, ?)
            ");
            
            $expenseStmt->bind_param("sdssss", 
                $expenseID,
                $amount,
                $currentDate,
                $term,
                $description,
                $activeTreasurer
            );
            
            if (!$expenseStmt->execute()) {
                throw new Exception("Failed to create expense record: " . $expenseStmt->error);
            }
        }
        
        // Store fee details as JSON for the changelog
        $oldValues = json_encode([
            'FeeID' => $feeId,
            'Member_MemberID' => $memberID,
            'Amount' => $amount,
            'Term' => $term,
            'Type' => $type,
            'IsPaid' => $isPaid,
            'Date' => $feeDate
        ]);
        
        // Add to ChangeLog
        $logQuery = "INSERT INTO ChangeLog (
                RecordType, 
                RecordID, 
                MemberID,
                TreasurerID, 
                OldValues, 
                NewValues, 
                ChangeDetails,
                Status
            ) VALUES (
                'MembershipFee',
                ?,
                ?,
                ?,
                ?,
                '{}',
                'Deleted membership fee record',
                'Not Read'
            )";
            
        $stmt = prepare($logQuery);
        $stmt->bind_param("ssss", 
            $feeId,
            $memberID,
            $activeTreasurer,
            $oldValues
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create change log entry: " . $stmt->error);
        }
        
        // Finally, delete the fee record
        $deleteFeeQuery = "DELETE FROM MembershipFee WHERE FeeID = ?";
        $stmt = prepare($deleteFeeQuery);
        $stmt->bind_param("s", $feeId);
        $stmt->execute();
        
        // Commit transaction
        $GLOBALS['db_connection']->commit();
        
        $_SESSION['success_message'] = "Membership Fee #$feeId was successfully deleted.";
    } catch(Exception $e) {
        // Rollback on error
        $GLOBALS['db_connection']->rollback();
        $_SESSION['error_message'] = "Error deleting fee: " . $e->getMessage();
    }
    
    // Redirect back to fee page with pagination preserved
    $page = isset($_GET['page']) ? $_GET['page'] : 1;
    header("Location: editMFDetails.php?year=" . $currentYear . 
           (isset($_GET['type']) ? "&type=" . $_GET['type'] : "") . 
           (isset($_GET['month']) ? "&month=" . $_GET['month'] : "") . 
           "&page=" . $page);
    exit();
}

// Function to check if financial report for a specific term is approved
function isReportApproved($year) {
    $stmt = prepare("
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

// Define months array
$months = [
    0 => 'All Months',
    1 => 'January', 2 => 'February', 3 => 'March', 
    4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September',
    10 => 'October', 11 => 'November', 12 => 'December'
];

// Pagination variables
$recordsPerPage = 10;
$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$selectedType = isset($_GET['type']) ? $_GET['type'] : null;
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : 0; // 0 means all months
$currentPage = isset($_GET['page']) ? intval($_GET['page']) : 1;

// Get total records and calculate total pages
$totalRecords = getTotalMemberFees($selectedYear, $selectedType, $selectedMonth > 0 ? $selectedMonth : null);
$totalPages = ceil($totalRecords / $recordsPerPage);

// Ensure current page is valid
if ($currentPage < 1) {
    $currentPage = 1;
} elseif ($currentPage > $totalPages && $totalPages > 0) {
    $currentPage = $totalPages;
}

$allTerms = getAllTerms();
$feeSettings = getFeeSettings();
$isReportApproved = isReportApproved($selectedYear);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Membership Fee Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
    <link rel="stylesheet" href="../../../assets/css/alert.css">
    <script src="../../../assets/js/alertHandler.js"></script>
    <style>
        .status-paid {
            background-color: #c2f1cd;
            color: rgb(25, 151, 10);
        }
        .status-unpaid {
            background-color: #e2bcc0;
            color: rgb(234, 59, 59);
        }
        /* Modal Styles */
        #feeModal, #editFeeModal {
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

        #feeModal .modal-content, #editFeeModal .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            width: 90%;
            max-width: 900px;
            height: 90%;
            border-radius: 8px;
            position: relative;
        }

        #feeModal .close,#editFeeModal .close {
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

        .table-container {
            margin-top: 5px;
            overflow-x: auto;
            max-height: 900px;
            overflow-y: auto;
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

        .back-button {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .term-selector {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        /* Filter section styles */
        .filters-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 10px 0;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        /* Type filter styles */
        .type-filter {
            display: flex;
            gap: 10px;
            margin: 10px 0;
        }
        
        .type-filter button {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f8f8f8;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .type-filter button.active {
            background-color: #4a6eb5;
            color: white;
            border-color: #4a6eb5;
        }
        
        .type-filter button:hover:not(.active) {
            background-color: #e0e0e0;
        }
        
        /* Month filter styles */
        .month-filter {
            margin: 10px 0;
        }
        
        .month-filter select {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f8f8f8;
            cursor: pointer;
            transition: all 0.3s;
            min-width: 150px;
        }
        
        .month-filter select:hover {
            background-color: #e0e0e0;
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
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .filters-container {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .type-filter, .month-filter {
                width: 100%;
            }
            
            .month-filter select {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Edit Membership Fee Details</h1>
            <div class="term-selector">
            <select class="filter-select" onchange="updateFilters()" id="yearSelect">
                <?php while($term = $allTerms->fetch_assoc()): ?>
                    <option value="<?php echo $term['year']; ?>" <?php echo $term['year'] == $selectedYear ? 'selected' : ''; ?>>
                        Year <?php echo $term['year']; ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <a href="membershipFee.php" class="back-button">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            </div>
        </div>

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

        <div class="tabs">
            <div class="filters-container">
                <div class="type-filter">
                    <button onclick="filterByType('all')" class="<?php echo !isset($_GET['type']) ? 'active' : ''; ?>">All Types</button>
                    <button onclick="filterByType('registration')" class="<?php echo isset($_GET['type']) && $_GET['type'] === 'registration' ? 'active' : ''; ?>">Registration</button>
                    <button onclick="filterByType('monthly')" class="<?php echo isset($_GET['type']) && $_GET['type'] === 'monthly' ? 'active' : ''; ?>">Monthly Fee</button>
                </div>
                <div class="month-filter">
                    <select id="monthSelect" onchange="updateFilters()">
                        <?php foreach($months as $month_num => $month_name): ?>
                            <option value="<?php echo $month_num; ?>" <?php echo $month_num == $selectedMonth ? 'selected' : ''; ?>>
                                <?php echo $month_name; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="filters">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search by Name, Member ID, or Fee ID..." class="search-input">
                    <button onclick="clearSearch()" class="clear-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>

        <div id="members-view">
            <div class="fee-type-header">
                <h2>Membership Fee Details (Registration: Rs. <?php echo number_format($feeSettings['registration_fee'], 2); ?>, Monthly: Rs. <?php echo number_format($feeSettings['monthly_fee'], 2); ?>)</h2>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Fee ID</th>
                            <th>Member ID</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    $memberFees = getMemberFees(
                        $selectedYear, 
                        $selectedType, 
                        $selectedMonth > 0 ? $selectedMonth : null, 
                        $currentPage, 
                        $recordsPerPage
                    );

                    while($row = $memberFees->fetch_assoc()): 
                        // Set the status badge class based on payment status
                        $statusClass = $row['IsPaid'] === 'Yes' ? 'status-paid' : 'status-unpaid';
                        $statusLabel = $row['IsPaid'] === 'Yes' ? 'Paid' : 'Unpaid';
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['FeeID']); ?></td>
                        <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                        <td><?php echo htmlspecialchars($row['Name']); ?></td>
                        <td><?php echo ucfirst(htmlspecialchars($row['Type'])); ?></td>
                        <td>Rs. <?php echo number_format($row['Amount'] ?? 0, 2); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($row['Date'])); ?></td>
                        <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                        <td class="actions">
                            <button onclick="viewFee('<?php echo $row['FeeID']; ?>')" class="action-btn small">
                                <i class="fas fa-eye"></i>
                            </button>
                            
                            <?php if (!$isReportApproved): ?>
                                <button onclick="editFee('<?php echo $row['FeeID']; ?>')" class="action-btn small">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="openDeleteModal('<?php echo $row['FeeID']; ?>')" class="action-btn small">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php else: ?>
                                <button onclick="showReportMessage()" class="action-btn small info-btn" title="Report approved">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
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
    </div>
    <?php include '../../templates/footer.php'; ?>
    </div>

    <div id="feeModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeFeeModal()">&times;</span>
            <iframe id="feeFrame" class="modal-iframe"></iframe>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this membership fee record? This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" id="delete_fee_id" name="fee_id">
                <div class="delete-modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete_fee" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Fee Modal -->
    <div id="editFeeModal" class="modal">
        <div class="modal-content" style="max-width: 90%; height: 90%;">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <iframe id="editFeeFrame" style="width: 100%; height: 90%; border: none;"></iframe>
        </div>
    </div>

    <script>
        function viewFee(FeeID) {
            // Set the iframe source to your viewMembershipFee.php page
            document.getElementById('feeFrame').src = `viewMembershipFee.php?id=${FeeID}&popup=true`;
            
            // Show the modal
            document.getElementById('feeModal').style.display = 'block';
        }

        function closeFeeModal() {
            document.getElementById('feeModal').style.display = 'none';
        }

        // Edit Fee Modal Functions
        function editFee(feeID) {
            // Set the iframe source to your editFee.php page
            document.getElementById('editFeeFrame').src = `editMembershipFee.php?id=${feeID}&popup=true`;
            
            // Show the modal
            document.getElementById('editFeeModal').style.display = 'block';
        }

        function closeEditModal() {
            // Just close the modal without refreshing
            document.getElementById('editFeeModal').style.display = 'none';
        }

        function filterByType(type) {
            const year = document.getElementById('yearSelect').value;
            const month = document.getElementById('monthSelect').value;
            
            let url = `editMFDetails.php?year=${year}`;
            
            if (type !== 'all') {
                url += `&type=${type}`;
            }
            
            if (month > 0) {
                url += `&month=${month}`;
            }
            
            // Reset to first page when changing filters
            url += `&page=1`;
            
            window.location.href = url;
        }

        function updateFilters() {
            const year = document.getElementById('yearSelect').value;
            const urlParams = new URLSearchParams(window.location.search);
            const type = urlParams.get('type');
            const month = document.getElementById('monthSelect').value;
            
            let url = `editMFDetails.php?year=${year}`;
            
            if (type) {
                url += `&type=${type}`;
            }
            
            if (month > 0) {
                url += `&month=${month}`;
            }
            
            // Instead of redirecting, just update the current URL without reloading
            window.history.pushState({}, '', url);
            
            // Preserve any success messages that are currently visible
            const alertsContainer = document.querySelector('.alerts-container');
            const existingAlerts = alertsContainer.innerHTML;
            
            // Now use fetch to get the updated content
            fetch(url)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Update the table container with new data
                    document.querySelector('.table-container').innerHTML = 
                        doc.querySelector('.table-container').innerHTML;
                    
                    // Keep existing alerts instead of replacing with new ones
                    if (existingAlerts.trim()) {
                        alertsContainer.innerHTML = existingAlerts;
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Pagination function
        function goToPage(page) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('page', page);
            window.location.href = 'editMFDetails.php?' + urlParams.toString();
        }

        // Search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            searchInput.addEventListener('input', performSearch);
        });

        function performSearch() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const tableRows = document.querySelectorAll('#members-view tbody tr');
            let hasResults = false;

            tableRows.forEach(row => {
                const memberID = row.cells[0].textContent.toLowerCase();
                const name = row.cells[1].textContent.toLowerCase();
                const feeID = row.cells[2].textContent.toLowerCase();
                
                if (name.includes(searchTerm) || 
                    memberID.includes(searchTerm) || 
                    feeID.includes(searchTerm)) {
                    row.style.display = '';
                    hasResults = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Show/hide no results message
            let noResultsMsg = document.querySelector('.no-results');
            if (!hasResults) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.className = 'no-results';
                    noResultsMsg.textContent = 'No matching records found';
                    const table = document.querySelector('#members-view .table-container');
                    table.appendChild(noResultsMsg);
                }
                noResultsMsg.style.display = 'block';
            } else if (noResultsMsg) {
                noResultsMsg.style.display = 'none';
            }
            
            // Hide pagination when searching
            const pagination = document.querySelector('.pagination');
            if (pagination) {
                pagination.style.display = searchTerm ? 'none' : 'flex';
            }
        }

        function clearSearch() {
            const searchInput = document.getElementById('searchInput');
            searchInput.value = '';
            performSearch();
            searchInput.focus();
            
            // Show pagination again
            const pagination = document.querySelector('.pagination');
            if (pagination) {
                pagination.style.display = 'flex';
            }
        }

        function openDeleteModal(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_fee_id').value = id;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Update window.onclick to handle all modals
        window.onclick = function(event) {
            const feeModal = document.getElementById('feeModal');
            const deleteModal = document.getElementById('deleteModal');
            const editModal = document.getElementById('editFeeModal');
            
            if (event.target == feeModal) {
                closeFeeModal();
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
            alertDiv.className = type === 'success' ? 'alert alert-success' : 
                                type === 'info' ? 'alert alert-info' : 'alert alert-danger';
            alertDiv.textContent = message;
            
            // Clear previous alerts
            alertsContainer.innerHTML = '';
            
            // Add new alert
            alertsContainer.appendChild(alertDiv);
            
            // Scroll to top to see the alert
            window.scrollTo(0, 0);
            
            // Add close button
            const closeBtn = document.createElement('span');
            closeBtn.innerHTML = '&times;';
            closeBtn.className = 'alert-close';
            closeBtn.style.float = 'right';
            closeBtn.style.cursor = 'pointer';
            closeBtn.style.fontWeight = 'bold';
            closeBtn.style.fontSize = '20px';
            closeBtn.style.marginLeft = '15px';
            
            // Proper event listener for closing the alert
            closeBtn.addEventListener('click', function() {
                alertDiv.style.display = 'none';
            });
            
            // Insert close button at the beginning of the alert
            alertDiv.insertBefore(closeBtn, alertDiv.firstChild);
            
            // Auto-hide alerts after 5 seconds for all alert types
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
    </script>
</body>
</html>