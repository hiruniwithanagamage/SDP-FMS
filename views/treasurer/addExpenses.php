<?php
session_start();
require_once "../../config/database.php";

// Store referrer URL in session if not already set
if (!isset($_SESSION['previous_page']) && isset($_SERVER['HTTP_REFERER'])) {
    $_SESSION['previous_page'] = $_SERVER['HTTP_REFERER'];
}

// Generate new Expense ID
$query = "SELECT ExpenseID FROM Expenses ORDER BY ExpenseID DESC LIMIT 1";
$result = search($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    if ($row && isset($row['ExpenseID'])) {
        $lastId = $row['ExpenseID'];
        // Use preg_replace to extract only numeric part
        $numericPart = preg_replace('/[^0-9]/', '', $lastId);
        $newNumericPart = intval($numericPart) + 1;
        // Format with leading zeros (001, 002, etc.)
        $newExpenseId = "EXP" . str_pad($newNumericPart, 3, "0", STR_PAD_LEFT);
    } else {
        $newExpenseId = "EXP001";
    }
} else {
    $newExpenseId = "EXP001";
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
    
    // File upload handling
    $imagePath = null;
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] == 0) {
        $uploadDir = '../uploads/expenses/';
        
        // Create directory if it doesn't exist
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = time() . '_' . basename($_FILES['receipt']['name']);
        $targetFilePath = $uploadDir . $fileName;
        
        // Check if image file is a actual image or fake image
        $check = getimagesize($_FILES['receipt']['tmp_name']);
        if($check !== false) {
            // Upload file
            if(move_uploaded_file($_FILES['receipt']['tmp_name'], $targetFilePath)) {
                $imagePath = 'uploads/expenses/' . $fileName;
            } else {
                $error = "Sorry, there was an error uploading your file.";
            }
        } else {
            $error = "File is not an image.";
        }
    }
    
    // Validate inputs
    if(empty($category) || empty($method) || empty($amount) || empty($date) || empty($treasurerId)) {
        $error = "All fields are required except description and image";
    } else {
        // Insert into Expenses table
        $query = "INSERT INTO Expenses (ExpenseID, Category, Method, Amount, Date, Term, Description, Image, Treasurer_TreasurerID) 
                 VALUES ('" . $expenseId . "', '" . $category . "', '" . $method . "', " . $amount . ", '" . $date . "', 
                 " . $currentTerm . ", " . ($description ? "'" . $description . "'" : "NULL") . ", " . 
                 ($imagePath ? "'" . $imagePath . "'" : "NULL") . ", '" . $treasurerId . "')";
        
        try {
            Database::iud($query);
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
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: bold;
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
            gap: 1rem;
            margin-top: 2rem;
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

        .btn-cancel {
            background-color: white;
            color: #1a237e;
            border: 2px solid #1a237e;
            text-decoration: none;
        }

        .btn-cancel:hover {
            background-color: #f5f7fa;
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
                    <option value="Death Welfare">Death Welfare</option>
                    <option value="Administrative">Administrative</option>
                    <option value="Utility">Utility</option>
                    <option value="Maintenance">Maintenance</option>
                    <option value="Event">Event</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label for="method">Payment Method</label>
                <select id="method" name="method" required>
                    <option value="">Select Method</option>
                    <option value="Cash">Cash</option>
                    <option value="Check">Check</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Digital Payment">Digital Payment</option>
                </select>
            </div>

            <div class="form-group">
                <label for="amount">Amount</label>
                <input type="number" id="amount" name="amount" step="0.01" required>
            </div>

            <div class="form-group">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" required>
            </div>

            <div class="form-group">
                <label for="description">Description (Optional)</label>
                <textarea id="description" name="description" rows="3"></textarea>
            </div>

            <div class="form-group">
                <label for="receipt">Receipt Image (Optional)</label>
                <input type="file" id="receipt" name="receipt" accept="image/*">
            </div>

            <div class="button-group">
                <button type="submit" name="add" class="btn btn-add">Add Expense</button>
                <a href="?cancel=1" class="btn btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
    </div>
</body>
</html>