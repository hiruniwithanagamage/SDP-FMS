<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if ID parameter exists
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No membership fee ID provided";
    header("Location: membershipFee.php");
    exit();
}

$feeID = $_GET['id'];

/**
 * Function to get membership fee details
 * @param string $feeID The fee ID to retrieve
 * @return array|null The fee details or null if not found
 */
function getMembershipFeeDetails($feeID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            f.FeeID, 
            f.Amount, 
            f.Date, 
            f.Term, 
            f.Type, 
            f.IsPaid,
            f.Member_MemberID,
            m.Name as MemberName
        FROM MembershipFee f
        JOIN Member m ON f.Member_MemberID = m.MemberID
        WHERE f.FeeID = ?
    ");
    
    $stmt->bind_param("s", $feeID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

/**
 * Function to get all members
 * @return mysqli_result The result set with all members
 */
function getAllMembers() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT MemberID, Name FROM Member ORDER BY Name");
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Function to get fee settings from Static table
 * @param int $term The term year to get settings for
 * @return array|null The fee settings or null if not found
 */
function getFeeSettings($term) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT monthly_fee, registration_fee 
        FROM Static 
        WHERE year = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param("i", $term);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Function to generate a unique payment ID
 * @return string The generated payment ID
 */
function generatePaymentID() {
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
 * @return string The generated expense ID
 */
function generateExpenseID() {
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

// Get fee details, members list, and settings
$fee = getMembershipFeeDetails($feeID);
if (!$fee) {
    $_SESSION['error_message'] = "Membership fee not found";
    header("Location: editMFDetails.php");
    exit();
}

$allMembers = getAllMembers();
$feeSettings = getFeeSettings($fee['Term']);
$activeTreasurer = getActiveTreasurer();

// Check if treasurer exists
if (!$activeTreasurer) {
    $_SESSION['error_message'] = "No active treasurer found. Please set an active treasurer first.";
    if (!$isPopup) {
        header("Location: editMFDetails.php");
        exit();
    }
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // Get form data
        $memberID = $_POST['member_id'];
        $amount = floatval($_POST['amount']);
        $date = $_POST['date'];
        $term = intval($_POST['term']);
        $type = $_POST['type'];
        $isPaid = $_POST['is_paid'];
        
        // VALIDATION 1: Check if date is not in the future
        $currentDate = date('Y-m-d');
        if ($date > $currentDate) {
            throw new Exception("Date cannot be in the future");
        }
        
        // VALIDATION 2: Check fee type and amount constraints
        $oldAmount = floatval($fee['Amount']);
        $oldIsPaid = $fee['IsPaid'];
        $oldType = $fee['Type']; // Fix: Use correct case for 'Type'
        
        // VALIDATION 3: Prevent type swapping
        if ($type != $oldType) {
            throw new Exception("Fee type cannot be changed from " . ucfirst($oldType) . " to " . ucfirst($type));
        }
        
        // Get current fee settings for validation
        $currentFeeSettings = getFeeSettings($term);
        if (!$currentFeeSettings) {
            throw new Exception("Fee settings for term $term not found");
        }
        
        // For monthly fees, amount cannot be changed
        if ($type == 'monthly' && $amount != $oldAmount) {
            throw new Exception("Monthly fee amount cannot be changed");
        }
        
        // For registration fees, validate amount changes
        if ($type == 'registration' && $amount != $oldAmount) {
            // Cannot exceed the registration fee of the term
            if ($amount > $currentFeeSettings['registration_fee']) {
                throw new Exception("Amount cannot exceed the registration fee for this term (Rs. " . 
                    number_format($currentFeeSettings['registration_fee'], 2) . ")");
            }
        }
        
        // Prepare the base update statement
        $stmt = $conn->prepare("
            UPDATE MembershipFee SET 
                Member_MemberID = ?,
                Amount = ?,
                Date = ?,
                Term = ?,
                Type = ?,
                IsPaid = ?
            WHERE FeeID = ?
        ");
        
        $stmt->bind_param("sdsisss", 
            $memberID, 
            $amount, 
            $date, 
            $term, 
            $type, 
            $isPaid,
            $feeID
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update membership fee: " . $conn->error);
        }
        
        // Handle amount changes for registration fees
        if ($type == 'registration' && $amount != $oldAmount && $isPaid == 'Yes') {
            if ($amount < $oldAmount) {
                // Case: Amount decreased - add as an expense
                $expenseID = generateExpenseID($term);
                $difference = $oldAmount - $amount;
                
                $expenseStmt = $conn->prepare("
                    INSERT INTO Expenses (
                        ExpenseID, Category, Method, Amount, Date, Term, 
                        Description, Treasurer_TreasurerID
                    ) VALUES (?, 'Adjustment', 'System', ?, ?, ?, 'Edited Membership Fee', ?)
                ");
                
                $expenseStmt->bind_param("sdsss", 
                    $expenseID,
                    $difference,
                    $currentDate,
                    $term,
                    $activeTreasurer
                );
                
                if (!$expenseStmt->execute()) {
                    throw new Exception("Failed to create expense record: " . $conn->error);
                }
                
            } else if ($amount > $oldAmount) {
                // Case: Amount increased - add as a payment
                $paymentID = generatePaymentID($term);
                $difference = $amount - $oldAmount;
                
                // Debug log
                error_log("Creating payment record with ID: $paymentID, Amount: $difference");
                
                $paymentStmt = $conn->prepare("
                    INSERT INTO Payment (
                        PaymentID, Payment_Type, Method, Amount, Date, Term,
                        Member_MemberID, status
                    ) VALUES (?, 'Membership Fee', 'cash', ?, ?, ?, ?, 'cash')
                ");
                
                $paymentStmt->bind_param("sdsss", 
                    $paymentID,
                    $difference,
                    $currentDate,
                    $term,
                    $memberID
                );
                
                if (!$paymentStmt->execute()) {
                    throw new Exception("Failed to create payment record: " . $conn->error);
                }
                
                // Add entry to MembershipFeePayment junction table
                $junctionStmt = $conn->prepare("
                    INSERT INTO MembershipFeePayment (FeeID, PaymentID, Details)
                    VALUES (?, ?, 'Amount adjustment')
                ");
                
                $junctionStmt->bind_param("ss", 
                    $feeID,
                    $paymentID
                );
                
                if (!$junctionStmt->execute()) {
                    throw new Exception("Failed to create fee-payment relationship: " . $conn->error);
                }
            }
        }
        
        // Handle payment status changes
        if ($isPaid != $oldIsPaid) {
            if ($isPaid == 'Yes' && $oldIsPaid == 'No') {
                // Case: Changed from unpaid to paid - add as payment
                $paymentID = generatePaymentID($term);
                
                // Debug log
                error_log("Creating payment record for status change with ID: $paymentID, Amount: $amount");
                
                $paymentStmt = $conn->prepare("
                    INSERT INTO Payment (
                        PaymentID, Payment_Type, Method, Amount, Date, Term,
                        Member_MemberID, status
                    ) VALUES (?, 'Membership Fee', 'cash', ?, ?, ?, ?, 'cash')
                ");
                
                $paymentStmt->bind_param("sdsss", 
                    $paymentID,
                    $amount,
                    $currentDate,
                    $term,
                    $memberID
                );
                
                if (!$paymentStmt->execute()) {
                    throw new Exception("Failed to create payment record: " . $conn->error);
                }
                
                // Add entry to MembershipFeePayment junction table
                $junctionStmt = $conn->prepare("
                    INSERT INTO MembershipFeePayment (FeeID, PaymentID, Details)
                    VALUES (?, ?, 'Status changed to Paid')
                ");
                
                $junctionStmt->bind_param("ss", 
                    $feeID,
                    $paymentID
                );
                
                if (!$junctionStmt->execute()) {
                    throw new Exception("Failed to create fee-payment relationship: " . $conn->error);
                }
                
            } else if ($isPaid == 'No' && $oldIsPaid == 'Yes') {
                // Case: Changed from paid to unpaid - add as expense
                $expenseID = generateExpenseID($term);
                
                $expenseStmt = $conn->prepare("
                    INSERT INTO Expenses (
                        ExpenseID, Category, Method, Amount, Date, Term, 
                        Description, Treasurer_TreasurerID
                    ) VALUES (?, 'Adjustment', 'System', ?, ?, ?, 'Edited Membership Fee', ?)
                ");
                
                $expenseStmt->bind_param("sdsss", 
                    $expenseID,
                    $amount,
                    $currentDate,
                    $term,
                    $activeTreasurer
                );
                
                if (!$expenseStmt->execute()) {
                    throw new Exception("Failed to create expense record: " . $conn->error);
                }
                
                // Find and void any related payments in the junction table
                $deleteJunctionStmt = $conn->prepare("
                    DELETE FROM MembershipFeePayment WHERE FeeID = ?
                ");
                
                $deleteJunctionStmt->bind_param("s", $feeID);
                if (!$deleteJunctionStmt->execute()) {
                    throw new Exception("Failed to update payment relationships: " . $conn->error);
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success_message'] = "Membership fee #$feeID successfully updated";
        
        // Handle redirection based on popup mode
        if (!$isPopup) {
            header("Location: editMFDetails.php?year=" . $term);
            exit();
        }
        // If it's popup mode, we'll continue rendering the page with a success message
        
    } catch (Exception $e) {
        // Rollback on error
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        $_SESSION['error_message'] = "Error updating membership fee: " . $e->getMessage();
        error_log("Fee Update Error: " . $e->getMessage());
    }
}

// Membership fee types
$feeTypes = [
    'monthly' => 'Monthly Fee',
    'registration' => 'Registration Fee'
];

// Payment status options
$isPaidOptions = [
    'Yes' => 'Paid',
    'No' => 'Unpaid'
];

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Membership Fee</title>
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
            .fee-info {
                background-color: #f9f9f9;
                padding: 12px;
                border-radius: 5px;
                margin-bottom: 15px;
                font-size: 14px;
            }
            .fee-info-title {
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
            .status-paid {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-unpaid {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
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
        <title>Edit Membership Fee</title>
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
            .fee-info {
                background-color: #f9f9f9;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
            }
            .fee-info-title {
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
            .status-paid {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-unpaid {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
        </style>
    </head>
    <body>
        <div class="main-container">
            <?php include '../../templates/navbar-treasurer.php'; ?>
            <div class="container">
                <div class="header-card">
                    <h1>Edit Membership Fee</h1>
                    <a href="editMFDetails.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Fees
                    </a>
                </div>
<?php endif; ?>

            <div class="form-container">
                <h2 class="form-title">Edit Membership Fee #<?php echo htmlspecialchars($feeID); ?></h2>
                
                <div class="fee-info">
                    <div class="fee-info-title">Current Fee Information</div>
                    <p>Member ID: <?php echo htmlspecialchars($fee['Member_MemberID']); ?></p>
                    <p>Member Name: <?php echo htmlspecialchars($fee['MemberName']); ?></p>
                    <p>Type: <?php echo ucfirst($fee['Type']); ?></p>
                    <p>Payment Status: 
                        <span class="status-badge status-<?php echo strtolower($fee['IsPaid']); ?>">
                            <?php echo ($fee['IsPaid'] == 'Yes') ? 'Paid' : 'Unpaid'; ?>
                        </span>
                    </p>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="fee_id">Fee ID</label>
                            <input type="text" id="fee_id" class="form-control" value="<?php echo htmlspecialchars($feeID); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="member_id">Member</label>
                            <select id="member_id" name="member_id" class="form-control" required>
                                <?php while($member = $allMembers->fetch_assoc()): ?>
                                    <option value="<?php echo $member['MemberID']; ?>" <?php echo ($member['MemberID'] == $fee['Member_MemberID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['MemberID'] . ' - ' . $member['Name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Amount (Rs.)</label>
                            <input type="number" id="amount" name="amount" class="form-control" 
                                   value="<?php echo htmlspecialchars($fee['Amount']); ?>" 
                                   min="0" step="0.01" required
                                   <?php echo ($fee['Type'] == 'monthly') ? 'readonly' : ''; ?>>
                            
                            <?php if ($fee['Type'] == 'registration'): ?>
                                <small>Maximum registration fee: Rs. <?php echo number_format($feeSettings['registration_fee'], 2); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="type">Fee Type</label>
                            <!-- Make the type field disabled to prevent switching -->
                            <select id="type" name="type" class="form-control" required readonly disabled>
                                <?php foreach($feeTypes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $fee['Type']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <!-- Add a hidden field to ensure the type value is submitted -->
                            <input type="hidden" name="type" value="<?php echo htmlspecialchars($fee['Type']); ?>">
                            <small>Fee type cannot be changed after creation</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="date">Date</label>
                            <input type="date" id="date" name="date" class="form-control" 
                                   value="<?php echo date('Y-m-d', strtotime($fee['Date'])); ?>" 
                                   max="<?php echo date('Y-m-d'); ?>" required>
                            <small>Date cannot be in the future</small>
                        </div>
                        <div class="form-group">
                            <label for="term">Term</label>
                            <input type="number" id="term" name="term" class="form-control" 
                                   value="<?php echo htmlspecialchars($fee['Term']); ?>" min="2000" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="is_paid">Payment Status</label>
                            <select id="is_paid" name="is_paid" class="form-control" required>
                                <?php foreach($isPaidOptions as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $fee['IsPaid']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($fee['Type'] == 'monthly'): ?>
                        <div class="form-group">
                            <label for="monthly_fee">Monthly Fee Rate (Rs.)</label>
                            <input type="text" id="monthly_fee" class="form-control" 
                                   value="<?php echo $feeSettings['monthly_fee']; ?>" disabled>
                            <small>Current monthly fee as per system settings</small>
                        </div>
                        <?php else: ?>
                        <div class="form-group">
                            <label for="registration_fee">Max Registration Fee (Rs.)</label>
                            <input type="text" id="registration_fee" class="form-control" 
                                   value="<?php echo $feeSettings['registration_fee']; ?>" disabled>
                            <small>Maximum registration fee as per system settings</small>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="btn-container">
                        <?php if ($isPopup): ?>
                            <button type="button" class="btn btn-secondary" onclick="window.parent.closeEditModal()">Cancel</button>
                        <?php else: ?>
                            <a href="editMFDetails.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Update Fee</button>
                    </div>
                </form>
            </div>

<?php if ($isPopup): ?>
    </div>
    
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_SESSION['error_message'])): ?>
<script>
    // If form was submitted successfully in popup mode, pass message to parent
    window.parent.showAlert('success', 'Membership fee #<?php echo $feeID; ?> successfully updated');
    setTimeout(function() {
        window.parent.updateFilters(); // Refresh the parent page to show updated data
        window.parent.closeEditModal();
    }); // To delay the refresh and modal close to ensure alert is visible -> ,1000
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
    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('amount').value);
        const feeType = document.getElementById('type').value || document.querySelector('input[name="type"]').value;
        const dateField = document.getElementById('date').value;
        const currentDate = new Date().toISOString().split('T')[0];
        
        // Validate amount
        if (isNaN(amount) || amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid amount greater than zero.');
            return;
        }
        
        // Validate date is not in the future
        if (dateField > currentDate) {
            e.preventDefault();
            alert('Date cannot be in the future.');
            return;
        }
        
        // If fee type is monthly, validate that amount matches the preset value
        if (feeType === 'monthly') {
            const expectedMonthlyFee = <?php echo $feeSettings['monthly_fee']; ?>;
            if (amount !== expectedMonthlyFee) {
                e.preventDefault();
                alert('Monthly fee amount cannot be changed from the system setting of Rs. ' + 
                      expectedMonthlyFee.toFixed(2));
                return;
            }
        }
        
        // If fee type is registration, validate amount doesn't exceed max
        if (feeType === 'registration') {
            const maxRegistrationFee = <?php echo $feeSettings['registration_fee']; ?>;
            if (amount > maxRegistrationFee) {
                e.preventDefault();
                alert('Registration fee cannot exceed Rs. ' + maxRegistrationFee.toFixed(2));
                return;
            }
        }
    });