<?php
session_start();
require_once "../../config/database.php";

// Generate new Welfare ID
$query = "SELECT WelfareID FROM DeathWelfare ORDER BY WelfareID DESC LIMIT 1";
$result = search($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $lastId = $row['WelfareID'];
    $numericPart = preg_replace('/[^0-9]/', '', $lastId);
    $newNumericPart = intval($numericPart) + 1;
    $newWelfareId = "WF" . str_pad($newNumericPart, 3, '0', STR_PAD_LEFT);
} else {
    $newWelfareId = "WF001";
}

// Check for pending applications first
function hasPendingApplication($memberId, $term) {
    $checkQuery = "SELECT * FROM DeathWelfare 
                  WHERE Member_MemberID = ? 
                  AND Term = ? 
                  AND Status = 'pending'";
    $stmt = prepare($checkQuery);
    $stmt->bind_param("ss", $memberId, $term);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Handle form submission
if(isset($_POST['apply'])) {
    $memberId = $_POST['member_id'];
    $date = $_POST['date'];
    $term = date('Y'); // Current year as term
    $relationship = $_POST['relationship'];
    
    // Initialize errors array
    $errors = [];
    
    // Check if member already has an approved death welfare in the current year
    $checkQuery = "SELECT * FROM DeathWelfare 
                  WHERE Member_MemberID = ? 
                  AND Term = ? 
                  AND Status = 'approved'";
    $stmt = prepare($checkQuery);
    $stmt->bind_param("ss", $memberId, $term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0) {
        $errors[] = "You already have an approved death welfare application for this year";
    }
    
    // Default amount based on company policy
    $amount = 10000.00;
    
    // Validate inputs
    if(empty($memberId)) $errors[] = "Member ID is required";
    if(empty($date)) $errors[] = "Date is required";
    if(empty($relationship)) $errors[] = "Relationship to the deceased is required";
    
    // Validate date format and check if it's not in the future
    if (!empty($date)) {
        $inputDate = new DateTime($date);
        $today = new DateTime();
        if ($inputDate > $today) {
            $errors[] = "Date cannot be in the future";
        }
    }
    
    if(empty($errors)) {
        // Prepare SQL insert statement
        $query = "INSERT INTO DeathWelfare (WelfareID, Amount, Date, Term, Relationship, Member_MemberID, Status, Expense_ExpenseID) 
                  VALUES (?, ?, ?, ?, ?, ?, 'pending', NULL)";
                  
        try {
            $stmt = prepare($query);
            $stmt->bind_param("sdssss", 
                $newWelfareId,
                $amount,
                $date,
                $term,
                $relationship,
                $memberId
            );
            
            if($stmt->execute()) {
                $_SESSION['success_message'] = "Death welfare application submitted successfully";
                header("Location: home-member.php");
                exit();
            } else {
                throw new Exception($stmt->error);
            }
        } catch(Exception $e) {
            $error = "Error submitting application: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get member details and check existing applications if logged in
$memberName = "";
$memberId = "";
$hasExistingApplication = false;
$existingApplicationStatus = "";

if(isset($_SESSION['member_id'])) {
    // Check for existing applications in current year
    $currentYear = date('Y');
    $checkQuery = "SELECT Status FROM DeathWelfare 
                  WHERE Member_MemberID = ? 
                  AND Term = ?
                  AND (Status = 'approved' OR Status = 'pending')";
    $stmt = prepare($checkQuery);
    $stmt->bind_param("ss", $_SESSION['member_id'], $currentYear);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows > 0) {
        $hasExistingApplication = true;
        $row = $result->fetch_assoc();
        $existingApplicationStatus = $row['Status'];
    }
    
    // Get member details
    $query = "SELECT MemberID, Name FROM Member WHERE MemberID = ?";
    $stmt = prepare($query);
    $stmt->bind_param("s", $_SESSION['member_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows > 0) {
        $member = $result->fetch_assoc();
        $memberName = $member['Name'];
        $memberId = $member['MemberID'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Death Welfare Application</title>
    <link rel="stylesheet" href="../../assets/css/applyLWF.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-member.php'; ?>
        
        <div class="container">
            <h1>Death Welfare Application</h1>
            
            <?php if(isset($error)): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if($hasExistingApplication): ?>
                <div class="alert alert-info">
                    You already have a death welfare application for this year (Status: <?php echo ucfirst($existingApplicationStatus); ?>)
                </div>
            <?php endif; ?>

            <form method="POST" action="" <?php echo $hasExistingApplication ? 'style="display: none;"' : ''; ?>>
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Name</label>
                        <input type="text" id="name" value="<?php echo htmlspecialchars($memberName); ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="member_id">Member ID</label>
                        <input type="text" id="member_id" name="member_id" value="<?php echo htmlspecialchars($memberId); ?>" readonly>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="date">Date</label>
                        <input type="date" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="term">Term</label>
                        <input type="text" id="term" value="<?php echo date('Y'); ?>" readonly>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="relationship">Relationship to the deceased person</label>
                    <input type="text" id="relationship" name="relationship" required>
                </div>
                
                <div class="terms-group">
                    <input type="checkbox" id="terms" required>
                    <label for="terms">I confirm that all provided information is true and accurate</label>
                </div>
                
                <div class="button-group">
                    <button type="submit" name="apply" class="btn btn-apply">Apply</button>
                    <button type="button" onclick="window.location.href='home-member.php'" class="btn btn-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const relationship = document.getElementById('relationship').value.trim();
            const terms = document.getElementById('terms').checked;
            const date = document.getElementById('date').value;
            
            let errors = [];
            
            if (!relationship) {
                errors.push("Please enter the relationship to the deceased person");
            }
            
            if (!date) {
                errors.push("Please select a date");
            }
            
            if (!terms) {
                errors.push("Please confirm the information accuracy");
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                const errorContainer = document.createElement('div');
                errorContainer.className = 'alert alert-error';
                errorContainer.innerHTML = errors.join('<br>');
                
                const existingError = document.querySelector('.alert-error');
                if (existingError) {
                    existingError.remove();
                }
                
                document.querySelector('h1').insertAdjacentElement('afterend', errorContainer);
            }
        });
    </script>
</body>
</html>