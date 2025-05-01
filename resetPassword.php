<?php
// Password reset functionality

// Function to generate secure password reset token
function generatePasswordResetToken() {
    return bin2hex(random_bytes(32));
}

// Function to initiate password reset
function initiatePasswordReset($email) {
    // Check if email exists
    $query = "SELECT UserId, Username FROM User WHERE Email = ?";
    $stmt = prepare($query);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $token = generatePasswordResetToken();
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token and expiry
        $updateQuery = "UPDATE User SET reset_token = ?, reset_expires = ? WHERE UserId = ?";
        $updateStmt = prepare($updateQuery);
        $updateStmt->bind_param("sss", $token, $expiry, $user['UserId']);
        
        if ($updateStmt->execute()) {
            // Here you would send an email with the reset link
            // For now, we'll return the token and details
            return [
                'status' => 'success',
                'username' => $user['Username'],
                'token' => $token,
                'reset_link' => "reset_password.php?token=$token"
            ];
        }
    }
    
    return ['status' => 'error', 'message' => 'Email not found'];
}

// Function to reset password with token
function resetPassword($token, $newPassword) {
    // Validate token
    $query = "SELECT UserId, reset_expires FROM User WHERE reset_token = ?";
    $stmt = prepare($query);
    $stmt->bind_param("s", $token);
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
            display: none;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            display: block;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            display: block;
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
    </style>
</head>
<body>
    <div class="container animate__animated animate__fadeIn">
        <!-- Reset Request Form -->
        <div id="resetRequestForm">
            <h2>Reset Password</h2>
            <div class="form-group">
                <label for="email">Enter your email</label>
                <input type="email" id="email" required>
            </div>
            <div id="resetMessage" class="message"></div>
            <button onclick="requestReset()">Send Reset Link</button>
            <a href="index.php" class="back-link">Back to Login</a>
        </div>

        <!-- Password Reset Form (hidden initially) -->
        <div id="resetPasswordForm" style="display: none;">
            <h2>New Password</h2>
            <div class="form-group">
                <label for="newPassword">New Password</label>
                <div class="password-container">
                    <input type="password" id="newPassword" required>
                    <span class="password-toggle" onclick="togglePassword('newPassword', this)">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
            </div>
            <div class="form-group">
                <label for="confirmPassword">Confirm Password</label>
                <div class="password-container">
                    <input type="password" id="confirmPassword" required>
                    <span class="password-toggle" onclick="togglePassword('confirmPassword', this)">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
            </div>
            <div id="passwordMessage" class="message"></div>
            <button onclick="resetPassword()">Reset Password</button>
        </div>
    </div>

    <script>
        // Check if we have a token in the URL
        const urlParams = new URLSearchParams(window.location.search);
        const token = urlParams.get('token');

        if (token) {
            document.getElementById('resetRequestForm').style.display = 'none';
            document.getElementById('resetPasswordForm').style.display = 'block';
        }

        function requestReset() {
            const email = document.getElementById('email').value;
            const messageDiv = document.getElementById('resetMessage');

            if (!email) {
                messageDiv.className = 'message error';
                messageDiv.textContent = 'Please enter your email';
                return;
            }

            fetch('process_reset_request.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `email=${encodeURIComponent(email)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    messageDiv.className = 'message success';
                    messageDiv.textContent = 'Password reset link sent to your email';
                } else {
                    messageDiv.className = 'message error';
                    messageDiv.textContent = data.message;
                }
            })
            .catch(error => {
                messageDiv.className = 'message error';
                messageDiv.textContent = 'An error occurred. Please try again.';
            });
        }

        function resetPassword() {
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const messageDiv = document.getElementById('passwordMessage');

            if (!newPassword || !confirmPassword) {
                messageDiv.className = 'message error';
                messageDiv.textContent = 'Please fill in all fields';
                return;
            }

            if (newPassword !== confirmPassword) {
                messageDiv.className = 'message error';
                messageDiv.textContent = 'Passwords do not match';
                return;
            }

            if (newPassword.length < 5) {
                messageDiv.className = 'message error';
                messageDiv.textContent = 'Password must be at least 5 characters long';
                return;
            }

            fetch('process_password_reset.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `token=${encodeURIComponent(token)}&password=${encodeURIComponent(newPassword)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    messageDiv.className = 'message success';
                    messageDiv.textContent = 'Password reset successful! Redirecting...';
                    setTimeout(() => {
                        window.location.href = 'login.php';
                    }, 2000);
                } else {
                    messageDiv.className = 'message error';
                    messageDiv.textContent = data.message;
                }
            })
            .catch(error => {
                messageDiv.className = 'message error';
                messageDiv.textContent = 'An error occurred. Please try again.';
            });
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