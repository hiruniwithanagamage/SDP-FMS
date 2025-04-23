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

// Get loan details for all members
function getMemberLoans($year) {
    $sql = "SELECT 
            m.MemberID,
            m.Name,
            l.LoanID,
            l.Amount,
            l.Issued_Date,
            l.Due_Date,
            l.Paid_Loan,
            l.Remain_Loan,
            l.Paid_Interest,
            l.Remain_Interest,
            l.Status
        FROM Member m
        LEFT JOIN Loan l ON m.MemberID = l.Member_MemberID 
            AND YEAR(l.Issued_Date) = $year
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
        WHERE YEAR(Issued_Date) = $year
        AND Status = 'approved'";
    
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

// Get all available terms/years
function getAllTerms() {
    $sql = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
    return search($sql);
}

$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$allTerms = getAllTerms();

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
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Loan Details</h1>
            <select class="filter-select" onchange="updateFilters()" id="yearSelect">
                <?php while($term = $allTerms->fetch_assoc()): ?>
                    <option value="<?php echo $term['year']; ?>" <?php echo $term['year'] == $selectedYear ? 'selected' : ''; ?>>
                        Year <?php echo $term['year']; ?>
                    </option>
                <?php endwhile; ?>
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                        $memberLoans = getMemberLoans($selectedYear);
                        $currentMemberID = null;

                        while($row = $memberLoans->fetch_assoc()): 
                            // Track when we switch to a new member
                            $isNewMember = ($currentMemberID !== $row['MemberID']);
                            $currentMemberID = $row['MemberID'];
                            
                            // Show an empty row if no loans (null LoanID)
                            if ($row['LoanID'] === null):
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
                        <?php else: ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                            <td><?php echo htmlspecialchars($row['Name']); ?></td>
                            <td><?php echo htmlspecialchars($row['LoanID']); ?></td>
                            <td>Rs. <?php echo number_format($row['Amount'] ?? 0, 2); ?></td>
                            <td><?php echo isset($row['Issued_Date']) ? date('Y-m-d', strtotime($row['Issued_Date'])) : '-'; ?></td>
                            <td><?php echo isset($row['Due_Date']) ? date('Y-m-d', strtotime($row['Due_Date'])) : '-'; ?></td>
                            <td>Rs. <?php echo number_format($row['Paid_Loan'] ?? 0, 2); ?></td>
                            <td>Rs. <?php echo number_format($row['Remain_Loan'] ?? 0, 2); ?></td>
                            <td>Rs. <?php echo number_format($row['Paid_Interest'] ?? 0, 2); ?></td>
                            <td>Rs. <?php echo number_format($row['Remain_Interest'] ?? 0, 2); ?></td>
                            <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo ucfirst(htmlspecialchars($row['Status'] ?? 'None')); ?></span></td>
                            <td class="actions">
                            <button onclick="viewLoan('<?php echo $row['LoanID']; ?>')" class="action-btn small">
                                <i class="fas fa-eye"></i>
                            </button>
                                <button onclick="editLoan('<?php echo $row['LoanID']; ?>')" class="action-btn small">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="openDeleteModal('<?php echo $row['LoanID']; ?>')" class="action-btn small">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php 
                            endif;
                        endwhile; 
                        ?>
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