<?php
session_start();
require_once "../../../config/database.php";

// Get current term
function getCurrentTerm() {
    $sql = "SELECT year FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get loan details for all members
function getMemberLoans($year) {
    $sql = "SELECT 
            m.MemberID,
            m.Name,
            GROUP_CONCAT(
                CASE 
                    WHEN l.Status = 'approved' 
                    THEN l.LoanID
                    ELSE NULL 
                END
            ) as loan_ids,
            GROUP_CONCAT(
                CASE 
                    WHEN l.Status = 'approved' 
                    THEN l.Amount
                    ELSE NULL 
                END
            ) as loan_amounts,
            GROUP_CONCAT(
                CASE 
                    WHEN l.Status = 'approved' 
                    THEN l.Issued_Date
                    ELSE NULL 
                END
            ) as issue_dates,
            GROUP_CONCAT(
                CASE 
                    WHEN l.Status = 'approved' 
                    THEN l.Due_Date
                    ELSE NULL 
                END
            ) as due_dates,
            SUM(CASE WHEN l.Status = 'approved' THEN l.Paid_Loan ELSE 0 END) as total_paid,
            SUM(CASE WHEN l.Status = 'approved' THEN l.Remain_Loan ELSE 0 END) as total_remaining,
            SUM(CASE WHEN l.Status = 'approved' THEN l.Paid_Interest ELSE 0 END) as total_interest_paid,
            SUM(CASE WHEN l.Status = 'approved' THEN l.Remain_Interest ELSE 0 END) as total_interest_remaining
        FROM Member m
        LEFT JOIN Loan l ON m.MemberID = l.Member_MemberID 
            AND YEAR(l.Issued_Date) = $year
        GROUP BY m.MemberID, m.Name
        ORDER BY m.Name";
    
    return search($sql);
}

// Get loan summary for the year
function getLoanSummary($year) {
    $sql = "SELECT 
            COUNT(*) as total_loans,
            SUM(Amount) as total_amount,
            SUM(Paid_Loan) as total_paid,
            SUM(Remain_Loan) as total_remaining,
            SUM(Paid_Interest) as total_interest_paid,
            SUM(Remain_Interest) as total_interest_remaining
        FROM Loan
        WHERE YEAR(Issued_Date) = $year";
    
    return search($sql);
}

// Get monthly loan statistics
function getMonthlyLoanStats($year) {
    $sql = "SELECT 
            MONTH(Issued_Date) as month,
            COUNT(*) as loans_issued,
            SUM(Amount) as total_amount,
            SUM(Paid_Loan) as amount_paid,
            SUM(Paid_Interest) as interest_paid
        FROM Loan
        WHERE YEAR(Issued_Date) = $year
        GROUP BY MONTH(Issued_Date)
        ORDER BY month";
    
    return search($sql);
}

// Get loan limits and interest rate from Static table
function getLoanSettings() {
    $sql = "SELECT interest, max_loan_limit FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    return $result->fetch_assoc();
}

$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;

$loanStats = getLoanSummary($selectedYear);
$monthlyStats = getMonthlyLoanStats($selectedYear);
$loanSettings = getLoanSettings();

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 
    4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September',
    10 => 'October', 11 => 'November', 12 => 'December'
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminActorDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Loan Details</h1>
            <select class="filter-select" onchange="updateFilters()" id="yearSelect">
                    <?php for($y = $currentTerm; $y >= $currentTerm - 2; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $selectedYear ? 'selected' : ''; ?>>
                            Year <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
        </div>

        <div id="stats-section" class="stats-cards">
            <?php
            $stats = $loanStats->fetch_assoc();
            ?>
            <div class="stat-card">
                <i class="fas fa-hand-holding-usd"></i>
                <div class="stat-number">Rs. <?php echo number_format($stats['total_amount'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Loans Issued (<?php echo $selectedYear; ?>)</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-money-bill-wave"></i>
                <div class="stat-number">Rs. <?php echo number_format($stats['total_paid'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Amount Repaid</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-percent"></i>
                <div class="stat-number">Rs. <?php echo number_format($stats['total_interest_paid'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Interest Collected</div>
            </div>
            <button onclick="window.location.href='editLoanSettings.php'" class="edit-btn">
                <i class="fas fa-edit"></i>
                Edit Details
            </button>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('members')">Member-wise View</button>
            <button class="tab" onclick="showTab('months')">Month-wise View</button>
            <div class="filters">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search by Name, Member ID, or Loan ID..." class="search-input">
                    <button onclick="clearSearch()" class="clear-btn"><i class="fas fa-times"></i></button>
                </div>
                
            </div>
        </div>

        <div id="members-view">
            <div class="fee-type-header">
                <h2>Loan Details (Interest Rate: <?php echo $loanSettings['interest']; ?>%, Max Limit: Rs. <?php echo number_format($loanSettings['max_loan_limit'], 2); ?>)</h2>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Member ID</th>
                            <th>Name</th>
                            <th>Loan ID</th>
                            <th>Amount</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Paid Amount</th>
                            <th>Remaining</th>
                            <th>Interest Paid</th>
                            <th>Interest Due</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $memberLoans = getMemberLoans($selectedYear);
                        while($row = $memberLoans->fetch_assoc()): 
                            $loanIds = $row['loan_ids'] ? explode(',', $row['loan_ids']) : [];
                            $loanAmounts = $row['loan_amounts'] ? explode(',', $row['loan_amounts']) : [];
                            $issueDates = $row['issue_dates'] ? explode(',', $row['issue_dates']) : [];
                            $dueDates = $row['due_dates'] ? explode(',', $row['due_dates']) : [];
                            
                            // Show an empty row if no loans
                            if (empty($loanIds)):
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                            <td><?php echo htmlspecialchars($row['Name']); ?></td>
                            <td>-</td>
                            <td>Rs. 0.00</td>
                            <td>-</td>
                            <td>-</td>
                            <td>Rs. 0.00</td>
                            <td>Rs. 0.00</td>
                            <td>Rs. 0.00</td>
                            <td>Rs. 0.00</td>
                            <td><span class="status-badge status-none">None</span></td>
                        </tr>
                        <?php else: 
                            // Show a row for each loan
                            foreach($loanIds as $index => $loanId):
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                            <td><?php echo htmlspecialchars($row['Name']); ?></td>
                            <td><?php echo htmlspecialchars($loanId); ?></td>
                            <td>Rs. <?php echo number_format($loanAmounts[$index] ?? 0, 2); ?></td>
                            <td><?php echo isset($issueDates[$index]) ? date('Y-m-d', strtotime($issueDates[$index])) : '-'; ?></td>
                            <td><?php echo isset($dueDates[$index]) ? date('Y-m-d', strtotime($dueDates[$index])) : '-'; ?></td>
                            <td>Rs. <?php echo number_format($row['total_paid'] ?? 0, 2); ?></td>
                            <td>Rs. <?php echo number_format($row['total_remaining'] ?? 0, 2); ?></td>
                            <td>Rs. <?php echo number_format($row['total_interest_paid'] ?? 0, 2); ?></td>
                            <td>Rs. <?php echo number_format($row['total_interest_remaining'] ?? 0, 2); ?></td>
                            <td><span class="status-badge status-approved">Active</span></td>
                        </tr>
                        <?php 
                            endforeach;
                        endif;
                        endwhile; ?>
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
                            <th>Loans Issued</th>
                            <th>Total Amount</th>
                            <th>Amount Repaid</th>
                            <th>Interest Collected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $monthlyStats->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $months[$row['month']]; ?></td>
                            <td><?php echo $row['loans_issued']; ?></td>
                            <td>Rs. <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td>Rs. <?php echo number_format($row['amount_paid'], 2); ?></td>
                            <td>Rs. <?php echo number_format($row['interest_paid'], 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php include '../../templates/footer.php'; ?>
    </div>

    <script>
        function updateFilters() {
            const year = document.getElementById('yearSelect').value;
            
            window.location.href = `?year=${year}`;
            
            fetch(`loan.php?year=${year}`)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Update stats cards
                    document.getElementById('stats-section').innerHTML = doc.getElementById('stats-section').innerHTML;
                    
                    // Update members view
                    document.getElementById('members-view').innerHTML = doc.getElementById('members-view').innerHTML;
                    
                    // Update months view
                    document.getElementById('months-view').innerHTML = doc.getElementById('months-view').innerHTML;
                })
                .catch(error => console.error('Error:', error));
        }

        function showTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById('members-view').style.display = 'none';
            document.getElementById('months-view').style.display = 'none';
            
            if (tab === 'members') {
                document.getElementById('members-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'members\')"]').classList.add('active');
            } else {
                document.getElementById('months-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'months\')"]').classList.add('active');
            }
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
                const loanID = row.cells[2].textContent.toLowerCase();
                
                if (name.includes(searchTerm) || 
                    memberID.includes(searchTerm) || 
                    loanID.includes(searchTerm)) {
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
        }

        function clearSearch() {
            const searchInput = document.getElementById('searchInput');
            searchInput.value = '';
            performSearch();
            searchInput.focus();
        }
    </script>
</body>
</html>