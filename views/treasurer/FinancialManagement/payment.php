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

// Get payments for all members
function getMemberPayments($year, $month = null, $memberID = null) {
    $whereConditions = ["YEAR(p.Date) = $year"];
    
    if ($month !== null && $month > 0) {
        $whereConditions[] = "MONTH(p.Date) = $month";
    }
    
    if ($memberID !== null && $memberID !== '') {
        $whereConditions[] = "m.MemberID = '$memberID'";
    }
    
    $whereClause = implode(" AND ", $whereConditions);
    
    $sql = "SELECT 
            p.PaymentID,
            m.MemberID,
            m.Name,
            p.Payment_Type,
            p.Method,
            p.Amount,
            p.Date,
            p.Term,
            p.Notes
        FROM Payment p
        JOIN Member m ON p.Member_MemberID = m.MemberID
        WHERE $whereClause
        ORDER BY p.Date DESC, m.Name";
    
    return search($sql);
}

// Get payment summary for the period
function getPaymentSummary($year, $month = null, $memberID = null) {
    $whereConditions = ["YEAR(Date) = $year"];
    
    if ($month !== null && $month > 0) {
        $whereConditions[] = "MONTH(Date) = $month";
    }
    
    if ($memberID !== null && $memberID !== '') {
        $whereConditions[] = "Member_MemberID = '$memberID'";
    }
    
    $whereClause = implode(" AND ", $whereConditions);
    
    $sql = "SELECT 
            COUNT(*) as total_payments,
            SUM(Amount) as total_amount,
            COUNT(DISTINCT Member_MemberID) as unique_members,
            -- COUNT(CASE WHEN Payment_Type = 'Loan' THEN 1 END) as loan_payments,
            -- COUNT(CASE WHEN Payment_Type = 'Membership Fee' THEN 1 END) as membership_fee_payments,
            COUNT(CASE WHEN Payment_Type = 'Fine' THEN 1 END) as fine_payments,
            SUM(CASE WHEN Payment_Type = 'Loan' THEN Amount ELSE 0 END) as loan_amount,
            SUM(CASE WHEN Payment_Type = 'registration' THEN Amount ELSE 0 END) as registration_fee_amount,
            SUM(CASE WHEN Payment_Type = 'monthly' THEN Amount ELSE 0 END) as monthly_fee_amount,
            SUM(CASE WHEN Payment_Type = 'Fine' THEN Amount ELSE 0 END) as fine_amount
        FROM Payment
        WHERE $whereClause";
    
    return search($sql);
}

// Get monthly payment statistics
function getMonthlyPaymentStats($year) {
    $sql = "SELECT 
            MONTH(Date) as month,
            COUNT(*) as payment_count,
            SUM(Amount) as total_amount,
            COUNT(DISTINCT Member_MemberID) as unique_members
        FROM Payment
        WHERE YEAR(Date) = $year
        GROUP BY MONTH(Date)
        ORDER BY month";
    
    return search($sql);
}

// Get all members for dropdown
function getAllMembers() {
    $sql = "SELECT MemberID, Name FROM Member ORDER BY Name";
    return search($sql);
}

// Handle current filter selections
$currentTerm = getCurrentTerm();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : 0; // 0 means all months
$selectedMemberID = isset($_GET['member']) ? $_GET['member'] : '';

// Get all available terms/years
function getAllTerms() {
    $sql = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
    return search($sql);
}

// Get data based on filters
$paymentSummary = getPaymentSummary($selectedYear, $selectedMonth, $selectedMemberID);
$monthlyStats = getMonthlyPaymentStats($selectedYear);
$allMembers = getAllMembers();
$allTerms = getAllTerms();

$months = [
    0 => 'All Months',
    1 => 'January', 2 => 'February', 3 => 'March', 
    4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September',
    10 => 'October', 11 => 'November', 12 => 'December'
];

$paymentTypes = [
    'All Types' => '',
    'Loan' => 'Loan',
    'Membership Fee' => 'Membership Fee',
    'Fine' => 'Fine'
];

$methodTypes = [
    'All Methods' => '',
    'Cash' => 'Cash',
    'Bank Transfer' => 'Bank Transfer',
    'Card' => 'Card',
    'Check' => 'Check'
];

// Handle Delete Payment
if(isset($_POST['delete_payment'])) {
    $paymentId = $_POST['payment_id'];
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // First check if this payment is linked to any membership fees
        $checkQuery = "SELECT * FROM MembershipFeePayment WHERE PaymentID = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("s", $paymentId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // If there are links, remove them first
        if($result->num_rows > 0) {
            $deleteLinksQuery = "DELETE FROM MembershipFeePayment WHERE PaymentID = ?";
            $stmt = $conn->prepare($deleteLinksQuery);
            $stmt->bind_param("s", $paymentId);
            $stmt->execute();
            
            // Also need to update related membership fees
            $updateFeesQuery = "UPDATE MembershipFee SET IsPaid = 'No' 
                              WHERE FeeID IN (
                                  SELECT FeeID FROM MembershipFeePayment 
                                  WHERE PaymentID = ?
                              )";
            $stmt = $conn->prepare($deleteLinksQuery);
            $stmt->bind_param("s", $paymentId);
            $stmt->execute();
        }
        
        // Delete the payment
        $deleteQuery = "DELETE FROM Payment WHERE PaymentID = ?";
        $stmt = $conn->prepare($deleteQuery);
        $stmt->bind_param("s", $paymentId);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Payment #$paymentId was successfully deleted.";
    } catch(Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting payment: " . $e->getMessage();
    }
    
    // Redirect back to payment page
    header("Location: payment.php?year=" . $selectedYear);
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
    <link rel="stylesheet" href="../../../assets/css/alert.css">
    <script src="../../../assets/js/alertHandler.js"></script>
    <style>
    /* Delete Modal Styles */
    .delete-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        padding: 20px;
        overflow-y: auto;
    }

    .delete-modal-content {
        background-color: white;
        margin: 10% auto;
        padding: 2rem;
        width: 90%;
        max-width: 500px;
        border-radius: 12px;
        position: relative;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        text-align: center;
    }

    .delete-modal-content h2 {
        color: #e53935;
        margin-bottom: 1rem;
    }

    .delete-modal-buttons {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 2rem;
    }

    .confirm-delete-btn {
        padding: 0.8rem 1.8rem;
        border: none;
        border-radius: 6px;
        font-size: 1rem;
        cursor: pointer;
        background-color: #e53935;
        color: white;
        transition: background-color 0.3s;
    }

    .confirm-delete-btn:hover {
        background-color: #c62828;
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
    .month-filter-container {
        display: flex;
        gap: 15px;
        align-items: center;
    }

    .month-filter-select {
        padding: 8px 15px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background-color: white;
        color: #333;
        font-size: 14px;
        cursor: pointer;
    }

    .month-filter-select:focus {
        outline: none;
        border-color: #1e3c72;
        box-shadow: 0 0 0 2px rgba(30, 60, 114, 0.2);
    }

    /* Edit Payment Modal */
#editPaymentModal {
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

#editPaymentModal .modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 20px;
    width: 90%;
    max-width: 900px;
    height: 80%;
    border-radius: 8px;
    position: relative;
}

#editPaymentModal .close {
    position: absolute;
    right: 20px;
    top: 10px;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}
</style>
</head>
<body>
    <div class="main-container">
    <?php include '../../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <div class="header-card">
            <h1>Payment Management</h1>
            <div class="filter-container">
                <select class="filter-select" id="yearSelect" onchange="updateFilters()">
                    <?php while($term = $allTerms->fetch_assoc()): ?>
                        <option value="<?php echo $term['year']; ?>" <?php echo $term['year'] == $selectedYear ? 'selected' : ''; ?>>
                            Year <?php echo $term['year']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>

        <!-- Generate alert -->
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
            $stats = $paymentSummary->fetch_assoc();
            ?>
            <div class="stat-card">
                <i class="fas fa-money-bill-wave"></i>
                <div class="stat-number"><?php echo $stats['total_payments'] ?? 0; ?></div>
                <div class="stat-label">Total Payments</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-hand-holding-usd"></i>
                <div class="stat-number">Rs. <?php echo number_format($stats['total_amount'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Amount</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-chart-pie"></i>
                <div style="color:#1e3c72; font-weight:bold;" class="stat-label">Breakdown</div>
                <div class="stat-number-small">
                    Loan: Rs. <?php echo number_format($stats['loan_amount'] ?? 0, 2); ?><br>
                    Registration Fees: Rs. <?php echo number_format($stats['registration_fee_amount'] ?? 0, 2); ?><br>
                    Monthly Fees: Rs. <?php echo number_format($stats['monthly_fee_amount'] ?? 0, 2); ?><br>
                    Fines: Rs. <?php echo number_format($stats['fine_amount'] ?? 0, 2); ?>
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('payments')">Payment List</button>
            <button class="tab" onclick="showTab('monthly')">Monthly Summary</button>
            <div class="month-filter-container">
                <select class="month-filter-select" id="monthSelect" onchange="updateFilters()">
                    <?php foreach($months as $num => $name): ?>
                        <option value="<?php echo $num; ?>" <?php echo $num == $selectedMonth ? 'selected' : ''; ?>>
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filters">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search by ID, Name, or Payment ID..." class="search-input">
                    <button onclick="clearSearch()" class="clear-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>

        <div id="payments-view">
            <div class="table-container">
                <table id="paymentsTable">
                    <thead>
                        <tr>
                            <th>Payment ID</th>
                            <th>Member ID</th>
                            <th>Name</th>
                            <th>Payment Type</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $memberPayments = getMemberPayments($selectedYear, $selectedMonth, $selectedMemberID);
                        while($row = $memberPayments->fetch_assoc()): 
                        ?>
                        <tr data-payment-type="<?php echo htmlspecialchars($row['Payment_Type']); ?>" data-method="<?php echo htmlspecialchars($row['Method']); ?>">
                            <td><?php echo htmlspecialchars($row['PaymentID']); ?></td>
                            <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                            <td><?php echo htmlspecialchars($row['Name']); ?></td>
                            <td><?php echo htmlspecialchars($row['Payment_Type']); ?></td>
                            <td><?php echo htmlspecialchars($row['Method']); ?></td>
                            <td>Rs. <?php echo number_format($row['Amount'], 2); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($row['Date'])); ?></td>
                            <td class="actions">
                            <button onclick="viewPaymentReceipt('<?php echo $row['PaymentID']; ?>')" class="action-btn small">
                                <i class="fas fa-eye"></i>
                            </button>
                                <button onclick="editPayment('<?php echo $row['PaymentID']; ?>')" class="action-btn small">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="openDeleteModal('<?php echo $row['PaymentID']; ?>')" class="action-btn small">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="monthly-view" style="display: none;">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Payment Count</th>
                            <th>Total Amount</th>
                            <th>Unique Members</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $monthlyStats->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $months[$row['month']]; ?></td>
                            <td><?php echo $row['payment_count']; ?></td>
                            <td>Rs. <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td><?php echo $row['unique_members']; ?></td>
                            <td class="actions">
                                <button onclick="viewMonthDetails(<?php echo $row['month']; ?>)" class="action-btn small">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button onclick="exportMonthReport(<?php echo $row['month']; ?>)" class="action-btn small">
                                    <i class="fas fa-download"></i> Export
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php include '../../templates/footer.php'; ?>
    </div>

    <!-- Payment Details Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Payment Details</h2>
            <div id="paymentDetails"></div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this payment record? This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" id="delete_payment_id" name="payment_id">
                <div class="delete-modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete_payment" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Payment Modal -->
<div id="editPaymentModal" class="modal">
    <div class="modal-content" style="max-width: 90%; height: 90%;">
        <span class="close" onclick="closeEditModal()">&times;</span>
        <iframe id="editPaymentFrame" style="width: 100%; height: 90%; border: none;"></iframe>
    </div>
</div>

    <script>
        // Update filters using AJAX like in membership_fee.php
        function updateFilters() {
            const year = document.getElementById('yearSelect').value;
            const month = document.getElementById('monthSelect').value;
            
            // refresh the page at the same location
            history.pushState(null, '', `?year=${year}&month=${month}`);
            
            fetch(`payment.php?year=${year}&month=${month}`)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Update stats cards
                    document.getElementById('stats-section').innerHTML = doc.getElementById('stats-section').innerHTML;
                    
                    // Update payments view
                    document.getElementById('payments-view').innerHTML = doc.getElementById('payments-view').innerHTML;
                    
                    // Update monthly view
                    document.getElementById('monthly-view').innerHTML = doc.getElementById('monthly-view').innerHTML;
                });
        }

        // Switch between tabs
        function showTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById('payments-view').style.display = 'none';
            document.getElementById('monthly-view').style.display = 'none';
            
            if (tab === 'payments') {
                document.getElementById('payments-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'payments\')"]').classList.add('active');
            } else {
                document.getElementById('monthly-view').style.display = 'block';
                document.querySelector('button[onclick="showTab(\'monthly\')"]').classList.add('active');
            }
        }

        // Filter payment list based on type and method
        function filterPaymentList() {
            const paymentType = document.getElementById('paymentTypeFilter').value;
            const method = document.getElementById('methodFilter').value;
            const tableRows = document.querySelectorAll('#paymentsTable tbody tr');
            let hasResults = false;

            tableRows.forEach(row => {
                const rowPaymentType = row.getAttribute('data-payment-type');
                const rowMethod = row.getAttribute('data-method');
                
                const typeMatch = paymentType === '' || rowPaymentType === paymentType;
                const methodMatch = method === '' || rowMethod === method;
                
                if (typeMatch && methodMatch) {
                    row.style.display = '';
                    hasResults = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Show/hide no results message
            updateNoResultsMessage(hasResults);
        }

        // Search functionality, updated to match membership_fee.php
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            searchInput.addEventListener('input', performSearch);
        });

        function performSearch() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const tableRows = document.querySelectorAll('#paymentsTable tbody tr');
            let hasResults = false;

            tableRows.forEach(row => {

                const paymentID = row.cells[0].textContent.toLowerCase();
                const memberID = row.cells[1].textContent.toLowerCase();
                const name = row.cells[2].textContent.toLowerCase();
                
                if (name.includes(searchTerm) || 
                    memberID.includes(searchTerm) || 
                    paymentID.includes(searchTerm)) {
                    row.style.display = '';
                    hasResults = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Show/hide no results message
            updateNoResultsMessage(hasResults);
        }

        function updateNoResultsMessage(hasResults) {
            let noResultsMsg = document.querySelector('.no-results');
            if (!hasResults) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.className = 'no-results';
                    noResultsMsg.textContent = 'No matching records found';
                    const table = document.querySelector('#payments-view .table-container');
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

        function viewPaymentReceipt(paymentID) {
            window.location.href = `../payments/payment_receipt.php?payment_id=${paymentID}`;
        }

        // Modal handling
        const modal = document.getElementById('paymentModal');
        const closeBtn = document.querySelector('.close');

        closeBtn.onclick = function() {
            modal.style.display = "none";
        }

        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }

        // View payment details
        function viewPaymentDetails(paymentID) {
            document.getElementById('paymentDetails').innerHTML = paymentDetails;
            modal.style.display = "block";
        }

        // Edit payment
        function editPayment(paymentID) {
            // Redirect to edit page
            document.getElementById('editPaymentFrame').src = `editPayment.php?id=${paymentID}&popup=true`;
            document.getElementById('editPaymentModal').style.display = 'block';
        }

        function openDeleteModal(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_payment_id').value = id;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Update window.onclick to handle delete modal too
        window.onclick = function(event) {
            const modal = document.getElementById('paymentModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target == modal) {
                modal.style.display = "none";
            }
            
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        };

        // View month details
        function viewMonthDetails(month) {
            const year = document.getElementById('yearSelect').value;
            window.location.href = `?year=${year}&month=${month}`;
        }

        // Export month report
        function exportMonthReport(month) {
            const year = document.getElementById('yearSelect').value;
            window.location.href = `exportPaymentReport.php?year=${year}&month=${month}`;
        }

        // Edit Payment Modal Functions
function editPayment(paymentID) {
    // Set the iframe source to your editPayment.php page
    document.getElementById('editPaymentFrame').src = `editPayment.php?id=${paymentID}&popup=true`;
    
    // Show the modal
    document.getElementById('editPaymentModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('editPaymentModal').style.display = 'none';
    
    // After closing, refresh the payment list to see any changes
    updateFilters();
}

// Add edit modal to window onclick handler
window.onclick = function(event) {
    const modal = document.getElementById('paymentModal');
    const deleteModal = document.getElementById('deleteModal');
    const editModal = document.getElementById('editPaymentModal');
    
    if (event.target == modal) {
        modal.style.display = "none";
    }
    
    if (event.target == deleteModal) {
        closeDeleteModal();
    }
    
    if (event.target == editModal) {
        closeEditModal();
    }
};

// Add this function to handle displaying alerts from the iframe
function showAlert(type, message) {
    const alertsContainer = document.querySelector('.alerts-container');
    
    // Create alert element
    const alertDiv = document.createElement('div');
    alertDiv.className = type === 'success' ? 'alert alert-success' : 'alert alert-danger';
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
    </script>
</body>
</html>