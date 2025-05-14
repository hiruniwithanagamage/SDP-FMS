<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if ID parameter exists
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No fine ID provided";
    header("Location: fine.php");
    exit();
}

$fineID = $_GET['id'];

// Function to get fine details
function getFineDetails($fineID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            f.FineID, f.Amount, f.Date, f.Description, f.IsPaid, f.Term,
            f.Member_MemberID, f.Payment_PaymentID,
            m.Name as MemberName
        FROM Fine f
        JOIN Member m ON f.Member_MemberID = m.MemberID
        WHERE f.FineID = ?
    ");
    
    $stmt->bind_param("s", $fineID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Function to get all members using prepared statement
function getAllMembers() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT MemberID, Name FROM Member ORDER BY Name");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result;
}

// Function to get fine settings and current active term
function getFineSettings() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT late_fine, absent_fine, rules_violation_fine, year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Function to generate a unique payment ID
 * @param string $term The term year for the payment
 * @return string The generated payment ID
 */
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

/**
 * Function to generate a unique expense ID
 * @param string $term The term year for the expense
 * @return string The generated expense ID
 */
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

/**
 * Function to get active treasurer ID
 * @return string|null The active treasurer ID or null if not found
 */
function getActiveTreasurer() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT TreasurerID FROM Treasurer WHERE isActive = 1 LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        return null;
    }
    $row = $result->fetch_assoc();
    return $row['TreasurerID'];
}

// Get fine details
$fine = getFineDetails($fineID);
if (!$fine) {
    $_SESSION['error_message'] = "Fine not found";
    header("Location: fine.php");
    exit();
}

// Get all members for the dropdown
$allMembers = getAllMembers();
$fineSettings = getFineSettings();
$currentActiveTerm = $fineSettings['year'];
$activeTreasurer = getActiveTreasurer(); 

// Check if treasurer exists
if (!$activeTreasurer) {
    $_SESSION['error_message'] = "No active treasurer found. Please set an active treasurer first.";
    if (!$isPopup) {
        header("Location: fine.php");
        exit();
    }
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $memberID = $_POST['member_id'];
    $amount = $_POST['amount'];
    $date = $_POST['date'];
    $description = $_POST['description'];
    $isPaid = $_POST['status'];
    $term = $fine['Term']; // Keep the original term value
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // VALIDATION 1: If fine is paid, member cannot be changed
        if ($fine['IsPaid'] === 'Yes' && $memberID !== $fine['Member_MemberID']) {
            throw new Exception("Member cannot be changed for a paid fine");
        }
        
        // VALIDATION 2: If fine is paid, type cannot be changed
        if ($fine['IsPaid'] === 'Yes' && $description !== $fine['Description']) {
            throw new Exception("Fine type cannot be changed for a paid fine");
        }
        
        // VALIDATION 3: Check if date is not in the future
        $currentDate = date('Y-m-d');
        if ($date > $currentDate) {
            throw new Exception("Fine date cannot be in the future");
        }
        
        // VALIDATION 4: Amount should match the fine type
        $fineAmount = 0;
        switch ($description) {
            case 'late':
                $fineAmount = $fineSettings['late_fine'];
                break;
            case 'absent':
                $fineAmount = $fineSettings['absent_fine'];
                break;
            case 'violation':
                $fineAmount = $fineSettings['rules_violation_fine'];
                break;
        }
        
        if (floatval($amount) !== floatval($fineAmount)) {
            throw new Exception("Amount must match the fine type. The amount for " . ucfirst($description) . " Fine is Rs. " . number_format($fineAmount, 2));
        }
        
        // Get old status for comparison
        $oldStatus = $fine['IsPaid'];
        
        // VALIDATION 5: Handle status changes (paid/unpaid)
        if ($oldStatus !== $isPaid) {
            // Case 1: Changing from unpaid to paid
            if ($oldStatus === 'No' && $isPaid === 'Yes') {
                // Generate a payment ID
                $paymentID = generatePaymentID($term);
                
                // Create payment record
                $paymentStmt = $conn->prepare("
                    INSERT INTO Payment (
                        PaymentID, Payment_Type, Method, Amount, Date, Term,
                        Member_MemberID, status, Notes
                    ) VALUES (?, 'Fine', 'system', ?, ?, ?, ?, 'transfer', ?)
                ");
                
                $notes = "Payment for Fine #$fineID - " . ucfirst($description) . " Fine";
                
                $paymentStmt->bind_param("sdssss", 
                    $paymentID,
                    $amount,
                    $currentDate,
                    $term,
                    $memberID,
                    $notes
                );
                
                if (!$paymentStmt->execute()) {
                    throw new Exception("Failed to create payment record: " . $conn->error);
                }
                
                // Create entry in FinePayment junction table
                $junctionStmt = $conn->prepare("
                    INSERT INTO FinePayment (FineID, PaymentID)
                    VALUES (?, ?)
                ");
                
                $junctionStmt->bind_param("ss", 
                    $fineID,
                    $paymentID
                );
                
                if (!$junctionStmt->execute()) {
                    throw new Exception("Failed to create fine-payment relationship: " . $conn->error);
                }
                
                // Update the fine with the payment ID
                $updatePaymentIDStmt = $conn->prepare("
                    UPDATE Fine SET Payment_PaymentID = ? WHERE FineID = ?
                ");
                
                $updatePaymentIDStmt->bind_param("ss", 
                    $paymentID,
                    $fineID
                );
                
                if (!$updatePaymentIDStmt->execute()) {
                    throw new Exception("Failed to update fine with payment ID: " . $conn->error);
                }
            }
            
            // Case 2: Changing from paid to unpaid
            if ($oldStatus === 'Yes' && $isPaid === 'No') {
                // Check if payment ID exists
                if (!empty($fine['Payment_PaymentID'])) {
                    // Create expense record for the reversal
                    $expenseID = generateExpenseID($term);
                    $expenseDescription = "Edited Fine - Reverting paid fine #$fineID to unpaid"; // Changed variable name
                    
                    $expenseStmt = $conn->prepare("
                        INSERT INTO Expenses (
                            ExpenseID, Category, Method, Amount, Date, Term, 
                            Description, Treasurer_TreasurerID
                        ) VALUES (?, 'Adjustment', 'System', ?, ?, ?, ?, ?)
                    ");
                    
                    $expenseStmt->bind_param("sdssss", 
                        $expenseID,
                        $amount,
                        $currentDate,
                        $term,
                        $expenseDescription, // Use the renamed variable
                        $activeTreasurer
                    );
                    
                    if (!$expenseStmt->execute()) {
                        throw new Exception("Failed to create expense record: " . $conn->error);
                    }
                    
                    // Remove the payment ID from the fine
                    $removePaymentStmt = $conn->prepare("
                        UPDATE Fine SET Payment_PaymentID = NULL WHERE FineID = ?
                    ");
                    
                    $removePaymentStmt->bind_param("s", $fineID);
                    
                    if (!$removePaymentStmt->execute()) {
                        throw new Exception("Failed to remove payment reference from fine: " . $conn->error);
                    }
                    
                    // Delete from FinePayment junction table
                    $deleteJunctionStmt = $conn->prepare("
                        DELETE FROM FinePayment WHERE FineID = ? AND PaymentID = ?
                    ");
                    
                    $deleteJunctionStmt->bind_param("ss", 
                        $fineID,
                        $fine['Payment_PaymentID']
                    );
                    
                    if (!$deleteJunctionStmt->execute()) {
                        throw new Exception("Failed to remove fine-payment relationship: " . $conn->error);
                    }
                    
                    // Delete the payment record
                    $deletePaymentStmt = $conn->prepare("
                        DELETE FROM Payment WHERE PaymentID = ?
                    ");
                    
                    $deletePaymentStmt->bind_param("s", $fine['Payment_PaymentID']);
                    
                    if (!$deletePaymentStmt->execute()) {
                        throw new Exception("Failed to delete payment record: " . $conn->error);
                    }
                }
            }
        }
        
        // Update fine
        $stmt = $conn->prepare("
            UPDATE Fine SET 
                Member_MemberID = ?,
                Amount = ?,
                Date = ?,
                Description = ?,
                IsPaid = ?
            WHERE FineID = ?
        ");
        
        $stmt->bind_param("sdssss", 
            $memberID, 
            $amount, 
            $date, 
            $description, 
            $isPaid,
            $fineID
        );
        
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success_message'] = "Fine #$fineID successfully updated";
        
        // Handle redirection based on popup mode after ALL database operations are complete
        if (!$isPopup) {
            header("Location: fine.php");
            exit();
        }
        // If it's popup mode, we'll continue rendering the page with a success message
        // and add JavaScript to refresh the parent later
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error updating fine: " . $e->getMessage();
    }
}

// Fine status options
$fineStatus = [
    'No' => 'Unpaid',
    'Yes' => 'Paid'
];

// Fine type options (from ENUM in database)
$fineTypes = [
    'late' => 'Late Fine',
    'absent' => 'Absent Fine',
    'violation' => 'Rule Violation'
];

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Fine</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
        <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
        <link rel="stylesheet" href="../../../assets/css/alert.css">
        <style>
            body { 
                padding: 0; 
                margin: 0; 
                background: white; 
                font-family: Arial, sans-serif;
            }
            .container { 
                padding: 10px; 
            }
            .header-card { 
                display: none; 
            }
            .main-container { 
                padding: 0; 
            }
            .form-container {
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 20px;
                width: 100%;
                margin: 10px auto;
            }
            .form-title {
                color: #1e3c72;
                margin-bottom: 20px;
                text-align: center;
                font-size: 1.5rem;
            }
            .form-group {
                margin-bottom: 15px;
            }
            .form-group label {
                display: block;
                margin-bottom: 6px;
                font-weight: 600;
                color: #333;
            }
            .form-control {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
                transition: border-color 0.3s;
                box-sizing: border-box;
            }
            .form-control:disabled {
                background-color: #f5f5f5;
                cursor: not-allowed;
            }
            .form-control:focus {
                border-color: #1e3c72;
                outline: none;
                box-shadow: 0 0 0 2px rgba(30, 60, 114, 0.2);
            }
            .form-row {
                display: flex;
                gap: 15px;
            }
            .form-row .form-group {
                flex: 1;
            }
            .btn-container {
                display: flex;
                justify-content: flex-end;
                margin-top: 20px;
            }
            .btn {
                min-width: 120px;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                font-size: 14px;
                cursor: pointer;
                transition: background-color 0.3s;
            }
            .btn-primary {
                background-color: #1e3c72;
                color: white;
                height: 40px;
            }
            .btn-primary:hover {
                background-color: #16305c;
            }
            .btn-secondary {
                background-color: #e0e0e0;
                color: #333;
                margin-right: 30px;
                text-align: center;
                font-weight: bold;
                display: block;
            }
            .btn-secondary:hover {
                background-color: #5a6268;
            }
            .member-info {
                background-color: #f9f9f9;
                padding: 12px;
                border-radius: 5px;
                margin-bottom: 15px;
                font-size: 14px;
            }
            .member-info-title {
                font-weight: 600;
                margin-bottom: 8px;
                color: #1e3c72;
            }
            .alert {
                padding: 10px 15px;
                margin-bottom: 15px;
                border-radius: 4px;
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
            .status-yes {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-no {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
            .term-info {
                background-color: #d1ecf1;
                color: #0c5460;
                padding: 8px 12px;
                border-radius: 4px;
                margin-bottom: 15px;
                font-size: 14px;
                border: 1px solid #bee5eb;
            }
        </style>
    </head>
    <body>
        <div class="container">
<?php else: ?>
    <!-- Regular header for standalone page -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Fine</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
        <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
        <link rel="stylesheet" href="../../../assets/css/alert.css">
        <script src="../../../assets/js/alertHandler.js"></script>
        <style>
            .form-container {
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 30px;
                max-width: 800px;
                margin: 20px auto;
            }
            .form-title {
                color: #1e3c72;
                margin-bottom: 25px;
                text-align: center;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #333;
            }
            .form-control {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
                transition: border-color 0.3s;
            }
            .form-control:disabled {
                background-color: #f5f5f5;
                cursor: not-allowed;
            }
            .form-control:focus {
                border-color: #1e3c72;
                outline: none;
                box-shadow: 0 0 0 2px rgba(30, 60, 114, 0.2);
            }
            .form-row {
                display: flex;
                gap: 20px;
            }
            .form-row .form-group {
                flex: 1;
            }
            .btn-container {
                display: flex;
                justify-content: space-between;
                margin-top: 30px;
            }
            .btn {
                padding: 12px 24px;
                border: none;
                border-radius: 4px;
                font-size: 16px;
                cursor: pointer;
                transition: background-color 0.3s;
            }
            .btn-primary {
                background-color: #1e3c72;
                color: white;
            }
            .btn-primary:hover {
                background-color: #16305c;
            }
            .btn-secondary {
                background-color: #e0e0e0;
                color: #333;
            }
            .btn-secondary:hover {
                background-color: #5a6268;
            }
            .member-info {
                background-color: #f9f9f9;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
            }
            .member-info-title {
                font-weight: 600;
                margin-bottom: 10px;
                color: #1e3c72;
            }
            .status-badge {
                display: inline-block;
                padding: 5px 10px;
                border-radius: 15px;
                font-size: 0.8rem;
                font-weight: bold;
            }
            .status-yes {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-no {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
            .term-info {
                background-color: #d1ecf1;
                color: #0c5460;
                padding: 10px 15px;
                border-radius: 4px;
                margin-bottom: 20px;
                font-size: 14px;
                border: 1px solid #bee5eb;
            }
        </style>
    </head>
    <body>
        <div class="main-container">
            <?php include '../../templates/navbar-treasurer.php'; ?>
            <div class="container">
                <div class="header-card">
                    <h1>Edit Fine</h1>
                    <a href="fine.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Fines
                    </a>
                </div>
<?php endif; ?>

            <!-- Generate alerts -->
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

            <div class="form-container">
                <h2 class="form-title">Edit Fine #<?php echo htmlspecialchars($fineID); ?></h2>
                
                <div class="member-info">
                    <div class="member-info-title">Current Fine Information</div>
                    <p>Member ID: <?php echo htmlspecialchars($fine['Member_MemberID']); ?></p>
                    <p>Member Name: <?php echo htmlspecialchars($fine['MemberName']); ?></p>
                    <p>Status: <span class="status-badge status-<?php echo strtolower($fine['IsPaid']); ?>"><?php echo $fine['IsPaid'] == 'Yes' ? 'Paid' : 'Unpaid'; ?></span></p>
                    <p>Term: <?php echo htmlspecialchars($fine['Term']); ?></p>
                </div>
                
                <div class="term-info">
                    <strong>Note:</strong> Fine amounts are based on current active term (<?php echo $currentActiveTerm; ?>) settings.
                </div>

                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="fine_id">Fine ID</label>
                            <input type="text" id="fine_id" class="form-control" value="<?php echo htmlspecialchars($fineID); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="member_id">Member</label>
                            <select id="member_id" name="member_id" class="form-control" required <?php echo ($fine['IsPaid'] == 'Yes') ? 'disabled' : ''; ?>>
                                <?php 
                                $allMembers->data_seek(0); // Reset result pointer
                                while($member = $allMembers->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $member['MemberID']; ?>" <?php echo ($member['MemberID'] == $fine['Member_MemberID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['MemberID'] . ' - ' . $member['Name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <?php if ($fine['IsPaid'] == 'Yes'): ?>
                                <!-- Hidden field to ensure the member ID is submitted when the select is disabled -->
                                <input type="hidden" name="member_id" value="<?php echo htmlspecialchars($fine['Member_MemberID']); ?>">
                                <small>Member cannot be changed for a paid fine</small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Fine Amount (Rs.)</label>
                            <input type="number" id="amount" name="amount" class="form-control" value="<?php echo htmlspecialchars($fine['Amount']); ?>" min="0" step="0.01" required readonly>
                            <small>Amount is automatically set based on fine type</small>
                        </div>
                        <div class="form-group">
                            <label for="date">Date</label>
                            <input type="date" id="date" name="date" class="form-control" value="<?php echo date('Y-m-d', strtotime($fine['Date'])); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                            <small>Date cannot be in the future</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control" required>
                                <?php foreach($fineStatus as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $fine['IsPaid']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="description">Fine Type</label>
                            <select id="description" name="description" class="form-control" onchange="updateAmount()" <?php echo ($fine['IsPaid'] == 'Yes') ? 'disabled' : ''; ?>>
                                <?php foreach($fineTypes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $fine['Description']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($fine['IsPaid'] == 'Yes'): ?>
                                <!-- Hidden field to ensure the description is submitted when the select is disabled -->
                                <input type="hidden" name="description" value="<?php echo htmlspecialchars($fine['Description']); ?>">
                                <small>Fine type cannot be changed for a paid fine</small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="btn-container">
                        <?php if ($isPopup): ?>
                            <button type="button" class="btn btn-secondary" onclick="window.parent.closeEditModal()">Cancel</button>
                        <?php else: ?>
                            <a href="fine.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Update Fine</button>
                    </div>
                </form>
            </div>

<?php if ($isPopup): ?>
    </div>
    
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_SESSION['error_message'])): ?>
<script>
    // If form was submitted successfully in popup mode, pass message to parent
    window.parent.showAlert('success', 'Fine #<?php echo $fineID; ?> successfully updated');
    window.parent.closeEditModal();
    window.parent.updateFilters(); // Refresh the parent page to show updated data
</script>
<?php elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['error_message'])): ?>
<script>
    // If form had errors, pass error message to parent
    window.parent.showAlert('error', '<?php echo addslashes($_SESSION['error_message']); ?>');
</script>
<?php unset($_SESSION['error_message']); ?>
<?php endif; ?>
    
</body>
</html>
<?php else: ?>
        </div>
        <?php include '../../templates/footer.php'; ?>
    </div>
</body>
</html>
<?php endif; ?>

<script>
    // Update amount based on fine type selection using current active term settings
    function updateAmount() {
        const fineType = document.getElementById('description').value;
        const lateFine = <?php echo (float)$fineSettings['late_fine']; ?>;
        const absentFine = <?php echo (float)$fineSettings['absent_fine']; ?>;
        const violationFine = <?php echo (float)$fineSettings['rules_violation_fine']; ?>;
        
        switch(fineType) {
            case 'late':
                document.getElementById('amount').value = lateFine;
                break;
            case 'absent':
                document.getElementById('amount').value = absentFine;
                break;
            case 'violation':
                document.getElementById('amount').value = violationFine;
                break;
        }
    }

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const date = document.getElementById('date').value;
        const currentDate = new Date().toISOString().split('T')[0];
        
        // Validate date is not in the future
        if (date > currentDate) {
            e.preventDefault();
            alert('Fine date cannot be in the future.');
            return;
        }
        
        // Check if fine type and amount match
        const fineType = document.getElementById('description').value;
        const amount = parseFloat(document.getElementById('amount').value);
        let correctAmount = 0;
        
        switch(fineType) {
            case 'late':
                correctAmount = <?php echo (float)$fineSettings['late_fine']; ?>;
                break;
            case 'absent':
                correctAmount = <?php echo (float)$fineSettings['absent_fine']; ?>;
                break;
            case 'violation':
                correctAmount = <?php echo (float)$fineSettings['rules_violation_fine']; ?>;
                break;
        }
        
        if (amount !== correctAmount) {
            e.preventDefault();
            alert('Fine amount must match the selected fine type. The correct amount for ' + 
                  fineType.charAt(0).toUpperCase() + fineType.slice(1) + ' Fine is Rs. ' + 
                  correctAmount.toFixed(2));
        }
    });
    
    // On page load, ensure the amount matches the fine type and current settings
    document.addEventListener('DOMContentLoaded', function() {
        updateAmount();
    });
</script>