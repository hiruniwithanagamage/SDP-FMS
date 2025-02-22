<?php
session_start();
require_once "../../config/database.php";

// Generate new Admin ID
$query = "SELECT AdminID FROM Admin ORDER BY AdminID DESC LIMIT 1";
$result = Database::search($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    if ($row && isset($row['AdminID'])) {
        $lastId = $row['AdminID'];
        // Use preg_replace to extract only numeric part
        $numericPart = preg_replace('/[^0-9]/', '', $lastId);
        $newNumericPart = intval($numericPart) + 1;
        $newAdminId = "admin" . $newNumericPart;
    } else {
        $newAdminId = "admin1";
    }
} else {
    $newAdminId = "admin1";
}

// Check if form is submitted
if(isset($_POST['add'])) {
    $name = $_POST['name'];
    $adminId = $newAdminId;
    $contactNumber = $_POST['contact_number'];
    
    // Validate inputs
    $errors = [];
    
    if(empty($name)) $errors[] = "Name is required";
    if(empty($contactNumber)) $errors[] = "Contact Number is required";
    
    // Additional validation for contact number (optional)
    if(!preg_match('/^[0-9]{10}$/', $contactNumber)) {
        $errors[] = "Invalid contact number format";
    }
    
    if(empty($errors)) {
        // Prepare SQL insert statement
        $query = "INSERT INTO Admin (AdminID, Name, Contact_Number) 
                  VALUES ('" . $adminId . "', '" . $name . "', '" . $contactNumber . "')";
        
        try {
            // Use the existing database method for insert/update/delete
            Database::iud($query);
            
            // Set session message
            $_SESSION['success_message'] = "Admin added successfully";
            
            // Redirect to adminDetails.php
            header("Location: adminDetails.php");
            exit();
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

        .btn-cancel {
            background-color: white;
            color: #1a237e;
            border: 2px solid #1a237e;
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
    <?php include '../templates/navbar-admin.php'; ?>
    <div class="container">
        <h1>Admin Details</h1>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" required>
            </div>

            <div class="form-group">
                <label for="admin_id">Admin ID</label>
                <input type="text" id="admin_id" name="admin_id" value="<?php echo htmlspecialchars($newAdminId); ?>" disabled>
            </div>

            <div class="form-group">
                <label for="contact_number">Contact Number</label>
                <input type="text" id="contact_number" name="contact_number" required>
            </div>

            <div class="button-group">
                <button type="submit" name="add" class="btn btn-add">Add</button>
                <button type="button" onclick="window.location.href='adminDetails.php'" class="btn btn-cancel">Cancel</button>
            </div>
        </form>
    </div>
    </div>
</body>
</html>