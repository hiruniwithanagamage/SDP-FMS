<?php
session_start();
require_once "../../config/database.php";

// Function to generate a new welfare ID
function generateTreasurerID() {
    $countQuery = "SELECT COUNT(*) as count FROM Treasurer";
    $countResult = search($countQuery);
    $treasurerCount = $countResult->fetch_assoc()['count'] + 1;
    
    // Format the ID as TRES followed by a two-digit number (01, 02, etc.)
    return "TRES" . str_pad($treasurerCount, 2, "0", STR_PAD_LEFT);
}

// Function to get the current active year
function getActiveYear() {
    $activeYearQuery = "SELECT year FROM Static WHERE status = 'active' LIMIT 1";
    $activeYearResult = search($activeYearQuery);
    
    if ($activeYearResult && $activeYearResult->num_rows > 0) {
        return $activeYearResult->fetch_assoc()['year'];
    }
    
    return null;
}

// Get the logged-in treasurer's ID from the session
$treasurerID = isset($_SESSION['TreasurerID']) ? $_SESSION['TreasurerID'] : null;

// Fetch all terms (both active and inactive)
$allTermsQuery = "SELECT * FROM Static ORDER BY year DESC";
$allTermsResult = search($allTermsQuery);
$allTerms = [];
while ($term = $allTermsResult->fetch_assoc()) {
    $allTerms[] = $term;
}

// Identify the current active term
$currentActiveTerm = null;
$availableTerms = [];
foreach ($allTerms as $term) {
    if ($term['status'] == 'active') {
        $currentActiveTerm = $term;
    }
    $availableTerms[] = $term;
}

// Generate sequential Treasurer ID
$newTreasurerID = generateTreasurerID();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $memberID = $_POST['member_id'];
    $term = $_POST['term'];
    $treasurerID = $_POST['treasurer_id'];

    // Validate inputs
    $errors = [];
    
    if (empty($memberID)) {
        $errors[] = "Member selection is required";
    }
    
    // Check if member is already a treasurer
    $checkTreasurerQuery = "SELECT * FROM Treasurer WHERE MemberID = '$memberID' AND isActive = 1";
    $checkTreasurerResult = search($checkTreasurerQuery);
    if ($checkTreasurerResult->num_rows > 0) {
        $errors[] = "This member is already a treasurer";
    }

    // Check if there's already an active treasurer for this term
    $activeTreasurerQuery = "SELECT * FROM Treasurer WHERE Term = '$term' AND isActive = 1";
    $activeTreasurerResult = search($activeTreasurerQuery);
    if ($activeTreasurerResult->num_rows > 0) {
        $activeTreasurer = $activeTreasurerResult->fetch_assoc();
        $errors[] = "There is already an active treasurer for this term. Please deactivate the existing treasurer first.";
    }

    // Get member details
    $memberQuery = "SELECT Name FROM Member WHERE MemberID = '$memberID'";
    $memberResult = search($memberQuery);
    
    if ($memberResult->num_rows == 0) {
        $errors[] = "Invalid member selected";
    } else {
        $memberData = $memberResult->fetch_assoc();
        $name = $memberData['Name'];
    }

    // Validate term
    $termQuery = "SELECT * FROM Static WHERE year = '$term'";
    $termResult = search($termQuery);
    if ($termResult->num_rows == 0) {
        $errors[] = "Invalid term selected";
    }

    // If no errors, proceed with insertion
    if (empty($errors)) {
        $insertQuery = "INSERT INTO Treasurer (TreasurerID, Name, Term, isActive, MemberID) 
                        VALUES ('$treasurerID', '$name', '$term', 1, '$memberID')";
        
        try {
            iud($insertQuery);
            $_SESSION['success_message'] = "Treasurer added successfully!";
            header("Location: treasurerDetails.php");
            exit();
        } catch(Exception $e) {
            $_SESSION['error_message'] = "Error adding treasurer: " . $e->getMessage();
        }
    } else {
        // Store errors in session to display on page reload
        $_SESSION['error_messages'] = $errors;
    }
}

// Fetch existing members for dropdown
$membersQuery = "SELECT MemberID, Name FROM Member";
$membersResult = search($membersQuery);

// Check if we have members
if ($membersResult->num_rows == 0) {
    $_SESSION['error_message'] = "No members found in the system.";
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
    <link rel="stylesheet" href="../../assets/css/adminDetails.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
    <style>
        .select2-container {
            width: 100% !important;
            flex: 1;
            margin-right: 50px;
        }
        .select2-container .select2-selection--single {
            height: 38px;
            width: 100%;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
            padding-left: 12px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        .form-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
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
            margin-right: 20px;
            font-weight: 500;
        }
        .form-group input,
        .form-group select {
            flex: 1;
            width: 100%;
            box-sizing: border-box;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }
        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 20px;
        }
        .term-status {
            margin-left: 10px;
            font-size: 0.8em;
            color: #6c757d;
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
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-admin.php'; ?>
        
        <div class="container">
            <?php 
            // Display error messages
            if(isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php 
            // Display multiple error messages
            if(isset($_SESSION['error_messages']) && is_array($_SESSION['error_messages'])): ?>
                <div class="alert alert-danger">
                    <?php 
                        foreach($_SESSION['error_messages'] as $error) {
                            echo htmlspecialchars($error) . "<br>";
                        }
                        unset($_SESSION['error_messages']);
                    ?>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <h2 class="form-title" style="text-align: left; margin-bottom: 2rem; color:#1a237e">Add New Treasurer</h2>

                <form method="POST">
                    <div class="form-group">
                        <label for="treasurer_id">Treasurer ID</label>
                        <input type="text" id="treasurer_id" name="treasurer_id" 
                               value="<?php echo htmlspecialchars($newTreasurerID); ?>" 
                               readonly>
                    </div>

                    <div class="form-group">
                        <label for="member_id">Member Name</label>
                        <select id="member_id" name="member_id" class="member-select" required>
                            <option value="">Select Member</option>
                            <?php 
                            if($membersResult && $membersResult->num_rows > 0):
                                // Reset the pointer to the beginning
                                $membersResult->data_seek(0);
                                while($member = $membersResult->fetch_assoc()): 
                            ?>
                                <option value="<?php echo htmlspecialchars($member['MemberID']); ?>">
                                    <?php echo htmlspecialchars($member['Name'] . " (ID: " . $member['MemberID'] . ")"); ?>
                                </option>
                            <?php 
                                endwhile; 
                            endif;
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="term">Term</label>
                        <select id="term" name="term" required>
                            <?php if($currentActiveTerm): ?>
                                <option value="<?php echo htmlspecialchars($currentActiveTerm['year']); ?>">
                                    <?php 
                                    echo htmlspecialchars($currentActiveTerm['year']); 
                                    echo ' <span class="term-status">(Active)</span>';
                                    $termCheckQuery = "SELECT Name FROM Treasurer WHERE Term = '{$currentActiveTerm['year']}' AND isActive = 1";
                                    $termCheckResult = search($termCheckQuery);
                                    if ($termCheckResult->num_rows > 0) {
                                        $existingTreasurer = $termCheckResult->fetch_assoc();
                                    }
                                    ?>
                                </option>
                            <?php endif; ?>

                            <?php foreach($availableTerms as $termOption): 
                                // Skip the current active term as it's already added
                                if($currentActiveTerm && $termOption['year'] == $currentActiveTerm['year']) continue;
                            ?>
                                <option value="<?php echo htmlspecialchars($termOption['year']); ?>">
                                    <?php 
                                    echo htmlspecialchars($termOption['year']); 
                                    echo $termOption['status'] == 'active' ? ' <span class="term-status">(Active)</span>' : ' <span class="term-status">(Inactive)</span>';
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-footer">
                        <a href="treasurerDetails.php" class="cancel-btn">Cancel</a>
                        <button type="submit" class="save-btn" 
                                <?php echo (count($availableTerms) == 0) ? 'disabled' : ''; ?>>
                            Add Treasurer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        // Initialize Select2 for member dropdown
        $('.member-select').select2({
            placeholder: 'Select or search for a member...',
            allowClear: true,
            width: '100%'
        });
    });
    </script>
</body>
</html>