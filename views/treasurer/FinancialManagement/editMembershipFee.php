<?php
session_start();
require_once "../../../config/database.php";

// Get current term and selected year from previous page
$currentTerm = isset($_SESSION['selected_year']) ? $_SESSION['selected_year'] : date('Y');
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentTerm;

// Fetch membership fee details for the selected year with prepared statement
function getMembershipFeeDetails($year, $limit = 15) {
    $sql = "SELECT 
            mf.*,
            m.Name as MemberName,
            m.MemberID,
            mfp.Details as change_details,
            p.Date as payment_date
        FROM MembershipFee mf
        JOIN Member m ON m.MemberID = mf.Member_MemberID
        LEFT JOIN MembershipFeePayment mfp ON mfp.FeeID = mf.FeeID
        LEFT JOIN Payment p ON p.PaymentID = mfp.PaymentID
        WHERE YEAR(mf.Date) = ? AND mf.Term = ?
        ORDER BY mf.Date DESC
        LIMIT ?";
    
    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $year, $year, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result;
}

$feeDetails = getMembershipFeeDetails($selectedYear);
$limitedFeeDetails = [];
$rowCount = 0;

// Fix the while loop
while (($row = $feeDetails->fetch_assoc()) && $rowCount < 15) {
    $limitedFeeDetails[] = $row;
    $rowCount++;
}

// Get fee amounts from Static table with prepared statement
function getFeeAmounts($year) {
    $sql = "SELECT monthly_fee, registration_fee FROM Static WHERE year = ? LIMIT 1";
    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    return $stmt->get_result();
}

// Handle Update
if(isset($_POST['update'])) {
    $feeId = $_POST['fee_id'];
    $amount = floatval($_POST['amount']);
    $isPaid = $_POST['is_paid'];
    $date = $_POST['date'];
    $details = $_POST['details'];
    
    try {
        $conn = getConnection();
        
        // Start transaction to ensure consistency
        $conn->begin_transaction();
        
        // Update MembershipFee table with prepared statement
        $updateQuery = "UPDATE MembershipFee SET 
                       Amount = ?,
                       IsPaid = ?,
                       Date = ?
                       WHERE FeeID = ?";
        
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("dsss", $amount, $isPaid, $date, $feeId);
        $stmt->execute();
        
        // If payment status changed to Paid, create payment record
        if($isPaid == 'Yes') {
            // First check if payment already exists
            $checkQuery = "SELECT PaymentID FROM MembershipFeePayment WHERE FeeID = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("s", $feeId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if($result->num_rows == 0) {
                // Get member ID for the fee
                $memberQuery = "SELECT Member_MemberID, Term FROM MembershipFee WHERE FeeID = ?";
                $stmt = $conn->prepare($memberQuery);
                $stmt->bind_param("s", $feeId);
                $stmt->execute();
                $memberResult = $stmt->get_result();
                $memberData = $memberResult->fetch_assoc();
                $memberId = $memberData['Member_MemberID'];
                $term = $memberData['Term'];
                
                // Generate a proper payment ID
                $paymentId = generatePaymentId($conn);
                
                // Create new payment
                $paymentQuery = "INSERT INTO Payment 
                                (PaymentID, Payment_Type, Method, Amount, Date, Term, Member_MemberID)
                                VALUES (?, 'Membership', 'Cash', ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($paymentQuery);
                $stmt->bind_param("sdsss", $paymentId, $amount, $date, $term, $memberId);
                $stmt->execute();
                
                // Link payment to membership fee with details
                $linkQuery = "INSERT INTO MembershipFeePayment (FeeID, PaymentID, Details) 
                            VALUES (?, ?, ?)";
                $stmt = $conn->prepare($linkQuery);
                $stmt->bind_param("sss", $feeId, $paymentId, $details);
                $stmt->execute();
            } else {
                // Update existing payment
                $row = $result->fetch_assoc();
                $paymentId = $row['PaymentID'];
                
                // Update Payment record
                $updatePaymentQuery = "UPDATE Payment SET 
                                     Amount = ?,
                                     Date = ?
                                     WHERE PaymentID = ?";
                $stmt = $conn->prepare($updatePaymentQuery);
                $stmt->bind_param("dss", $amount, $date, $paymentId);
                $stmt->execute();
                
                // Update MembershipFeePayment details
                $newDetails = date('Y-m-d') . ": " . $details;
                $updateDetailsQuery = "UPDATE MembershipFeePayment SET 
                                     Details = CONCAT(IFNULL(Details,''), '\n', ?)
                                     WHERE FeeID = ? AND PaymentID = ?";
                $stmt = $conn->prepare($updateDetailsQuery);
                $stmt->bind_param("sss", $newDetails, $feeId, $paymentId);
                $stmt->execute();
            }
        } else {
            // If marked as unpaid, delete any existing payment records
            
            // First, get the payment ID related to this fee
            $findPaymentQuery = "SELECT PaymentID FROM MembershipFeePayment WHERE FeeID = ?";
            $stmt = $conn->prepare($findPaymentQuery);
            $stmt->bind_param("s", $feeId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $paymentId = $row['PaymentID'];
                
                // Delete from MembershipFeePayment first
                $deleteLinkQuery = "DELETE FROM MembershipFeePayment WHERE FeeID = ?";
                $stmt = $conn->prepare($deleteLinkQuery);
                $stmt->bind_param("s", $feeId);
                $stmt->execute();
                
                // Delete from Payment table
                $deletePaymentQuery = "DELETE FROM Payment WHERE PaymentID = ?";
                $stmt = $conn->prepare($deletePaymentQuery);
                $stmt->bind_param("s", $paymentId);
                $stmt->execute();
            }
        }
        
        // Commit all changes
        $conn->commit();
        
        $_SESSION['success_message'] = "Fee details updated successfully!";
        
    } catch (Exception $e) {
        // Rollback on error
        if(isset($conn)) {
            $conn->rollback();
        }
        $_SESSION['error_message'] = "Error updating fee details: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?year=" . $selectedYear);
    exit();
}

if(isset($_POST['delete'])) {
    $feeId = $_POST['fee_id'];
    
    try {
        $conn = getConnection();
        
        // Start transaction for consistency
        $conn->begin_transaction();
        
        // First, get the payment ID related to this fee
        $findPaymentQuery = "SELECT PaymentID FROM MembershipFeePayment WHERE FeeID = ?";
        $stmt = $conn->prepare($findPaymentQuery);
        $stmt->bind_param("s", $feeId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Delete related records from MembershipFeePayment
        $deletePaymentLinks = "DELETE FROM MembershipFeePayment WHERE FeeID = ?";
        $stmt = $conn->prepare($deletePaymentLinks);
        $stmt->bind_param("s", $feeId);
        $stmt->execute();
        
        // Delete related Payment records if they exist
        if($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $paymentId = $row['PaymentID'];
            
            $deletePayment = "DELETE FROM Payment WHERE PaymentID = ?";
            $stmt = $conn->prepare($deletePayment);
            $stmt->bind_param("s", $paymentId);
            $stmt->execute();
        }
        
        // Then delete the fee record
        $deleteFee = "DELETE FROM MembershipFee WHERE FeeID = ?";
        $stmt = $conn->prepare($deleteFee);
        $stmt->bind_param("s", $feeId);
        $stmt->execute();
        
        // Commit all changes
        $conn->commit();
        
        $_SESSION['success_message'] = "Fee record deleted successfully!";
        
    } catch (Exception $e) {
        // Rollback on error
        if(isset($conn)) {
            $conn->rollback();
        }
        $_SESSION['error_message'] = "Error deleting fee record: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?year=" . $selectedYear);
    exit();
}

// Function to generate a proper payment ID
function generatePaymentId($conn) {
    // Try to set isolation level
    $conn->query("SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE");
    
    // Get the highest ID
    $query = "SELECT CAST(SUBSTRING(PaymentID, 4) AS UNSIGNED) as max_num 
             FROM Payment 
             WHERE PaymentID LIKE 'PAY%'
             ORDER BY PaymentID DESC 
             LIMIT 1 FOR UPDATE";
    
    $result = $conn->query($query);
    
    // Determine the next number
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $nextNum = $row['max_num'] + 1;
    } else {
        $nextNum = 1;
    }
    
    // Generate the new ID
    $newId = "PAY" . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
    
    // Verify it doesn't exist (double check)
    $verifyQuery = "SELECT COUNT(*) as count FROM Payment WHERE PaymentID = ?";
    $stmt = $conn->prepare($verifyQuery);
    $stmt->bind_param("s", $newId);
    $stmt->execute();
    $verifyResult = $stmt->get_result();
    
    if ($verifyResult->fetch_assoc()['count'] > 0) {
        return generatePaymentId($conn); // Try again if ID exists
    }
    
    return $newId;
}

// Get fee details for display
$feeAmountsResult = getFeeAmounts($selectedYear);
$feeAmounts = $feeAmountsResult->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Membership Fee Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/adminActorDetails.css">
    <style>
        /* Add these styles to your existing adminActorDetails.css or include them in a style tag */

/* Year Selection Box */
.filter-section {
    margin-bottom: 2rem;
}

.filter-input {
    margin-top: 2rem;
    padding: 0.8rem 1rem;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 1rem;
    min-width: 200px;
    background-color: #1a237e;
    color: #FFFFFF;
    cursor: pointer;
    transition: border-color 0.3s, box-shadow 0.3s;
}

.filter-input:focus {
    border-color: #1a237e;
    outline: none;
    box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
}

.filter-input:hover {
    border-color: #1a237e;
}

/* Edit Modal Styles */
.modal {
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

.modal-content {
    background-color: white;
    margin: 3% auto;
    padding: 2.5rem;
    width: 90%;
    max-width: 600px;
    border-radius: 12px;
    position: relative;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
}

.modal-content h2 {
    color: #1a237e;
    margin-bottom: 1.5rem;
}

.close {
    position: absolute;
    right: 25px;
    top: 25px;
    font-size: 24px;
    cursor: pointer;
    color: #666;
    transition: color 0.3s;
}

.close:hover {
    color: #000;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.7rem;
    font-weight: 500;
    color: #333;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.8rem 1rem;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 1rem;
    transition: border-color 0.3s;
}

.form-group textarea {
    min-height: 100px;
    resize: vertical;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #1a237e;
    outline: none;
    box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
}

.modal-footer {
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
}

.save-btn, 
.cancel-btn {
    padding: 0.8rem 1.8rem;
    border: none;
    border-radius: 6px;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s;
}

.save-btn {
    background-color: #1a237e;
    color: white;
}

.save-btn:hover {
    background-color: #0d1757;
}

.cancel-btn {
    background-color: #e0e0e0;
    color: #333;
}

.cancel-btn:hover {
    background-color: #d0d0d0;
}

.back-button-container {
    margin-top: 0rem;
    text-align: center;
}

.container {
    margin-top: 0rem;
}

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.8rem 1.5rem;
    background-color: #1e3c72;
    color: white;
    text-decoration: none;
    border-radius: 8px;
    transition: background-color 0.3s ease;
}

.back-btn:hover {
    background-color: #2a5298;
}

.back-btn i {
    margin-right: 0.5rem;
}

.search-section {
    margin-bottom: 2rem;
}

.search-input {
    padding: 0.8rem 1rem;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 1rem;
    min-width: 300px;
    background-color: white;
    color: #333;
    transition: border-color 0.3s, box-shadow 0.3s;
}

.search-input:focus {
    border-color: #1a237e;
    outline: none;
    box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
}

.search-input::placeholder {
    color: #999;
}

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

/* Responsive Adjustments */
@media (max-width: 768px) {
    .filter-input {
        width: 100%;
    }

    .modal-content, .delete-modal-content {
        margin: 0;
        margin-top: 20px;
        width: 100%;
        max-height: 95vh;
        overflow-y: auto;
    }

    .modal-footer, .delete-modal-buttons {
        flex-direction: column;
    }

    .save-btn, .cancel-btn, .confirm-delete-btn {
        width: 100%;
    }
}

.table-container {
    max-height: 400px; /* Adjust height as needed */
    overflow-y: auto;
    padding-top: 0px;
}

/* Keep the table header fixed while scrolling */
.table-container table thead {
    position: sticky;
    top: 0;
    background: #f8f9fa;
    z-index: 1;
}

/* Add shadow to header when scrolling */
.table-container table thead::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: 0;
    width: 100%;
    border-bottom: 1px solid #eee;
}

/* Style the scrollbar */
.table-container::-webkit-scrollbar {
    width: 8px;
}

.table-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb {
    background: #1e3c72;
    border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb:hover {
    background: #2a5298;
}

/* Ensure consistent cell heights */
.table-container table td {
    height: 40px;
}
</style>
</head>
<body>
    <div class="main-container">
        <?php include '../../templates/navbar-treasurer.php'; ?>
        <div class="container">
            <div class="header-section">
                <h1>Edit Membership Fee Details</h1>
                <div class="filter-section">
                    <select class="filter-input" onchange="updateYear(this.value)">
                        <?php for($y = $currentTerm; $y >= $currentTerm - 2; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $selectedYear ? 'selected' : ''; ?>>
                                Year <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="search-section">
                <input type="text" 
                    id="searchInput" 
                    class="search-input" 
                    placeholder="Search by Member Name or ID..."
                    onkeyup="searchTable()">
            </div>

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

            <div class="table-responsive table-container">
                <table class="treasurer-table">
                    <thead>
                        <tr>
                            <th>Member ID</th>
                            <th>Name</th>
                            <th>Fee Type</th>
                            <th>Amount</th>
                            <th>Fee Date</th>
                            <th>Payment Date</th>
                            <th>Change Details</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>

                        <?php foreach($limitedFeeDetails as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                            <td><?php echo htmlspecialchars($row['MemberName']); ?></td>
                            <td><?php echo htmlspecialchars($row['Type']); ?></td>
                            <td>Rs. <?php echo number_format($row['Amount'], 2); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($row['Date'])); ?></td>
                            <td><?php echo $row['payment_date'] ? date('Y-m-d', strtotime($row['payment_date'])) : '-'; ?></td>
                            <td class="history-cell" title="<?php echo htmlspecialchars($row['change_details'] ?? ''); ?>">
                                <?php 
                                // Limit display length with ellipsis for better UI
                                $details = $row['change_details'] ?? '-';
                                echo strlen($details) > 50 ? htmlspecialchars(substr($details, 0, 50) . '...') : htmlspecialchars($details); 
                                ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick="openEditModal('<?php echo $row['FeeID']; ?>', '<?php echo $row['Amount']; ?>', '<?php echo $row['IsPaid']; ?>', '<?php echo date('Y-m-d', strtotime($row['Date'])); ?>')">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn delete-btn" onclick="openDeleteModal('<?php echo $row['FeeID']; ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Edit Fee Details</h2>
            <form id="editForm" method="POST">
                <input type="hidden" id="edit_fee_id" name="fee_id">
                
                <div class="form-group">
                    <label for="edit_amount">Amount (Rs.)</label>
                    <input type="number" step="0.01" id="edit_amount" name="amount" required>
                </div>

                <div class="form-group">
                    <label for="edit_status">Payment Status</label>
                    <select id="edit_status" name="is_paid" required>
                        <option value="Yes">Paid</option>
                        <option value="No">Unpaid</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_date">Date</label>
                    <input type="date" id="edit_date" name="date" required>
                </div>

                <div class="form-group">
                    <label for="edit_details">Change Details/Notes (Required)</label>
                    <textarea id="edit_details" name="details" 
                              placeholder="Please provide a reason for this change..." required></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="update" class="save-btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this fee record? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" id="delete_fee_id" name="fee_id">
                <div class="delete-modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete" class="confirm-delete-btn">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Back Button -->
    <div class="container">
        <div class="back-button-container">
            <a href="membershipFee.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Membership Fees
            </a>
        </div>
    </div>

    <script>
        function updateYear(year) {
            window.location.href = `?year=${year}`;
        }

        function openEditModal(id, amount, isPaid, date) {
            document.getElementById('editModal').style.display = 'block';
            document.getElementById('edit_fee_id').value = id;
            document.getElementById('edit_amount').value = amount;
            document.getElementById('edit_status').value = isPaid;
            document.getElementById('edit_date').value = date;
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openDeleteModal(id) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_fee_id').value = id;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Combined window.onclick for both modals
        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target == editModal) {
                closeModal();
            }
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        };

        function searchTable() {
            // Get input value and convert to lowercase for case-insensitive search
            var input = document.getElementById("searchInput");
            var filter = input.value.toLowerCase();
            
            // Get all table rows except the header
            var table = document.querySelector(".treasurer-table");
            var rows = table.getElementsByTagName("tr");
            
            // Loop through all table rows
            for (var i = 1; i < rows.length; i++) {
                var show = false;
                
                // Get member ID and name cells
                var idCell = rows[i].cells[0];
                var nameCell = rows[i].cells[1];
                
                if (idCell && nameCell) {
                    var id = idCell.textContent || idCell.innerText;
                    var name = nameCell.textContent || nameCell.innerText;
                    
                    // Check if either ID or name matches the search term
                    if (id.toLowerCase().indexOf(filter) > -1 || 
                        name.toLowerCase().indexOf(filter) > -1) {
                        show = true;
                    }
                }
                
                // Show or hide the row based on search match
                rows[i].style.display = show ? "" : "none";
            }
        }
    </script>
</body>
</html>