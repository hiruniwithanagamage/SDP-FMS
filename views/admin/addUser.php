<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once "../../config/database.php";

// Function to get the current active year from static table
function getCurrentActiveYear($conn) {
    $query = "SELECT year FROM static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['year'];
    }
    
    // Fallback to current year if no active record found
    return date('Y');
}

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
            // Extract numeric part
            $numericPart = substr($lastId, 4); // Extract after "USER"
            $newNumericPart = intval($numericPart) + 1;
            // Format with leading zeros if needed (e.g., USER01, USER02, etc.)
            return "USER" . str_pad($newNumericPart, 2, '0', STR_PAD_LEFT);
        }
    }
    return "USER01"; // First user if no records exist
}

// Generate a secure default password
function generateDefaultPassword() {
    // Generate a password with at least one uppercase, one lowercase, one number
    $uppercase = chr(rand(65, 90)); // A-Z
    $lowercase = chr(rand(97, 122)); // a-z
    $number = chr(rand(48, 57)); // 0-9
    
    // Generate 5 more random characters (can be any of uppercase, lowercase, or numbers)
    $random = '';
    for ($i = 0; $i < 5; $i++) {
        $charType = rand(1, 3);
        switch ($charType) {
            case 1:
                $random .= chr(rand(65, 90)); // uppercase
                break;
            case 2:
                $random .= chr(rand(97, 122)); // lowercase
                break;
            case 3:
                $random .= chr(rand(48, 57)); // number
                break;
        }
    }
    
    // Combine all parts and shuffle to ensure randomness
    $password = $uppercase . $lowercase . $number . $random;
    $passwordArray = str_split($password);
    shuffle($passwordArray);
    
    return implode('', $passwordArray);
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
$conn = getConnection(); 
$newUserId = generateNewUserId($conn);
$currentYear = getCurrentActiveYear($conn);

// Fetch role options with prepared statements
function fetchRoleOptions($conn, $tableName, $idColumn, $nameColumn) {

    // Add Term field as YearInfo only for Auditor and Treasurer tables
    $yearField = '';
    if ($tableName == 'Auditor' || $tableName == 'Treasurer') {
        $yearField = ", Term AS YearInfo";
    }

    $query = "SELECT $idColumn, $nameColumn$yearField FROM $tableName";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->get_result();
}

// Keep the calls to fetch options for each role the same:
$adminResult = fetchRoleOptions($conn, "Admin", "AdminID", "Name");
$auditorResult = fetchRoleOptions($conn, "Auditor", "AuditorID", "Name");
$treasurerResult = fetchRoleOptions($conn, "Treasurer", "TreasurerID", "Name");
$memberResult = fetchRoleOptions($conn, "Member", "MemberID", "Name");

// Default password for newly created user
$defaultPassword = generateDefaultPassword();
$passwordMessage = "";

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

    // Additional email validation
    if(!empty($email)) {
        // Check email length
        if(strlen($email) > 100) {
            $errors[] = "Email address is too long (maximum 100 characters)";
        }
        
        // Check domain validity
        $domain = substr(strrchr($email, "@"), 1);
        if(!checkdnsrr($domain, "MX")) {
            $errors[] = "Email domain appears to be invalid";
        }
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
            
            // Update the query to include the additional fields from your updated schema
            $query = "INSERT INTO User (UserId, Username, Email, Password, 
                            Admin_AdminID, Auditor_AuditorID, 
                            Treasurer_TreasurerID, Member_MemberID,
                            failed_attempts) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)";
            
            // Set the appropriate role ID and null for others
            $adminId = ($role === 'admin') ? $roleId : null;
            $auditorId = ($role === 'auditor') ? $roleId : null;
            $treasurerId = ($role === 'treasurer') ? $roleId : null;
            $memberId = ($role === 'member') ? $roleId : null;
            
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
            
            // Save password for display
            $passwordMessage = $password;
            
            $_SESSION['success_message'] = "User added successfully";
            $_SESSION['user_created'] = true;
            $_SESSION['created_username'] = $username;
            $_SESSION['created_password'] = $password;
            $_SESSION['created_userid'] = $newUserId;
            
            header("Location: userDetails.php");
            exit();
        } catch(Exception $e) {
            $error = "Error adding user: " . $e->getMessage();
            // Generate a new user ID in case we need to retry
            $newUserId = generateNewUserId($conn);
            $defaultPassword = generateDefaultPassword();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

function generateUsername($name, $role, $memberId) {
    // Extract first 5 characters of name (or less if name is shorter)
    $namePrefix = substr(strtolower($name), 0, 5);
    
    // Extract first 4 characters of role (or less if role is shorter)
    $rolePrefix = substr(strtolower($role), 0, 4);
    
    // Extract all digits from the end of memberID
    preg_match('/\d+$/', $memberId, $matches);
    $memberDigits = !empty($matches) ? $matches[0] : '';
    
    // Combine to form the username
    $username = $namePrefix . '-' . $rolePrefix . $memberDigits;
    
    return $username;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Add Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../../assets/css/adminDetails.css">
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
            flex: 1;
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
            width:500px;
        }

        .password-toggle {
            position: absolute;
            right: 0px;
            top: 35%;
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
            justify-content: flex-end;
            padding-top: 1rem;
            border-top: 1px solid #eee;
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
        
        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .form-title {
            font-size: 1.5rem;
            color: #1a237e;
            margin: 0;
        }
        
        .password-info {
            padding: 0.8rem;
            background-color: #e3f2fd;
            color: #0d47a1;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            border: 1px solid #bbdefb;
        }
        
        .password-copy-btn {
            background: none;
            border: none;
            color: #1a237e;
            cursor: pointer;
            margin-left: 0.5rem;
        }
        
        .password-copy-btn:hover {
            color: #0d1757;
        }
        
        /* Style for auto-filled fields */
        input[readonly], 
        input[disabled],
        select[readonly], 
        select[disabled] {
            background-color: #f2f2f2;
            cursor: not-allowed;
        }

        .select2-container{
            flex: 1;
            margin-right: 50px;
        }
        
        /* Select2 custom styling */
        .select2-container--default .select2-selection--single {
            height: 45px;
            padding: 8px 4px;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 43px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 28px;
            color: #333;
        }
        
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #1a237e;
        }
        
        .select2-dropdown {
            border: 2px solid #e0e0e0;
        }
        
        .select2-search--dropdown .select2-search__field {
            padding: 8px;
            border: 1px solid #ddd;
        }
    </style>    
    
    <!-- Add jQuery (required for Select2) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <!-- Add Select2 JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    
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
                    echo "{id: '" . addslashes($auditor['AuditorID']) . "', name: '" . addslashes($auditor['Name']) . "', year: '" . (isset($auditor['YearInfo']) ? addslashes($auditor['YearInfo']) : '') . "'},";
                }
                ?>
            ],
            treasurer: [
                <?php 
                while($treasurer = $treasurerResult->fetch_assoc()) {
                    echo "{id: '" . addslashes($treasurer['TreasurerID']) . "', name: '" . addslashes($treasurer['Name']) . "', year: '" . (isset($treasurer['YearInfo']) ? addslashes($treasurer['YearInfo']) : '') . "'},";
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

        // Initialize Select2 and update role options
        $(document).ready(function() {
            // Initialize Select2 for role_id dropdown
            $('#role_id').select2({
                placeholder: "Select Associated Name",
                allowClear: true,
                width: '100%'
            });
            
            // Handle role change
            $('#role').on('change', function() {
                updateRoleOptions();
            });
            
            // Initialize options if a role is already selected (e.g., on form submit with errors)
            if ($('#role').val()) {
                updateRoleOptions();
            }
        });

        function updateRoleOptions() {
            const role = document.getElementById('role').value;
            const roleIdSelect = document.getElementById('role_id');
            
            // Clear existing options
            $('#role_id').empty().append('<option value=""></option>');
            
            if (role) {
                // Populate with new options, including year information only for auditor and treasurer
                roleOptions[role].forEach(option => {
                    let displayText = option.name;
                    
                    // Only append year information for auditor and treasurer roles
                    if ((role === 'auditor' || role === 'treasurer') && option.year) {
                        displayText += ` (${option.year})`;
                    }
                    
                    const optionElement = new Option(displayText, option.id, false, false);
                    $('#role_id').append(optionElement);
                });
            }
            
            // Refresh Select2 to reflect new options
            $('#role_id').trigger('change');
            
            <?php if(isset($role) && isset($roleId)): ?>
            // Set the previously selected value if form was submitted with errors
            $('#role_id').val("<?php echo htmlspecialchars($roleId); ?>").trigger('change');
            <?php endif; ?>
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
        
        // Copy password to clipboard
        function copyPassword() {
            const passwordInput = document.getElementById('password');
            passwordInput.select();
            document.execCommand('copy');
            
            // Show feedback
            const copyBtn = document.getElementById('copy-password-btn');
            const originalText = copyBtn.innerHTML;
            copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            
            setTimeout(() => {
                copyBtn.innerHTML = originalText;
            }, 2000);
        }

        // Username generation when role and role_id are selected
        $(document).ready(function() {
            // Setup event handlers for role and role_id changes
            $('#role, #role_id').on('change', function() {
                updateUsernameField();
            });
            
            function updateUsernameField() {
                const role = $('#role').val();
                const roleId = $('#role_id').val();
                
                if (role && roleId) {
                    // Find the name associated with the selected roleId
                    const selectedOption = roleOptions[role].find(option => option.id === roleId);
                    
                    if (selectedOption) {
                        // Get the name from the selected option (without year information)
                        const name = selectedOption.name;
                        
                        // Generate username on client side following the format
                        // 5prefix of name + "-" + 4prefix of role + all digits from memberID
                        const namePrefix = name.toLowerCase().substring(0, 5);
                        const rolePrefix = role.toLowerCase().substring(0, 4);
                        
                        // Extract digits from roleId
                        const digitMatches = roleId.match(/\d+$/);
                        const memberDigits = digitMatches ? digitMatches[0] : '';
                        
                        // Combine to form username
                        const generatedUsername = namePrefix + '-' + rolePrefix + memberDigits;
                        
                        // Set the username field
                        $('#username').val(generatedUsername);
                        
                        // Make the username field readonly as it's generated
                        $('#username').attr('readonly', true);
                        $('#username').addClass('generated-field');
                    }
                }
            }
        });
    </script>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-admin.php'; ?>
        
        <div class="container">
            <div class="form-header">
                <h1 class="form-title">Add New User</h1>
            </div>
            
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="form-group">
                    <label for="user_id">User ID</label>
                    <input type="text" id="user_id" name="user_id" value="<?php echo htmlspecialchars($newUserId); ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" required>
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
                        <option value=""></option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                </div>

                <div class="password-info">
                    <strong>Auto-generated Password:</strong> User can use this secure password until create a own.
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-container">
                        <input type="password" id="password" name="password" required value="<?php echo htmlspecialchars($defaultPassword); ?>">
                        <span class="password-toggle" onclick="togglePasswordVisibility()">
                            <i class="fas fa-eye" id="password-toggle-icon"></i>
                        </span>
                        <button type="button" id="copy-password-btn" class="password-copy-btn" onclick="copyPassword()">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                </div>

                <div class="button-group">
                    <button type="button" onclick="window.location.href='userDetails.php'" class="btn cancel-btn">Cancel</button>
                    <button type="submit" name="add" class="btn btn-add">Add User</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>