<?php
session_start();
require_once "../../config/database.php";

function generateExpenseId() {
    // Get the latest expense ID
    $sql = "SELECT ExpenseID FROM Expenses 
            WHERE ExpenseID LIKE 'EXP%' 
            ORDER BY ExpenseID DESC LIMIT 1";
    $result = search($sql);
    
    if ($result->num_rows > 0) {
        $lastId = $result->fetch_assoc()['ExpenseID'];
        // Extract the number part and increment
        $lastNum = intval(substr($lastId, 3)); // Remove 'EXP' and convert to integer
        $newNum = $lastNum + 1;
        // Format with leading zeros
        $expenseId = 'EXP' . str_pad($newNum, 3, '0', STR_PAD_LEFT);
    } else {
        // If no existing records, start with EXP001
        $expenseId = 'EXP001';
    }
    
    return $expenseId;
}

function createExpenseRecord($welfareId, $amount) {
    $treasurerQuery = "SELECT Treasurer_TreasurerID FROM User WHERE UserId = '{$_SESSION['user_id']}'";
    $treasurerResult = search($treasurerQuery);
    $treasurerData = $treasurerResult->fetch_assoc();
    
    if (!$treasurerData || !$treasurerData['Treasurer_TreasurerID']) {
        throw new Exception("Invalid Treasurer ID");
    }

    $date = date('Y-m-d');
    $term = date('Y');
    $expenseId = generateExpenseId();
    $treasurerId = $treasurerData['Treasurer_TreasurerID'];
    
    $sql = "INSERT INTO Expenses (ExpenseID, Category, Method, Amount, Date, Term, Description, Image, Treasurer_TreasurerID) 
            VALUES ('$expenseId', 'Death Welfare', 'System', $amount, '$date', $term, 'Death Welfare Payment (WelfareID: $welfareId)', NULL, '$treasurerId')";
    
    Database::iud($sql);
    
    // Update DeathWelfare record with the expense ID
    $updateSql = "UPDATE DeathWelfare SET Expense_ExpenseID = '$expenseId' WHERE WelfareID = '$welfareId'";
    Database::iud($updateSql);
    
    return $expenseId;
}

// Fetch pending death welfare applications with member details
$query = "SELECT dw.*, m.Name as MemberName, m.MemberID 
          FROM DeathWelfare dw
          JOIN Member m ON dw.Member_MemberID = m.MemberID 
          WHERE dw.Status = 'pending' 
          ORDER BY dw.Date DESC";
$result = search($query);

// Replace the existing approval handling code with this
if(isset($_POST['update_status'])) {
    $welfareId = $_POST['welfare_id'];
    $status = $_POST['status'];
    
    try {
        // Start by getting welfare amount if it's being approved
        if($status === 'approved') {
            $welfareQuery = "SELECT Amount FROM DeathWelfare WHERE WelfareID = '$welfareId'";
            $welfareResult = search($welfareQuery);
            $welfareData = $welfareResult->fetch_assoc();
            
            if($welfareData) {
                // Create expense record
                $expenseId = createExpenseRecord($welfareId, $welfareData['Amount']);
                
                // Update welfare status
                $updateQuery = "UPDATE DeathWelfare SET Status = '$status' WHERE WelfareID = '$welfareId'";
                Database::iud($updateQuery);
                
                $_SESSION['success_message'] = "Death welfare approved and expense recorded successfully!";
            } else {
                throw new Exception("Welfare record not found");
            }
        } else {
            // Just update status for rejection
            $updateQuery = "UPDATE DeathWelfare SET Status = '$status' WHERE WelfareID = '$welfareId'";
            Database::iud($updateQuery);
            $_SESSION['success_message'] = "Death welfare application has been rejected.";
        }
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error updating status: " . $e->getMessage();
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
    <title>Pending Death Welfare Applications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminDetails.css">
    <style>
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            opacity: 1;
            transition: all 0.5s ease-in-out;
            position: relative;
            z-index: 1000;
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

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: #1e3c72;
            font-weight: 500;
            margin-bottom: 1rem;
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

        .relationship-tag {
            background-color: #e9ecef;
            color: #495057;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.875rem;
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
                <h1>Pending Death Welfare Applications</h1>
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
                            <th>Welfare ID</th>
                            <th>Member Name</th>
                            <th>Relationship</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Term</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['WelfareID']); ?></td>
                            <td><?php echo htmlspecialchars($row['MemberName']); ?></td>
                            <td>
                                <span class="relationship-tag">
                                    <?php echo htmlspecialchars($row['Relationship']); ?>
                                </span>
                            </td>
                            <td>Rs. <?php echo htmlspecialchars(number_format($row['Amount'], 2)); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($row['Date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['Term']); ?></td>
                            <td>
                                <span class="status-badge status-pending">
                                    Pending
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn approve-btn" onclick="openApproveModal('<?php echo $row['WelfareID']; ?>')">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button class="action-btn reject-btn" onclick="openRejectModal('<?php echo $row['WelfareID']; ?>')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Approve Confirmation Modal -->
        <div id="approveModal" class="delete-modal">
            <div class="delete-modal-content">
                <h2>Confirm Approve</h2>
                <p>Are you sure you want to approve this death welfare application?</p>
                <form method="POST" id="approveForm">
                    <input type="hidden" id="approve_welfare_id" name="welfare_id">
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
                <p>Are you sure you want to reject this death welfare application?</p>
                <form method="POST" id="rejectForm">
                    <input type="hidden" id="reject_welfare_id" name="welfare_id">
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

    <script>
        function openApproveModal(id) {
            document.getElementById('approveModal').style.display = 'block';
            document.getElementById('approve_welfare_id').value = id;
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }

        function openRejectModal(id) {
            document.getElementById('rejectModal').style.display = 'block';
            document.getElementById('reject_welfare_id').value = id;
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