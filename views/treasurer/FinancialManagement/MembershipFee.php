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

// Get members with monthly fee status for all months
function getMemberMonthlyPayments($year) {
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
        AND YEAR(mf.Date) = $year
        GROUP BY m.MemberID, m.Name
        ORDER BY m.Name";
    
    return search($sql);
}

// Get registration fee status
function getRegistrationFeeStatus($year) {
    $sql = "SELECT 
            m.MemberID,
            m.Name,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM MembershipFee mf2 
                    WHERE mf2.Member_MemberID = m.MemberID 
                    AND mf2.Type = 'Registration' 
                    AND mf2.IsPaid = 'Yes'
                ) THEN 'Yes'
                ELSE 'No'
            END as IsPaid,
            (SELECT Date FROM MembershipFee mf3 
             WHERE mf3.Member_MemberID = m.MemberID 
             AND mf3.Type = 'Registration' 
             AND mf3.IsPaid = 'Yes' 
             LIMIT 1) as payment_date,
            (SELECT Amount FROM MembershipFee mf4 
             WHERE mf4.Member_MemberID = m.MemberID 
             AND mf4.Type = 'Registration' 
             AND mf4.IsPaid = 'Yes' 
             LIMIT 1) as Amount
        FROM Member m
        ORDER BY m.Name";
    
    return search($sql);
}

// Get fee amounts from Static table
function getFeeAmounts() {
    $sql = "SELECT monthly_fee, registration_fee FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
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
        WHERE YEAR(mf.Date) = $year AND mf.Type = 'Monthly' AND mf.IsPaid = 'Yes'
        GROUP BY MONTH(mf.Date)
        ORDER BY month";
    
    return search($sql);
}

$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;

$monthlyPayments = getMonthlyPaymentSummary($selectedYear);
$registrationFees = getRegistrationFeeStatus($selectedYear);
$feeAmounts = getFeeAmounts();

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
    <title>Membership Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Membership Fee Details</h1>
            <div class="filters">
                <select class="filter-select" onchange="updateFilters()" id="yearSelect">
                    <?php for($y = $currentTerm; $y >= $currentTerm - 2; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $selectedYear ? 'selected' : ''; ?>>
                            Year <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
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
            <button onclick="window.location.href='editMembershipFee.php'" class="edit-btn">
                <i class="fas fa-edit"></i>
                Edit Details
            </button>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('members')">Member-wise View</button>
            <button class="tab" onclick="showTab('months')">Month-wise View</button>
            <div class="filters">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search by Name or Member ID..." class="search-input">
                    <button onclick="clearSearch()" class="clear-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>

        <div id="members-view" >
            <div class="fee-type-header">
                <h2>Monthly Fee (Rs. <?php echo number_format($feeAmounts['monthly_fee'], 2); ?> per month)</h2>
            </div>
            <div class="table-container">
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
                    $memberMonthlyPayments->data_seek(0);
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
        </div>

        <div id="months-view" style="display: none;">
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

        <div id="registration-view" >
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
                        <th>Amount</th>
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
                        <td>Rs. <?php echo number_format($row['Amount'] ?? $feeAmounts['registration_fee'], 2); ?></td>
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
            
            fetch(`membership_fee.php?year=${year}`)
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
                    
                    // Update registration view
                    document.getElementById('registration-view').innerHTML = doc.getElementById('registration-view').innerHTML;
                });
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
        }

        // Search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            searchInput.addEventListener('input', performSearch);
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