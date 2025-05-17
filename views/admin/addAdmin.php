<?php
session_start();
require_once "../../config/database.php";

// Get database connection
$conn = getConnection(); // Assuming this function exists in database.php

// Get current active year from static table
function getCurrentActiveYear($conn) {
    $query = "SELECT year FROM static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['year'];
    }
    
    // Fallback to current year if no active year found
    return date('Y');
}

// Generate new Admin ID with format ADMI01, ADMI02, etc.
function generateNewAdminId($conn) {
    $query = "SELECT AdminID FROM Admin ORDER BY AdminID DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row && isset($row['AdminID'])) {
            $lastId = $row['AdminID'];
            // Extract numeric part after "ADMI"
            $numericPart = substr($lastId, 4); // Get everything after "ADMI"
            $newNumericPart = intval($numericPart) + 1;
            return "ADMI" . str_pad($newNumericPart, 2, "0", STR_PAD_LEFT); // Ensure at least 2 digits
        }
    }
    return "ADMI01"; // First admin ID
}

// Check if admin name already exists
function checkExistingAdmin($conn, $name) {
    $query = "SELECT AdminID FROM Admin WHERE Name = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Get current active year
$currentYear = getCurrentActiveYear($conn);

// Generate new admin ID
$newAdminId = generateNewAdminId($conn);

// Check if form is submitted
if(isset($_POST['add'])) {
    $name = trim($_POST['name']);
    $adminId = $newAdminId;
    $contactNumber = trim($_POST['contact_number']);
    
    // Validate inputs
    $errors = [];
    
    if(empty($name)) $errors[] = "Name is required";
    if(empty($contactNumber)) $errors[] = "Contact Number is required";
    
    // Additional validation for contact number
    if(!preg_match('/^[0-9]{10}$/', $contactNumber)) {
        $errors[] = "Contact number must be 10 digits";
    }
    
    // Check if admin name already exists
    if(empty($errors) && checkExistingAdmin($conn, $name)) {
        $errors[] = "An admin with this name already exists";
    }
    
    if(empty($errors)) {
        try {
            // Prepare SQL insert statement with parameters
            $query = "INSERT INTO Admin (AdminID, Name, Contact_Number) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sss", $adminId, $name, $contactNumber);
            
            // Execute the statement
            $stmt->execute();
            
            if($stmt->affected_rows > 0) {
                // Set session message
                $_SESSION['success_message'] = "Admin added successfully";
                
                // Redirect to adminDetails.php
                header("Location: adminDetails.php");
                exit();
            } else {
                $error = "Failed to add admin. Please try again.";
            }
        } catch(Exception $e) {
            $error = "Error adding admin: " . $e->getMessage();
        }
    } else {
        // Collect errors
        $error = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
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

        input[type="text"] {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        input[type="text"]:focus {
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

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .alert-error, .alert-danger {
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
    <?php include '../templates/navbar-admin.php'; ?>
    <div class="container">
        <h1>Add New Admin</h1>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" required value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="admin_id">Admin ID</label>
                <input type="text" id="admin_id" name="admin_id" value="<?php echo htmlspecialchars($newAdminId); ?>" disabled>
            </div>

            <div class="form-group">
                <label for="contact_number">Contact Number</label>
                <input type="text" id="contact_number" name="contact_number" required value="<?php echo isset($contactNumber) ? htmlspecialchars($contactNumber) : ''; ?>" placeholder="10 digits">
            </div>

            <div class="form-group">
                <label for="current_year">Current Active Year</label>
                <input type="text" id="current_year" name="current_year" value="<?php echo htmlspecialchars($currentYear); ?>" disabled>
            </div>

            <div class="button-group">
                <button type="button" onclick="window.location.href='adminDetails.php'" class="btn cancel-btn">Cancel</button>
                <button type="submit" name="add" class="btn btn-add">Add Admin</button>
            </div>
        </form>
    </div>
    </div>
</body>
</html>