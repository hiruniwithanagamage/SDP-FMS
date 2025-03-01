<?php
session_start();
require_once "../../config/database.php";

function createExpenseRecord($loanId, $amount) {
    try {
        // Get treasurer ID from User table
        $treasurerQuery = "SELECT Treasurer_TreasurerID FROM User WHERE UserId = '{$_SESSION['user_id']}'";
        $treasurerResult = search($treasurerQuery);
        $treasurerData = $treasurerResult->fetch_assoc();
        
        if (!$treasurerData || !$treasurerData['Treasurer_TreasurerID']) {
            throw new Exception("Invalid Treasurer ID");
        }

        // Generate Expense ID
        $sql = "SELECT ExpenseID FROM Expenses 
                WHERE ExpenseID LIKE 'EXP%' 
                ORDER BY ExpenseID DESC LIMIT 1";
        $result = search($sql);
        
        if ($result->num_rows > 0) {
            $lastId = $result->fetch_assoc()['ExpenseID'];
            $lastNum = intval(substr($lastId, 3));
            $newNum = $lastNum + 1;
            $expenseId = 'EXP' . str_pad($newNum, 3, '0', STR_PAD_LEFT);
        } else {
            $expenseId = 'EXP001';
        }

        // Set up expense data
        $date = date('Y-m-d');
        $term = date('Y');
        $treasurerId = $treasurerData['Treasurer_TreasurerID'];
        
        // Insert expense record
        $sql = "INSERT INTO Expenses (ExpenseID, Category, Method, Amount, Date, Term, Description, Image, Treasurer_TreasurerID) 
                VALUES ('$expenseId', 'Loan', 'System', $amount, '$date', $term, 'Loan Payment (LoanID: $loanId)', NULL, '$treasurerId')";
        Database::iud($sql);
        
        return true;

    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error creating expense record: " . $e->getMessage();
        return false;
    }
}

// Fetch pending loans with member details
$query = "SELECT l.*, m.Name as MemberName, m.MemberID 
          FROM Loan l 
          JOIN Member m ON l.Member_MemberID = m.MemberID 
          WHERE l.Status = 'pending' 
          ORDER BY l.Issued_Date DESC";
$result = search($query);

// Handle loan approval/rejection
if(isset($_POST['update_status'])) {
    $loanId = $_POST['loan_id'];
    $status = $_POST['status'];
    
    try {
        if($status === 'approved') {
            // Get loan amount
            $loanQuery = "SELECT Amount FROM Loan WHERE LoanID = '$loanId'";
            $loanResult = search($loanQuery);
            $loanData = $loanResult->fetch_assoc();
            
            if($loanData) {
                // Begin processing
                if(createExpenseRecord($loanId, $loanData['Amount'])) {
                    // Update loan status
                    $updateQuery = "UPDATE Loan SET Status = '$status' WHERE LoanID = '$loanId'";
                    Database::iud($updateQuery);
                    $_SESSION['success_message'] = "Loan approved and expense recorded successfully!";
                } else {
                    throw new Exception("Failed to create expense record");
                }
            } else {
                throw new Exception("Loan record not found");
            }
        } else {
            // Just update status for rejection
            $updateQuery = "UPDATE Loan SET Status = '$status' WHERE LoanID = '$loanId'";
            Database::iud($updateQuery);
            $_SESSION['success_message'] = "Loan application has been rejected.";
        }
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error updating loan status: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Loans</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminActorDetails.css">
    <style>
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            opacity: 1;
            transition: all 0.5s ease-in-out;
        }

        .alert.fade-out {
            opacity: 0;
            transform: translateY(-20px);
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .action-btn.approve-btn {
            background-color: #28a745;
            color: white;
        }

        .action-btn.reject-btn {
            background-color: #dc3545;
            color: white;
        }

        .loan-details {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin-top: 0.5rem;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: #1e3c72;
            font-weight: 500;
            margin-bottom: 1rem;
        }

        .loan-amount {
            font-weight: bold;
            color: #1e3c72;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .delete-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .delete-modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 2rem;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            position: relative;
        }

        .delete-modal-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-treasurer.php'; ?>
        <div class="container">
            <div class="header-section">
                <h1>Pending Loan Applications</h1>
                <a href="home-treasurer.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
            </div>

            <?php if(isset($_SESSION['success_message'])): ?>
                <div id="success-alert" class="alert alert-success">
                    <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['error_message'])): ?>
                <div id="error-alert" class="alert alert-danger">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="treasurer-table">
                    <thead>
                        <tr>
                            <th>Loan ID</th>
                            <th>Member Name</th>
                            <th>Amount</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['LoanID']); ?></td>
                            <td><?php echo htmlspecialchars($row['MemberName']); ?></td>
                            <td>Rs. <?php echo htmlspecialchars(number_format($row['Amount'], 2)); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($row['Issued_Date'])); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($row['Due_Date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['Reason']); ?></td>
                            <td>
                                <span class="status-badge status-pending">
                                    Pending
                                </span>
                            </td>
                            <td>
                            <div class="action-buttons">
                                <button class="action-btn approve-btn" onclick="openApproveModal('<?php echo $row['LoanID']; ?>')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="action-btn reject-btn" onclick="openRejectModal('<?php echo $row['LoanID']; ?>')">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <!-- Approve Confirmation Modal -->
            <div id="approveModal" class="delete-modal">
                <div class="delete-modal-content">
                    <h2>Confirm Approve</h2>
                    <p>Are you sure you want to approve this loan application?</p>
                    <form method="POST" id="approveForm">
                        <input type="hidden" id="approve_loan_id" name="loan_id">
                        <input type="hidden" name="status" value="approved">
                        <input type="hidden" name="update_status" value="1">
                        <div class="delete-modal-buttons">
                            <button type="button" class="cancel-btn" onclick="closeApproveModal()">Cancel</button>
                            <button type="submit" class="confirm-delete-btn" style="background-color: #28a745;">Approve</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Reject Confirmation Modal -->
            <div id="rejectModal" class="delete-modal">
                <div class="delete-modal-content">
                    <h2>Confirm Reject</h2>
                    <p>Are you sure you want to reject this loan application?</p>
                    <form method="POST" id="rejectForm">
                        <input type="hidden" id="reject_loan_id" name="loan_id">
                        <input type="hidden" name="status" value="rejected">
                        <input type="hidden" name="update_status" value="1">
                        <div class="delete-modal-buttons">
                            <button type="button" class="cancel-btn" onclick="closeRejectModal()">Cancel</button>
                            <button type="submit" class="confirm-delete-btn">Reject</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openApproveModal(id) {
            document.getElementById('approveModal').style.display = 'block';
            document.getElementById('approve_loan_id').value = id;
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }

        function openRejectModal(id) {
            document.getElementById('rejectModal').style.display = 'block';
            document.getElementById('reject_loan_id').value = id;
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const approveModal = document.getElementById('approveModal');
            const rejectModal = document.getElementById('rejectModal');
            
            if (event.target == approveModal) {
                closeApproveModal();
            }
            if (event.target == rejectModal) {
                closeRejectModal();
            }
        }

        // Alert handling
        document.addEventListener('DOMContentLoaded', function() {
            function fadeOutAlert(alertElement) {
                if (alertElement) {
                    setTimeout(() => {
                        alertElement.classList.add('fade-out');
                    }, 2000);
                    setTimeout(() => {
                        alertElement.remove();
                    }, 2500);
                }
            }

            fadeOutAlert(document.getElementById('success-alert'));
            fadeOutAlert(document.getElementById('error-alert'));
        });
    </script>
</body>
</html>