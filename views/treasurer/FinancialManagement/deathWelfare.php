<?php
session_start();
require_once "../../../config/database.php";

// Get current term
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get death welfare details for all members - Updated to filter by term instead of year
function getMemberWelfare($term) {
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
            AND dw.Term = $term
        GROUP BY m.MemberID, m.Name
        ORDER BY m.Name";
    
    return search($sql);
}

// Get all welfare claims for the term - Updated to filter by term instead of year
function getWelfareClaims($term) {
    $sql = "SELECT 
            dw.WelfareID,
            dw.Amount,
            dw.Date,
            dw.Relationship,
            dw.Status,
            dw.Member_MemberID,
            m.Name
        FROM DeathWelfare dw
        INNER JOIN Member m ON dw.Member_MemberID = m.MemberID 
        WHERE dw.Term = $term
        ORDER BY dw.Date DESC";
    
    return search($sql);
}

// Get welfare summary for the term - Updated to filter by term and calculate total_amount properly
function getWelfareSummary($term) {
    $sql = "SELECT 
            COUNT(*) as total_claims,
            COUNT(CASE WHEN Status = 'approved' THEN 1 END) as approved_claims,
            COUNT(CASE WHEN Status = 'pending' THEN 1 END) as pending_claims,
            COUNT(CASE WHEN Status = 'rejected' THEN 1 END) as rejected_claims,
            (SELECT COALESCE(SUM(e.Amount), 0) 
             FROM Expenses e 
             INNER JOIN DeathWelfare dw ON e.ExpenseID = dw.Expense_ExpenseID 
             WHERE dw.Term = $term AND dw.Status = 'approved'
            ) as total_amount
        FROM DeathWelfare
        WHERE Term = $term";
    
    return search($sql);
}

// Get monthly welfare statistics - Updated to filter by term instead of year
function getMonthlyWelfareStats($term) {
    $sql = "SELECT 
            MONTH(Date) as month,
            COUNT(*) as claims_filed,
            COUNT(CASE WHEN Status = 'approved' THEN 1 END) as approved_claims,
            (SELECT COALESCE(SUM(e.Amount), 0) 
             FROM Expenses e 
             INNER JOIN DeathWelfare dw ON e.ExpenseID = dw.Expense_ExpenseID 
             WHERE MONTH(dw.Date) = month AND dw.Term = $term AND dw.Status = 'approved'
            ) as total_amount
        FROM DeathWelfare
        WHERE Term = $term
        GROUP BY MONTH(Date)
        ORDER BY month";
    
    return search($sql);
}

// Get welfare amount from Static table
function getWelfareAmount() {
    $sql = "SELECT death_welfare FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    return $result->fetch_assoc();
}

// Get all available terms/years
function getAllTerms() {
    $sql = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
    return search($sql);
}

// Handle Delete Welfare Claim
if(isset($_POST['delete_welfare'])) {
    $welfareId = $_POST['welfare_id'];
    $currentTerm = isset($_GET['year']) ? $_GET['year'] : (isset($_POST['year']) ? $_POST['year'] : getCurrentTerm());
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // Get welfare claim details
        $getWelfareQuery = "SELECT * FROM DeathWelfare WHERE WelfareID = ?";
        $stmt = $conn->prepare($getWelfareQuery);
        $stmt->bind_param("s", $welfareId);
        $stmt->execute();
        $welfareResult = $stmt->get_result();
        
        if($welfareResult->num_rows > 0) {
            $welfareData = $welfareResult->fetch_assoc();
            $status = $welfareData['Status'];
            $memberId = $welfareData['Member_MemberID'];
            $amount = $welfareData['Amount'];
            $expenseId = $welfareData['Expense_ExpenseID'];
            
            // Get the current treasurer ID (assuming it's stored in session)
            $treasurerId = $_SESSION['treasurer_id']; // Adjust based on your session structure
            
            // Log the deletion in ChangeLog
            $recordType = "DeathWelfare";
            $oldValues = json_encode($welfareData);
            $newValues = "{}"; // Empty as record is deleted
            $changeDetails = "Death welfare claim #{$welfareId} deleted. Status was: {$status}";
            
            $logQuery = "INSERT INTO ChangeLog (RecordType, RecordID, MemberID, TreasurerID, OldValues, NewValues, ChangeDetails, Status) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'Not Read')";
            $stmt = $conn->prepare($logQuery);
            $stmt->bind_param("sssssss", $recordType, $welfareId, $memberId, $treasurerId, $oldValues, $newValues, $changeDetails);
            $stmt->execute();
            
            // Handle based on status
            if($status == 'pending' || $status == 'rejected') {
                // For pending or rejected claims, simply delete the record
                $deleteWelfareQuery = "DELETE FROM DeathWelfare WHERE WelfareID = ?";
                $stmt = $conn->prepare($deleteWelfareQuery);
                $stmt->bind_param("s", $welfareId);
                $stmt->execute();
                
                $_SESSION['success_message'] = "Welfare claim #$welfareId was successfully deleted.";
            } 
            else if($status == 'approved') {
                // For approved claims:
                // 1. Delete the expense record if exists
                if($expenseId) {
                    $deleteExpenseQuery = "DELETE FROM Expenses WHERE ExpenseID = ?";
                    $stmt = $conn->prepare($deleteExpenseQuery);
                    $stmt->bind_param("s", $expenseId);
                    $stmt->execute();
                }
                
                // 2. Add a new payment entry of type cash with status 'cash'
                $paymentId = generatePaymentID($currentTerm);
                $paymentType = "Refund";
                $method = "Cash";
                $date = date('Y-m-d');
                $notes = "Refund for deleted welfare claim #$welfareId";
                
                $addPaymentQuery = "INSERT INTO Payment (PaymentID, Payment_Type, Method, Amount, Date, Term, Notes, Member_MemberID, status) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'edited')";
                $stmt = $conn->prepare($addPaymentQuery);
                $stmt->bind_param("sssdsiss", $paymentId, $paymentType, $method, $amount, $date, $currentTerm, $notes, $memberId);
                $stmt->execute();
                
                // 3. Delete the welfare claim
                $deleteWelfareQuery = "DELETE FROM DeathWelfare WHERE WelfareID = ?";
                $stmt = $conn->prepare($deleteWelfareQuery);
                $stmt->bind_param("s", $welfareId);
                $stmt->execute();
                
                $_SESSION['success_message'] = "Approved welfare claim #$welfareId was successfully deleted and a payment record was created.";
            }
        } else {
            throw new Exception("Welfare claim not found.");
        }
        
        // Commit transaction
        $conn->commit();
        
    } catch(Exception $e) {
        // Rollback on error
        if(isset($conn)) {
            $conn->rollback();
        }
        $_SESSION['error_message'] = "Error deleting welfare claim: " . $e->getMessage();
    }
    
    // Redirect back to welfare page
    header("Location: deathWelfare.php?year=" . $currentTerm);
    exit();
}

function generatePaymentID($term = null) {
    $conn = getConnection();
    
    // Get current year if term is not provided
    if (empty($term)) {
        $term = date('Y');
    }
    
    // Extract the last 2 digits of the term
    $shortTerm = substr((string)$term, -2);
    
    // Find the highest sequence number for the current term
    $stmt = $conn->prepare("
        SELECT MAX(CAST(SUBSTRING(PaymentID, 6) AS UNSIGNED)) as max_seq 
        FROM Payment 
        WHERE PaymentID LIKE 'PAY{$shortTerm}%'
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $nextSeq = 1; // Default starting value
    if ($row && $row['max_seq']) {
        $nextSeq = $row['max_seq'] + 1;
    }
    
    // Format: PAY followed by last 2 digits of term and sequence number
    // Use leading zeros for numbers 1-9, no leading zeros after 10
    if ($nextSeq < 10) {
        return 'PAY' . $shortTerm . '0' . $nextSeq;
    } else {
        return 'PAY' . $shortTerm . $nextSeq;
    }
}

// Function to check if financial report for a specific term is approved
function isReportApproved($term) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT Status 
        FROM FinancialReportVersions 
        WHERE Term = ? 
        ORDER BY Date DESC 
        LIMIT 1
    ");
    
    $stmt->bind_param("i", $term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return ($row['Status'] === 'approved');
    }
    
    return false; // If no report exists, it's not approved
}

$currentTerm = getCurrentTerm();
$selectedTerm = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$allTerms = getAllTerms();

$welfareStats = getWelfareSummary($selectedTerm);
$monthlyStats = getMonthlyWelfareStats($selectedTerm);
$welfareAmount = getWelfareAmount();

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 
    4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September',
    10 => 'October', 11 => 'November', 12 => 'December'
];

$isReportApproved = isReportApproved($selectedTerm);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Death Welfare Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
    <link rel="stylesheet" href="../../../assets/css/alert.css">
    <script src="../../../assets/js/alertHandler.js"></script>
    <style>
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .status-approved {
            background-color: #c2f1cd;
            color: rgb(25, 151, 10);
        }
        .status-pending {
            background-color: #fff8e8;
            color: #f6a609;
        }
        .status-rejected {
            background-color: #e2bcc0;
            color: rgb(234, 59, 59);
        }
        .status-none {
            background-color: #f0f0f0;
            color: #666;
        }
        /* Modal Styles */
        #welfareModal, #editWelfareModal {
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

        #welfareModal .modal-content, #editWelfareModal .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            width: 90%;
            max-width: 900px;
            height: 90%;
            border-radius: 8px;
            position: relative;
        }

        #welfareModal .close, #editWelfareModal .close {
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
        
        /* Delete Modal Styles */
        .delete-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }

        .delete-modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 400px;
            border-radius: 8px;
            text-align: center;
        }

        .delete-modal-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
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
            padding: 10px 20px;
            background-color: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Death Welfare Details</h1>
            <select class="filter-select" onchange="updateFilters()" id="yearSelect">
                <?php while($term = $allTerms->fetch_assoc()): ?>
                    <option value="<?php echo $term['year']; ?>" <?php echo $term['year'] == $selectedTerm ? 'selected' : ''; ?>>
                        Year <?php echo $term['year']; ?>
                    </option>
                <?php endwhile; ?>
            </select>
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

        <div id="stats-section" class="stats-cards">
            <?php
            $stats = $welfareStats->fetch_assoc();
            ?>
            <div class="stat-card">
                <i class="fas fa-file-medical"></i>
                <div class="stat-number"><?php echo $stats['total_claims'] ?? 0; ?></div>
                <div class="stat-label">Total Claims (<?php echo $selectedTerm; ?>)</div>
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    $welfareClaims = getWelfareClaims($selectedTerm);
                    
                    while($row = $welfareClaims->fetch_assoc()): 
                        // Set the status badge class based on welfare status
                        $statusClass = '';
                        switch($row['Status']) {
                            case 'approved':
                                $statusClass = 'status-approved';
                                break;
                            case 'pending':
                                $statusClass = 'status-pending';
                                break;
                            case 'rejected':
                                $statusClass = 'status-rejected';
                                break;
                            default:
                                $statusClass = 'status-none';
                        }
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['Member_MemberID']); ?></td>
                        <td><?php echo htmlspecialchars($row['Name']); ?></td>
                        <td><?php echo htmlspecialchars($row['WelfareID']); ?></td>
                        <td>Rs. <?php echo number_format($row['Amount'] ?? 0, 2); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($row['Date'])); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($row['Relationship'])); ?></td>
                        <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo ucfirst(htmlspecialchars($row['Status'] ?? 'None')); ?></span></td>
                        <td class="actions">
                            <button onclick="viewWelfare('<?php echo $row['WelfareID']; ?>')" class="action-btn small">
                                <i class="fas fa-eye"></i>
                            </button>
                            
                            <?php if (!$isReportApproved): ?>
                                <button onclick="editWelfare('<?php echo $row['WelfareID']; ?>')" class="action-btn small">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="openDeleteModal('<?php echo $row['WelfareID']; ?>')" class="action-btn small">
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

    <!-- View Welfare Modal -->
    <div id="welfareModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeWelfareModal()">&times;</span>
            <iframe id="welfareFrame" class="modal-iframe"></iframe>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this welfare claim record? This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" id="delete_welfare_id" name="welfare_id">
                <div class="delete-modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete_welfare" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Welfare Modal -->
    <div id="editWelfareModal" class="modal">
        <div class="modal-content" style="max-width: 90%; height: 90%;">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <iframe id="editWelfareFrame" style="width: 100%; height: 90%; border: none;"></iframe>
        </div>
    </div>

    <script>
        function viewWelfare(welfareID) {
            // Set the iframe source to your viewWelfare.php page (if one exists)
            document.getElementById('welfareFrame').src = `viewWelfare.php?id=${welfareID}&popup=true`;
            
            // Show the modal
            document.getElementById('welfareModal').style.display = 'block';
        }

        function closeWelfareModal() {
            document.getElementById('welfareModal').style.display = 'none';
        }

        // Edit Welfare Modal Functions
        function editWelfare(welfareID) {
            // Set the iframe source to your editWelfare.php page
            document.getElementById('editWelfareFrame').src = `editWelfare.php?id=${welfareID}&popup=true`;
            
            // Show the modal
            document.getElementById('editWelfareModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editWelfareModal').style.display = 'none';
            
            // After closing, refresh the welfare list to see any changes
            updateFilters();
        }

        function updateFilters() {
            const year = document.getElementById('yearSelect').value;
        
            // Redirect to the page with the selected term
            window.location.href = `deathWelfare.php?year=${year}`;
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

        function openDeleteModal(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_welfare_id').value = id;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Update window.onclick to handle all modals
        window.onclick = function(event) {
            const welfareModal = document.getElementById('welfareModal');
            const deleteModal = document.getElementById('deleteModal');
            const editModal = document.getElementById('editWelfareModal');
            
            if (event.target == welfareModal) {
                closeWelfareModal();
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
            
            // Add close button to alert
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
    </script>
</body>
</html>