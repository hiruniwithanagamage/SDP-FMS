<?php
session_start();
require_once "../../config/database.php";

// Check if user is logged in and is admin
if (!isset($_SESSION["u"]) || $_SESSION["role"] !== "admin") {
    header("Location: login.php");
    exit();
}

// Get database connection
$conn = getConnection();

$errors = [];

// Validation helper functions
function validateNIC($nic) {
    // Old or New NIC format validation for Sri Lanka
    return preg_match("/^([0-9]{9}[vVxX]|[0-9]{12})$/", $nic);
}

function validateMobile($mobile) {
    // Sri Lankan mobile number validation
    return preg_match("/^(?:0|94|\+94)?(?:7[0-9]{8})$/", $mobile);
}

function validateName($name) {
    // Name should be at least 3 characters, max 50, and contain only letters and spaces
    return preg_match("/^[a-zA-Z\s]{3,50}$/", $name);
}

function validateAddress($address) {
    // Address should be max 50 characters
    return strlen($address) <= 50;
}

function validateDOB($dob) {
    $date = new DateTime($dob);
    $now = new DateTime();
    $age = $now->diff($date)->y;
    return $age >= 18; // Minimum age requirement
}

// Check if NIC already exists
function checkNICExists($conn, $nic) {
    $query = "SELECT COUNT(*) as count FROM Member WHERE NIC = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $nic);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['count'] > 0;
}

// Get current active year from static table
function getActiveYear($conn) {
    $query = "SELECT year FROM static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['year'];
    }
    
    // Fallback to current year if no active year is found
    return date('Y');
}

// Generate new Member ID using prepared statement in format MEMB01, MEMB02, etc.
function generateNewMemberId($conn) {
    $query = "SELECT MemberID FROM Member ORDER BY MemberID DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $lastId = $result->fetch_assoc()['MemberID'];
        // Check if the last ID follows our format (MEMB followed by numbers)
        if (preg_match('/^MEMB(\d+)$/', $lastId, $matches)) {
            $numericPart = intval($matches[1]);
            $newNumericPart = $numericPart + 1;
            return "MEMB" . str_pad($newNumericPart, 2, '0', STR_PAD_LEFT);
        }
    }
    
    // If no records exist or last ID doesn't match format, start with MEMB01
    return "MEMB01";
}

$newMemberId = generateNewMemberId($conn);
$currentYear = getActiveYear($conn);

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
        $errors['name'] = "Name should contain only letters and spaces (3-50 characters)";
    }

    // Validate NIC
    if (empty($nic)) {
        $errors['nic'] = "NIC is required";
    } elseif (!validateNIC($nic)) {
        $errors['nic'] = "Invalid NIC format. Use 9 digits + V/X or 12 digits";
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
    } elseif (!validateAddress($address)) {
        $errors['address'] = "Address must be 50 characters or less";
    }

    // Validate mobile number if provided
    if (!empty($mobile)) {
        if (!validateMobile($mobile)) {
            $errors['mobile'] = "Invalid Sri Lankan mobile number format (e.g., 07XXXXXXXX)";
        } else {
            // Clean the mobile number
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
    if (empty($errors['nic']) && checkNICExists($conn, $nic)) {
        $errors['nic'] = "This NIC is already registered";
    }

    // Handle file upload
    $fileName = null;
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === 0) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        $maxSize = 20 * 1024 * 1024; // 20MB

        if (!in_array($_FILES['profile_photo']['type'], $allowedTypes)) {
            $errors['profile_photo'] = "Only JPG, JPEG & PNG files are allowed";
        } elseif ($_FILES['profile_photo']['size'] > $maxSize) {
            $errors['profile_photo'] = "File size must be less than 20MB";
        } else {
            // Generate a unique filename
            $fileName = $newMemberId . '_' . time() . '.' . pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        }
    }

    // If no errors, proceed with insertion
    if (empty($errors)) {
        try {
            // Begin transaction
            $conn->begin_transaction();

            // Insert new member with prepared statement
            $insertQuery = "INSERT INTO Member (MemberID, Name, NIC, DoB, Address, Mobile_Number, 
                            No_of_Family_Members, Other_Members, Status, Image, Joined_Date) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE())";
            
            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param("sssssiiiss", 
                $newMemberId, 
                $name, 
                $nic, 
                $dob, 
                $address, 
                $mobile, 
                $familyMembers, 
                $otherMembers, 
                $status,
                $fileName
            );
            
            $stmt->execute();

            // Handle file upload if exists
            if ($fileName && isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === 0) {
                $uploadPath = "../uploads/" . $fileName;
                if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadPath)) {
                    throw new Exception("Failed to upload image");
                }
            }

            // Commit transaction
            $conn->commit();
            
            $_SESSION['success_message'] = "Member added successfully!";
            header("Location: memberDetails.php");
            exit();

        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
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
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="../../assets/js/alertHandler.js"></script>
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
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-column {
            flex: 1;
            min-width: 250px; /* Ensure columns don't get too narrow */
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }

        .input-container {
            width: 100%;
            margin-bottom: 1rem;
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
            align-items: flex-start;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .terms-group input[type="checkbox"] {
            width: 1.2rem;
            height: 1.2rem;
            margin-top: 0.2rem;
        }

        .button-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
            width: 100%;
        }

        .btn {
            padding: 1rem 2rem;
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
            flex: 1;
            max-width: 100%; 
            box-sizing: border-box;
        }

        .btn-submit {
            background-color: #1a237e;
            color: white;
        }

        .btn-submit:hover {
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
        .error-message {
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 0.3rem;
            display: block;
        }

        input.error,
        select.error {
            border-color: #dc3545;
        }

        @media (max-width: 768px) {
            .container {
                width: 95%;
                margin: 1rem auto;
                padding: 1.5rem;
            }

            .button-group {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .main-container {
                padding: 0.5rem;
            }
            
            .container {
                padding: 1rem;
                margin: 0.5rem auto;
                width: 100%;
            }

            input[type="file"] {
                font-size: 0.9rem;
            }
            
            .form-column {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="main-container" style="min-height: 100vh; background: #f5f7fa; padding: 1rem;">
        <?php include '../templates/navbar-admin.php'; ?>
        <div class="container">
            <h1>Add New Member</h1>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data" novalidate>
                <?php if (isset($errors['db'])): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($errors['db']); ?></div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-column">
                        <label for="name">Name</label>
                        <div class="input-container">
                            <input type="text" id="name" name="name" maxlength="50"
                                   value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" 
                                   class="<?php echo isset($errors['name']) ? 'error' : ''; ?>" required>
                            <?php if (isset($errors['name'])): ?>
                                <span class="error-message"><?php echo htmlspecialchars($errors['name']); ?></span>
                            <?php endif; ?>
                            <span class="hint-text">Maximum 50 characters</span>
                        </div>
                    </div>
                    <div class="form-column">
                        <label for="member_id">Member ID</label>
                        <div class="input-container">
                            <input type="text" id="member_id" value="<?php echo htmlspecialchars($newMemberId); ?>" disabled>
                        </div>
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
                                <span class="error-message"><?php echo htmlspecialchars($errors['nic']); ?></span>
                            <?php endif; ?>
                            <span class="hint-text">9 digits + V/X or 12 digits</span>
                        </div>
                    </div>
                    <div class="form-column">
                        <label for="mobile">Contact Number</label>
                        <div class="input-container">
                            <input type="text" id="mobile" name="mobile"
                                   value="<?php echo isset($_POST['mobile']) ? htmlspecialchars($_POST['mobile']) : ''; ?>"
                                   class="<?php echo isset($errors['mobile']) ? 'error' : ''; ?>"
                                   placeholder="07XXXXXXXX">
                            <?php if (isset($errors['mobile'])): ?>
                                <span class="error-message"><?php echo htmlspecialchars($errors['mobile']); ?></span>
                            <?php endif; ?>
                            <span class="hint-text">Sri Lankan mobile format (e.g., 07XXXXXXXX)</span>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-column">
                        <label for="dob">Date of Birth</label>
                        <div class="input-container">
                            <input type="date" id="dob" name="dob" 
                                   value="<?php echo isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : ''; ?>"
                                   max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
                                   class="<?php echo isset($errors['dob']) ? 'error' : ''; ?>" required>
                            <?php if (isset($errors['dob'])): ?>
                                <span class="error-message"><?php echo htmlspecialchars($errors['dob']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-column">
                        <label for="joined_date">Joined Date</label>
                        <div class="input-container">
                            <input type="date" id="joined_date" name="joined_date" value="<?php echo date('Y-m-d'); ?>" readonly>
                            <span class="hint-text">Automatically set to today's date</span>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-column">
                        <label for="address">Address</label>
                        <div class="input-container">
                            <input type="text" id="address" name="address" maxlength="50"
                                   value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>"
                                   class="<?php echo isset($errors['address']) ? 'error' : ''; ?>" required>
                            <?php if (isset($errors['address'])): ?>
                                <span class="error-message"><?php echo htmlspecialchars($errors['address']); ?></span>
                            <?php endif; ?>
                            <span class="hint-text">Maximum 50 characters</span>
                        </div>
                    </div>
                    <div class="form-column">
                        <label for="status">Status</label>
                        <div class="input-container">
                            <select id="status" name="status" required>
                                <option value="FAIL" <?php echo (isset($_POST['status']) && $_POST['status'] === 'FAIL') ? 'selected' : ''; ?>>Pending</option>
                                <option value="TRUE" <?php echo (isset($_POST['status']) && $_POST['status'] === 'TRUE') ? 'selected' : ''; ?>>Full Member</option>
                            </select>
                        </div>
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
                                    <span class="error-message"><?php echo htmlspecialchars($errors['family_members']); ?></span>
                                <?php endif; ?>
                                <p class="hint-text">Only consider wife, children & Parents</p>
                            </div>
                        </div>
                        <div class="form-column">
                            <label for="other_members">Other Members Living at Home</label>
                            <div class="input-container">
                                <input type="number" id="other_members" name="other_members" min="0" 
                                       value="<?php echo isset($_POST['other_members']) ? htmlspecialchars($_POST['other_members']) : '0'; ?>"
                                       class="<?php echo isset($errors['other_members']) ? 'error' : ''; ?>">
                                <?php if (isset($errors['other_members'])): ?>
                                    <span class="error-message"><?php echo htmlspecialchars($errors['other_members']); ?></span>
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
                                <span class="error-message"><?php echo htmlspecialchars($errors['profile_photo']); ?></span>
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
                        <span class="error-message"><?php echo htmlspecialchars($errors['terms']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="button-group">
                    <a href="memberDetails.php" class="btn cancel-btn">Cancel</a>
                    <button type="submit" name="add" class="btn btn-submit">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            
            form.addEventListener('submit', function(e) {
                let hasError = false;
                
                // Clear previous errors
                document.querySelectorAll('.error-message').forEach(el => el.remove());
                document.querySelectorAll('.error').forEach(el => el.classList.remove('error'));

                // Validate name
                const name = document.getElementById('name').value.trim();
                if (name === '') {
                    showError('name', 'Name is required');
                    hasError = true;
                } else if (!/^[a-zA-Z\s]{3,50}$/.test(name)) {
                    showError('name', 'Name should contain only letters and spaces (3-50 characters)');
                    hasError = true;
                }

                // Validate NIC
                const nic = document.getElementById('nic').value.trim();
                if (nic === '') {
                    showError('nic', 'NIC is required');
                    hasError = true;
                } else if (!/^([0-9]{9}[vVxX]|[0-9]{12})$/.test(nic)) {
                    showError('nic', 'Invalid NIC format. Use 9 digits + V/X or 12 digits');
                    hasError = true;
                }

                // Validate Address
                const address = document.getElementById('address').value.trim();
                if (address === '') {
                    showError('address', 'Address is required');
                    hasError = true;
                } else if (address.length > 50) {
                    showError('address', 'Address must be 50 characters or less');
                    hasError = true;
                }

                // Validate mobile if provided
                const mobile = document.getElementById('mobile').value.trim();
                if (mobile !== '' && !/^(?:0|94|\+94)?(?:7[0-9]{8})$/.test(mobile)) {
                    showError('mobile', 'Invalid Sri Lankan mobile number format (e.g., 07XXXXXXXX)');
                    hasError = true;
                }

                // Validate family members
                const familyMembers = parseInt(document.getElementById('family_members').value);
                if (isNaN(familyMembers) || familyMembers < 0) {
                    showError('family_members', 'Number of family members cannot be negative');
                    hasError = true;
                }
                
                // Validate other members
                const otherMembers = parseInt(document.getElementById('other_members').value);
                if (isNaN(otherMembers) || otherMembers < 0) {
                    showError('other_members', 'Number of other members cannot be negative');
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
            
            // Set max date for DOB to 18 years ago
            const dobInput = document.getElementById('dob');
            const maxDate = new Date();
            maxDate.setFullYear(maxDate.getFullYear() - 18);
            dobInput.max = maxDate.toISOString().split('T')[0];
        });
    </script>
</body>
</html>