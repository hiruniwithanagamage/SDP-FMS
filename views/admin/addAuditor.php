<?php
session_start();
require_once "../../config/database.php";

// Get database connection
$conn = getConnection();

// Generate new Auditor ID
function generateNewAuditorId($conn) {
    $query = "SELECT AuditorID FROM Auditor ORDER BY AuditorID DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row && isset($row['AuditorID'])) {
            $lastId = $row['AuditorID'];
            // Use preg_replace to extract only numeric part
            $numericPart = preg_replace('/[^0-9]/', '', $lastId);
            $newNumericPart = intval($numericPart) + 1;
            return "auditor" . $newNumericPart;
        }
    }
    return "auditor1";
}

// Check if auditor name already exists
function checkExistingAuditor($conn, $name) {
    $query = "SELECT AuditorID FROM Auditor WHERE Name = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

$newAuditorId = generateNewAuditorId($conn);

// Check if form is submitted
if(isset($_POST['add'])) {
    $name = trim($_POST['name']);
    $auditorId = $newAuditorId;
    $term = trim($_POST['term']);
    
    // Validate inputs
    $errors = [];
    
    if(empty($name)) $errors[] = "Name is required";
    if(empty($term)) $errors[] = "Term is required";
    if(!is_numeric($term)) $errors[] = "Term must be a number";
    
    // Check if auditor name already exists
    if(empty($errors) && checkExistingAuditor($conn, $name)) {
        $errors[] = "An auditor with this name already exists";
    }
    
    if(empty($errors)) {
        try {
            // Use prepared statement for insert
            $query = "INSERT INTO Auditor (AuditorID, Name, Term, isActive) VALUES (?, ?, ?, 1)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ssi", $auditorId, $name, $term);
            
            // Execute the statement
            $stmt->execute();
            
            if($stmt->affected_rows > 0) {
                // Set session message
                $_SESSION['success_message'] = "Auditor added successfully";
                
                // Redirect to auditorDetails.php
                header("Location: auditorDetails.php");
                exit();
            } else {
                $error = "Failed to add auditor. Please try again.";
            }
        } catch(Exception $e) {
            $error = "Error adding auditor: " . $e->getMessage();
            // Generate new ID in case we need to retry
            $newAuditorId = generateNewAuditorId($conn);
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
    <title>Add Auditor</title>
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
    </style>
</head>
<body>
    <div class="main-container" style="min-height: 100vh; background: #f5f7fa; padding: 2rem;">
    <?php include '../templates/navbar-admin.php'; ?>
    <div class="container">
        <h1>Add New Auditor</h1>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" required value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="auditor_id">Auditor ID</label>
                <input type="text" id="auditor_id" name="auditor_id" value="<?php echo htmlspecialchars($newAuditorId); ?>" disabled>
            </div>

            <div class="form-group">
                <label for="term">Term</label>
                <input type="number" id="term" name="term" required value="<?php echo isset($term) ? htmlspecialchars($term) : ''; ?>">
            </div>

            <div class="button-group">
                <button type="submit" name="add" class="btn btn-add">Add Auditor</button>
                <button type="button" onclick="window.location.href='auditorDetails.php'" class="btn btn-cancel">Cancel</button>
            </div>
        </form>
    </div>
    </div>
</body>
</html>