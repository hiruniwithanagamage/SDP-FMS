<?php
session_start();
require_once "../../config/database.php";

// Function to generate a new welfare ID
function generateFineID() {
    global $conn; // Using the global connection from database.php
    
    // Get the current active term from static table
    $termStmt = prepare("SELECT year FROM Static WHERE status = 'active'");
    $termStmt->execute();
    $termResult = $termStmt->get_result();
    
    if (!$termResult || $termResult->num_rows === 0) {
        throw new Exception("No active term found in the system");
    }
    
    $termData = $termResult->fetch_assoc();
    $term = $termData['year'];
    $termStmt->close();
    
    // Extract last 2 digits of the term
    $termSuffix = substr($term, -2);
    
    // Check for existing FineIDs with the same term prefix to determine next sequence
    $prefix = "FIN" . $termSuffix;
    $likePattern = $prefix . "%";
    
    // Get the maximum sequential number used for this term
    $maxIdStmt = prepare("SELECT MAX(CAST(SUBSTRING(FineID, LENGTH(?) + 1) AS UNSIGNED)) as max_seq 
                          FROM Fine 
                          WHERE FineID LIKE ?");
    $maxIdStmt->bind_param("ss", $prefix, $likePattern);
    $maxIdStmt->execute();
    $maxIdResult = $maxIdStmt->get_result();
    $maxSeqRow = $maxIdResult->fetch_assoc();
    $maxIdStmt->close();
    
    // If we have existing records, use the next sequence number
    if ($maxSeqRow && !is_null($maxSeqRow['max_seq'])) {
        $nextSeq = (int)$maxSeqRow['max_seq'] + 1;
    } else {
        // Otherwise start with 1
        $nextSeq = 1;
    }
    
    // Format with leading zero for single digits
    $sequentialNumber = ($nextSeq < 10) ? "0" . $nextSeq : $nextSeq;
    
    // Create the FineID
    $fineID = $prefix . $sequentialNumber;
    
    // Verify the ID doesn't already exist (just to be safe)
    $checkIdStmt = prepare("SELECT COUNT(*) as exists_count FROM Fine WHERE FineID = ?");
    $checkIdStmt->bind_param("s", $fineID);
    $checkIdStmt->execute();
    $checkIdResult = $checkIdStmt->get_result();
    $idExists = $checkIdResult->fetch_assoc()['exists_count'] > 0;
    $checkIdStmt->close();
    
    // If the ID exists (very unlikely but possible with concurrent operations)
    // recursively call this function to get the next available ID
    if ($idExists) {
        return generateFineID();
    }
    
    return $fineID;
}

// Get current term from Static table
$termStmt = prepare("SELECT * FROM Static WHERE status = ?");
$activeStatus = 'active';
$termStmt->bind_param("s", $activeStatus);
$termStmt->execute();
$termResult = $termStmt->get_result();
$termData = $termResult->fetch_assoc();
$termStmt->close();

// Check if term data exists
if (!$termData) {
    $_SESSION['error_message'] = "No active term data found. Please set up a term first.";
    header("Location: home-treasurer.php");
    exit();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $amount = $_POST['amount'];
    $date = $_POST['date'];
    $term = $termData['year']; // Use the active term from Static table
    $description = $_POST['description'];
    $memberID = $_POST['memberID'];
    // Set IsPaid to 'No' by default
    $isPaid = 'No';

    try {
        // Generate the fine ID using our function
        $fineID = generateFineID();
        
        // Check if a fine of the same type already exists for this member in the current month and term
        if ($description == 'late' || $description == 'absent') {
            $dateMonth = date('m', strtotime($date));
            $dateYear = date('Y', strtotime($date));
            
            $checkDuplicateStmt = prepare("SELECT COUNT(*) as count FROM Fine 
                                          WHERE Member_MemberID = ? 
                                          AND Description = ? 
                                          AND Term = ? 
                                          AND MONTH(Date) = ? 
                                          AND YEAR(Date) = ?");
            $checkDuplicateStmt->bind_param("ssiss", 
                $memberID, 
                $description, 
                $term, 
                $dateMonth, 
                $dateYear
            );

            $checkDuplicateStmt->execute();
            $duplicateResult = $checkDuplicateStmt->get_result();
            $duplicateCount = $duplicateResult->fetch_assoc()['count'];
            $checkDuplicateStmt->close();
            
            if ($duplicateCount > 0) {
                $fineTypeText = ($description == 'late') ? 'late' : 'absent';
                $_SESSION['error_message'] = "This member already has a $fineTypeText fine for this month in the selected term.";
                header("Location: addFine.php");
                exit();
            }
        }

        $stmt = prepare("INSERT INTO Fine (FineID, Amount, Date, Term, Description, Member_MemberID, IsPaid) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)");
                        
        // Bind parameters
        $stmt->bind_param("sdsisss", 
            $fineID, 
            $amount, 
            $date, 
            $term, 
            $description, 
            $memberID, 
            $isPaid
        ); 
        
        // Execute statement
        $stmt->execute();
        $stmt->close();
        
        // Store for the confirmation modal
        $_SESSION['fine_success'] = true;
        $_SESSION['fine_id'] = $fineID;
        $_SESSION['fine_member_id'] = $memberID;
        $_SESSION['fine_amount'] = $amount;
        $_SESSION['fine_description'] = $description;
        
        $_SESSION['success_message'] = "Fine added successfully!";
        // Redirect back to this page to show the modal
        header("Location: addFine.php");
        exit();
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error adding fine: " . $e->getMessage();
    }
}

// Get all members for dropdown
$membersStmt = prepare("SELECT MemberID, Name FROM Member ORDER BY Name");
$membersStmt->execute();
$membersResult = $membersStmt->get_result();
$membersStmt->close();

// Check if we have members
if ($membersResult->num_rows == 0) {
    $_SESSION['error_message'] = "No active members found in the system.";
}

// Check if we need to show the confirmation modal
$showModal = false;
if (isset($_SESSION['fine_success']) && $_SESSION['fine_success']) {
    $showModal = true;
    $fineID = $_SESSION['fine_id'];
    $memberID = $_SESSION['fine_member_id'];
    $amount = $_SESSION['fine_amount'];
    $description = $_SESSION['fine_description'];
    
    // Clear the session variables
    unset($_SESSION['fine_success']);
    unset($_SESSION['fine_id']);
    unset($_SESSION['fine_member_id']);
    unset($_SESSION['fine_amount']);
    unset($_SESSION['fine_description']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Fine</title>
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
        }
        .select2-container .select2-selection--single {
            height: 38px;
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
        .select2-dropdown {
            border: 1px solid #ced4da;
        }
        .select2-search__field {
            border: 1px solid #e0e0e0 !important;
            padding: 4px 8px !important;
        }
        .select2-results__option {
            padding: 8px 12px;
        }
        .form-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            margin: 2rem auto;
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: #1e3c72;
            font-weight: 500;
        }

        .back-btn:hover {
            color: #2a5298;
        }

        .form-title {
            font-size: 1.5rem;
            color: #1e3c72;
            margin: 0;
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
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
        .form-group select {
            flex: 1;
        }
        
        /* Style for auto-filled fields */
        input[readonly], 
        input[disabled],
        select[readonly], 
        select[disabled] {
            background-color: #f2f2f2;
            cursor: not-allowed;
        }
        
        /* Ensure Select2 container aligns properly */
        .form-group .select2-container {
            flex: 1;
            margin-right: 50px;
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
        }
        
        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            max-width: 500px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .success-icon {
            color: #28a745;
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }
        
        .modal-btn {
            padding: 10px 20px;
            border-radius: 4px;
            font-weight: 500;
            cursor: pointer;
            border: none;
        }
        
        .btn-primary {
            background-color: #1e3c72;
            color: white;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-treasurer.php'; ?>
        
        <div class="container">
            <?php if(isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <div class="form-header">
                    <h2 class="form-title" style="text-align: right;">Add New Fine</h2>
                </div>

                <form method="POST">
                    <div class="form-group">
                        <label for="memberName">Member Name</label>
                        <select id="memberName" class="member-select" required>
                            <option value="">Select Member</option>
                            <?php 
                            if($membersResult && $membersResult->num_rows > 0):
                                // Reset the pointer to the beginning
                                $membersResult->data_seek(0);
                                while($member = $membersResult->fetch_assoc()): 
                            ?>
                                <option value="<?php echo htmlspecialchars($member['MemberID']); ?>" 
                                        data-id="<?php echo htmlspecialchars($member['MemberID']); ?>">
                                    <?php echo htmlspecialchars($member['Name']); ?>
                                </option>
                            <?php 
                                endwhile; 
                            endif;
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="memberID">Member ID</label>
                        <input type="text" id="memberID" name="memberID" required readonly>
                    </div>

                    <div class="form-group">
                        <label for="date">Date</label>
                        <input type="date" id="date" name="date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="term">Term</label>
                        <input type="number" id="term" name="term" required value="<?php echo $termData['year']; ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="description">Fine Type</label>
                        <select id="description" name="description" required>
                            <option value="">Select Fine Type</option>
                            <option value="late">Late (Rs. <?php echo $termData['late_fine']; ?>)</option>
                            <option value="absent">Absent (Rs. <?php echo $termData['absent_fine']; ?>)</option>
                            <option value="violation">Rules Violation (Rs. <?php echo $termData['rules_violation_fine']; ?>)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="amount">Fine Amount (Rs.)</label>
                        <input type="number" id="amount" name="amount" required step="0.01" readonly>
                    </div>

                    <div class="form-footer">
                        <a href="home-treasurer.php" class="cancel-btn">Cancel</a>
                        <button type="submit" class="save-btn">Add Fine</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Success Modal -->
    <div id="successModal" class="modal" <?php echo $showModal ? 'style="display:block"' : ''; ?>>
        <div class="modal-content">
            <i class="fas fa-check-circle success-icon"></i>
            <h2>Fine Added Successfully</h2>
            <p>Would you like to process the payment for this fine now?</p>
            
            <div class="modal-buttons">
                <button id="payNowBtn" class="modal-btn btn-primary">Yes, Process Payment Now</button>
                <button id="payLaterBtn" class="modal-btn btn-secondary">No, I'll Do It Later</button>
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
            
            // Update Member ID field when a member is selected
            $('#memberName').on('change', function() {
                const selectedMemberId = $(this).val();
                if (selectedMemberId) {
                    $('#memberID').val(selectedMemberId);
                } else {
                    $('#memberID').val('');
                }
            });
            
            // Auto-fill amount based on fine type
            $('#description').on('change', function() {
                const fineType = $(this).val();
                let amount = 0;
                
                switch(fineType) {
                    case 'late':
                        amount = <?php echo $termData['late_fine']; ?>;
                        break;
                    case 'absent':
                        amount = <?php echo $termData['absent_fine']; ?>;
                        break;
                    case 'violation':
                        amount = <?php echo $termData['rules_violation_fine']; ?>;
                        break;
                }
                
                $('#amount').val(amount);
            });

            // Form validation
            $('form').on('submit', function(e) {
                const amount = $('#amount').val();
                const memberID = $('#memberID').val();
                
                if (!amount || parseFloat(amount) <= 0) {
                    e.preventDefault();
                    alert('Please select a fine type to determine the amount.');
                }
                
                if (!memberID) {
                    e.preventDefault();
                    alert('Please select a member.');
                }
            });
            
            // Modal buttons
            $('#payNowBtn').on('click', function() {
                <?php if(isset($memberID)): ?>
                window.location.href = 'treasurerPayment.php?member_id=<?php echo $memberID; ?>';
                <?php endif; ?>
            });
            
            $('#payLaterBtn').on('click', function() {
                $('#successModal').hide();
                window.location.href = 'home-treasurer.php';
            });
        });
    </script>
</body>
</html>