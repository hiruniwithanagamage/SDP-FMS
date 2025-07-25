<?php
session_start();
require_once "../../config/database.php";

// Store referrer URL in session if not already set
if (!isset($_SESSION['previous_page']) && isset($_SERVER['HTTP_REFERER'])) {
    $_SESSION['previous_page'] = $_SERVER['HTTP_REFERER'];
}

// Generate new Expense ID
// Get current term/year from database
$termQuery = "SELECT year FROM Static WHERE status = 'active'";
$termResult = search($termQuery);
$currentTerm = date('Y'); // Default to current year if not found in DB

if ($termResult && $termResult->num_rows > 0) {
    $termRow = $termResult->fetch_assoc();
    $currentTerm = $termRow['year'];
}

// Get last 2 digits of the year
$yearSuffix = substr($currentTerm, -2);

// Generate new Expense ID with year component
$query = "SELECT ExpenseID FROM Expenses WHERE ExpenseID LIKE 'EXP{$yearSuffix}%' ORDER BY ExpenseID DESC LIMIT 1";
$result = search($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    if ($row && isset($row['ExpenseID'])) {
        $lastId = $row['ExpenseID'];
        // Extract the sequence number (last 2 digits)
        $numericPart = substr($lastId, -2);
        $newNumericPart = intval($numericPart) + 1;
        // Format with leading zeros (01, 02, etc.)
        $newExpenseId = "EXP" . $yearSuffix . str_pad($newNumericPart, 2, "0", STR_PAD_LEFT);
    } else {
        $newExpenseId = "EXP" . $yearSuffix . "01";
    }
} else {
    $newExpenseId = "EXP" . $yearSuffix . "01";
}

// Get current treasurer
$treasurerQuery = "SELECT TreasurerID FROM Treasurer WHERE isActive = 1 LIMIT 1";
$treasurerResult = search($treasurerQuery);
$treasurerId = "";

if ($treasurerResult && $treasurerResult->num_rows > 0) {
    $treasurerRow = $treasurerResult->fetch_assoc();
    $treasurerId = $treasurerRow['TreasurerID'];
}

// Get current term
$termQuery = "SELECT year FROM Static WHERE status = 'active'";
$termResult = search($termQuery);
$currentTerm = 1;

if ($termResult && $termResult->num_rows > 0) {
    $termRow = $termResult->fetch_assoc();
    $currentTerm = $termRow['year'];
}

// Determine redirect URL based on previous page
$redirectUrl = "home-treasurer.php"; // Default redirect
if (isset($_SESSION['previous_page'])) {
    if (strpos($_SESSION['previous_page'], 'trackExpenses.php') !== false) {
        $redirectUrl = "financialManagement/trackExpenses.php";
    } elseif (strpos($_SESSION['previous_page'], 'home-treasurer.php') !== false) {
        $redirectUrl = "home-treasurer.php";
    }
}

// Check if form is submitted
if(isset($_POST['add'])) {
    $category = $_POST['category'];
    $method = $_POST['method'];
    $amount = $_POST['amount'];
    $date = $_POST['date'];
    $description = $_POST['description'];
    $expenseId = $newExpenseId;
    $errors = [];

    // Validate amount
    if(empty($amount)) {
        $errors[] = "Amount is required";
    } elseif(!is_numeric($amount) || $amount <= 0) {
        $errors[] = "Amount must be a positive number";
    } elseif($amount > 1000000) { // maximum limit is added
        $errors[] = "Amount exceeds the maximum limit";
    }

    // Validate description (if provided)
    if(!empty($description) && strlen($description) > 500) { // Example maximum length
        $errors[] = "Description cannot exceed 500 characters";
    }
    
    // File upload validation
    $imagePath = null;
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] == 0) {
        $uploadDir = '../../uploads/expenses/';
        
        // Create directory if it doesn't exist
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Get file extension
        $fileExtension = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        
        // Create sanitized category name
        $sanitizedCategory = preg_replace('/[^A-Za-z0-9]/', '', $category); // Remove special chars
        
        // Create filename with extension
        $fileName = $expenseId . '_' . $sanitizedCategory . '_' . date('Ymd') . '.' . $fileExtension;
        $targetFilePath = $uploadDir . $fileName;
        
        // Check if file is an actual image
        $check = getimagesize($_FILES['receipt']['tmp_name']);
        if($check === false) {
            $errors[] = "Uploaded file is not a valid image";
        }
        
        // Validate file extension
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        if(!in_array($fileExtension, $allowedExtensions)) {
            $errors[] = "Only JPG, JPEG, PNG, GIF, and PDF files are allowed";
        }
        
        // Validate file size (5MB maximum)
        if($_FILES['receipt']['size'] > 5 * 1024 * 1024) {
            $errors[] = "File size cannot exceed 5MB";
        }
        
        // If all file validations pass, upload the file
        if(empty($errors)) {
            // Use absolute path for troubleshooting permission issues
            $absoluteUploadDir = $_SERVER['DOCUMENT_ROOT'] . '/SDP/uploads/expenses/';
            
            // Ensure directory exists
            if (!file_exists($absoluteUploadDir)) {
                mkdir($absoluteUploadDir, 0777, true);
            }
            
            $absoluteTargetPath = $absoluteUploadDir . $fileName;
            
            if(move_uploaded_file($_FILES['receipt']['tmp_name'], $absoluteTargetPath)) {
                $imagePath = 'uploads/expenses/' . $fileName;
            } else {
                $errors[] = "Error uploading file. Permission denied. Please check folder permissions. Error: " . error_get_last()['message'];
            }
        }
    }
    
    // Validate inputs
    // if(empty($category) || empty($method) || empty($amount) || empty($date) || empty($treasurerId)) {
    //     $error = "All fields are required except description and image";
    if(!empty($errors)) {
        $error = implode("<br>", $errors);
    } else {
        try {
            // Use prepared statement for insertion
            $stmt = prepare("INSERT INTO Expenses (ExpenseID, Category, Method, Amount, Date, Term, Description, Image, Treasurer_TreasurerID) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            // Bind parameters
            $stmt->bind_param("sssdsisss", 
                $expenseId, 
                $category, 
                $method, 
                $amount, 
                $date, 
                $currentTerm, 
                $descriptionValue, 
                $imageValue, 
                $treasurerId
            );
            
            // Set null values correctly
            $descriptionValue = empty($description) ? null : $description;
            $imageValue = empty($imagePath) ? null : $imagePath;
            
            // Execute the statement
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['success_message'] = "Expense added successfully";
            
            // Redirect to previous page
            header("Location: $redirectUrl");
            exit();
        } catch(Exception $e) {
            $error = "Error adding expense: " . $e->getMessage();
        }
    }
}

// Clear previous page from session if cancel is clicked
if(isset($_GET['cancel'])) {
    // Redirect to the stored previous page
    header("Location: $redirectUrl");
    unset($_SESSION['previous_page']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Expense</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminDetails.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
        }

        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #1a237e;
            margin-bottom: 2rem;
        }

        .form-group {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group label {
            width: 150px;
            text-align: right;
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            flex: 1;
        }

        input[type="text"],
        input[type="number"],
        input[type="date"],
        select,
        textarea {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="number"]:focus,
        input[type="date"]:focus,
        select:focus,
        textarea:focus {
            border-color: #1a237e;
            outline: none;
        }

        .button-group {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }

        .btn {
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-add {
            background-color: #1a237e;
            color: white;
        }

        .btn-add:hover {
            background-color: #0d1757;
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .alert-error {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        /* Responsive styles */
        @media (max-width: 992px) {
           .statistics-grid {
                flex-wrap: wrap;
           }
           
           .action-buttons {
               grid-template-columns: repeat(2, 1fr);
           }
       }

       @media (max-width: 768px) {
           .action-buttons {
               grid-template-columns: 1fr;
           }
           
           .info-grid {
               grid-template-columns: 1fr;
           }
           
           .welcome-card {
               flex-direction: column;
               text-align: center;
               gap: 1rem;
           }
           
           .status-cards {
               flex-direction: column;
           }
           
           .term-button {
                width: 100%;
                justify-content: center;
            }
       }
    </style>
</head>
<body>
    <div class="main-container" style="min-height: 100vh; background: #f5f7fa; padding: 2rem;">
    <?php include '../templates/navbar-treasurer.php'; ?>
    <div class="container">
        <h1>Add Expense</h1>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-group">
                <label for="expense_id">Expense ID</label>
                <input type="text" id="expense_id" name="expense_id" value="<?php echo $newExpenseId; ?>" disabled>
            </div>

            <div class="form-group">
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <option value="">Select Category</option>
                    <option value="Maintenace">Maintenance</option>
                    <option value="Stationary">Stationary</option>
                    <option value="Event">Event</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label for="method">Payment Method</label>
                <select id="method" name="method" required>
                    <option value="">Select Method</option>
                    <option value="Cash">Cash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                </select>
            </div>

            <div class="form-group">
                <label for="amount">Amount</label>
                <input type="number" id="amount" name="amount" step="0.01" required>
            </div>

            <div class="form-group">
                <label></label>
                <span style="color: #888; font-size: 0.95em;">
                    Maximum allowed amount: 1,000,000
                </span>
            </div>

            <div class="form-group">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" required>
            </div>

            <div class="form-group">
                <label for="description">Description (Optional)</label>
                <textarea id="description" name="description" rows="3" style="margin-right:48px;"></textarea>
            </div>

            <div class="form-group">
                <label for="receipt">Receipt Image (Optional)</label>
                <input type="file" id="receipt" name="receipt" accept="image/*">
            </div>

            <div class="button-group">
                <a href="home-treasurer.php" class="cancel-btn">Cancel</a>
                <button type="submit" name="add" class="btn btn-add">Add Expense</button>
            </div>
        </form>
    </div>
    </div>
</body>
</html>