<?php
session_start();
require_once "../../config/database.php";

function createExpenseRecord($loanId, $amount) {
    try {
        // Get treasurer ID from User table using prepared statement
        $treasurerQuery = prepare("SELECT Treasurer_TreasurerID FROM User WHERE UserId = ?");
        $treasurerQuery->bind_param("s", $_SESSION['user_id']);
        $treasurerQuery->execute();
        $treasurerResult = $treasurerQuery->get_result();
        $treasurerData = $treasurerResult->fetch_assoc();
        
        if (!$treasurerData || !$treasurerData['Treasurer_TreasurerID']) {
            throw new Exception("Invalid Treasurer ID");
        }

        // Get current active term (year)
        $termQuery = "SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
        $termResult = search($termQuery);
        
        if (!$termResult || $termResult->num_rows === 0) {
            throw new Exception("Could not determine active term");
        }
        
        $termData = $termResult->fetch_assoc();
        $activeTerm = $termData['year'];
        
        // Get last 2 digits of the active term
        $termSuffix = substr($activeTerm, -2);
        
        // Find the highest sequential number for the current term
        $sql = "SELECT ExpenseID FROM Expenses 
                WHERE ExpenseID LIKE 'EXP$termSuffix%' 
                ORDER BY ExpenseID DESC LIMIT 1";
        $result = search($sql);
        
        if ($result && $result->num_rows > 0) {
            $lastId = $result->fetch_assoc()['ExpenseID'];
            // Extract the sequential number (last 2 characters of the ID)
            $lastSeq = intval(substr($lastId, -2));
            $newSeq = $lastSeq + 1;
            $expenseId = 'EXP' . $termSuffix . str_pad($newSeq, 2, '0', STR_PAD_LEFT);
        } else {
            // If no existing IDs for this term, start with 01
            $expenseId = 'EXP' . $termSuffix . '01';
        }

        // Set up expense data
        $date = date('Y-m-d');
        $term = $activeTerm; // Use the active term we retrieved
        $treasurerId = $treasurerData['Treasurer_TreasurerID'];
        $category = 'Loan';
        $method = 'Cash';
        $description = "Loan Payment (LoanID: $loanId)";
        
        // Insert expense record using prepared statement
        $stmt = prepare("INSERT INTO Expenses (ExpenseID, Category, Method, Amount, Date, Term, Description, Image, Treasurer_TreasurerID) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)");
        
        // Make sure all parameters are correctly bound
        $stmt->bind_param("sssdssss", $expenseId, $category, $method, $amount, $date, $term, $description, $treasurerId);
        $stmt->execute();
        
        if($stmt->affected_rows > 0) {
            return $expenseId; // Return the generated expense ID
        } else {
            throw new Exception("Failed to insert expense record");
        }

    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error creating expense record: " . $e->getMessage();
        return false;
    }
}

// Fetch pending loans with member details
function getPendingLoans() {
    // Get active term within the function
    $termQuery = "SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $termResult = search($termQuery);
    
    if ($termResult && $termResult->num_rows > 0) {
        $termData = $termResult->fetch_assoc();
        $activeTerm = $termData['year'];
        
        // Use the active term in the query
        $query = "SELECT l.*, m.Name as MemberName, m.MemberID 
                FROM Loan l 
                JOIN Member m ON l.Member_MemberID = m.MemberID 
                WHERE l.Status = 'pending' AND Term = '$activeTerm'
                ORDER BY l.Issued_Date DESC";
    } else {
        // No active term found, get all pending loans regardless of term
        $query = "SELECT l.*, m.Name as MemberName, m.MemberID 
                FROM Loan l 
                JOIN Member m ON l.Member_MemberID = m.MemberID 
                WHERE l.Status = 'pending'
                ORDER BY l.Issued_Date DESC";
    }
    
    return search($query);
}

function logChange($recordType, $recordId, $memberId, $treasurerId, $oldValues, $newValues, $changeDetails) {
    try {
        $stmt = prepare("INSERT INTO ChangeLog (RecordType, RecordID, MemberID, TreasurerID, OldValues, NewValues, ChangeDetails, Status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Not Read')");
        
        $stmt->bind_param("sssssss", $recordType, $recordId, $memberId, $treasurerId, $oldValues, $newValues, $changeDetails);
        $stmt->execute();
        
        if($stmt->affected_rows <= 0) {
            throw new Exception("Failed to add log record");
        }
        
        return true;
    } catch (Exception $e) {
        // Only log to session, don't disrupt main process
        $_SESSION['error_message'] = $_SESSION['error_message'] . " Warning: Log entry failed: " . $e->getMessage();
        return false;
    }
}

// Handle loan approval/rejection
function processLoanUpdate() {
    if(isset($_POST['update_status'])) {
        $loanId = $_POST['loan_id'];
        $status = $_POST['status'];
        
        try {
            // First get the current loan data before any changes
            $getCurrentLoanStmt = prepare("SELECT l.*, m.Name as MemberName, m.MemberID 
                                          FROM Loan l 
                                          JOIN Member m ON l.Member_MemberID = m.MemberID 
                                          WHERE l.LoanID = ?");
            $getCurrentLoanStmt->bind_param("s", $loanId);
            $getCurrentLoanStmt->execute();
            $currentLoanData = $getCurrentLoanStmt->get_result()->fetch_assoc();
            
            if(!$currentLoanData) {
                throw new Exception("Loan record not found");
            }
            
            // Store old values for logging
            $oldValues = json_encode([
                'Status' => $currentLoanData['Status'],
                'Expenses_ExpenseID' => $currentLoanData['Expenses_ExpenseID'],
                'Remain_Interest' => $currentLoanData['Remain_Interest']
            ]);
            
            // Get treasurer ID for logging
            $treasurerQuery = prepare("SELECT Treasurer_TreasurerID FROM User WHERE UserId = ?");
            $treasurerQuery->bind_param("s", $_SESSION['user_id']);
            $treasurerQuery->execute();
            $treasurerResult = $treasurerQuery->get_result();
            $treasurerData = $treasurerResult->fetch_assoc();
            
            if (!$treasurerData || !$treasurerData['Treasurer_TreasurerID']) {
                throw new Exception("Invalid Treasurer ID");
            }
            
            $treasurerId = $treasurerData['Treasurer_TreasurerID'];
            $memberId = $currentLoanData['MemberID'];

            if($status === 'approved') {
                // Get loan amount using prepared statement
                $stmt = prepare("SELECT Amount FROM Loan WHERE LoanID = ?");
                $stmt->bind_param("s", $loanId);
                $stmt->execute();
                $loanResult = $stmt->get_result();
                
                if(!$loanResult) {
                    throw new Exception("Failed to retrieve loan data");
                }
                
                $loanData = $loanResult->fetch_assoc();
                
                if(!$loanData) {
                    throw new Exception("Loan record not found");
                }

                // Get current interest rate from Static table
                $interestQuery = "SELECT interest FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
                $interestResult = search($interestQuery);
                $interestRate = 3; // Default value if query fails
                if ($interestResult && $interestResult->num_rows > 0) {
                    $interestData = $interestResult->fetch_assoc();
                    $interestRate = $interestData['interest'];
                }

                // Calculate initial interest based on loan amount (for first month only)
                $initialInterest = $loanData['Amount'] * ($interestRate / 100);

                // Begin processing - create expense record
                $expenseId = createExpenseRecord($loanId, $loanData['Amount']);

                if(!$expenseId) {
                    throw new Exception("Failed to create expense record");
                }

                // After successfully inserting expense record, update loan with expense ID and interest
                $updateLoanStmt = prepare("UPDATE Loan SET Expenses_ExpenseID = ?, Status = ?, Remain_Interest = ? WHERE LoanID = ?");
                $updateLoanStmt->bind_param("ssds", $expenseId, $status, $initialInterest, $loanId);
                $updateLoanStmt->execute();

                if($updateLoanStmt->affected_rows > 0) {
                    // Create new values for logging
                    $newValues = json_encode([
                        'Status' => 'approved',
                        'Expenses_ExpenseID' => $expenseId,
                        'Remain_Interest' => $initialInterest
                    ]);
                    
                    $changeDetails = "Loan application approved with initial interest of Rs. " . number_format($initialInterest, 2);
                    
                    // Log the change
                    logChange('Loan', $loanId, $memberId, $treasurerId, $oldValues, $newValues, $changeDetails);
                    
                    $_SESSION['success_message'] = "Loan approved and expense recorded successfully!";
                } else {
                    throw new Exception("Failed to update loan with expense ID");
                }
            } else {
                // Just update status for rejection using prepared statement
                $updateStmt = prepare("UPDATE Loan SET Status = ? WHERE LoanID = ?");
                $updateStmt->bind_param("ss", $status, $loanId);
                $updateStmt->execute();
                
                if($updateStmt->affected_rows > 0) {
                    // Delete associated guarantor records when loan is rejected
                    $deleteGuarantorsStmt = prepare("DELETE FROM Guarantor WHERE Loan_LoanID = ?");
                    $deleteGuarantorsStmt->bind_param("s", $loanId);
                    $deleteGuarantorsStmt->execute();
                    
                    // Get number of deleted guarantors for the message
                    $guarantorsDeleted = $deleteGuarantorsStmt->affected_rows;
                    
                    // Create new values for logging
                    $newValues = json_encode([
                        'Status' => 'rejected',
                        'Expenses_ExpenseID' => null,
                        'Remain_Interest' => 0
                    ]);
                    
                    $changeDetails = "Loan application rejected";
                    if ($guarantorsDeleted > 0) {
                        $changeDetails .= " and " . $guarantorsDeleted . " associated guarantor(s) removed";
                    }
                    
                    // Log the change
                    logChange('Loan', $loanId, $memberId, $treasurerId, $oldValues, $newValues, $changeDetails);
                    
                    $_SESSION['success_message'] = "Loan application has been rejected.";
                    if ($guarantorsDeleted > 0) {
                        $_SESSION['success_message'] .= " Associated guarantor records have been removed.";
                    }
                } else {
                    throw new Exception("Failed to update loan status");
                }
            }
        } catch(Exception $e) {
            $_SESSION['error_message'] = "Error updating loan status: " . $e->getMessage();
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Process any submitted form
processLoanUpdate();

// Get pending loans for display
$result = getPendingLoans();
$hasPendingLoans = ($result && $result->num_rows > 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Loans</title>
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
        .no-records-message {
            text-align: center;
            padding: 2rem;
            font-style: italic;
            color: #6c757d;
            background-color: #f8f9fa;
            border-radius: 8px;
            margin: 2rem 0;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-treasurer.php'; ?>
        <div class="container">
            <div class="header-section" style="border-bottom: 1px solid #ddd; color: #1a237e;">
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

            <?php if ($hasPendingLoans): ?>
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
            <?php else: ?>
                <div class="no-records-message">
                    There are no pending loan applications at this time.
                </div>
            <?php endif; ?>
            
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