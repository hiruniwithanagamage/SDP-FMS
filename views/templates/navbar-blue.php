<?php
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Check for session
    if (!isset($_SESSION["u"])) {
        header("Location: login.php");
        exit();
    }

   $userData = $_SESSION["u"];

   // Get member details if this is a member user
   $memberName = "Guest";
   $memberImage = "../assets/images/profile_photo.jpg"; // default image

   if (isset($userData['Member_MemberID'])) {
      $memberQuery = "SELECT Name, Image FROM Member WHERE MemberID = '" . $userData['Member_MemberID'] . "'";
      $memberResult = Database::search($memberQuery);
      
      if ($memberResult && $memberResult->num_rows > 0) {
         $memberData = $memberResult->fetch_assoc();
         $memberName = $memberData['Name'];
         // Use member's image if available, otherwise keep default
         if (!empty($memberData['Image'])) {
               $memberImage = "../uploads/" . $memberData['Image'];
         }
      }
   }

   if (isset($userData['Treasurer_TreasurerID'])) {
      $treasurerQuery = "SELECT Name FROM Treasurer WHERE TreasurerID = '" . $userData['Treasurer_TreasurerID'] . "'";
      $treasurerResult = Database::search($treasurerQuery);
      
      if ($treasurerResult && $treasurerResult->num_rows > 0) {
         $treasurerData = $treasurerResult->fetch_assoc();
         $memberName = $treasurerData['Name'];
      }
   }
?>

<!DOCTYPE html>
<html>
<head>
   <style>
      .modern-nav {
         background: #1a237e;
         color: white;
         box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
         padding: 1rem  1rem;
         position: sticky;
         top: 0;
         z-index: 1000;
         border: 2px solid #ccc;    
         border-radius: 4px;
      }

      .nav-content {
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
         color: white;
      }

      .nav-links {
         display: flex;
         gap: 2rem;
         margin-left: auto;
      }

      .nav-link {
         color: rgba(255, 255, 255, 0.9);
         text-decoration: none;
         display: flex;
         align-items: center;
         gap: 0.5rem;
         padding: 0.8rem 1.2rem;
         border-radius: 6px;
         transition: all 0.2s ease;
      }

      .nav-link:hover {
         color: rgba(255, 255, 255, 0.1);
         color: white;
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
         color: white;
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
            padding: 0 1rem;  /* Smaller padding for mobile */
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
           <img src="../assets/images/society_logo.png" alt="Logo" class="brand-logo">
           <span class="society-name">එක්සත් මරණාධාර සමිතිය</span>
       </div>

       <div class="nav-links">
           <a href="home-member.php" class="nav-link">
               <i class="fas fa-home"></i>
               <span>Home</span>
           </a>
           <a href="payments.php" class="nav-link">
               <i class="fas fa-credit-card"></i>
               <span>Payments</span>
           </a>
       </div>

       <div class="nav-profile" id="profileDropdown">
           <span class="profile-name"><?php echo htmlspecialchars($memberName); ?></span>
           <div class="profile-avatar">
               <img src="<?php echo htmlspecialchars($memberImage); ?>" alt="Profile">
           </div>
           
           <div class="profile-dropdown" id="dropdownMenu">
               <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
               <a href="reports.php"><i class="fas fa-file-alt"></i> Reports</a>
               <a href="../logout.php" class="logout">
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