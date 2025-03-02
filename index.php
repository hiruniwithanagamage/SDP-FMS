<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
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
            position: relative;
            background-color: #f4f4f4;
            z-index: 1;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('assets/images/background_society.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 0.5;
            z-index: -1;
            filter: blur(5px);
        }

        .container {
            background-color: rgba(255, 255, 255, 0.95);
            padding: 3rem;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            text-align: center;
            width: 400px;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease;
        }

        .container:hover {
            transform: translateY(-5px);
        }

        .logo {
            width: 120px;
            height: 120px;
            animation: fadeIn 1s ease-in;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        h2 {
            margin-bottom: 2rem;
            color: var(--primary-color);
            font-size: 1.8rem;
            animation: fadeInDown 1s ease-in;
        }

        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #555;
            font-weight: 500;
        }

        input {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(21, 21, 99, 0.1);
        }

        .password-container {
            position: relative;
            width: 100%;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
            z-index: 10;
        }

        .password-toggle:hover {
            color: #1a237e;
        }

        button {
            background-color: var(--primary-color);
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            width: auto;
            min-width: 120px;
            font-weight: 500;
        }

        button:hover {
            background-color: var(--hover-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(21, 21, 99, 0.2);
        }

        .forgot-password {
            display: inline-block;
            margin-top: 1rem;
            color: #666;
            font-size: 0.9rem;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .forgot-password:hover {
            color: var(--primary-color);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message-container {
            margin: 1rem 0;
            padding: 0.5rem;
            border-radius: 5px;
            font-size: 0.9rem;
            display: none;
        }

        .message-error {
            display: block;
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        .message-success {
            display: block;
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
        }
    </style>
</head>
<body>
    <div class="container animate__animated animate__fadeIn">
        <form id="loginForm" method="POST" action="loginProcess.php" onsubmit="event.preventDefault(); login();">
            <img src="assets/images/society_logo.png" alt="Society Logo" class="logo">
            <h2>එක්සත් මරණාධාර සමිතිය</h2>
            
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="u" autocomplete="username">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-container">
                    <input type="password" id="password" name="p" autocomplete="current-password">
                    <span class="password-toggle" onclick="togglePasswordVisibility()">
                        <i class="fas fa-eye" id="password-toggle-icon"></i>
                    </span>
                </div>
            </div>

            <div id="msg" class="message-container"></div>
            
            <button type="submit">Login</button>
            
            <div>
                <a href="#" class="forgot-password">Forgot Password?</a>
            </div>
        </form>
    </div>
    
    <script src="assets/js/loginProcess.js"></script>
    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('password-toggle-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>