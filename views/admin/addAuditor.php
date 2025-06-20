<?php
session_start();
require_once "../../config/database.php";

// Get the logged-in treasurer's ID from the session
$treasurerID = isset($_SESSION['TreasurerID']) ? $_SESSION['TreasurerID'] : null;

// Get the current active term from the Static table
function getCurrentActiveTerm() {
    $activeTermQuery = "SELECT * FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $activeTermResult = search($activeTermQuery);
    
    if ($activeTermResult && $activeTermResult->num_rows > 0) {
        return $activeTermResult->fetch_assoc();
    }
    
    return null;
}

// Generate sequential Auditor ID following the pattern AUDI01, AUDI02, etc.
function generateAuditorID() {
    $prefix = "AUDI";
    
    // Get the highest existing auditor ID
    $queryMaxID = "SELECT AuditorID FROM Auditor ORDER BY AuditorID DESC LIMIT 1";
    $result = search($queryMaxID);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastID = $row['AuditorID'];
        
        // Extract the numeric part
        $numericPart = intval(substr($lastID, 4)); // Skip 'AUDI' prefix
        $nextNumber = $numericPart + 1;
    } else {
        // No existing records, start with 1
        $nextNumber = 1;
    }
    
    // Format the number with leading zeros (2 digits)
    return $prefix . str_pad($nextNumber, 2, '0', STR_PAD_LEFT);
}

// Fetch all terms (both active and inactive)
$allTermsQuery = "SELECT * FROM Static ORDER BY year DESC";
$allTermsResult = search($allTermsQuery);
$allTerms = [];
while ($allTermsResult && $term = $allTermsResult->fetch_assoc()) {
    $allTerms[] = $term;
}

// Identify the current active term
$currentActiveTerm = getCurrentActiveTerm();
$availableTerms = $allTerms;

// Generate Auditor ID
$newAuditorID = generateAuditorID();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $memberID = $_POST['member_id'];
    $term = $_POST['term'];
    $auditorID = $_POST['auditor_id'];

    // Validate inputs
    $errors = [];
    
    if (empty($memberID)) {
        $errors[] = "Member selection is required";
    }
    
    // Check if member is already an auditor
    $checkAuditorQuery = "SELECT * FROM Auditor WHERE MemberID = '$memberID' AND isActive = 1";
    $checkAuditorResult = search($checkAuditorQuery);
    if ($checkAuditorResult && $checkAuditorResult->num_rows > 0) {
        $errors[] = "This member is already an auditor";
    }

    // Check if there's already an active auditor for this term
    $activeAuditorQuery = "SELECT * FROM Auditor WHERE Term = '$term' AND isActive = 1";
    $activeAuditorResult = search($activeAuditorQuery);
    if ($activeAuditorResult && $activeAuditorResult->num_rows > 0) {
        $activeAuditor = $activeAuditorResult->fetch_assoc();
        $errors[] = "There is already an active auditor for this term. Please deactivate the existing auditor first.";
    }

    // Check if member is already a treasurer for the same term
    $checkTreasurerQuery = "SELECT * FROM Treasurer WHERE MemberID = '$memberID' AND Term = '$term' AND isActive = 1";
    $checkTreasurerResult = search($checkTreasurerQuery);
    if ($checkTreasurerResult && $checkTreasurerResult->num_rows > 0) {
        $errors[] = "This member is already a treasurer for this term. Same member cannot be both auditor and treasurer for the same year.";
    }

    // Get member details
    $memberQuery = "SELECT Name FROM Member WHERE MemberID = '$memberID'";
    $memberResult = search($memberQuery);
    
    if (!$memberResult || $memberResult->num_rows == 0) {
        $errors[] = "Invalid member selected";
    } else {
        $memberData = $memberResult->fetch_assoc();
        $name = $memberData['Name'];
    }

    // Validate term
    $termQuery = "SELECT * FROM Static WHERE year = '$term'";
    $termResult = search($termQuery);
    if (!$termResult || $termResult->num_rows == 0) {
        $errors[] = "Invalid term selected";
    }

    // If no errors, proceed with insertion
    if (empty($errors)) {
        $insertQuery = "INSERT INTO Auditor (AuditorID, Name, Term, isActive, MemberID) 
                        VALUES ('$auditorID', '$name', '$term', 1, '$memberID')";
        
        try {
            iud($insertQuery);
            $_SESSION['success_message'] = "Auditor added successfully!";
            header("Location: auditorDetails.php");
            exit();
        } catch(Exception $e) {
            $_SESSION['error_message'] = "Error adding auditor: " . $e->getMessage();
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
if (!$membersResult || $membersResult->num_rows == 0) {
    $_SESSION['error_message'] = "No members found in the system.";
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
            /* padding-top: 1rem;
            border-top: 1px solid #eee; */
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
                <h2 class="form-title" style="text-align: left; margin-bottom: 2rem; color:#1a237e;">Add New Auditor</h2>

                <form method="POST">
                    <div class="form-group">
                        <label for="auditor_id">Auditor ID</label>
                        <input type="text" id="auditor_id" name="auditor_id" 
                               value="<?php echo htmlspecialchars($newAuditorID); ?>" 
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
                                    $termCheckQuery = "SELECT Name FROM Auditor WHERE Term = '{$currentActiveTerm['year']}' AND isActive = 1";
                                    $termCheckResult = search($termCheckQuery);
                                    if ($termCheckResult && $termCheckResult->num_rows > 0) {
                                        $existingAuditor = $termCheckResult->fetch_assoc();
                                        // echo ' <span class="text-warning">(Active Auditor: ' . htmlspecialchars($existingAuditor['Name']) . ')</span>';
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
                        <a href="auditorDetails.php" class="cancel-btn">Cancel</a>
                        <button type="submit" class="save-btn" 
                                <?php echo (count($availableTerms) == 0) ? 'disabled' : ''; ?>>
                            Add Auditor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        // Initialize Select2 for member and term dropdowns
        $('.member-select').select2({
            placeholder: 'Select or search for a member...',
            allowClear: true,
            width: '100%'
        });

        // // Custom rendering for term select to show status
        // $('#term').select2({
        //     templateResult: function(state) {
        //         if (!state.id) { return state.text; }
        //         var $state = $('<span>' + state.text + 
        //             (state.element.getAttribute('data-status') === 'active' ? 
        //             ' <span class="term-status">(Active)</span>' : 
        //             ' <span class="term-status">(Inactive)</span>') + 
        //         '</span>');
        //         return $state;
        //     },
        //     templateSelection: function(state) {
        //         return state.text;
        //     }
        // });
    });
    </script>
</body>
</html>