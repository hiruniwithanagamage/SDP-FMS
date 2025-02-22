<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once "../../config/database.php";

// Generate new User ID
$query = "SELECT UserId FROM User ORDER BY UserId DESC LIMIT 1";
$result = Database::search($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    if ($row && isset($row['UserId'])) {
        $lastId = $row['UserId'];
        $numericPart = preg_replace('/[^0-9]/', '', $lastId);
        $newNumericPart = intval($numericPart) + 1;
        $newUserId = "user" . $newNumericPart;
    } else {
        $newUserId = "user1";
    }
} else {
    $newUserId = "user1";
}

// Fetch role options
$adminQuery = "SELECT AdminID, Name FROM Admin";
$auditorQuery = "SELECT AuditorID, Name FROM Auditor";
$treasurerQuery = "SELECT TreasurerID, Name FROM Treasurer";
$memberQuery = "SELECT MemberID, Name FROM Member";

$adminResult = Database::search($adminQuery);
$auditorResult = Database::search($auditorQuery);
$treasurerResult = Database::search($treasurerQuery);
$memberResult = Database::search($memberQuery);

// Check if form is submitted
if(isset($_POST['add'])) {
    $username = $_POST['username'];
    $email = !empty($_POST['email']) ? $_POST['email'] : null;
    $password = $_POST['password'];
    $role = $_POST['role'];
    $roleId = $_POST['role_id'];
    
    // Validate inputs
    $errors = [];
    
    if(empty($username)) $errors[] = "Username is required";
    if(empty($password)) $errors[] = "Password is required";
    if(empty($role)) $errors[] = "Role is required";
    if(empty($roleId)) $errors[] = "Associated name is required";
    
    if(empty($errors)) {
        // Set role-specific columns
        $adminId = $role === 'admin' ? "'$roleId'" : "NULL";
        $auditorId = $role === 'auditor' ? "'$roleId'" : "NULL";
        $treasurerId = $role === 'treasurer' ? "'$roleId'" : "NULL";
        $memberId = $role === 'member' ? "'$roleId'" : "NULL";

        // Handle null email
        $emailValue = $email === null ? "NULL" : "'$email'";
        
        // Prepare SQL insert statement
        $query = "INSERT INTO User (UserId, Username, Email, Password, 
                                Admin_AdminID, Auditor_AuditorID, 
                                Treasurer_TreasurerID, Member_MemberID) 
                VALUES ('$newUserId', '$username', $emailValue, '$password',
                        $adminId, $auditorId, $treasurerId, $memberId)";
        
        try {
            Database::iud($query);
            $_SESSION['success_message'] = "User added successfully";
            header("Location: userDetails.php");
            exit();
        } catch(Exception $e) {
            $error = "Error adding user: " . $e->getMessage();
        }
        $query = "SELECT UserId FROM User ORDER BY UserId DESC LIMIT 1";
            $result = Database::search($query);
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row && isset($row['UserId'])) {
                    $lastId = $row['UserId'];
                    $numericPart = preg_replace('/[^0-9]/', '', $lastId);
                    $newNumericPart = intval($numericPart) + 1;
                    $newUserId = "user" . $newNumericPart;
                }
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
                    echo "{id: '{$admin['AdminID']}', name: '" . addslashes($admin['Name']) . "'},";
                }
                ?>
            ],
            auditor: [
                <?php 
                while($auditor = $auditorResult->fetch_assoc()) {
                    echo "{id: '{$auditor['AuditorID']}', name: '" . addslashes($auditor['Name']) . "'},";
                }
                ?>
            ],
            treasurer: [
                <?php 
                while($treasurer = $treasurerResult->fetch_assoc()) {
                    echo "{id: '{$treasurer['TreasurerID']}', name: '" . addslashes($treasurer['Name']) . "'},";
                }
                ?>
            ],
            member: [
                <?php 
                while($member = $memberResult->fetch_assoc()) {
                    echo "{id: '{$member['MemberID']}', name: '" . addslashes($member['Name']) . "'},";
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
    </script>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-admin.php'; ?>
        
        <div class="container">
            <h1>Add New User</h1>
            
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="form-group">
                    <label for="user_id">User ID</label>
                    <input type="text" id="user_id" name="user_id" value="<?php echo htmlspecialchars($newUserId); ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" required onchange="updateRoleOptions()">
                        <option value="">Select Role</option>
                        <option value="admin">Admin</option>
                        <option value="auditor">Auditor</option>
                        <option value="treasurer">Treasurer</option>
                        <option value="member">Member</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="role_id">Associated Name</label>
                    <select id="role_id" name="role_id" required>
                        <option value="">Select Associated Name</option>
                    </select>
                </div>

                <div class="button-group">
                    <button type="submit" name="add" class="btn btn-add" >Add User</button>
                    <button type="button" onclick="window.location.href='userDetails.php'" class="btn btn-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    
</body>
</html>