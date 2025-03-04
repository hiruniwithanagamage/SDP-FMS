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

// Get death welfare details for all members
function getMemberWelfare($year) {
    $sql = "SELECT 
            m.MemberID,
            m.Name,
            GROUP_CONCAT(
                CASE 
                    WHEN dw.Status = 'approved' 
                    THEN dw.WelfareID
                    ELSE NULL 
                END
            ) as welfare_ids,
            GROUP_CONCAT(
                CASE 
                    WHEN dw.Status = 'approved' 
                    THEN dw.Amount
                    ELSE NULL 
                END
            ) as amounts,
            GROUP_CONCAT(
                CASE 
                    WHEN dw.Status = 'approved' 
                    THEN dw.Date
                    ELSE NULL 
                END
            ) as dates,
            GROUP_CONCAT(
                CASE 
                    WHEN dw.Status = 'approved' 
                    THEN dw.Relationship
                    ELSE NULL 
                END
            ) as relationships,
            GROUP_CONCAT(
                CASE 
                    WHEN dw.Status = 'approved' 
                    THEN dw.Status
                    ELSE NULL 
                END
            ) as statuses
        FROM Member m
        LEFT JOIN DeathWelfare dw ON m.MemberID = dw.Member_MemberID 
            AND YEAR(dw.Date) = $year
        GROUP BY m.MemberID, m.Name
        ORDER BY m.Name";
    
    return search($sql);
}

// Get welfare summary for the year
function getWelfareSummary($year) {
    $sql = "SELECT 
            COUNT(*) as total_claims,
            SUM(Amount) as total_amount,
            COUNT(CASE WHEN Status = 'approved' THEN 1 END) as approved_claims,
            COUNT(CASE WHEN Status = 'pending' THEN 1 END) as pending_claims,
            COUNT(CASE WHEN Status = 'rejected' THEN 1 END) as rejected_claims
        FROM DeathWelfare
        WHERE YEAR(Date) = $year";
    
    return search($sql);
}

// Get monthly welfare statistics
function getMonthlyWelfareStats($year) {
    $sql = "SELECT 
            MONTH(Date) as month,
            COUNT(*) as claims_filed,
            SUM(Amount) as total_amount,
            COUNT(CASE WHEN Status = 'approved' THEN 1 END) as approved_claims
        FROM DeathWelfare
        WHERE YEAR(Date) = $year
        GROUP BY MONTH(Date)
        ORDER BY month";
    
    return search($sql);
}

// Get welfare amount from Static table
function getWelfareAmount() {
    $sql = "SELECT death_welfare FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    return $result->fetch_assoc();
}

$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;

$welfareStats = getWelfareSummary($selectedYear);
$monthlyStats = getMonthlyWelfareStats($selectedYear);
$welfareAmount = getWelfareAmount();

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
    <title>Death Welfare Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Death Welfare Details</h1>
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
            $stats = $welfareStats->fetch_assoc();
            ?>
            <div class="stat-card">
                <i class="fas fa-file-medical"></i>
                <div class="stat-number"><?php echo $stats['total_claims'] ?? 0; ?></div>
                <div class="stat-label">Total Claims (<?php echo $selectedYear; ?>)</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <div class="stat-number"><?php echo $stats['approved_claims'] ?? 0; ?></div>
                <div class="stat-label">Approved Claims</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-money-bill-wave"></i>
                <div class="stat-number">Rs. <?php echo number_format($stats['total_amount'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Amount Paid</div>
            </div>
            <button onclick="window.location.href='editWelfareSettings.php'" class="edit-btn">
                <i class="fas fa-edit"></i>
                Edit Settings
            </button>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('members')">Member-wise View</button>
            <button class="tab" onclick="showTab('months')">Month-wise View</button>
            <div class="filters">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search by Name, Member ID, or Welfare ID..." class="search-input">
                    <button onclick="clearSearch()" class="clear-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>

        <div id="members-view">
            <div class="fee-type-header">
                <h2>Death Welfare Claims (Amount: Rs. <?php echo number_format($welfareAmount['death_welfare'], 2); ?>)</h2>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Member ID</th>
                            <th>Name</th>
                            <th>Welfare ID</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Relationship</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $memberWelfare = getMemberWelfare($selectedYear);
                        while($row = $memberWelfare->fetch_assoc()): 
                            $welfareIds = $row['welfare_ids'] ? explode(',', $row['welfare_ids']) : [];
                            $amounts = $row['amounts'] ? explode(',', $row['amounts']) : [];
                            $dates = $row['dates'] ? explode(',', $row['dates']) : [];
                            $relationships = $row['relationships'] ? explode(',', $row['relationships']) : [];
                            $statuses = $row['statuses'] ? explode(',', $row['statuses']) : [];
                            
                            // Show an empty row if no welfare claims
                            if (empty($welfareIds)):
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
                            // Show a row for each welfare claim
                            foreach($welfareIds as $index => $welfareId):
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                            <td><?php echo htmlspecialchars($row['Name']); ?></td>
                            <td><?php echo htmlspecialchars($welfareId); ?></td>
                            <td>Rs. <?php echo number_format($amounts[$index] ?? 0, 2); ?></td>
                            <td><?php echo isset($dates[$index]) ? date('Y-m-d', strtotime($dates[$index])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($relationships[$index] ?? '-'); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($statuses[$index] ?? 'none'); ?>">
                                    <?php echo ucfirst($statuses[$index] ?? 'None'); ?>
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
                            <th>Claims Filed</th>
                            <th>Approved Claims</th>
                            <th>Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $monthlyStats->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $months[$row['month']]; ?></td>
                            <td><?php echo $row['claims_filed']; ?></td>
                            <td><?php echo $row['approved_claims']; ?></td>
                            <td>Rs. <?php echo number_format($row['total_amount'], 2); ?></td>
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
            
            fetch(`deathWelfare.php?year=${year}`)
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
                const welfareID = row.cells[2].textContent.toLowerCase();
                
                if (name.includes(searchTerm) || 
                    memberID.includes(searchTerm) || 
                    welfareID.includes(searchTerm)) {
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