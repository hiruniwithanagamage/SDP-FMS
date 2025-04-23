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
        INNER JOIN Loan l ON m.MemberID = l.Member_MemberID 
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

// Handle Delete Loan
if(isset($_POST['delete_payment'])) {
    $loanId = $_POST['loan_id'];
    $currentYear = isset($_GET['year']) ? $_GET['year'] : (isset($_POST['year']) ? $_POST['year'] : getCurrentTerm());
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // First check if this loan has any payments
        $checkQuery = "SELECT * FROM LoanPayment WHERE LoanID = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("s", $loanId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // If there are payments linked to this loan, delete them first
        if($result->num_rows > 0) {
            // Get all payment IDs linked to this loan
            $paymentIds = [];
            while($row = $result->fetch_assoc()) {
                $paymentIds[] = $row['PaymentID'];
            }
            
            // Delete the loan payment links
            $deleteLoanPaymentsQuery = "DELETE FROM LoanPayment WHERE LoanID = ?";
            $stmt = $conn->prepare($deleteLoanPaymentsQuery);
            $stmt->bind_param("s", $loanId);
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
        
        // Check if this loan has any guarantors
        $checkGuarantorsQuery = "SELECT * FROM Guarantor WHERE Loan_LoanID = ?";
        $stmt = $conn->prepare($checkGuarantorsQuery);
        $stmt->bind_param("s", $loanId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // If there are guarantors for this loan, delete them
        if($result->num_rows > 0) {
            $deleteGuarantorsQuery = "DELETE FROM Guarantor WHERE Loan_LoanID = ?";
            $stmt = $conn->prepare($deleteGuarantorsQuery);
            $stmt->bind_param("s", $loanId);
            $stmt->execute();
        }
        
        // Finally, delete the loan record
        $deleteLoanQuery = "DELETE FROM Loan WHERE LoanID = ?";
        $stmt = $conn->prepare($deleteLoanQuery);
        $stmt->bind_param("s", $loanId);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Loan #$loanId was successfully deleted.";
    } catch(Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting loan: " . $e->getMessage();
    }
    
    // Redirect back to loan page
    header("Location: loan.php?year=" . $currentYear);
    exit();
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
#loanModal, #editLoanModal {
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

#loanModal .modal-content, #editLoanModal .modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 20px;
    width: 90%;
    max-width: 900px;
    height: 90%;
    border-radius: 8px;
    position: relative;
}

#loanModal .close,#editLoanModal .close {
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
    </style>
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
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
$memberLoans = getMemberLoans($selectedYear);

while($row = $memberLoans->fetch_assoc()): 
    // Set the status badge class based on loan status
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
    <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
    <td><?php echo htmlspecialchars($row['Name']); ?></td>
    <td><?php echo htmlspecialchars($row['LoanID']); ?></td>
    <td>Rs. <?php echo number_format($row['Amount'] ?? 0, 2); ?></td>
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

    <div id="loanModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeLoanModal()">&times;</span>
            <iframe id="loanFrame" class="modal-iframe"></iframe>
        </div>
    </div>

<!-- Delete Modal -->
<div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this loan record? This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" id="delete_loan_id" name="loan_id">
                <div class="delete-modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete_payment" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Loan Modal -->
<div id="editLoanModal" class="modal">
    <div class="modal-content" style="max-width: 90%; height: 90%;">
        <span class="close" onclick="closeEditModal()">&times;</span>
        <iframe id="editLoanFrame" style="width: 100%; height: 90%; border: none;"></iframe>
    </div>
</div>

    <script>
        function viewLoan(LoanID) {
            // Set the iframe source to your viewLoan.php page
            document.getElementById('loanFrame').src = `viewLoan.php?id=${LoanID}&popup=true`;
            
            // Show the modal
            document.getElementById('loanModal').style.display = 'block';
        }

        function closeLoanModal() {
            document.getElementById('loanModal').style.display = 'none';
        }

        // Edit Loan Modal Functions
        function editLoan(loanID) {
            // Set the iframe source to your editLoan.php page
            document.getElementById('editLoanFrame').src = `editLoan.php?id=${loanID}&popup=true`;
            
            // Show the modal
            document.getElementById('editLoanModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editLoanModal').style.display = 'none';
            
            // After closing, refresh the loan list to see any changes
            updateFilters();
        }

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

        function openDeleteModal(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_loan_id').value = id;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Update window.onclick to handle both modals
        window.onclick = function(event) {
            const loanModal = document.getElementById('loanModal');
            const deleteModal = document.getElementById('deleteModal');
            const editModal = document.getElementById('editLoanModal');
            
            if (event.target == loanModal) {
                closeLoanModal();
            }
            
            if (event.target == deleteModal) {
                closeDeleteModal();
            }

            if (event.target == editModal) {
                closeEditModal();
            }
        };
    </script>
</body>
</html>