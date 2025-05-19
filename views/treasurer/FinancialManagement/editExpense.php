<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if ID parameter exists
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No expense ID provided";
    header("Location: trackExpenses.php");
    exit();
}

$expenseID = $_GET['id'];

// Function to get expense details
function getExpenseDetails($expenseID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            e.ExpenseID, 
            e.Category, 
            e.Method, 
            e.Amount, 
            e.Date, 
            e.Term, 
            e.Description, 
            e.Image,
            e.Treasurer_TreasurerID,
            t.Name as TreasurerName
        FROM Expenses e
        JOIN Treasurer t ON e.Treasurer_TreasurerID = t.TreasurerID
        WHERE e.ExpenseID = ?
    ");
    
    $stmt->bind_param("s", $expenseID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Function to get all treasurers using prepared statement
function getAllTreasurers() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT TreasurerID, Name FROM Treasurer WHERE isActive = 1 ORDER BY Name");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result;
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

// Function to get max loan limit from static table
function getMaxLoanLimit() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT max_loan_limit FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['max_loan_limit'] ?? 50000; // Default fallback value if not found
}

// Function to log changes in the ChangeLog table
function logExpenseChanges($expenseID, $oldValues, $newValues, $treasurerID) {
    $conn = getConnection();
    
    // Serialize old and new values for storage
    $oldValuesJson = json_encode($oldValues);
    $newValuesJson = json_encode($newValues);
    
    // Generate change details by comparing old and new values
    $changeDetails = [];
    foreach ($newValues as $key => $value) {
        // Skip Image field as it's a path, not a meaningful value to track
        if ($key === 'Image') continue;
        
        // If the value has changed, add to change details
        if (isset($oldValues[$key]) && $oldValues[$key] != $value) {
            // Format the change details based on field type
            if ($key === 'Amount') {
                $changeDetails[] = "Changed $key from Rs. " . number_format($oldValues[$key], 2) . " to Rs. " . number_format($value, 2);
            } else if ($key === 'Date') {
                $changeDetails[] = "Changed $key from " . date('Y-m-d', strtotime($oldValues[$key])) . " to " . date('Y-m-d', strtotime($value));
            } else {
                $changeDetails[] = "Changed $key from {$oldValues[$key]} to $value";
            }
        }
    }
    
    // If no changes were made, don't log
    if (empty($changeDetails)) {
        return;
    }
    
    $changeDetailsText = implode(", ", $changeDetails);
    
    // Get MemberID associated with the Treasurer
    $memberID = getMemberIDFromTreasurer($treasurerID);
    
    // Insert into ChangeLog table
    $stmt = $conn->prepare("
        INSERT INTO ChangeLog 
        (RecordType, RecordID, TreasurerID, OldValues, NewValues, ChangeDetails, MemberID, Status) 
        VALUES 
        (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $recordType = 'Expense';
    $status = 'Not Read';
    
    // Bind all parameters in the correct order
    $stmt->bind_param(
        "ssssssss", 
        $recordType,
        $expenseID, 
        $treasurerID, 
        $oldValuesJson, 
        $newValuesJson, 
        $changeDetailsText,
        $memberID,
        $status
    );
    
    $stmt->execute();
    
    return $stmt->affected_rows > 0;
}

// Function to get MemberID from Treasurer
function getMemberIDFromTreasurer($treasurerID) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT MemberID FROM Treasurer WHERE TreasurerID = ?");
    $stmt->bind_param("s", $treasurerID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['MemberID'];
    }
    
    // If no MemberID found, return a default or placeholder value
    return "UNKNOWN";
}

// Check if this expense is linked to a Death Welfare
function isLinkedToDeathWelfare($expenseID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT * FROM DeathWelfare 
        WHERE Expense_ExpenseID = ?
    ");
    
    $stmt->bind_param("s", $expenseID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

// Get expense details
$expense = getExpenseDetails($expenseID);
if (!$expense) {
    $_SESSION['error_message'] = "Expense not found";
    header("Location: trackExpenses.php");
    exit();
}

// Check if expense is linked to Death Welfare
$isLinked = isLinkedToDeathWelfare($expenseID);

// Check if category is Adjustment
$isAdjustment = ($expense['Category'] === 'Adjustment');

// Get all treasurers for the dropdown
$allTreasurers = getAllTreasurers();
$currentTerm = getCurrentTerm();
$maxLoanLimit = getMaxLoanLimit();

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $treasurerID = $_POST['treasurer_id'];
    $category = $_POST['category'];
    $amount = $_POST['amount'];
    $method = $_POST['method'];
    $date = $_POST['date'];
    $term = $_POST['term'];
    $description = $_POST['description'];
    
    // SERVER-SIDE VALIDATION
    $validationErrors = [];
    
    // Validate category - prevent editing Adjustment expenses
    if ($isAdjustment) {
        $validationErrors[] = "Adjustment expenses cannot be edited.";
    }
    
    // Validate amount
    if (!is_numeric($amount) || $amount <= 0) {
        $validationErrors[] = "Amount must be a positive number.";
    }
    
    if (floatval($amount) > $maxLoanLimit) {
        $validationErrors[] = "Amount cannot exceed the maximum limit of Rs. " . number_format($maxLoanLimit, 2);
    }
    
    // Validate Death Welfare amount - prevent changing amount for Death Welfare expenses
    if ($isLinked && floatval($amount) != floatval($expense['Amount'])) {
        $validationErrors[] = "Amount cannot be changed for Death Welfare expenses.";
    }
    
    // Validate date - prevent future dates
    $expenseDate = new DateTime($date);
    $expenseDate->setTime(0, 0, 0);

    $today = new DateTime();
    $today->setTime(0, 0, 0);
    if ($expenseDate > $today) {
        $validationErrors[] = "Expense date cannot be in the future.";
    }
    
    // Validate term - prevent changing term
    if (intval($term) !== intval($expense['Term'])) {
        $validationErrors[] = "Term cannot be changed.";
    }
    
    // If validation errors found, set error message and don't proceed
    if (!empty($validationErrors)) {
        $_SESSION['error_message'] = "Validation errors: " . implode(" ", $validationErrors);
        // Redirect back to the same page to show error
        if (!$isPopup) {
            header("Location: editExpense.php?id=" . $expenseID);
            exit();
        }
        // For popup, we'll continue rendering with error message
    } else {
        // Handle file upload if a new receipt is provided
        $imagePath = $expense['Image']; // Default to existing image path
        
        if (!empty($_FILES['receipt']['name'])) {
            // Use the same relative path structure as in add expense
            $uploadDir = "uploads/expenses/";
            
            // Create sanitized category name
            $sanitizedCategory = preg_replace('/[^A-Za-z0-9]/', '', $category);
            
            // Create filename with extension
            $fileExtension = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
            $fileName = $expenseID . '_' . $sanitizedCategory . '_' . date('Ymd') . '.' . $fileExtension;
            
            // Store physical file with correct absolute path
            $absoluteUploadDir = $_SERVER['DOCUMENT_ROOT'] . '/SDP/uploads/expenses/';
            
            // Ensure directory exists
            if (!file_exists($absoluteUploadDir)) {
                mkdir($absoluteUploadDir, 0777, true);
            }
            
            $absoluteTargetPath = $absoluteUploadDir . $fileName;
            
            // Check if file is a valid image or PDF
            $allowedTypes = array('jpg', 'jpeg', 'png', 'pdf');
            if (in_array($fileExtension, $allowedTypes)) {
                // Upload file
                if (move_uploaded_file($_FILES["receipt"]["tmp_name"], $absoluteTargetPath)) {
                    // Store relative path in database
                    $imagePath = $uploadDir . $fileName;
                } else {
                    $_SESSION['error_message'] = "Error uploading file.";
                }
            } else {
                $_SESSION['error_message'] = "Only JPG, JPEG, PNG, and PDF files are allowed.";
            }
        }
        
        try {
            $conn = getConnection();
            
            // Start transaction
            $conn->begin_transaction();
            
            // Save old values for change logging
            $oldValues = [
                'Category' => $expense['Category'],
                'Method' => $expense['Method'],
                'Amount' => $expense['Amount'],
                'Date' => $expense['Date'],
                'Term' => $expense['Term'],
                'Description' => $expense['Description'],
                'Image' => $expense['Image'],
                'Treasurer_TreasurerID' => $expense['Treasurer_TreasurerID']
            ];
            
            // Prepare new values for change logging
            $newValues = [
                'Category' => $category,
                'Method' => $method,
                'Amount' => $amount,
                'Date' => $date,
                'Term' => $term,
                'Description' => $description,
                'Image' => $imagePath,
                'Treasurer_TreasurerID' => $treasurerID
            ];
            
            // Check if expense is linked to Death Welfare
            if ($isLinked) {
                // If linked, can only update description and image
                $stmt = $conn->prepare("
                    UPDATE Expenses SET 
                        Description = ?,
                        Image = ?
                    WHERE ExpenseID = ?
                ");
                
                $stmt->bind_param("sss", 
                    $description, 
                    $imagePath,
                    $expenseID
                );
                
                // Update new values to only reflect what actually changed
                $newValues = [
                    'Category' => $oldValues['Category'],
                    'Method' => $oldValues['Method'],
                    'Amount' => $oldValues['Amount'],
                    'Date' => $oldValues['Date'],
                    'Term' => $oldValues['Term'],
                    'Description' => $description,
                    'Image' => $imagePath,
                    'Treasurer_TreasurerID' => $oldValues['Treasurer_TreasurerID']
                ];
            } else {
                // If not linked, update all fields
                $stmt = $conn->prepare("
                    UPDATE Expenses SET 
                        Category = ?,
                        Method = ?,
                        Amount = ?,
                        Date = ?,
                        Term = ?,
                        Description = ?,
                        Image = ?,
                        Treasurer_TreasurerID = ?
                    WHERE ExpenseID = ?
                ");
                
                $stmt->bind_param("ssdsissss", 
                    $category, 
                    $method, 
                    $amount, 
                    $date, 
                    $term, 
                    $description, 
                    $imagePath,
                    $treasurerID,
                    $expenseID
                );
            }
            
            $stmt->execute();
            
            // Log the changes to ChangeLog table
            logExpenseChanges($expenseID, $oldValues, $newValues, $treasurerID);
            
            // Commit transaction
            $conn->commit();
            
            // Set success message
            $_SESSION['success_message'] = "Expense #$expenseID successfully updated";
            
            // Handle redirection based on popup mode after ALL database operations are complete
            if (!$isPopup) {
                header("Location: trackExpenses.php");
                exit();
            }
            // If it's popup mode, we'll continue rendering the page with a success message
            // and add JavaScript to refresh the parent later
            
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $_SESSION['error_message'] = "Error updating expense: " . $e->getMessage();
        }
    }
}

// Category options - removed Adjustment from selectable options
$categories = [
    'Loan' => 'Loan',
    'Death Welfare' => 'Death Welfare',
    'Administrative' => 'Administrative',
    'Utility' => 'Utility',
    'Maintenance' => 'Maintenance',
    'Event' => 'Event',
    'Other' => 'Other'
];

// Payment method options
$paymentMethods = [
    'Cash' => 'Cash',
    'Online' => 'Online',
    'System' => 'System',
    'Bank Transfer' => 'Bank Transfer'
];

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Expense</title>
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
            .expense-info {
                background-color: #f9f9f9;
                padding: 12px;
                border-radius: 5px;
                margin-bottom: 15px;
                font-size: 14px;
            }
            .expense-info-title {
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
            .receipt-preview {
                max-width: 100px;
                max-height: 100px;
                display: block;
                margin-top: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .form-note {
                font-size: 0.85rem;
                color: #666;
                margin-top: 5px;
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
        <title>Edit Expense</title>
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

            .expense-info {
                background-color: #f9f9f9;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
            }

            .expense-info-title {
                font-weight: 600;
                margin-bottom: 10px;
                color: #1e3c72;
            }
            
            .receipt-preview {
                max-width: 200px;
                max-height: 200px;
                display: block;
                margin-top: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            
            .form-note {
                font-size: 0.9rem;
                color: #666;
                margin-top: 5px;
            }
        </style>
    </head>
    <body>
        <div class="main-container">
            <?php include '../../templates/navbar-treasurer.php'; ?>
            <div class="container">
                <div class="header-card">
                    <h1>Edit Expense</h1>
                    <a href="trackExpenses.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Expenses
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
                <h2 class="form-title">Edit Expense #<?php echo htmlspecialchars($expenseID); ?></h2>
                
                <div class="expense-info">
                    <div class="expense-info-title">Current Expense Information</div>
                    <p>Expense ID: <?php echo htmlspecialchars($expense['ExpenseID']); ?></p>
                    <p>Category: <?php echo htmlspecialchars($expense['Category']); ?></p>
                    <p>Amount: Rs. <?php echo number_format($expense['Amount'], 2); ?></p>
                    <p>Date: <?php echo date('Y-m-d', strtotime($expense['Date'])); ?></p>
                    
                    <?php if ($isLinked): ?>
                    <p style="color: #cc0000; font-weight: bold;">This expense is linked to a Death Welfare record. Some fields are locked to maintain data integrity.</p>
                    <?php endif; ?>
                    
                    <?php if ($isAdjustment): ?>
                    <p style="color: #cc0000; font-weight: bold;">This expense has an Adjustment category and cannot be edited.</p>
                    <?php endif; ?>
                </div>

                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="expense_id">Expense ID</label>
                            <input type="text" id="expense_id" class="form-control" value="<?php echo htmlspecialchars($expenseID); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="treasurer_id">Treasurer</label>
                            <select id="treasurer_id" name="treasurer_id" class="form-control" <?php echo ($isLinked || $isAdjustment) ? 'disabled' : ''; ?> required>
                                <?php while($treasurer = $allTreasurers->fetch_assoc()): ?>
                                    <option value="<?php echo $treasurer['TreasurerID']; ?>" <?php echo ($treasurer['TreasurerID'] == $expense['Treasurer_TreasurerID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($treasurer['TreasurerID'] . ' - ' . $treasurer['Name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <?php if ($isLinked || $isAdjustment): ?>
                            <input type="hidden" name="treasurer_id" value="<?php echo $expense['Treasurer_TreasurerID']; ?>">
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" class="form-control" <?php echo ($isLinked || $isAdjustment) ? 'disabled' : ''; ?> required>
                                <?php
                                // Handle Adjustment category specially
                                if ($expense['Category'] === 'Adjustment') {
                                    // If current expense is Adjustment, show it as an option
                                    echo '<option value="Adjustment" selected>Adjustment</option>';
                                }
                                
                                // Add all regular category options
                                foreach($categories as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $expense['Category']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($isLinked || $isAdjustment): ?>
                            <input type="hidden" name="category" value="<?php echo $expense['Category']; ?>">
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="amount">Amount (Rs.)</label>
                            <input type="number" id="amount" name="amount" class="form-control" value="<?php echo htmlspecialchars($expense['Amount']); ?>" min="0" max="<?php echo $maxLoanLimit; ?>" step="0.01" <?php echo ($isLinked || $isAdjustment) ? 'disabled' : ''; ?> required>
                            <div class="form-note">Maximum amount: Rs. <?php echo number_format($maxLoanLimit, 2); ?></div>
                            <?php if ($isLinked || $isAdjustment): ?>
                            <input type="hidden" name="amount" value="<?php echo $expense['Amount']; ?>">
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="method">Payment Method</label>
                            <select id="method" name="method" class="form-control" <?php echo ($isLinked || $isAdjustment) ? 'disabled' : ''; ?> required>
                                <?php foreach($paymentMethods as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $expense['Method']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($isLinked || $isAdjustment): ?>
                            <input type="hidden" name="method" value="<?php echo $expense['Method']; ?>">
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="date">Date</label>
                            <input type="date" id="date" name="date" class="form-control" value="<?php echo date('Y-m-d', strtotime($expense['Date'])); ?>" <?php echo ($isLinked || $isAdjustment) ? 'disabled' : ''; ?> required>
                            <?php if ($isLinked || $isAdjustment): ?>
                            <input type="hidden" name="date" value="<?php echo date('Y-m-d', strtotime($expense['Date'])); ?>">
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="term">Term</label>
                            <input type="number" id="term" name="term" class="form-control" value="<?php echo htmlspecialchars($expense['Term']); ?>" disabled required>
                            <input type="hidden" name="term" value="<?php echo $expense['Term']; ?>">
                            <div class="form-note">Term cannot be changed.</div>
                        </div>
                        <div class="form-group" id="receipt-group">
                            <label for="receipt">Receipt Image</label>
                            <input type="file" id="receipt" name="receipt" class="form-control" <?php echo $isAdjustment ? 'disabled' : ''; ?>>
                            <div class="form-note">Leave empty to keep the current receipt. Only JPG, JPEG, PNG, and PDF files are allowed.</div>
                            <?php if (!empty($expense['Image'])): ?>
                            <div>
                                <p>Current Receipt:</p>
                                <?php 
                                    // Check if the path already includes "../../../" to avoid duplicating it
                                    $imgPath = $expense['Image'];
                                    if (strpos($imgPath, '../../../') === 0) {
                                        $imgPath = substr($imgPath, 9); // Remove "../../../" if it's already there
                                    }
                                    
                                    // Determine if it's an image or PDF for correct display
                                    $fileExtension = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
                                    $isPdf = ($fileExtension === 'pdf');
                                ?>
                                
                                <?php if ($isPdf): ?>
                                    <a href="../../../<?php echo $expense['Image']; ?>" target="_blank" class="btn btn-sm btn-info">View PDF Receipt</a>
                                <?php else: ?>
                                    <img src="../../../<?php echo $expense['Image']; ?>" alt="Receipt" class="receipt-preview">
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3" <?php echo $isAdjustment ? 'disabled' : ''; ?>><?php echo htmlspecialchars($expense['Description'] ?? ''); ?></textarea>
                        <?php if ($isAdjustment): ?>
                        <input type="hidden" name="description" value="<?php echo htmlspecialchars($expense['Description'] ?? ''); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="btn-container">
                        <?php if ($isPopup): ?>
                            <button type="button" class="btn btn-secondary" onclick="window.parent.closeEditModal()">Cancel</button>
                        <?php else: ?>
                            <a href="trackExpenses.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary" <?php echo $isAdjustment ? 'disabled' : ''; ?>>Update Expense</button>
                    </div>
                </form>
            </div>

<?php if ($isPopup): ?>
    </div>
    
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_SESSION['error_message'])): ?>
<script>
    // If form was submitted successfully in popup mode, pass message to parent
    window.parent.showAlert('success', 'Expense #<?php echo $expenseID; ?> successfully updated');
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
    // Form validation and dynamic UI behavior
    document.addEventListener('DOMContentLoaded', function() {
        const methodSelect = document.getElementById('method');
        const receiptGroup = document.getElementById('receipt-group');
        const amountInput = document.getElementById('amount');
        const dateInput = document.getElementById('date');
        const categorySelect = document.getElementById('category');
        
        // Show/hide receipt upload based on payment method
        function toggleReceiptVisibility() {
            if (methodSelect.value === 'Bank Transfer') {
                receiptGroup.style.display = 'block';
            } else {
                receiptGroup.style.display = '<?php echo (!empty($expense['Image'])) ? "block" : "none"; ?>';
            }
        }
        
        // Initialize visibility
        toggleReceiptVisibility();
        
        // Add event listener for changes
        methodSelect.addEventListener('change', toggleReceiptVisibility);
        
        // Form validation before submission
        document.querySelector('form').addEventListener('submit', function(e) {
            let isValid = true;
            let errorMessage = '';
            
            // Validate amount
            const amount = parseFloat(amountInput.value);
            const maxLimit = <?php echo $maxLoanLimit; ?>;
            
            if (isNaN(amount) || amount <= 0) {
                isValid = false;
                errorMessage += 'Please enter a valid amount greater than zero. ';
            }
            
            if (amount > maxLimit) {
                isValid = false;
                errorMessage += 'Amount cannot exceed the maximum limit of Rs. ' + maxLimit.toLocaleString() + '. ';
            }
            
            // Validate date
            const expenseDate = new Date(dateInput.value);
            expenseDate.setHours(0, 0, 0, 0);

            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (expenseDate > today) {
                isValid = false;
                errorMessage += 'Expense date cannot be in the future. ';
            }
            
            // Check if category is Death Welfare and method is appropriate
            if (categorySelect.value === 'Death Welfare' && methodSelect.value !== 'Bank Transfer') {
                isValid = false;
                errorMessage += 'Death Welfare expenses must use Bank Transfer payment method. ';
            }
            
            // If there are validation errors, prevent form submission and show alert
            if (!isValid) {
                e.preventDefault();
                alert('Validation Error: ' + errorMessage);
            }
        });
    });
</script>