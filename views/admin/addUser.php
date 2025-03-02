<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once "../../config/database.php";

// Generate new User ID
function generateNewUserId($conn) {
    $query = "SELECT UserId FROM User ORDER BY UserId DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row && isset($row['UserId'])) {
            $lastId = $row['UserId'];
            $numericPart = preg_replace('/[^0-9]/', '', $lastId);
            $newNumericPart = intval($numericPart) + 1;
            return "user" . $newNumericPart;
        }
    }
    return "user1";
}

// Function to check for existing username or email
function checkExistingUser($conn, $username, $email) {
    $query = "SELECT UserId FROM User WHERE Username = ? OR Email = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Function to check if a role and associated ID are already assigned to another user
function checkDuplicateRoleAssociation($conn, $role, $roleId) {
    $column = null;
    
    // Determine which column to check based on the role
    switch($role) {
        case 'admin':
            $column = 'Admin_AdminID';
            break;
        case 'auditor':
            $column = 'Auditor_AuditorID';
            break;
        case 'treasurer':
            $column = 'Treasurer_TreasurerID';
            break;
        case 'member':
            $column = 'Member_MemberID';
            break;
        default:
            return false; // Invalid role, shouldn't happen
    }
    
    // Check if this role ID is already associated with another user
    $query = "SELECT UserId FROM User WHERE $column = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $roleId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

// Get database connection
$conn = getConnection(); // Assuming this function exists in database.php
$newUserId = generateNewUserId($conn);

// Fetch role options with prepared statements
function fetchRoleOptions($conn, $tableName, $idColumn, $nameColumn) {
    $query = "SELECT $idColumn, $nameColumn FROM $tableName";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->get_result();
}

$adminResult = fetchRoleOptions($conn, "Admin", "AdminID", "Name");
$auditorResult = fetchRoleOptions($conn, "Auditor", "AuditorID", "Name");
$treasurerResult = fetchRoleOptions($conn, "Treasurer", "TreasurerID", "Name");
$memberResult = fetchRoleOptions($conn, "Member", "MemberID", "Name");

// Check if form is submitted
if(isset($_POST['add'])) {
    $username = trim($_POST['username']);
    $email = !empty($_POST['email']) ? trim($_POST['email']) : null;
    $password = $_POST['password'];
    $role = $_POST['role'];
    $roleId = $_POST['role_id'];
    
    // Validate inputs
    $errors = [];
    
    if(empty($username)) $errors[] = "Username is required";
    if(empty($password)) $errors[] = "Password is required";
    if(empty($role)) $errors[] = "Role is required";
    if(empty($roleId)) $errors[] = "Associated name is required";
    
    // Validate email format if provided
    if(!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Validate password strength
    if(strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if(!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if(!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if(!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if(empty($errors)) {
        try {
            // Check if username or email already exists
            if(checkExistingUser($conn, $username, $email)) {
                $errors[] = "Username or email already exists";
                throw new Exception("Username or email already exists");
            }

            // Check if the role and associated ID are already assigned to another user
            if(checkDuplicateRoleAssociation($conn, $role, $roleId)) {
                $errors[] = "This " . ucfirst($role) . " is already associated with another user";
                throw new Exception("This " . ucfirst($role) . " is already associated with another user");
            }
            
            // Hash the password with a strong algorithm
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            
            // Prepare the insert statement with placeholders
            $query = "INSERT INTO User (UserId, Username, Email, Password, 
                            Admin_AdminID, Auditor_AuditorID, 
                            Treasurer_TreasurerID, Member_MemberID) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($query);
            
            // Set the appropriate role ID and null for others
            $adminId = ($role === 'admin') ? $roleId : null;
            $auditorId = ($role === 'auditor') ? $roleId : null;
            $treasurerId = ($role === 'treasurer') ? $roleId : null;
            $memberId = ($role === 'member') ? $roleId : null;
            
            // Get current timestamp for updated_at
            $currentTimestamp = date('Y-m-d H:i:s');
            
            // Update the query to include the additional fields from your updated schema
            $query = "INSERT INTO User (UserId, Username, Email, Password, 
                            Admin_AdminID, Auditor_AuditorID, 
                            Treasurer_TreasurerID, Member_MemberID,
                            failed_attempts) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)";
            
            // Bind parameters
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ssssssss", 
                $newUserId, 
                $username, 
                $email, 
                $hashedPassword, 
                $adminId, 
                $auditorId, 
                $treasurerId, 
                $memberId
            );
            
            // Execute the statement
            $stmt->execute();
            
            $_SESSION['success_message'] = "User added successfully";
            header("Location: userDetails.php");
            exit();
        } catch(Exception $e) {
            $error = "Error adding user: " . $e->getMessage();
            // Generate a new user ID in case we need to retry
            $newUserId = generateNewUserId($conn);
        }
    } else {
        $error = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
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
            margin-right: 2rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: bold;
        }

        input[type="text"],
        input[type="number"],
        input[type="email"],
        input[type="password"],
        select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="number"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus,
        select:focus {
            border-color: #1a237e;
            outline: none;
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

        input[type="password"], 
        input[type="text"] {
            padding-right: 40px;
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

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .alert-error, .alert-danger {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }

        .main-container {
            min-height: 100vh;
            background: #f5f7fa;
            padding: 2rem;
        }
    </style>    
    <script>
        // Role-specific options
        const roleOptions = {
            admin: [
                <?php 
                while($admin = $adminResult->fetch_assoc()) {
                    echo "{id: '" . addslashes($admin['AdminID']) . "', name: '" . addslashes($admin['Name']) . "'},";
                }
                ?>
            ],
            auditor: [
                <?php 
                while($auditor = $auditorResult->fetch_assoc()) {
                    echo "{id: '" . addslashes($auditor['AuditorID']) . "', name: '" . addslashes($auditor['Name']) . "'},";
                }
                ?>
            ],
            treasurer: [
                <?php 
                while($treasurer = $treasurerResult->fetch_assoc()) {
                    echo "{id: '" . addslashes($treasurer['TreasurerID']) . "', name: '" . addslashes($treasurer['Name']) . "'},";
                }
                ?>
            ],
            member: [
                <?php 
                while($member = $memberResult->fetch_assoc()) {
                    echo "{id: '" . addslashes($member['MemberID']) . "', name: '" . addslashes($member['Name']) . "'},";
                }
                ?>
            ]
        };

        function updateRoleOptions() {
            const role = document.getElementById('role').value;
            const roleIdSelect = document.getElementById('role_id');
            
            // Clear existing options
            roleIdSelect.innerHTML = '<option value="">Select Associated Name</option>';
            
            if (role) {
                // Populate with new options
                roleOptions[role].forEach(option => {
                    const optionElement = document.createElement('option');
                    optionElement.value = option.id;
                    optionElement.textContent = option.name;
                    roleIdSelect.appendChild(optionElement);
                });
            }
        }

        // Password visibility toggle
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
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-admin.php'; ?>
        
        <div class="container">
            <h1>Add New User</h1>
            
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-container">
                        <input type="password" id="password" name="password" required>
                        <span class="password-toggle" onclick="togglePasswordVisibility()">
                            <i class="fas fa-eye" id="password-toggle-icon"></i>
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="user_id">User ID</label>
                    <input type="text" id="user_id" name="user_id" value="<?php echo htmlspecialchars($newUserId); ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" required onchange="updateRoleOptions()">
                        <option value="">Select Role</option>
                        <option value="admin" <?php echo (isset($role) && $role === 'admin') ? 'selected' : ''; ?>>Admin</option>
                        <option value="auditor" <?php echo (isset($role) && $role === 'auditor') ? 'selected' : ''; ?>>Auditor</option>
                        <option value="treasurer" <?php echo (isset($role) && $role === 'treasurer') ? 'selected' : ''; ?>>Treasurer</option>
                        <option value="member" <?php echo (isset($role) && $role === 'member') ? 'selected' : ''; ?>>Member</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="role_id">Associated Name</label>
                    <select id="role_id" name="role_id" required>
                        <option value="">Select Associated Name</option>
                        <?php if(isset($role) && isset($roleId)): ?>
                            <script>
                                // Re-populate the options if form was submitted with errors
                                document.addEventListener('DOMContentLoaded', function() {
                                    updateRoleOptions();
                                    document.getElementById('role_id').value = "<?php echo htmlspecialchars($roleId); ?>";
                                });
                            </script>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="button-group">
                    <button type="submit" name="add" class="btn btn-add">Add User</button>
                    <button type="button" onclick="window.location.href='userDetails.php'" class="btn btn-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>