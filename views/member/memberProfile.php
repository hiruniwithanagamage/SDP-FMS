<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check for session
if (!isset($_SESSION["u"])) {
    header("Location: ../../login.php");
    exit();
}

// Include database connection
require_once "../../config/database.php";

// Include the navigation header
include_once '../templates/navbar-member.php';

// Get the member ID from session
$memberID = $_SESSION["member_id"];

// Fetch member details
$memberQuery = "SELECT * FROM Member WHERE MemberID = ?";
$stmt = prepare($memberQuery);
$stmt->bind_param("s", $memberID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<div class='alert alert-danger'>Member not found!</div>";
    exit();
}

$memberData = $result->fetch_assoc();

// Fetch user details
$userQuery = "SELECT Username, Email, last_login FROM User WHERE Member_MemberID = ?";
$stmt = prepare($userQuery);
$stmt->bind_param("s", $memberID);
$stmt->execute();
$userResult = $stmt->get_result();
$userData = $userResult->fetch_assoc();

// Handle form submission for profile update
$successMessage = "";
$errorMessage = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_profile"])) {
    // Only get the editable fields
    $email = $_POST["email"];
    $mobile = $_POST["mobile"];
    
    // Start transaction
    $conn = getConnection();
    $conn->begin_transaction();
    
    try {
        // Update only the Mobile_Number in Member table
        $updateMemberQuery = "UPDATE Member SET 
                            Mobile_Number = ?
                            WHERE MemberID = ?";
        
        $stmt = prepare($updateMemberQuery);
        $stmt->bind_param("is", $mobile, $memberID);
        $stmt->execute();
        
        // Update User table (email)
        $updateUserQuery = "UPDATE User SET Email = ? WHERE Member_MemberID = ?";
        $stmt = prepare($updateUserQuery);
        $stmt->bind_param("ss", $email, $memberID);
        $stmt->execute();
        
        // Handle profile image upload
        if (isset($_FILES["profile_image"]) && $_FILES["profile_image"]["error"] == 0) {
            $allowed = ["jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png"];
            $filename = $_FILES["profile_image"]["name"];
            $filetype = $_FILES["profile_image"]["type"];
            $filesize = $_FILES["profile_image"]["size"];
            
            // Verify file extension
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if (!array_key_exists($ext, $allowed)) {
                throw new Exception("Error: Please select a valid file format.");
            }
            
            // Verify file size - 5MB maximum
            $maxsize = 5 * 1024 * 1024;
            if ($filesize > $maxsize) {
                throw new Exception("Error: File size is larger than the allowed limit (5MB).");
            }
            
            // Verify MIME type of the file
            if (in_array($filetype, $allowed)) {
                // Create new filename to prevent overwriting
                $newfilename = uniqid() . "." . $ext;
                $uploadPath = "../../uploads/profilePictures/" . $newfilename;
                
                // Check if the directory exists, if not create it
                if (!is_dir("../../uploads/profilePictures/")) {
                    mkdir("../../uploads/profilePictures/", 0755, true);
                }
                
                // Upload file
                if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $uploadPath)) {
                    // Update database with new image path
                    $updateImageQuery = "UPDATE Member SET Image = ? WHERE MemberID = ?";
                    $stmt = prepare($updateImageQuery);
                    $stmt->bind_param("ss", $newfilename, $memberID);
                    $stmt->execute();
                } else {
                    throw new Exception("Error: There was a problem uploading your file. Please try again.");
                }
            } else {
                throw new Exception("Error: There was a problem with your upload. Please try again.");
            }
        }
        
        // Commit transaction
        $conn->commit();
        $successMessage = "Profile updated successfully!";
        
        // Refresh member data after update
        $stmt = prepare($memberQuery);
        $stmt->bind_param("s", $memberID);
        $stmt->execute();
        $result = $stmt->get_result();
        $memberData = $result->fetch_assoc();
        
        // Refresh user data after update
        $stmt = prepare($userQuery);
        $stmt->bind_param("s", $memberID);
        $stmt->execute();
        $userResult = $stmt->get_result();
        $userData = $userResult->fetch_assoc();
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        $errorMessage = $e->getMessage();
    }
}

// Handle password change
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["change_password"])) {
    $currentPassword = $_POST["current_password"];
    $newPassword = $_POST["new_password"];
    $confirmPassword = $_POST["confirm_password"];
    
    // Verify current password
    $passwordQuery = "SELECT Password FROM User WHERE Member_MemberID = ?";
    $stmt = prepare($passwordQuery);
    $stmt->bind_param("s", $memberID);
    $stmt->execute();
    $passwordResult = $stmt->get_result();
    $passwordData = $passwordResult->fetch_assoc();
    
    $isHashed = strlen($passwordData['Password']) > 20 && strpos($passwordData['Password'], '$') === 0;
    $passwordCorrect = false;
    
    if ($isHashed) {
        $passwordCorrect = password_verify($currentPassword, $passwordData['Password']);
    } else {
        $passwordCorrect = ($currentPassword == $passwordData['Password']);
    }
    
    if (!$passwordCorrect) {
        $errorMessage = "Current password is incorrect.";
    } else if ($newPassword != $confirmPassword) {
        $errorMessage = "New passwords do not match.";
    } else if (strlen($newPassword) < 5) {
        $errorMessage = "Password must be at least 5 characters long.";
    } else if (strlen($newPassword) > 12) {
        $errorMessage = "Password must not exceed 12 characters";
    } else {
        // Hash the new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update the password
        $updatePasswordQuery = "UPDATE User SET Password = ? WHERE Member_MemberID = ?";
        $stmt = prepare($updatePasswordQuery);
        $stmt->bind_param("ss", $hashedPassword, $memberID);
        
        if ($stmt->execute()) {
            $successMessage = "Password changed successfully!";
        } else {
            $errorMessage = "Error changing password. Please try again.";
        }
    }
}

// Set profile image path
$profileImage = "../../assets/images/profile_photo.jpg"; // default image
if (!empty($memberData['Image'])) {
    $profileImage = "../../uploads/profilePictures/" . $memberData['Image'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color:  #f5f7fa;
            color: #333;
        }

        .home-container{
            max-width: 1200px;
            margin: 0 auto;;
        }
        
        .profile-container {
            max-width: 1200px;
            margin: 30px auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .profile-header {
            max-width: 1200px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 30px auto;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid white;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .tabs-container {
            padding: 20px;
        }
        
        .tab-content {
            padding: 20px 0;
        }
        
        .info-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .info-card h5 {
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            margin-bottom: 20px;
            color: #1a237e;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 15px;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            width: 150px;
            flex-shrink: 0;
        }
        
        .info-value {
            color: #333;
        }
        
        .btn-primary {
            background-color: #1a237e;
            border-color: #1a237e;
        }
        
        .btn-primary:hover {
            background-color: #3949ab;
            border-color: #3949ab;
        }
        
        .nav-tabs .nav-link {
            color: #1a237e;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            color: #1a237e;
            font-weight: 600;
            border-color: #1a237e;
        }
        
        .form-label {
            font-weight: 500;
            color: #555;
        }
        
        .alert {
            border-radius: 8px;
        }

        /* Style for read-only fields to visually indicate they are not editable */
input[readonly], textarea[readonly] {
    background-color: #f8f9fa;
    border-color: #dee2e6;
    color: #6c757d;
    cursor: not-allowed;
}

/* Highlight the editable fields */
#email, #mobile, #profile_image {
    border-left: 3px solid #1a237e;
}

/* Add a subtle icon to indicate which fields are editable */
.editable-field {
    position: relative;
}

.editable-field::after {
    content: "\f044"; /* Font Awesome edit icon */
    font-family: "Font Awesome 5 Free";
    font-weight: 900;
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #1a237e;
    opacity: 0.7;
}

        /* Add specific styles to differentiate navbar profile image from main profile image */
        .nav-profile .profile-avatar {
            width: 42px;
            height: 42px;
            gap: 1rem;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid #1a237e;
            display: flex;
            align-items: center;
            justify-content: center;
            position: static; /* Override any position: relative from the main profile */
            margin: 0;
            /* Override any margin settings from the main profile */
        }

        .nav-profile .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            vertical-align: middle;
        }

        /* Ensure the main profile doesn't affect navbar */
        .profile-sidebar .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            margin: 0 auto 1.5rem;
            border: 5px solid #f5f7fa;
            overflow: hidden;
            position: relative;
        }
        .nav-content {
            font-family: Arial, sans-serif;
            height: 60px;
            padding: 22px;    
        }
        .modern-nav {
            margin: 32px;
        }
        .nav-link {
            color: #333;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.2rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
    </style>
</head>
<body>
    <div class='home-container'>
    <div class="profile-header">
        <div class="d-flex flex-column flex-md-row align-items-center">
            <div class="profile-avatar me-md-4">
                <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile Image">
            </div>
            <div class="text-center text-md-start">
                <h2><?php echo htmlspecialchars($memberData['Name']); ?></h2>
                <p class="mb-1"><i class="fas fa-id-card me-2"></i>Member ID: <?php echo htmlspecialchars($memberData['MemberID']); ?></p>
                <p class="mb-1"><i class="fas fa-calendar-alt me-2"></i>Joined: <?php echo date("F j, Y", strtotime($memberData['Joined_Date'])); ?></p>
                <p><i class="fas fa-circle me-2"></i>Status: 
                    <span class="badge <?php echo ($memberData['Status'] == 'Full Member') ? 'bg-success' : 'bg-warning'; ?>">
                        <?php echo ucfirst(htmlspecialchars($memberData['Status'])); ?>
                    </span>
                </p>
            </div>
        </div>
    </div>
    <?php if(!empty($successMessage)): ?>
        <div class="alert alert-success" role="alert">
            <?php echo $successMessage; ?>
        </div>
    <?php endif; ?>
        
    <?php if(!empty($errorMessage)): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo $errorMessage; ?>
        </div>
    <?php endif; ?>
    <div class="container profile-container">
        <div class="tabs-container">
            <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info-content" type="button" role="tab" aria-controls="info-content" aria-selected="true">
                        <i class="fas fa-user me-2"></i>Personal Information
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="edit-tab" data-bs-toggle="tab" data-bs-target="#edit-content" type="button" role="tab" aria-controls="edit-content" aria-selected="false">
                        <i class="fas fa-edit me-2"></i>Edit Profile
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security-content" type="button" role="tab" aria-controls="security-content" aria-selected="false">
                        <i class="fas fa-lock me-2"></i>Security
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="profileTabContent">
                <!-- Personal Information Tab -->
                <div class="tab-pane fade show active" id="info-content" role="tabpanel" aria-labelledby="info-tab">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-card">
                                <h5><i class="fas fa-user me-2"></i>Personal Details</h5>
                                
                                <div class="info-item">
                                    <div class="info-label">Full Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($memberData['Name']); ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">NIC Number</div>
                                    <div class="info-value"><?php echo htmlspecialchars($memberData['NIC']); ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Date of Birth</div>
                                    <div class="info-value"><?php echo date("F j, Y", strtotime($memberData['DoB'])); ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Address</div>
                                    <div class="info-value"><?php echo htmlspecialchars($memberData['Address']); ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Mobile Number</div>
                                    <div class="info-value"><?php echo htmlspecialchars($memberData['Mobile_Number']); ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Family Members</div>
                                    <div class="info-value"><?php echo htmlspecialchars($memberData['No_of_Family_Members']); ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Other Members</div>
                                    <div class="info-value"><?php echo htmlspecialchars($memberData['Other_Members']); ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="info-card">
                                <h5><i class="fas fa-user-shield me-2"></i>Account Information</h5>
                                
                                <div class="info-item">
                                    <div class="info-label">Username</div>
                                    <div class="info-value"><?php echo htmlspecialchars($userData['Username']); ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Email</div>
                                    <div class="info-value"><?php echo htmlspecialchars($userData['Email']); ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Account Status</div>
                                    <div class="info-value">
                                        <span class="badge <?php echo ($memberData['Status'] == 'Full Member') ? 'bg-success' : 'bg-warning'; ?>">
                                            <?php echo ucfirst(htmlspecialchars($memberData['Status'])); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Last Login</div>
                                    <div class="info-value">
                                        <?php echo $userData['last_login'] ? date("F j, Y, g:i a", strtotime($userData['last_login'])) : 'N/A'; ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Member Since</div>
                                    <div class="info-value"><?php echo date("F j, Y", strtotime($memberData['Joined_Date'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Profile Tab -->
                <div class="tab-pane fade" id="edit-content" role="tabpanel" aria-labelledby="edit-tab">
                    <div class="info-card">
                        <h5><i class="fas fa-edit me-2"></i>Edit Your Profile</h5>
                        
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="name" class="form-label">Full Name</label>
                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($memberData['Name']); ?>" readonly>
                                    <div class="form-text text-muted">This field cannot be modified</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($userData['Email']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="2" readonly><?php echo htmlspecialchars($memberData['Address']); ?></textarea>
                                <div class="form-text text-muted">This field cannot be modified</div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="mobile" class="form-label">Mobile Number</label>
                                    <input type="number" class="form-control" id="mobile" name="mobile" value="<?php echo htmlspecialchars($memberData['Mobile_Number']); ?>">
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="family_members" class="form-label">Family Members</label>
                                    <input type="number" class="form-control" id="family_members" name="family_members" value="<?php echo htmlspecialchars($memberData['No_of_Family_Members']); ?>" readonly>
                                    <div class="form-text text-muted">This field cannot be modified</div>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="other_members" class="form-label">Other Members</label>
                                    <input type="number" class="form-control" id="other_members" name="other_members" value="<?php echo htmlspecialchars($memberData['Other_Members']); ?>" readonly>
                                    <div class="form-text text-muted">This field cannot be modified</div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="profile_image" class="form-label">Profile Image</label>
                                <input type="file" class="form-control" id="profile_image" name="profile_image" accept="image/*">
                                <div class="form-text">Upload a new profile image (JPG, PNG, GIF - max 5MB)</div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Security Tab -->
                <div class="tab-pane fade" id="security-content" role="tabpanel" aria-labelledby="security-tab">
                    <div class="info-card">
                        <h5><i class="fas fa-lock me-2"></i>Change Password</h5>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" minlength="6" required>
                                <div class="form-text">Password must be 5-12 characters long</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="6" required>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="change_password" class="btn btn-primary">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="info-card">
                        <h5><i class="fas fa-shield-alt me-2"></i>Security Tips</h5>
                        
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Use a strong, unique password for your account
                            </li>
                            <li class="list-group-item">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Change your password regularly
                            </li>
                            <li class="list-group-item">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Never share your account credentials with others
                            </li>
                            <li class="list-group-item">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Always log out when using shared computers
                            </li>
                            <li class="list-group-item">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Keep your contact information up to date for account recovery
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Preview profile image before upload
        document.getElementById('profile_image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const avatarImg = document.querySelector('.profile-avatar img');
                    avatarImg.src = event.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Show success alert for 5 seconds then fade out
        const alertSuccess = document.querySelector('.alert-success');
        if (alertSuccess) {
            setTimeout(function() {
                alertSuccess.classList.add('fade');
                setTimeout(function() {
                    alertSuccess.style.display = 'none';
                }, 500);
            }, 5000);
        }
        
        // Check if passwords match
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        
        confirmPasswordInput.addEventListener('input', function() {
            if (newPasswordInput.value !== confirmPasswordInput.value) {
                confirmPasswordInput.setCustomValidity('Passwords do not match');
            } else {
                confirmPasswordInput.setCustomValidity('');
            }
        });
    </script>
</body>
</html>