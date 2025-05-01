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

// Function to simulate SMS sending for XAMPP testing
function sendEmailToSMS($phone, $message) {
    // Clean the phone number
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Ensure it's a 10-digit number
    if (strlen($phone) === 10 && $phone[0] === '0') {
        // Valid Sri Lankan number
    } elseif (strlen($phone) === 9) {
        // Add 0 prefix if missing
        $phone = '0' . $phone;
    } else {
        // Invalid number format
        return false;
    }
    
    // Determine carrier based on phone number prefix
    $prefix = substr($phone, 0, 3);
    $carrier = '';
    $email_domain = '';
    
    if(in_array($prefix, ['071', '077'])) {
        $carrier = 'Dialog';
        $email_domain = '@sms.dialog.lk';
    } elseif(in_array($prefix, ['072', '070'])) {
        $carrier = 'Mobitel';
        $email_domain = '@sms.mobitel.lk';
    } elseif(in_array($prefix, ['075', '078'])) {
        $carrier = 'Etisalat';
        $email_domain = '@sms.etisalat.lk';
    } elseif(in_array($prefix, ['076'])) {
        $carrier = 'Hutch';
        $email_domain = '@sms.hutch.lk';
    } else {
        $carrier = 'Unknown';
    }
    
    // Create log directory if it doesn't exist - let's fix this part
    $logDir = __DIR__ . '../../../sms_logs';
    
    // Debug: Log directory creation attempt
    error_log("Attempting to create directory: " . $logDir);
    
    if (!file_exists($logDir)) {
        if(!mkdir($logDir, 0777, true)) {
            error_log("Failed to create directory: " . $logDir);
            // Fallback to current directory if mkdir fails
            $logDir = __DIR__;
        } else {
            error_log("Successfully created directory: " . $logDir);
        }
    } else {
        error_log("Directory already exists: " . $logDir);
    }
    
    // Log file path
    $logFile = $logDir . '/sms_log_' . date('Y-m-d') . '.txt';
    
    // Prepare log message
    $logMessage = str_repeat("-", 50) . "\n";
    $logMessage .= "Time: " . date('Y-m-d H:i:s') . "\n";
    $logMessage .= "Phone: " . $phone . " (" . $carrier . ")\n";
    $logMessage .= "Email: " . $phone . $email_domain . "\n";
    $logMessage .= "Message: " . $message . "\n";
    $logMessage .= str_repeat("-", 50) . "\n\n";
    
    // Write to log file
    if(file_put_contents($logFile, $logMessage, FILE_APPEND) === false) {
        error_log("Failed to write to log file: " . $logFile);
    } else {
        error_log("Successfully wrote to log file: " . $logFile);
    }
    
    // Show in browser for debugging
    if(isset($_SESSION['debug_mode']) && $_SESSION['debug_mode'] === true) {
        echo "<div style='background: #e8f5e9; border: 1px solid #4caf50; padding: 15px; margin: 10px; border-radius: 5px;'>";
        echo "<h4 style='margin: 0 0 10px 0; color: #2e7d32;'>SMS Simulation</h4>";
        echo "<strong>To:</strong> " . $phone . " (" . $carrier . ")<br>";
        echo "<strong>Gateway:</strong> " . $email_domain . "<br>";
        echo "<strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "<br>";
        echo "<small style='color: #666;'>This message would be sent when hosted on a real server.</small>";
        echo "</div>";
    }
    
    return true;
}

// Function to get active treasurer's phone number
function getActiveTreasurerPhone() {
    // First, get the active treasurer
    $query = "SELECT MemberID FROM Treasurer WHERE isActive = 1 LIMIT 1";
    $result = search($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $memberID = $row['MemberID'];
        
        // Now get the phone number from the Member table
        $phoneQuery = "SELECT Mobile_Number FROM Member WHERE MemberID = ?";
        $stmt = prepare($phoneQuery);
        $stmt->bind_param("s", $memberID);
        $stmt->execute();
        $phoneResult = $stmt->get_result();
        
        if ($phoneResult && $phoneResult->num_rows > 0) {
            $phoneRow = $phoneResult->fetch_assoc();
            return $phoneRow['Mobile_Number'];
        }
    }
    
    // If no active treasurer found, return null
    return null;
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

// Get welfare amount from static table
function getWelfareAmount() {
    $query = "SELECT death_welfare FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $result = search($query);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['death_welfare'];
    }
    return 10000.00; // Default fallback value if no settings found
}

// Define relationship options
$relationships = [
    'dog' => 'Dog',
    'mother' => 'Mother',
    'father' => 'Father',
    'child' => 'Child',
    'sibling' => 'Sibling',
    'self' => 'Self'
];

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
    
    // Get amount from settings
    $amount = getWelfareAmount();
    
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
                
                // Enable debug mode for testing
                $_SESSION['debug_mode'] = true;
                
                // Get treasurer's phone number from database
                $treasurerPhone = getActiveTreasurerPhone();
                
                if($treasurerPhone) {
                    // Prepare the SMS message
                    $smsMessage = "New Death Welfare Application\n";
                    $smsMessage .= "ID: " . $newWelfareId . "\n";
                    $smsMessage .= "Member: " . $memberName . " (" . $memberId . ")\n";
                    $smsMessage .= "Relationship: " . $relationships[$relationship] . "\n";
                    $smsMessage .= "Amount: Rs." . number_format($amount, 2) . "\n";
                    $smsMessage .= "Date: " . date('Y-m-d') . "\n";
                    $smsMessage .= "From: Society Management System";
                    
                    // Send SMS (simulated in XAMPP)
                    sendEmailToSMS($treasurerPhone, $smsMessage);
                }
                
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

// Get welfare amount for display
$welfareAmount = getWelfareAmount();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Death Welfare Application</title>
    <link rel="stylesheet" href="../../assets/css/applyLWF.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .info-box {
            background-color: #e7f3ff;
            border: 1px solid #b8daff;
            color: #004085;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .info-box h3 {
            margin-top: 0;
            font-size: 16px;
        }
        .welfare-amount {
            font-weight: bold;
            font-size: 18px;
            color: #1e3c72;
        }
    </style>
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
            <?php else: ?>
                <div class="info-box">
                    <h3>Death Welfare Benefit Information</h3>
                    <p>The death welfare benefit amount is currently set at <span class="welfare-amount">Rs. <?php echo number_format($welfareAmount, 2); ?></span> according to organization policy.</p>
                    <p>This benefit is available to members who have experienced a death in their immediate family.</p>
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
                    <select id="relationship" name="relationship" required>
                        <option value="">-- Select Relationship --</option>
                        <?php foreach($relationships as $key => $value): ?>
                            <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                        <?php endforeach; ?>
                    </select>
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
                errors.push("Please select the relationship to the deceased person");
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