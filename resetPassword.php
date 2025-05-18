<?php
session_start();

date_default_timezone_set('Asia/Colombo');

// Clear password reset flag when directly accessing the page
if (!isset($_GET['reset']) && basename($_SERVER['PHP_SELF']) == 'reset_password.php') {
    unset($_SESSION['password_reset']);
}


// Clear all reset session variables if "start over" is requested
if (isset($_GET['reset']) && $_GET['reset'] == 'true') {
    unset($_SESSION['reset_requested']);
    unset($_SESSION['code_verified']);
    unset($_SESSION['reset_token']);
    // Redirect to the same page without parameters
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}

require_once "config/database.php";

// Function to generate secure password reset token
function generatePasswordResetToken() {
    return bin2hex(random_bytes(32));
}

// Function to simulate SMS sending (same as in your death welfare application)
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
    
    // Create log directory if it doesn't exist
    $logDir = __DIR__ . '/sms_logs';
    
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
    // if(isset($_SESSION['debug_mode']) && $_SESSION['debug_mode'] === true) {
    //     echo "<div style='background: #e8f5e9; border: 1px solid #4caf50; padding: 15px; margin: 10px; border-radius: 5px;'>";
    //     echo "<h4 style='margin: 0 0 10px 0; color: #2e7d32;'>SMS Simulation</h4>";
    //     echo "<strong>To:</strong> " . $phone . " (" . $carrier . ")<br>";
    //     echo "<strong>Gateway:</strong> " . $email_domain . "<br>";
    //     echo "<strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "<br>";
    //     echo "<small style='color: #666;'>This message would be sent when hosted on a real server.</small>";
    //     echo "</div>";
    // }
    
    return true;
}

// Function to initiate password reset via SMS for all user types
function initiatePasswordReset($username) {
    // Check if username exists
    $query = "SELECT UserId, Username, Member_MemberID, Admin_AdminID, Treasurer_TreasurerID, Auditor_AuditorID 
              FROM User WHERE Username = ?";
    $stmt = prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $token = generatePasswordResetToken();
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Determine user type and get corresponding phone number
        $phone = null;
        
        if (!empty($user['Member_MemberID'])) {
            // Get member's phone number
            $phoneQuery = "SELECT Mobile_Number FROM Member WHERE MemberID = ?";
            $phoneStmt = prepare($phoneQuery);
            $phoneStmt->bind_param("s", $user['Member_MemberID']);
            $phoneStmt->execute();
            $phoneResult = $phoneStmt->get_result();
            
            if ($phoneResult->num_rows == 1) {
                $userData = $phoneResult->fetch_assoc();
                $phone = $userData['Mobile_Number'];
            }
        } 
        elseif (!empty($user['Admin_AdminID'])) {
            // Get admin's phone number
            $phoneQuery = "SELECT Contact_Number FROM Admin WHERE AdminID = ?";
            $phoneStmt = prepare($phoneQuery);
            $phoneStmt->bind_param("s", $user['Admin_AdminID']);
            $phoneStmt->execute();
            $phoneResult = $phoneStmt->get_result();
            
            if ($phoneResult->num_rows == 1) {
                $userData = $phoneResult->fetch_assoc();
                $phone = $userData['Contact_Number'];
            }
        }
        elseif (!empty($user['Treasurer_TreasurerID'])) {
            // For Treasurer, we need to get their MemberID first, then get the phone number
            $memberQuery = "SELECT MemberID FROM Treasurer WHERE TreasurerID = ?";
            $memberStmt = prepare($memberQuery);
            $memberStmt->bind_param("s", $user['Treasurer_TreasurerID']);
            $memberStmt->execute();
            $memberResult = $memberStmt->get_result();
            
            if ($memberResult->num_rows == 1) {
                $treasurerData = $memberResult->fetch_assoc();
                $memberID = $treasurerData['MemberID'];
                
                // Now get the phone number using the MemberID
                $phoneQuery = "SELECT Mobile_Number FROM Member WHERE MemberID = ?";
                $phoneStmt = prepare($phoneQuery);
                $phoneStmt->bind_param("s", $memberID);
                $phoneStmt->execute();
                $phoneResult = $phoneStmt->get_result();
                
                if ($phoneResult->num_rows == 1) {
                    $userData = $phoneResult->fetch_assoc();
                    $phone = $userData['Mobile_Number'];
                }
            }
        }
        elseif (!empty($user['Auditor_AuditorID'])) {
            // For Auditor, we need to get their MemberID first, then get the phone number
            $memberQuery = "SELECT MemberID FROM Auditor WHERE AuditorID = ?";
            $memberStmt = prepare($memberQuery);
            $memberStmt->bind_param("s", $user['Auditor_AuditorID']);
            $memberStmt->execute();
            $memberResult = $memberStmt->get_result();
            
            if ($memberResult->num_rows == 1) {
                $auditorData = $memberResult->fetch_assoc();
                $memberID = $auditorData['MemberID'];
                
                // Now get the phone number using the MemberID
                $phoneQuery = "SELECT Mobile_Number FROM Member WHERE MemberID = ?";
                $phoneStmt = prepare($phoneQuery);
                $phoneStmt->bind_param("s", $memberID);
                $phoneStmt->execute();
                $phoneResult = $phoneStmt->get_result();
                
                if ($phoneResult->num_rows == 1) {
                    $userData = $phoneResult->fetch_assoc();
                    $phone = $userData['Mobile_Number'];
                }
            }
        }
        
        if (!empty($phone)) {
            // Store token and expiry
            $updateQuery = "UPDATE User SET reset_token = ?, reset_expires = ? WHERE UserId = ?";
            $updateStmt = prepare($updateQuery);
            $updateStmt->bind_param("sss", $token, $expiry, $user['UserId']);
            
            if ($updateStmt->execute()) {
                // Enable debug mode for testing
                $_SESSION['debug_mode'] = true;
                
                // Create SMS message with token
                $smsMessage = "Password Reset Request\n";
                $smsMessage .= "Username: " . $user['Username'] . "\n";
                $smsMessage .= "Reset Code: " . substr($token, 0, 4) . "\n";
                $smsMessage .= "Valid for: 1 hour\n";
                $smsMessage .= "From: Society Management System";
                
                // Send SMS
                if (sendEmailToSMS($phone, $smsMessage)) {
                    return [
                        'status' => 'success',
                        'message' => 'Password reset code sent to your mobile number'
                    ];
                } else {
                    return ['status' => 'error', 'message' => 'Failed to send SMS. Please try again.'];
                }
            }
        } else {
            return ['status' => 'error', 'message' => 'No phone number found for this account'];
        }
    }
    
    return ['status' => 'error', 'message' => 'Username not found or invalid'];
}

// Function to reset password with token
function resetPassword($token, $newPassword) {
    // Validate token
    $query = "SELECT UserId, reset_expires FROM User WHERE reset_token = ? OR reset_token LIKE ?";
    $tokenPattern = substr($token, 0, 4) . '%';
    $stmt = prepare($query);
    $stmt->bind_param("ss", $token, $tokenPattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        // Check if token is expired
        if (strtotime($user['reset_expires']) > time()) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Update password and clear reset token
            $updateQuery = "UPDATE User 
                          SET Password = ?, reset_token = NULL, reset_expires = NULL,
                              failed_attempts = 0, locked_until = NULL
                          WHERE UserId = ?";
            $updateStmt = prepare($updateQuery);
            $updateStmt->bind_param("ss", $hashedPassword, $user['UserId']);
            
            if ($updateStmt->execute()) {
                return ['status' => 'success', 'message' => 'Password reset successful'];
            }
        } else {
            return ['status' => 'error', 'message' => 'Reset token has expired'];
        }
    }
    
    return ['status' => 'error', 'message' => 'Invalid reset token'];
}

// Process the form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process form
    if (isset($_POST['reset-submit'])) {
        // Get username
        $username = isset($_POST['username']) ? $_POST['username'] : '';
        if (empty($username)) {
            $error = "Please enter your username";
        } else {
            // Enable debug mode for testing
            $_SESSION['debug_mode'] = true;
            
            // Send SMS code
            $result = initiatePasswordReset($username);
            if ($result['status'] === 'success') {
                $_SESSION['reset_requested'] = true;
                $_SESSION['reset_message'] = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    } 
    // Process verification code
    else if (isset($_POST['verify-submit'])) {
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= isset($_POST["code-$i"]) ? $_POST["code-$i"] : '';
        }
        
        if (strlen($code) !== 4) {
            $verify_error = "Please enter all 4 digits of the verification code";
        } else {
            // Find the token in the database that starts with this code
            $query = "SELECT reset_token FROM User WHERE reset_token LIKE ? AND reset_expires > NOW()";
            $param = $code . '%';
            $stmt = prepare($query);
            $stmt->bind_param("s", $param);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $token_row = $result->fetch_assoc();
                $_SESSION['reset_token'] = $token_row['reset_token'];
                $_SESSION['code_verified'] = true;
            } else {
                $verify_error = "Invalid or expired verification code";
            }
        }
    }
    // Process password reset
    else if (isset($_POST['password-submit'])) {
        $password = isset($_POST['new-password']) ? $_POST['new-password'] : '';
        $confirm = isset($_POST['confirm-password']) ? $_POST['confirm-password'] : '';
        
        if (empty($password) || empty($confirm)) {
            $password_error = "Please fill in all fields";
        } else if ($password !== $confirm) {
            $password_error = "Passwords do not match";
        } else if (strlen($password) < 5) {
            $password_error = "Password must be at least 5 characters long";
        } else if (strlen($password) > 12) {
            $password_error = "Password must not exceed 12 characters";
        }else if (isset($_SESSION['reset_token'])) {
            $result = resetPassword($_SESSION['reset_token'], $password);
            if ($result['status'] === 'success') {
                $_SESSION['password_reset'] = true;
                $_SESSION['reset_message'] = $result['message'];
                
                // Clean up session
                unset($_SESSION['reset_token']);
                unset($_SESSION['code_verified']);
                unset($_SESSION['reset_requested']);
                unset($_SESSION['password_reset']);
                
                // Redirect to login after a delay
                // header("Refresh: 2; URL=index.php");
                // Redirect to the same page without parameters
                header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
            } else {
                $password_error = $result['message'];
            }
        } else {
            $password_error = "Invalid reset process. Please start over.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --primary-color: rgb(21, 21, 99);
            --hover-color: rgb(31, 31, 150);
        }

        body {
            font-family: 'Arial', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: #f4f4f4;
        }

        .container {
            background-color: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            width: 400px;
            text-align: center;
        }

        .logo {
            width: 80px;
            height: 80px;
            margin-bottom: 1rem;
        }

        h2 {
            margin-bottom: 1.5rem;
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #555;
        }

        input {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            box-sizing: border-box;
        }

        input:focus {
            border-color: var(--primary-color);
            outline: none;
        }

        button {
            background-color: var(--primary-color);
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s ease;
        }

        button:hover {
            background-color: var(--hover-color);
        }

        .message {
            margin: 1rem 0;
            padding: 0.8rem;
            border-radius: 5px;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
        }

        .back-link {
            margin-top: 1rem;
            display: inline-block;
            color: var(--primary-color);
            text-decoration: none;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
        }

        .password-container {
            position: relative;
        }
        
        .token-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .token-input {
            width: 40px !important;
            text-align: center;
            font-size: 1.2rem;
            padding: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container animate__animated animate__fadeIn">
        <?php if (isset($_SESSION['password_reset']) && $_SESSION['password_reset']): ?>
            <!-- Success message -->
            <div class="message success">
                <?= htmlspecialchars($_SESSION['reset_message'] ?? 'Password reset successful!') ?>
                <p>Redirecting to login page...</p>
            </div>
            <a href="index.php" class="back-link">Back to Login</a>
        
        <?php elseif (isset($_SESSION['code_verified']) && $_SESSION['code_verified']): ?>
            <!-- Password Reset Form -->
            <h2>Set New Password</h2>
            <?php if (isset($password_error)): ?>
                <div class="message error"><?= htmlspecialchars($password_error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="new-password">New Password</label>
                    <div class="password-container">
                        <input type="password" id="new-password" name="new-password" required>
                        <span class="password-toggle" onclick="togglePassword('new-password', this)">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="confirm-password">Confirm Password</label>
                    <div class="password-container">
                        <input type="password" id="confirm-password" name="confirm-password" required>
                        <span class="password-toggle" onclick="togglePassword('confirm-password', this)">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                    <small>Password must be 5-8 characters long</small>
                </div>
                <button type="submit" name="password-submit">Reset Password</button>
            </form>
            <a href="?reset=true" class="back-link">Start Over</a>
        
        <?php elseif (isset($_SESSION['reset_requested']) && $_SESSION['reset_requested']): ?>
            <!-- Verification Code Form -->
            <h2>Enter Verification Code</h2>
            <p>We've sent a verification code to your mobile number</p>
            
            <?php if (isset($verify_error)): ?>
                <div class="message error"><?= htmlspecialchars($verify_error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Verification Code</label>
                    <div class="token-form">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                            <input type="text" class="token-input" maxlength="1" name="code-<?= $i ?>" 
                                   data-index="<?= $i ?>" onkeyup="moveToNext(this)" required>
                        <?php endfor; ?>
                    </div>
                </div>
                <button type="submit" name="verify-submit">Verify Code</button>
            </form>
            <a href="?reset=true" class="back-link">Start Over</a>
        
        <?php else: ?>
            <!-- Initial Reset Form -->
            <h2>Reset Password</h2>
            <?php if (isset($error)): ?>
                <div class="message error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Enter your username</label>
                    <input type="text" id="username" name="username" required>
                    <small>We'll send a reset code to your registered mobile number</small>
                </div>
                <button type="submit" name="reset-submit">Send Reset Code</button>
            </form>
            <a href="index.php" class="back-link">Back to Login</a>
        <?php endif; ?>
    </div>

    <script>
        function moveToNext(input) {
            const index = parseInt(input.getAttribute('data-index'));
            const maxIndex = 3; // 4 digits - 1 (zero-indexed)
            
            const evt = window.event || event; // Ensure event is defined
            
            // If backspace is pressed, move to previous input
            if (evt.keyCode === 4 && index > 0) {
                const prevInput = document.querySelector(`.token-input[data-index="${index - 1}"]`);
                prevInput.focus();
                return;
            }
            
            // Move to next input if current has value and we're not at the end
            if (input.value && index < maxIndex) {
                const nextInput = document.querySelector(`.token-input[data-index="${index + 1}"]`);
                nextInput.focus();
            }
        }
        
        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            const iconElement = icon.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                iconElement.classList.remove('fa-eye');
                iconElement.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                iconElement.classList.remove('fa-eye-slash');
                iconElement.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>