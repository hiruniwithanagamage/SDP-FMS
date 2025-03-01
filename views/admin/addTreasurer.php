<?php
session_start();
require_once "../../config/database.php";

// Generate new Treasurer ID
$query = "SELECT TreasurerID FROM Treasurer ORDER BY TreasurerID DESC LIMIT 1";
$result = search($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    if ($row && isset($row['TreasurerID'])) {
        $lastId = $row['TreasurerID'];
        // Use preg_replace to extract only numeric part
        $numericPart = preg_replace('/[^0-9]/', '', $lastId);
        $newNumericPart = intval($numericPart) + 1;
        $newTreasurerId = "tres" . $newNumericPart;
    } else {
        $newTreasurerId = "tres1";
    }
} else {
    $newTreasurerId = "tres1";
}

// Check if form is submitted
if(isset($_POST['add'])) {
    $name = $_POST['name'];
    $treasurerId = $newTreasurerId;
    $term = $_POST['term'];
    
    // Validate inputs
    if(empty($name)) {
        $error = "Name is required";
    } elseif(empty($term)) {
        $error = "Term is required";
    } elseif(!is_numeric($term) || intval($term) <= 0) {
        $error = "Term must be a positive number";
    } else {
        try {
            $conn = getConnection();
            
            // Use prepared statement for insert
            $stmt = $conn->prepare("INSERT INTO Treasurer (TreasurerID, Name, Term, isActive) VALUES (?, ?, ?, 1)");
            $term = intval($term);
            $stmt->bind_param("ssi", $treasurerId, $name, $term);
            $stmt->execute();
            
            if($stmt->affected_rows > 0) {
                $_SESSION['success_message'] = "Treasurer added successfully";
            } else {
                $error = "Failed to add treasurer";
            }
            $stmt->close();
            
            header("Location: treasurerDetails.php");
            exit();
        } catch(Exception $e) {
            $error = "Error adding treasurer: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Treasurer</title>
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
        input[type="number"] {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="number"]:focus {
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
        <h1>Treasurer Details</h1>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" required>
            </div>

            <div class="form-group">
                <label for="treasurer_id">Treasurer ID</label>
                <input type="text" id="treasurer_id" name="treasurer_id" value="<?php echo $newTreasurerId; ?>" disabled>
            </div>

            <div class="form-group">
                <label for="term">Term</label>
                <input type="number" id="term" name="term" required>
            </div>

            <div class="button-group">
                <button type="submit" name="add" class="btn btn-add">Add</button>
                <button type="button" onclick="window.location.href='treasurerDetails.php'" class="btn btn-cancel">Cancel</button>
            </div>
        </form>
    </div>
    </div>
</body>
</html>