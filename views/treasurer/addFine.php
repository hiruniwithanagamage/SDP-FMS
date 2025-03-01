<?php
session_start();
require_once "../../config/database.php";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Generate sequential Fine ID (Fine001, Fine002, etc.)
    $countQuery = "SELECT COUNT(*) as count FROM Fine";
    $countResult = search($countQuery);
    $fineCount = $countResult->fetch_assoc()['count'] + 1;
    $fineID = "Fine" . str_pad($fineCount, 3, "0", STR_PAD_LEFT);
    
    $amount = $_POST['amount'];
    $date = $_POST['date'];
    $term = $_POST['term'];
    $description = $_POST['description'];
    $memberID = $_POST['memberID'];
    $isPaid = $_POST['isPaid'];

    $insertQuery = "INSERT INTO Fine (FineID, Amount, Date, Term, Description, Member_MemberID, IsPaid) 
                    VALUES ('$fineID', '$amount', '$date', '$term', '$description', '$memberID', '$isPaid')";
    
    try {
        Database::iud($insertQuery);
        $_SESSION['success_message'] = "Fine added successfully!";
        header("Location: home-treasurer.php");
        exit();
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error adding fine: " . $e->getMessage();
    }
}

// Get current term
$termQuery = "SELECT * FROM Static ORDER BY year DESC LIMIT 1";
$termResult = search($termQuery);
$termData = $termResult->fetch_assoc();

// Check if term data exists
if (!$termData) {
    $_SESSION['error_message'] = "No term data found. Please set up a term first.";
}

// Get all members for dropdown
$membersQuery = "SELECT MemberID, Name FROM Member";
$membersResult = search($membersQuery);

// Check if we have members
if ($membersResult->num_rows == 0) {
    $_SESSION['error_message'] = "No active members found in the system.";
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
    <link rel="stylesheet" href="../../assets/css/adminActorDetails.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
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

                    <div class="form-group">
                        <label for="isPaid">Payment Status</label>
                        <select id="isPaid" name="isPaid" required>
                            <option value="No" selected>Unpaid</option>
                            <option value="Yes">Paid</option>
                        </select>
                    </div>

                    <div class="form-footer">
                        <a href="fineDetails.php" class="cancel-btn">Cancel</a>
                        <button type="submit" class="save-btn">Add Fine</button>
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
        });
    </script>
</body>
</html>