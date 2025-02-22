<?php
session_start();
require_once "../../config/database.php";

// Check if user is logged in and is admin
if (!isset($_SESSION["u"]) || $_SESSION["role"] !== "admin") {
    header("Location: login.php");
    exit();
}

$errors = [];

// Validation helper functions
function validateNIC($nic) {
    // Old or New NIC format validation
    return preg_match("/^([0-9]{9}[vVxX]|[0-9]{12})$/", $nic);
}

function validateMobile($mobile) {
    // Sri Lankan mobile number validation
    return preg_match("/^(?:0|94|\+94)?(?:7[0-9]{8})$/", $mobile);
}

function validateName($name) {
    // Name should be at least 3 characters and contain only letters and spaces
    return preg_match("/^[a-zA-Z\s]{3,}$/", $name);
}

function validateDOB($dob) {
    $date = new DateTime($dob);
    $now = new DateTime();
    $age = $now->diff($date)->y;
    return $age >= 18; // Minimum age requirement
}

// Generate new Member ID
$query = "SELECT MemberID FROM Member ORDER BY MemberID DESC LIMIT 1";
$result = Database::search($query);

if ($result->num_rows > 0) {
    $lastId = $result->fetch_assoc()['MemberID'];
    $numericPart = intval(substr($lastId, 6)); // Assuming format is "MEMBER1", "MEMBER2", etc.
    $newNumericPart = $numericPart + 1;
    $newMemberId = "Member" . $newNumericPart;
} else {
    $newMemberId = "Member1";
}

// Handle form submission with validation
if (isset($_POST['add'])) {
    $name = trim($_POST['name']);
    $nic = trim($_POST['nic']);
    $dob = $_POST['dob'];
    $address = trim($_POST['address']);
    $mobile = !empty($_POST['mobile']) ? trim($_POST['mobile']) : null;
    $familyMembers = empty($_POST['family_members']) ? 0 : (int)$_POST['family_members'];
    $otherMembers = empty($_POST['other_members']) ? 0 : (int)$_POST['other_members'];
    $status = $_POST['status'];
    
    // Validate name
    if (empty($name)) {
        $errors['name'] = "Name is required";
    } elseif (!validateName($name)) {
        $errors['name'] = "Name should contain only letters and spaces";
    }

    // Validate NIC
    if (empty($nic)) {
        $errors['nic'] = "NIC is required";
    } elseif (!validateNIC($nic)) {
        $errors['nic'] = "Invalid NIC format";
    }

    // Validate DOB
    if (empty($dob)) {
        $errors['dob'] = "Date of Birth is required";
    } elseif (!validateDOB($dob)) {
        $errors['dob'] = "Member must be at least 18 years old";
    }

    // Validate address
    if (empty($address)) {
        $errors['address'] = "Address is required";
    }

    // Validate mobile number if provided
    if (!empty($mobile)) {
        if (!validateMobile($mobile)) {
            $errors['mobile'] = "Invalid mobile number format";
        } else {
            // Convert to integer by removing non-numeric characters
            $mobile = preg_replace('/[^0-9]/', '', $mobile);
        }
    }

    // Validate family members
    if ($familyMembers < 0) {
        $errors['family_members'] = "Number of family members cannot be negative";
    }

    // Validate other members
    if ($otherMembers < 0) {
        $errors['other_members'] = "Number of other members cannot be negative";
    }

    // Check for duplicate NIC
    $checkNICQuery = "SELECT COUNT(*) as count FROM Member WHERE NIC = '$nic'";
    $result = Database::search($checkNICQuery);
    if ($result->fetch_assoc()['count'] > 0) {
        $errors['nic'] = "This NIC is already registered";
    }

    // Handle file upload
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === 0) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        $maxSize = 20 * 1024 * 1024; // 20MB

        if (!in_array($_FILES['profile_photo']['type'], $allowedTypes)) {
            $errors['profile_photo'] = "Only JPG, JPEG & PNG files are allowed";
        } elseif ($_FILES['profile_photo']['size'] > $maxSize) {
            $errors['profile_photo'] = "File size must be less than 20MB";
        }
    }

    // If no errors, proceed with insertion
    if (empty($errors)) {
        try {
            // Begin transaction
            Database::$connection->begin_transaction();

            $mobileValue = $mobile === null ? "NULL" : $mobile;

            $insertQuery = "INSERT INTO Member (MemberID, Name, NIC, DoB, Address, Mobile_Number, 
                          No_of_Family_Members, Other_Members, Status, Joined_Date) 
                          VALUES ('$newMemberId', '$name', '$nic', '$dob', '$address', $mobileValue, 
                          $familyMembers, $otherMembers, '$status', CURRENT_DATE())";

            Database::iud($insertQuery);

            // Handle file upload if exists
            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === 0) {
                $fileName = $newMemberId . '_' . time() . '.' . pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
                $uploadPath = "../uploads/" . $fileName;

                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadPath)) {
                    // Update member record with image path
                    $updateImageQuery = "UPDATE Member SET Image = '$fileName' WHERE MemberID = '$newMemberId'";
                    Database::iud($updateImageQuery);
                }
            }

            // Commit transaction
            Database::$connection->commit();
            
            $_SESSION['success_message'] = "Member added successfully!";
            header("Location: memberDetails.php");
            exit();

        } catch (Exception $e) {
            // Rollback transaction on error
            Database::$connection->rollback();
            $errors['db'] = "Error adding member: " . $e->getMessage();
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Member</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
        }

        .container {
            max-width: 1000px;
            width: 95%;
            margin: 2rem auto;
            padding: 2rem;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #1a237e;
            margin-bottom: 2rem;
            font-size: clamp(1.5rem, 4vw, 2rem);
        }

        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-column {
            flex: 1;
            min-width: 0;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }

        .input-container {
            width: 100%;
        }

        input[type="text"],
        input[type="number"],
        input[type="date"],
        input[type="file"],
        select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            box-sizing: border-box;
        }

        .family-details {
            border: 2px solid #ddd;
            border-radius: 6px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .family-details h3 {
            margin-top: 0;
            margin-bottom: 1rem;
            color: #1a237e;
        }

        .hint-text {
            color: #666;
            font-size: 0.9rem;
            margin-top: 0.3rem;
        }

        .terms-group {
            margin: 2rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .terms-group input[type="checkbox"] {
            width: 1.2rem;
            height: 1.2rem;
        }

        .button-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            cursor: pointer;
            font-weight: 500;
            min-width: 120px;
            text-align: center;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-submit {
            background-color: #1a237e;
            color: white;
        }

        .btn-submit:hover {
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

        .error-message {
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 0.3rem;
            display: block;
        }

        input.error {
            border-color: #dc3545;
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        @media (max-width: 768px) {
            .container {
                width: 100%;
                margin: 0;
                padding: 1rem;
                border-radius: 0;
            }

            .form-row {
                flex-direction: column;
                gap: 1.5rem;
            }

            .button-group {
                flex-direction: column-reverse;
            }

            .btn {
                width: 100%;
            }

            .terms-group {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 480px) {
            .main-container {
                padding: 0;
            }

            input[type="file"] {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-container" style="min-height: 100vh; background: #f5f7fa; padding: 2rem;">
        <?php include '../templates/navbar-admin.php'; ?>
        <div class="container">
            <h1>Membership Details</h1>

            <form method="POST" action="" enctype="multipart/form-data">
                <?php if (isset($errors['db'])): ?>
                    <div class="alert alert-danger"><?php echo $errors['db']; ?></div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-column">
                        <label for="name">Name</label>
                        <div class="input-container">
                            <input type="text" id="name" name="name" 
                                   value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" 
                                   class="<?php echo isset($errors['name']) ? 'error' : ''; ?>" required>
                            <?php if (isset($errors['name'])): ?>
                                <span class="error-message"><?php echo $errors['name']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-column">
                        <label for="member_id">Member ID</label>
                        <input type="text" id="member_id" value="<?php echo $newMemberId; ?>" disabled>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-column">
                        <label for="nic">NIC</label>
                        <div class="input-container">
                            <input type="text" id="nic" name="nic" 
                                   value="<?php echo isset($_POST['nic']) ? htmlspecialchars($_POST['nic']) : ''; ?>"
                                   class="<?php echo isset($errors['nic']) ? 'error' : ''; ?>" required>
                            <?php if (isset($errors['nic'])): ?>
                                <span class="error-message"><?php echo $errors['nic']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-column">
                        <label for="mobile">Contact Number</label>
                        <div class="input-container">
                            <input type="text" id="mobile" name="mobile"
                                   value="<?php echo isset($_POST['mobile']) ? htmlspecialchars($_POST['mobile']) : ''; ?>"
                                   class="<?php echo isset($errors['mobile']) ? 'error' : ''; ?>">
                            <?php if (isset($errors['mobile'])): ?>
                                <span class="error-message"><?php echo $errors['mobile']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-column">
                        <label for="dob">Date of Birth</label>
                        <div class="input-container">
                            <input type="date" id="dob" name="dob" 
                                   value="<?php echo isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : ''; ?>"
                                   class="<?php echo isset($errors['dob']) ? 'error' : ''; ?>" required>
                            <?php if (isset($errors['dob'])): ?>
                                <span class="error-message"><?php echo $errors['dob']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-column">
                        <label for="joined_date">Joined Date</label>
                        <input type="date" id="joined_date" name="joined_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-column">
                        <label for="address">Address</label>
                        <div class="input-container">
                            <input type="text" id="address" name="address" 
                                   value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>"
                                   class="<?php echo isset($errors['address']) ? 'error' : ''; ?>" required>
                            <?php if (isset($errors['address'])): ?>
                                <span class="error-message"><?php echo $errors['address']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-column">
                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <option value="FAIL">Pending</option>
                            <option value="TRUE">Full Member</option>
                        </select>
                    </div>
                </div>

                <div class="family-details">
                    <h3>Family Details</h3>
                    <div class="form-row">
                        <div class="form-column">
                            <label for="family_members">Number of Family Members</label>
                            <div class="input-container">
                                <input type="number" id="family_members" name="family_members" min="0" 
                                       value="<?php echo isset($_POST['family_members']) ? htmlspecialchars($_POST['family_members']) : '0'; ?>"
                                       class="<?php echo isset($errors['family_members']) ? 'error' : ''; ?>">
                                <?php if (isset($errors['family_members'])): ?>
                                    <span class="error-message"><?php echo $errors['family_members']; ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="hint-text">Only consider wife, children & Parents</p>
                        </div>
                        <div class="form-column">
                            <label for="other_members">Other Members Living at Home</label>
                            <div class="input-container">
                                <input type="number" id="other_members" name="other_members" min="0" 
                                       value="<?php echo isset($_POST['other_members']) ? htmlspecialchars($_POST['other_members']) : '0'; ?>"
                                       class="<?php echo isset($errors['other_members']) ? 'error' : ''; ?>">
                                <?php if (isset($errors['other_members'])): ?>
                                    <span class="error-message"><?php echo $errors['other_members']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-column">
                        <label>Profile Photo</label>
                        <div class="input-container">
                            <input type="file" name="profile_photo" accept="image/*"
                                   class="<?php echo isset($errors['profile_photo']) ? 'error' : ''; ?>">
                            <?php if (isset($errors['profile_photo'])): ?>
                                <span class="error-message"><?php echo $errors['profile_photo']; ?></span>
                            <?php endif; ?>
                            <p class="hint-text">(jpeg / jpg / png , 20MB max)</p>
                        </div>
                    </div>
                </div>

                <div class="terms-group">
                    <input type="checkbox" id="terms" name="terms" required 
                           class="<?php echo isset($errors['terms']) ? 'error' : ''; ?>">
                    <label for="terms">I agree to the Terms & Conditions</label>
                    <?php if (isset($errors['terms'])): ?>
                        <span class="error-message"><?php echo $errors['terms']; ?></span>
                    <?php endif; ?>
                </div>

                <div class="button-group">
                    <button type="submit" name="add" class="btn btn-submit">Submit</button>
                    <a href="memberDetails.php" class="btn btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.querySelector('form').addEventListener('submit', function(e) {
        let hasError = false;
        
        // Clear previous errors
        document.querySelectorAll('.error-message').forEach(el => el.remove());
        document.querySelectorAll('.error').forEach(el => el.classList.remove('error'));

        // Validate name
        const name = document.getElementById('name').value.trim();
        if (!/^[a-zA-Z\s]{3,}$/.test(name)) {
            showError('name', 'Name should contain only letters and spaces');
            hasError = true;
        }

        // Validate NIC
        const nic = document.getElementById('nic').value.trim();
        if (!/^([0-9]{9}[vVxX]|[0-9]{12})$/.test(nic)) {
            showError('nic', 'Invalid NIC format');
            hasError = true;
        }

        // Validate Terms & Conditions
        if (!document.getElementById('terms').checked) {
            showError('terms', 'Please accept the Terms & Conditions');
            hasError = true;
        }

        if (hasError) {
            e.preventDefault();
        }
    });

    function showError(fieldId, message) {
        const field = document.getElementById(fieldId);
        field.classList.add('error');
        const errorDiv = document.createElement('span');
        errorDiv.className = 'error-message';
        errorDiv.textContent = message;
        field.parentNode.appendChild(errorDiv);
    }
    </script>
</body>
</html>