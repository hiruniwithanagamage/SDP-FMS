<?php
function getBasePath() {
    // Get the current script path
    $currentPath = $_SERVER['SCRIPT_NAME'];
    
    // Check if we're in the Management subfolder
    if (strpos($currentPath, '/financialManagement/') !== false) {
        return "../../../";  // One level deeper, so need an extra ../
    } else if (strpos($currentPath, '/reportsAnalytics/') !== false) {
        return "../../../";  // One level deeper, so need an extra ../
    } else if (strpos($currentPath, '/reports/') !== false) {
        return "../";     // Direct in reports folder
    } else {
        return "../../";     // Direct in treasurer folder
    }
}

// Get the appropriate base path
$basePath = getBasePath();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check for session
if (!isset($_SESSION["u"])) {
    header("Location: " . $basePath . "login.php");
    exit();
}

$userData = $_SESSION["u"];

// Get member details if this is a member user
$treasurerName = "Guest";
$defaultProfileImage = $basePath . "assets/images/profile_photo 2.png";
$treasurerImage = $defaultProfileImage; // default image

$memberQuery = "SELECT Image FROM Member WHERE MemberID = 
                (SELECT MemberID FROM Treasurer WHERE TreasurerID = '" . $userData['Treasurer_TreasurerID'] . "')";
$memberResult = search($memberQuery);
$memberDataImg = $memberResult->fetch_assoc();

if (isset($userData['Treasurer_TreasurerID'])) {
    $treasurerQuery = "SELECT Name FROM Treasurer WHERE TreasurerID = '" . $userData['Treasurer_TreasurerID'] . "'";
    $treasurerResult = search($treasurerQuery);
    
    if ($treasurerResult && $treasurerResult->num_rows > 0) {
        $treasurerData = $treasurerResult->fetch_assoc();
        $treasurerName = $treasurerData['Name'];
        if (!empty($memberDataImg['Image'])) {
            $treasurerImage = $basePath . "uploads/profilePictures/" . $memberDataImg['Image'];
        }
    }
}
?>
<head>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
.modern-nav {
    background: white;
    box-shadow: 0 1px 4px #1a237e;
    padding: 1rem 1rem;
    position: sticky;
    top: 0;
    z-index: 1000;
    border: 2px solid #ccc;    
    border-radius: 4px;
}

.nav-content {
    font-family: Arial, sans-serif;
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 2rem;
}

.nav-brand {
    display: flex;
    align-items: center;
}

.brand-container {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.brand-logo {
    height: 45px;
    width: auto;
}

.society-name {
    margin-left: 1rem;
    font-size: 1.2rem;
    font-weight: 500;
    color: #1a237e;
}

.nav-links {
    display: flex;
    gap: 2rem;
    margin-left: auto;
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

.nav-link:hover {
    background: #f5f7fa;
    color: #1a237e;
}

.nav-profile {
    position: relative;
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-left: 2rem;
    padding: 0.5rem;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.nav-profile:hover {
    background: #f5f7fa;
}

.profile-name {
    font-weight: 500;
    color: #333;
}

.profile-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    border: 2px solid #1a237e;
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-dropdown {
    position: absolute;
    top: 120%;
    right: 0;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    padding: 0.5rem;
    min-width: 200px;
    display: none;
    border: 1px solid #eee;
}

.profile-dropdown.active {
    display: block;
    animation: dropdown 0.2s ease;
}

.profile-dropdown a {
    color: #333;
    text-decoration: none;
    padding: 0.8rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.8rem;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.profile-dropdown a:hover {
    background: #f5f7fa;
    color: #1a237e;
}

.profile-dropdown .logout {
    color: #dc3545;
}

.profile-dropdown .logout:hover {
    background: #fff5f5;
    color: #dc3545;
}

@keyframes dropdown {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (max-width: 768px) {
    .modern-nav {
        padding: 1rem 0.5rem;
    }

    .nav-content {
        padding: 0 1rem;
    }
    
    .society-name {
        display: none;
    }
    
    .nav-link span {
        display: none;
    }
    
    .profile-name {
        display: none;
    }

    .nav-links {
        margin-left: 0;
    }
}
</style>
</head>
<body>
<nav class="modern-nav">
    <div class="nav-content">
        <div class="nav-brand">
            <img src="<?php echo $basePath; ?>assets/images/society_logo.png" 
                 alt="Logo" 
                 class="brand-logo"
                 onerror="this.src='<?php echo $defaultProfileImage; ?>'">
            <span class="society-name">එක්සත් මරණාධාර සමිතිය</span>
        </div>

        <div class="nav-links">
            <a href="<?php echo $basePath; ?>views/treasurer/home-treasurer.php" class="nav-link">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            <a href="<?php echo $basePath; ?>views/treasurer/treasurerPayment.php" class="nav-link">
                <i class="fas fa-credit-card"></i>
                <span>Payments</span>
            </a>
        </div>

        <div class="nav-profile" id="profileDropdown">
            <span class="profile-name"><?php echo htmlspecialchars($treasurerName); ?></span>
            <div class="profile-avatar">
                <img src="<?php echo htmlspecialchars($treasurerImage); ?>" 
                     alt="Profile" 
                     onerror="this.src='<?php echo $defaultProfileImage; ?>'">
            </div>
            
            <div class="profile-dropdown" id="dropdownMenu">
                <a href="<?php echo $basePath; ?>views/treasurer/treasurerProfile.php">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a href="<?php echo $basePath; ?>reports/yearEndReport.php">
                    <i class="fas fa-file-alt"></i> Reports
                </a>
                <a href="<?php echo $basePath; ?>logout.php" class="logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>
</nav>

<script>
document.getElementById('profileDropdown').addEventListener('click', function() {
    document.getElementById('dropdownMenu').classList.toggle('active');
});

document.addEventListener('click', function(event) {
    if (!event.target.closest('.nav-profile')) {
        document.getElementById('dropdownMenu').classList.remove('active');
    }
});
</script>
</body>
</html>