<?php
session_start();
require_once "../../../config/database.php";

// Get current term
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE Status = 'active'";
    $stmt = prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get members with monthly fee status for all months
function getMemberMonthlyPayments($year, $page = 1, $recordsPerPage = 5) {
    // Calculate offset for pagination
    $offset = ($page - 1) * $recordsPerPage;
    
    $sql = "SELECT 
            m.MemberID,
            m.Name,
            GROUP_CONCAT(
                CASE 
                    WHEN mf.IsPaid = 'Yes' AND mf.Type = 'Monthly' 
                    THEN MONTH(mf.Date) 
                    ELSE NULL 
                END
            ) as paid_months,
            SUM(CASE WHEN mf.Type = 'Monthly' AND mf.IsPaid = 'Yes' THEN mf.Amount ELSE 0 END) as total_paid
        FROM Member m
        LEFT JOIN MembershipFee mf ON m.MemberID = mf.Member_MemberID 
        AND YEAR(mf.Date) = ?
        GROUP BY m.MemberID, m.Name
        ORDER BY m.MemberID
        LIMIT ?, ?";
    
    $stmt = prepare($sql);
    $stmt->bind_param("iii", $year, $offset, $recordsPerPage);
    $stmt->execute();
    return $stmt->get_result();
}

// Get total count of members for pagination
function getTotalMembers() {
    $sql = "SELECT COUNT(*) as total FROM Member";
    $stmt = prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['total'];
}

// Get registration fee status - MODIFIED FUNCTION
function getRegistrationFeeStatus($year, $page = 1, $recordsPerPage = 5) {
    // Calculate offset for pagination
    $offset = ($page - 1) * $recordsPerPage;
    
    // Get the registration fee amount from Static table to compare with
    $staticFee = getFeeAmounts();
    $requiredAmount = $staticFee['registration_fee'];
    
    $sql = "SELECT 
            m.MemberID,
            m.Name,
            COALESCE((
                SELECT SUM(p.Amount)
                FROM Payment p
                JOIN MembershipFeePayment mfp ON p.PaymentID = mfp.PaymentID
                JOIN MembershipFee mf ON mfp.FeeID = mf.FeeID
                WHERE mf.Member_MemberID = m.MemberID 
                AND mf.Type = 'Registration'
            ), 0) as paid_amount,
            CASE 
                WHEN COALESCE((
                    SELECT SUM(p.Amount)
                    FROM Payment p
                    JOIN MembershipFeePayment mfp ON p.PaymentID = mfp.PaymentID
                    JOIN MembershipFee mf ON mfp.FeeID = mf.FeeID
                    WHERE mf.Member_MemberID = m.MemberID 
                    AND mf.Type = 'Registration'
                ), 0) >= ? THEN 'Yes'
                ELSE 'No'
            END as IsPaid,
            (SELECT MAX(p.Date) 
             FROM Payment p
             JOIN MembershipFeePayment mfp ON p.PaymentID = mfp.PaymentID
             JOIN MembershipFee mf ON mfp.FeeID = mf.FeeID
             WHERE mf.Member_MemberID = m.MemberID 
             AND mf.Type = 'Registration') as payment_date
        FROM Member m
        ORDER BY m.MemberID
        LIMIT ?, ?";
    
    $stmt = prepare($sql);
    $stmt->bind_param("dii", $requiredAmount, $offset, $recordsPerPage);
    $stmt->execute();
    return $stmt->get_result();
}

// Get fee amounts from Static table
function getFeeAmounts() {
    $sql = "SELECT monthly_fee, registration_fee FROM Static ORDER BY year DESC LIMIT 1";
    $stmt = prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Get monthly summary
function getMonthlyPaymentSummary($year) {
    $sql = "SELECT 
            MONTH(mf.Date) as month,
            (SELECT COUNT(*) FROM Member) as all_members,
            COUNT(*) as paid_members,
            SUM(mf.Amount) as total_amount
        FROM MembershipFee mf
        WHERE YEAR(mf.Date) = ? AND mf.Type = 'Monthly' AND mf.IsPaid = 'Yes'
        GROUP BY MONTH(mf.Date)
        ORDER BY month";
    
    $stmt = prepare($sql);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    return $stmt->get_result();
}

// Get all available terms/years
function getAllTerms() {
    $sql = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
    $stmt = prepare($sql);
    $stmt->execute();
    return $stmt->get_result();
}

$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$allTerms = getAllTerms();

// Pagination parameters
$recordsPerPage = 5; // Changed from 10 to 5
$currentPage = isset($_GET['page']) ? intval($_GET['page']) : 1;
$totalMembers = getTotalMembers();
$totalPages = ceil($totalMembers / $recordsPerPage);

// Get the selected view from URL parameter
$selectedView = isset($_GET['view']) ? $_GET['view'] : 'members';

// Ensure current page is valid
if ($currentPage < 1) {
    $currentPage = 1;
} elseif ($currentPage > $totalPages && $totalPages > 0) {
    $currentPage = $totalPages;
}

$monthlyPayments = getMonthlyPaymentSummary($selectedYear);
$registrationFees = getRegistrationFeeStatus($selectedYear, $currentPage, $recordsPerPage);
$feeAmounts = getFeeAmounts();

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 
    4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September',
    10 => 'October', 11 => 'November', 12 => 'December'
];

// Additional query for stats section
$sql = "SELECT 
        (SELECT SUM(Amount) 
        FROM MembershipFee 
        WHERE YEAR(Date) = ? 
        AND Type = 'Monthly' 
        AND IsPaid = 'Yes') as yearly_amount";
    
$stmt = prepare($sql);
$stmt->bind_param("i", $selectedYear);
$stmt->execute();
$result = $stmt->get_result();
$totalAmount = $result->fetch_assoc()['yearly_amount'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Membership Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
    <style>
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
    </style>
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Membership Fee Details</h1>
            <div class="filters">
                <select class="filter-select" onchange="updateFilters()" id="yearSelect">
                    <?php while($currentTerm = $allTerms->fetch_assoc()): ?>
                    <option value="<?php echo $currentTerm['year']; ?>" <?php echo $currentTerm['year'] == $selectedYear ? 'selected' : ''; ?>>
                        Year <?php echo $currentTerm['year']; ?>
                    </option>
                <?php endwhile; ?>
                </select>
            </div>
        </div>

        <div id="stats-section" class="stats-cards">
            <?php
            $memberMonthlyPayments = getMemberMonthlyPayments($selectedYear);
            $totalMembers = 0;
            $paidMembers = 0;
            $totalAmount = 0;
            
            while($row = $memberMonthlyPayments->fetch_assoc()) {
                $totalMembers++;
                if($row['paid_months']) {
                    $paidMembers++;
                    $totalAmount += $row['total_paid'];
                }
            }
            ?>
        </div>

        <div id="stats-section" class="stats-cards">
            <?php
            $sql = "SELECT 
                    (SELECT SUM(Amount) 
                    FROM MembershipFee 
                    WHERE YEAR(Date) = $selectedYear 
                    AND Type = 'Monthly' 
                    AND IsPaid = 'Yes') as yearly_amount";
                
            $result = search($sql);
            $totalAmount = $result->fetch_assoc()['yearly_amount'] ?? 0;
            ?>
            <div class="stat-card">
                <i class="fas fa-money-bill-wave"></i>
                <div class="stat-number">Rs. <?php echo number_format($totalAmount, 2); ?></div>
                <div class="stat-label">Total Membership Fee (<?php echo $selectedYear; ?>)</div>
            </div>
            <button onclick="window.location.href='editMFDetails.php'" class="edit-btn">
                <i class="fas fa-edit"></i>
                Edit Details
            </button>
        </div>

        <div class="tabs">
            <button class="tab <?php echo $selectedView == 'members' ? 'active' : ''; ?>" onclick="showTab('members')">Member-wise View</button>
            <button class="tab <?php echo $selectedView == 'months' ? 'active' : ''; ?>" onclick="showTab('months')">Monthly Summary</button>
            <div class="filters">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search by Name or Member ID..." class="search-input">
                    <button onclick="clearSearch()" class="clear-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>

        <div id="members-view" style="display: <?php echo $selectedView == 'members' ? 'block' : 'none'; ?>">
            <a id="members-anchor"></a> <!-- Anchor point for scrolling -->
            <div class="fee-type-header">
                <h2>Monthly Fee (Rs. <?php echo number_format($feeAmounts['monthly_fee'], 2); ?> per month)</h2>
            </div>
            <div class="table-container" style="max-height:500px;">
            <table>
                <thead>
                    <tr>
                        <th>Member ID</th>
                        <th>Name</th>
                        <th>Monthly Payment Status</th>
                        <th>Total Paid Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $memberMonthlyPayments = getMemberMonthlyPayments($selectedYear, $currentPage, $recordsPerPage);
                    while($row = $memberMonthlyPayments->fetch_assoc()): 
                        $paidMonths = $row['paid_months'] ? explode(',', $row['paid_months']) : [];
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                        <td><?php echo htmlspecialchars($row['Name']); ?></td>
                        <td>
                            <?php foreach($months as $num => $name): ?>
                                <div class="month-cell <?php echo in_array($num, $paidMonths) ? 'month-paid' : 'month-unpaid'; ?>" 
                                     title="<?php echo $name; ?>">
                                    <?php echo substr($name, 0, 1); ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td class="total-cell">Rs. <?php echo number_format($row['total_paid'], 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            
            <!-- Pagination for Member-wise View -->
            <?php if ($totalPages > 0): ?>
            <div class="pagination">
                <div class="pagination-info">
                    Showing <?php echo ($currentPage-1)*$recordsPerPage+1; ?> to 
                    <?php echo min($currentPage*$recordsPerPage, $totalMembers); ?> of 
                    <?php echo $totalMembers; ?> records
                </div>
                
                <!-- First and Previous buttons -->
                <button onclick="goToPage(1, 'members')" 
                        <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                        <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-double-left"></i>
                </button>
                <button onclick="goToPage(<?php echo $currentPage-1; ?>, 'members')" 
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
                    <button onclick="goToPage(<?php echo $i; ?>, 'members')" 
                            class="<?php echo $i == $currentPage ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </button>
                <?php endfor; ?>
                
                <!-- Next and Last buttons -->
                <button onclick="goToPage(<?php echo $currentPage+1; ?>, 'members')" 
                        <?php echo $currentPage == $totalPages ? 'class="disabled"' : ''; ?> 
                        <?php echo $currentPage == $totalPages ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-right"></i>
                </button>
                <button onclick="goToPage(<?php echo $totalPages; ?>, 'members')" 
                        <?php echo $currentPage == $totalPages ? 'class="disabled"' : ''; ?> 
                        <?php echo $currentPage == $totalPages ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-double-right"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div id="months-view" style="display: <?php echo $selectedView == 'months' ? 'block' : 'none'; ?>">
            <a id="months-anchor"></a> <!-- Anchor point for scrolling -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Paid Members</th>
                            <th>Due Members</th>
                            <th>Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $monthlyPayments->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $months[$row['month']]; ?></td>
                            <td><?php echo $row['paid_members']; ?></td>
                            <td><?php echo $row['all_members'] - $row['paid_members']; ?></td>
                            <td>Rs. <?php echo number_format($row['total_amount'], 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Updated Registration View Section -->
        <div id="registration-view">
            <a id="registration-anchor"></a> <!-- Anchor point for scrolling -->
            <div class="fee-type-header">
                <h2>Registration Fee (Rs. <?php echo number_format($feeAmounts['registration_fee'], 2); ?>)</h2>
            </div>
            <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Member ID</th>
                        <th>Name</th>
                        <th>Payment Status</th>
                        <th>Payment Date</th>
                        <th>Paid Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $registrationFees->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                        <td><?php echo htmlspecialchars($row['Name']); ?></td>
                        <td>
                            <span class="status-badge <?php echo $row['IsPaid'] == 'Yes' ? 'status-paid' : 'status-unpaid'; ?>">
                                <?php echo $row['IsPaid'] == 'Yes' ? 'Paid' : 'Due'; ?>
                            </span>
                        </td>
                        <td><?php echo $row['payment_date'] ? date('Y-m-d', strtotime($row['payment_date'])) : '-'; ?></td>
                        <td>Rs. <?php echo number_format($row['paid_amount'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            
            <!-- Pagination for Registration View -->
            <?php if ($totalPages > 0): ?>
            <div class="pagination">
                <div class="pagination-info">
                    Showing <?php echo ($currentPage-1)*$recordsPerPage+1; ?> to 
                    <?php echo min($currentPage*$recordsPerPage, $totalMembers); ?> of 
                    <?php echo $totalMembers; ?> records
                </div>
                
                <!-- First and Previous buttons -->
                <button onclick="goToPage(1, 'registration')" 
                        <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                        <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-double-left"></i>
                </button>
                <button onclick="goToPage(<?php echo $currentPage-1; ?>, 'registration')" 
                        <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                        <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-left"></i>
                </button>
                
                <!-- Page numbers -->
                <?php
                for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <button onclick="goToPage(<?php echo $i; ?>, 'registration')" 
                            class="<?php echo $i == $currentPage ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </button>
                <?php endfor; ?>
                
                <!-- Next and Last buttons -->
                <button onclick="goToPage(<?php echo $currentPage+1; ?>, 'registration')" 
                        <?php echo $currentPage == $totalPages ? 'class="disabled"' : ''; ?> 
                        <?php echo $currentPage == $totalPages ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-right"></i>
                </button>
                <button onclick="goToPage(<?php echo $totalPages; ?>, 'registration')" 
                        <?php echo $currentPage == $totalPages ? 'class="disabled"' : ''; ?> 
                        <?php echo $currentPage == $totalPages ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-double-right"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include '../../templates/footer.php'; ?>
    </div>

    <script>
        function updateFilters() {
            const year = document.getElementById('yearSelect').value;
            const page = <?php echo $currentPage; ?>; // Preserve current page
            const view = document.getElementById('months-view').style.display === 'block' ? 'months' : 'members';

            window.location.href = `?year=${year}&page=${page}&view=${view}`;
        }

        function showTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            
            // Only toggle between monthly views
            document.getElementById('members-view').style.display = 'none';
            document.getElementById('months-view').style.display = 'none';
            
            if (tab === 'members') {
                document.getElementById('members-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'members\')"]').classList.add('active');
            } else {
                document.getElementById('months-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'months\')"]').classList.add('active');
            }
            
            // Store the current active view in sessionStorage
            sessionStorage.setItem('activeView', tab);
            
            // Update URL without reloading the page
            const url = new URL(window.location);
            url.searchParams.set('view', tab);
            window.history.pushState({}, '', url);
            
            // No scrolling - just show the correct view
        }

        // Navigate to specific page
        function goToPage(page, tableType) {
            if (page < 1 || page > <?php echo $totalPages; ?> || page === <?php echo $currentPage; ?>) {
                return; // Don't navigate if already on that page or if invalid
            }
            
            // Store which table view we're currently on
            const activeView = document.getElementById('months-view').style.display === 'block' ? 'months' : 'members';
            sessionStorage.setItem('activeView', activeView);
            
            // Use tableType if provided, otherwise use the current active view
            const viewToUse = tableType || activeView;
            
            const year = document.getElementById('yearSelect').value;
            
            // Use URL fragment to jump to the specific anchor
            window.location.href = `?year=${year}&page=${page}&view=${viewToUse}#${viewToUse}-anchor`;
        }

        // Function to show the correct tab based on URL parameter or stored value
        function showTabAndScrollToTable() {
            // Get the view parameter from URL if available
            const urlParams = new URLSearchParams(window.location.search);
            const viewParam = urlParams.get('view');
            
            // Get stored view if no URL parameter
            const storedView = sessionStorage.getItem('activeView');
            const tabToShow = viewParam || storedView || 'members';
            
            // Show the correct tab without scrolling
            if (tabToShow === 'months') {
                document.getElementById('members-view').style.display = 'none';
                document.getElementById('months-view').style.display = 'block';
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelector('button[onclick="showTab(\'months\')"]').classList.add('active');
            } else {
                document.getElementById('members-view').style.display = 'block';
                document.getElementById('months-view').style.display = 'none';
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelector('button[onclick="showTab(\'members\')"]').classList.add('active');
            }
            
            // No scrolling is performed - we just show the correct view
        }

        // Search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            searchInput.addEventListener('input', performSearch);
            
            // Show the correct tab and scroll to table on page load
            showTabAndScrollToTable();
        });

        function performSearch() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const tableRows = document.querySelectorAll('#members-view tbody tr, #registration-view tbody tr');
            let hasResults = false;

            tableRows.forEach(row => {
                const memberID = row.cells[0].textContent.toLowerCase();
                const name = row.cells[1].textContent.toLowerCase();
                
                if (name.includes(searchTerm) || memberID.includes(searchTerm)) {
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
            const paginationDivs = document.querySelectorAll('.pagination');
            paginationDivs.forEach(function(pagination) {
                pagination.style.display = searchTerm ? 'none' : 'flex';
            });
        }

        function clearSearch() {
            const searchInput = document.getElementById('searchInput');
            searchInput.value = '';
            performSearch();
            searchInput.focus();
            
            // Show pagination again
            const paginationDivs = document.querySelectorAll('.pagination');
            paginationDivs.forEach(function(pagination) {
                pagination.style.display = 'flex';
            });
        }
    </script>
</body>
</html> 