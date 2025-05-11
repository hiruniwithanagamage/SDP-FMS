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

// Function to get membership fee details
function getFeeDetails($feeID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            mf.FeeID, 
            mf.Amount, 
            mf.Date, 
            mf.Term,
            mf.Type,
            mf.IsPaid,
            mf.Member_MemberID,
            m.Name as MemberName
        FROM MembershipFee mf
        JOIN Member m ON mf.Member_MemberID = m.MemberID
        WHERE mf.FeeID = ?
    ");
    
    $stmt->bind_param("s", $feeID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Function to get payment associated with the membership fee
function getAssociatedPayment($feeID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            p.PaymentID,
            p.Amount,
            p.Date,
            p.Term,
            mfp.Details
        FROM Payment p
        JOIN MembershipFeePayment mfp ON p.PaymentID = mfp.PaymentID
        WHERE mfp.FeeID = ?
    ");
    
    $stmt->bind_param("s", $feeID);
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

// Function to get fee settings using prepared statement
function getFeeSettings() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT monthly_fee, registration_fee FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get current term/year using prepared statement
function getCurrentTerm() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get fee details
$fee = getFeeDetails($feeID);
if (!$fee) {
    $_SESSION['error_message'] = "Membership fee not found";
    header("Location: membershipFee.php");
    exit();
}

// Get associated payment (if any)
$associatedPayment = getAssociatedPayment($feeID);

// Get all members for the dropdown
$allMembers = getAllMembers();
$currentTerm = getCurrentTerm();
$feeSettings = getFeeSettings();

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $memberID = $_POST['member_id'];
    $amount = $_POST['amount'];
    $type = $_POST['type'];
    $date = $_POST['date'];
    $term = $_POST['term'];
    $isPaid = $_POST['is_paid'];
    $details = $_POST['payment_details'] ?? 'Updated membership fee';
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // Update membership fee
        $stmt = $conn->prepare("
            UPDATE MembershipFee SET 
                Member_MemberID = ?,
                Amount = ?,
                Type = ?,
                Date = ?,
                Term = ?,
                IsPaid = ?
            WHERE FeeID = ?
        ");
        
        $stmt->bind_param("sssssss", 
            $memberID, 
            $amount, 
            $type, 
            $date, 
            $term, 
            $isPaid,
            $feeID
        );
        
        $stmt->execute();
        
        // Check if there's an associated payment to update
        if ($associatedPayment) {
            // Update the payment record
            $paymentStmt = $conn->prepare("
                UPDATE Payment SET 
                    Amount = ?,
                    Date = ?,
                    Term = ?,
                    Member_MemberID = ?
                WHERE PaymentID = ?
            ");
            
            $paymentStmt->bind_param("sssss", 
                $amount, 
                $date, 
                $term, 
                $memberID,
                $associatedPayment['PaymentID']
            );
            
            $paymentStmt->execute();
            
            // Update the MembershipFeePayment details
            $membershipFeePaymentStmt = $conn->prepare("
                UPDATE MembershipFeePayment SET 
                    Details = ?
                WHERE FeeID = ? AND PaymentID = ?
            ");
            
            $membershipFeePaymentStmt->bind_param("sss", 
                $details, 
                $feeID,
                $associatedPayment['PaymentID']
            );
            
            $membershipFeePaymentStmt->execute();
        } 
        // If status changed to 'Paid' and no payment record exists, create one
        else if ($isPaid === 'Yes' && !$associatedPayment) {
            // Generate new PaymentID (you might have a function for this)
            $paymentID = uniqid('PAY_');
            
            // Create a new payment record
            $paymentStmt = $conn->prepare("
                INSERT INTO Payment (
                    PaymentID, 
                    Payment_Type, 
                    Method, 
                    Amount, 
                    Date, 
                    Term, 
                    Member_MemberID,
                    status
                ) VALUES (
                    ?, 
                    'membership_fee', 
                    'cash', 
                    ?, 
                    ?, 
                    ?, 
                    ?,
                    'cash'
                )
            ");
            
            $paymentStmt->bind_param("sssss", 
                $paymentID, 
                $amount, 
                $date, 
                $term, 
                $memberID
            );
            
            $paymentStmt->execute();
            
            // Create the link in MembershipFeePayment with details
            $membershipFeePaymentStmt = $conn->prepare("
                INSERT INTO MembershipFeePayment (
                    FeeID, 
                    PaymentID, 
                    Details
                ) VALUES (
                    ?, 
                    ?, 
                    ?
                )
            ");
            
            $membershipFeePaymentStmt->bind_param("sss", 
                $feeID, 
                $paymentID, 
                $details
            );
            
            $membershipFeePaymentStmt->execute();
        }
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success_message'] = "Membership Fee #$feeID successfully updated";
        
        // Handle redirection based on popup mode after ALL database operations are complete
        if (!$isPopup) {
            header("Location: membershipFee.php");
            exit();
        }
        // If it's popup mode, we'll continue rendering the page with a success message
        // and add JavaScript to refresh the parent later
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error updating membership fee: " . $e->getMessage();
    }
}

// Fee type options
$feeTypes = [
    'registration' => 'Registration',
    'monthly' => 'Monthly'
];

// Fee payment status options
$paymentStatus = [
    'No' => 'Unpaid',
    'Yes' => 'Paid'
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
            .status-paid {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-unpaid {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
            .payment-details {
                display: none;
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
            
            .status-paid {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            
            .status-unpaid {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
            
            .payment-details {
                display: none;
            }
        </style>
    </head>
    <body>
        <div class="main-container">
            <?php include '../../templates/navbar-treasurer.php'; ?>
            <div class="container">
                <div class="header-card">
                    <h1>Edit Membership Fee</h1>
                    <a href="membershipFee.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Membership Fees
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
                <h2 class="form-title">Edit Membership Fee #<?php echo htmlspecialchars($feeID); ?></h2>
                
                <div class="member-info">
                    <div class="member-info-title">Current Fee Information</div>
                    <p>Member ID: <?php echo htmlspecialchars($fee['Member_MemberID']); ?></p>
                    <p>Member Name: <?php echo htmlspecialchars($fee['MemberName']); ?></p>
                    <p>Payment Status: <span class="status-badge status-<?php echo strtolower($fee['IsPaid']) === 'yes' ? 'paid' : 'unpaid'; ?>"><?php echo ($fee['IsPaid'] === 'Yes') ? 'Paid' : 'Unpaid'; ?></span></p>
                    <?php if($associatedPayment): ?>
                    <p>Associated Payment ID: <?php echo htmlspecialchars($associatedPayment['PaymentID']); ?></p>
                    <p>Payment Details: <?php echo htmlspecialchars($associatedPayment['Details'] ?? 'No details available'); ?></p>
                    <?php endif; ?>
                </div>

                <form method="POST" action="">
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
                            <label for="type">Fee Type</label>
                            <select id="type" name="type" class="form-control" required onchange="updateAmount()">
                                <?php foreach($feeTypes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $fee['Type']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="amount">Fee Amount (Rs.)</label>
                            <input type="number" id="amount" name="amount" class="form-control" value="<?php echo htmlspecialchars($fee['Amount']); ?>" min="0" step="0.01" required>
                            <small>Registration Fee: Rs. <?php echo number_format($feeSettings['registration_fee'], 2); ?>, Monthly Fee: Rs. <?php echo number_format($feeSettings['monthly_fee'], 2); ?></small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="date">Date</label>
                            <input type="date" id="date" name="date" class="form-control" value="<?php echo date('Y-m-d', strtotime($fee['Date'])); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="term">Term</label>
                            <input type="number" id="term" name="term" class="form-control" value="<?php echo htmlspecialchars($fee['Term']); ?>" min="1" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="is_paid">Payment Status</label>
                            <select id="is_paid" name="is_paid" class="form-control" required onchange="togglePaymentDetails()">
                                <?php foreach($paymentStatus as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $fee['IsPaid']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group payment-details" id="payment_details_group">
                            <label for="payment_details">Payment Details</label>
                            <input type="text" id="payment_details" name="payment_details" class="form-control" value="<?php echo htmlspecialchars($associatedPayment['Details'] ?? 'Updated membership fee'); ?>" placeholder="Enter payment details">
                        </div>
                    </div>

                    <div class="btn-container">
                        <?php if ($isPopup): ?>
                            <button type="button" class="btn btn-secondary" onclick="window.parent.closeEditModal()">Cancel</button>
                        <?php else: ?>
                            <a href="membershipFee.php" class="btn btn-secondary">Cancel</a>
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
    window.parent.showAlert('success', 'Membership Fee #<?php echo $feeID; ?> successfully updated');
    window.parent.closeEditModal();
    // Don't reload the entire page as it will lose the alert
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
    // Auto-update amount based on fee type
    function updateAmount() {
        const feeType = document.getElementById('type').value;
        const registrationFee = <?php echo $feeSettings['registration_fee']; ?>;
        const monthlyFee = <?php echo $feeSettings['monthly_fee']; ?>;
        
        if (feeType === 'registration') {
            document.getElementById('amount').value = registrationFee.toFixed(2);
        } else if (feeType === 'monthly') {
            document.getElementById('amount').value = monthlyFee.toFixed(2);
        }
    }
    
    // Toggle payment details visibility based on payment status
    function togglePaymentDetails() {
        const isPaid = document.getElementById('is_paid').value;
        const paymentDetailsGroup = document.getElementById('payment_details_group');
        
        if (isPaid === 'Yes') {
            paymentDetailsGroup.style.display = 'block';
        } else {
            paymentDetailsGroup.style.display = 'none';
        }
    }
    
    // Initialize payment details visibility on page load
    document.addEventListener('DOMContentLoaded', function() {
        togglePaymentDetails();
    });

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('amount').value);
        
        if (isNaN(amount) || amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid amount greater than zero.');
        }
        
        const date = new Date(document.getElementById('date').value);
        if (isNaN(date.getTime())) {
            e.preventDefault();
            alert('Please enter a valid date.');
        }
    });
</script>