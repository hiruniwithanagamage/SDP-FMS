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

// Get fine details for all members with optional type filter
function getMemberFines($year, $type = null) {
    $sql = "SELECT 
            m.MemberID,
            m.Name,
            GROUP_CONCAT(f.FineID) as fine_ids,
            GROUP_CONCAT(f.Amount) as amounts,
            GROUP_CONCAT(f.Date) as dates,
            GROUP_CONCAT(f.Description) as descriptions,
            GROUP_CONCAT(f.IsPaid) as payment_statuses
        FROM Member m
        LEFT JOIN Fine f ON m.MemberID = f.Member_MemberID 
            AND YEAR(f.Date) = $year";
    
    if ($type) {
        $sql .= " AND f.Description LIKE '%$type%'";
    }
    
    $sql .= " GROUP BY m.MemberID, m.Name
        ORDER BY m.Name";
    
    return search($sql);
}

// Get fine summary for the year
function getFineSummary($year) {
    $sql = "SELECT 
            COUNT(*) as total_fines,
            SUM(Amount) as total_amount,
            COUNT(CASE WHEN IsPaid = 'Yes' THEN 1 END) as paid_fines,
            COUNT(CASE WHEN IsPaid = 'No' THEN 1 END) as unpaid_fines,
            SUM(CASE WHEN Description LIKE '%late%' THEN Amount ELSE 0 END) as late_amount,
            SUM(CASE WHEN Description LIKE '%absent%' THEN Amount ELSE 0 END) as absent_amount,
            SUM(CASE WHEN Description LIKE '%violation%' THEN Amount ELSE 0 END) as violation_amount
        FROM Fine
        WHERE YEAR(Date) = $year";
    
    return search($sql);
}

// Get monthly fine statistics
function getMonthlyFineStats($year) {
    $sql = "SELECT 
            MONTH(Date) as month,
            COUNT(*) as total_fines,
            SUM(Amount) as total_amount,
            COUNT(CASE WHEN IsPaid = 'Yes' THEN 1 END) as paid_fines,
            SUM(CASE WHEN IsPaid = 'Yes' THEN Amount ELSE 0 END) as collected_amount
        FROM Fine
        WHERE YEAR(Date) = $year
        GROUP BY MONTH(Date)
        ORDER BY month";
    
    return search($sql);
}

// Get fine amounts from Static table
function getFineAmounts() {
    $sql = "SELECT late_fine, absent_fine, rules_violation_fine FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    return $result->fetch_assoc();
}

$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$selectedType = isset($_GET['type']) ? $_GET['type'] : null;

$fineStats = getFineSummary($selectedYear);
$monthlyStats = getMonthlyFineStats($selectedYear);
$fineAmounts = getFineAmounts();

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
    <title>Fine Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminActorDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
    <style>
        .fine-type-filters {
            display: flex;
            gap: 10px;
            margin-right: 20px;
        }

        .filter-btn {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .filter-btn:hover {
            background: #f0f0f0;
        }

        .filter-btn.active {
            background: #4a90e2;
            color: white;
            border-color: #4a90e2;
        }

        .fine-amounts {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            font-size: 0.9em;
            color: #666;
        }

        .fine-amounts {
            display: flex;
            gap: 30px;
            align-items: center;
            color: white;
        }
    </style>
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Fine Details</h1>
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
            $stats = $fineStats->fetch_assoc();
            ?>
            <div class="stat-card">
                <i class="fas fa-exclamation-circle"></i>
                <div class="stat-number"><?php echo $stats['total_fines'] ?? 0; ?></div>
                <div class="stat-label">Total Fines (<?php echo $selectedYear; ?>)</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <div class="stat-number"><?php echo $stats['paid_fines'] ?? 0; ?></div>
                <div class="stat-label">Paid Fines</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-money-bill-wave"></i>
                <div class="stat-number">Rs. <?php echo number_format($stats['total_amount'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Amount</div>
            </div>
            <button onclick="window.location.href='editFineSettings.php'" class="edit-btn">
                <i class="fas fa-edit"></i>
                Edit Settings
            </button>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('members')">Member-wise View</button>
            <button class="tab" onclick="showTab('months')">Month-wise View</button>
            <div class="filters">
                <div class="fine-type-filters">
                    <button onclick="filterByType('all')" class="filter-btn <?php echo !$selectedType ? 'active' : ''; ?>">All</button>
                    <button onclick="filterByType('late')" class="filter-btn <?php echo $selectedType === 'late' ? 'active' : ''; ?>">Late</button>
                    <button onclick="filterByType('absent')" class="filter-btn <?php echo $selectedType === 'absent' ? 'active' : ''; ?>">Absent</button>
                    <button onclick="filterByType('violation')" class="filter-btn <?php echo $selectedType === 'violation' ? 'active' : ''; ?>">Rule Violation</button>
                </div>
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search by Name, Member ID, or Fine ID..." class="search-input">
                    <button onclick="clearSearch()" class="clear-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>

        <div id="members-view">
            <div class="fee-type-header">
                <h2>Fine Details</h2>
                <div class="fine-amounts">
                    <span>Late Fine: Rs. <?php echo number_format($fineAmounts['late_fine'], 2); ?></span>
                    <span>Absent Fine: Rs. <?php echo number_format($fineAmounts['absent_fine'], 2); ?></span>
                    <span>Rule Violation: Rs. <?php echo number_format($fineAmounts['rules_violation_fine'], 2); ?></span>
                </div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Member ID</th>
                            <th>Name</th>
                            <th>Fine ID</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $memberFines = getMemberFines($selectedYear, $selectedType);
                        while($row = $memberFines->fetch_assoc()): 
                            $fineIds = $row['fine_ids'] ? explode(',', $row['fine_ids']) : [];
                            $amounts = $row['amounts'] ? explode(',', $row['amounts']) : [];
                            $dates = $row['dates'] ? explode(',', $row['dates']) : [];
                            $descriptions = $row['descriptions'] ? explode(',', $row['descriptions']) : [];
                            $paymentStatuses = $row['payment_statuses'] ? explode(',', $row['payment_statuses']) : [];
                            
                            if (empty($fineIds)):
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                            <td><?php echo htmlspecialchars($row['Name']); ?></td>
                            <td>-</td>
                            <td>Rs. 0.00</td>
                            <td>-</td>
                            <td>-</td>
                            <td><span class="status-badge status-none">None</span></td>
                        </tr>
                        <?php else:
                            foreach($fineIds as $index => $fineId):
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                            <td><?php echo htmlspecialchars($row['Name']); ?></td>
                            <td><?php echo htmlspecialchars($fineId); ?></td>
                            <td>Rs. <?php echo number_format($amounts[$index] ?? 0, 2); ?></td>
                            <td><?php echo isset($dates[$index]) ? date('Y-m-d', strtotime($dates[$index])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($descriptions[$index] ?? '-'); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($paymentStatuses[$index] ?? 'none'); ?>">
                                    <?php echo $paymentStatuses[$index] == 'Yes' ? 'Paid' : 'Unpaid'; ?>
                                </span>
                            </td>
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
                            <th>Total Fines</th>
                            <th>Paid Fines</th>
                            <th>Total Amount</th>
                            <th>Collected Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $monthlyStats->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $months[$row['month']]; ?></td>
                            <td><?php echo $row['total_fines']; ?></td>
                            <td><?php echo $row['paid_fines']; ?></td>
                            <td>Rs. <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td>Rs. <?php echo number_format($row['collected_amount'], 2); ?></td>
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
        // Filter by fine type
        function filterByType(type) {
            const year = document.getElementById('yearSelect').value;
            let url = `?year=${year}`;
            if (type !== 'all') {
                url += `&type=${type}`;
            }
            window.location.href = url;
        }

        function updateFilters() {
            const year = document.getElementById('yearSelect').value;
            const type = new URLSearchParams(window.location.search).get('type');
            let url = `?year=${year}`;
            if (type) {
                url += `&type=${type}`;
            }
            
            window.location.href = url;
            
            fetch(`fine.php${url}`)
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
                const fineID = row.cells[2].textContent.toLowerCase();
                const description = row.cells[5].textContent.toLowerCase();
                
                if (name.includes(searchTerm) || 
                    memberID.includes(searchTerm) || 
                    fineID.includes(searchTerm) ||
                    description.includes(searchTerm)) {
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