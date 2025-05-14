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

// Get fine details for all members
function getMemberFines($year) {
    $sql = "SELECT 
            m.MemberID,
            m.Name,
            f.FineID,
            f.Amount,
            f.Date,
            f.Description,
            f.IsPaid as Status
        FROM Member m
        INNER JOIN Fine f ON m.MemberID = f.Member_MemberID 
            AND YEAR(f.Date) = $year
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

// Get fine settings from Static table
function getFineSettings() {
    $sql = "SELECT late_fine, absent_fine, rules_violation_fine FROM Static ORDER BY year DESC LIMIT 1";
    $result = search($sql);
    return $result->fetch_assoc();
}

// Get all available terms/years
function getAllTerms() {
    $sql = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
    return search($sql);
}

// Handle Delete Fine
if(isset($_POST['delete_fine'])) {
    $fineId = $_POST['fine_id'];
    $currentYear = isset($_GET['year']) ? $_GET['year'] : (isset($_POST['year']) ? $_POST['year'] : getCurrentTerm());
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // First, get fine details
        $getFineQuery = "SELECT * FROM Fine WHERE FineID = ?";
        $stmt = $conn->prepare($getFineQuery);
        $stmt->bind_param("s", $fineId);
        $stmt->execute();
        $fineResult = $stmt->get_result();
        
        if($fineResult->num_rows == 0) {
            throw new Exception("Fine not found");
        }
        
        $fineData = $fineResult->fetch_assoc();
        $isPaid = ($fineData['IsPaid'] === 'Yes');
        $fineAmount = $fineData['Amount'];
        $memberID = $fineData['Member_MemberID'];
        $fineDescription = $fineData['Description'];
        
        // If the fine has been paid, create an expense adjustment record
        if($isPaid) {
            // Get current active treasurer ID
            $treasurerQuery = "SELECT TreasurerID FROM Treasurer WHERE isActive = 1 LIMIT 1";
            $treasurerResult = search($treasurerQuery);
            $treasurerRow = $treasurerResult->fetch_assoc();
            $treasurerID = $treasurerRow['TreasurerID'];
            
            // Generate a new expense ID
            $expenseID = generateExpenseID($term = null);
            
            // Insert a new expense record
            $insertExpenseQuery = "INSERT INTO Expenses (ExpenseID, Category, Method, Amount, Date, Term, 
                                  Description, Treasurer_TreasurerID) 
                                  VALUES (?, 'adjustment', 'system', ?, CURDATE(), ?, 
                                  'Deleted Fine - Deleting paid fine #$fineId for $fineDescription', ?)";
            $stmt = $conn->prepare($insertExpenseQuery);
            $stmt->bind_param("sdis", $expenseID, $fineAmount, $currentYear, $treasurerID);
            $stmt->execute();
            
            $_SESSION['success_message'] = "Fine #$fineId was deleted and recorded as an expense adjustment.";
        } else {
            $_SESSION['success_message'] = "Fine #$fineId was successfully deleted.";
        }
        
        // Check if this fine has any payments
        $checkQuery = "SELECT * FROM Payment p 
                      JOIN FinePayment fp ON p.PaymentID = fp.PaymentID
                      WHERE fp.FineID = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("s", $fineId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // If there are payments linked to this fine, delete them first
        if($result->num_rows > 0) {
            // Get all payment IDs linked to this fine
            $paymentIds = [];
            while($row = $result->fetch_assoc()) {
                $paymentIds[] = $row['PaymentID'];
            }
            
            // Delete the fine payment links
            $deleteFinePaymentsQuery = "DELETE FROM FinePayment WHERE FineID = ?";
            $stmt = $conn->prepare($deleteFinePaymentsQuery);
            $stmt->bind_param("s", $fineId);
            $stmt->execute();
            
            // Delete the associated payment records
            if(!empty($paymentIds)) {
                foreach($paymentIds as $paymentId) {
                    $deletePaymentQuery = "DELETE FROM Payment WHERE PaymentID = ?";
                    $stmt = $conn->prepare($deletePaymentQuery);
                    $stmt->bind_param("s", $paymentId);
                    $stmt->execute();
                }
            }
        }
        
        // Finally, delete the fine record
        $deleteFineQuery = "DELETE FROM Fine WHERE FineID = ?";
        $stmt = $conn->prepare($deleteFineQuery);
        $stmt->bind_param("s", $fineId);
        $stmt->execute();
        
        // Add to change log
        $logQuery = "INSERT INTO ChangeLog (RecordType, RecordID, UserID, TreasurerID, OldValues, NewValues, 
                    ChangeDetails) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $recordType = "Fine";
        $userId = $_SESSION['user_id'] ?? 'Unknown';
        $treasurerId = $treasurerID ?? 'Unknown';
        $oldValues = json_encode($fineData);
        $newValues = "{}";
        $changeDetails = "Deleted fine record #$fineId";
        
        $stmt = $conn->prepare($logQuery);
        $stmt->bind_param("sssssss", $recordType, $fineId, $userId, $treasurerId, $oldValues, $newValues, $changeDetails);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
    } catch(Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting fine: " . $e->getMessage();
    }
    
    // Redirect back to fine page
    header("Location: fine.php?year=" . $currentYear);
    exit();
}

function generateExpenseID($term = null) {
    $conn = getConnection();
    
    // Get current year if term is not provided
    if (empty($term)) {
        $term = date('Y');
    }
    
    // Extract the last 2 digits of the term
    $shortTerm = substr((string)$term, -2);
    
    // Find the highest sequence number for the current term
    $stmt = $conn->prepare("
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

$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$allTerms = getAllTerms();

$fineStats = getFineSummary($selectedYear);
$monthlyStats = getMonthlyFineStats($selectedYear);
$fineSettings = getFineSettings();

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 
    4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September',
    10 => 'October', 11 => 'November', 12 => 'December'
];

$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$isReportApproved = isReportApproved($selectedYear);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fine Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
    <link rel="stylesheet" href="../../../assets/css/alert.css">
    <script src="../../../assets/js/alertHandler.js"></script>
    <style>
        .status-yes {
            background-color: #c2f1cd;
            color: rgb(25, 151, 10);
        }
        .status-no {
            background-color: #e2bcc0;
            color: rgb(234, 59, 59);
        }
        /* Modal Styles */
#fineModal, #editFineModal {
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

#fineModal .modal-content, #editFineModal .modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 20px;
    width: 90%;
    max-width: 900px;
    height: 90%;
    border-radius: 8px;
    position: relative;
}

#fineModal .close, #editFineModal .close {
    position: absolute;
    right: 20px;
    top: 10px;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
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
    </style>
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Fine Details</h1>
            <select class="filter-select" onchange="updateFilters()" id="yearSelect">
                <?php while($term = $allTerms->fetch_assoc()): ?>
                    <option value="<?php echo $term['year']; ?>" <?php echo $term['year'] == $selectedYear ? 'selected' : ''; ?>>
                        Year <?php echo $term['year']; ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <!-- Add this right after the header-card div -->
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
                <div class="stat-label">Total Fine Amount</div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('members')">Member-wise View</button>
            <button class="tab" onclick="showTab('months')">Month-wise View</button>
            <div class="filters">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search by Name, Member ID, or Fine ID..." class="search-input">
                    <button onclick="clearSearch()" class="clear-btn"><i class="fas fa-times"></i></button>
                </div>
                
            </div>
        </div>

        <div id="members-view">
            <div class="fee-type-header">
                <h2>Fine Details (Late Fine: Rs. <?php echo number_format($fineSettings['late_fine'], 2); ?>, Absent Fine: Rs. <?php echo number_format($fineSettings['absent_fine'], 2); ?>, Rule Violation: Rs. <?php echo number_format($fineSettings['rules_violation_fine'], 2); ?>)</h2>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Member ID</th>
                            <th>Name</th>
                            <th>Fine ID</th>
                            <th>Amount</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
$memberFines = getMemberFines($selectedYear);

while($row = $memberFines->fetch_assoc()): 
    // Set the status badge class based on fine status
    $statusClass = '';
    switch($row['Status']) {
        case 'Yes':
            $statusClass = 'status-yes';
            break;
        case 'No':
            $statusClass = 'status-no';
            break;
        default:
            $statusClass = 'status-none';
    }
?>
<tr>
    <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
    <td><?php echo htmlspecialchars($row['Name']); ?></td>
    <td><?php echo htmlspecialchars($row['FineID']); ?></td>
    <td>Rs. <?php echo number_format($row['Amount'] ?? 0, 2); ?></td>
    <td><?php echo htmlspecialchars($row['Description'] ?? 'None'); ?></td>
    <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $row['Status'] == 'Yes' ? 'Paid' : 'Unpaid'; ?></span></td>
    <td class="actions">
    <button onclick="viewFine('<?php echo $row['FineID']; ?>')" class="action-btn small">
        <i class="fas fa-eye"></i>
    </button>
    
    <?php if (!$isReportApproved): ?>
        <button onclick="editFine('<?php echo $row['FineID']; ?>')" class="action-btn small">
            <i class="fas fa-edit"></i>
        </button>
        <button onclick="openDeleteModal('<?php echo $row['FineID']; ?>')" class="action-btn small">
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

    <div id="fineModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeFineModal()">&times;</span>
            <iframe id="fineFrame" class="modal-iframe"></iframe>
        </div>
    </div>

<!-- Delete Modal -->
<div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this fine record? This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" id="delete_fine_id" name="fine_id">
                <div class="delete-modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete_fine" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Fine Modal -->
<div id="editFineModal" class="modal">
    <div class="modal-content" style="max-width: 90%; height: 90%;">
        <span class="close" onclick="closeEditModal()">&times;</span>
        <iframe id="editFineFrame" style="width: 100%; height: 90%; border: none;"></iframe>
    </div>
</div>

    <script>
        function viewFine(fineID) {
            // Set the iframe source to your viewFine.php page
            document.getElementById('fineFrame').src = `viewFine.php?id=${fineID}&popup=true`;
            
            // Show the modal
            document.getElementById('fineModal').style.display = 'block';
        }

        function closeFineModal() {
            document.getElementById('fineModal').style.display = 'none';
        }

        // Edit Fine Modal Functions
        function editFine(fineID) {
            // Set the iframe source to your editFine.php page
            document.getElementById('editFineFrame').src = `editFine.php?id=${fineID}&popup=true`;
            
            // Show the modal
            document.getElementById('editFineModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editFineModal').style.display = 'none';
            
            // After closing, refresh the fine list to see any changes
            updateFilters();
        }

        function updateFilters() {
        const year = document.getElementById('yearSelect').value;
    
        // Don't redirect, just update via fetch
        fetch(`fine.php?year=${year}`)
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
                const description = row.cells[4].textContent.toLowerCase();
                
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

        function openDeleteModal(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_fine_id').value = id;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Update window.onclick to handle both modals
        window.onclick = function(event) {
            const fineModal = document.getElementById('fineModal');
            const deleteModal = document.getElementById('deleteModal');
            const editModal = document.getElementById('editFineModal');
            
            if (event.target == fineModal) {
                closeFineModal();
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
    </script>
</body>
</html>