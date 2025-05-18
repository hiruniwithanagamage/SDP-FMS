<?php
session_start();
require_once "../../../config/database.php";

// Function to get current term/year
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get all available terms
function getAllTerms() {
    $sql = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
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

// Get expenses based on filters with pagination
function getExpenses($year, $category = '', $method = '', $fromDate = '', $toDate = '', $page = 1, $recordsPerPage = 10) {
    // Calculate the starting point for the LIMIT clause
    $start = ($page - 1) * $recordsPerPage;
    
    $sql = "SELECT e.*, t.Name as TreasurerName 
            FROM Expenses e 
            LEFT JOIN Treasurer t ON e.Treasurer_TreasurerID = t.TreasurerID
            WHERE e.Term = $year";
    
    if (!empty($category)) {
        $sql .= " AND e.Category = '$category'";
    }
    if (!empty($method)) {
        $sql .= " AND e.Method = '$method'";
    }
    if (!empty($fromDate)) {
        $sql .= " AND e.Date >= '$fromDate'";
    }
    if (!empty($toDate)) {
        $sql .= " AND e.Date <= '$toDate'";
    }
    
    $sql .= " ORDER BY e.ExpenseID ASC LIMIT $start, $recordsPerPage";
    
    return search($sql);
}

// Get total count of records for pagination
function getTotalExpenses($year, $category = '', $method = '', $fromDate = '', $toDate = '') {
    $sql = "SELECT COUNT(*) as total 
            FROM Expenses e 
            WHERE e.Term = $year";
    
    if (!empty($category)) {
        $sql .= " AND e.Category = '$category'";
    }
    if (!empty($method)) {
        $sql .= " AND e.Method = '$method'";
    }
    if (!empty($fromDate)) {
        $sql .= " AND e.Date >= '$fromDate'";
    }
    if (!empty($toDate)) {
        $sql .= " AND e.Date <= '$toDate'";
    }
    
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['total'];
}

// Get expense summary for the year
function getExpenseSummary($year) {
    $sql = "SELECT 
            COUNT(*) as total_expenses,
            SUM(Amount) as total_amount
        FROM Expenses
        WHERE Term = $year";
    
    return search($sql);
}

// Get monthly expense statistics
function getMonthlyExpenseStats($year) {
    $sql = "SELECT 
            MONTH(Date) as month,
            COUNT(*) as expenses_count,
            SUM(Amount) as total_amount
        FROM Expenses
        WHERE Term = $year
        GROUP BY MONTH(Date)
        ORDER BY month";
    
    return search($sql);
}

// Get expense breakdown by category
function getCategoryBreakdown($year) {
    $sql = "SELECT 
            Category,
            COUNT(*) as count,
            SUM(Amount) as total_amount
        FROM Expenses
        WHERE Term = $year
        GROUP BY Category
        ORDER BY total_amount DESC";
    
    return search($sql);
}

// Handle Delete Expense
if(isset($_GET['delete']) && isset($_GET['id'])) {
    $expenseId = $_GET['id'];
    $currentYear = isset($_GET['year']) ? $_GET['year'] : getCurrentTerm();
    
    // First check if this expense is an Adjustment - if so, do not allow deletion
    $checkCategoryQuery = "SELECT Category FROM Expenses WHERE ExpenseID = ?";
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // Check if this is an Adjustment expense
        $stmt = $conn->prepare($checkCategoryQuery);
        $stmt->bind_param("s", $expenseId);
        $stmt->execute();
        $categoryResult = $stmt->get_result();
        $categoryRow = $categoryResult->fetch_assoc();
        
        if($categoryRow && $categoryRow['Category'] === 'Adjustment') {
            $_SESSION['error_message'] = "Cannot delete this expense as it is an Adjustment record.";
        } else {
            // Then check if this expense is linked to a Death Welfare
            $checkQuery = "SELECT * FROM DeathWelfare WHERE Expense_ExpenseID = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("s", $expenseId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if($result->num_rows > 0) {
                $_SESSION['error_message'] = "Cannot delete this expense as it is linked to a Death Welfare record.";
            } else {
                // Get the original expense data before deletion (for logging)
                $getExpenseQuery = "SELECT * FROM Expenses WHERE ExpenseID = ?";
                $stmt = $conn->prepare($getExpenseQuery);
                $stmt->bind_param("s", $expenseId);
                $stmt->execute();
                $expenseResult = $stmt->get_result();
                $expenseData = $expenseResult->fetch_assoc();
                
                // Get current treasurer ID (assuming it's stored in session)
                $treasurerId = $_SESSION['user_id'] ?? $expenseData['Treasurer_TreasurerID'];
                
                // Get member ID (from the treasurer table, linked by treasurer ID)
                $getMemberQuery = "SELECT MemberID FROM Treasurer WHERE TreasurerID = ?";
                $stmt = $conn->prepare($getMemberQuery);
                $stmt->bind_param("s", $treasurerId);
                $stmt->execute();
                $memberResult = $stmt->get_result();
                $memberData = $memberResult->fetch_assoc();
                $memberId = $memberData['MemberID'] ?? 'UNKNOWN';
                
                // Convert expense data to JSON for logging
                $oldValues = json_encode($expenseData);
                $newValues = "{}"; // Empty JSON object for deletion
                $changeDetails = "Expense #$expenseId was deleted";
                
                // Delete the expense
                $deleteQuery = "DELETE FROM Expenses WHERE ExpenseID = ?";
                $stmt = $conn->prepare($deleteQuery);
                $stmt->bind_param("s", $expenseId);
                $stmt->execute();
                
                // Log the deletion to ChangeLog table
                $logQuery = "INSERT INTO ChangeLog (RecordType, RecordID, MemberID, OldValues, NewValues, ChangeDetails, TreasurerID, Status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, 'Not Read')";
                $stmt = $conn->prepare($logQuery);
                $recordType = "Expense";
                $stmt->bind_param("sssssss", $recordType, $expenseId, $memberId, $oldValues, $newValues, $changeDetails, $treasurerId);
                $stmt->execute();
                
                $conn->commit();
                $_SESSION['success_message'] = "Expense #$expenseId was successfully deleted.";
            }
        }
    } catch(Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting expense: " . $e->getMessage();
    }
    
    // Redirect back to expenses page
    header("Location: trackExpenses.php?year=" . $currentYear);
    exit();
}

// Pagination variables
$recordsPerPage = 10;
$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$currentPage = isset($_GET['page']) ? intval($_GET['page']) : 1;
$allTerms = getAllTerms();

// Initialize filter parameters
$category = isset($_GET['category']) ? $_GET['category'] : '';
$method = isset($_GET['method']) ? $_GET['method'] : '';
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// Get total records and calculate total pages
$totalRecords = getTotalExpenses($selectedYear, $category, $method, $fromDate, $toDate);
$totalPages = ceil($totalRecords / $recordsPerPage);

// Ensure current page is valid
if ($currentPage < 1) {
    $currentPage = 1;
} elseif ($currentPage > $totalPages && $totalPages > 0) {
    $currentPage = $totalPages;
}

$expenseSummary = getExpenseSummary($selectedYear);
$monthlyStats = getMonthlyExpenseStats($selectedYear);
$categoryBreakdown = getCategoryBreakdown($selectedYear);

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 
    4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September',
    10 => 'October', 11 => 'November', 12 => 'December'
];

$isReportApproved = isReportApproved($selectedYear);
$scrollToTable = isset($_GET['scrollToTable']) ? true : false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expense Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
    <link rel="stylesheet" href="../../../assets/css/alert.css">
    <script src="../../../assets/js/alertHandler.js"></script>
    <style>
        .status-approved {
            background-color: #c2f1cd;
            color: rgb(25, 151, 10);
        }
        .status-rejected {
            background-color: #e2bcc0;
            color: rgb(234, 59, 59);
        }
        
        /* Modal Styles */
        #expenseModal, #editExpenseModal {
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

        #expenseModal .modal-content, #editExpenseModal .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            width: 90%;
            max-width: 900px;
            height: 90%;
            border-radius: 8px;
            position: relative;
        }

        #expenseModal .close, #editExpenseModal .close {
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
        
        .receipt-preview {
            max-width: 60px;
            max-height: 60px;
            cursor: pointer;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
        }
        
        #receiptModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            overflow: auto;
        }
        
        #receiptModal .modal-content {
            margin: 5% auto;
            padding: 20px;
            max-width: 800px;
            background-color: #fff;
            border-radius: 8px;
            position: relative;
        }
        
        .receipt-image {
            max-width: 100%;
            display: block;
            margin: 0 auto;
        }
        
        /* Delete Modal */
        #deleteModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .delete-modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 30%;
            border-radius: 8px;
        }
        
        .delete-modal-buttons {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
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
        
        .confirm-delete-btn {
            background-color: #f44336;
            color: white;
            border: none;
            padding: 8px 16px;
            cursor: pointer;
            border-radius: 4px;
        }
        .filter-dropdown {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
            background-color: #fff;
            margin-left: 10px;
            min-width: 150px;
        }

        .filter-dropdown:focus {
            border-color: #1e3c72;
            outline: none;
            box-shadow: 0 0 0 2px rgba(30, 60, 114, 0.2);
        }

        .filters {
            display: flex;
            align-items: center;
            margin-left: auto;
            flex-wrap: wrap;
        }

        .search-container {
            position: relative;
            display: flex;
            align-items: center;
            flex: 1;
            min-width: 200px;
        }
        .search-input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
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
        
        /* Responsive styles for filters */
        @media screen and (max-width: 900px) {
            .filters {
                flex-direction: column;
                align-items: flex-start;
                margin-top: 10px;
                width: 100%;
            }
            
            .search-container {
                width: 100%;
                margin-bottom: 10px;
            }
            
            .search-input {
                width: 100%;
            }
            
            .filter-dropdown {
                width: 100%;
                margin-left: 0;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Expense Details</h1>
            <select class="filter-select" onchange="updateFilters()" id="yearSelect">
                <?php while($term = $allTerms->fetch_assoc()): ?>
                    <option value="<?php echo $term['year']; ?>" <?php echo $term['year'] == $selectedYear ? 'selected' : ''; ?>>
                        Year <?php echo $term['year']; ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <!-- Alerts container -->
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
            $stats = $expenseSummary->fetch_assoc();
            ?>
            <div class="stat-card">
                <i class="fas fa-chart-line"></i>
                <div class="stat-number">Rs. <?php echo number_format($stats['total_amount'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Expenses (<?php echo $selectedYear; ?>)</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-receipt"></i>
                <div class="stat-number"><?php echo $stats['total_expenses'] ?? 0; ?></div>
                <div class="stat-label">Number of Transactions</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-money-bill-wave"></i>
                <div class="stat-number">
                    <?php 
                    $avgAmount = 0;
                    if (($stats['total_expenses'] ?? 0) > 0) {
                        $avgAmount = ($stats['total_amount'] ?? 0) / ($stats['total_expenses'] ?? 1);
                    }
                    echo 'Rs. ' . number_format($avgAmount, 2); 
                    ?>
                </div>
                <div class="stat-label">Average Transaction Amount</div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('expense')">Expense-wise View</button>
            <button class="tab" onclick="showTab('category')">Category-wise View</button>
            <button class="tab" onclick="showTab('months')">Month-wise View</button>
            <div class="filters">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search by ID, Category, or Amount..." class="search-input">
                    <button onclick="clearSearch()" class="clear-btn"><i class="fas fa-times"></i></button>
                </div>
                
                <!-- Add category filter dropdown -->
                <select id="categoryFilter" onchange="filterExpenses()" class="filter-dropdown">
                    <option value="">All Categories</option>
                    <option value="Death Welfare">Death Welfare</option>
                    <option value="Administrative">Administrative</option>
                    <option value="Utility">Utility</option>
                    <option value="Maintenance">Maintenance</option>
                    <option value="Event">Event</option>
                    <option value="Other">Other</option>
                </select>
                
                <!-- Add payment method filter dropdown -->
                <select id="methodFilter" onchange="filterExpenses()" class="filter-dropdown">
                    <option value="">All Methods</option>
                    <option value="Cash">Cash</option>
                    <option value="Check">Check</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Digital Payment">Digital Payment</option>
                </select>
            </div>
        </div>

        <div id="expense-view">
            <div class="fee-type-header">
                <h2>Expense Details</h2>
                <a href="../addExpenses.php" class="btn-add">
                    <i class="fas fa-plus"></i> Add New Expense
                </a>
            </div>
            <div id = "table-container" class="table-container" style="max-height: 900px;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Date</th>
                            <th>Treasurer</th>
                            <th>Receipt</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    $expenses = getExpenses($selectedYear, $category, $method, $fromDate, $toDate, $currentPage, $recordsPerPage);
                    
                    while($row = $expenses->fetch_assoc()): 
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['ExpenseID']); ?></td>
                        <td><?php echo htmlspecialchars($row['Category']); ?></td>
                        <td>Rs. <?php echo number_format($row['Amount'] ?? 0, 2); ?></td>
                        <td><?php echo htmlspecialchars($row['Method']); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($row['Date'])); ?></td>
                        <td><?php echo htmlspecialchars($row['TreasurerName']); ?></td>
                        <td>
                            <?php if (!empty($row['Image'])): ?>
                                <img src="../../../<?php echo $row['Image']; ?>" alt="Receipt" class="receipt-preview" onclick="showReceiptModal('../../<?php echo $row['Image']; ?>')">
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <button onclick="viewExpense('<?php echo $row['ExpenseID']; ?>')" class="action-btn small">
                                <i class="fas fa-eye"></i>
                            </button>
                            
                            <?php if (!$isReportApproved): ?>
                                <button onclick="editExpense('<?php echo $row['ExpenseID']; ?>')" class="action-btn small">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="openDeleteModal('<?php echo $row['ExpenseID']; ?>')" class="action-btn small">
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
                
                <!-- Pagination Controls -->
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
            </div>
        </div>  

        <div id="category-view" style="display: none;">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Transactions</th>
                            <th>Total Amount</th>
                            <th>Average Amount</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $totalAmount = $stats['total_amount'] ?? 0;
                        while($row = $categoryBreakdown->fetch_assoc()): 
                            $percentage = ($totalAmount > 0) ? ($row['total_amount'] / $totalAmount) * 100 : 0;
                            $avgAmount = ($row['count'] > 0) ? $row['total_amount'] / $row['count'] : 0;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['Category']); ?></td>
                            <td><?php echo $row['count']; ?></td>
                            <td>Rs. <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td>Rs. <?php echo number_format($avgAmount, 2); ?></td>
                            <td><?php echo number_format($percentage, 1); ?>%</td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="months-view" style="display: none;">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Transactions</th>
                            <th>Total Amount</th>
                            <th>Average Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $monthlyStats->fetch_assoc()): 
                            $avgAmount = ($row['expenses_count'] > 0) ? $row['total_amount'] / $row['expenses_count'] : 0;
                        ?>
                        <tr>
                            <td><?php echo $months[$row['month']]; ?></td>
                            <td><?php echo $row['expenses_count']; ?></td>
                            <td>Rs. <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td>Rs. <?php echo number_format($avgAmount, 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php include '../../templates/footer.php'; ?>
    </div>

    <div id="expenseModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeExpenseModal()">&times;</span>
            <iframe id="expenseFrame" class="modal-iframe"></iframe>
        </div>
    </div>
    
    <!-- Edit Expense Modal -->
    <div id="editExpenseModal" class="modal">
        <div class="modal-content" style="max-width: 90%; height: 90%;">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <iframe id="editExpenseFrame" style="width: 100%; height: 90%; border: none;"></iframe>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div id="receiptModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeReceiptModal()">&times;</span>
            <h2>Receipt Image</h2>
            <img id="fullReceiptImage" src="" alt="Receipt" class="receipt-image">
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this expense record? This action cannot be undone.</p>
            <form method="GET">
                <input type="hidden" id="delete_expense_id" name="id">
                <input type="hidden" name="delete" value="true">
                <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                <input type="hidden" name="page" value="<?php echo $currentPage; ?>">
                <div class="delete-modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function viewExpense(expenseID) {
            // Set the iframe source to your viewExpense.php page
            document.getElementById('expenseFrame').src = `viewExpense.php?id=${expenseID}&popup=true`;
            
            // Show the modal
            document.getElementById('expenseModal').style.display = 'block';
        }

        function closeExpenseModal() {
            document.getElementById('expenseModal').style.display = 'none';
        }
        
        // Edit Expense Modal Functions
        function editExpense(expenseID) {
            // Set the iframe source to your editExpense.php page
            document.getElementById('editExpenseFrame').src = `editExpense.php?id=${expenseID}&popup=true`;
            
            // Show the modal
            document.getElementById('editExpenseModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editExpenseModal').style.display = 'none';
            
            // After closing, refresh the expense list to see any changes
            updateFilters();
        }
        
        // Show receipt image in modal
        function showReceiptModal(imageSrc) {
            document.getElementById('fullReceiptImage').src = imageSrc;
            document.getElementById('receiptModal').style.display = 'block';
        }
        
        // Close receipt modal
        function closeReceiptModal() {
            document.getElementById('receiptModal').style.display = 'none';
        }

        // Pagination function
        function goToPage(page) {
            // Build the URL with all current filters plus the new page
            const year = document.getElementById('yearSelect').value;
            const category = document.getElementById('categoryFilter')?.value || '';
            const method = document.getElementById('methodFilter')?.value || '';
            
            // Add scrollToTable parameter to indicate we should scroll to the table
            window.location.href = `trackExpenses.php?year=${year}&category=${category}&method=${method}&page=${page}&scrollToTable=true`;
        }

        // Add scrolling logic to the DOMContentLoaded event
        document.addEventListener('DOMContentLoaded', function() {
            // Check if we need to scroll to the table
            const urlParams = new URLSearchParams(window.location.search);
            const shouldScrollToTable = urlParams.get('scrollToTable') === 'true';
            
            if (shouldScrollToTable) {
                // Get the table container element
                const tableContainer = document.getElementById('table-container');
                if (tableContainer) {
                    // Scroll directly to the table container without animation
                    const tableTop = tableContainer.getBoundingClientRect().top + window.pageYOffset - 80;
                    window.scrollTo(0, tableTop);
                }
            }
            
            // Rest of your initialization code
            addEventListeners();
        });

        function updateFilters() {
            const year = document.getElementById('yearSelect').value;
            const category = document.getElementById('categoryFilter')?.value || '';
            const method = document.getElementById('methodFilter')?.value || '';
            
            // Reset to page 1 when changing filters
            window.location.href = `trackExpenses.php?year=${year}&category=${category}&method=${method}&page=1`;
        }

        function filterExpenses() {
            updateFilters();
        }

        // Function to reattach event listeners after DOM updates
        function addEventListeners() {
            // Add event listeners for search input
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', performSearch);
            }
            
            // Check if a modal might need to show alerts
            const editModal = document.getElementById('editExpenseModal');
            if (editModal && editModal.style.display === 'block') {
                // If there are stored alerts, show them
                const alertType = sessionStorage.getItem('alertType');
                const alertMessage = sessionStorage.getItem('alertMessage');
                
                if (alertType && alertMessage) {
                    showAlert(alertType, alertMessage);
                    sessionStorage.removeItem('alertType');
                    sessionStorage.removeItem('alertMessage');
                }
            }
        }

        // Add this to the DOMContentLoaded event
        document.addEventListener('DOMContentLoaded', function() {
            // Check if there's a saved position to scroll to
            const savedPosition = sessionStorage.getItem('tablePosition');
            if (savedPosition) {
                // Clear it immediately to prevent it being used again
                sessionStorage.removeItem('tablePosition');
                
                // Scroll to the saved position
                window.scrollTo({
                    top: parseInt(savedPosition),
                    behavior: 'smooth'
                });
            }

            addEventListeners();
        });

        function showTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById('expense-view').style.display = 'none';
            document.getElementById('category-view').style.display = 'none';
            document.getElementById('months-view').style.display = 'none';
            
            if (tab === 'expense') {
                document.getElementById('expense-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'expense\')"]').classList.add('active');
            } else if (tab === 'category') {
                document.getElementById('category-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'category\')"]').classList.add('active');
            } else {
                document.getElementById('months-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'months\')"]').classList.add('active');
            }
        }

        // Search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', performSearch);
            }
        });

        function performSearch() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const tableRows = document.querySelectorAll('#expense-view tbody tr');
            let hasResults = false;

            tableRows.forEach(row => {
                const expenseID = row.cells[0].textContent.toLowerCase();
                const category = row.cells[1].textContent.toLowerCase();
                const amount = row.cells[2].textContent.toLowerCase();
                
                if (expenseID.includes(searchTerm) || 
                    category.includes(searchTerm) || 
                    amount.includes(searchTerm)) {
                    row.style.display = '';
                    hasResults = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Show/hide no results message
            let noResultsMsg = document.querySelector('.no-results');
            if (!hasResults && tableRows.length > 0) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.className = 'no-results';
                    noResultsMsg.textContent = 'No matching records found';
                    const table = document.querySelector('#expense-view .table-container');
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
            document.getElementById('delete_expense_id').value = id;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Update window.onclick to handle all modals
        window.onclick = function(event) {
            const expenseModal = document.getElementById('expenseModal');
            const editModal = document.getElementById('editExpenseModal');
            const receiptModal = document.getElementById('receiptModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target == expenseModal) {
                closeExpenseModal();
            }
            
            if (event.target == editModal) {
                closeEditModal();
            }
            
            if (event.target == receiptModal) {
                closeReceiptModal();
            }
            
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        };

        // Function to create and show alerts programmatically
        function showAlert(type, message) {
            // Store the alert in sessionStorage
            sessionStorage.setItem('alertType', type);
            sessionStorage.setItem('alertMessage', message);
            
            // Create and display the alert (original code)
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
        
        // Check for stored alerts on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check if there are any stored alerts
            const alertType = sessionStorage.getItem('alertType');
            const alertMessage = sessionStorage.getItem('alertMessage');
            
            if (alertType && alertMessage) {
                // Display the alert
                showAlert(alertType, alertMessage);
                
                // Clear the stored alert
                sessionStorage.removeItem('alertType');
                sessionStorage.removeItem('alertMessage');
            }
            
            // Rest of your DOMContentLoaded code...
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', performSearch);
            }
        });

        function showReportMessage() {
            showAlert('info', 'This record cannot be modified as the financial report for this term has already been approved.');
        }
    </script>
</body>
</html>